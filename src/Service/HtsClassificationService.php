<?php

namespace App\Service;

use App\Entity\HtsMapRule;
use App\Repository\HtsMapRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * HtsClassificationService
 * 
 * Classifies Bill of Materials (BOM) line items to Harmonized Tariff Schedule (HTS) codes
 * using a waterfall approach: PROVIDED → MAPPED → HEURISTIC.
 * 
 * Confidence Scoring:
 * - PROVIDED: 100% (HTS code explicitly provided in BOM)
 * - MAPPED: 85% (Exact MPN match in hts_map_rules table)
 * - HEURISTIC: 60% (Keyword/category matching via hts_map_rules)
 * 
 * Used by: Quote Co-Pilot, Landed-Cost Estimator
 */
class HtsClassificationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HtsMapRuleRepository $htsMapRuleRepository
    ) {}

    /**
     * Classify a single BOM line item to HTS code
     * 
     * @param array $bomLine BOM line data: ['mpn' => 'STM32F407VGT6', 'manufacturer' => 'STMicroelectronics', 'category' => 'Microcontroller', 'description' => '...', 'hts_code' => null]
     * @return array Classification result: ['hts_code' => '8542.39.00', 'confidence' => 85, 'method' => 'MAPPED', 'rule_id' => 123]
     * 
     * TODO Implementation:
     * 1. Check if bomLine['hts_code'] is provided (not null/empty)
     *    → If yes, return ['hts_code' => bomLine['hts_code'], 'confidence' => 100, 'method' => 'PROVIDED']
     * 2. Query hts_map_rules for exact MPN match:
     *    → WHERE mpn_pattern = :mpn AND manufacturer = :manufacturer AND is_active = true
     *    → If match found, return ['hts_code' => rule.hts_code, 'confidence' => 85, 'method' => 'MAPPED', 'rule_id' => rule.id]
     * 3. Apply heuristic matching (keyword/category):
     *    → Call applyHeuristics(bomLine) → returns best matching rule
     *    → Return ['hts_code' => rule.hts_code, 'confidence' => 60, 'method' => 'HEURISTIC', 'rule_id' => rule.id]
     * 4. If no match, return ['hts_code' => null, 'confidence' => 0, 'method' => 'UNKNOWN']
     */
    public function classifyBomLine(array $bomLine): array
    {
        // 1. Check if HTS code is provided
        if (!empty($bomLine['hts_code'])) {
            return [
                'hts_code' => $bomLine['hts_code'],
                'confidence' => 100,
                'method' => 'PROVIDED',
                'rule_id' => null
            ];
        }
        
        // 2. Try exact MPN match
        $mpn = $bomLine['mpn'] ?? null;
        $manufacturer = $bomLine['manufacturer'] ?? null;
        
        if ($mpn && $manufacturer) {
            $mappedRule = $this->htsMapRuleRepository->createQueryBuilder('h')
                ->where('h.mpnPattern = :mpn')
                ->andWhere('h.manufacturer = :manufacturer')
                ->andWhere('h.isActive = true')
                ->setParameter('mpn', $mpn)
                ->setParameter('manufacturer', $manufacturer)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            
            if ($mappedRule) {
                return [
                    'hts_code' => $mappedRule->getHtsCode(),
                    'confidence' => $this->calculateConfidence('MAPPED', $mappedRule),
                    'method' => 'MAPPED',
                    'rule_id' => $mappedRule->getId()
                ];
            }
        }
        
        // 3. Apply heuristic matching
        $heuristicRule = $this->applyHeuristics($bomLine);
        
        if ($heuristicRule) {
            return [
                'hts_code' => $heuristicRule->getHtsCode(),
                'confidence' => $this->calculateConfidence('HEURISTIC', $heuristicRule),
                'method' => 'HEURISTIC',
                'rule_id' => $heuristicRule->getId()
            ];
        }
        
        // 4. No match found
        return [
            'hts_code' => null,
            'confidence' => 0,
            'method' => 'UNKNOWN',
            'rule_id' => null
        ];
    }

    /**
     * Apply heuristic matching rules for HTS classification
     * 
     * @param array $bomLine BOM line data
     * @return HtsMapRule|null Best matching rule (highest priority) or null
     * 
     * TODO Implementation:
     * 1. Extract keywords from bomLine['description'] and bomLine['category']
     * 2. Query hts_map_rules WHERE:
     *    - keyword_pattern matches any extracted keyword (use LIKE '%keyword%')
     *    - OR category = bomLine['category']
     *    - AND is_active = true
     * 3. Order by priority DESC, confidence_score DESC
     * 4. Return first result or null
     * 
     * Example heuristic rules:
     * - keyword_pattern: '%microcontroller%' → hts_code: '8542.39.00'
     * - keyword_pattern: '%resistor%' → hts_code: '8533.21.00'
     * - category: 'Capacitor' → hts_code: '8532.24.00'
     */
    private function applyHeuristics(array $bomLine): ?HtsMapRule
    {
        $description = $bomLine['description'] ?? '';
        $category = $bomLine['category'] ?? '';
        
        // Extract keywords from description (words with 4+ characters)
        $keywords = [];
        if ($description) {
            $words = preg_split('/\s+/', strtolower($description));
            foreach ($words as $word) {
                $cleaned = preg_replace('/[^a-z0-9]/', '', $word);
                if (strlen($cleaned) >= 4) {
                    $keywords[] = $cleaned;
                }
            }
        }
        
        // Build query for heuristic matching
        $qb = $this->htsMapRuleRepository->createQueryBuilder('h')
            ->where('h.isActive = true');
        
        // Add keyword matching conditions
        if (!empty($keywords)) {
            $keywordConditions = [];
            foreach ($keywords as $i => $keyword) {
                $keywordConditions[] = "h.keywordPattern LIKE :keyword{$i}";
                $qb->setParameter("keyword{$i}", "%{$keyword}%");
            }
            
            $qb->andWhere('(' . implode(' OR ', $keywordConditions) . ')');
        }
        
        // Add category matching as alternative
        if ($category) {
            if (!empty($keywords)) {
                $qb->orWhere('h.category = :category');
            } else {
                $qb->andWhere('h.category = :category');
            }
            $qb->setParameter('category', $category);
        }
        
        // If no keywords or category, return null
        if (empty($keywords) && !$category) {
            return null;
        }
        
        // Order by priority and confidence
        $qb->orderBy('h.priority', 'DESC')
            ->addOrderBy('h.confidenceScore', 'DESC')
            ->setMaxResults(1);
        
        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Calculate confidence score for classification
     * 
     * @param string $method Classification method (PROVIDED|MAPPED|HEURISTIC)
     * @param HtsMapRule|null $rule Matching rule (null if PROVIDED)
     * @return int Confidence score 0-100
     * 
     * TODO Implementation:
     * - PROVIDED: Always return 100
     * - MAPPED: Return 85 (or rule.confidence_score if available)
     * - HEURISTIC: Return 60 (or rule.confidence_score if available)
     * - UNKNOWN: Return 0
     */
    public function calculateConfidence(string $method, ?HtsMapRule $rule = null): int
    {
        return match($method) {
            'PROVIDED' => 100,
            'MAPPED' => $rule?->getConfidenceScore() ?? 85,
            'HEURISTIC' => $rule?->getConfidenceScore() ?? 60,
            default => 0,
        };
    }

    /**
     * Classify entire BOM (multiple line items)
     * 
     * @param array $bom Array of BOM lines
     * @return array Array of classification results (same order as input)
     * 
     * TODO Implementation:
     * 1. Loop through each BOM line
     * 2. Call classifyBomLine() for each
     * 3. Return array of results
     * 4. Log any UNKNOWN classifications for review
     */
    public function classifyBom(array $bom): array
    {
        $results = [];
        foreach ($bom as $line) {
            $results[] = $this->classifyBomLine($line);
        }
        return $results;
    }

    /**
     * Get classification statistics for a BOM
     * 
     * @param array $classificationResults Results from classifyBom()
     * @return array Statistics: ['total' => 100, 'provided' => 20, 'mapped' => 50, 'heuristic' => 25, 'unknown' => 5, 'avg_confidence' => 78.5]
     * 
     * TODO Implementation:
     * 1. Count classifications by method
     * 2. Calculate average confidence score
     * 3. Return statistics array
     */
    public function getClassificationStats(array $classificationResults): array
    {
        $stats = [
            'total' => count($classificationResults),
            'provided' => 0,
            'mapped' => 0,
            'heuristic' => 0,
            'unknown' => 0,
            'avg_confidence' => 0
        ];
        
        if (empty($classificationResults)) {
            return $stats;
        }
        
        $totalConfidence = 0;
        
        foreach ($classificationResults as $result) {
            $method = strtolower($result['method'] ?? 'unknown');
            
            if (isset($stats[$method])) {
                $stats[$method]++;
            }
            
            $totalConfidence += $result['confidence'] ?? 0;
        }
        
        $stats['avg_confidence'] = round($totalConfidence / $stats['total'], 1);
        
        return $stats;
    }

    /**
     * Validate HTS code format
     * 
     * @param string $htsCode HTS code to validate (e.g., '8542.39.00')
     * @return bool True if valid format
     * 
     * TODO Implementation:
     * - Check format: 4 digits . 2 digits . 2 digits (e.g., '8542.39.00')
     * - Or: 10 digits without dots (e.g., '8542390000')
     * - Validate digit ranges (valid HTS chapters 01-99)
     */
    public function validateHtsCode(string $htsCode): bool
    {
        // Format with dots: 8542.39.00
        if (preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $htsCode)) {
            return true;
        }
        
        // Format without dots: 8542390000
        if (preg_match('/^\d{10}$/', $htsCode)) {
            return true;
        }
        
        return false;
    }

    /**
     * Normalize HTS code to standard format (with dots)
     * 
     * @param string $htsCode Input HTS code (any format)
     * @return string Normalized HTS code (e.g., '8542.39.00')
     * 
     * TODO Implementation:
     * - If already has dots, return as-is
     * - If 10 digits without dots, insert dots at positions 4 and 6
     * - Throw exception if invalid format
     */
    public function normalizeHtsCode(string $htsCode): string
    {
        // Remove any existing dots
        $cleaned = str_replace('.', '', $htsCode);
        
        if (strlen($cleaned) !== 10) {
            throw new \InvalidArgumentException("Invalid HTS code format: {$htsCode}");
        }
        
        // Insert dots: XXXX.XX.XX
        return substr($cleaned, 0, 4) . '.' . substr($cleaned, 4, 2) . '.' . substr($cleaned, 6, 4);
    }
}
