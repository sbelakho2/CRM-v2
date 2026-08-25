<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WebEvent;
use App\Entity\AbmHit;
use App\Entity\AbmAccount;
use App\Entity\IpMap;
use App\Repository\WebEventRepository;
use App\Repository\AbmHitRepository;
use App\Repository\AbmAccountRepository;
use App\Repository\IpMapRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * AbmResolverService
 * 
 * Account-Based Marketing (ABM) visitor de-anonymization service.
 * 
 * Core functionality:
 * - Process anonymous web events (pageviews, downloads, form submissions)
 * - Resolve visitor IP addresses to company names using GeoIP + reverse DNS
 * - Match resolved companies to ABM target account list
 * - Create AbmHit records for matched visitors
 * - Trigger marketing playbooks (auto-create leads, send alerts, create activities)
 * 
 * De-anonymization pipeline:
 * 1. WebEvent logged (IP, page, timestamp, user agent)
 * 2. IP lookup in IpMap table (ISP name, organization, country)
 * 3. Company matching against AbmAccount list
 * 4. Create AbmHit if match found
 * 5. Evaluate playbook triggers (e.g., "3+ pageviews in 7 days")
 * 6. Execute playbook actions (create Activity, send email, create RFQ)
 * 
 * Used by:
 * - Website analytics tracking pixel
 * - AbmDashboardController for visitor activity display
 * - PlaybookEngine for automated workflows
 */
class AbmResolverService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WebEventRepository $webEventRepository,
        private AbmHitRepository $abmHitRepository,
        private AbmAccountRepository $abmAccountRepository,
        private IpMapRepository $ipMapRepository,
        private PlaybookEngine $playbookEngine,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Process web event and resolve visitor
     * 
     * @param array $eventData - Web event data
     *   - ip: string
     *   - url: string (URL path)
     *   - user_agent: string
     *   - timestamp: string
     *   - referer: string
     * 
     * @return array{
     *   resolved: bool,
     *   companyName: string|null,
     *   abmHitId: int|null,
     *   playbookTriggered: bool
     * }
     */
    public function processWebEvent(array $eventData): array
    {
        $ipAddress = (string)($eventData['ip'] ?? '');
        if ($ipAddress === '') {
            throw new \InvalidArgumentException('Missing required field: ip');
        }

        $url = (string)($eventData['url'] ?? '/');
        $timestamp = (string)($eventData['timestamp'] ?? 'now');
        $userAgent = $eventData['user_agent'] ?? $eventData['userAgent'] ?? null;
        $referer = $eventData['referer'] ?? null;
        $method = (string)($eventData['method'] ?? 'GET');
        $statusCode = $eventData['status_code'] ?? $eventData['statusCode'] ?? null;

        $webEvent = new WebEvent();
        $webEvent->setIpAddress($ipAddress);
        $webEvent->setUrl($url);
        $webEvent->setTimestamp(new \DateTime($timestamp));
        $webEvent->setMethod($method);
        $webEvent->setStatusCode(is_numeric($statusCode) ? (int)$statusCode : null);
        $webEvent->setUserAgent(is_string($userAgent) ? $userAgent : null);
        $webEvent->setReferer(is_string($referer) ? $referer : null);

        $this->entityManager->persist($webEvent);

        // Resolve IP address
        $resolvedData = $this->resolveIp($ipAddress);
        
        // Check if resolved to a company and if it's an ABM target
        $abmHitId = null;
        $playbookTriggered = false;
        
        if ($resolvedData['companyName']) {
            // Look for ABM account by domain or company name
            $domain = $this->extractDomainFromCompanyName($resolvedData['companyName']);
            
            $abmAccount = null;
            if ($domain) {
                $abmAccount = $this->abmAccountRepository->findOneBy(['domain' => $domain]);
            }
            
            if (!$abmAccount && $resolvedData['companyName']) {
                $abmAccount = $this->abmAccountRepository->findOneBy(['accountName' => $resolvedData['companyName']]);
            }
            
            if ($abmAccount) {
                $abmHit = $this->createAbmHit($webEvent, $abmAccount, $resolvedData);
                $this->entityManager->persist($abmHit);
                
                // Update account metrics
                $abmAccount->setLastActivityAt(new \DateTime());
                $abmAccount->setTotalPageViews(($abmAccount->getTotalPageViews() ?? 0) + 1);
                
                // Simple engagement score update (+1 per page view, cap at 100)
                $currentScore = $abmAccount->getEngagementScore() ?? 0;
                $abmAccount->setEngagementScore(min(100, $currentScore + 1));
                
                $this->entityManager->flush();
                
                $abmHitId = $abmHit->getId();
                
                $playbookTriggered = $this->triggerPlaybooks($abmAccount, $webEvent);
            }
        }

        $webEvent->setIsProcessed(true);
        $webEvent->setProcessedAt(new \DateTime());
        $this->entityManager->flush();
        
        return [
            'resolved' => $resolvedData['resolved'],
            'companyName' => $resolvedData['companyName'],
            'abmHitId' => $abmHitId,
            'playbookTriggered' => $playbookTriggered
        ];
    }

    /**
     * Resolve IP address to company name
     * 
     * @param string $ipAddress - IP address to resolve
     * 
     * @return array{
     *   resolved: bool,
     *   companyName: string|null,
     *   isp: string|null,
     *   country: string|null,
     *   city: string|null
     * }
     */
    public function resolveIp(string $ipAddress): array
    {
        // 1. Check IpMap cache first
        $ipMap = $this->ipMapRepository->findOneBy(['ipAddress' => $ipAddress]);
        
        if ($ipMap) {
            if ($ipMap->getExpiresAt() && $ipMap->getExpiresAt() < new \DateTime()) {
                $ipMap = null;
            }
        }

        if ($ipMap) {
            // Found in cache
            return [
                'resolved' => true,
                'companyName' => $ipMap->getOrganizationName(),
                'isp' => null,
                'country' => $ipMap->getCountry(),
                'city' => $ipMap->getCity()
            ];
        }
        
        // 2. Basic IP resolution using reverse DNS lookup
        // For now, use a simple approach - in production, integrate GeoIP2 or similar
        $organization = null;
        $country = null;
        $city = null;
        
        try {
            // Attempt reverse DNS lookup
            $hostname = @gethostbyaddr($ipAddress);
            
            if ($hostname !== $ipAddress && $hostname !== false) {
                // Successfully resolved hostname
                // Extract organization from hostname (e.g., "mail.company.com" -> "company")
                $parts = explode('.', $hostname);
                if (count($parts) >= 2) {
                    // Get the second-level domain as organization
                    $organization = ucfirst($parts[count($parts) - 2]);
                }
                
                // Check if it looks like ISP/residential (common patterns)
                $residentialPatterns = ['comcast', 'verizon', 'att', 'cox', 'charter', 'spectrum'];
                foreach ($residentialPatterns as $pattern) {
                    if (stripos($hostname, $pattern) !== false) {
                        $organization = null; // Don't track residential IPs
                        break;
                    }
                }
            }
            
            // NOTE: no country heuristic here — a country is only reported when it
            // is actually known (e.g. from the IpMap cache). Inventing a country
            // from the first IP octet feeds wrong data into lead/ABM records.
            
        } catch (\Exception $e) {
            $this->logger?->debug('Reverse DNS lookup failed', [
                'ip' => $ipAddress,
                'error' => $e->getMessage(),
            ]);
        }
        
        // 3. Cache result in IpMap (even if unresolved, to avoid repeated lookups)
        try {
            $newIpMap = new IpMap();
            $newIpMap->setIpAddress($ipAddress);
            $newIpMap->setOrganizationName($organization);
            $newIpMap->setCountry($country);
            $newIpMap->setCity($city);
            $newIpMap->setAsof(new \DateTime());
            $newIpMap->setExpiresAt(new \DateTime('+30 days'));

            $this->entityManager->persist($newIpMap);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // IpMap table might not exist yet, but the failure should not be silent
            $this->logger?->warning('Failed to cache IP resolution', [
                'ip' => $ipAddress,
                'error' => $e->getMessage(),
            ]);
        }
        
        // 4. Return resolved data
        return [
            'resolved' => $organization !== null,
            'companyName' => $organization,
            'isp' => null,
            'country' => $country,
            'city' => $city
        ];
    }

    public function createAbmHit(WebEvent $webEvent, AbmAccount $abmAccount, array $resolvedData): AbmHit
    {
        $abmHit = new AbmHit();
        $abmHit->setTimestamp($webEvent->getTimestamp() ?? new \DateTime());
        $abmHit->setIpAddress($webEvent->getIpAddress() ?? '');
        $abmHit->setUrlVisited($webEvent->getUrl());
        $abmHit->setOrganizationName($abmAccount->getAccountName() ?? ($resolvedData['companyName'] ?? null));
        $abmHit->setIsIdentified(true);
        $abmHit->setPageViews(1);
        $abmHit->setFirmographicData(is_array($abmAccount->getMetadata()) ? json_encode($abmAccount->getMetadata()) : null);
        return $abmHit;
    }

    public function triggerPlaybooks(AbmAccount $abmAccount, WebEvent $webEvent): bool
    {
        try {
            return (bool)$this->playbookEngine->evaluatePlaybooks($abmAccount);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get recent ABM hits for account
     * 
     * @param int $accountId - ABM account ID
     * @param int $days - Number of days to look back
     * 
     * @return array - Array of AbmHit entities
     */
    public function getRecentHits(int $accountId, int $days = 30): array
    {
        $account = $this->abmAccountRepository->find($accountId);
        if (!$account || !$account->getAccountName()) {
            return [];
        }

        $cutoffDate = new \DateTime("-$days days");
        $hits = $this->abmHitRepository->findBy(['organizationName' => $account->getAccountName()]);

        $hits = array_values(array_filter($hits, static function (AbmHit $hit) use ($cutoffDate): bool {
            $ts = $hit->getTimestamp();
            return $ts instanceof \DateTimeInterface && $ts >= $cutoffDate;
        }));

        usort($hits, static function (AbmHit $a, AbmHit $b): int {
            $ta = $a->getTimestamp();
            $tb = $b->getTimestamp();
            if (!$ta || !$tb) {
                return 0;
            }
            return $tb <=> $ta;
        });

        return $hits;
    }

    /**
     * Get ABM account activity statistics
     * 
     * @param int $accountId - ABM account ID
     * 
     * @return array{
     *   totalHits: int,
     *   lastHitAt: \DateTime|null,
     *   pageviews: int,
     *   downloads: int,
     *   formSubmits: int,
     *   uniqueVisitors: int
     * }
     */
    public function getAccountStats(int $accountId): array
    {
        $account = $this->abmAccountRepository->find($accountId);
        
        if (!$account) {
            return [
                'totalHits' => 0,
                'lastHitAt' => null,
                'pageviews' => 0,
                'downloads' => 0,
                'formSubmits' => 0,
                'uniqueVisitors' => 0
            ];
        }
        
        $hits = [];
        if ($account->getAccountName()) {
            $hits = $this->abmHitRepository->findBy(['organizationName' => $account->getAccountName()]);
        }
        
        $stats = [
            'totalHits' => count($hits),
            'lastHitAt' => null,
            'pageviews' => 0,
            'downloads' => 0,
            'formSubmits' => 0,
            'uniqueVisitors' => []
        ];
        
        foreach ($hits as $hit) {
            // Last hit timestamp
            if (!$stats['lastHitAt'] || $hit->getTimestamp() > $stats['lastHitAt']) {
                $stats['lastHitAt'] = $hit->getTimestamp();
            }
            
            // Count all as pageviews for now (can be enhanced later)
            $stats['pageviews']++;
            
            // Unique visitors (by IP)
            $stats['uniqueVisitors'][$hit->getIpAddress()] = true;
        }
        
        $stats['uniqueVisitors'] = count($stats['uniqueVisitors']);
        
        return $stats;
    }

    /**
     * Bulk import ABM target account list
     * 
     * @param string $csvPath - Path to CSV file with company names
     * @return int - Number of accounts imported
     */
    public function importTargetAccounts(string $csvPath): int
    {
        if (!file_exists($csvPath)) {
            throw new \RuntimeException("CSV file not found: {$csvPath}");
        }
        
        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open CSV file: {$csvPath}");
        }
        
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!$header) {
            fclose($handle);
            throw new \RuntimeException("Empty CSV file");
        }
        
        $count = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (empty(array_filter($row))) {
                continue; // Skip empty rows
            }
            
            try {
                $data = array_combine($header, $row);
            } catch (\ValueError $e) {
                continue;
            }
            
            // Check if account already exists
            $existingAccount = $this->abmAccountRepository->findOneBy([
                'accountName' => $data['company_name'] ?? $data['name'] ?? null
            ]);
            
            if ($existingAccount) {
                continue; // Skip duplicates
            }
            
            // Create AbmAccount
            $account = new AbmAccount();
            $account->setAccountName($data['company_name'] ?? $data['name']);
            $account->setDomain($data['domain'] ?? $this->extractDomainFromCompanyName($data['company_name'] ?? $data['name']));
            $account->setIcpTier($data['tier'] ?? 'B');
            
            // Set metadata
            $metadata = [];
            if (isset($data['industry'])) $metadata['industry'] = $data['industry'];
            if (isset($data['country'])) $metadata['country'] = $data['country'];
            if (isset($data['company_size'])) $metadata['company_size'] = $data['company_size'];
            if (isset($data['revenue'])) $metadata['revenue'] = $data['revenue'];
            $account->setMetadata($metadata);
            
            $account->setEngagementScore(0);
            $account->setTotalPageViews(0);
            
            $this->entityManager->persist($account);
            $count++;
        }
        
        fclose($handle);
        $this->entityManager->flush();
        
        return $count;
    }
    
    /**
     * Extract domain from company name
     * 
     * Only returns a domain when the name plausibly contains one (i.e. the name
     * is a bare domain like "acme.com" or contains a domain token with a known
     * TLD). Never fabricates ".com" domains from plain company names — invented
     * domains cause account mismatches downstream.
     * 
     * @param string $companyName - Company name
     * @return string|null - Domain or null
     */
    private function extractDomainFromCompanyName(string $companyName): ?string
    {
        $cleanName = strtolower(trim($companyName));
        if ($cleanName === '') {
            return null;
        }

        // Known TLDs (covers the markets Starz operates in plus generic ones)
        $knownTlds = 'com|net|org|io|co|de|fr|uk|us|ca|cn|jp|sg|ae|sa|ma|tn|nl|it|es|pl|cz|sk|hu|ro|bg|se|no|fi|dk|at|ch|be|pt|gr|hr|si|lt|lv|ee|info|biz|dev|ai|online|store|site|eu|asia';

        // Case 1: the whole name is already a domain ("www.mail.acme.com", "acme.com")
        if (preg_match('/^(?:www\.)?(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:' . $knownTlds . ')$/i', $cleanName)) {
            $parts = explode('.', $cleanName);
            // Registered domain = last two labels ("co.uk", "co.ma" style TLDs
            // are only two labels, so keep last two labels at minimum).
            $tld = array_pop($parts);
            $sld = array_pop($parts);
            if ($sld === null) {
                return null;
            }
            // Handle two-part TLDs (co.uk, co.ma, com.sa, ...)
            while (!in_array($tld, ['com', 'net', 'org', 'co', 'gov', 'ac', 'edu'], true) && count($parts) > 0) {
                $tld = $sld . '.' . $tld;
                $sld = array_pop($parts);
                if ($sld === null) {
                    return $tld;
                }
            }
            return $sld . '.' . $tld;
        }

        // Case 2: the name contains a domain token with a known TLD
        // (e.g. "Acme Holdings (acme.com)" or "Acme - acme.ma")
        if (preg_match('/(?:^|[\s(])[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.(?:' . $knownTlds . ')\b/i', $cleanName, $m)) {
            $domain = strtolower(trim($m[0], " \t\n\r\0\x0B()"));
            $parts = explode('.', $domain);
            $tld = array_pop($parts);
            $sld = array_pop($parts);
            if ($sld === null) {
                return null;
            }
            while (!in_array($tld, ['com', 'net', 'org', 'co', 'gov', 'ac', 'edu'], true) && count($parts) > 0) {
                $tld = $sld . '.' . $tld;
                $sld = array_pop($parts);
                if ($sld === null) {
                    return $tld;
                }
            }
            return $sld . '.' . $tld;
        }

        return null;
    }
}
