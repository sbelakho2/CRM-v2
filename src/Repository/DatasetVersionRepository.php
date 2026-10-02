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
        /** @var DatasetVersion|null $version */
        $version = $this->createQueryBuilder('d')
            ->andWhere('d.datasetType = :type')
            ->andWhere('d.isActive = :active')
            ->setParameter('type', $datasetType)
            ->setParameter('active', true)
            ->orderBy('d.importedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $version;
    }

    /**
     * Find all versions for a dataset type
     *
     * @return list<DatasetVersion>
     */
    public function findByDatasetType(string $datasetType): array
    {
        /** @var list<DatasetVersion> $versions */
        $versions = $this->createQueryBuilder('d')
            ->andWhere('d.datasetType = :type')
            ->setParameter('type', $datasetType)
            ->orderBy('d.importedAt', 'DESC')

            ->getQuery()
            ->getResult();

        return $versions;
    }

    /**
     * Find version by UUID
     */
    public function findByVersionUuid(string $versionUuid): ?DatasetVersion
    {
        /** @var DatasetVersion|null $version */
        $version = $this->createQueryBuilder('d')
            ->andWhere('d.versionUuid = :uuid')
            ->setParameter('uuid', $versionUuid)
            ->getQuery()
            ->getOneOrNullResult();

        return $version;
    }

    /**
     * Get version history for a dataset type
     *
     * @return list<DatasetVersion>
     */
    public function getVersionHistory(string $datasetType, int $limit = 10): array
    {
        /** @var list<DatasetVersion> $versions */
        $versions = $this->createQueryBuilder('d')
            ->andWhere('d.datasetType = :type')
            ->setParameter('type', $datasetType)
            ->orderBy('d.importedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $versions;
    }

    /**
     * Deactivate all versions for a dataset type (used before activating a new version)
     */
    public function deactivateAllForType(string $datasetType): int
    {
        /** @var int $affected */
        $affected = $this->createQueryBuilder('d')
            ->update()
            ->set('d.isActive', ':inactive')
            ->andWhere('d.datasetType = :type')
            ->setParameter('inactive', false)
            ->setParameter('type', $datasetType)
            ->getQuery()
            ->execute();

        return $affected;
    }
}
