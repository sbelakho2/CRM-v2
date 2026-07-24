<?php

namespace App\Repository;

use App\Entity\InboxMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InboxMessage>
 */
class InboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxMessage::class);
    }

    /**
     * Find messages pending human review
     */
    public function findPendingReview(int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.requiresHumanReview = true')
            ->andWhere('m.humanReviewedAt IS NULL')
            ->orderBy('m.receivedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find by classification
     */
    public function findByClassification(string $classification, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.classification = :classification')
            ->setParameter('classification', $classification)
            ->orderBy('m.receivedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find by sender email
     */
    public function findByFromEmail(string $email): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.fromEmail = :email')
            ->setParameter('email', $email)
            ->orderBy('m.receivedAt', 'DESC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get classification statistics
     */
    public function getClassificationStats(): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select([
                'm.classification',
                'COUNT(m.id) as total',
                'AVG(m.classificationConfidence) as avgConfidence',
            ])
            ->groupBy('m.classification');

        $results = $qb->getQuery()->getResult();

        $stats = [
            'total' => 0,
            'pending_review' => 0,
            'by_classification' => [],
        ];

        foreach ($results as $row) {
            $classification = $row['classification'] ?? 'UNKNOWN';
            $stats['by_classification'][$classification] = [
                'count' => (int) $row['total'],
                'avg_confidence' => round((float) ($row['avgConfidence'] ?? 0), 2),
            ];
            $stats['total'] += (int) $row['total'];
        }

        // Count pending reviews
        $stats['pending_review'] = $this->count(['requiresHumanReview' => true, 'humanReviewedAt' => null]);

        return $stats;
    }

    public function save(InboxMessage $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
