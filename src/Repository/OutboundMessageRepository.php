<?php

namespace App\Repository;

use App\Entity\OutboundMessage;
use App\Entity\BanditArm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OutboundMessage>
 */
class OutboundMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutboundMessage::class);
    }

    /**
     * Find messages by status
     */
    public function findByStatus(string $status, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.status = :status')
            ->setParameter('status', $status)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find messages needing outcome recording for Thompson Sampling
     */
    public function findNeedingOutcomeRecording(int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.outcomeRecorded = false')
            ->andWhere('m.subjectArm IS NOT NULL')
            ->andWhere('m.status IN (:statuses)')
            ->setParameter('statuses', [
                OutboundMessage::STATUS_OPENED,
                OutboundMessage::STATUS_CLICKED,
                OutboundMessage::STATUS_REPLIED,
                OutboundMessage::STATUS_BOUNCED,
                OutboundMessage::STATUS_FAILED,
            ])
            ->orderBy('m.sentAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find by external message ID
     */
    public function findByMessageId(string $messageId): ?OutboundMessage
    {
        return $this->findOneBy(['messageId' => $messageId]);
    }

    /**
     * Find by variation hash (for deduplication)
     */
    public function findByVariationHash(string $hash): ?OutboundMessage
    {
        return $this->findOneBy(['variationHash' => $hash]);
    }

    /**
     * Get statistics for a bandit arm
     */
    public function getArmStatistics(BanditArm $arm): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select([
                'COUNT(m.id) as total',
                'SUM(CASE WHEN m.status IN (:successStatuses) THEN 1 ELSE 0 END) as successes',
                'SUM(CASE WHEN m.status IN (:failureStatuses) THEN 1 ELSE 0 END) as failures',
            ])
            ->where('m.subjectArm = :arm')
            ->setParameter('arm', $arm)
            ->setParameter('successStatuses', [
                OutboundMessage::STATUS_OPENED,
                OutboundMessage::STATUS_CLICKED,
                OutboundMessage::STATUS_REPLIED,
            ])
            ->setParameter('failureStatuses', [
                OutboundMessage::STATUS_BOUNCED,
                OutboundMessage::STATUS_FAILED,
            ]);

        $result = $qb->getQuery()->getSingleResult();

        return [
            'total' => (int) $result['total'],
            'successes' => (int) $result['successes'],
            'failures' => (int) $result['failures'],
            'pending' => (int) $result['total'] - (int) $result['successes'] - (int) $result['failures'],
        ];
    }

    public function save(OutboundMessage $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
