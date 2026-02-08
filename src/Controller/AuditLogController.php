<?php

namespace App\Controller;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/audit')]
#[IsGranted('ROLE_ADMIN')]
class AuditLogController extends AbstractController
{
    public function __construct(
        private AuditLogRepository $auditLogRepository
    ) {}

    #[Route('', name: 'app_audit_log_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $entityType = $request->query->get('entity_type');
        $entityId = $request->query->get('entity_id');
        $action = $request->query->get('action');
        $userId = $request->query->get('user_id');
        
        $qb = $this->auditLogRepository->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u');
        
        if ($entityType && $entityId) {
            $qb->andWhere('a.entityType = :entityType')
               ->andWhere('a.entityId = :entityId')
               ->setParameter('entityType', $entityType)
               ->setParameter('entityId', $entityId);
        } elseif ($entityType) {
            $qb->andWhere('a.entityType = :entityType')
               ->setParameter('entityType', $entityType);
        }
        
        if ($action) {
            $qb->andWhere('a.action = :action')
               ->setParameter('action', $action);
        }
        
        if ($userId) {
            $qb->andWhere('a.user = :userId')
               ->setParameter('userId', $userId);
        }
        
        $qb->orderBy('a.createdAt', 'DESC')
           ->setMaxResults(100);
        
        $auditLogs = $qb->getQuery()->getResult();
        
        // Get filter options
        $entityTypes = ['Company', 'Contact', 'Lead', 'Quote', 'RFQ', 'Activity', 'EmailCampaign', 'User'];
        $actions = ['create', 'update', 'delete'];
        
        return $this->render('audit_log/index.html.twig', [
            'audit_logs' => $auditLogs,
            'entity_types' => $entityTypes,
            'actions' => $actions,
            'current_entity_type' => $entityType,
            'current_entity_id' => $entityId,
            'current_action' => $action,
            'current_user_id' => $userId,
        ]);
    }

    #[Route('/{id}', name: 'app_audit_log_show', methods: ['GET'])]
    public function show(int $id): Response
    {
        $auditLog = $this->auditLogRepository->find($id);
        
        if (!$auditLog) {
            throw $this->createNotFoundException('Audit log not found');
        }
        
        return $this->render('audit_log/show.html.twig', [
            'audit_log' => $auditLog,
        ]);
    }

    #[Route('/entity/{entityType}/{entityId}', name: 'app_audit_log_entity', methods: ['GET'])]
    public function entity(string $entityType, int $entityId): Response
    {
        $auditLogs = $this->auditLogRepository->findByEntity($entityType, $entityId);
        
        return $this->render('audit_log/entity.html.twig', [
            'audit_logs' => $auditLogs,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }
}
