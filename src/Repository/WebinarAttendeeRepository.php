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
