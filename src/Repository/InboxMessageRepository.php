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
     *
     * @return list<InboxMessage>
     */
    public function findPendingReview(int $limit = 50): array
    {
        /** @var list<InboxMessage> $results */
        $results = $this->createQueryBuilder('m')
            ->where('m.requiresHumanReview = true')
            ->andWhere('m.humanReviewedAt IS NULL')
            ->orderBy('m.receivedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find by classification
     *
     * @return list<InboxMessage>
     */
    public function findByClassification(string $classification, int $limit = 100): array
    {
        /** @var list<InboxMessage> $results */
        $results = $this->createQueryBuilder('m')
            ->where('m.classification = :classification')
            ->setParameter('classification', $classification)
            ->orderBy('m.receivedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find by sender email
     *
     * @return list<InboxMessage>
     */
    public function findByFromEmail(string $email): array
    {
        /** @var list<InboxMessage> $results */
        $results = $this->createQueryBuilder('m')
            ->where('m.fromEmail = :email')
            ->setParameter('email', $email)
            ->orderBy('m.receivedAt', 'DESC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Get classification statistics
     *
     * @return array{total: int, pending_review: int, by_classification: array<string, array{count: int, avg_confidence: float}>}
     */
    public function getClassificationStats(): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select([
                'm.classification',
                'COUNT(m.id) as total',
                'AVG(m.classificationConfidence) as avgConfidence',
                'SUM(CASE WHEN m.requiresHumanReview = :pendingReview AND m.humanReviewedAt IS NULL THEN 1 ELSE 0 END) as pendingCount',
            ])
            ->setParameter('pendingReview', true)
            ->groupBy('m.classification');

        /** @var array<int, array<string, mixed>> $results */
        $results = $qb->getQuery()->getResult();

        $stats = [
            'total' => 0,
            'pending_review' => 0,
            'by_classification' => [],
        ];

        foreach ($results as $row) {
            $classification = is_string($row['classification'] ?? null) ? $row['classification'] : 'UNKNOWN';
            $total = is_numeric($row['total'] ?? null) ? (int) $row['total'] : 0;
            $avgConfidence = is_numeric($row['avgConfidence'] ?? null) ? (float) $row['avgConfidence'] : 0.0;
            $pendingCount = is_numeric($row['pendingCount'] ?? null) ? (int) $row['pendingCount'] : 0;
            $stats['by_classification'][$classification] = [
                'count' => $total,
                'avg_confidence' => round($avgConfidence, 2),
            ];
            $stats['total'] += $total;
            $stats['pending_review'] += $pendingCount;
        }

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
