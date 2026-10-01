<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Quote;
use App\Entity\DfmRule;
use App\Entity\DfmFinding;
use App\Repository\QuoteRepository;
use App\Repository\DfmRuleRepository;
use App\Repository\DfmFindingRepository;
use App\Repository\BomLineRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * DfmLintService
 * 
 * Design for Manufacturing (DFM) and Design for Assembly (DFA) linting service.
 * 
 * Analyzes BOM and PCB design files for manufacturability issues:
 * - Component selection (obsolete parts, single-source parts, exotic packages)
 * - PCB design rules (trace width, spacing, via sizes, layer count)
 * - Assembly constraints (part density, tombstoning risk, thermal issues)
 * - Cost optimization (high-cost parts, over-spec'd components)
 * 
 * Severity levels:
 * - CRITICAL: Prevents manufacturing (e.g., obsolete parts, unavailable packages)
 * - HIGH: Significantly impacts cost/lead time (e.g., exotic parts, tight tolerances)
 * - MEDIUM: Moderate impact (e.g., non-preferred parts, suboptimal design)
 * - LOW: Best practice recommendations (e.g., component standardization)
 * 
 * Used by:
 * - QuoteCoPilotController for automatic DFM checks on quote generation
 * - Quote detail page for manual DFM lint runs
 * - UnifiedPdfGeneratorService for DFM report PDF generation
 */
class DfmLintService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private QuoteRepository $quoteRepository,
        private DfmRuleRepository $dfmRuleRepository,
        private DfmFindingRepository $dfmFindingRepository,
        private BomLineRepository $bomLineRepository
    ) {}

    /**
     * Lint entire BOM for DFM/DFA issues
     * 
     * @param int $quoteId - Quote ID
     * @param array $options - Lint options (severity filter, rule categories, etc.)
     * 
     * @return array{
     *   findingsCount: int,
     *   criticalCount: int,
     *   highCount: int,
     *   mediumCount: int,
     *   lowCount: int,
     *   findings: array
     * }
     */
    public /**
 * @param array<string|int, mixed> $options
 */
function lintBom(int $quoteId, array $options = []): array
    {
        // 1. Get all BOM lines for quote
        $bomLines = $this->bomLineRepository->findBy(['quote' => $quoteId]);
        
        if (empty($bomLines)) {
            return [
                'findingsCount' => 0,
                'criticalCount' => 0,
                'highCount' => 0,
                'mediumCount' => 0,
                'lowCount' => 0,
                'findings' => []
            ];
        }
        
        // 2. Get all active DFM rules
        $rules = $this->dfmRuleRepository->findBy(['isActive' => true]);
        
        // Filter by category if specified
        if (isset($options['categories']) && !empty($options['categories'])) {
            $rules = array_filter($rules, fn($r) => in_array($r->getRuleType(), $options['categories']));
        }
        
        // 3. Apply each rule to BOM
        $findings = [];
        foreach ($rules as $rule) {
            $ruleFindings = $this->applyRule($rule, $bomLines, $quoteId);
            $findings = array_merge($findings, $ruleFindings);
        }
        
        // 4. Categorize findings by severity
        $categorized = [
            'CRITICAL' => 0,
            'HIGH' => 0,
            'MEDIUM' => 0,
            'LOW' => 0,
        ];
        
        foreach ($findings as $finding) {
            $severity = $finding->getSeverity();
            if (isset($categorized[$severity])) {
                $categorized[$severity]++;
            }
        }
        
        // 5. Persist findings to database
        foreach ($findings as $finding) {
            $this->entityManager->persist($finding);
        }
        $this->entityManager->flush();
        
        // 6. Return summary
        return [
            'findingsCount' => count($findings),
            'criticalCount' => $categorized['CRITICAL'],
            'highCount' => $categorized['HIGH'],
            'mediumCount' => $categorized['MEDIUM'],
            'lowCount' => $categorized['LOW'],
            'findings' => $findings
        ];
    }

    /**
     * Apply single DFM rule to BOM lines
     * 
     * @param DfmRule $rule - DFM rule to apply
     * @param array $bomLines - Array of BomLine entities
     * @param int $quoteId - Quote ID
     * 
     * @return array - Array of DfmFinding entities
     */
    public /**
 * @param array<string|int, mixed> $bomLines
 */
function applyRule(DfmRule $rule, array $bomLines, int $quoteId): array
    {
        // 1. Parse rule condition (JSON)
        $ruleConditionJson = $rule->getCheckLogic();
        if (empty($ruleConditionJson)) {
            return [];
        }
        
        /** @var array<string, mixed>|null $ruleCondition */
        $ruleCondition = json_decode($ruleConditionJson, true);
        if (!$ruleCondition) {
            return [];
        }
        
        // 2. Evaluate condition for each BOM line
        $findings = [];
        foreach ($bomLines as $bomLine) {
            try {
                $matches = $this->evaluateCondition($ruleCondition, $bomLine);
                
                if ($matches) {
                    // Create DfmFinding against the REAL model: Quote and
                    // DfmRule associations, findingType/description fields,
                    // createdAt via PrePersist (no phantom ID/category/
                    // message/detectedAt setters).
                    $finding = new DfmFinding();
                    $finding->setQuote($this->entityManager->find(\App\Entity\Quote::class, $quoteId));
                    $finding->setDfmRule($rule);
                    $finding->setSeverity($rule->getSeverity());
                    $finding->setFindingType($rule->getRuleType());
                    $finding->setDescription($this->formatMessage($rule->getDescription() ?? '', $bomLine));
                    $finding->setRemediation($rule->getRemediationText() ?? 'Contact engineering for guidance');
                    $finding->setMetadata(['bom_line_id' => $bomLine->getId()]);
                    
                    $findings[] = $finding;
                }
            } catch (\Exception $e) {
                // Skip this BOM line if evaluation fails
                continue;
            }
        }
        
        // 3. Return findings
        return $findings;
    }

    /**
     * Categorize findings by severity level
     * 
     * @param array $findings - Array of DfmFinding entities
     * 
     * @return array{
     *   CRITICAL: int,
     *   HIGH: int,
     *   MEDIUM: int,
     *   LOW: int
     * }
     */
    public /**
 * @param array<string|int, mixed> $findings
 */
function categorizeFindings(array $findings): array
    {
        // Fully implemented helper method
        
        $counts = [
            'CRITICAL' => 0,
            'HIGH' => 0,
            'MEDIUM' => 0,
            'LOW' => 0
        ];
        
        foreach ($findings as $finding) {
            $severity = $finding->getSeverity();
            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }
        
        return $counts;
    }

    /**
     * Evaluate rule condition against BOM line
     * 
     * @param array $condition - Parsed JSON condition
     * @param mixed $bomLine - BomLine entity
     * 
     * @return bool - True if condition matches
     */
    private /**
 * @param array<string|int, mixed> $condition
 */
function evaluateCondition(array $condition, $bomLine): bool
    {
        // 1. Get field value from BomLine
        $field = $condition['field'] ?? null;
        if (!$field) {
            return false;
        }
        
        // Try standard getter
        $getter = 'get' . ucfirst($field);
        if (method_exists($bomLine, $getter)) {
            $value = $bomLine->$getter();
        } else {
            // Try alternative getter patterns (e.g., 'is' for booleans)
            $isGetter = 'is' . ucfirst($field);
            if (method_exists($bomLine, $isGetter)) {
                $value = $bomLine->$isGetter();
            } else {
                // Field doesn't exist on entity
                return false;
            }
        }
        
        // 2. Evaluate operator
        $operator = $condition['operator'] ?? 'equals';
        $expectedValue = $condition['value'] ?? null;
        
        switch ($operator) {
            case 'equals':
                return $value == $expectedValue;
                
            case 'notEquals':
                return $value != $expectedValue;
                
            case 'in':
                return is_array($expectedValue) && in_array($value, $expectedValue);
                
            case 'notIn':
                return is_array($expectedValue) && !in_array($value, $expectedValue);
                
            case 'greaterThan':
                return is_numeric($value) && is_numeric($expectedValue) && $value > $expectedValue;
                
            case 'lessThan':
                return is_numeric($value) && is_numeric($expectedValue) && $value < $expectedValue;
                
            case 'greaterOrEqual':
                return is_numeric($value) && is_numeric($expectedValue) && $value >= $expectedValue;
                
            case 'lessOrEqual':
                return is_numeric($value) && is_numeric($expectedValue) && $value <= $expectedValue;
                
            case 'contains':
                return is_string($value) && is_string($expectedValue) && str_contains($value, $expectedValue);
                
            case 'notContains':
                return is_string($value) && is_string($expectedValue) && !str_contains($value, $expectedValue);
                
            case 'regex':
                return is_string($value) && is_string($expectedValue) && preg_match($expectedValue, $value) === 1;
                
            case 'isEmpty':
                return empty($value);
                
            case 'isNotEmpty':
                return !empty($value);
                
            default:
                throw new \InvalidArgumentException("Unknown operator: $operator");
        }
    }

    /**
     * Format message template with BOM line data
     * 
     * @param string $template - Message template with placeholders
     * @param mixed $bomLine - BomLine entity
     * 
     * @return string - Formatted message
     */
    private function formatMessage(string $template, $bomLine): string
    {
        // Derive designator from available data (BomLine has no direct designator field)
        $sourcingData = null;
        $designator = 'N/A';
        if (method_exists($bomLine, 'getSourcingData')) {
            $sourcingData = $bomLine->getSourcingData();
            if (is_array($sourcingData) && !empty($sourcingData['designator'])) {
                $designator = $sourcingData['designator'];
            }
        }

        // Replace common BOM line placeholders
        $replacements = [
            '{mpn}' => $bomLine->getMpn() ?? 'N/A',
            '{manufacturer}' => $bomLine->getManufacturer() ?? 'N/A',
            '{description}' => $bomLine->getDescription() ?? 'N/A',
            '{designator}' => $designator,
            '{quantity}' => $bomLine->getQuantity() ?? 0,
            '{unitPrice}' => $bomLine->getUnitPrice() ?? 0,
            '{supplier}' => $bomLine->getSupplierName() ?? 'N/A',
            '{leadTimeDays}' => $bomLine->getLeadTimeDays() ?? 'N/A',
        ];
        
        $message = $template;
        foreach ($replacements as $placeholder => $value) {
            $message = str_replace($placeholder, (string) $value, $message);
        }
        
        return $message;
    }

    /**
     * Get DFM findings for a quote
     * 
     * @param int $quoteId - Quote ID
     * @param string|null $severityFilter - Filter by severity (CRITICAL, HIGH, MEDIUM, LOW)
     * 
     * @return array - Array of DfmFinding entities
     */
    public function getFindings(int $quoteId, ?string $severityFilter = null): array
    {
        // 1. Build query criteria
        $criteria = ['quoteId' => $quoteId];
        if ($severityFilter) {
            $criteria['severity'] = $severityFilter;
        }
        
        // 2. Query findings (DB sorts alphabetically which is wrong for severity)
        $findings = $this->dfmFindingRepository->findBy(
            $criteria,
            ['detectedAt' => 'DESC']
        );
        
        // 3. Sort by severity in correct priority order:
        //    CRITICAL → HIGH → MEDIUM → LOW → INFO
        $severityOrder = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3, 'INFO' => 4];
        usort($findings, function ($a, $b) use ($severityOrder) {
            $aOrder = $severityOrder[$a->getSeverity()] ?? 99;
            $bOrder = $severityOrder[$b->getSeverity()] ?? 99;
            return $aOrder <=> $bOrder;
        });
        
        return $findings;
    }

    /**
     * Mark finding as resolved/dismissed
     * 
     * @param int $findingId - Finding ID
     * @param string $resolution - Resolution type (FIXED, ACCEPTED_RISK, DISMISSED)
     * @param string|null $notes - Resolution notes
     */
    public function resolveFinding(int $findingId, string $resolution, ?string $notes = null): void
    {
        // 1. Get finding
        $finding = $this->dfmFindingRepository->find($findingId);
        if (!$finding) {
            throw new \RuntimeException("Finding $findingId not found");
        }
        
        // 2. Update resolution fields
        $finding->setResolution($resolution);
        $finding->setResolutionNotes($notes);
        $finding->setResolvedAt(new \DateTime());
        
        // 3. Flush changes
        $this->entityManager->flush();
    }

    /**
     * Get DFM statistics for dashboard
     * 
     * @param int $quoteId - Quote ID
     * 
     * @return array{
     *   totalFindings: int,
     *   bySeverity: array,
     *   byCategory: array,
     *   resolvedCount: int,
     *   unresolvedCount: int
     * }
     */
    public function getStatistics(int $quoteId): array
    {
        // 1. Get all findings for quote
        $findings = $this->dfmFindingRepository->findBy(['quoteId' => $quoteId]);
        
        // 2. Count by severity
        $bySeverity = [
            'CRITICAL' => 0,
            'HIGH' => 0,
            'MEDIUM' => 0,
            'LOW' => 0,
            'INFO' => 0
        ];
        
        foreach ($findings as $finding) {
            $severity = $finding->getSeverity();
            if (isset($bySeverity[$severity])) {
                $bySeverity[$severity]++;
            }
        }
        
        // 3. Count by category
        $byCategory = [];
        foreach ($findings as $finding) {
            $category = $finding->getFindingType() ?? 'unknown';
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
        }
        
        // 4. Count resolved/unresolved
        $resolvedCount = 0;
        foreach ($findings as $finding) {
            if ($finding->getResolvedAt() !== null) {
                $resolvedCount++;
            }
        }
        
        // 5. Return statistics
        return [
            'totalFindings' => count($findings),
            'bySeverity' => $bySeverity,
            'byCategory' => $byCategory,
            'resolvedCount' => $resolvedCount,
            'unresolvedCount' => count($findings) - $resolvedCount
        ];
    }

    /**
     * Import DFM rules from JSON file
     * 
     * @param string $jsonPath - Path to JSON rules file
     * 
     * @return int - Number of rules imported
     */
    public function importRules(string $jsonPath): int
    {
        // 1. Validate file exists
        if (!file_exists($jsonPath)) {
            throw new \RuntimeException("Rules file not found: $jsonPath");
        }
        
        // 2. Parse JSON file
        $jsonContent = file_get_contents($jsonPath);
        /** @var array<string, mixed>|null $rulesData */
        $rulesData = json_decode($jsonContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }
        
        if (!is_array($rulesData)) {
            throw new \RuntimeException('Rules data must be an array');
        }
        
        // 3. Create DfmRule entities
        $count = 0;
        foreach ($rulesData as $ruleData) {
            if (!isset($ruleData['name'], $ruleData['category'], $ruleData['severity'])) {
                continue; // Skip invalid rules
            }
            
            $rule = new DfmRule();
            $rule->setRuleName($ruleData['name']);
            $rule->setRuleType($ruleData['category']);
            $rule->setSeverity($ruleData['severity']);
            $rule->setCheckLogic(json_encode($ruleData['condition'] ?? []));
            $rule->setDescription($ruleData['message'] ?? '');
            $rule->setRemediationText($ruleData['remediation'] ?? '');
            $rule->setIsActive($ruleData['active'] ?? true);
            
            $this->entityManager->persist($rule);
            $count++;
            
            // Batch flush every 50 rules
            if ($count % 50 === 0) {
                $this->entityManager->flush();
            }
        }
        
        // 4. Final flush and return count
        $this->entityManager->flush();
        return $count;
    }
}
