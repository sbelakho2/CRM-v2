<?php

namespace App\Controller;

use App\Entity\AbmAccount;
use App\Entity\AbmHit;
use App\Entity\Playbook;
use App\Entity\PlaybookRun;
use App\Service\AbmResolverService;
use App\Service\PlaybookEngine;
use App\Service\EngagementHeatMapService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AbmDashboardController
 * 
 * Account-based marketing dashboard for visitor intelligence.
 * 
 * Features:
 * - Real-time visitor activity feed
 * - Company identification from IP addresses
 * - Intent signals (page views, downloads, form fills)
 * - Engagement scoring per account
 * - Playbook management (trigger rules + actions)
 * - Automated lead routing and outreach
 * 
 * Routes:
 * - GET  /abm-dashboard                 - Main dashboard
 * - GET  /abm-dashboard/accounts        - Account list
 * - GET  /abm-dashboard/account/{id}    - Account detail
 * - GET  /abm-dashboard/playbooks       - Playbook management
 * - POST /abm-dashboard/playbook/create - Create playbook
 * - POST /abm-dashboard/playbook/{id}/toggle - Enable/disable playbook
 */
#[Route('/abm-dashboard')]
#[IsGranted('ROLE_USER')]
class AbmDashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AbmResolverService $abmResolver,
        private PlaybookEngine $playbookEngine,
        private EngagementHeatMapService $heatMapService,
        private LoggerInterface $logger
    ) {}

    /**
     * Main ABM dashboard with recent activity
     */
    #[Route('', name: 'abm_dashboard_index', methods: ['GET'])]
    public function index(): Response
    {
        // Get recent ABM hits (last 24 hours)
        $recentHits = $this->entityManager->getRepository(AbmHit::class)
            ->createQueryBuilder('h')
            ->where('h.timestamp >= :since')
            ->setParameter('since', new \DateTime('-24 hours'))
            ->orderBy('h.timestamp', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
        
        // Get top engaged accounts (last 7 days)
        $topAccountsQuery = $this->entityManager->getRepository(AbmAccount::class)
            ->createQueryBuilder('a')
            ->leftJoin('a.abmHits', 'h')
            ->where('h.timestamp >= :since')
            ->setParameter('since', new \DateTime('-7 days'))
            ->groupBy('a.id')
            ->orderBy('a.engagementScore', 'DESC')
            ->setMaxResults(10)
            ->getQuery();
        
        try {
            $topAccounts = $topAccountsQuery->getResult();
        } catch (\Exception $e) {
            $this->logger->error('ABM dashboard: failed to load top engaged accounts', [
                'exception' => $e->getMessage(),
            ]);
            $topAccounts = [];
        }
        
        // Get active playbooks
        $activePlaybooks = $this->entityManager->getRepository(Playbook::class)
            ->findBy(['isActive' => true], ['priority' => 'DESC']);
        
        // Calculate engagement metrics
        $newAccounts24h = 0;
        try {
            $newAccounts24h = $this->entityManager->getRepository(AbmAccount::class)
                ->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.createdAt >= :since')
                ->setParameter('since', new \DateTime('-24 hours'))
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Exception $e) {
            // If error, continue with 0
            $this->logger->error('ABM dashboard: failed to count new accounts in 24h', [
                'exception' => $e->getMessage(),
            ]);
            $newAccounts24h = 0;
        }
        
        $metrics = [
            'totalVisitors24h' => count($recentHits),
            'newAccounts24h' => $newAccounts24h,
            'activePlaybooks' => count($activePlaybooks),
            'totalAccounts' => $this->entityManager->getRepository(AbmAccount::class)->count([])
        ];
        
        // Get engagement heat map data with error handling
        $heatMapCompanies = [];
        $heatMapError = null;
        
        try {
            $heatMapCompanies = $this->heatMapService->getTopCompaniesByEngagement(20);
        } catch (\Exception $e) {
            $this->logger->error('ABM dashboard: failed to load engagement heat map', [
                'exception' => $e->getMessage(),
            ]);
            $heatMapError = 'Operation failed. Please try again.';
        }
        
        return $this->render('abm_dashboard/index.html.twig', [
            'pageTitle' => 'abm_dashboard.title',
            'recentHits' => $recentHits,
            'topAccounts' => $topAccounts,
            'metrics' => $metrics,
            'activePlaybooks' => $activePlaybooks,
            'heatMapCompanies' => $heatMapCompanies,
            'heatMapError' => $heatMapError
        ]);
    }

    /**
     * Account list with filters
     */
    #[Route('/accounts', name: 'abm_dashboard_accounts', methods: ['GET'])]
    public function accounts(Request $request): Response
    {
        // Get ABM accounts from the database
        $accounts = $this->entityManager->getRepository(AbmAccount::class)
            ->createQueryBuilder('a')
            ->orderBy('a.lastActivityAt', 'DESC')
            ->addOrderBy('a.engagementScore', 'DESC')
            ->getQuery()
            ->getResult();
        
        // Transform ABM accounts for the template
        $accountData = [];
        foreach ($accounts as $account) {
            $accountData[] = [
                'id' => $account->getId(),
                'name' => $account->getAccountName(),
                'industry' => $account->getMetadata()['industry'] ?? 'N/A',
                'location' => $account->getMetadata()['country'] ?? 'N/A',
                'company_size' => $account->getMetadata()['company_size'] ?? 'N/A',
                'revenue' => $account->getMetadata()['revenue'] ?? 'N/A',
                'status' => $account->getIcpTier() ? 'active' : 'prospect',
                'engagement_score' => $account->getEngagementScore() ?? 0,
            ];
        }

        $q = trim((string) $request->query->get('q', ''));
        $region = trim((string) $request->query->get('region', ''));
        $status = trim((string) $request->query->get('status', ''));

        if ('' !== $q || '' !== $region || '' !== $status) {
            $accountData = array_values(array_filter($accountData, static function (array $account) use ($q, $region, $status): bool {
                if ('' !== $region && ($account['location'] ?? '') !== $region) {
                    return false;
                }
                if ('' !== $status && ($account['status'] ?? '') !== $status) {
                    return false;
                }
                if ('' !== $q) {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        (string) ($account['name'] ?? ''),
                        (string) ($account['industry'] ?? ''),
                        (string) ($account['location'] ?? ''),
                    ])));
                    if (!str_contains($haystack, mb_strtolower($q))) {
                        return false;
                    }
                }

                return true;
            }));
        }
        
        return $this->render('abm_dashboard/accounts.html.twig', [
            'pageTitle' => 'abm_dashboard.accounts',
            'accounts' => $accountData,
            'q' => $q,
            'region' => $region,
            'status' => $status,
        ]);
    }

    /**
     * Create new ABM account
     */
    #[Route('/account/new', name: 'abm_dashboard_account_new', methods: ['GET', 'POST'])]
    public function newAccount(Request $request): Response
    {
        $account = new AbmAccount();
        
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('abm_account_new', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $account->setAccountName($request->request->get('account_name'));
            $account->setDomain($request->request->get('domain'));
            $account->setIcpTier($request->request->get('icp_tier'));
            
            // Store additional data in metadata
            $metadata = [
                'industry' => $request->request->get('industry'),
                'country' => $request->request->get('country'),
                'company_size' => $request->request->get('company_size'),
                'revenue' => $request->request->get('revenue'),
            ];
            $account->setMetadata($metadata);
            
            $this->entityManager->persist($account);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'abm_dashboard.flash.account_created');
            return $this->redirectToRoute('abm_dashboard_accounts');
        }
        
        return $this->render('abm_dashboard/account_form.html.twig', [
            'pageTitle' => 'abm_dashboard.new_account',
            'account' => null
        ]);
    }

    /**
     * Edit ABM account
     */
    #[Route('/account/{id}/edit', name: 'abm_dashboard_account_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editAccount(string $id, Request $request): Response
    {
        $account = $this->entityManager->getRepository(AbmAccount::class)->find((int)$id);
        
        if (!$account) {
            throw $this->createNotFoundException('ABM account not found');
        }
        
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('abm_account_edit', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $account->setAccountName($request->request->get('account_name'));
            $account->setDomain($request->request->get('domain'));
            $account->setIcpTier($request->request->get('icp_tier'));
            
            // Update metadata
            $metadata = [
                'industry' => $request->request->get('industry'),
                'country' => $request->request->get('country'),
                'company_size' => $request->request->get('company_size'),
                'revenue' => $request->request->get('revenue'),
            ];
            $account->setMetadata($metadata);
            $account->setUpdatedAt(new \DateTime());
            
            $this->entityManager->flush();
            
            $this->addFlash('success', 'abm_dashboard.flash.account_updated');
            return $this->redirectToRoute('abm_dashboard_accounts');
        }
        
        return $this->render('abm_dashboard/account_form.html.twig', [
            'pageTitle' => 'abm_dashboard.edit_account',
            'account' => $account
        ]);
    }

    /**
     * Account detail with activity timeline
     */
    #[Route('/account/{id}', name: 'abm_dashboard_account_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function accountDetail(string $id): Response
    {
        $abmAccount = $this->entityManager->getRepository(AbmAccount::class)->find((int)$id);
        
        if (!$abmAccount) {
            throw $this->createNotFoundException('ABM account not found');
        }
        
        // Get email statistics for this account (if company is linked)
        $emailsSent = 0;
        $emailOpens = 0;
        
        if ($abmAccount->getCompany()) {
            try {
                $emailStats = $this->entityManager->getRepository(\App\Entity\EmailSend::class)
                    ->createQueryBuilder('es')
                    ->select('COUNT(es.id) as total_sent', 'SUM(CASE WHEN es.opened = 1 THEN 1 ELSE 0 END) as total_opens')
                    ->join('es.contact', 'c')
                    ->join('c.company', 'comp')
                    ->where('comp.id = :companyId')
                    ->setParameter('companyId', $abmAccount->getCompany()->getId())
                    ->getQuery()
                    ->getOneOrNullResult();
                    
                $emailsSent = $emailStats['total_sent'] ?? 0;
                $emailOpens = $emailStats['total_opens'] ?? 0;
            } catch (\Exception $e) {
                // If query fails, just use zeros
                $this->logger->error('ABM dashboard: failed to load email stats for account', [
                    'account_id' => $abmAccount->getId(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }
        
        // Transform ABM account data for the template
        $account = [
            'id' => $abmAccount->getId(),
            'name' => $abmAccount->getAccountName(),
            'industry' => $abmAccount->getMetadata()['industry'] ?? 'N/A',
            'location' => $abmAccount->getMetadata()['country'] ?? 'N/A',
            'company_size' => $abmAccount->getMetadata()['company_size'] ?? 'N/A',
            'revenue' => $abmAccount->getMetadata()['revenue'] ?? 'N/A',
            'status' => $abmAccount->getIcpTier() ? 'active' : 'prospect',
            'engagement_score' => $abmAccount->getEngagementScore() ?? 0,
            'page_views' => $abmAccount->getTotalPageViews() ?? 0,
            'created_at' => $abmAccount->getCreatedAt(),
            'emails_sent' => $emailsSent,
            'email_opens' => $emailOpens,
        ];
        
        return $this->render('abm_dashboard/account_detail.html.twig', [
            'pageTitle' => 'abm_dashboard.account_detail',
            'account' => $account,
            'accountId' => $id
        ]);
    }

    /**
     * Delete ABM account
     */
    #[Route('/account/{id}/delete', name: 'abm_dashboard_account_delete', methods: ['POST'])]
    public function deleteAccount(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('abm_account_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $account = $this->entityManager->getRepository(AbmAccount::class)->find($id);

        if (!$account) {
            throw $this->createNotFoundException('ABM account not found');
        }

        $this->entityManager->remove($account);
        $this->entityManager->flush();

        $this->addFlash('success', 'abm_dashboard.flash.account_deleted');

        return $this->redirectToRoute('abm_dashboard_accounts');
    }

    /**
     * Playbook management page
     */
    #[Route('/playbooks', name: 'abm_dashboard_playbooks', methods: ['GET'])]
    public function playbooks(): Response
    {
        // Get all playbooks
        $playbooks = $this->entityManager->getRepository(Playbook::class)
            ->findBy([], ['priority' => 'DESC']);

        $runRepository = $this->entityManager->getRepository(PlaybookRun::class);

        // Get execution statistics for each playbook
        $stats = [];
        $activePlaybooks = 0;
        $totalRuns = 0;
        $successfulRuns = 0;

        foreach ($playbooks as $playbook) {
            $playbookRuns = $runRepository->count(['playbook' => $playbook]);
            $playbookSuccesses = $runRepository->count(['playbook' => $playbook, 'status' => PlaybookRun::STATUS_COMPLETED]);

            $totalRuns += $playbookRuns;
            $successfulRuns += $playbookSuccesses;

            if ($playbook->isActive()) {
                ++$activePlaybooks;
            }

            $stats[$playbook->getId()] = [
                'totalRuns' => $playbookRuns,
                'successfulRuns' => $playbookSuccesses,
                'lastRun' => $runRepository->findOneBy(['playbook' => $playbook], ['triggeredAt' => 'DESC']),
            ];
        }

        $totalAccounts = $this->entityManager->getRepository(AbmAccount::class)->count([]);

        return $this->render('abm_dashboard/playbooks.html.twig', [
            'pageTitle' => 'abm_dashboard.playbooks',
            'playbooks' => $playbooks,
            'stats' => $stats,
            'overview' => [
                'total_accounts' => $totalAccounts,
                'active_playbooks' => $activePlaybooks,
                'success_rate' => $totalRuns > 0 ? (int) round($successfulRuns / $totalRuns * 100) : null,
                'total_runs' => $totalRuns,
            ],
        ]);
    }

    /**
     * Create new playbook
     */
    #[Route('/playbook/create', name: 'abm_dashboard_playbook_create', methods: ['POST'])]
    public function createPlaybook(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('abm_playbook_create', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $name = $request->request->get('name');
        $description = $request->request->get('description');
        $triggerRulesJson = $request->request->get('trigger_rules');
        $actionsJson = $request->request->get('actions');
        $priority = (int) $request->request->get('priority', 100);
        
        // Validate data
        if (!$name) {
            $this->addFlash('error', 'abm_dashboard.flash.error.playbook_name_required');
            return $this->redirectToRoute('abm_dashboard_playbooks');
        }
        
        // Create playbook
        $playbook = new Playbook();
        $playbook->setName($name);
        $playbook->setDescription($description);
        $playbook->setTriggerRules($triggerRulesJson);
        $playbook->setActions($actionsJson);
        $playbook->setPriority($priority);
        $playbook->setIsActive(true);
        $playbook->setCooldownHours(24);
        $playbook->setCreatedAt(new \DateTime());
        
        $this->entityManager->persist($playbook);
        $this->entityManager->flush();
        
        $this->addFlash('success', 'abm_dashboard.flash.playbook_created');
        return $this->redirectToRoute('abm_dashboard_playbooks');
    }

    /**
     * Toggle playbook active status
     */
    #[Route('/playbook/{id}/toggle', name: 'abm_dashboard_playbook_toggle', methods: ['POST'])]
    public function togglePlaybook(Request $request, string $id): JsonResponse
    {
        if (!$this->isCsrfTokenValid('abm_playbook_toggle_' . $id, $request->request->get('_csrf_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        $playbook = $this->entityManager->getRepository(Playbook::class)->find((int)$id);
        
        if (!$playbook) {
            return new JsonResponse(['error' => 'Playbook not found'], 404);
        }
        
        // Toggle active status
        $playbook->setIsActive(!$playbook->isActive());
        $this->entityManager->flush();
        
        return new JsonResponse([
            'success' => true,
            'active' => $playbook->isActive()
        ]);
    }
}
