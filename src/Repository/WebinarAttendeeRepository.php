<?php

namespace App\Repository;

use App\Entity\WebinarAttendee;
use App\Entity\Webinar;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebinarAttendee>
 */
class WebinarAttendeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebinarAttendee::class);
    }

    /**
     * Find an attendee by webinar and email (registration duplicate check).
     */
    public function findByWebinarAndEmail(Webinar $webinar, string $email): ?WebinarAttendee
    {
        return $this->createQueryBuilder('a')
            ->where('a.webinar = :webinar')
            ->andWhere('LOWER(a.email) = LOWER(:email)')
            ->setParameter('webinar', $webinar)
            ->setParameter('email', $email)
            ->orderBy('a.registeredAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<App\Entity\WebinarAttendee> */
    public function findNeedingFollowUp(Webinar $webinar): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.webinar = :webinar')
            ->andWhere('a.attended = true')
            ->andWhere('a.followUpSent = false')
            ->setParameter('webinar', $webinar)
            ->getQuery()
            ->getResult();
    }
}
