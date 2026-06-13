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
     * @return CompetitorDetection[]
     */
    public function findByLead(Lead $lead): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('d.competitorTier', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if a lead has any competitor detection.
     */
    public function leadHasCompetitor(Lead $lead): bool
    {
        return (bool) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.lead = :lead')
            ->setParameter('lead', $lead)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get the highest-priority (lowest tier number) competitor for a lead.
     */
    public function getTopCompetitorForLead(Lead $lead): ?CompetitorDetection
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('d.competitorTier', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get competitor detection statistics.
     */
    public function getCompetitorStats(): array
    {
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
