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
     */
    public function findActiveByType(string $armType): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.armType = :type')
            ->andWhere('a.active = true')
            ->setParameter('type', $armType)
            ->orderBy('a.totalTrials', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get bandit statistics by arm type
     */
    public function getStatsByType(string $armType): array
    {
        $arms = $this->findActiveByType($armType);
        
        $totalTrials = array_sum(array_map(fn($a) => $a->getTotalTrials(), $arms));
        $totalSuccesses = array_sum(array_map(fn($a) => $a->getTotalSuccesses(), $arms));
        
        // Calculate convergence (how sure we are about the best arm)
        $convergence = 0.0;
        if (count($arms) > 1 && $totalTrials > 0) {
            $rates = array_map(fn($a) => $a->getExpectedRate(), $arms);
            rsort($rates);
            $convergence = isset($rates[1]) ? ($rates[0] - $rates[1]) : 1.0;
        }

        return [
            'arm_count' => count($arms),
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
