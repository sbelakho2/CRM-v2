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
        return $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.sentAt >= :start')
            ->andWhere('e.sentAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * All sends of a campaign with the recipient contact joined, so the
     * show page does not lazy-load one query per send.
     *
     * @return EmailSend[]
     */
    public function findByCampaignWithContact(int $campaignId): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.contact', 'c')
            ->addSelect('c')
            ->where('e.campaign = :campaignId')
            ->setParameter('campaignId', $campaignId)
            ->orderBy('e.touchNumber', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
