<?php

namespace App\Controller;

use App\Service\CommandCenterService;
use App\Service\LeadSalesAnalystService;
use App\Service\InteractiveLiveQuoteService;
use App\Service\CountryService;
use App\Entity\Lead;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Command Center Controller
 * 
 * Unified dashboard for Starz CRM providing:
 * - Live Lead Inflow (LeadBot discoveries)
 * - Quote Status (Quote Buddy pipeline)
 * - Supply Chain Alerts
 * - Key Performance Metrics
 * - Action Items
 * 
 * Routes:
 * - GET  /command-center              - Main dashboard view
 * - GET  /command-center/data         - Full data (JSON)
 * - GET  /command-center/leads        - Lead inflow data
 * - GET  /command-center/quotes       - Quote status data
 * - GET  /command-center/alerts       - Supply alerts
 * - GET  /command-center/actions      - Action items
 * - GET  /command-center/lead/{id}/analysis - Sales analyst for lead
 */
#[Route('/command-center')]
#[IsGranted('ROLE_USER')]
class CommandCenterController extends AbstractController
{
    public function __construct(
        private CommandCenterService $commandCenter,
        private LeadSalesAnalystService $salesAnalyst,
        private InteractiveLiveQuoteService $liveQuoteService,
        private EntityManagerInterface $entityManager,
        private CountryService $countryService
    ) {}

    /**
     * Main Command Center dashboard
     */
    #[Route('', name: 'command_center_index', methods: ['GET'])]
    public function index(): Response
    {
        $data = $this->commandCenter->getCommandCenterData();
        $regionLabels = $this->countryService->getRegionOptions();
        foreach (array_keys($data['lead_inflow']['by_region'] ?? []) as $region) {
            if ($region && !isset($regionLabels[$region])) {
                $regionLabels[$region] = strtoupper((string) $region);
            }
        }
        
        return $this->render('command_center/index.html.twig', [
            'data' => $data,
            'region_labels' => $regionLabels,
        ]);
    }
    
    /**
     * Get full command center data (AJAX/API)
     */
    #[Route('/data', name: 'command_center_data', methods: ['GET'])]
    public function getData(): JsonResponse
    {
        $data = $this->commandCenter->getCommandCenterData();
        
        return new JsonResponse($data);
    }
    
    /**
     * Get lead inflow data
     */
    #[Route('/leads', name: 'command_center_leads', methods: ['GET'])]
    public function getLeadsData(): JsonResponse
    {
        $data = $this->commandCenter->getLeadInflowData();
        
        return new JsonResponse($data);
    }
    
    /**
     * Get quote status data
     */
    #[Route('/quotes', name: 'command_center_quotes', methods: ['GET'])]
    public function getQuotesData(): JsonResponse
    {
        $data = $this->commandCenter->getQuotesStatusData();
        
        return new JsonResponse($data);
    }
    
    /**
     * Get supply alerts
     */
    #[Route('/alerts', name: 'command_center_alerts', methods: ['GET'])]
    public function getAlerts(): JsonResponse
    {
        $data = $this->commandCenter->getSupplyAlertsData();
        
        return new JsonResponse($data);
    }
    
    /**
     * Get action items
     */
    #[Route('/actions', name: 'command_center_actions', methods: ['GET'])]
    public function getActions(): JsonResponse
    {
        $items = $this->commandCenter->getActionItems();
        
        return new JsonResponse([
            'action_items' => $items,
            'count' => count($items),
        ]);
    }
    
    /**
     * Get key metrics
     */
    #[Route('/metrics', name: 'command_center_metrics', methods: ['GET'])]
    public function getMetrics(): JsonResponse
    {
        $metrics = $this->commandCenter->getKeyMetrics();
        
        return new JsonResponse($metrics);
    }
    
    /**
     * Get sales analyst data for a specific lead
     */
    #[Route('/lead/{id}/analysis', name: 'command_center_lead_analysis', methods: ['GET'])]
    public function getLeadAnalysis(int $id): JsonResponse
    {
        $lead = $this->entityManager->getRepository(Lead::class)->find($id);
        
        if (!$lead) {
            return new JsonResponse([
                'error' => 'Lead not found',
            ], Response::HTTP_NOT_FOUND);
        }
        
        $analysis = $this->salesAnalyst->analyzeLead($lead);
        
        return new JsonResponse($analysis);
    }
    
    /**
     * Bulk analyze leads
     */
    #[Route('/leads/analyze', name: 'command_center_leads_analyze', methods: ['GET'])]
    public function analyzeLeads(): JsonResponse
    {
        // Get pending leads with high scores
        $leads = $this->entityManager->getRepository(Lead::class)
            ->createQueryBuilder('l')
            ->where('l.reviewStatus = :status')
            ->andWhere('l.leadScore >= :minScore')
            ->setParameter('status', 'pending')
            ->setParameter('minScore', 50)
            ->orderBy('l.leadScore', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        
        $analyses = $this->salesAnalyst->analyzeMultipleLeads($leads);
        
        return new JsonResponse([
            'count' => count($analyses),
            'analyses' => $analyses,
        ]);
    }
    
    /**
     * Get interactive quote statistics
     */
    #[Route('/interactive-quotes', name: 'command_center_interactive_quotes', methods: ['GET'])]
    public function getInteractiveQuoteStats(): JsonResponse
    {
        $stats = $this->liveQuoteService->getInteractiveQuoteStats();
        
        return new JsonResponse($stats);
    }
    
    /**
     * Get alert count for notification badge
     */
    #[Route('/alert-count', name: 'command_center_alert_count', methods: ['GET'])]
    public function getAlertCount(): JsonResponse
    {
        $count = $this->commandCenter->getAlertCount();
        
        return new JsonResponse([
            'count' => $count,
        ]);
    }
}
