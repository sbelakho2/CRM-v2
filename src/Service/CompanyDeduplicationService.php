<?php

namespace App\Service;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Company Deduplication Service
 * 
 * Detects and helps resolve duplicate company entries using:
 * - Exact name matching
 * - Fuzzy name matching (Levenshtein distance)
 * - Website domain matching
 * - Normalized name comparison (remove legal suffixes)
 * 
 * Used by:
 * - Import services (prevention)
 * - Admin tools (detection & merge)
 */
class CompanyDeduplicationService
{
    // Legal suffixes to normalize company names
    private const LEGAL_SUFFIXES = [
        ' ltd', ' limited', ' inc', ' incorporated', ' corp', ' corporation',
        ' llc', ' l.l.c.', ' gmbh', ' sarl', ' s.a.r.l.', ' sa', ' s.a.',
        ' sas', ' plc', ' nv', ' bv', ' ag', ' co', ' company', ' pty',
        ' sprl', ' srl', ' kk', ' kabushiki kaisha', ' oü',
        ' maroc', ' morocco', ' africa', ' mea', ' emea',
    ];
    
    // Common abbreviations to expand
    private const NAME_EXPANSIONS = [
        'intl' => 'international',
        'int' => 'international',
        'mfg' => 'manufacturing',
        'tech' => 'technology',
        'sys' => 'systems',
        'svcs' => 'services',
        'svc' => 'service',
        'ind' => 'industries',
        'elec' => 'electronics',
        'auto' => 'automotive',
    ];
    
    // Threshold for fuzzy matching (0-100, higher = stricter)
    private const SIMILARITY_THRESHOLD = 80;
    
    public function __construct(
        private EntityManagerInterface $em,
        private CompanyRepository $companyRepository,
        private LoggerInterface $logger
    ) {}
    
    /**
     * Find potential duplicate companies for a given company
     * 
     * @param Company $company The company to check
     * @return array<array{company: Company, matchType: string, confidence: int}>
     */
    public function findDuplicates(Company $company): array
    {
        $duplicates = [];
        $companyName = $company->getName();
        $normalizedName = $this->normalizeName($companyName);
        $website = $company->getWebsite();
        $domain = $website ? $this->extractDomain($website) : null;
        
        // Get all companies to compare
        $allCompanies = $this->companyRepository->createQueryBuilder('c')
            ->where('c.id != :id')
            ->setParameter('id', $company->getId() ?? 0)
            ->getQuery()
            ->getResult();
        
        foreach ($allCompanies as $existing) {
            $match = $this->compareCompanies($company, $existing, $normalizedName, $domain);
            
            if ($match) {
                $duplicates[] = $match;
            }
        }
        
        // Sort by confidence (highest first)
        usort($duplicates, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        
        return $duplicates;
    }
    
    /**
     * Check if a company name exists before import (prevention)
     * 
     * @param string $companyName Name to check
     * @param string|null $website Website to check
     * @return Company|null Existing company if found
     */
    public function findExistingCompany(string $companyName, ?string $website = null): ?Company
    {
        // 1. Exact match
        $exact = $this->companyRepository->findOneBy(['name' => $companyName]);
        if ($exact) {
            return $exact;
        }
        
        // 2. Domain match
        if ($website) {
            $domain = $this->extractDomain($website);
            if ($domain) {
                $domainMatch = $this->findByDomain($domain);
                if ($domainMatch) {
                    return $domainMatch;
                }
            }
        }
        
        // 3. Normalized name match
        $normalizedName = $this->normalizeName($companyName);
        $allCompanies = $this->companyRepository->findAll();
        
        foreach ($allCompanies as $existing) {
            $existingNormalized = $this->normalizeName($existing->getName());
            
            // Exact normalized match
            if ($normalizedName === $existingNormalized) {
                return $existing;
            }
            
            // Fuzzy match
            $similarity = $this->calculateSimilarity($normalizedName, $existingNormalized);
            if ($similarity >= self::SIMILARITY_THRESHOLD) {
                return $existing;
            }
        }
        
        return null;
    }
    
    /**
     * Detect all duplicate groups in the database
     * 
     * @return array<array{companies: Company[], matchType: string, confidence: int}>
     */
    public function detectAllDuplicates(): array
    {
        $groups = [];
        $processed = [];
        
        $allCompanies = $this->companyRepository->findAll();
        
        foreach ($allCompanies as $company) {
            if (in_array($company->getId(), $processed)) {
                continue;
            }
            
            $duplicates = $this->findDuplicates($company);
            
            if (!empty($duplicates)) {
                $groupCompanies = [$company];
                $bestMatchType = '';
                $bestConfidence = 0;
                
                foreach ($duplicates as $dup) {
                    $groupCompanies[] = $dup['company'];
                    $processed[] = $dup['company']->getId();
                    
                    if ($dup['confidence'] > $bestConfidence) {
                        $bestConfidence = $dup['confidence'];
                        $bestMatchType = $dup['matchType'];
                    }
                }
                
                $groups[] = [
                    'companies' => $groupCompanies,
                    'matchType' => $bestMatchType,
                    'confidence' => $bestConfidence,
                ];
                
                $processed[] = $company->getId();
            }
        }
        
        return $groups;
    }
    
    /**
     * Merge duplicate companies into one
     * 
     * @param Company $primary Company to keep
     * @param Company[] $duplicates Companies to merge into primary
     * @return int Number of merged companies
     */
    public function mergeCompanies(Company $primary, array $duplicates): int
    {
        $merged = 0;
        
        foreach ($duplicates as $duplicate) {
            if ($duplicate->getId() === $primary->getId()) {
                continue;
            }
            
            // Transfer contacts to primary
            foreach ($duplicate->getContacts() as $contact) {
                $contact->setCompany($primary);
            }
            
            // Transfer activities to primary
            foreach ($duplicate->getActivities() as $activity) {
                $activity->setCompany($primary);
            }
            
            // Transfer RFQs to primary
            foreach ($duplicate->getRfqs() as $rfq) {
                $rfq->setCompany($primary);
            }
            
            // Merge notes
            $dupNotes = $duplicate->getNotes();
            if ($dupNotes) {
                $existingNotes = $primary->getNotes() ?? '';
                $primary->setNotes($existingNotes . "\n\n[Merged from {$duplicate->getName()}]\n" . $dupNotes);
            }
            
            // Fill in missing fields on primary
            if (!$primary->getWebsite() && $duplicate->getWebsite()) {
                $primary->setWebsite($duplicate->getWebsite());
            }
            if (!$primary->getSector() && $duplicate->getSector()) {
                $primary->setSector($duplicate->getSector());
            }
            if (!$primary->getRegion() && $duplicate->getRegion()) {
                $primary->setRegion($duplicate->getRegion());
            }
            
            // Delete duplicate
            $this->em->remove($duplicate);
            $merged++;
            
            $this->logger->info("Merged company", [
                'primaryId' => $primary->getId(),
                'primaryName' => $primary->getName(),
                'duplicateId' => $duplicate->getId(),
                'duplicateName' => $duplicate->getName(),
            ]);
        }
        
        $this->em->flush();
        
        return $merged;
    }
    
    /**
     * Compare two companies and return match details
     */
    private function compareCompanies(
        Company $company,
        Company $existing,
        string $normalizedName,
        ?string $domain
    ): ?array {
        $existingNormalized = $this->normalizeName($existing->getName());
        
        // 1. Exact name match
        if ($company->getName() === $existing->getName()) {
            return [
                'company' => $existing,
                'matchType' => 'exact_name',
                'confidence' => 100,
            ];
        }
        
        // 2. Website domain match
        if ($domain) {
            $existingDomain = $existing->getWebsite() ? $this->extractDomain($existing->getWebsite()) : null;
            if ($existingDomain && $domain === $existingDomain) {
                return [
                    'company' => $existing,
                    'matchType' => 'same_domain',
                    'confidence' => 95,
                ];
            }
        }
        
        // 3. Normalized name match
        if ($normalizedName === $existingNormalized) {
            return [
                'company' => $existing,
                'matchType' => 'normalized_name',
                'confidence' => 90,
            ];
        }
        
        // 4. Fuzzy name match
        $similarity = $this->calculateSimilarity($normalizedName, $existingNormalized);
        if ($similarity >= self::SIMILARITY_THRESHOLD) {
            return [
                'company' => $existing,
                'matchType' => 'fuzzy_name',
                'confidence' => $similarity,
            ];
        }
        
        return null;
    }
    
    /**
     * Normalize a company name for comparison
     */
    private function normalizeName(string $name): string
    {
        $normalized = strtolower(trim($name));
        
        // Remove legal suffixes
        foreach (self::LEGAL_SUFFIXES as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                $normalized = rtrim(substr($normalized, 0, -strlen($suffix)));
            }
        }
        
        // Expand abbreviations (word-boundary to avoid partial matches)
        foreach (self::NAME_EXPANSIONS as $abbr => $full) {
            $normalized = preg_replace('/\b' . preg_quote($abbr, '/') . '\b/', $full, $normalized);
        }
        
        // Remove special characters and extra spaces
        $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        return trim($normalized);
    }
    
    /**
     * Extract domain from URL
     */
    private function extractDomain(?string $url): ?string
    {
        if (!$url) {
            return null;
        }
        
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;
        
        if (!$host) {
            return null;
        }
        
        // Remove www. prefix
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        
        return strtolower($host);
    }
    
    /**
     * Find company by website domain
     */
    private function findByDomain(string $domain): ?Company
    {
        $companies = $this->companyRepository->createQueryBuilder('c')
            ->where('c.website LIKE :domain1')
            ->orWhere('c.website LIKE :domain2')
            ->setParameter('domain1', "%://{$domain}%")
            ->setParameter('domain2', "%://www.{$domain}%")
            ->getQuery()
            ->getResult();
        
        return $companies[0] ?? null;
    }
    
    /**
     * Calculate similarity between two strings (0-100)
     */
    private function calculateSimilarity(string $str1, string $str2): int
    {
        if ($str1 === $str2) {
            return 100;
        }
        
        // Use Levenshtein distance (max 255 chars per PHP limitation)
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0) {
            return 100;
        }
        
        // levenshtein() crashes on strings > 255 chars; fall back to similar_text
        if (strlen($str1) > 255 || strlen($str2) > 255) {
            similar_text($str1, $str2, $percent);
            return (int)round($percent);
        }
        
        $distance = levenshtein($str1, $str2);
        $similarity = (1 - ($distance / $maxLen)) * 100;
        
        return (int)round($similarity);
    }
}
