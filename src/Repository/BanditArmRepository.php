<?php

namespace App\Repository;

use App\Entity\BanditArm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BanditArm>
 */
class BanditArmRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BanditArm::class);
    }

    /**
     * Find active arms by type
     *
     * @return list<BanditArm>
     */
    public function findActiveByType(string $armType): array
    {
        /** @var list<BanditArm> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.armType = :type')
            ->andWhere('a.active = true')
            ->setParameter('type', $armType)
            ->orderBy('a.totalTrials', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Get bandit statistics by arm type
     *
     * @return array{arm_count: int, total_trials: int, total_successes: int, overall_rate: float, convergence: float}
     */
    public function getStatsByType(string $armType): array
    {
        /** @var array<string, int|string|float|null> $result */
        $result = $this->createQueryBuilder('a')
            ->select('SUM(a.totalTrials) as totalTrials, SUM(a.totalSuccesses) as totalSuccesses, COUNT(a.id) as armCount')
            ->where('a.armType = :type')
            ->andWhere('a.active = true')
            ->setParameter('type', $armType)
            ->getQuery()
            ->getSingleResult();

        $totalTrials = (int) ($result['totalTrials'] ?? 0);
        $totalSuccesses = (int) ($result['totalSuccesses'] ?? 0);
        $armCount = (int) ($result['armCount'] ?? 0);

        $convergence = 0.0;
        if ($armCount > 1 && $totalTrials > 0) {
            $arms = $this->findActiveByType($armType);
            $rates = array_map(fn($a) => $a->getExpectedRate(), $arms);
            rsort($rates);
            $convergence = isset($rates[1]) ? ($rates[0] - $rates[1]) : 1.0;
        }

        return [
            'arm_count' => $armCount,
            'total_trials' => $totalTrials,
            'total_successes' => $totalSuccesses,
            'overall_rate' => $totalTrials > 0 ? round($totalSuccesses / $totalTrials, 4) : 0,
            'convergence' => round($convergence, 4),
        ];
    }

    /**
     * Find arm with highest expected rate (for display)
     */
    public function findBestArm(string $armType): ?BanditArm
    {
        $arms = $this->findActiveByType($armType);
        
        if (empty($arms)) {
            return null;
        }

        usort($arms, fn($a, $b) => $b->getExpectedRate() <=> $a->getExpectedRate());
        
        return $arms[0];
    }

    public function save(BanditArm $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
