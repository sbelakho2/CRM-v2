<?php

namespace App\EventListener;

use App\Entity\AbmAccount;
use App\Entity\Activity;
use App\Entity\AuditLog;
use App\Entity\BomLine;
use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\EmailCampaign;
use App\Entity\Lead;
use App\Entity\Quote;
use App\Entity\RFQ;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditLogListener
{
    private array $entitiesToAudit = [
        Company::class,
        Contact::class,
        Lead::class,
        Quote::class,
        RFQ::class,
        Activity::class,
        EmailCampaign::class,
        User::class,
        AbmAccount::class,
        BomLine::class,
    ];

    private array $sensitiveFields = [
        'password',
        'plainPassword',
        'salt',
        'resetToken',
    ];

    private array $queuedAuditLogs = [];

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

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (empty($this->queuedAuditLogs)) {
            return;
        }

        foreach ($this->queuedAuditLogs as $auditLog) {
            $this->em->persist($auditLog);
        }
        $this->queuedAuditLogs = [];
        $this->em->flush();
    }

    private function shouldAudit(object $entity): bool
    {
        if ($entity instanceof AuditLog) {
            return false;
        }

        return in_array(get_class($entity), $this->entitiesToAudit);
    }

    private function createAuditLog(string $action, object $entity, array $changeSet = []): void
    {
        $auditLog = new AuditLog();
        $auditLog->setEntityType($this->getShortClassName($entity));

        $metadata = $this->em->getClassMetadata(get_class($entity));
        $identifierValues = $metadata->getIdentifierValues($entity);
        $entityId = json_encode($identifierValues);

        if ($entityId) {
            $auditLog->setEntityId($entityId);
        }

        $auditLog->setAction($action);

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $auditLog->setUser($user);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $auditLog->setIpAddress($request->getClientIp());
            $auditLog->setUserAgent($request->headers->get('User-Agent'));
        }

        if ($action === 'update' && !empty($changeSet)) {
            $oldValues = [];
            $newValues = [];
            $changedFields = [];

            foreach ($changeSet as $field => $changes) {
                if (in_array($field, $this->sensitiveFields)) {
                    continue;
                }

                [$oldValue, $newValue] = $changes;

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
            $newValues = $this->extractEntityValues($entity);
            $auditLog->setNewValues($newValues);
        } elseif ($action === 'delete') {
            $oldValues = $this->extractEntityValues($entity);
            $auditLog->setOldValues($oldValues);
        }

        $this->queuedAuditLogs[] = $auditLog;
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
            if (in_array($fieldName, $this->sensitiveFields)) {
                continue;
            }

            $value = $metadata->getFieldValue($entity, $fieldName);
            $values[$fieldName] = $this->serializeValue($value);
        }

        return $values;
    }
}
