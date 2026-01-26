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
 * - Score ~ Beta(α, β) where α = successes + 1, β = failures + 1
 * - Selection: Pick arm with highest sampled score
 * - Update: On success → α++, On failure → β++
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
class ThompsonSamplerService
{
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
    public function sampleBeta(int $alpha, int $beta): float
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
     */
    private function sampleGamma(int $shape): float
    {
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
     * Select best arm via Thompson Sampling
     * 
     * @param string $armType Type of arm ('subject_line', 'template', 'send_time')
     * @return array|null ['arm' => BanditArm, 'sampledScore' => float] or null if no arms
     */
    public function sampleAndSelect(string $armType): ?array
    {
        $arms = $this->armRepository->findActiveByType($armType);
        
        if (empty($arms)) {
            $this->logger->warning('No active arms found for type', ['armType' => $armType]);
            return null;
        }

        $bestArm = null;
        $bestScore = -1.0;
        $allSamples = [];

        foreach ($arms as $arm) {
            $sampledScore = $this->sampleBeta($arm->getAlpha(), $arm->getBeta());
            $allSamples[$arm->getId()] = $sampledScore;
            
            if ($sampledScore > $bestScore) {
                $bestScore = $sampledScore;
                $bestArm = $arm;
            }
        }

        $this->logger->debug('Thompson Sampling selection', [
            'armType' => $armType,
            'armCount' => count($arms),
            'selectedArm' => $bestArm?->getArmName(),
            'sampledScore' => $bestScore,
            'allSamples' => $allSamples,
        ]);

        return $bestArm ? [
            'arm' => $bestArm,
            'sampledScore' => $bestScore,
        ] : null;
    }

    /**
     * Record outcome for an arm (success or failure)
     */
    public function recordOutcome(int $armId, bool $success): void
    {
        $arm = $this->armRepository->find($armId);
        
        if (!$arm) {
            $this->logger->warning('Arm not found for outcome recording', ['armId' => $armId]);
            return;
        }

        if ($success) {
            $arm->recordSuccess();
        } else {
            $arm->recordFailure();
        }

        $this->entityManager->flush();

        $this->logger->info('Recorded outcome for arm', [
            'armId' => $armId,
            'armName' => $arm->getArmName(),
            'success' => $success,
            'newAlpha' => $arm->getAlpha(),
            'newBeta' => $arm->getBeta(),
            'expectedRate' => $arm->getExpectedRate(),
        ]);
    }

    /**
     * Get bandit statistics for a type
     */
    public function getBanditStats(string $armType): array
    {
        return $this->armRepository->getStatsByType($armType);
    }

    /**
     * Create a new arm
     */
    public function createArm(string $armType, string $armName, string $armValue): BanditArm
    {
        $arm = new BanditArm();
        $arm->setArmType($armType);
        $arm->setArmName($armName);
        $arm->setArmValue($armValue);

        $this->entityManager->persist($arm);
        $this->entityManager->flush();

        $this->logger->info('Created new bandit arm', [
            'id' => $arm->getId(),
            'type' => $armType,
            'name' => $armName,
        ]);

        return $arm;
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
                'alpha' => $arm->getAlpha(),
                'beta' => $arm->getBeta(),
                'totalTrials' => $arm->getTotalTrials(),
                'totalSuccesses' => $arm->getTotalSuccesses(),
                'empiricalRate' => round($arm->getEmpiricalRate(), 4),
                'expectedRate' => round($arm->getExpectedRate(), 4),
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
            ],
            [
                'name' => 'Value Proposition',
                'value' => 'Morocco manufacturing opportunity for {{company_name}}',
            ],
            [
                'name' => 'Capability Focus',
                'value' => '{{company_name}}: PCBA capacity in Morocco',
            ],
            [
                'name' => 'Cost Focus',
                'value' => 'Reducing costs for {{company_name}} with nearshore PCBA',
            ],
        ];

        $created = [];
        foreach ($defaultArms as $armData) {
            $arm = $this->createArm('subject_line', $armData['name'], $armData['value']);
            $created[] = $arm;
        }

        $this->logger->info('Seeded default subject line arms', ['count' => count($created)]);

        return $created;
    }
}
