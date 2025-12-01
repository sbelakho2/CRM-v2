<?php

namespace App\EventListener;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Audit Log Listener
 * 
 * Automatically tracks all entity changes (create, update, delete) for auditing purposes.
 * Stores old/new values and the user who made the change.
 */
class AuditLogListener
{
    private array $entitiesToAudit = [
        'Company',
        'Contact',
        'Lead',
        'Quote',
        'RFQ',
        'Activity',
        'EmailCampaign',
        'User',
        'AbmAccount',
        'BomLine',
    ];

    private array $sensitiveFields = [
        'password',
        'plainPassword',
        'salt',
        'resetToken',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        
        if (!$this->shouldAudit($entity)) {
            return;
        }

        $this->createAuditLog('create', $entity);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        
        if (!$this->shouldAudit($entity)) {
            return;
        }

        $changeSet = $args->getEntityChangeSet();
        
        if (empty($changeSet)) {
            return;
        }

        $this->createAuditLog('update', $entity, $changeSet);
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        
        if (!$this->shouldAudit($entity)) {
            return;
        }

        $this->createAuditLog('delete', $entity);
    }

    private function shouldAudit(object $entity): bool
    {
        // Don't audit AuditLog entities (would create infinite loop)
        if ($entity instanceof AuditLog) {
            return false;
        }

        $className = $this->getShortClassName($entity);
        
        return in_array($className, $this->entitiesToAudit);
    }

    private function createAuditLog(string $action, object $entity, array $changeSet = []): void
    {
        $auditLog = new AuditLog();
        $auditLog->setEntityType($this->getShortClassName($entity));
        
        // Get entity ID
        $metadata = $this->em->getClassMetadata(get_class($entity));
        $identifierValues = $metadata->getIdentifierValues($entity);
        $entityId = reset($identifierValues);
        
        if ($entityId) {
            $auditLog->setEntityId($entityId);
        }
        
        $auditLog->setAction($action);
        
        // Get current user
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $auditLog->setUser($user);
        }
        
        // Get request info
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $auditLog->setIpAddress($request->getClientIp());
            $auditLog->setUserAgent($request->headers->get('User-Agent'));
        }
        
        // Store change details
        if ($action === 'update' && !empty($changeSet)) {
            $oldValues = [];
            $newValues = [];
            $changedFields = [];
            
            foreach ($changeSet as $field => $changes) {
                // Skip sensitive fields
                if (in_array($field, $this->sensitiveFields)) {
                    continue;
                }
                
                [$oldValue, $newValue] = $changes;
                
                // Convert objects to strings
                $oldValues[$field] = $this->serializeValue($oldValue);
                $newValues[$field] = $this->serializeValue($newValue);
                $changedFields[] = $field;
            }
            
            if (!empty($changedFields)) {
                $auditLog->setOldValues($oldValues);
                $auditLog->setNewValues($newValues);
                $auditLog->setChangedFields($changedFields);
            }
        } elseif ($action === 'create') {
            // For new entities, store all values
            $newValues = $this->extractEntityValues($entity);
            $auditLog->setNewValues($newValues);
        } elseif ($action === 'delete') {
            // For deleted entities, store old values
            $oldValues = $this->extractEntityValues($entity);
            $auditLog->setOldValues($oldValues);
        }
        
        // Persist using a separate entity manager to avoid flushing issues
        $this->em->persist($auditLog);
        
        // Flush immediately if we're in a delete operation
        if ($action === 'delete') {
            $this->em->flush($auditLog);
        }
    }

    private function getShortClassName(object $entity): string
    {
        $reflection = new \ReflectionClass($entity);
        return $reflection->getShortName();
    }

    private function serializeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        
        if (is_object($value)) {
            // For entities, store just the ID
            if (method_exists($value, 'getId')) {
                return sprintf('%s#%d', $this->getShortClassName($value), $value->getId());
            }
            return get_class($value);
        }
        
        if (is_array($value)) {
            return json_encode($value);
        }
        
        return $value;
    }

    private function extractEntityValues(object $entity): array
    {
        $metadata = $this->em->getClassMetadata(get_class($entity));
        $values = [];
        
        foreach ($metadata->getFieldNames() as $fieldName) {
            // Skip sensitive fields
            if (in_array($fieldName, $this->sensitiveFields)) {
                continue;
            }
            
            $value = $metadata->getFieldValue($entity, $fieldName);
            $values[$fieldName] = $this->serializeValue($value);
        }
        
        return $values;
    }
}
