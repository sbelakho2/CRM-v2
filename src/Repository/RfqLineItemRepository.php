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
     *
     * @return list<RfqLineItem>
     */
    public function findByRfq(RFQ $rfq): array
    {
        /** @var list<RfqLineItem> $result */
        $result = $this->createQueryBuilder('li')
            ->where('li.rfq = :rfq')
            ->setParameter('rfq', $rfq)
            ->orderBy('li.lineNumber', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
    
    /**
     * Get total value of line items for an RFQ
     */
    public function getTotalValue(RFQ $rfq): float
    {
        /** @var array<string, int|string|float|null> $result */
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
        /** @var int|string|null $result */
        $result = $this->createQueryBuilder('li')
            ->select('MAX(li.lineNumber)')
            ->where('li.rfq = :rfq')
            ->setParameter('rfq', $rfq)
            ->getQuery()
            ->getSingleScalarResult();
        
        return ((int) $result) + 1;
    }
}
