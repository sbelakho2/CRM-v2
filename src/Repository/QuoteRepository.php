<?php

namespace App\Repository;

use App\Entity\Quote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Quote>
 */
class QuoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quote::class);
    }

    /**
     * Find quotes by company
     *
     * @return list<Quote>
     */
    public function findByCompany(int $companyId): array
    {
        /** @var list<Quote> $result */
        $result = $this->createQueryBuilder('q')
            ->where('q.company = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find quotes pending review (failed auto-publish)
     *
     * @return list<Quote>
     */
    public function findPendingReview(): array
    {
        /** @var list<Quote> $result */
        $result = $this->createQueryBuilder('q')
            ->where('q.status = :status')
            ->andWhere('q.autoPublished = :autoPublished')
            ->setParameter('status', 'pending_review')
            ->setParameter('autoPublished', false)
            ->orderBy('q.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find a single quote with its BOM lines eagerly loaded (avoids N+1).
     */
    public function findWithBomLines(int $id): ?Quote
    {
        /** @var Quote|null $result */
        $result = $this->createQueryBuilder('q')
            ->leftJoin('q.bomLines', 'b')
            ->addSelect('b')
            ->where('q.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Atomically increment the view count of a quote (avoids read-modify-write races).
     */
    public function incrementViewCount(int $id): void
    {
        $this->createQueryBuilder('q')
            ->update()
            ->set('q.viewCount', 'q.viewCount + 1')
            ->set('q.lastViewedAt', ':now')
            ->where('q.id = :id')
            ->setParameter('now', new \DateTime())
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();
    }
}
