<?php

namespace App\Repository;

use App\Entity\ComplianceDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComplianceDocument>
 */
class ComplianceDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceDocument::class);
    }
    
    /**
     * Find all documents that need attention (expired, expiring soon, or required but not provided)
     * Respects snooze settings - snoozed documents are excluded unless $includeSnoozed is true.
     * 
     * @param int $expiryWarningDays Days before expiry to include as "expiring soon"
     * @param int $limit Maximum number of results
     * @param bool $includeSnoozed Whether to include snoozed documents
     * @return ComplianceDocument[]
     */
    public function findDocumentsNeedingAttention(int $expiryWarningDays = 30, int $limit = 20, bool $includeSnoozed = false): array
    {
        $warningDate = (new \DateTime())->modify("+{$expiryWarningDays} days");
        $today = new \DateTime('today');
        
        // Get documents with expiry dates that are expired or expiring soon
        // OR documents that are required but not provided
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.company', 'c')
            ->addSelect('c')
            ->where('c.companyStatus = :companyStatus')
            ->andWhere(
                '(d.expiryDate IS NOT NULL AND d.expiryDate <= :warningDate) OR ' .
                '(d.required = :required AND d.provided = :provided) OR ' .
                'd.status IN (:alertStatuses)'
            )
            ->setParameter('companyStatus', 'active')
            ->setParameter('warningDate', $warningDate)
            ->setParameter('required', true)
            ->setParameter('provided', false)
            ->setParameter('alertStatuses', ['Rejected', 'Expired']);
        
        // Exclude snoozed documents (unless explicitly included)
        if (!$includeSnoozed) {
            $qb->andWhere('(d.snoozedUntil IS NULL OR d.snoozedUntil < :today)')
               ->setParameter('today', $today);
        }
        
        $qb->orderBy('d.expiryDate', 'ASC')
           ->setMaxResults($limit);

        /** @var list<ComplianceDocument> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }
    
    /**
     * Find snoozed documents that still have underlying issues
     * Useful for showing a "snoozed alerts" summary
     * 
     * @param int $limit Maximum number of results
     * @return list<ComplianceDocument>
     */
    public function findSnoozedDocuments(int $limit = 20): array
    {
        $today = new \DateTime('today');
        $warningDate = (new \DateTime())->modify('+30 days');
        
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.company', 'c')
            ->addSelect('c')
            ->where('c.companyStatus = :companyStatus')
            ->andWhere('d.snoozedUntil IS NOT NULL AND d.snoozedUntil >= :today')
            ->andWhere(
                '(d.expiryDate IS NOT NULL AND d.expiryDate <= :warningDate) OR ' .
                '(d.required = :required AND d.provided = :provided) OR ' .
                'd.status IN (:alertStatuses)'
            )
            ->setParameter('companyStatus', 'active')
            ->setParameter('today', $today)
            ->setParameter('warningDate', $warningDate)
            ->setParameter('required', true)
            ->setParameter('provided', false)
            ->setParameter('alertStatuses', ['Rejected', 'Expired'])
            ->orderBy('d.snoozedUntil', 'ASC')
            ->setMaxResults($limit);

        /** @var list<ComplianceDocument> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }
    
    /**
     * Get compliance alert counts for dashboard summary
     * Respects snooze settings - snoozed documents are counted separately
     * 
     * @return array{expired: int, expiring_soon: int, missing: int, rejected: int, snoozed: int, total: int, total_with_snoozed: int}
     */
    public function getAlertCounts(): array
    {
        $today = new \DateTime('today');
        $warningDate = (new \DateTime())->modify('+30 days');

        /** @var array<string, int|string|null> $result */
        $result = $this->createQueryBuilder('d')
            ->select('
                SUM(CASE WHEN (d.snoozedUntil IS NULL OR d.snoozedUntil < :today) AND d.expiryDate IS NOT NULL AND d.expiryDate < :today THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN (d.snoozedUntil IS NULL OR d.snoozedUntil < :today) AND d.expiryDate >= :today AND d.expiryDate <= :warningDate THEN 1 ELSE 0 END) as expiringSoon,
                SUM(CASE WHEN (d.snoozedUntil IS NULL OR d.snoozedUntil < :today) AND d.required = :required AND d.provided = :provided THEN 1 ELSE 0 END) as missing,
                SUM(CASE WHEN (d.snoozedUntil IS NULL OR d.snoozedUntil < :today) AND d.status = :rejectedStatus THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN d.snoozedUntil IS NOT NULL AND d.snoozedUntil >= :today AND ((d.expiryDate IS NOT NULL AND d.expiryDate <= :warningDate) OR (d.required = :required AND d.provided = :provided) OR d.status IN (:alertStatuses)) THEN 1 ELSE 0 END) as snoozed
            ')
            ->leftJoin('d.company', 'c')
            ->where('c.companyStatus = :companyStatus')
            ->setParameter('companyStatus', 'active')
            ->setParameter('today', $today)
            ->setParameter('warningDate', $warningDate)
            ->setParameter('required', true)
            ->setParameter('provided', false)
            ->setParameter('rejectedStatus', 'Rejected')
            ->setParameter('alertStatuses', ['Rejected', 'Expired'])
            ->getQuery()
            ->getSingleResult();

        $expired = (int) ($result['expired'] ?? 0);
        $expiringSoon = (int) ($result['expiringSoon'] ?? 0);
        $missing = (int) ($result['missing'] ?? 0);
        $rejected = (int) ($result['rejected'] ?? 0);
        $snoozed = (int) ($result['snoozed'] ?? 0);
        $total = $expired + $expiringSoon + $missing + $rejected;

        return [
            'expired' => $expired,
            'expiring_soon' => $expiringSoon,
            'missing' => $missing,
            'rejected' => $rejected,
            'snoozed' => $snoozed,
            'total' => $total,
            'total_with_snoozed' => $total + $snoozed,
        ];
    }
}
