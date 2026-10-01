<?php

namespace App\Controller;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Lead;
use App\Service\AutonomousSalesOrchestratorService;
use App\Service\AutonomousSalesSettingsService;
use App\Service\HourlyOptimizationService;
use App\Service\ThompsonSamplerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Autonomous Sales Dashboard Controller
 *
 * This controller translates the complex ML/bandit internals into
 * plain-English data the template can display without jargon.
 * All backend services remain untouched.
 *
 * @phpstan-type BanditArmStats array{
 *   id: int|null,
 *   name: string|null,
 *   value: string|null,
 *   alpha: float,
 *   beta: float,
 *   totalTrials: int,
 *   totalSuccesses: int,
 *   empiricalRate: float,
 *   expectedRate: float,
 *   icpCluster: string,
 *   quarantined: bool,
 *   isControl: bool,
 *   recentNegRate: float
 * }
 * @phpstan-type BanditTypeStats array{
 *   arm_count: int,
 *   total_trials: int,
 *   total_successes: int,
 *   overall_rate: float|int,
 *   convergence: float
 * }
 * @phpstan-type SystemStats array{
 *   discovery: array{totalLeads: int, pendingReview: int, scored: int},
 *   scoring: array{averageScore: float},
 *   optimizer: array{arms: int, trials: int, successRate: float|int, convergence: float},
 *   inbox: array<int|string, mixed>,
 *   competitors: array{detectionsCount: int, stats: array<int|string, mixed>}
 * }
 * @phpstan-type HealthStatus array{
 *   status: string,
 *   statusLabel: string,
 *   statusDesc: string,
 *   totalArms: int,
 *   totalTrials: int,
 *   quarantined: int,
 *   hasControl: bool,
 *   safeMode: bool,
 *   successRate: float|int
 * }
 * @phpstan-type PerformanceOverview array{
 *   emailsSent: int,
 *   variationsActive: int,
 *   successRate: float|int,
 *   successPct: float,
 *   leadsTotal: int,
 *   leadsScored: int,
 *   leadsPending: int,
 *   avgScore: float,
 *   competitors: int
 * }
 * @phpstan-type VariationItem array{
 *   id: int|null,
 *   name: string|null,
 *   value: string|null,
 *   emailsSent: int,
 *   opens: int,
 *   successPct: float|int,
 *   badge: string,
 *   badgeLabel: string,
 *   explanation: string,
 *   isControl: bool,
 *   quarantined: bool,
 *   vsBaseline: float|int|null
 * }
 * @phpstan-type VariationsSection array{
 *   type: string,
 *   label: string,
 *   items: list<VariationItem>,
 *   count: int,
 *   explanation: string
 * }
 * @phpstan-type AutomationStatus array{summary: string, detail: string, actions: list<string>}
 * @phpstan-type SetupStep array{label: string, done: bool, help: string}
 * @phpstan-type SetupChecklist array{steps: list<SetupStep>, completed: int, total: int, allDone: bool, pct: float}
 * @phpstan-type SubtitleSpec array{key: string, params: array<string, bool|float|int|string|null>}
 */
#[Route('/autonomous-sales')]
class AutonomousSalesDashboardController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $translator,
        private EntityManagerInterface $entityManager,
        private \App\Service\CurrencyConverter $currencyConverter,
    ) {}

    #[Route('', name: 'autonomous_sales_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(
        Request $request,
        AutonomousSalesOrchestratorService $orchestrator,
        ThompsonSamplerService $thompsonSampler,
        AutonomousSalesSettingsService $settingsService,
    ): Response {
        $enabled = $settingsService->isEnabled();
        /** @var SystemStats $systemStats */
        $systemStats = $orchestrator->getStats();

        // Tab routing — server-side, same pattern as webinar/index
        $validTabs = ['overview', 'optimization', 'settings'];
        $activeTab = $request->query->get('tab', 'overview');
        if (!in_array($activeTab, $validTabs, true)) {
            $activeTab = 'overview';
        }

        // Whether to expand the full variations table on the optimization tab
        $expandVariations = $request->query->getBoolean('expand', false);

        // ── Comprehensive sales metrics ──

        // Lead stage breakdown
        $leadRepo = $this->entityManager->getRepository(Lead::class);
        $leadStageCounts = $leadRepo->createQueryBuilder('l')
            ->select('l.nurturingStage AS stage, COUNT(l.id) AS count')
            ->groupBy('l.nurturingStage')
            ->getQuery()->getResult();

        // Total leads in pipeline
        $totalLeads = $leadRepo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Email stats
        $emailSendRepo = $this->entityManager->getRepository(EmailSend::class);

        $weeklyEmails = (int) $emailSendRepo->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.sentAt >= :week')
            ->setParameter('week', new \DateTime('-7 days'))
            ->getQuery()->getSingleScalarResult();

        $monthlyEmails = (int) $emailSendRepo->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.sentAt >= :month')
            ->setParameter('month', new \DateTime('-30 days'))
            ->getQuery()->getSingleScalarResult();

        // Email engagement rates
        /** @var array<string, int|string|null> $emailStats DQL aggregate row (SUM/COUNT come back as string|int|null depending on the driver) */
        $emailStats = $emailSendRepo->createQueryBuilder('e')
            ->select(
                'COUNT(e.id) AS total',
                'SUM(CASE WHEN e.opened = true THEN 1 ELSE 0 END) AS opened',
                'SUM(CASE WHEN e.clicked = true THEN 1 ELSE 0 END) AS clicked',
                'SUM(CASE WHEN e.replied = true THEN 1 ELSE 0 END) AS replied',
                'SUM(CASE WHEN e.bounced = true THEN 1 ELSE 0 END) AS bounced'
            )
            ->where('e.sentAt >= :month')
            ->setParameter('month', new \DateTime('-30 days'))
            ->getQuery()->getSingleResult();

        $totalEmail = (int) ($emailStats['total'] ?? 0);
        $totalOpened = (int) ($emailStats['opened'] ?? 0);
        $totalClicked = (int) ($emailStats['clicked'] ?? 0);
        $totalReplied = (int) ($emailStats['replied'] ?? 0);
        $totalBounced = (int) ($emailStats['bounced'] ?? 0);

        $openRate = $totalEmail > 0 ? round(($totalOpened / $totalEmail) * 100, 1) : 0;
        $clickRate = $totalEmail > 0 ? round(($totalClicked / $totalEmail) * 100, 1) : 0;
        $replyRate = $totalEmail > 0 ? round(($totalReplied / $totalEmail) * 100, 1) : 0;
        $bounceRate = $totalEmail > 0 ? round(($totalBounced / $totalEmail) * 100, 1) : 0;

        // Active campaigns count
        $campaignRepo = $this->entityManager->getRepository(EmailCampaign::class);
        $activeCampaigns = (int) $campaignRepo->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.active = true')
            ->andWhere('c.status = :status')
            ->setParameter('status', EmailCampaign::STATUS_SENDING)
            ->getQuery()->getSingleScalarResult();

        // Recent email activity (for activity feed)
        $recentEmailEvents = $emailSendRepo->createQueryBuilder('e')
            ->select('e.id', 'e.sentAt', 'e.opened', 'e.clicked', 'e.replied', 'e.emailAddress', 'e.variant')
            ->where('e.sentAt >= :week')
            ->setParameter('week', new \DateTime('-7 days'))
            ->orderBy('e.sentAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()->getResult();

        // Pipeline value estimates (from quotes), normalized to the display
        // currency — quotes are captured in mixed currencies (USD/EUR/MAD/...),
        // so SUM(totalCost) in SQL would produce a currency-meaningless number.
        $displayCurrency = $this->currencyConverter->getDisplayCurrency();
        /** @var array<int, array<string, mixed>> $pipelineRows */
        $pipelineRows = $this->entityManager->createQuery(
            'SELECT q.totalCost, q.currency FROM App\Entity\Quote q WHERE q.status IN (:statuses) AND q.archivedAt IS NULL'
        )->setParameter('statuses', ['draft', 'pending_review', 'approved', 'sent'])
         ->getArrayResult();

        $pipelineValue = 0.0;
        foreach ($pipelineRows as $row) {
            $rawAmount = $row['totalCost'] ?? 0;
            $amount = is_numeric($rawAmount) ? (float) $rawAmount : 0.0;
            if ($amount <= 0) {
                continue;
            }
            $rawCurrency = $row['currency'];
            $fromCurrency = is_string($rawCurrency) && $rawCurrency !== '' ? $rawCurrency : $displayCurrency;
            $pipelineValue += $this->currencyConverter->convert(
                $amount,
                $fromCurrency,
                $displayCurrency
            );
        }

        // Gather raw arm data from the sampler (backend unchanged)
        $armTypes = [
            'subject_line' => 'Subject Lines',
            'template'     => 'Email Templates',
            'send_time'    => 'Send Times',
        ];

        $rawStats = [];
        $rawArms  = [];
        foreach ($armTypes as $type => $label) {
            /** @var BanditTypeStats $stats */
            $stats = $thompsonSampler->getBanditStats($type);
            /** @var list<BanditArmStats> $arms */
            $arms  = $thompsonSampler->getArmsWithStats($type);
            $rawStats[$type] = $stats;
            $rawArms[$type]  = $arms;
        }

        // ── Translate raw data into user-friendly structures ──

        // 1. System health — simple yes/no checks
        $health = $this->buildHealthStatus($rawArms, $systemStats, $enabled);

        // 2. Performance overview — top-line numbers explained
        $performance = $this->buildPerformanceOverview($rawStats, $systemStats);

        // 3. Email variations — each "arm" explained as an email variation
        $variations = $this->buildVariationsList($rawArms, $armTypes);

        // 4. Automation status — what the system is doing right now
        $automation = $this->buildAutomationStatus($health, $performance, $enabled);

        // 5. Setup checklist — what needs to happen before the system works
        $setup = $this->buildSetupChecklist($rawArms, $systemStats, $enabled);

        // 6. Dynamic subtitle — context-aware one-liner
        $dynamicSubtitle = $this->buildDynamicSubtitle($enabled, $setup, $performance, $health);

        // 7. Safety feature count for the shield summary
        $safetyCount = 8;

        return $this->render('autonomous_sales/index.html.twig', [
            'enabled'           => $enabled,
            'health'            => $health,
            'performance'       => $performance,
            'variations'        => $variations,
            'automation'        => $automation,
            'setup'             => $setup,
            'system_stats'      => $systemStats,
            'active_tab'        => $activeTab,
            'expand_variations' => $expandVariations,
            'dynamic_subtitle'  => $dynamicSubtitle,
            'safety_count'      => $safetyCount,
            // ── Comprehensive sales metrics ──
            'lead_stage_counts'   => $leadStageCounts,
            'total_leads'         => (int) $totalLeads,
            'weekly_emails'       => $weeklyEmails,
            'monthly_emails'      => $monthlyEmails,
            'email_open_rate'     => $openRate,
            'email_click_rate'    => $clickRate,
            'email_reply_rate'    => $replyRate,
            'email_bounce_rate'   => $bounceRate,
            'active_campaigns'    => $activeCampaigns,
            'recent_email_events' => $recentEmailEvents,
            'pipeline_value'      => (float) $pipelineValue,
        ]);
    }

    // ==================== POST ACTIONS (unchanged backend) ====================

    #[Route('/toggle', name: 'autonomous_sales_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(Request $request, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('autonomous_sales_toggle', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $enabled = $settingsService->toggle();
        $this->addFlash('success', $enabled
            ? 'Sales automation is now ON. The system will begin optimizing your outreach.'
            : 'Sales automation is now OFF. No emails will be sent automatically.'
        );

        return $this->redirectToRoute('autonomous_sales_index');
    }

    #[Route('/initialize', name: 'autonomous_sales_initialize', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function initializeSystem(Request $request, AutonomousSalesOrchestratorService $orchestrator, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$settingsService->isEnabled()) {
            $this->addFlash('error', 'Please turn on sales automation first.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        if (!$this->isCsrfTokenValid('autonomous_sales_initialize', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        /** @var array{templates: int, arms: int, valuePropArms: int, competitors: int} $result */
        $result = $orchestrator->initialize();
        $this->addFlash('success', sprintf(
            'System initialized: %d email templates, %d subject line variations, and %d competitor signals loaded.',
            $result['templates'],
            $result['arms'] + $result['valuePropArms'],
            $result['competitors']
        ));

        return $this->redirectToRoute('autonomous_sales_index');
    }

    #[Route('/seed', name: 'autonomous_sales_seed', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function seedDefaults(Request $request, ThompsonSamplerService $thompsonSampler, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$settingsService->isEnabled()) {
            $this->addFlash('error', 'Please turn on sales automation first.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        if (!$this->isCsrfTokenValid('autonomous_sales_seed', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $created = $thompsonSampler->seedDefaultSubjectLineArms();
        $this->addFlash('success', sprintf(
            '%d default subject line variations created. The system will now test which ones perform best.',
            count($created)
        ));

        return $this->redirectToRoute('autonomous_sales_index');
    }

    #[Route('/score-leads', name: 'autonomous_sales_score_leads', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function scoreLeads(Request $request, AutonomousSalesOrchestratorService $orchestrator, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$settingsService->isEnabled()) {
            $this->addFlash('error', 'Please turn on sales automation first.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        if (!$this->isCsrfTokenValid('autonomous_sales_score_leads', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $limit = max(1, (int) $request->request->get('limit', 50));
        /** @var array{scored: int, byTier: array<string, int>, leads: list<array{id: int|null, name: string|null, score: int, tier: string, competitorBoost: int}>} $result */
        $result = $orchestrator->scoreLeads($limit);
        $scored = $result['scored'];

        $this->addFlash('success', sprintf(
            '%d leads scored and ranked by purchase likelihood. The system will prioritize the highest-scoring leads for outreach.',
            $scored
        ));

        return $this->redirectToRoute('autonomous_sales_index');
    }

    #[Route('/hourly-run', name: 'autonomous_sales_hourly_run', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function runHourlyCycle(Request $request, HourlyOptimizationService $hourlyOptimization, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$settingsService->isEnabled()) {
            $this->addFlash('error', 'Please turn on sales automation first.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        if (!$this->isCsrfTokenValid('autonomous_sales_hourly_run', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $dryRun = $request->request->get('dryRun', '1') === '1';
        $limit = max(1, min(100, (int) $request->request->get('limit', 50)));

        $report = $hourlyOptimization->runHourlyCycle($dryRun, $limit);

        $stage10 = $report['stages'][10] ?? null;
        $outcome = is_array($stage10) && is_string($stage10['outcome'] ?? null)
            ? $stage10['outcome']
            : 'unknown';
        $duration = $report['duration'];

        // Translate ML outcomes into plain English
        $stage7 = $report['stages'][7] ?? null;
        $promoteCount = is_array($stage7) && is_numeric($stage7['promoteCount'] ?? null)
            ? (int) $stage7['promoteCount'] : 0;
        $pruneCount   = is_array($stage7) && is_numeric($stage7['pruneCount'] ?? null)
            ? (int) $stage7['pruneCount'] : 0;

        if ($outcome === 'improve') {
            $this->addFlash('success', sprintf(
                'Optimization complete (%.1fs). %d email variation(s) identified as top performers and will receive more traffic.',
                $duration, $promoteCount
            ));
        } elseif ($outcome === 'rollback') {
            $this->addFlash('warning', sprintf(
                'Optimization complete (%.1fs). %d underperforming variation(s) removed. The system rolled back to safer options.',
                $duration, $pruneCount
            ));
        } else {
            $this->addFlash('info', sprintf(
                'Optimization complete (%.1fs). Everything looks stable%s.',
                $duration,
                $dryRun ? ' (dry run -- no emails were sent)' : ''
            ));
        }

        return $this->redirectToRoute('autonomous_sales_index');
    }

    // These routes are kept for API/programmatic use but hidden from the UI
    // The hourly optimizer handles arm outcomes automatically now

    #[Route('/arms/new', name: 'autonomous_sales_arm_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createArm(Request $request, ThompsonSamplerService $thompsonSampler, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$settingsService->isEnabled()) {
            $this->addFlash('error', 'Please turn on sales automation first.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        if (!$this->isCsrfTokenValid('autonomous_sales_arm_create', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $armType = (string) $request->request->get('armType', 'subject_line');
        $armName = trim((string) $request->request->get('armName', ''));
        $armValue = trim((string) $request->request->get('armValue', ''));

        if ($armName === '' || $armValue === '') {
            $this->addFlash('error', 'Please provide both a name and a value for the new variation.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $thompsonSampler->createArm($armType, $armName, $armValue);
        $this->addFlash('success', sprintf(
            'New subject line variation "%s" created. The system will begin testing it automatically.',
            $armName
        ));

        return $this->redirectToRoute('autonomous_sales_index');
    }

    #[Route('/arms/{id}/outcome', name: 'autonomous_sales_arm_outcome', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function recordOutcome(int $id, Request $request, ThompsonSamplerService $thompsonSampler, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$settingsService->isEnabled()) {
            $this->addFlash('error', 'Please turn on sales automation first.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        if (!$this->isCsrfTokenValid('autonomous_sales_arm_outcome_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $success = $request->request->get('result') === 'success';
        $eventType = (string) $request->request->get('eventType', 'open');

        $thompsonSampler->recordOutcome($id, $success, $eventType);
        $this->addFlash('success', 'Outcome recorded. The system will adjust future email selection accordingly.');

        return $this->redirectToRoute('autonomous_sales_index');
    }

    /**
     * @param array<string, bool|float|int|string|null> $parameters
     */
    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters);
    }

    // ==================== PRIVATE HELPERS (translate ML → plain English) ====================

    /**
     * System health: simple traffic-light status for the whole system.
     *
     * @param array<string, list<BanditArmStats>> $rawArms
     * @param SystemStats $systemStats
     * @return HealthStatus
     */
    private function buildHealthStatus(array $rawArms, array $systemStats, bool $enabled): array
    {
        $totalArms = 0;
        $totalTrials = 0;
        $totalSuccesses = 0;
        $quarantined = 0;
        $hasControl = false;

        foreach ($rawArms as $type => $arms) {
            foreach ($arms as $arm) {
                $totalArms++;
                $totalTrials += $arm['totalTrials'];
                $totalSuccesses += $arm['totalSuccesses'];
                if ($arm['quarantined']) $quarantined++;
                if ($arm['isControl']) $hasControl = true;
            }
        }

        $successRate = $totalTrials > 0 ? $totalSuccesses / $totalTrials : 0.0;
        $negRate = 1.0 - $successRate;
        $safeMode = $negRate >= 0.35;

        // Overall status: green / yellow / red
        if (!$enabled) {
            $status = 'off';
            $statusLabel = $this->trans('autonomous_sales.health.off_label');
            $statusDesc = $this->trans('autonomous_sales.health.off_desc');
        } elseif ($safeMode) {
            $status = 'red';
            $statusLabel = $this->trans('autonomous_sales.health.paused_label');
            $statusDesc = $this->trans('autonomous_sales.health.paused_desc');
        } elseif ($quarantined > 0 || $totalArms < 3) {
            $status = 'yellow';
            $statusLabel = $this->trans('autonomous_sales.health.needs_attention_label');
            $issues = [];
            if ($totalArms < 3) {
                $issues[] = $this->trans('autonomous_sales.health.needs_variations', ['%min%' => 3]);
            }
            if ($quarantined > 0) {
                $issues[] = $this->trans('autonomous_sales.health.quarantined_variations', ['%count%' => $quarantined]);
            }
            $statusDesc = implode(' ', $issues);
        } elseif ($totalTrials === 0) {
            $status = 'yellow';
            $statusLabel = $this->trans('autonomous_sales.health.ready_label');
            $statusDesc = $this->trans('autonomous_sales.health.ready_desc');
        } else {
            $status = 'green';
            $statusLabel = $this->trans('autonomous_sales.health.running_label');
            $statusDesc = $this->trans('autonomous_sales.health.running_desc');
        }

        return [
            'status'        => $status,
            'statusLabel'   => $statusLabel,
            'statusDesc'    => $statusDesc,
            'totalArms'     => $totalArms,
            'totalTrials'   => $totalTrials,
            'quarantined'   => $quarantined,
            'hasControl'    => $hasControl,
            'safeMode'      => $safeMode,
            'successRate'   => $successRate,
        ];
    }

    /**
     * Performance: translate raw stats into meaningful business numbers.
     *
     * @param array<string, BanditTypeStats> $rawStats
     * @param SystemStats $systemStats
     * @return PerformanceOverview
     */
    private function buildPerformanceOverview(array $rawStats, array $systemStats): array
    {
        $totalTrials = 0;
        $totalArms = 0;

        foreach ($rawStats as $type => $stats) {
            $totalTrials += $stats['total_trials'];
            $totalArms += $stats['arm_count'];
        }

        $successRate = $systemStats['optimizer']['successRate'];

        return [
            'emailsSent'       => $totalTrials,
            'variationsActive' => $totalArms,
            'successRate'      => $successRate,
            'successPct'       => round($successRate * 100, 1),
            'leadsTotal'       => $systemStats['discovery']['totalLeads'],
            'leadsScored'      => $systemStats['discovery']['scored'],
            'leadsPending'     => $systemStats['discovery']['pendingReview'],
            'avgScore'         => $systemStats['scoring']['averageScore'],
            'competitors'      => $systemStats['competitors']['detectionsCount'],
        ];
    }

    /**
     * Variations: translate each "bandit arm" into a human-readable variation card.
     *
     * @param array<string, list<BanditArmStats>> $rawArms
     * @param array<string, string> $armTypes
     * @return list<VariationsSection>
     */
    private function buildVariationsList(array $rawArms, array $armTypes): array
    {
        $sections = [];

        foreach ($armTypes as $type => $label) {
            $arms = $rawArms[$type] ?? [];
            if (empty($arms)) continue;

            // Find the control/baseline arm
            $baseline = null;
            foreach ($arms as $arm) {
                if ($arm['isControl']) {
                    $baseline = $arm;
                    break;
                }
            }

            $items = [];
            foreach ($arms as $arm) {
                $trials    = $arm['totalTrials'];
                $successes = $arm['totalSuccesses'];
                $rate      = $arm['expectedRate'];
                $negRate   = $arm['recentNegRate'];
                $isControl = $arm['isControl'];
                $quarantined = $arm['quarantined'];

                // Compute relative performance vs baseline
                $baselineRate = $baseline['expectedRate'] ?? 0;
                $relPerf = ($baselineRate > 0 && !$isControl) ? $rate / $baselineRate : 1.0;

                // Plain-English status
                if ($quarantined) {
                    $badge = 'disabled';
                    $badgeLabel = 'Disabled';
                    $explanation = 'This variation was automatically disabled because it performed poorly (high bounce/unsubscribe rate). The system will not use it for new emails.';
                } elseif ($isControl) {
                    $badge = 'baseline';
                    $badgeLabel = 'Baseline';
                    $explanation = 'This is your baseline variation. All other variations are compared against it. It always receives at least 10% of email traffic to ensure reliable measurement.';
                } elseif ($trials < 5) {
                    $badge = 'testing';
                    $badgeLabel = 'Still testing';
                    $explanation = sprintf('This variation needs at least 5 sends to be properly evaluated. It has been sent %d time(s) so far.', $trials);
                } elseif ($relPerf >= 1.15) {
                    $badge = 'winning';
                    $badgeLabel = 'Top performer';
                    $explanation = sprintf('This variation is outperforming the baseline by %d%%. The system is automatically sending it to more recipients.', round(($relPerf - 1) * 100));
                } elseif ($relPerf < 0.70) {
                    $badge = 'losing';
                    $badgeLabel = 'Underperforming';
                    $explanation = 'This variation is performing significantly below the baseline. The system will reduce its usage and may disable it in the next optimization cycle.';
                } else {
                    $badge = 'active';
                    $badgeLabel = 'Active';
                    $explanation = 'This variation is performing within the normal range. The system continues to test it alongside others.';
                }

                $items[] = [
                    'id'          => $arm['id'],
                    'name'        => $arm['name'] ?? 'Unknown',
                    'value'       => $arm['value'] ?? '',
                    'emailsSent'  => $trials,
                    'opens'       => $successes,
                    'successPct'  => $trials > 0 ? round(($successes / $trials) * 100, 1) : 0,
                    'badge'       => $badge,
                    'badgeLabel'  => $badgeLabel,
                    'explanation'  => $explanation,
                    'isControl'   => $isControl,
                    'quarantined' => $quarantined,
                    'vsBaseline'  => $isControl ? null : round(($relPerf - 1) * 100),
                ];
            }

            // Sort: baseline first, then by success rate descending
            usort($items, function (array $a, array $b): int {
                if ($a['isControl']) return -1;
                if ($b['isControl']) return 1;
                return $b['successPct'] <=> $a['successPct'];
            });

            $sections[] = [
                'type'  => $type,
                'label' => $label,
                'items' => $items,
                'count' => count($items),
                'explanation' => match($type) {
                    'subject_line' => 'These are different email subject lines the system tests. It automatically sends more emails with subject lines that get higher open rates, and phases out ones that do not perform well.',
                    'template'     => 'These are different email body templates. The system learns which message style resonates best with your prospects and shifts traffic accordingly.',
                    'send_time'    => 'The system tests different times of day for sending emails and learns when your prospects are most likely to engage.',
                    default        => 'The system is testing these variations and learning which ones perform best.',
                },
            ];
        }

        return $sections;
    }

    /**
     * Automation status: what the system is doing and what actions are available.
     *
     * @param HealthStatus $health
     * @param PerformanceOverview $performance
     * @return AutomationStatus
     */
    private function buildAutomationStatus(array $health, array $performance, bool $enabled): array
    {
        $actions = [];

        if (!$enabled) {
            return [
                'summary' => 'Sales automation is turned off.',
                'detail' => 'Turn it on using the switch above. Once enabled, the system will automatically compose, send, and optimize your outreach emails.',
                'actions' => [],
            ];
        }

        if ($health['totalArms'] === 0) {
            return [
                'summary' => 'System needs to be initialized.',
                'detail' => 'Click "Initialize system" to load email templates and create the first set of email variations. Then click "Set up default variations" to populate subject lines.',
                'actions' => ['initialize', 'seed'],
            ];
        }

        if ($health['totalArms'] < 3) {
            return [
                'summary' => 'More email variations needed.',
                'detail' => 'The system works best with at least 3 email variations to compare. Click "Set up default variations" to add more.',
                'actions' => ['seed'],
            ];
        }

        if ($health['safeMode']) {
            return [
                'summary' => 'System is in protective mode.',
                'detail' => 'Recent email performance triggered automatic safety protections. Only the proven baseline variation is being used. Run an optimization cycle to let the system evaluate and recover.',
                'actions' => ['optimize'],
            ];
        }

        if ($health['totalTrials'] === 0) {
            return [
                'summary' => 'Ready to send your first test batch.',
                'detail' => 'Everything is set up. Run an optimization cycle to start testing email variations. We recommend starting with a dry run first.',
                'actions' => ['optimize'],
            ];
        }

        return [
            'summary' => 'System is running and learning.',
            'detail' => sprintf(
                'The system has sent %s emails across %d variations. It continuously learns which subject lines, templates, and send times work best and automatically adjusts.',
                number_format($health['totalTrials']),
                $health['totalArms']
            ),
            'actions' => ['optimize', 'score'],
        ];
    }

    /**
     * Setup checklist: clear steps for getting started.
     *
     * @param array<string, list<BanditArmStats>> $rawArms
     * @param SystemStats $systemStats
     * @return SetupChecklist
     */
    private function buildSetupChecklist(array $rawArms, array $systemStats, bool $enabled): array
    {
        $totalArms = 0;
        foreach ($rawArms as $arms) {
            $totalArms += count($arms);
        }

        $leadsTotal = $systemStats['discovery']['totalLeads'];
        $leadsScored = $systemStats['discovery']['scored'];

        /** @var list<SetupStep> $steps */
        $steps = [
            [
                'label' => 'Turn on sales automation',
                'done'  => $enabled,
                'help'  => 'Use the power switch at the top of this page.',
            ],
            [
                'label' => 'Initialize the system',
                'done'  => $totalArms > 0,
                'help'  => 'This loads email templates and competitor data. Click "Initialize system" below.',
            ],
            [
                'label' => 'Set up email variations',
                'done'  => $totalArms >= 3,
                'help'  => 'Click "Set up default variations" to create subject line options for the system to test.',
            ],
            [
                'label' => 'Import leads',
                'done'  => $leadsTotal > 0,
                'help'  => 'Add leads through the Lead Discovery page or import a CSV.',
            ],
            [
                'label' => 'Score your leads',
                'done'  => $leadsScored > 0,
                'help'  => 'Click "Score leads" below so the system knows which prospects to prioritize.',
            ],
            [
                'label' => 'Run first optimization cycle',
                'done'  => ($rawArms['subject_line'][0]['totalTrials'] ?? 0) > 0,
                'help'  => 'Click "Run optimization cycle" to let the system start testing and sending.',
            ],
        ];

        $completedCount = count(array_filter($steps, fn($s) => $s['done']));
        $allDone = $completedCount === count($steps);

        return [
            'steps'     => $steps,
            'completed' => $completedCount,
            'total'     => count($steps),
            'allDone'   => $allDone,
            'pct'       => round(($completedCount / count($steps)) * 100),
        ];
    }

    /**
     * Dynamic subtitle: context-aware one-liner based on system state.
     *
     * Returns a translation key that the template will pass through |trans.
     * The controller also passes parameters for interpolation.
     *
     * @param SetupChecklist $setup
     * @param PerformanceOverview $performance
     * @param HealthStatus $health
     * @return SubtitleSpec
     */
    private function buildDynamicSubtitle(bool $enabled, array $setup, array $performance, array $health): array
    {
        if (!$enabled) {
            return [
                'key' => 'autonomous_sales.subtitle_off',
                'params' => [],
            ];
        }

        if (!$setup['allDone']) {
            $remaining = $setup['total'] - $setup['completed'];
            return [
                'key' => 'autonomous_sales.subtitle_setup',
                'params' => ['%remaining%' => $remaining],
            ];
        }

        if ($health['status'] === 'red') {
            return [
                'key' => 'autonomous_sales.subtitle_paused',
                'params' => [],
            ];
        }

        if ($performance['emailsSent'] > 0) {
            return [
                'key' => 'autonomous_sales.subtitle_running',
                'params' => [
                    '%emails%' => number_format($performance['emailsSent']),
                    '%rate%'   => $performance['successPct'],
                ],
            ];
        }

        return [
            'key' => 'autonomous_sales.subtitle_ready',
            'params' => [],
        ];
    }
}
