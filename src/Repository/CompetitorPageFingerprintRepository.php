<?php

namespace App\Repository;

use App\Entity\CompetitorPageFingerprint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitorPageFingerprint>
 */
class CompetitorPageFingerprintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitorPageFingerprint::class);
    }

    public function save(CompetitorPageFingerprint $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Find fingerprint by competitor + URL hash */
    public function findByCompetitorAndUrl(int $competitorId, string $urlHash): ?CompetitorPageFingerprint
    {
        return $this->createQueryBuilder('f')
            ->where('f.competitor = :cid')
            ->andWhere('f.urlHash = :hash')
            ->setParameter('cid', $competitorId)
            ->setParameter('hash', $urlHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Get all fingerprints for a competitor */
    public function findAllForCompetitor(int $competitorId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.competitor = :cid')
            ->setParameter('cid', $competitorId)
            ->orderBy('f.lastCheckedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Find stale fingerprints needing re-check */
    public function findStale(int $daysStale = 7, int $limit = 100): array
    {
        $cutoff = new \DateTime("-{$daysStale} days");
        return $this->createQueryBuilder('f')
            ->where('f.lastCheckedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('f.lastCheckedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
