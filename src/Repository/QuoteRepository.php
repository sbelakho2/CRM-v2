<?php

namespace App\Repository;

use App\Entity\Quote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class QuoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quote::class);
    }

    /**
     * Find quotes by company
     */
    public function findByCompany(int $companyId): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.company = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find quotes pending review (failed auto-publish)
     */
    public function findPendingReview(): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.status = :status')
            ->andWhere('q.autoPublished = :autoPublished')
            ->setParameter('status', 'pending_review')
            ->setParameter('autoPublished', false)
            ->orderBy('q.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
