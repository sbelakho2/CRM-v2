<?php

namespace App\Controller;

use App\Service\KPITrackingService;
use App\Service\GuidanceNotificationService;
use App\Repository\CompanyRepository;
use App\Repository\RFQRepository;
use App\Repository\ActivityRepository;
use App\Repository\WebinarRepository;
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
        private GuidanceNotificationService $guidanceService
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
}
