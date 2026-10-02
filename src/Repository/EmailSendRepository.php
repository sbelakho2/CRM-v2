<?php

namespace App\Repository;

use App\Entity\EmailSend;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailSend>
 */
class EmailSendRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailSend::class);
    }

    public function countSentBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        /** @var int|string|null $result */
        $result = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.sentAt >= :start')
            ->andWhere('e.sentAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * All sends of a campaign with the recipient contact joined, so the
     * show page does not lazy-load one query per send.
     *
     * @return list<EmailSend>
     */
    public function findByCampaignWithContact(int $campaignId): array
    {
        /** @var list<EmailSend> $result */
        $result = $this->createQueryBuilder('e')
            ->leftJoin('e.contact', 'c')
            ->addSelect('c')
            ->where('e.campaign = :campaignId')
            ->setParameter('campaignId', $campaignId)
            ->orderBy('e.touchNumber', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find the record of a campaign touch in ANY state.
     *
     * Retry semantics depend on the full row: SENT means do not resend,
     * QUEUED/SENDING means another worker owns it, FAILED means the row
     * must be REUSED for the retry (never re-inserted — the unique
     * (campaign, contact, touch) constraint forbids a second row).
     */
    public function findTouch(int $campaignId, int $contactId, int $touchNumber): ?EmailSend
    {
        /** @var EmailSend|null $result */
        $result = $this->createQueryBuilder('e')
            ->where('e.campaign = :campaignId')
            ->andWhere('e.contact = :contactId')
            ->andWhere('e.touchNumber = :touchNumber')
            ->setParameter('campaignId', $campaignId)
            ->setParameter('contactId', $contactId)
            ->setParameter('touchNumber', $touchNumber)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Find the record of an already-delivered (or in-flight) campaign touch.
     *
     * Used for per-(campaign, contact, touch) idempotency: if a sent/sending
     * row exists, a redelivered worker message must not email the customer a
     * second time for the same touch.
     */
    public function findSentTouch(int $campaignId, int $contactId, int $touchNumber): ?EmailSend
    {
        /** @var EmailSend|null $result */
        $result = $this->createQueryBuilder('e')
            ->where('e.campaign = :campaignId')
            ->andWhere('e.contact = :contactId')
            ->andWhere('e.touchNumber = :touchNumber')
            ->andWhere('e.status IN (:statuses)')
            ->setParameter('campaignId', $campaignId)
            ->setParameter('contactId', $contactId)
            ->setParameter('touchNumber', $touchNumber)
            ->setParameter('statuses', [EmailSend::STATUS_SENT, EmailSend::STATUS_SENDING])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }
}
