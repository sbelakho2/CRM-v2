<?php

namespace App\Controller;

use App\Service\AutonomousSalesOrchestratorService;
use App\Service\AutonomousSalesSettingsService;
use App\Service\HourlyOptimizationService;
use App\Service\ThompsonSamplerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Autonomous Sales Dashboard Controller
 *
 * This controller translates the complex ML/bandit internals into
 * plain-English data the template can display without jargon.
 * All backend services remain untouched.
 */
#[Route('/autonomous-sales')]
class AutonomousSalesDashboardController extends AbstractController
{
    #[Route('', name: 'autonomous_sales_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(
        AutonomousSalesOrchestratorService $orchestrator,
        ThompsonSamplerService $thompsonSampler,
        AutonomousSalesSettingsService $settingsService,
    ): Response {
        $enabled = $settingsService->isEnabled();
        $systemStats = $orchestrator->getStats();

        // Gather raw arm data from the sampler (backend unchanged)
        $armTypes = [
            'subject_line' => 'Subject Lines',
            'template'     => 'Email Templates',
            'send_time'    => 'Send Times',
        ];

        $rawStats = [];
        $rawArms  = [];
        foreach ($armTypes as $type => $label) {
            $rawStats[$type] = $thompsonSampler->getBanditStats($type);
            $rawArms[$type]  = $thompsonSampler->getArmsWithStats($type);
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

        return $this->render('autonomous_sales/index.html.twig', [
            'enabled'     => $enabled,
            'health'      => $health,
            'performance' => $performance,
            'variations'  => $variations,
            'automation'  => $automation,
            'setup'       => $setup,
            'system_stats' => $systemStats,
        ]);
    }

    // ==================== POST ACTIONS (unchanged backend) ====================

    #[Route('/toggle', name: 'autonomous_sales_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(Request $request, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('autonomous_sales_toggle', $request->request->get('_token'))) {
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

        if (!$this->isCsrfTokenValid('autonomous_sales_initialize', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $result = $orchestrator->initialize();
        $this->addFlash('success', sprintf(
            'System initialized: %d email templates, %d subject line variations, and %d competitor signals loaded.',
            $result['templates'] ?? 0,
            ($result['arms'] ?? 0) + ($result['valuePropArms'] ?? 0),
            $result['competitors'] ?? 0
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

        if (!$this->isCsrfTokenValid('autonomous_sales_seed', $request->request->get('_token'))) {
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

        if (!$this->isCsrfTokenValid('autonomous_sales_score_leads', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $limit = max(1, (int) $request->request->get('limit', 50));
        $result = $orchestrator->scoreLeads($limit);
        $scored = $result['scored'] ?? 0;

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

        if (!$this->isCsrfTokenValid('autonomous_sales_hourly_run', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $dryRun = $request->request->get('dryRun', '1') === '1';
        $limit = max(1, min(100, (int) $request->request->get('limit', 50)));

        $report = $hourlyOptimization->runHourlyCycle($dryRun, $limit);

        $outcome  = $report['stages'][10]['outcome'] ?? 'unknown';
        $duration = $report['duration'] ?? 0;

        // Translate ML outcomes into plain English
        $stage7 = $report['stages'][7] ?? [];
        $promoteCount = $stage7['promoteCount'] ?? 0;
        $pruneCount   = $stage7['pruneCount'] ?? 0;

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

        if (!$this->isCsrfTokenValid('autonomous_sales_arm_create', $request->request->get('_token'))) {
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

        if (!$this->isCsrfTokenValid('autonomous_sales_arm_outcome_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $success = $request->request->get('result') === 'success';
        $eventType = (string) $request->request->get('eventType', 'open');

        $thompsonSampler->recordOutcome($id, $success, $eventType);
        $this->addFlash('success', 'Outcome recorded. The system will adjust future email selection accordingly.');

        return $this->redirectToRoute('autonomous_sales_index');
    }

    // ==================== PRIVATE HELPERS (translate ML → plain English) ====================

    /**
     * System health: simple traffic-light status for the whole system.
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
                $totalTrials += $arm['totalTrials'] ?? 0;
                $totalSuccesses += $arm['totalSuccesses'] ?? 0;
                if ($arm['quarantined'] ?? false) $quarantined++;
                if ($arm['isControl'] ?? false) $hasControl = true;
            }
        }

        $successRate = $totalTrials > 0 ? $totalSuccesses / $totalTrials : 0.0;
        $negRate = 1.0 - $successRate;
        $safeMode = $negRate >= 0.35;

        // Overall status: green / yellow / red
        if (!$enabled) {
            $status = 'off';
            $statusLabel = 'Turned off';
            $statusDesc = 'The sales automation system is currently disabled. Turn it on to start optimizing outreach.';
        } elseif ($safeMode) {
            $status = 'red';
            $statusLabel = 'Paused for safety';
            $statusDesc = 'Email performance has dropped below the safety threshold. The system has automatically paused non-essential sends to protect your sender reputation. Only proven email variations are being used.';
        } elseif ($quarantined > 0 || $totalArms < 3) {
            $status = 'yellow';
            $statusLabel = 'Needs attention';
            $issues = [];
            if ($totalArms < 3) {
                $issues[] = 'The system needs at least 3 email variations to optimize effectively. Click "Set up default variations" below.';
            }
            if ($quarantined > 0) {
                $issues[] = sprintf('%d email variation(s) were automatically disabled due to poor performance.', $quarantined);
            }
            $statusDesc = implode(' ', $issues);
        } elseif ($totalTrials === 0) {
            $status = 'yellow';
            $statusLabel = 'Ready to start';
            $statusDesc = 'The system is set up but has not sent any emails yet. Run an optimization cycle to begin testing.';
        } else {
            $status = 'green';
            $statusLabel = 'Running well';
            $statusDesc = 'The system is actively optimizing your outreach emails. It automatically tests different subject lines and templates, and sends more traffic to the best performers.';
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
     */
    private function buildPerformanceOverview(array $rawStats, array $systemStats): array
    {
        $totalTrials = 0;
        $totalArms = 0;

        foreach ($rawStats as $type => $stats) {
            $totalTrials += $stats['total_trials'] ?? 0;
            $totalArms += $stats['total_arms'] ?? $stats['arm_count'] ?? 0;
        }

        $successRate = $systemStats['optimizer']['successRate'] ?? 0;

        return [
            'emailsSent'       => $totalTrials,
            'variationsActive' => $totalArms,
            'successRate'      => $successRate,
            'successPct'       => round($successRate * 100, 1),
            'leadsTotal'       => $systemStats['discovery']['totalLeads'] ?? 0,
            'leadsScored'      => $systemStats['discovery']['scored'] ?? 0,
            'leadsPending'     => $systemStats['discovery']['pendingReview'] ?? 0,
            'avgScore'         => $systemStats['scoring']['averageScore'] ?? 0,
            'competitors'      => $systemStats['competitors']['detectionsCount'] ?? 0,
        ];
    }

    /**
     * Variations: translate each "bandit arm" into a human-readable variation card.
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
                if ($arm['isControl'] ?? false) {
                    $baseline = $arm;
                    break;
                }
            }

            $items = [];
            foreach ($arms as $arm) {
                $trials    = $arm['totalTrials'] ?? 0;
                $successes = $arm['totalSuccesses'] ?? 0;
                $rate      = $arm['expectedRate'] ?? 0;
                $negRate   = $arm['recentNegRate'] ?? 0;
                $isControl = $arm['isControl'] ?? false;
                $quarantined = $arm['quarantined'] ?? false;

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
                    'id'          => $arm['id'] ?? null,
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
            usort($items, function ($a, $b) {
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
     */
    private function buildSetupChecklist(array $rawArms, array $systemStats, bool $enabled): array
    {
        $totalArms = 0;
        foreach ($rawArms as $arms) {
            $totalArms += count($arms);
        }

        $leadsTotal = $systemStats['discovery']['totalLeads'] ?? 0;
        $leadsScored = $systemStats['discovery']['scored'] ?? 0;

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
}
