<?php

namespace App\Controller;

use App\Service\KPITrackingService;
use App\Service\GuidanceNotificationService;
use App\Repository\CompanyRepository;
use App\Repository\RFQRepository;
use App\Repository\ActivityRepository;
use App\Repository\WebinarRepository;
use App\Repository\ComplianceDocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private KPITrackingService $kpiService,
        private CompanyRepository $companyRepository,
        private RFQRepository $rfqRepository,
        private ActivityRepository $activityRepository,
        private WebinarRepository $webinarRepository,
        private GuidanceNotificationService $guidanceService,
        private ComplianceDocumentRepository $complianceDocumentRepository
    ) {}

    #[Route('/', name: 'app_dashboard')]
    public function index(): Response
    {
        // Daily workflow reminders are shown on the guidance notifications page
        // Visit /guidance/all to see all notifications

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
        
        // Prepare chart data for Chart.js
        $sectorLabels = array_keys($sectorBreakdown);
        $sectorCompanyCounts = array_column($sectorBreakdown, 'company_count');
        $sectorRfqCounts = array_column($sectorBreakdown, 'active_rfqs');
        
        $pipelineLabels = array_keys($pipelineDistribution);
        $pipelineData = array_values($pipelineDistribution);
        
        // Get compliance alerts for dashboard widget
        $complianceAlerts = $this->complianceDocumentRepository->findDocumentsNeedingAttention(30, 10);
        $complianceAlertCounts = $this->complianceDocumentRepository->getAlertCounts();

        return $this->render('dashboard/index.html.twig', [
            'kpis' => $kpis,
            'sector_breakdown' => $sectorBreakdown,
            'pipeline_distribution' => $pipelineDistribution,
            'weekly_metrics' => $weeklyMetrics,
            'recent_activities' => $recentActivities,
            'sector_labels' => json_encode($sectorLabels),
            'sector_company_counts' => json_encode($sectorCompanyCounts),
            'sector_rfq_counts' => json_encode($sectorRfqCounts),
            'pipeline_labels' => json_encode($pipelineLabels),
            'pipeline_data' => json_encode($pipelineData),
            'compliance_alerts' => $complianceAlerts,
            'compliance_alert_counts' => $complianceAlertCounts,
        ]);
    }
}
