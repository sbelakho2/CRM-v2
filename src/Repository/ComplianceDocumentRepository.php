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
            ->where('(d.expiryDate IS NOT NULL AND d.expiryDate <= :warningDate)')
            ->orWhere('(d.required = :required AND d.provided = :provided)')
            ->orWhere('d.status IN (:alertStatuses)')
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
        
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find snoozed documents that still have underlying issues
     * Useful for showing a "snoozed alerts" summary
     * 
     * @param int $limit Maximum number of results
     * @return ComplianceDocument[]
     */
    public function findSnoozedDocuments(int $limit = 20): array
    {
        $today = new \DateTime('today');
        $warningDate = (new \DateTime())->modify('+30 days');
        
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.company', 'c')
            ->addSelect('c')
            ->where('d.snoozedUntil IS NOT NULL AND d.snoozedUntil >= :today')
            ->andWhere(
                '(d.expiryDate IS NOT NULL AND d.expiryDate <= :warningDate) OR ' .
                '(d.required = :required AND d.provided = :provided) OR ' .
                'd.status IN (:alertStatuses)'
            )
            ->setParameter('today', $today)
            ->setParameter('warningDate', $warningDate)
            ->setParameter('required', true)
            ->setParameter('provided', false)
            ->setParameter('alertStatuses', ['Rejected', 'Expired'])
            ->orderBy('d.snoozedUntil', 'ASC')
            ->setMaxResults($limit);
        
        return $qb->getQuery()->getResult();
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
        
        // Base condition to exclude snoozed
        $notSnoozedCondition = '(d.snoozedUntil IS NULL OR d.snoozedUntil < :today)';
        
        // Expired count (not snoozed)
        $expired = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.expiryDate IS NOT NULL AND d.expiryDate < :today')
            ->andWhere($notSnoozedCondition)
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Expiring soon (within 30 days but not yet expired, not snoozed)
        $expiringSoon = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.expiryDate >= :today AND d.expiryDate <= :warningDate')
            ->andWhere($notSnoozedCondition)
            ->setParameter('today', $today)
            ->setParameter('warningDate', $warningDate)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Missing (required but not provided, not snoozed)
        $missing = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.required = :required AND d.provided = :provided')
            ->andWhere($notSnoozedCondition)
            ->setParameter('required', true)
            ->setParameter('provided', false)
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Rejected (not snoozed)
        $rejected = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.status = :status')
            ->andWhere($notSnoozedCondition)
            ->setParameter('status', 'Rejected')
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Snoozed (with underlying issues)
        $snoozed = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.snoozedUntil IS NOT NULL AND d.snoozedUntil >= :today')
            ->andWhere(
                '(d.expiryDate IS NOT NULL AND d.expiryDate <= :warningDate) OR ' .
                '(d.required = :required AND d.provided = :provided) OR ' .
                'd.status IN (:alertStatuses)'
            )
            ->setParameter('today', $today)
            ->setParameter('warningDate', $warningDate)
            ->setParameter('required', true)
            ->setParameter('provided', false)
            ->setParameter('alertStatuses', ['Rejected', 'Expired'])
            ->getQuery()
            ->getSingleScalarResult();
        
        $total = (int) ($expired + $expiringSoon + $missing + $rejected);
        
        return [
            'expired' => (int) $expired,
            'expiring_soon' => (int) $expiringSoon,
            'missing' => (int) $missing,
            'rejected' => (int) $rejected,
            'snoozed' => (int) $snoozed,
            'total' => $total,
            'total_with_snoozed' => $total + (int) $snoozed,
        ];
    }
}
