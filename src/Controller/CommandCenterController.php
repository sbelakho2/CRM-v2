<?php

namespace App\Controller;

use App\Service\CommandCenterService;
use App\Service\LeadSalesAnalystService;
use App\Service\InteractiveLiveQuoteService;
use App\Service\CountryService;
use App\Entity\Activity;
use App\Entity\EmailSend;
use App\Entity\Lead;
use App\Entity\Notification;
use App\Entity\Quote;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Command Center Controller
 * 
 * Unified dashboard for StarzCRM providing:
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
        private CountryService $countryService,
        private \App\Service\CurrencyConverter $currencyConverter
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

        $em = $this->entityManager;

        // ── Enhanced pipeline data ──

        // Leads by nurturing stage for pipeline visualization
        $leadsByStage = $em->getRepository(Lead::class)->createQueryBuilder('l')
            ->select('l.nurturingStage AS stage, COUNT(l.id) AS count')
            ->groupBy('l.nurturingStage')
            ->getQuery()->getResult();

        // Total leads count
        $totalLeads = (int) $em->getRepository(Lead::class)->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->getQuery()->getSingleScalarResult();

        // Open quotes and their total value
        $openQuotes = $em->getRepository(Quote::class)->createQueryBuilder('q')
            ->select('q.id, q.quoteNumber, q.totalCost, q.currency, q.status, q.createdAt')
            ->where('q.status IN (:statuses)')
            ->andWhere('q.archivedAt IS NULL')
            ->setParameter('statuses', ['draft', 'pending_review', 'sent'])
            ->orderBy('q.totalCost', 'DESC')
            ->setMaxResults(20)
            ->getQuery()->getResult();

        // Sum in a single display currency: quotes are captured in mixed
        // currencies (USD/EUR/MAD/...), so a raw SUM(totalCost) would add
        // apples and oranges into a meaningless number. Each row converts
        // through the same CurrencyConverter used by CommandCenterService.
        $displayCurrency = $this->currencyConverter->getDisplayCurrency();
        $openQuotesValue = 0.0;
        foreach ($openQuotes as $q) {
            $amount = (float) ($q['totalCost'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $openQuotesValue += $this->currencyConverter->convert(
                $amount,
                $q['currency'] ?: $displayCurrency,
                $displayCurrency
            );
        }

        // Recent activities counts
        $now = new \DateTime();
        $last24h = (new \DateTime())->modify('-24 hours');
        $last7d = (new \DateTime())->modify('-7 days');

        $activitiesLast24h = (int) $em->getRepository(Activity::class)->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt >= :since')
            ->setParameter('since', $last24h)
            ->getQuery()->getSingleScalarResult();

        $activitiesLast7d = (int) $em->getRepository(Activity::class)->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt >= :since')
            ->setParameter('since', $last7d)
            ->getQuery()->getSingleScalarResult();

        // Pending tasks
        $pendingTasks = (int) $em->getRepository(Task::class)->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.status NOT IN (:doneStatuses)')
            ->setParameter('doneStatuses', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->getQuery()->getSingleScalarResult();

        // Unread notifications for current user
        $currentUser = $this->getUser();
        $unreadNotifications = 0;
        if ($currentUser) {
            $unreadNotifications = (int) $em->getRepository(Notification::class)->createQueryBuilder('n')
                ->select('COUNT(n.id)')
                ->where('n.user = :user')
                ->andWhere('n.readAt IS NULL')
                ->setParameter('user', $currentUser)
                ->getQuery()->getSingleScalarResult();
        }

        // Emails sent today
        $emailsToday = (int) $em->getRepository(EmailSend::class)->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.sentAt >= :today')
            ->andWhere('e.status = :status')
            ->setParameter('today', new \DateTime('today'))
            ->setParameter('status', EmailSend::STATUS_SENT)
            ->getQuery()->getSingleScalarResult();

        // Open/click/reply rates for sent emails (last 30 days)
        $emailEngagement = $em->getRepository(EmailSend::class)->createQueryBuilder('e')
            ->select(
                'COUNT(e.id) AS total',
                'SUM(CASE WHEN e.opened = true THEN 1 ELSE 0 END) AS opened',
                'SUM(CASE WHEN e.clicked = true THEN 1 ELSE 0 END) AS clicked',
                'SUM(CASE WHEN e.replied = true THEN 1 ELSE 0 END) AS replied'
            )
            ->where('e.sentAt >= :month')
            ->setParameter('month', new \DateTime('-30 days'))
            ->andWhere('e.status = :status')
            ->setParameter('status', EmailSend::STATUS_SENT)
            ->getQuery()->getSingleResult();

        $totalSent = (int) ($emailEngagement['total'] ?? 0);
        $emailOpenRate = $totalSent > 0 ? round(((int)($emailEngagement['opened'] ?? 0) / $totalSent) * 100, 1) : 0;
        $emailClickRate = $totalSent > 0 ? round(((int)($emailEngagement['clicked'] ?? 0) / $totalSent) * 100, 1) : 0;
        $emailReplyRate = $totalSent > 0 ? round(((int)($emailEngagement['replied'] ?? 0) / $totalSent) * 100, 1) : 0;

        // Alerts count (from service)
        $alertCount = $this->commandCenter->getAlertCount();
        
        return $this->render('command_center/index.html.twig', [
            'data' => $data,
            'region_labels' => $regionLabels,
            // ── Enhanced pipeline data ──
            'leads_by_stage'       => $leadsByStage,
            'total_leads'          => $totalLeads,
            'open_quotes'          => $openQuotes,
            'open_quotes_value'    => round($openQuotesValue, 2),
            'activities_24h'       => $activitiesLast24h,
            'activities_7d'        => $activitiesLast7d,
            'pending_tasks'        => $pendingTasks,
            'unread_notifications' => $unreadNotifications,
            'emails_today'         => $emailsToday,
            'email_open_rate'      => $emailOpenRate,
            'email_click_rate'     => $emailClickRate,
            'email_reply_rate'     => $emailReplyRate,
            'alert_count'          => $alertCount,
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
