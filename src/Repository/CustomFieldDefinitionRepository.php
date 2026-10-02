<?php

namespace App\Repository;

use App\Entity\CustomFieldDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomFieldDefinition>
 */
class CustomFieldDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomFieldDefinition::class);
    }

    public function save(CustomFieldDefinition $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CustomFieldDefinition $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find all active fields for an entity type
     *
     * @return list<CustomFieldDefinition>
     */
    public function findByEntityType(string $entityType, bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder('f')
            ->where('f.entityType = :entityType')
            ->setParameter('entityType', $entityType)
            ->orderBy('f.sortOrder', 'ASC')
            ->addOrderBy('f.label', 'ASC');

        if ($activeOnly) {
            $qb->andWhere('f.isActive = :active')
               ->setParameter('active', true);
        }

        /** @var list<CustomFieldDefinition> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Find fields that should be shown in list view
     *
     * @return list<CustomFieldDefinition>
     */
    public function findListFields(string $entityType): array
    {
        /** @var list<CustomFieldDefinition> $result */
        $result = $this->createQueryBuilder('f')
            ->where('f.entityType = :entityType')
            ->andWhere('f.isActive = :active')
            ->andWhere('f.showInList = :showInList')
            ->setParameter('entityType', $entityType)
            ->setParameter('active', true)
            ->setParameter('showInList', true)
            ->orderBy('f.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find fields that should be shown in detail view
     *
     * @return list<CustomFieldDefinition>
     */
    public function findDetailFields(string $entityType): array
    {
        /** @var list<CustomFieldDefinition> $result */
        $result = $this->createQueryBuilder('f')
            ->where('f.entityType = :entityType')
            ->andWhere('f.isActive = :active')
            ->andWhere('f.showInDetail = :showInDetail')
            ->setParameter('entityType', $entityType)
            ->setParameter('active', true)
            ->setParameter('showInDetail', true)
            ->orderBy('f.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find searchable fields
     *
     * @return list<CustomFieldDefinition>
     */
    public function findSearchableFields(string $entityType): array
    {
        /** @var list<CustomFieldDefinition> $result */
        $result = $this->createQueryBuilder('f')
            ->where('f.entityType = :entityType')
            ->andWhere('f.isActive = :active')
            ->andWhere('f.isSearchable = :searchable')
            ->setParameter('entityType', $entityType)
            ->setParameter('active', true)
            ->setParameter('searchable', true)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find fields grouped by field group
     *
     * @return array<string, list<CustomFieldDefinition>>
     */
    public function findGroupedByFieldGroup(string $entityType): array
    {
        $fields = $this->findByEntityType($entityType);

        $grouped = [];
        foreach ($fields as $field) {
            $group = $field->getFieldGroup() ?: 'Custom Fields';
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = $field;
        }

        return $grouped;
    }

    /**
     * Check if a field key already exists for an entity type
     */
    public function fieldKeyExists(string $fieldKey, string $entityType, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.fieldKey = :fieldKey')
            ->andWhere('f.entityType = :entityType')
            ->setParameter('fieldKey', $fieldKey)
            ->setParameter('entityType', $entityType);

        if ($excludeId) {
            $qb->andWhere('f.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        /** @var int|string|null $scalar */
        $scalar = $qb->getQuery()->getSingleScalarResult();

        return (int) $scalar > 0;
    }

    /**
     * Get the next sort order for an entity type
     */
    public function getNextSortOrder(string $entityType): int
    {
        /** @var int|string|null $result */
        $result = $this->createQueryBuilder('f')
            ->select('MAX(f.sortOrder)')
            ->where('f.entityType = :entityType')
            ->setParameter('entityType', $entityType)
            ->getQuery()
            ->getSingleScalarResult();

        return ($result !== null ? (int) $result : 0) + 1;
    }

    /**
     * Update sort orders
     *
     * @param array<string|int, mixed> $orderedIds
     */
    public function updateSortOrders(array $orderedIds): void
    {
        foreach ($orderedIds as $order => $id) {
            $this->createQueryBuilder('f')
                ->update()
                ->set('f.sortOrder', ':order')
                ->where('f.id = :id')
                ->setParameter('order', $order)
                ->setParameter('id', $id)
                ->getQuery()
                ->execute();
        }
    }

    /**
     * Get statistics about custom fields
     *
     * @return array{byEntity: array<array-key, mixed>, byType: array<array-key, mixed>, total: int|float}
     */
    public function getStatistics(): array
    {
        /** @var list<array{entityType: mixed, count: mixed}> $byEntity */
        $byEntity = $this->createQueryBuilder('f')
            ->select('f.entityType, COUNT(f.id) as count')
            ->where('f.isActive = :active')
            ->setParameter('active', true)
            ->groupBy('f.entityType')
            ->getQuery()
            ->getResult();

        /** @var list<array{fieldType: mixed, count: mixed}> $byType */
        $byType = $this->createQueryBuilder('f')
            ->select('f.fieldType, COUNT(f.id) as count')
            ->where('f.isActive = :active')
            ->setParameter('active', true)
            ->groupBy('f.fieldType')
            ->getQuery()
            ->getResult();

        return [
            'byEntity' => array_column($byEntity, 'count', 'entityType'),
            'byType' => array_column($byType, 'count', 'fieldType'),
            'total' => array_sum(array_column($byEntity, 'count')),
        ];
    }
}
