<?php

namespace App\Repository;

use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomFieldValue>
 */
class CustomFieldValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomFieldValue::class);
    }

    public function save(CustomFieldValue $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CustomFieldValue $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find all values for an entity
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('v')
            ->join('v.fieldDefinition', 'f')
            ->where('v.entityType = :entityType')
            ->andWhere('v.entityId = :entityId')
            ->andWhere('f.isActive = :active')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->setParameter('active', true)
            ->orderBy('f.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find values as a key-value map
     */
    public function findAsMap(string $entityType, int $entityId): array
    {
        $values = $this->findByEntity($entityType, $entityId);
        
        $map = [];
        foreach ($values as $value) {
            $map[$value->getFieldDefinition()->getFieldKey()] = $value;
        }

        return $map;
    }

    /**
     * Find a specific value
     */
    public function findValue(CustomFieldDefinition $field, string $entityType, int $entityId): ?CustomFieldValue
    {
        return $this->createQueryBuilder('v')
            ->where('v.fieldDefinition = :field')
            ->andWhere('v.entityType = :entityType')
            ->andWhere('v.entityId = :entityId')
            ->setParameter('field', $field)
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Set a value for an entity (create or update)
     */
    public function setValue(CustomFieldDefinition $field, string $entityType, int $entityId, mixed $value): CustomFieldValue
    {
        $fieldValue = $this->findValue($field, $entityType, $entityId);

        if (!$fieldValue) {
            $fieldValue = new CustomFieldValue();
            $fieldValue->setFieldDefinition($field);
            $fieldValue->setEntityType($entityType);
            $fieldValue->setEntityId($entityId);
        }

        $fieldValue->setValue($value);

        $this->getEntityManager()->persist($fieldValue);
        $this->getEntityManager()->flush();

        return $fieldValue;
    }

    /**
     * Bulk set values for an entity
     */
    public function setValues(string $entityType, int $entityId, array $fieldValues): void
    {
        $em = $this->getEntityManager();

        foreach ($fieldValues as $fieldKey => $value) {
            // Find the field definition
            $field = $em->getRepository(CustomFieldDefinition::class)
                ->findOneBy(['fieldKey' => $fieldKey, 'entityType' => $entityType]);

            if ($field && $field->isActive()) {
                $this->setValue($field, $entityType, $entityId, $value);
            }
        }
    }

    /**
     * Delete all values for an entity
     */
    public function deleteByEntity(string $entityType, int $entityId): int
    {
        return $this->createQueryBuilder('v')
            ->delete()
            ->where('v.entityType = :entityType')
            ->andWhere('v.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->getQuery()
            ->execute();
    }

    /**
     * Search entities by custom field value
     */
    public function searchByFieldValue(CustomFieldDefinition $field, string $searchValue): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.entityId')
            ->where('v.fieldDefinition = :field')
            ->setParameter('field', $field);

        // Search based on field type
        switch ($field->getFieldType()) {
            case CustomFieldDefinition::TYPE_TEXT:
            case CustomFieldDefinition::TYPE_TEXTAREA:
            case CustomFieldDefinition::TYPE_EMAIL:
            case CustomFieldDefinition::TYPE_URL:
            case CustomFieldDefinition::TYPE_PHONE:
            case CustomFieldDefinition::TYPE_SELECT:
                $qb->andWhere('v.textValue LIKE :search')
                   ->setParameter('search', '%' . $searchValue . '%');
                break;

            case CustomFieldDefinition::TYPE_NUMBER:
            case CustomFieldDefinition::TYPE_DECIMAL:
            case CustomFieldDefinition::TYPE_CURRENCY:
            case CustomFieldDefinition::TYPE_PERCENTAGE:
                $qb->andWhere('v.numberValue = :search')
                   ->setParameter('search', $searchValue);
                break;

            case CustomFieldDefinition::TYPE_BOOLEAN:
                $boolValue = in_array(strtolower($searchValue), ['yes', 'true', '1']);
                $qb->andWhere('v.booleanValue = :search')
                   ->setParameter('search', $boolValue);
                break;

            default:
                $qb->andWhere('v.textValue LIKE :search')
                   ->setParameter('search', '%' . $searchValue . '%');
        }

        return array_column($qb->getQuery()->getResult(), 'entityId');
    }

    /**
     * Get unique values for a field (for filter dropdowns)
     */
    public function getUniqueValues(CustomFieldDefinition $field, int $limit = 100): array
    {
        $column = match($field->getFieldType()) {
            CustomFieldDefinition::TYPE_NUMBER,
            CustomFieldDefinition::TYPE_DECIMAL,
            CustomFieldDefinition::TYPE_CURRENCY,
            CustomFieldDefinition::TYPE_PERCENTAGE => 'v.numberValue',
            CustomFieldDefinition::TYPE_BOOLEAN => 'v.booleanValue',
            default => 'v.textValue',
        };

        return $this->createQueryBuilder('v')
            ->select("DISTINCT $column as value")
            ->where('v.fieldDefinition = :field')
            ->andWhere("$column IS NOT NULL")
            ->setParameter('field', $field)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
