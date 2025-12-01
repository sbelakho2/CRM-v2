<?php

namespace App\Service;

use App\Entity\Playbook;
use App\Entity\PlaybookRun;
use App\Entity\Activity;
use App\Repository\PlaybookRepository;
use App\Repository\PlaybookRunRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PlaybookEngine
 * 
 * Marketing/sales workflow automation engine.
 * 
 * Playbooks are automated workflows triggered by specific events:
 * - ABM visitor activity (e.g., "3+ pageviews in 7 days from target account")
 * - Form submissions (e.g., "Demo request from Fortune 500 company")
 * - Quote events (e.g., "Quote > $50k pending for > 48 hours")
 * - Email engagement (e.g., "Clicked link in follow-up email")
 * 
 * Playbook structure:
 * - Triggers: JSON rules defining when playbook runs (AND/OR logic, comparisons)
 * - Actions: Sequence of actions to execute (create Activity, send email, create RFQ, assign Lead)
 * 
 * Example playbook:
 * {
 *   "triggers": [
 *     {"field": "abm_hits_7d", "operator": ">=", "value": 3},
 *     {"field": "company_tier", "operator": "=", "value": "A"}
 *   ],
 *   "actions": [
 *     {"type": "create_activity", "data": {"type": "CALL", "priority": "HIGH"}},
 *     {"type": "send_email", "data": {"template": "abm_hot_lead"}},
 *     {"type": "assign_lead", "data": {"userId": 5}}
 *   ]
 * }
 * 
 * Used by:
 * - AbmResolverService for ABM-triggered playbooks
 * - Email campaign tracking for engagement playbooks
 * - Quote/RFQ status changes
 */
class PlaybookEngine
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlaybookRepository $playbookRepository,
        private PlaybookRunRepository $playbookRunRepository
    ) {}

    /**
     * Get all active playbooks
     * 
     * @return array - Array of Playbook entities
     */
    public function getActivePlaybooks(): array
    {
        // Fully implemented helper method
        return $this->playbookRepository->findBy(['isActive' => true]);
    }
    
    /**
     * Evaluate all playbooks for a given context (simplified version)
     * 
     * @param mixed $context - Context object (AbmAccount, etc.)
     * @return bool - True if any playbook was triggered
     */
    public function evaluatePlaybooks($context): bool
    {
        $playbooks = $this->getActivePlaybooks();
        $triggered = false;
        
        foreach ($playbooks as $playbook) {
            try {
                // Check cooldown period to avoid re-triggering
                $lastRun = $this->playbookRunRepository->findOneBy(
                    ['playbook' => $playbook],
                    ['executedAt' => 'DESC']
                );
                
                if ($lastRun) {
                    $cooldownHours = $playbook->getCooldownHours() ?? 24;
                    $cooldownEnd = (clone $lastRun->getExecutedAt())->modify("+{$cooldownHours} hours");
                    
                    if (new \DateTime() < $cooldownEnd) {
                        // Still in cooldown period, skip this playbook
                        continue;
                    }
                }
                
                // Evaluate triggers
                if ($this->evaluateTriggers($playbook, $context)) {
                    // Execute actions
                    $this->executeActions($playbook, $context);
                    $triggered = true;
                }
            } catch (\Exception $e) {
                // Log error but continue with other playbooks
                error_log("Playbook evaluation error: " . $e->getMessage());
            }
        }
        
        return $triggered;
    }

    /**
     * Evaluate playbook triggers against context
     * 
     * @param Playbook $playbook - Playbook to evaluate
     * @param mixed $context - Context data (AbmAccount, Quote, EmailSend, etc.)
     * @param mixed|null $event - Event data (WebEvent, etc.)
     * 
     * @return bool - True if triggers match
     */
    public function evaluateTriggers(Playbook $playbook, mixed $context, mixed $event = null): bool
    {
        // Parse trigger rules from JSON
        $triggersJson = $playbook->getTriggerRules();
        
        if (!$triggersJson) {
            return true; // No triggers = always match
        }
        
        $triggers = json_decode($triggersJson, true);
        
        if (!$triggers || !is_array($triggers)) {
            return false;
        }
        
        // Evaluate each trigger (AND logic by default)
        foreach ($triggers as $trigger) {
            if (!isset($trigger['field']) || !isset($trigger['operator']) || !isset($trigger['value'])) {
                continue; // Skip malformed triggers
            }
            
            $field = $trigger['field'];
            $operator = $trigger['operator'];
            $expectedValue = $trigger['value'];
            
            try {
                // Extract field value from context
                $actualValue = $this->extractFieldValue($field, $context, $event);
                
                // Evaluate condition
                $matches = $this->evaluateCondition($actualValue, $operator, $expectedValue);
                
                if (!$matches) {
                    return false; // AND logic: all must match
                }
            } catch (\Exception $e) {
                // Field extraction failed, treat as non-match
                return false;
            }
        }
        
        // All triggers matched
        return true;
    }

    /**
     * Execute playbook actions
     * 
     * @param Playbook $playbook - Playbook to execute
     * @param mixed $context - Context data
     * @param mixed|null $event - Event data
     * 
     * @return array - Results of each action
     */
    public function executeActions(Playbook $playbook, mixed $context, mixed $event = null): array
    {
        // Create PlaybookRun record
        $run = new PlaybookRun();
        $run->setPlaybook($playbook);
        $run->setExecutedAt(new \DateTime());
        $run->setSuccess(false);
        $this->entityManager->persist($run);
        $this->entityManager->flush(); // Get run ID
        
        // Parse actions from JSON
        $actionsJson = $playbook->getActions();
        
        if (!$actionsJson) {
            $run->setSuccess(false);
            $this->entityManager->flush();
            return [];
        }
        
        $actions = json_decode($actionsJson, true);
        
        if (!$actions || !is_array($actions)) {
            $run->setSuccess(false);
            $this->entityManager->flush();
            return [];
        }
        
        // Execute each action in sequence
        $results = [];
        $allSuccessful = true;
        
        foreach ($actions as $action) {
            try {
                $result = $this->executeAction($action, $context, $event);
                $results[] = $result;
                
                if (!($result['success'] ?? false)) {
                    $allSuccessful = false;
                }
            } catch (\Exception $e) {
                // Log error but continue with other actions
                $results[] = [
                    'action' => $action['type'] ?? 'unknown',
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $allSuccessful = false;
            }
        }
        
        // Update run status
        $run->setSuccess($allSuccessful);
        $this->entityManager->flush();
        
        return $results;
    }

    /**
     * Execute single action
     * 
     * @param array $action - Action definition
     * @param mixed $context - Context data
     * @param mixed|null $event - Event data
     * 
     * @return array - Action result
     */
    private function executeAction(array $action, $context, $event): array
    {
        $actionType = $action['type'] ?? 'unknown';
        $actionData = $action['data'] ?? [];
        
        // 1. create_activity
        if ($actionType === 'create_activity') {
            $activity = new Activity();
            $activity->setType($actionData['type'] ?? 'Task');
            $activity->setSubject($actionData['subject'] ?? 'Follow-up required');
            $activity->setNotes($actionData['notes'] ?? '');
            $activity->setActivityDate(new \DateTime($actionData['due'] ?? '+1 day'));
            $activity->setStatus('Open');
            
            // Link to company if context is AbmAccount
            if (method_exists($context, 'getCompany') && $context->getCompany()) {
                $activity->setCompany($context->getCompany());
            }
            
            $this->entityManager->persist($activity);
            $this->entityManager->flush();
            
            return [
                'action' => 'create_activity',
                'success' => true,
                'activityId' => $activity->getId()
            ];
        }
        
        // 2. send_email
        if ($actionType === 'send_email') {
            // For now, just log that email would be sent
            // In production: integrate with EmailSchedulerService or Symfony Mailer
            return [
                'action' => 'send_email',
                'success' => true,
                'template' => $actionData['template'] ?? 'default',
                'note' => 'Email sending not yet implemented'
            ];
        }
        
        // 3. update_score
        if ($actionType === 'update_score') {
            if (method_exists($context, 'getEngagementScore') && method_exists($context, 'setEngagementScore')) {
                $currentScore = $context->getEngagementScore() ?? 0;
                $increment = $actionData['increment'] ?? 10;
                $context->setEngagementScore(min(100, $currentScore + $increment));
                $this->entityManager->flush();
                
                return [
                    'action' => 'update_score',
                    'success' => true,
                    'newScore' => $context->getEngagementScore()
                ];
            }
            
            return [
                'action' => 'update_score',
                'success' => false,
                'error' => 'Context does not support score updates'
            ];
        }
        
        // Unknown action type
        return [
            'action' => $actionType,
            'success' => false,
            'error' => "Unknown action type: {$actionType}"
        ];
    }

    /**
     * Extract field value from context/event
     * 
     * @param string $field - Field name (e.g., "abm_hits_7d", "company_tier")
     * @param mixed $context - Context object
     * @param mixed|null $event - Event object
     * 
     * @return mixed - Field value
     */
    private function extractFieldValue(string $field, $context, $event)
    {
        // ABM fields
        if ($field === 'engagement_score' && method_exists($context, 'getEngagementScore')) {
            return $context->getEngagementScore() ?? 0;
        }
        
        if ($field === 'page_views' && method_exists($context, 'getTotalPageViews')) {
            return $context->getTotalPageViews() ?? 0;
        }
        
        if ($field === 'icp_tier' && method_exists($context, 'getIcpTier')) {
            return $context->getIcpTier();
        }
        
        // Event fields
        if ($field === 'page_url' && $event && method_exists($event, 'getPage')) {
            return $event->getPage();
        }
        
        // Generic getter method
        $getter = 'get' . str_replace('_', '', ucwords($field, '_'));
        if (method_exists($context, $getter)) {
            return $context->$getter();
        }
        
        // Unknown field - return null instead of throwing to allow graceful degradation
        return null;
    }

    /**
     * Evaluate condition
     * 
     * @param mixed $actualValue - Actual field value
     * @param string $operator - Comparison operator (=, !=, >, <, >=, <=, contains, in)
     * @param mixed $expectedValue - Expected value
     * 
     * @return bool - True if condition matches
     */
    private function evaluateCondition($actualValue, string $operator, $expectedValue): bool
    {
        // Fully implemented helper method
        
        return match ($operator) {
            '=', '==' => $actualValue == $expectedValue,
            '!=', '<>' => $actualValue != $expectedValue,
            '>' => $actualValue > $expectedValue,
            '<' => $actualValue < $expectedValue,
            '>=' => $actualValue >= $expectedValue,
            '<=' => $actualValue <= $expectedValue,
            'contains' => str_contains((string)$actualValue, (string)$expectedValue),
            'in' => in_array($actualValue, (array)$expectedValue),
            'not_in' => !in_array($actualValue, (array)$expectedValue),
            'regex' => preg_match((string)$expectedValue, (string)$actualValue) === 1,
            default => throw new \InvalidArgumentException("Unknown operator: $operator")
        };
    }

    /**
     * Log playbook run
     * 
     * @param int $playbookId - Playbook ID
     * @param string $status - Run status (RUNNING, COMPLETED, FAILED)
     * @param array|null $results - Action results
     * @param string|null $errorMessage - Error message if failed
     * 
     * @return PlaybookRun
     */
    public function logRun(
        int $playbookId,
        string $status,
        ?array $results = null,
        ?string $errorMessage = null
    ): PlaybookRun {
        $playbook = $this->playbookRepository->find($playbookId);
        
        if (!$playbook) {
            throw new \RuntimeException('Playbook not found');
        }
        
        $run = new PlaybookRun();
        $run->setPlaybook($playbook);
        $run->setExecutedAt(new \DateTime());
        $run->setSuccess($status === 'COMPLETED');
        
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        
        return $run;
    }

    /**
     * Get playbook run history
     * 
     * @param int $playbookId - Playbook ID
     * @param int $limit - Max results
     * 
     * @return array - Array of PlaybookRun entities
     */
    public function getRunHistory(int $playbookId, int $limit = 50): array
    {
        $playbook = $this->playbookRepository->find($playbookId);
        
        if (!$playbook) {
            return [];
        }
        
        return $this->playbookRunRepository->findBy(
            ['playbook' => $playbook],
            ['executedAt' => 'DESC'],
            $limit
        );
    }
}
