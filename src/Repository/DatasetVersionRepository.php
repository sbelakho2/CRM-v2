<?php

namespace App\Repository;

use App\Entity\DatasetVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DatasetVersion>
 */
class DatasetVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatasetVersion::class);
    }

    /**
     * Find the active version for a dataset type
     */
    public function findActiveVersion(string $datasetType): ?DatasetVersion
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.datasetType = :type')
            ->andWhere('d.isActive = :active')
            ->setParameter('type', $datasetType)
            ->setParameter('active', true)
            ->orderBy('d.importedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all versions for a dataset type
     *
     * @return DatasetVersion[]
     */
    public function findByDatasetType(string $datasetType): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.datasetType = :type')
            ->setParameter('type', $datasetType)
            ->orderBy('d.importedAt', 'DESC')

            ->getQuery()
            ->getResult();
    }

    /**
     * Find version by UUID
     */
    public function findByVersionUuid(string $versionUuid): ?DatasetVersion
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.versionUuid = :uuid')
            ->setParameter('uuid', $versionUuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get version history for a dataset type
     *
     * @return DatasetVersion[]
     */
    public function getVersionHistory(string $datasetType, int $limit = 10): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.datasetType = :type')
            ->setParameter('type', $datasetType)
            ->orderBy('d.importedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Deactivate all versions for a dataset type (used before activating a new version)
     */
    public function deactivateAllForType(string $datasetType): int
    {
        return $this->createQueryBuilder('d')
            ->update()
            ->set('d.isActive', ':inactive')
            ->andWhere('d.datasetType = :type')
            ->setParameter('inactive', false)
            ->setParameter('type', $datasetType)
            ->getQuery()
            ->execute();
    }
}
