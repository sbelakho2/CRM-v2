<?php

namespace App\Repository;

use App\Entity\CompetitorDetection;
use App\Entity\Lead;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitorDetection>
 */
class CompetitorDetectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitorDetection::class);
    }

    /**
     * Find detections by lead.
     *
     * @return list<CompetitorDetection>
     */
    public function findByLead(Lead $lead): array
    {
        /** @var list<CompetitorDetection> $result */
        $result = $this->createQueryBuilder('d')
            ->andWhere('d.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('d.competitorTier', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Check if a lead has any competitor detection.
     */
    public function leadHasCompetitor(Lead $lead): bool
    {
        /** @var int|string|null $count */
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.lead = :lead')
            ->setParameter('lead', $lead)
            ->getQuery()
            ->getSingleScalarResult();

        return (bool) $count;
    }

    /**
     * Get the highest-priority (lowest tier number) competitor for a lead.
     */
    public function getTopCompetitorForLead(Lead $lead): ?CompetitorDetection
    {
        /** @var CompetitorDetection|null $result */
        $result = $this->createQueryBuilder('d')
            ->andWhere('d.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('d.competitorTier', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Get competitor detection statistics.
     *
     * @return list<array{name: mixed, domain: mixed, tier: mixed, count: int}>
     */
    public function getCompetitorStats(): array
    {
        /** @var list<array{competitorName: mixed, competitorDomain: mixed, competitorTier: mixed, detectionCount: int|string}> $results */
        $results = $this->createQueryBuilder('d')
            ->select('d.competitorName, d.competitorDomain, d.competitorTier, COUNT(d.id) as detectionCount')
            ->groupBy('d.competitorName, d.competitorDomain, d.competitorTier')
            ->orderBy('detectionCount', 'DESC')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($results as $row) {
            $stats[] = [
                'name' => $row['competitorName'],
                'domain' => $row['competitorDomain'],
                'tier' => $row['competitorTier'],
                'count' => (int) $row['detectionCount'],
            ];
        }

        return $stats;
    }

    /**
     * Find a detection by lead and competitor domain.
     */
    public function findOneByLeadAndDomain(Lead $lead, string $domain): ?CompetitorDetection
    {
        return $this->findOneBy([
            'lead' => $lead,
            'competitorDomain' => $domain,
        ]);
    }
}
