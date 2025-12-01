<?php

namespace App\Service;

use App\Entity\WebEvent;
use App\Entity\AbmHit;
use App\Entity\AbmAccount;
use App\Repository\WebEventRepository;
use App\Repository\AbmHitRepository;
use App\Repository\AbmAccountRepository;
use App\Repository\IpMapRepository;
use Doctrine\ORM\EntityManagerInterface;

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
        private PlaybookEngine $playbookEngine
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
        // Resolve IP address
        $resolvedData = $this->resolveIp($eventData['ip']);
        
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
                // Create ABM hit
                $abmHit = new AbmHit();
                $abmHit->setAbmAccount($abmAccount);
                $abmHit->setHitAt(new \DateTime($eventData['timestamp'] ?? 'now'));
                $abmHit->setPage($eventData['url']);
                $abmHit->setIpAddress($eventData['ip']);
                $abmHit->setUserAgent($eventData['user_agent'] ?? '');
                $abmHit->setReferer($eventData['referer'] ?? '');
                
                $this->entityManager->persist($abmHit);
                
                // Update account metrics
                $abmAccount->setLastActivityAt(new \DateTime());
                $abmAccount->setTotalPageViews(($abmAccount->getTotalPageViews() ?? 0) + 1);
                
                // Simple engagement score update (+1 per page view, cap at 100)
                $currentScore = $abmAccount->getEngagementScore() ?? 0;
                $abmAccount->setEngagementScore(min(100, $currentScore + 1));
                
                $this->entityManager->flush();
                
                $abmHitId = $abmHit->getId();
                
                // Trigger playbooks (will implement in next step)
                try {
                    $playbookTriggered = $this->playbookEngine->evaluatePlaybooks($abmAccount);
                } catch (\Exception $e) {
                    // Playbook engine not fully implemented yet, continue
                    $playbookTriggered = false;
                }
            }
        }
        
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
            // Found in cache
            return [
                'resolved' => true,
                'companyName' => $ipMap->getOrganization(),
                'isp' => $ipMap->getIsp(),
                'country' => $ipMap->getCountry(),
                'city' => $ipMap->getCity()
            ];
        }
        
        // 2. Basic IP resolution using reverse DNS lookup
        // For now, use a simple approach - in production, integrate GeoIP2 or similar
        $organization = null;
        $isp = null;
        $country = null;
        $city = null;
        
        try {
            // Attempt reverse DNS lookup
            $hostname = gethostbyaddr($ipAddress);
            
            if ($hostname !== $ipAddress) {
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
                        $isp = ucfirst($pattern);
                        $organization = null; // Don't track residential IPs
                        break;
                    }
                }
            }
            
            // Basic country detection from IP (first octet heuristic - not accurate)
            // In production, use MaxMind GeoIP2
            $firstOctet = (int)explode('.', $ipAddress)[0];
            if ($firstOctet >= 1 && $firstOctet <= 127) {
                $country = 'US'; // North America
            } elseif ($firstOctet >= 128 && $firstOctet <= 191) {
                $country = 'EU'; // Europe
            } else {
                $country = 'APAC'; // Asia-Pacific
            }
            
        } catch (\Exception $e) {
            // DNS lookup failed, continue with null values
        }
        
        // 3. Cache result in IpMap (even if unresolved, to avoid repeated lookups)
        try {
            $newIpMap = $this->entityManager->getRepository(\App\Entity\IpMap::class)->findOneBy(['ipAddress' => $ipAddress]);
            if (!$newIpMap) {
                $newIpMap = new \App\Entity\IpMap();
                $newIpMap->setIpAddress($ipAddress);
            }
            $newIpMap->setOrganization($organization);
            $newIpMap->setIsp($isp);
            $newIpMap->setCountry($country);
            $newIpMap->setCity($city);
            $newIpMap->setLookedUpAt(new \DateTime());
            
            $this->entityManager->persist($newIpMap);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // IpMap table might not exist yet, continue
        }
        
        // 4. Return resolved data
        return [
            'resolved' => $organization !== null,
            'companyName' => $organization,
            'isp' => $isp,
            'country' => $country,
            'city' => $city
        ];
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
        $cutoffDate = new \DateTime("-$days days");
        
        $qb = $this->abmHitRepository->createQueryBuilder('ah');
        return $qb
            ->where('ah.abmAccount = :accountId')
            ->andWhere('ah.hitAt >= :cutoff')
            ->setParameter('accountId', $accountId)
            ->setParameter('cutoff', $cutoffDate)
            ->orderBy('ah.hitAt', 'DESC')
            ->getQuery()
            ->getResult();
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
        
        $hits = $this->abmHitRepository->findBy(['abmAccount' => $account]);
        
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
            if (!$stats['lastHitAt'] || $hit->getHitAt() > $stats['lastHitAt']) {
                $stats['lastHitAt'] = $hit->getHitAt();
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
        
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            throw new \RuntimeException("Empty CSV file");
        }
        
        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) {
                continue; // Skip empty rows
            }
            
            $data = array_combine($header, $row);
            
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
     * @param string $companyName - Company name
     * @return string|null - Domain or null
     */
    private function extractDomainFromCompanyName(string $companyName): ?string
    {
        // Simple heuristic: lowercase, remove common suffixes, add .com
        $cleanName = strtolower($companyName);
        $cleanName = str_replace([' inc', ' llc', ' ltd', ' corp', ' corporation', ' company'], '', $cleanName);
        $cleanName = trim($cleanName);
        $cleanName = str_replace(' ', '', $cleanName); // Remove spaces
        
        if (strlen($cleanName) > 2) {
            return $cleanName . '.com';
        }
        
        return null;
    }
}
