<?php

namespace App\Service;

use App\Entity\BanditArm;
use App\Repository\BanditArmRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Thompson Sampling Service
 * 
 * Multi-armed bandit implementation using Thompson Sampling algorithm.
 * Uses Beta distribution for exploration/exploitation balance.
 * 
 * Mathematical Foundation:
 * - Score ~ Beta(α, β) where α = successes + prior, β = failures + prior
 * - Selection: Pick arm with highest sampled score
 * - Updates use stage-specific WEIGHTED increments (not integer ±1)
 * - Hierarchical priors: new arms inherit from global+industry stats
 * - Decay toward prior (not toward 1) for stale arms
 * - Catastrophic cap: quarantined arms blocked from selection
 * - Per-ICP pools: arms isolated by industry+role cluster
 * 
 * Event weight table:
 *   open:           α += 0.10
 *   click:          α += 0.30
 *   positive reply: α += 2.50
 *   neutral reply:  α += 0.50
 *   negative reply: β += 3.00
 *   unsubscribe:    β += 7.00
 *   no-response:    β += 0.30  (delayed soft failure)
 *   bounce:         β += 1.00
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
class ThompsonSamplerService
{
    // ==================== EVENT WEIGHT TABLE ====================
    public const WEIGHT_OPEN           = 0.10;
    public const WEIGHT_CLICK          = 0.30;
    public const WEIGHT_POSITIVE_REPLY = 2.50;
    public const WEIGHT_NEUTRAL_REPLY  = 0.50;
    public const WEIGHT_NEGATIVE_REPLY = 3.00;
    public const WEIGHT_UNSUBSCRIBE    = 7.00;
    public const WEIGHT_NO_RESPONSE    = 0.30;
    public const WEIGHT_BOUNCE         = 1.00;

    // Default prior (α₀, β₀) for new arms with no parent context
    public const DEFAULT_PRIOR_ALPHA   = 1.0;
    public const DEFAULT_PRIOR_BETA    = 1.0;

    // Catastrophic cap: arm is blocked if recent negative rate exceeds this
    public const CATASTROPHIC_NEG_RATE = 0.40;

    // Max traffic share for recovering arms (after un-quarantine)
    public const RECOVERY_MAX_SHARE    = 0.10;

    // Control group percentage
    public const CONTROL_GROUP_PCT     = 0.10;
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BanditArmRepository $armRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Sample from Beta distribution using the inverse CDF method
     * 
     * Uses Box-Muller approximation for efficiency
     */
    public function sampleBeta(float $alpha, float $beta): float
    {
        // Use the relationship between Beta and Gamma distributions
        // Beta(α, β) = X / (X + Y) where X ~ Gamma(α, 1), Y ~ Gamma(β, 1)
        $x = $this->sampleGamma($alpha);
        $y = $this->sampleGamma($beta);
        
        if ($x + $y === 0.0) {
            return 0.5; // Fallback for edge case
        }
        
        return $x / ($x + $y);
    }

    /**
     * Sample from Gamma distribution using Marsaglia and Tsang's method
     * 
     * @param float $shape Shape parameter (must be > 0)
     */
    private function sampleGamma(float $shape): float
    {
        // Guard against invalid shape parameter (prevents division by zero)
        if ($shape <= 0) {
            $this->logger->warning('Invalid gamma shape parameter, using default', ['shape' => $shape]);
            return 0.5; // Return neutral value for edge case
        }
        
        if ($shape < 1) {
            // For shape < 1, use: Gamma(a) = Gamma(a+1) * U^(1/a)
            return $this->sampleGamma($shape + 1) * pow(mt_rand() / mt_getrandmax(), 1.0 / $shape);
        }
        
        $d = $shape - 1.0 / 3.0;
        $c = 1.0 / sqrt(9.0 * $d);
        
        while (true) {
            // Generate standard normal
            $x = $this->sampleNormal();
            $v = pow(1.0 + $c * $x, 3);
            
            if ($v > 0) {
                $u = mt_rand() / mt_getrandmax();
                
                // Squeeze test
                if ($u < 1.0 - 0.0331 * pow($x, 4) ||
                    log($u) < 0.5 * pow($x, 2) + $d * (1.0 - $v + log($v))) {
                    return $d * $v;
                }
            }
        }
    }

    /**
     * Sample from standard normal using Box-Muller transform
     */
    private function sampleNormal(): float
    {
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();
        
        // Avoid log(0)
        $u1 = max($u1, 1e-10);
        
        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    /**
     * Select best arm via Thompson Sampling with:
     * - Per-ICP pool filtering
     * - Catastrophic exploration cap (quarantined arms excluded)
     * - Under-tested arms explored via Thompson (not uniform random)
     * - Control group routing (10% of calls return control arm)
     * 
     * @param string $armType Type of arm ('subject_line', 'value_prop_*', 'cta')
     * @param string $icpCluster ICP cluster for pool isolation (default 'global')
     * @param bool $applyDecay Whether to apply decay-toward-prior for stale arms
     * @return array|null ['arm' => BanditArm, 'sampledScore' => float, 'isControl' => bool, 'trace' => array]
     */
    public function sampleAndSelect(string $armType, string $icpCluster = 'global', bool $applyDecay = true): ?array
    {
        $trace = ['armType' => $armType, 'icpCluster' => $icpCluster, 'phase' => ''];

        // Fetch arms for this type; prefer ICP-specific, fall back to global
        $arms = $this->armRepository->findActiveByType($armType);
        if (empty($arms)) {
            $this->logger->warning('No active arms found for type', ['armType' => $armType]);
            return null;
        }

        // ==================== ICP POOL FILTERING ====================
        $icpArms = array_filter($arms, fn(BanditArm $a) => $a->getIcpCluster() === $icpCluster);
        if (empty($icpArms)) {
            $icpArms = array_filter($arms, fn(BanditArm $a) => $a->getIcpCluster() === 'global');
        }
        if (empty($icpArms)) {
            $icpArms = $arms; // ultimate fallback
        }
        $icpArms = array_values($icpArms);

        // ==================== EXCLUDE QUARANTINED ARMS ====================
        $eligible = array_filter($icpArms, fn(BanditArm $a) => !$a->isQuarantined());
        if (empty($eligible)) {
            // All quarantined — fall back to full set to avoid deadlock
            $eligible = $icpArms;
        }
        $eligible = array_values($eligible);

        // ==================== CONTROL GROUP ====================
        // 10% of selections return the designated control arm (if one exists)
        $roll = mt_rand() / mt_getrandmax();
        if ($roll < self::CONTROL_GROUP_PCT) {
            $controlArm = $this->findControlArm($eligible, $armType);
            if ($controlArm) {
                $controlArm->markUsed();
                $this->entityManager->persist($controlArm);
                $this->entityManager->flush();
                $trace['phase'] = 'control_group';
                return [
                    'arm' => $controlArm,
                    'sampledScore' => $controlArm->getExpectedRate(),
                    'isControl' => true,
                    'trace' => $trace,
                ];
            }
        }

        // ==================== EXPLORATION: UNDER-TESTED ARMS (Thompson within set) ====================
        $minTrials = (int)($_ENV['THOMPSON_MIN_TRIALS'] ?? 5);
        if ($minTrials > 0) {
            $underTested = array_filter($eligible, fn(BanditArm $a) => $a->getTotalTrials() < $minTrials);
            if (!empty($underTested)) {
                // Instead of uniform random, sample Thompson within under-tested set
                $selected = $this->thompsonSelectFrom(array_values($underTested), false);
                if ($selected) {
                    $selected['arm']->markUsed();
                    $this->entityManager->persist($selected['arm']);
                    $this->entityManager->flush();
                    $trace['phase'] = 'exploration_thompson';
                    $trace['underTestedCount'] = count($underTested);
                    $selected['isControl'] = false;
                    $selected['trace'] = $trace;
                    return $selected;
                }
            }
        }

        // ==================== EXPLOITATION: FULL THOMPSON SAMPLING ====================
        $result = $this->thompsonSelectFrom($eligible, $applyDecay);
        if ($result) {
            $result['arm']->markUsed();
            $this->entityManager->persist($result['arm']);
            $this->entityManager->flush();
            $trace['phase'] = 'exploitation';
            $result['isControl'] = false;
            $result['trace'] = $trace;
        }

        return $result;
    }

    /**
     * Thompson-sample within a set of arms, optionally applying decay-toward-prior.
     *
     * @return array|null ['arm' => BanditArm, 'sampledScore' => float, 'allSamples' => array]
     */
    private function thompsonSelectFrom(array $arms, bool $applyDecay): ?array
    {
        $bestArm = null;
        $bestScore = -1.0;
        $allSamples = [];

        // Compute global prior for decay-toward-prior
        $globalAlpha = self::DEFAULT_PRIOR_ALPHA;
        $globalBeta  = self::DEFAULT_PRIOR_BETA;
        if (count($arms) > 1) {
            $totalSucc  = array_sum(array_map(fn(BanditArm $a) => $a->getTotalSuccesses(), $arms));
            $totalTrials = array_sum(array_map(fn(BanditArm $a) => $a->getTotalTrials(), $arms));
            if ($totalTrials > 0) {
                $globalRate = $totalSucc / $totalTrials;
                $globalAlpha = max(1.0, $globalRate * 10);
                $globalBeta  = max(1.0, (1 - $globalRate) * 10);
            }
        }

        foreach ($arms as $arm) {
            $alpha = $arm->getAlpha();
            $beta  = $arm->getBeta();

            // ==================== DECAY TOWARD PRIOR (not toward 1) ====================
            if ($applyDecay) {
                $daysSinceLastUse = $arm->getDaysSinceLastUse();
                if ($daysSinceLastUse > 14) {
                    $d = 1.0 + ($daysSinceLastUse / 100.0);
                    // α' = α₀ + (α − α₀) / d
                    $alpha = $globalAlpha + ($alpha - $globalAlpha) / $d;
                    $beta  = $globalBeta  + ($beta  - $globalBeta)  / $d;
                    $alpha = max(0.01, $alpha);
                    $beta  = max(0.01, $beta);
                }
            }

            $sampledScore = $this->sampleBeta($alpha, $beta);
            $allSamples[$arm->getId()] = $sampledScore;

            if ($sampledScore > $bestScore) {
                $bestScore = $sampledScore;
                $bestArm = $arm;
            }
        }

        $this->logger->debug('Thompson Sampling selection', [
            'armCount' => count($arms),
            'selectedArm' => $bestArm?->getArmName(),
            'sampledScore' => $bestScore,
        ]);

        return $bestArm ? ['arm' => $bestArm, 'sampledScore' => $bestScore, 'allSamples' => $allSamples] : null;
    }

    /**
     * Find the control-group arm for a type, or promote first arm if none marked.
     */
    private function findControlArm(array $arms, string $armType): ?BanditArm
    {
        foreach ($arms as $arm) {
            if ($arm->isControl()) {
                return $arm;
            }
        }
        // If no control arm, designate the first (oldest) arm as control
        if (!empty($arms)) {
            $arms[0]->setIsControl(true);
            $this->entityManager->persist($arms[0]);
            return $arms[0];
        }
        return null;
    }

    /**
     * Record a WEIGHTED outcome for an arm.
     * 
     * Uses the event weight table instead of integer ±1.
     * Also checks for poison-pill conditions.
     *
     * @param int $armId Arm ID
     * @param string $eventType 'open','click','reply','bounce','unsubscribe','no_response'
     * @param string|null $replyPolarity 'positive','neutral','negative' (only for reply events)
     */
    public function recordWeightedOutcome(int $armId, string $eventType, ?string $replyPolarity = null): void
    {
        $arm = $this->armRepository->find($armId);
        if (!$arm) {
            $this->logger->warning('Arm not found for weighted outcome', ['armId' => $armId]);
            return;
        }

        // Check learning freeze
        if ($this->isLearningFrozen()) {
            $this->logger->info('Learning frozen — skipping update', ['armId' => $armId, 'event' => $eventType]);
            return;
        }

        $weight = match ($eventType) {
            'open'        => self::WEIGHT_OPEN,
            'click'       => self::WEIGHT_CLICK,
            'bounce'      => self::WEIGHT_BOUNCE,
            'unsubscribe' => self::WEIGHT_UNSUBSCRIBE,
            'no_response' => self::WEIGHT_NO_RESPONSE,
            'reply'       => match ($replyPolarity) {
                'positive' => self::WEIGHT_POSITIVE_REPLY,
                'neutral'  => self::WEIGHT_NEUTRAL_REPLY,
                'negative' => self::WEIGHT_NEGATIVE_REPLY,
                default    => 0.0,
            },
            default => 0.0,
        };

        if ($weight === 0.0) {
            return;
        }

        $isSuccess = in_array($eventType, ['open', 'click'], true)
            || ($eventType === 'reply' && in_array($replyPolarity, ['positive', 'neutral'], true));

        if ($isSuccess) {
            $arm->recordSuccess($weight);
        } else {
            $arm->recordFailure($weight);
        }

        // ==================== POISON PILL CHECK ====================
        $this->updateRecentNegativeRate($arm);

        $this->entityManager->flush();

        $this->logger->info('Recorded weighted outcome', [
            'armId' => $armId, 'event' => $eventType, 'polarity' => $replyPolarity,
            'weight' => $weight, 'newAlpha' => round($arm->getAlpha(), 3),
            'newBeta' => round($arm->getBeta(), 3),
        ]);
    }

    /**
     * Record outcome for an arm (legacy compat — maps to weighted updates)
     */
    public function recordOutcome(int $armId, bool $success, string $eventType = 'open'): void
    {
        $replyPolarity = null;
        if (!$success && $eventType === 'bounce') {
            $this->recordWeightedOutcome($armId, 'bounce');
            return;
        }
        $mapped = $success ? $eventType : 'no_response';
        $this->recordWeightedOutcome($armId, $mapped, $replyPolarity);
    }

    /**
     * Record reply outcome (legacy compat — maps to weighted updates)
     */
    public function recordReplyOutcome(int $armId, bool $positiveReply, string $classification = 'unknown'): void
    {
        $polarity = $positiveReply ? 'positive' : 'negative';
        $this->recordWeightedOutcome($armId, 'reply', $polarity);
    }

    // ==================== HIERARCHICAL PRIORS ====================

    /**
     * Create a new arm WITH hierarchical prior inherited from existing arms.
     *
     * New arm inherits a fraction of the global+type mean so it doesn't
     * start with an uninformed Beta(1,1) when the pool already has signal.
     *
     * @param float $inheritFraction Fraction of parent stats to inherit (default 0.15)
     */
    public function createArm(
        string $armType,
        string $armName,
        string $armValue,
        string $icpCluster = 'global',
        float $inheritFraction = 0.15,
    ): BanditArm {
        // Compute hierarchical prior from existing arms of same type
        $existingArms = $this->armRepository->findActiveByType($armType);
        $priorAlpha = self::DEFAULT_PRIOR_ALPHA;
        $priorBeta  = self::DEFAULT_PRIOR_BETA;

        if (!empty($existingArms)) {
            $totalAlpha = array_sum(array_map(fn(BanditArm $a) => $a->getAlpha(), $existingArms));
            $totalBeta  = array_sum(array_map(fn(BanditArm $a) => $a->getBeta(), $existingArms));
            $n = count($existingArms);

            // New arm inherits inheritFraction of the average α,β
            $priorAlpha = max(1.0, self::DEFAULT_PRIOR_ALPHA + $inheritFraction * ($totalAlpha / $n - self::DEFAULT_PRIOR_ALPHA));
            $priorBeta  = max(1.0, self::DEFAULT_PRIOR_BETA  + $inheritFraction * ($totalBeta  / $n - self::DEFAULT_PRIOR_BETA));
        }

        $arm = new BanditArm();
        $arm->setArmType($armType);
        $arm->setArmName($armName);
        $arm->setArmValue($armValue);
        $arm->setAlpha($priorAlpha);
        $arm->setBeta($priorBeta);
        $arm->setIcpCluster($icpCluster);

        $this->entityManager->persist($arm);
        $this->entityManager->flush();

        $this->logger->info('Created arm with hierarchical prior', [
            'id' => $arm->getId(), 'type' => $armType, 'name' => $armName,
            'icp' => $icpCluster, 'priorα' => round($priorAlpha, 3), 'priorβ' => round($priorBeta, 3),
        ]);

        return $arm;
    }

    // ==================== POISON PILL DETECTION ====================

    /**
     * Update recent negative rate for an arm; quarantine if above threshold.
     */
    private function updateRecentNegativeRate(BanditArm $arm): void
    {
        $trials = $arm->getTotalTrials();
        if ($trials < 5) {
            return; // Not enough data
        }

        // Compute rate from last ~20 trials (approximation via α,β ratio)
        $negRate = $arm->getBeta() / ($arm->getAlpha() + $arm->getBeta());
        $arm->setRecentNegativeRate(round($negRate, 4));

        if ($negRate > self::CATASTROPHIC_NEG_RATE && $trials >= 10) {
            $arm->setQuarantined(true);
            $this->logger->warning('POISON PILL: Arm quarantined due to high negative rate', [
                'armId' => $arm->getId(), 'armName' => $arm->getArmName(),
                'negRate' => $negRate, 'threshold' => self::CATASTROPHIC_NEG_RATE,
            ]);
        }
    }

    // ==================== DECAY TOWARD PRIOR (daily job) ====================

    /**
     * Run daily decay on ALL active arms. This should be called by a cron/command,
     * never inline during selection (reproducibility + auditability).
     *
     * α' = α₀ + (α − α₀) / d  where d = 1 + daysSinceUpdate/100
     * β' = β₀ + (β − β₀) / d
     */
    public function runDailyDecay(): array
    {
        $allArms = $this->armRepository->findAll();
        $decayed = [];

        foreach ($allArms as $arm) {
            if (!$arm->isActive()) continue;
            $days = $arm->getDaysSinceLastUse();
            if ($days <= 14) continue;

            $d = 1.0 + ($days / 100.0);
            $oldAlpha = $arm->getAlpha();
            $oldBeta  = $arm->getBeta();

            $arm->setAlpha(max(0.5, self::DEFAULT_PRIOR_ALPHA + ($oldAlpha - self::DEFAULT_PRIOR_ALPHA) / $d));
            $arm->setBeta(max(0.5, self::DEFAULT_PRIOR_BETA   + ($oldBeta  - self::DEFAULT_PRIOR_BETA)  / $d));

            $this->entityManager->persist($arm);
            $decayed[] = ['id' => $arm->getId(), 'name' => $arm->getArmName(), 'days' => $days,
                'alphaΔ' => round($oldAlpha - $arm->getAlpha(), 3), 'betaΔ' => round($oldBeta - $arm->getBeta(), 3)];
        }

        $this->entityManager->flush();
        $this->logger->info('Daily decay completed', ['decayedCount' => count($decayed)]);
        return $decayed;
    }

    // ==================== LEARNING FREEZE ====================

    /**
     * Check if learning updates are frozen (set via settings file or env).
     */
    public function isLearningFrozen(): bool
    {
        return (bool) ($_ENV['THOMPSON_LEARNING_FREEZE'] ?? false);
    }

    // ==================== ROLLBACK TRIGGERS ====================

    /**
     * Run rollback check across all arm types. Returns arms that should be
     * rolled back (negative rate above control for N consecutive checks).
     */
    public function checkRollbackTriggers(): array
    {
        $flagged = [];
        $armTypes = ['subject_line'];

        // Also check value_prop_* types
        $allArms = $this->armRepository->findAll();
        foreach ($allArms as $a) {
            if (str_starts_with($a->getArmType(), 'value_prop_') && !in_array($a->getArmType(), $armTypes, true)) {
                $armTypes[] = $a->getArmType();
            }
        }

        foreach ($armTypes as $type) {
            $arms = $this->armRepository->findActiveByType($type);
            $controlArm = null;
            foreach ($arms as $a) {
                if ($a->isControl()) { $controlArm = $a; break; }
            }
            if (!$controlArm || $controlArm->getTotalTrials() < 5) continue;

            $controlRate = $controlArm->getExpectedRate();

            foreach ($arms as $arm) {
                if ($arm->isControl() || $arm->getTotalTrials() < 10) continue;
                $armRate = $arm->getExpectedRate();

                // Flag if arm is performing >30% worse than control
                if ($armRate < $controlRate * 0.70) {
                    $flagged[] = [
                        'armId' => $arm->getId(), 'armName' => $arm->getArmName(),
                        'armRate' => round($armRate, 4), 'controlRate' => round($controlRate, 4),
                        'deficit' => round(($controlRate - $armRate) / max($controlRate, 0.01), 4),
                    ];
                    // Auto-quarantine severe underperformers
                    if ($armRate < $controlRate * 0.50) {
                        $arm->setQuarantined(true);
                        $this->entityManager->persist($arm);
                    }
                }
            }
        }

        $this->entityManager->flush();
        return $flagged;
    }

    /**
     * Get bandit statistics for a type
     */
    public function getBanditStats(string $armType): array
    {
        return $this->armRepository->getStatsByType($armType);
    }

    /**
     * Get all arms for a type with their statistics
     */
    public function getArmsWithStats(string $armType): array
    {
        $arms = $this->armRepository->findActiveByType($armType);
        
        return array_map(function (BanditArm $arm) {
            return [
                'id' => $arm->getId(),
                'name' => $arm->getArmName(),
                'value' => $arm->getArmValue(),
                'alpha' => round($arm->getAlpha(), 3),
                'beta' => round($arm->getBeta(), 3),
                'totalTrials' => $arm->getTotalTrials(),
                'totalSuccesses' => $arm->getTotalSuccesses(),
                'empiricalRate' => round($arm->getEmpiricalRate(), 4),
                'expectedRate' => round($arm->getExpectedRate(), 4),
                'icpCluster' => $arm->getIcpCluster(),
                'quarantined' => $arm->isQuarantined(),
                'isControl' => $arm->isControl(),
                'recentNegRate' => $arm->getRecentNegativeRate(),
            ];
        }, $arms);
    }

    /**
     * Seed default subject line arms if none exist
     */
    public function seedDefaultSubjectLineArms(): array
    {
        $existing = $this->armRepository->findActiveByType('subject_line');
        
        if (!empty($existing)) {
            return $existing;
        }

        $defaultArms = [
            [
                'name' => 'Direct Question',
                'value' => 'Quick question about {{company_name}} sourcing?',
                'control' => true, // First arm is always the control baseline
            ],
            [
                'name' => 'Value Proposition',
                'value' => 'North Africa manufacturing opportunity for {{company_name}}',
                'control' => false,
            ],
            [
                'name' => 'Capability Focus',
                'value' => '{{company_name}}: PCBA capacity in North Africa',
                'control' => false,
            ],
            [
                'name' => 'Cost Focus',
                'value' => 'Reducing costs for {{company_name}} with nearshore PCBA',
                'control' => false,
            ],
        ];

        $created = [];
        foreach ($defaultArms as $armData) {
            $arm = $this->createArm('subject_line', $armData['name'], $armData['value']);
            if ($armData['control'] ?? false) {
                $arm->setIsControl(true);
                $this->entityManager->persist($arm);
            }
            $created[] = $arm;
        }
        $this->entityManager->flush();

        $this->logger->info('Seeded default subject line arms', ['count' => count($created)]);

        return $created;
    }
}
