<?php

namespace App\Repository;

use App\Entity\RfqLineItem;
use App\Entity\RFQ;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RfqLineItem>
 */
class RfqLineItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RfqLineItem::class);
    }
    
    /**
     * Find line items for an RFQ
     */
    public function findByRfq(RFQ $rfq): array
    {
        return $this->createQueryBuilder('li')
            ->where('li.rfq = :rfq')
            ->setParameter('rfq', $rfq)
            ->orderBy('li.lineNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Get total value of line items for an RFQ
     */
    public function getTotalValue(RFQ $rfq): float
    {
        $result = $this->createQueryBuilder('li')
            ->select('SUM(li.unitPrice * li.quantityAnnual) as lineTotal, SUM(li.nrePrice) as nreTotal')
            ->where('li.rfq = :rfq')
            ->setParameter('rfq', $rfq)
            ->getQuery()
            ->getSingleResult();
        
        return (float) ($result['lineTotal'] ?? 0) + (float) ($result['nreTotal'] ?? 0);
    }
    
    /**
     * Get next available line number for an RFQ
     */
    public function getNextLineNumber(RFQ $rfq): int
    {
        $result = $this->createQueryBuilder('li')
            ->select('MAX(li.lineNumber)')
            ->where('li.rfq = :rfq')
            ->setParameter('rfq', $rfq)
            ->getQuery()
            ->getSingleScalarResult();
        
        return ((int) $result) + 1;
    }
}
