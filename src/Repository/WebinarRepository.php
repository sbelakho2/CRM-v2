<?php

namespace App\Repository;

use App\Entity\Webinar;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Webinar>
 */
class WebinarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Webinar::class);
    }

    public function countAttendeesBetween(\DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('w')
            ->select('SUM(w.attendedCount)')
            ->where('w.scheduledDate >= :start')
            ->andWhere('w.scheduledDate <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /** @return list<App\Entity\Webinar> */
    public function findUpcoming(?string $language = null): array
    {
        $qb = $this->createQueryBuilder('w')
            ->where('w.scheduledDate >= :now')
            ->setParameter('now', new \DateTime())
            ->orderBy('w.scheduledDate', 'ASC');

        if ($language) {
            $qb->andWhere('w.language = :language')
               ->setParameter('language', $language);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<App\Entity\Webinar> */
    public function findPast(?string $language = null): array
    {
        $qb = $this->createQueryBuilder('w')
            ->where('w.scheduledDate < :now')
            ->setParameter('now', new \DateTime())
            ->orderBy('w.scheduledDate', 'DESC');

        if ($language) {
            $qb->andWhere('w.language = :language')
               ->setParameter('language', $language);
        }

        return $qb->getQuery()->getResult();
    }
}
