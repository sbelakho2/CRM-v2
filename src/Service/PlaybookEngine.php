<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Playbook;
use App\Entity\PlaybookRun;
use App\Entity\Activity;
use App\Repository\PlaybookRepository;
use App\Repository\PlaybookRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

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
 *     {"type": "send_email", "data": {"template": "abm_hot_lead", "to": "sales@example.com"}},
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
    // Email templates available for playbook actions
    private const EMAIL_TEMPLATES = [
        'abm_hot_lead' => 'playbook/emails/abm_hot_lead.html.twig',
        'follow_up' => 'playbook/emails/follow_up.html.twig',
        'quote_reminder' => 'playbook/emails/quote_reminder.html.twig',
        'default' => 'playbook/emails/default.html.twig',
    ];
    
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlaybookRepository $playbookRepository,
        private PlaybookRunRepository $playbookRunRepository,
        private ?MailerInterface $mailer = null,
        private ?Environment $twig = null,
        private ?LoggerInterface $logger = null,
        private ?string $mailerFromAddress = null, // Bound via services.yaml
        private ?UrlGeneratorInterface $urlGenerator = null,
        private ?RequestStack $requestStack = null
    ) {}

    /**
     * Get all active playbooks
     * 
     * @return array - Array of Playbook entities
     */
    public function getActivePlaybooks(): array
    {
        // Fully implemented helper method
        // Executable playbooks exclude archived rows even if isActive lingers
        // true — findAllActive() enforces the archive invariant.
        return $this->playbookRepository->findAllActive();
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
                    ['triggeredAt' => 'DESC']
                );
                
                if ($lastRun) {
                    $cooldownHours = $playbook->getCooldownHours() ?? 24;
                    $cooldownEnd = (clone $lastRun->getTriggeredAt())->modify("+{$cooldownHours} hours");
                    
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
                $this->logger?->error('Playbook evaluation error: ' . $e->getMessage(), ['exception' => $e]);
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
        if ($context instanceof \App\Entity\AbmHit) {
            $run->setAbmHit($context);
        }
        $run->setStatus('in_progress');
        $this->entityManager->persist($run);
        $this->entityManager->flush(); // Get run ID
        
        // Parse actions from JSON
        $actionsJson = $playbook->getActions();
        
        if (!$actionsJson) {
            $run->setStatus('failed');
            $run->setErrorMessage('No actions configured');
            $run->setCompletedAt(new \DateTime());
            $this->entityManager->flush();
            return [];
        }
        
        $actions = json_decode($actionsJson, true);
        
        if (!$actions || !is_array($actions)) {
            $run->setStatus('failed');
            $run->setErrorMessage('Invalid actions JSON');
            $run->setCompletedAt(new \DateTime());
            $this->entityManager->flush();
            return [];
        }
        
        // Execute each action in sequence
        $results = [];
        $allSuccessful = true;
        $firstError = null;
        
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
                $firstError ??= $e->getMessage();
            }
        }
        
        // Update run status
        $run->setExecutionLog(json_encode(['results' => $results]));
        $run->setTasksCreated(count(array_filter($results, static fn(array $r): bool => (bool)($r['success'] ?? false))));
        $run->setStatus($allSuccessful ? 'completed' : 'failed');
        $run->setCompletedAt(new \DateTime());
        if (!$allSuccessful) {
            $run->setErrorMessage($firstError);
        }
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
    private /**
 * @param array<string|int, mixed> $action
 */
function executeAction(array $action, $context, $event): array
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
            return $this->executeSendEmailAction($actionData, $context, $event);
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
    public /**
 * @param array<string|int, mixed> $results
 */
function logRun(
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

        $normalizedStatus = strtolower($status);
        $normalizedStatus = match ($normalizedStatus) {
            'running', 'in_progress' => 'in_progress',
            'completed', 'success' => 'completed',
            'failed', 'error' => 'failed',
            default => 'pending',
        };
        $run->setStatus($normalizedStatus);
        if ($results !== null) {
            $run->setExecutionLog(json_encode(['results' => $results]));
        }
        if ($errorMessage !== null) {
            $run->setErrorMessage($errorMessage);
        }
        if (in_array($normalizedStatus, ['completed', 'failed'], true)) {
            $run->setCompletedAt(new \DateTime());
        }
        
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
            ['triggeredAt' => 'DESC'],
            $limit
        );
    }
    
    // ============================================================
    // EMAIL ACTION IMPLEMENTATION
    // ============================================================
    
    /**
     * Execute send_email action with actual mailer integration
     * 
     * @param array $actionData - Action configuration (template, to, subject, etc.)
     * @param mixed $context - Context object (AbmAccount, etc.)
     * @param mixed|null $event - Event object that triggered the playbook
     * 
     * @return array - Execution result
     */
    private /**
 * @param array<string|int, mixed> $actionData
 */
function executeSendEmailAction(array $actionData, $context, $event): array
    {
        $templateKey = $actionData['template'] ?? 'default';
        $recipient = $actionData['to'] ?? null;
        $subject = $actionData['subject'] ?? 'CRM Notification: ' . ucfirst(str_replace('_', ' ', $templateKey));
        
        // Extract recipient from context if not specified
        if (!$recipient && method_exists($context, 'getCompany')) {
            $company = $context->getCompany();
            if ($company && method_exists($company, 'getPrimaryEmail')) {
                $recipient = $company->getPrimaryEmail();
            }
        }
        
        // Check if mailer is available
        if ($this->mailer === null) {
            $this->logger?->warning('PlaybookEngine: Mailer not configured, email action logged only', [
                'template' => $templateKey,
                'to' => $recipient,
                'subject' => $subject,
            ]);
            
            return [
                'action' => 'send_email',
                'success' => false,
                'template' => $templateKey,
                'error' => 'Mailer not configured - email logged only',
                'would_send_to' => $recipient,
            ];
        }
        
        if (!$recipient) {
            return [
                'action' => 'send_email',
                'success' => false,
                'error' => 'No recipient specified and could not extract from context',
            ];
        }
        
        if (!$this->mailerFromAddress) {
            $this->logger?->error('PlaybookEngine: no sender address configured (bind $mailerFromAddress), email action cannot be sent', [
                'template' => $templateKey,
                'to' => $recipient,
            ]);

            return [
                'action' => 'send_email',
                'success' => false,
                'template' => $templateKey,
                'to' => $recipient,
                'error' => 'No sender address configured - email not sent',
            ];
        }

        try {
            // Build template context
            $templateContext = $this->buildEmailTemplateContext($context, $event, $actionData, $recipient);
            
            // Render email body
            $templatePath = self::EMAIL_TEMPLATES[$templateKey] ?? self::EMAIL_TEMPLATES['default'];
            $body = $this->renderEmailTemplate($templatePath, $templateContext);
            
            // Create and send email
            $email = (new Email())
                ->from($this->mailerFromAddress)
                ->to($recipient)
                ->subject($subject)
                ->html($body);
            
            // Add CC if specified
            if (!empty($actionData['cc'])) {
                $email->cc(...(array)$actionData['cc']);
            }
            
            $this->mailer->send($email);
            
            $this->logger?->info('PlaybookEngine: Email sent successfully', [
                'template' => $templateKey,
                'to' => $recipient,
                'subject' => $subject,
            ]);
            
            return [
                'action' => 'send_email',
                'success' => true,
                'template' => $templateKey,
                'to' => $recipient,
                'subject' => $subject,
            ];
            
        } catch (\Exception $e) {
            $this->logger?->error('PlaybookEngine: Email send failed', [
                'template' => $templateKey,
                'to' => $recipient,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'action' => 'send_email',
                'success' => false,
                'template' => $templateKey,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Build template context from context and event objects
     */
    private /**
 * @param array<string|int, mixed> $actionData
 */
function buildEmailTemplateContext($context, $event, array $actionData, ?string $recipient = null): array
    {
        $templateContext = [
            'action_data' => $actionData,
            'timestamp' => new \DateTime(),
            'recipient' => $recipient,
            'unsubscribe_url' => $recipient ? $this->buildUnsubscribeUrl($recipient) : null,
        ];
        
        // Add context data
        if (method_exists($context, 'getAccountName')) {
            $templateContext['account_name'] = $context->getAccountName();
        }
        if (method_exists($context, 'getDomain')) {
            $templateContext['domain'] = $context->getDomain();
        }
        if (method_exists($context, 'getEngagementScore')) {
            $templateContext['engagement_score'] = $context->getEngagementScore();
        }
        if (method_exists($context, 'getCompany')) {
            $company = $context->getCompany();
            if ($company) {
                $templateContext['company'] = $company;
                $templateContext['company_name'] = method_exists($company, 'getName') 
                    ? $company->getName() 
                    : 'Unknown';
            }
        }
        
        // Add event data
        if ($event) {
            $templateContext['event'] = $event;
            if (method_exists($event, 'getPage')) {
                $templateContext['page_visited'] = $event->getPage();
            }
        }
        
        return $templateContext;
    }
    
    /**
     * Build the one-click unsubscribe URL for a recipient, using the same
     * HMAC-signed token scheme as EmailConsentService::generateUnsubscribeLink()
     * so the /email/unsubscribe endpoint can process it.
     *
     * The URL is generated from the configured router default_uri (or the
     * current request context when available). If no URL can be determined,
     * the link is skipped and the problem is logged loudly — a fabricated
     * domain must never be emitted.
     *
     * @return string|null The absolute unsubscribe URL, or null when no URL is configurable
     */
    private function buildUnsubscribeUrl(string $email): ?string
    {
        $secret = $_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? getenv('APP_SECRET');
        $secret = (string) $secret;

        if ($secret === '') {
            $this->logger?->error('PlaybookEngine: APP_SECRET not configured, unsubscribe link cannot be signed and is skipped');
            return null;
        }

        $timestamp = time();
        $payload = $email . '|' . $timestamp;
        $hmac = hash_hmac('sha256', $payload, $secret);
        $token = base64_encode($payload . '|' . $hmac);

        $params = ['token' => $token];

        // Prefer an absolute URL generated from the routing configuration so
        // the router default_uri (DEFAULT_URI) is honored. When a request is
        // available, derive the context from it instead.
        if ($this->urlGenerator !== null) {
            $context = $this->urlGenerator->getContext();
            $request = $this->requestStack?->getMainRequest();
            if ($request !== null) {
                $context = $context->fromRequest($request);
            }

            if ($context->getScheme() !== '' && $context->getHost() !== '') {
                try {
                    return $this->urlGenerator->generate(
                        'email_unsubscribe',
                        $params,
                        UrlGeneratorInterface::ABSOLUTE_URL,
                        $context
                    );
                } catch (\Exception $e) {
                    $this->logger?->warning('PlaybookEngine: could not generate unsubscribe route URL, falling back to DEFAULT_URI', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $defaultUri = (string) ($_ENV['DEFAULT_URI'] ?? $_SERVER['DEFAULT_URI'] ?? getenv('DEFAULT_URI') ?? '');
        $baseUrl = rtrim($defaultUri, '/');

        if ($baseUrl !== '') {
            return $baseUrl . '/email/unsubscribe?' . http_build_query($params);
        }

        $this->logger?->error(
            'PlaybookEngine: cannot build unsubscribe URL - no request context and DEFAULT_URI is not configured; unsubscribe link skipped',
            ['recipient' => $email]
        );

        return null;
    }

    /**
     * Render email template, with fallback for missing templates
     */
    private /**
 * @param array<string|int, mixed> $context
 */
function renderEmailTemplate(string $templatePath, array $context): string
    {
        if ($this->twig === null) {
            // Fallback: generate simple HTML without Twig
            return $this->generateFallbackEmailHtml($context);
        }
        
        try {
            return $this->twig->render($templatePath, $context);
        } catch (\Exception $e) {
            // Template not found, use fallback
            $this->logger?->warning('PlaybookEngine: Email template not found, using fallback', [
                'template' => $templatePath,
                'error' => $e->getMessage(),
            ]);
            return $this->generateFallbackEmailHtml($context);
        }
    }
    
    /**
     * Generate fallback email HTML when Twig is unavailable or template missing
     */
    private /**
 * @param array<string|int, mixed> $context
 */
function generateFallbackEmailHtml(array $context): string
    {
        $accountName = $context['account_name'] ?? $context['company_name'] ?? 'Unknown';
        $score = $context['engagement_score'] ?? 'N/A';
        $timestamp = $context['timestamp'] instanceof \DateTimeInterface 
            ? $context['timestamp']->format('Y-m-d H:i:s') 
            : date('Y-m-d H:i:s');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head><title>CRM Playbook Notification</title></head>
<body style="font-family: Arial, sans-serif; padding: 20px;">
    <h2>Playbook Alert</h2>
    <p>A playbook has been triggered for <strong>{$accountName}</strong>.</p>
    <ul>
        <li><strong>Engagement Score:</strong> {$score}</li>
        <li><strong>Triggered At:</strong> {$timestamp}</li>
    </ul>
    <p>Please review and take appropriate action.</p>
    <hr>
    <p style="color: #555; font-size: 12px;">This is an automated message from your CRM system.</p>
</body>
</html>
HTML;
    }
}
