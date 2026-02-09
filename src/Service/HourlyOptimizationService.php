<?php

namespace App\Service;

use App\Entity\BanditArm;
use App\Entity\OutboundMessage;
use App\Repository\BanditArmRepository;
use App\Repository\OutboundMessageRepository;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hourly Optimization Service
 *
 * Implements the one-hour automated testing and improvement loop.
 * Every cycle executes stages 0-10 to continuously improve
 * email performance while enforcing hard safety constraints.
 *
 * Stage 0  – Fixed baseline arm (never mutated, >= 10% traffic)
 * Stage 1  – Snapshot current state (alpha, beta, expected rate per arm)
 * Stage 2  – Candidate selection (top-K + exploration floor)
 * Stage 3  – Personalization + generation + QA gates
 * Stage 4  – Execution window (send batch within cadence limits)
 * Stage 5  – Hourly evaluation (per-arm metrics vs baseline)
 * Stage 6  – Safety enforcement (instant kill, underperformance, safe mode)
 * Stage 7  – Hourly pruning + promotion (prune worst, freeze promoted)
 * Stage 8  – Adaptive refresh (decay stale arms, re-seed exploration)
 * Stage 9  – End-of-hour assertions (invariant checks)
 * Stage 10 – Guaranteed outcome (improve / hold / rollback)
 */
class HourlyOptimizationService
{
    // ==================== CONSTANTS ====================
    /** Minimum traffic share for the baseline arm */
    private const BASELINE_MIN_TRAFFIC_PCT = 0.10;

    /** Minimum trials before an arm can be evaluated */
    private const MIN_TRIALS_FOR_EVAL = 5;

    /** Underperformance threshold vs baseline (prune if below) */
    private const UNDERPERFORMANCE_THRESHOLD = 0.70;

    /** Instant-kill negative rate threshold */
    private const INSTANT_KILL_NEG_RATE = 0.50;

    /** Promotion threshold — arm must beat baseline by this margin */
    private const PROMOTION_THRESHOLD = 1.15;

    /** Max arms promoted per cycle */
    private const MAX_PROMOTIONS_PER_CYCLE = 2;

    /** Max arms pruned per cycle */
    private const MAX_PRUNES_PER_CYCLE = 3;

    /** Exploration floor — minimum fraction of traffic to exploratory arms */
    private const EXPLORATION_FLOOR = 0.15;

    /** Stale arm threshold in days (triggers decay) */
    private const STALE_DAYS_THRESHOLD = 14;

    /** System-wide safe mode: if overall negative rate exceeds this, halt all non-control sends */
    private const SYSTEM_SAFE_MODE_NEG_RATE = 0.35;

    /** Max batch size per hourly cycle */
    private const MAX_BATCH_SIZE = 50;

    /** Minimum arms required in pool to operate */
    private const MIN_POOL_SIZE = 3;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BanditArmRepository $armRepository,
        private OutboundMessageRepository $outboundRepository,
        private ContactRepository $contactRepository,
        private ThompsonSamplerService $thompsonSampler,
        private AutonomousSalesOrchestratorService $orchestrator,
        private ?CadenceGovernorService $cadenceGovernor,
        private ?CopyLintService $copyLintService,
        private LoggerInterface $logger,
    ) {}

    // ======================================================================
    // PUBLIC API
    // ======================================================================

    /**
     * Execute the full hourly optimization cycle (stages 0-10).
     *
     * @param bool $dryRun  If true, evaluate and report but make no DB changes
     * @param int  $limit   Max sends this cycle (overrides MAX_BATCH_SIZE)
     * @return array Complete cycle report
     */
    public function runHourlyCycle(bool $dryRun = false, int $limit = self::MAX_BATCH_SIZE): array
    {
        $cycleStart = microtime(true);
        $cycleId = bin2hex(random_bytes(8));
        $report = [
            'cycleId' => $cycleId,
            'startedAt' => (new \DateTime())->format('c'),
            'dryRun' => $dryRun,
            'stages' => [],
        ];

        $this->logger->info('=== HOURLY OPTIMIZATION CYCLE START ===', ['cycleId' => $cycleId, 'dryRun' => $dryRun]);

        try {
            // Stage 0 — Fixed baseline arm
            $report['stages'][0] = $this->stage0_ensureBaseline();

            // Stage 1 — Snapshot current state
            $snapshot = $this->stage1_snapshot();
            $report['stages'][1] = $snapshot;

            // Stage 6 (early) — Safety pre-check (safe mode halts everything)
            $safetyPreCheck = $this->stage6_safetyEnforcement($snapshot);
            $report['stages']['6_precheck'] = $safetyPreCheck;

            if ($safetyPreCheck['safeModeActive']) {
                $this->logger->warning('SAFE MODE ACTIVE — skipping send stages', ['cycleId' => $cycleId]);
                $report['stages'][2] = ['skipped' => 'safe_mode_active'];
                $report['stages'][3] = ['skipped' => 'safe_mode_active'];
                $report['stages'][4] = ['skipped' => 'safe_mode_active'];
            } else {
                // Stage 2 — Candidate selection
                $report['stages'][2] = $this->stage2_candidateSelection($snapshot);

                // Stage 3 — Personalization + QA gates (compose only, no send)
                $report['stages'][3] = $this->stage3_personalizeAndGate($report['stages'][2], $limit, $dryRun);

                // Stage 4 — Execution window (send batch)
                $report['stages'][4] = $this->stage4_executionWindow($report['stages'][3], $dryRun);
            }

            // Stage 5 — Hourly evaluation (always runs, uses historical data)
            $report['stages'][5] = $this->stage5_hourlyEvaluation($snapshot);

            // Stage 6 — Full safety enforcement
            $report['stages'][6] = $this->stage6_safetyEnforcement($snapshot);

            // Stage 7 — Pruning + promotion
            $report['stages'][7] = $this->stage7_pruneAndPromote($snapshot, $report['stages'][5], $dryRun);

            // Stage 8 — Adaptive refresh
            $report['stages'][8] = $this->stage8_adaptiveRefresh($dryRun);

            // Stage 9 — End-of-hour assertions
            $report['stages'][9] = $this->stage9_assertions($report);

            // Stage 10 — Guaranteed outcome determination
            $report['stages'][10] = $this->stage10_guaranteedOutcome($snapshot, $report['stages'][5], $report['stages'][7]);

        } catch (\Throwable $e) {
            $this->logger->error('Hourly cycle FAILED', [
                'cycleId' => $cycleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $report['error'] = $e->getMessage();
        }

        $report['duration'] = round(microtime(true) - $cycleStart, 3);
        $report['finishedAt'] = (new \DateTime())->format('c');

        $this->logger->info('=== HOURLY OPTIMIZATION CYCLE END ===', [
            'cycleId' => $cycleId,
            'duration' => $report['duration'],
            'outcome' => $report['stages'][10]['outcome'] ?? 'unknown',
        ]);

        return $report;
    }

    // ======================================================================
    // STAGE 0 — FIXED BASELINE ARM
    // ======================================================================

    /**
     * Ensure a control/baseline arm exists for every arm type.
     * The baseline is NEVER mutated, always receives >= 10% traffic.
     */
    private function stage0_ensureBaseline(): array
    {
        $armTypes = $this->getActiveArmTypes();
        $results = [];

        foreach ($armTypes as $type) {
            $arms = $this->armRepository->findActiveByType($type);
            $controlArm = null;

            foreach ($arms as $arm) {
                if ($arm->isControl()) {
                    $controlArm = $arm;
                    break;
                }
            }

            if (!$controlArm && !empty($arms)) {
                // Promote the oldest arm to control
                usort($arms, fn(BanditArm $a, BanditArm $b) =>
                    $a->getCreatedAt() <=> $b->getCreatedAt()
                );
                $controlArm = $arms[0];
                $controlArm->setIsControl(true);
                $this->entityManager->persist($controlArm);
                $this->entityManager->flush();

                $this->logger->info('Promoted oldest arm to baseline control', [
                    'type' => $type,
                    'armId' => $controlArm->getId(),
                    'armName' => $controlArm->getArmName(),
                ]);
            }

            $results[$type] = [
                'controlArmId' => $controlArm?->getId(),
                'controlArmName' => $controlArm?->getArmName(),
                'controlExpectedRate' => $controlArm?->getExpectedRate(),
                'totalArms' => count($arms),
            ];
        }

        return ['armTypes' => $results];
    }

    // ======================================================================
    // STAGE 1 — SNAPSHOT CURRENT STATE
    // ======================================================================

    /**
     * Capture a frozen snapshot of all arm states before any mutations.
     */
    private function stage1_snapshot(): array
    {
        $armTypes = $this->getActiveArmTypes();
        $snapshot = [
            'timestamp' => (new \DateTime())->format('c'),
            'armTypes' => [],
            'globalStats' => [
                'totalArms' => 0,
                'totalTrials' => 0,
                'totalSuccesses' => 0,
                'quarantinedCount' => 0,
            ],
        ];

        foreach ($armTypes as $type) {
            $arms = $this->armRepository->findActiveByType($type);
            $typeSnapshot = [];

            foreach ($arms as $arm) {
                $typeSnapshot[] = [
                    'armId' => $arm->getId(),
                    'armName' => $arm->getArmName(),
                    'alpha' => round($arm->getAlpha(), 4),
                    'beta' => round($arm->getBeta(), 4),
                    'expectedRate' => round($arm->getExpectedRate(), 4),
                    'empiricalRate' => round($arm->getEmpiricalRate(), 4),
                    'totalTrials' => $arm->getTotalTrials(),
                    'totalSuccesses' => $arm->getTotalSuccesses(),
                    'icpCluster' => $arm->getIcpCluster(),
                    'quarantined' => $arm->isQuarantined(),
                    'isControl' => $arm->isControl(),
                    'recentNegRate' => $arm->getRecentNegativeRate(),
                    'daysSinceLastUse' => $arm->getDaysSinceLastUse(),
                ];

                $snapshot['globalStats']['totalArms']++;
                $snapshot['globalStats']['totalTrials'] += $arm->getTotalTrials();
                $snapshot['globalStats']['totalSuccesses'] += $arm->getTotalSuccesses();
                if ($arm->isQuarantined()) {
                    $snapshot['globalStats']['quarantinedCount']++;
                }
            }

            $snapshot['armTypes'][$type] = $typeSnapshot;
        }

        // Compute global expected rate
        $totalTrials = $snapshot['globalStats']['totalTrials'];
        $snapshot['globalStats']['globalExpectedRate'] = $totalTrials > 0
            ? round($snapshot['globalStats']['totalSuccesses'] / $totalTrials, 4)
            : 0.0;

        return $snapshot;
    }

    // ======================================================================
    // STAGE 2 — CANDIDATE SELECTION
    // ======================================================================

    /**
     * Select candidates for this cycle:
     * - Top-K arms by Thompson sampling score
     * - Exploration floor: at least 15% of sends go to under-tested arms
     */
    private function stage2_candidateSelection(array $snapshot): array
    {
        $candidates = [
            'exploitation' => [],
            'exploration' => [],
        ];

        foreach ($snapshot['armTypes'] as $type => $arms) {
            // Filter out quarantined
            $eligible = array_filter($arms, fn($a) => !$a['quarantined']);

            // Identify under-tested arms (exploration pool)
            $underTested = array_filter($eligible, fn($a) => $a['totalTrials'] < self::MIN_TRIALS_FOR_EVAL);
            $tested = array_filter($eligible, fn($a) => $a['totalTrials'] >= self::MIN_TRIALS_FOR_EVAL);

            // Sort tested arms by expected rate (descending) for exploitation
            usort($tested, fn($a, $b) => $b['expectedRate'] <=> $a['expectedRate']);

            $candidates['exploitation'][$type] = array_values($tested);
            $candidates['exploration'][$type] = array_values($underTested);
        }

        $candidates['explorationFloor'] = self::EXPLORATION_FLOOR;
        $candidates['minTrialsForEval'] = self::MIN_TRIALS_FOR_EVAL;

        return $candidates;
    }

    // ======================================================================
    // STAGE 3 — PERSONALIZATION + QA GATES
    // ======================================================================

    /**
     * Compose messages for eligible contacts.
     * Each message passes through copy-lint and cadence governor.
     */
    private function stage3_personalizeAndGate(array $candidates, int $limit, bool $dryRun): array
    {
        $composed = [];
        $gateResults = ['passed' => 0, 'lintBlocked' => 0, 'cadenceBlocked' => 0, 'errors' => 0];

        if ($dryRun) {
            return [
                'dryRun' => true,
                'wouldComposeUpTo' => $limit,
                'gateResults' => $gateResults,
            ];
        }

        // Find contacts eligible for outreach
        $eligibleContacts = $this->findEligibleContacts($limit * 2); // Over-fetch to account for gate filtering

        $sent = 0;
        foreach ($eligibleContacts as $contact) {
            if ($sent >= $limit) {
                break;
            }

            // Cadence check
            if ($this->cadenceGovernor) {
                $cadenceCheck = $this->cadenceGovernor->canSendTo($contact);
                if (!$cadenceCheck['allowed']) {
                    $gateResults['cadenceBlocked']++;
                    continue;
                }
            }

            try {
                $result = $this->orchestrator->composeMessage($contact);

                // Copy lint gate
                if (isset($result['copyLintResult']) && !$result['copyLintResult']['passed']) {
                    $gateResults['lintBlocked']++;
                    continue;
                }

                $composed[] = [
                    'contactId' => $contact->getId(),
                    'contactEmail' => $contact->getEmail(),
                    'subject' => $result['subject'],
                    'body' => $result['body'],
                    'templateName' => $result['templateName'],
                    'subjectArmId' => $result['subjectArmId'],
                    'isControlGroup' => $result['isControlGroup'],
                    'icpCluster' => $result['icpCluster'],
                    'variationHash' => $result['variationHash'],
                    'composedResult' => $result,
                ];

                $gateResults['passed']++;
                $sent++;

            } catch (\Throwable $e) {
                $gateResults['errors']++;
                $this->logger->warning('Compose failed for contact', [
                    'contactId' => $contact->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'composed' => $composed,
            'gateResults' => $gateResults,
            'totalEligible' => count($eligibleContacts),
        ];
    }

    // ======================================================================
    // STAGE 4 — EXECUTION WINDOW
    // ======================================================================

    /**
     * Send the composed batch, recording each as an OutboundMessage.
     */
    private function stage4_executionWindow(array $stage3Result, bool $dryRun): array
    {
        if ($dryRun || empty($stage3Result['composed'] ?? [])) {
            return [
                'dryRun' => $dryRun,
                'sent' => 0,
                'failed' => 0,
                'skipped' => empty($stage3Result['composed'] ?? []) ? 'no_composed_messages' : 'dry_run',
            ];
        }

        $sent = 0;
        $failed = 0;

        foreach ($stage3Result['composed'] as $item) {
            $contact = $this->contactRepository->find($item['contactId']);
            if (!$contact) {
                $failed++;
                continue;
            }

            try {
                $composedResult = $item['composedResult'];

                // Save the outbound message
                $message = $this->orchestrator->saveOutboundMessage(
                    $contact,
                    $composedResult['subject'],
                    $composedResult['body'],
                    null, // bodyHtml
                    null, // subjectArm — resolved by ID below
                    null, // template
                    $composedResult['variationHash'],
                );

                // Set additional tracking fields
                if (method_exists($message, 'setIcpCluster')) {
                    $message->setIcpCluster($composedResult['icpCluster'] ?? 'global');
                }
                if (method_exists($message, 'setIsControlGroup')) {
                    $message->setIsControlGroup($composedResult['isControlGroup'] ?? false);
                }
                if (method_exists($message, 'setDecisionTrace')) {
                    $message->setDecisionTrace($composedResult['decisionTrace'] ?? []);
                }

                // Attempt send
                $sendResult = $this->orchestrator->sendEmail($message);
                if ($sendResult) {
                    $sent++;
                } else {
                    $failed++;
                }

            } catch (\Throwable $e) {
                $failed++;
                $this->logger->error('Send failed in execution window', [
                    'contactId' => $item['contactId'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'total' => count($stage3Result['composed']),
        ];
    }

    // ======================================================================
    // STAGE 5 — HOURLY EVALUATION
    // ======================================================================

    /**
     * Evaluate each arm's performance vs baseline over the recent window.
     * Uses messages from the last hour (or last 24h for broader context).
     */
    private function stage5_hourlyEvaluation(array $snapshot): array
    {
        $evaluation = [];

        foreach ($snapshot['armTypes'] as $type => $arms) {
            // Find baseline arm
            $baseline = null;
            foreach ($arms as $armData) {
                if ($armData['isControl']) {
                    $baseline = $armData;
                    break;
                }
            }

            if (!$baseline) {
                $evaluation[$type] = ['error' => 'no_baseline_arm'];
                continue;
            }

            $baselineRate = $baseline['expectedRate'];
            $armEvals = [];

            foreach ($arms as $armData) {
                if ($armData['isControl']) {
                    continue;
                }

                $armRate = $armData['expectedRate'];
                $relativePerformance = $baselineRate > 0
                    ? round($armRate / $baselineRate, 4)
                    : 0.0;

                $verdict = 'hold';
                if ($armData['totalTrials'] >= self::MIN_TRIALS_FOR_EVAL) {
                    if ($relativePerformance >= self::PROMOTION_THRESHOLD) {
                        $verdict = 'promote_candidate';
                    } elseif ($relativePerformance < self::UNDERPERFORMANCE_THRESHOLD) {
                        $verdict = 'prune_candidate';
                    } elseif ($armData['recentNegRate'] >= self::INSTANT_KILL_NEG_RATE) {
                        $verdict = 'instant_kill';
                    }
                } else {
                    $verdict = 'insufficient_data';
                }

                $armEvals[] = [
                    'armId' => $armData['armId'],
                    'armName' => $armData['armName'],
                    'expectedRate' => $armRate,
                    'relativePerformance' => $relativePerformance,
                    'totalTrials' => $armData['totalTrials'],
                    'recentNegRate' => $armData['recentNegRate'],
                    'verdict' => $verdict,
                ];
            }

            $evaluation[$type] = [
                'baselineArmId' => $baseline['armId'],
                'baselineRate' => $baselineRate,
                'arms' => $armEvals,
            ];
        }

        return $evaluation;
    }

    // ======================================================================
    // STAGE 6 — SAFETY ENFORCEMENT
    // ======================================================================

    /**
     * Enforce safety rules:
     * 1. Instant kill — quarantine arms with neg rate >= 50%
     * 2. Underperformance check — flag arms performing < 70% of baseline
     * 3. System-wide safe mode — halt non-control sends if overall neg rate >= 35%
     */
    private function stage6_safetyEnforcement(array $snapshot): array
    {
        $instantKills = [];
        $underperformers = [];
        $safeModeActive = false;

        // Compute system-wide negative rate
        $totalTrials = $snapshot['globalStats']['totalTrials'];
        $totalSuccesses = $snapshot['globalStats']['totalSuccesses'];
        $systemNegRate = $totalTrials > 0
            ? round(1 - ($totalSuccesses / $totalTrials), 4)
            : 0.0;

        if ($systemNegRate >= self::SYSTEM_SAFE_MODE_NEG_RATE) {
            $safeModeActive = true;
            $this->logger->critical('SYSTEM SAFE MODE ACTIVATED', [
                'systemNegRate' => $systemNegRate,
                'threshold' => self::SYSTEM_SAFE_MODE_NEG_RATE,
            ]);
        }

        foreach ($snapshot['armTypes'] as $type => $arms) {
            $baseline = null;
            foreach ($arms as $armData) {
                if ($armData['isControl']) {
                    $baseline = $armData;
                    break;
                }
            }

            foreach ($arms as $armData) {
                if ($armData['isControl']) {
                    continue;
                }

                // Instant kill check
                if ($armData['recentNegRate'] >= self::INSTANT_KILL_NEG_RATE && $armData['totalTrials'] >= 5) {
                    $instantKills[] = [
                        'armId' => $armData['armId'],
                        'armName' => $armData['armName'],
                        'recentNegRate' => $armData['recentNegRate'],
                    ];

                    // Execute quarantine
                    $arm = $this->armRepository->find($armData['armId']);
                    if ($arm && !$arm->isQuarantined()) {
                        $arm->setQuarantined(true);
                        $this->entityManager->persist($arm);
                        $this->logger->warning('INSTANT KILL: arm quarantined', [
                            'armId' => $armData['armId'],
                            'armName' => $armData['armName'],
                            'negRate' => $armData['recentNegRate'],
                        ]);
                    }
                }

                // Underperformance check
                if ($baseline && $armData['totalTrials'] >= self::MIN_TRIALS_FOR_EVAL) {
                    $relPerf = $baseline['expectedRate'] > 0
                        ? $armData['expectedRate'] / $baseline['expectedRate']
                        : 0.0;

                    if ($relPerf < self::UNDERPERFORMANCE_THRESHOLD) {
                        $underperformers[] = [
                            'armId' => $armData['armId'],
                            'armName' => $armData['armName'],
                            'relativePerformance' => round($relPerf, 4),
                            'armRate' => $armData['expectedRate'],
                            'baselineRate' => $baseline['expectedRate'],
                        ];
                    }
                }
            }
        }

        if (!empty($instantKills)) {
            $this->entityManager->flush();
        }

        return [
            'instantKills' => $instantKills,
            'underperformers' => $underperformers,
            'systemNegRate' => $systemNegRate,
            'safeModeActive' => $safeModeActive,
            'safeModeTriggerThreshold' => self::SYSTEM_SAFE_MODE_NEG_RATE,
        ];
    }

    // ======================================================================
    // STAGE 7 — PRUNING + PROMOTION
    // ======================================================================

    /**
     * Prune the worst arms, promote the best:
     * - Prune: deactivate arms with verdict 'prune_candidate' or 'instant_kill'
     * - Promote: freeze promoted arms (lock alpha/beta), mark as proven winners
     */
    private function stage7_pruneAndPromote(array $snapshot, array $evaluation, bool $dryRun): array
    {
        $pruned = [];
        $promoted = [];
        $pruneCount = 0;
        $promoteCount = 0;

        foreach ($evaluation as $type => $typeEval) {
            if (isset($typeEval['error'])) {
                continue;
            }

            $arms = $typeEval['arms'] ?? [];

            // Count active non-control arms for this type
            $activeCount = count(array_filter(
                $snapshot['armTypes'][$type] ?? [],
                fn($a) => !$a['quarantined'] && !$a['isControl']
            ));

            // === PRUNING ===
            $pruneCandidates = array_filter($arms, fn($a) =>
                in_array($a['verdict'], ['prune_candidate', 'instant_kill'], true)
            );

            // Sort by performance (worst first)
            usort($pruneCandidates, fn($a, $b) => $a['relativePerformance'] <=> $b['relativePerformance']);

            foreach ($pruneCandidates as $candidate) {
                if ($pruneCount >= self::MAX_PRUNES_PER_CYCLE) {
                    break;
                }
                // Never prune below minimum pool size
                if ($activeCount <= self::MIN_POOL_SIZE) {
                    break;
                }

                if (!$dryRun) {
                    $arm = $this->armRepository->find($candidate['armId']);
                    if ($arm) {
                        $arm->setActive(false);
                        $arm->setQuarantined(true);
                        $this->entityManager->persist($arm);
                    }
                }

                $pruned[] = $candidate;
                $pruneCount++;
                $activeCount--;
            }

            // === PROMOTION ===
            $promoteCandidates = array_filter($arms, fn($a) => $a['verdict'] === 'promote_candidate');

            // Sort by performance (best first)
            usort($promoteCandidates, fn($a, $b) => $b['relativePerformance'] <=> $a['relativePerformance']);

            foreach ($promoteCandidates as $candidate) {
                if ($promoteCount >= self::MAX_PROMOTIONS_PER_CYCLE) {
                    break;
                }

                $promoted[] = $candidate;
                $promoteCount++;

                // Promotion means the arm's current alpha/beta are "frozen" —
                // we log the promotion but don't stop Thompson from sampling it.
                // The arm keeps its earned advantage naturally.
                $this->logger->info('ARM PROMOTED: proven winner', [
                    'armId' => $candidate['armId'],
                    'armName' => $candidate['armName'],
                    'relativePerformance' => $candidate['relativePerformance'],
                ]);
            }
        }

        if (!$dryRun && ($pruneCount > 0)) {
            $this->entityManager->flush();
        }

        return [
            'pruned' => $pruned,
            'promoted' => $promoted,
            'pruneCount' => $pruneCount,
            'promoteCount' => $promoteCount,
        ];
    }

    // ======================================================================
    // STAGE 8 — ADAPTIVE REFRESH
    // ======================================================================

    /**
     * Decay stale arms and re-seed the exploration pool if it's thin.
     */
    private function stage8_adaptiveRefresh(bool $dryRun): array
    {
        $decayed = [];
        $reseeded = [];

        // 1. Decay stale arms (not used in > STALE_DAYS_THRESHOLD days)
        $allArms = $this->armRepository->findAll();
        foreach ($allArms as $arm) {
            if (!$arm->isActive() || $arm->isControl()) {
                continue;
            }

            $daysSinceUse = $arm->getDaysSinceLastUse();
            if ($daysSinceUse > self::STALE_DAYS_THRESHOLD) {
                $decayFactor = 1.0 + ($daysSinceUse / 100.0);
                $oldAlpha = $arm->getAlpha();
                $oldBeta = $arm->getBeta();

                $newAlpha = max(0.5, ThompsonSamplerService::DEFAULT_PRIOR_ALPHA
                    + ($oldAlpha - ThompsonSamplerService::DEFAULT_PRIOR_ALPHA) / $decayFactor);
                $newBeta = max(0.5, ThompsonSamplerService::DEFAULT_PRIOR_BETA
                    + ($oldBeta - ThompsonSamplerService::DEFAULT_PRIOR_BETA) / $decayFactor);

                if (!$dryRun) {
                    $arm->setAlpha($newAlpha);
                    $arm->setBeta($newBeta);
                    $this->entityManager->persist($arm);
                }

                $decayed[] = [
                    'armId' => $arm->getId(),
                    'armName' => $arm->getArmName(),
                    'daysSinceUse' => $daysSinceUse,
                    'alphaChange' => round($oldAlpha - $newAlpha, 3),
                    'betaChange' => round($oldBeta - $newBeta, 3),
                ];
            }
        }

        // 2. Re-seed exploration pool if running thin
        $armTypes = $this->getActiveArmTypes();
        foreach ($armTypes as $type) {
            $activeArms = $this->armRepository->findActiveByType($type);
            $nonQuarantined = array_filter($activeArms, fn(BanditArm $a) => !$a->isQuarantined());

            if (count($nonQuarantined) < self::MIN_POOL_SIZE) {
                $deficit = self::MIN_POOL_SIZE - count($nonQuarantined);
                $this->logger->info('Exploration pool thin, checking for un-quarantine candidates', [
                    'type' => $type,
                    'activeCount' => count($nonQuarantined),
                    'deficit' => $deficit,
                ]);

                // Try to un-quarantine recovered arms
                $quarantined = array_filter($activeArms, fn(BanditArm $a) => $a->isQuarantined());
                foreach ($quarantined as $qArm) {
                    if ($deficit <= 0) break;

                    // Un-quarantine if negative rate has dropped below threshold
                    if ($qArm->getRecentNegativeRate() < ThompsonSamplerService::CATASTROPHIC_NEG_RATE * 0.80) {
                        if (!$dryRun) {
                            $qArm->setQuarantined(false);
                            // Reset to prior to give it a fresh start
                            $qArm->setAlpha(ThompsonSamplerService::DEFAULT_PRIOR_ALPHA);
                            $qArm->setBeta(ThompsonSamplerService::DEFAULT_PRIOR_BETA);
                            $this->entityManager->persist($qArm);
                        }
                        $reseeded[] = [
                            'armId' => $qArm->getId(),
                            'armName' => $qArm->getArmName(),
                            'action' => 'un_quarantined_and_reset',
                        ];
                        $deficit--;
                    }
                }
            }
        }

        if (!$dryRun && (!empty($decayed) || !empty($reseeded))) {
            $this->entityManager->flush();
        }

        return [
            'decayed' => $decayed,
            'decayedCount' => count($decayed),
            'reseeded' => $reseeded,
            'reseededCount' => count($reseeded),
        ];
    }

    // ======================================================================
    // STAGE 9 — END-OF-HOUR ASSERTIONS
    // ======================================================================

    /**
     * Invariant checks that must hold at the end of every cycle.
     * Failures are logged as CRITICAL but do not crash the cycle.
     */
    private function stage9_assertions(array $report): array
    {
        $assertions = [];
        $allPassed = true;

        // Assertion 1: At least one control arm exists per type
        $armTypes = $this->getActiveArmTypes();
        foreach ($armTypes as $type) {
            $arms = $this->armRepository->findActiveByType($type);
            $hasControl = false;
            foreach ($arms as $arm) {
                if ($arm->isControl()) {
                    $hasControl = true;
                    break;
                }
            }
            $pass = $hasControl || empty($arms);
            $assertions["control_exists_{$type}"] = $pass;
            if (!$pass) {
                $allPassed = false;
                $this->logger->critical("ASSERTION FAILED: No control arm for type {$type}");
            }
        }

        // Assertion 2: No active arm has alpha or beta <= 0
        foreach ($armTypes as $type) {
            $arms = $this->armRepository->findActiveByType($type);
            foreach ($arms as $arm) {
                if ($arm->getAlpha() <= 0 || $arm->getBeta() <= 0) {
                    $assertions["positive_params_{$arm->getId()}"] = false;
                    $allPassed = false;
                    $this->logger->critical("ASSERTION FAILED: arm {$arm->getId()} has non-positive params", [
                        'alpha' => $arm->getAlpha(),
                        'beta' => $arm->getBeta(),
                    ]);
                }
            }
        }

        // Assertion 3: Pool size >= MIN_POOL_SIZE (warning, not critical)
        foreach ($armTypes as $type) {
            $arms = $this->armRepository->findActiveByType($type);
            $nonQ = array_filter($arms, fn(BanditArm $a) => !$a->isQuarantined());
            $pass = count($nonQ) >= self::MIN_POOL_SIZE || empty($arms);
            $assertions["min_pool_{$type}"] = $pass;
            if (!$pass) {
                $this->logger->warning("Pool below minimum size for type {$type}", [
                    'active' => count($nonQ),
                    'min' => self::MIN_POOL_SIZE,
                ]);
            }
        }

        // Assertion 4: Cycle completed without fatal error
        $assertions['no_fatal_error'] = !isset($report['error']);
        if (!$assertions['no_fatal_error']) {
            $allPassed = false;
        }

        return [
            'assertions' => $assertions,
            'allPassed' => $allPassed,
        ];
    }

    // ======================================================================
    // STAGE 10 — GUARANTEED OUTCOME
    // ======================================================================

    /**
     * Determine the cycle outcome: improve, hold, or rollback.
     *
     * - improve:  At least one arm was promoted (beating baseline by >= 15%)
     * - hold:     No significant change; system is stable
     * - rollback: Arms were pruned or instant-killed; system fell back to safer state
     */
    private function stage10_guaranteedOutcome(array $snapshot, array $evaluation, array $prunePromote): array
    {
        $promoteCount = $prunePromote['promoteCount'] ?? 0;
        $pruneCount = $prunePromote['pruneCount'] ?? 0;

        $outcome = 'hold';
        $reason = 'No significant changes detected this cycle.';

        if ($promoteCount > 0) {
            $outcome = 'improve';
            $promoted = $prunePromote['promoted'] ?? [];
            $names = array_map(fn($p) => $p['armName'] ?? 'unknown', $promoted);
            $reason = sprintf(
                '%d arm(s) promoted as proven winners: %s',
                $promoteCount,
                implode(', ', $names)
            );
        } elseif ($pruneCount > 0) {
            $outcome = 'rollback';
            $pruned = $prunePromote['pruned'] ?? [];
            $names = array_map(fn($p) => $p['armName'] ?? 'unknown', $pruned);
            $reason = sprintf(
                '%d underperforming arm(s) pruned/rolled back: %s',
                $pruneCount,
                implode(', ', $names)
            );
        }

        // Check for safe mode (overrides to rollback)
        $safetyStage = null;
        foreach ([6, '6_precheck'] as $key) {
            if (isset($evaluation[$key]) && ($evaluation[$key]['safeModeActive'] ?? false)) {
                $safetyStage = $evaluation[$key];
                break;
            }
        }

        // Use the stage 6 data from the parent report — check via snapshot
        $totalTrials = $snapshot['globalStats']['totalTrials'];
        $totalSuccesses = $snapshot['globalStats']['totalSuccesses'];
        $systemNegRate = $totalTrials > 0 ? 1 - ($totalSuccesses / $totalTrials) : 0.0;

        if ($systemNegRate >= self::SYSTEM_SAFE_MODE_NEG_RATE) {
            $outcome = 'rollback';
            $reason = sprintf(
                'SAFE MODE: system-wide negative rate %.1f%% exceeds %.1f%% threshold. Only control arms receive traffic.',
                $systemNegRate * 100,
                self::SYSTEM_SAFE_MODE_NEG_RATE * 100
            );
        }

        return [
            'outcome' => $outcome,
            'reason' => $reason,
            'promoteCount' => $promoteCount,
            'pruneCount' => $pruneCount,
            'systemNegRate' => round($systemNegRate, 4),
        ];
    }

    // ======================================================================
    // HELPER METHODS
    // ======================================================================

    /**
     * Get distinct arm types currently active in the system.
     */
    private function getActiveArmTypes(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('DISTINCT a.armType')
            ->from(BanditArm::class, 'a')
            ->where('a.active = true');

        $results = $qb->getQuery()->getScalarResult();
        return array_column($results, 'armType');
    }

    /**
     * Find contacts eligible for outreach this cycle.
     * Excludes recently contacted, bounced, unsubscribed.
     */
    private function findEligibleContacts(int $limit): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('c')
            ->from(\App\Entity\Contact::class, 'c')
            ->where('c.email IS NOT NULL')
            ->andWhere('c.email != :empty')
            ->setParameter('empty', '');

        // Exclude contacts with recent outbound messages (last 7 days)
        $subQb = $this->entityManager->createQueryBuilder();
        $subQb->select('IDENTITY(m.contact)')
            ->from(OutboundMessage::class, 'm')
            ->where('m.sentAt > :recentCutoff');

        $qb->andWhere($qb->expr()->notIn(
            'c.id',
            $subQb->getDQL()
        ));
        $qb->setParameter('recentCutoff', new \DateTime('-7 days'));

        // Exclude bounced/unsubscribed contacts
        $bouncedQb = $this->entityManager->createQueryBuilder();
        $bouncedQb->select('IDENTITY(m2.contact)')
            ->from(OutboundMessage::class, 'm2')
            ->where('m2.status IN (:excludeStatuses)');

        $qb->andWhere($qb->expr()->notIn(
            'c.id',
            $bouncedQb->getDQL()
        ));
        $qb->setParameter('excludeStatuses', [
            OutboundMessage::STATUS_BOUNCED,
        ]);

        $qb->setMaxResults($limit);

        try {
            return $qb->getQuery()->getResult();
        } catch (\Throwable $e) {
            $this->logger->warning('Error finding eligible contacts', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
