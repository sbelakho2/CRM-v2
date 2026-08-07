<?php

namespace App\Repository;

use App\Entity\SpintaxTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpintaxTemplate>
 */
class SpintaxTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpintaxTemplate::class);
    }

    /**
     * Find active templates by type
     */
    public function findActiveByType(string $templateType = 'email'): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.templateType = :type')
            ->andWhere('t.active = true')
            ->setParameter('type', $templateType)
            ->orderBy('t.timesUsed', 'DESC')

            ->getQuery()
            ->getResult();
    }

    /**
     * Find best performing template
     */
    public function findBestPerforming(string $templateType = 'email'): ?SpintaxTemplate
    {
        return $this->createQueryBuilder('t')
            ->where('t.templateType = :type')
            ->andWhere('t.active = true')
            ->setParameter('type', $templateType)
            ->orderBy('t.replyRate', 'DESC')
            ->addOrderBy('t.openRate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get a random active template
     */
    public function findRandomActive(string $templateType = 'email'): ?SpintaxTemplate
    {
        $total = (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.templateType = :type')
            ->andWhere('t.active = true')
            ->setParameter('type', $templateType)
            ->getQuery()
            ->getSingleScalarResult();

        if ($total === 0) {
            return null;
        }

        $offset = random_int(0, $total - 1);

        return $this->createQueryBuilder('t')
            ->where('t.templateType = :type')
            ->andWhere('t.active = true')
            ->setParameter('type', $templateType)
            ->setFirstResult($offset)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(SpintaxTemplate $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
