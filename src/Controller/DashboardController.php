<?php

namespace App\Controller;

use App\Service\KPITrackingService;
use App\Service\NotificationService;
use App\Repository\CompanyRepository;
use App\Repository\RFQRepository;
use App\Repository\ActivityRepository;
use App\Repository\WebinarRepository;
use App\Repository\NotificationRepository;
use App\Entity\Notification;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private KPITrackingService $kpiService,
        private NotificationService $notificationService,
        private CompanyRepository $companyRepository,
        private RFQRepository $rfqRepository,
        private ActivityRepository $activityRepository,
        private WebinarRepository $webinarRepository,
        private NotificationRepository $notificationRepository
    ) {}

    #[Route('/', name: 'app_dashboard')]
    public function index(): Response
    {
        // Get 90-day KPIs
        $kpis = $this->kpiService->get90DayKPIs();
        
        // Get sector breakdown
        $sectorBreakdown = $this->kpiService->getSectorBreakdown();
        
        // Get pipeline distribution
        $pipelineDistribution = $this->kpiService->getPipelineStageDistribution();
        
        // Get weekly metrics
        $weeklyMetrics = $this->kpiService->getWeeklyMetrics();
        
        // Get recent activities
        $recentActivities = $this->activityRepository->findRecent(8);
        
        // Calculate progress percentages for KPIs
        $pipelineProgress = $kpis['target_pipeline'] > 0 
            ? min(100, ($kpis['pipeline_value'] / $kpis['target_pipeline']) * 100) 
            : 0;
        
        $rfqProgress = $kpis['target_rfqs'] > 0 
            ? min(100, ($kpis['rfq_count'] / $kpis['target_rfqs']) * 100) 
            : 0;
        
        $npiProgress = $kpis['target_npis'] > 0 
            ? min(100, ($kpis['npi_awards'] / $kpis['target_npis']) * 100) 
            : 0;
        
        $frameworkProgress = $kpis['target_frameworks'] > 0 
            ? min(100, ($kpis['framework_agreements'] / $kpis['target_frameworks']) * 100) 
            : 0;

        // Prepare chart data for Chart.js
        $sectorLabels = array_keys($sectorBreakdown);
        $sectorCompanyCounts = array_column($sectorBreakdown, 'company_count');
        $sectorRfqCounts = array_column($sectorBreakdown, 'active_rfqs');
        
        $pipelineLabels = array_keys($pipelineDistribution);
        $pipelineData = array_values($pipelineDistribution);

        return $this->render('dashboard/index.html.twig', [
            'kpis' => $kpis,
            'pipeline_progress' => round($pipelineProgress, 1),
            'rfq_progress' => round($rfqProgress, 1),
            'npi_progress' => round($npiProgress, 1),
            'framework_progress' => round($frameworkProgress, 1),
            'sector_breakdown' => $sectorBreakdown,
            'pipeline_distribution' => $pipelineDistribution,
            'weekly_metrics' => $weeklyMetrics,
            'recent_activities' => $recentActivities,
            'sector_labels' => json_encode($sectorLabels),
            'sector_company_counts' => json_encode($sectorCompanyCounts),
            'sector_rfq_counts' => json_encode($sectorRfqCounts),
            'pipeline_labels' => json_encode($pipelineLabels),
            'pipeline_data' => json_encode($pipelineData),
        ]);
    }

    /**
     * Get unread notifications for current user
     * 
     * @return JsonResponse Array of unread notifications with metadata
     */
    #[Route('/api/notifications', name: 'api_notifications', methods: ['GET'])]
    public function getNotifications(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        // Get unread count
        $unreadCount = $this->notificationRepository->countUnreadForUser($user);

        // Get recent unread notifications (limit 5)
        $notifications = $this->notificationRepository->findUnreadForUser($user, 5);

        // Format notifications for frontend
        $formatted = [];
        foreach ($notifications as $notification) {
            $formatted[] = [
                'id' => $notification->getId(),
                'type' => $notification->getType(),
                'message' => $notification->getMessage(),
                'icon' => $notification->getIcon(),
                'label' => $notification->getTypeLabel(),
                'entityType' => $notification->getEntityType(),
                'entityId' => $notification->getEntityId(),
                'data' => $notification->getData(),
                'readAt' => $notification->getReadAt()?->format('c'),
                'createdAt' => $notification->getCreatedAt()->format('c'),
            ];
        }

        return $this->json([
            'unread_count' => $unreadCount,
            'notifications' => $formatted,
        ]);
    }

    /**
     * Mark notification as read
     * 
     * @param Notification $notification The notification to mark as read
     * @return JsonResponse Success response
     */
    #[Route('/api/notifications/{id}/read', name: 'api_notification_read', methods: ['PUT'])]
    public function markNotificationAsRead(Notification $notification): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        // Verify notification belongs to current user
        if ($notification->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $this->notificationService->markAsRead($notification);

        return $this->json([
            'success' => true,
            'notification_id' => $notification->getId(),
            'read_at' => $notification->getReadAt()?->format('c'),
        ]);
    }

    /**
     * Get notification count (for badge display)
     * 
     * @return JsonResponse Unread count
     */
    #[Route('/api/notifications/count', name: 'api_notifications_count', methods: ['GET'])]
    public function getNotificationCount(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $unreadCount = $this->notificationRepository->countUnreadForUser($user);

        return $this->json([
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Mark all notifications as read
     * 
     * @return JsonResponse Success response
     */
    #[Route('/api/notifications/mark-all-read', name: 'api_notifications_mark_all_read', methods: ['PUT'])]
    public function markAllAsRead(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        // Get all unread notifications
        $notifications = $this->notificationRepository->findUnreadForUser($user, 999);

        $count = 0;
        foreach ($notifications as $notification) {
            $this->notificationService->markAsRead($notification);
            $count++;
        }

        return $this->json([
            'success' => true,
            'marked_as_read' => $count,
        ]);
    }
}

