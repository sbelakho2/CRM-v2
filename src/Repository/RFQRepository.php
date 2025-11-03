<?php

namespace App\Repository;

use App\Entity\RFQ;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RFQ>
 */
class RFQRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RFQ::class);
    }

    public function getTotalPipelineValue(): float
    {
        $result = $this->createQueryBuilder('r')
            ->select('SUM(r.estimatedValue)')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', ['Submitted', 'In Review'])
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? (float)$result : 0.0;
    }

    public function countSubmittedBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.rfqDate >= :start')
            ->andWhere('r.rfqDate <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countBetweenDates(\DateTime $start, \DateTime $end): int
    {
        return $this->countSubmittedBetween($start, $end);
    }

    public function countNPIAwards(\DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.type = :type')
            ->andWhere('r.status = :status')
            ->andWhere('r.rfqDate >= :start')
            ->andWhere('r.rfqDate <= :end')
            ->setParameter('type', 'NPI')
            ->setParameter('status', 'Award')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countFrameworkAgreements(\DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.type = :type')
            ->andWhere('r.status = :status')
            ->andWhere('r.rfqDate >= :start')
            ->andWhere('r.rfqDate <= :end')
            ->setParameter('type', 'Framework')
            ->setParameter('status', 'Award')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActiveBySector(string $sector): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.company', 'c')
            ->where('c.sector = :sector')
            ->andWhere('r.status NOT IN (:closedStatuses)')
            ->setParameter('sector', $sector)
            ->setParameter('closedStatuses', ['Award', 'Lost', 'Cancelled'])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
