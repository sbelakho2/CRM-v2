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
     * Find detections for a lead
     */
    public function findByLead(Lead $lead): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('d.competitorTier', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find leads by competitor tier (for Sniper targeting)
     */
    public function findLeadsByTier(int $tier, int $limit = 100): array
    {
        return $this->createQueryBuilder('d')
            ->select('d', 'l')
            ->join('d.lead', 'l')
            ->where('d.competitorTier = :tier')
            ->setParameter('tier', $tier)
            ->orderBy('l.leadScore', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find leads by specific competitor domain
     */
    public function findLeadsByCompetitor(string $competitorDomain, int $limit = 100): array
    {
        return $this->createQueryBuilder('d')
            ->select('d', 'l')
            ->join('d.lead', 'l')
            ->where('d.competitorDomain = :domain')
            ->setParameter('domain', strtolower($competitorDomain))
            ->orderBy('l.leadScore', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get competitor statistics
     */
    public function getCompetitorStats(): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select([
                'd.competitorDomain',
                'd.competitorName',
                'd.competitorTier',
                'COUNT(d.id) as leadCount',
            ])
            ->groupBy('d.competitorDomain', 'd.competitorName', 'd.competitorTier')
            ->orderBy('d.competitorTier', 'ASC')
            ->addOrderBy('leadCount', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Check if lead has competitor detection
     */
    public function leadHasCompetitor(Lead $lead): bool
    {
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.lead = :lead')
            ->setParameter('lead', $lead)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Get top competitor for a lead
     */
    public function getTopCompetitorForLead(Lead $lead): ?CompetitorDetection
    {
        return $this->createQueryBuilder('d')
            ->where('d.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('d.competitorTier', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(CompetitorDetection $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
