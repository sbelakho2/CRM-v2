<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\RFQ;
use App\Form\RFQType;
use App\Repository\RFQRepository;
use App\Repository\CompanyRepository;
use App\Service\GuidanceNotificationService;
use App\Service\SalesPipelineOrchestratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/rfq')]
#[IsGranted('ROLE_USER')]
class RFQController extends AbstractController
{
    public function __construct(
        private GuidanceNotificationService $guidanceService,
        private SalesPipelineOrchestratorService $pipelineOrchestrator,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_rfq_index', methods: ['GET'])]
    public function index(Request $request, RFQRepository $rfqRepository, CompanyRepository $companyRepository): Response
    {
        // Filters
        $type = $request->query->get('type');
        $status = $request->query->get('status');
        $company = $request->query->get('company');
        $ndaStatus = $request->query->get('nda_status');

        $qb = $rfqRepository->createQueryBuilder('r')
            ->andWhere('r.archivedAt IS NULL')
            ->leftJoin('r.company', 'c')
            ->addSelect('c')
            ->orderBy('r.createdAt', 'DESC');

        if ($type) {
            $qb->andWhere('r.type = :type')->setParameter('type', $type);
        }

        if ($status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        if ($company) {
            $qb->andWhere('r.company = :company')->setParameter('company', $company);
        }

        if ($ndaStatus === 'sent') {
            $qb->andWhere('r.ndaSent = true');
        } elseif ($ndaStatus === 'executed') {
            $qb->andWhere('r.ndaExecuted = true');
        } elseif ($ndaStatus === 'pending') {
            $qb->andWhere('r.ndaSent = false');
        }

        $qb->orderBy('r.createdAt', 'DESC');

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 50;
        $qb->setMaxResults($limit)
           ->setFirstResult(($page - 1) * $limit);

        $rfqs = $qb->getQuery()->getResult();

        $companies = $companyRepository->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('rfq/index.html.twig', [
            'rfqs' => $rfqs,
            'companies' => $companies,
        ]);
    }

    #[Route('/pipeline', name: 'app_rfq_pipeline', methods: ['GET'])]
    public function pipeline(Request $request, RFQRepository $rfqRepository, CompanyRepository $companyRepository): Response
    {
        // Get filters
        $type = $request->query->get('type');
        $companyId = $request->query->get('company');

        $qb = $rfqRepository->createQueryBuilder('r')
            ->andWhere('r.archivedAt IS NULL')
            ->leftJoin('r.company', 'c')
            ->addSelect('c')
            ->orderBy('r.createdAt', 'DESC');

        if ($type) {
            $qb->andWhere('r.type = :type')->setParameter('type', $type);
        }

        if ($companyId) {
            $qb->andWhere('r.company = :company')->setParameter('company', $companyId);
        }

        $allRfqs = $qb->getQuery()->getResult();

        // Group by status for kanban view
        $pipeline = [
            'Pending' => [],
            'In Review' => [],
            'Submitted' => [],
            'Won' => [],
            'Lost' => [],
        ];

        foreach ($allRfqs as $rfq) {
            $status = $rfq->getStatus();
            if (isset($pipeline[$status])) {
                $pipeline[$status][] = $rfq;
            }
        }

        return $this->render('rfq/pipeline.html.twig', [
            'pipeline' => $pipeline,
            'companies' => $companyRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_rfq_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $rfq = new RFQ();
        
        // Pre-fill company from query parameter
        $companyId = $request->query->get('company');
        if ($companyId) {
            $company = $entityManager->getRepository(Company::class)->find($companyId);
            if ($company) {
                $rfq->setCompany($company);
            }
        }

        $form = $this->createForm(RFQType::class, $rfq);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($rfq);
            $entityManager->flush();

            // Provide guidance for RFQ workflow
            $companyName = $rfq->getCompany() ? $rfq->getCompany()->getName() : 'Customer';
            $this->guidanceService->afterRFQCreated(
                $rfq->getId(),
                $companyName,
                $rfq->getSopDate() !== null
            );

            $this->addFlash('success', $this->translator->trans('rfq.flash.created'));

            return $this->redirectToRoute('app_rfq_show', ['id' => $rfq->getId()]);
        }

        return $this->render('rfq/new.html.twig', [
            'rfq' => $rfq,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_rfq_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(RFQ $rfq): Response
    {
        // Archived commercial records are only reachable by admins.
        if ($rfq->isArchived() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException('RFQ not found');
        }

        return $this->render('rfq/show.html.twig', [
            'rfq' => $rfq,
        ]);
    }

    /**
     * Archived RFQs must not be mutated through direct URLs: every state
     * change (edit, status, NDA tracking) funnels through this guard.
     */
    private function rejectArchived(RFQ $rfq): ?Response
    {
        if ($rfq->isArchived() && !$this->isGranted('ROLE_ADMIN')) {
            $this->addFlash('error', 'This RFQ is archived and cannot be modified.');

            return $this->redirectToRoute('app_rfq_index');
        }

        return null;
    }

    #[Route('/{id}/edit', name: 'app_rfq_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, RFQ $rfq, EntityManagerInterface $entityManager): Response
    {
        if ($redirect = $this->rejectArchived($rfq)) {
            return $redirect;
        }

        $form = $this->createForm(RFQType::class, $rfq);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', $this->translator->trans('rfq.flash.updated'));

            return $this->redirectToRoute('app_rfq_show', ['id' => $rfq->getId()]);
        }

        return $this->render('rfq/edit.html.twig', [
            'rfq' => $rfq,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_rfq_delete', methods: ['POST'])]
    public function delete(Request $request, RFQ $rfq, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$rfq->getId(), $request->request->get('_token'))) {
            // RFQs are commercial history (line items, versions, quotes):
            // archive instead of hard-deleting.
            $rfq->archive($this->getUser(), 'Archived from RFQ list');
            $entityManager->flush();

            $this->addFlash('success', $this->translator->trans('rfq.flash.deleted'));
        }

        return $this->redirectToRoute('app_rfq_index');
    }

    #[Route('/{id}/update-status', name: 'app_rfq_update_status', methods: ['POST'])]
    public function updateStatus(Request $request, RFQ $rfq, EntityManagerInterface $entityManager): Response
    {
        if ($redirect = $this->rejectArchived($rfq)) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('update_status' . $rfq->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.flash.invalid_csrf'));
            return $this->redirectToRoute('app_rfq_show', ['id' => $rfq->getId()]);
        }

        $newStatus = $request->request->get('status');
        
        if (in_array($newStatus, ['Pending', 'In Review', 'Submitted', 'Won', 'Lost'])) {
            $oldStatus = $rfq->getStatus();
            $rfq->setStatus($newStatus);
            $entityManager->flush();

            // Advance company pipeline stage & feed outcome to lead
            $this->pipelineOrchestrator->afterRfqStatusChanged($rfq, $oldStatus, $newStatus);
            $entityManager->flush();

            // Provide guidance based on new status
            $companyName = $rfq->getCompany() ? $rfq->getCompany()->getName() : 'Customer';
            $this->guidanceService->afterRFQStatusUpdated($rfq->getId(), $newStatus, $companyName);

            $this->addFlash('success', $this->translator->trans('rfq.flash.status_updated', ['%status%' => $newStatus]));
        }

        return $this->redirectToRoute('app_rfq_show', ['id' => $rfq->getId()]);
    }

    #[Route('/{id}/nda-sent', name: 'app_rfq_nda_sent', methods: ['POST'])]
    public function ndaSent(Request $request, RFQ $rfq, EntityManagerInterface $entityManager): Response
    {
        if ($redirect = $this->rejectArchived($rfq)) {
            return $redirect;
        }

        if ($this->isCsrfTokenValid('nda_sent'.$rfq->getId(), $request->request->get('_token'))) {
            $rfq->setNdaSent(true);
            $rfq->setNdaDate(new \DateTime());
            $entityManager->flush();

            $this->addFlash('success', $this->translator->trans('rfq.flash.nda_sent'));
        }

        return $this->redirectToRoute('app_rfq_show', ['id' => $rfq->getId()]);
    }

    #[Route('/{id}/nda-executed', name: 'app_rfq_nda_executed', methods: ['POST'])]
    public function ndaExecuted(Request $request, RFQ $rfq, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('nda_executed'.$rfq->getId(), $request->request->get('_token'))) {
            $rfq->setNdaExecuted(true);
            
            // Set NDA sent and date if not already set
            if (!$rfq->isNdaSent()) {
                $rfq->setNdaSent(true);
                $rfq->setNdaDate(new \DateTime());
            }
            
            $entityManager->flush();

            $this->addFlash('success', $this->translator->trans('rfq.flash.nda_executed'));
        }

        return $this->redirectToRoute('app_rfq_show', ['id' => $rfq->getId()]);
    }
    
    /**
     * Win/Loss Analytics Dashboard
     * 
     * Displays competitive intelligence insights including:
     * - Win/Loss rates by time period
     * - Loss reason breakdown
     * - Competitor analysis
     * - Lessons learned repository
     * 
     * Supports filtering by:
     * - Preset periods (30, 90, 180, 365 days, YTD, All Time)
     * - Custom date ranges
     * - Period-over-period comparison
     */
    #[Route('/analytics/win-loss', name: 'app_rfq_win_loss_analytics', methods: ['GET'])]
    public function winLossAnalytics(Request $request, RFQRepository $rfqRepository): Response
    {
        // Get filter parameters
        $period = $request->query->get('period', '90');
        $startDateParam = $request->query->get('start_date');
        $endDateParam = $request->query->get('end_date');
        $compareMode = $request->query->get('compare') === 'previous';
        
        // Determine date range based on period or custom dates
        $customRange = false;
        $endDate = new \DateTime('today 23:59:59');
        
        if ($period === 'custom' && $startDateParam && $endDateParam) {
            // Custom date range
            $startDate = $this->parseDateParam($startDateParam . ' 00:00:00');
            $endDate = $this->parseDateParam($endDateParam . ' 23:59:59');
            if ($startDate === null || $endDate === null) {
                $this->addFlash('error', 'Invalid date range. Please use YYYY-MM-DD format.');
                return $this->redirectToRoute('app_rfq_index');
            }
            if ($endDate < $startDate) {
                $this->addFlash('error', 'End date must be after start date.');
                return $this->redirectToRoute('app_rfq_index');
            }
            $customRange = true;
            $dateRangeDisplay = $startDate->format('M j, Y') . ' - ' . $endDate->format('M j, Y');
        } elseif ($period === 'ytd') {
            // Year to date
            $startDate = new \DateTime('first day of January this year 00:00:00');
            $dateRangeDisplay = $startDate->format('M j, Y') . ' - ' . $endDate->format('M j, Y');
        } elseif ($period === 'all') {
            // All time
            $startDate = new \DateTime('2000-01-01 00:00:00'); // Far past date
            $dateRangeDisplay = 'All Time';
        } else {
            // Standard period in days
            $days = (int) $period;
            if ($days < 1 || $days > 3650) {
                $days = 90;
            }
            $startDate = (new \DateTime())->modify("-{$days} days")->setTime(0, 0, 0);
            $dateRangeDisplay = $startDate->format('M j, Y') . ' - ' . $endDate->format('M j, Y');
        }
        
        // Get all completed RFQs (Won or Lost) in period
        $completedRfqs = $rfqRepository->createQueryBuilder('r')
            ->leftJoin('r.company', 'c')
            ->addSelect('c')
            ->where('r.status IN (:statuses)')
            ->andWhere('r.decisionDate >= :start AND r.decisionDate <= :end OR (r.decisionDate IS NULL AND r.createdAt >= :start AND r.createdAt <= :end)')
            ->setParameter('statuses', ['Won', 'Lost'])
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->orderBy('r.decisionDate', 'DESC')
            ->getQuery()
            ->getResult();
        
        // Calculate metrics
        $metrics = $this->calculateWinLossMetrics($completedRfqs);
        
        // Comparison data (previous period)
        $comparisonMetrics = null;
        if ($compareMode && $period !== 'all' && $period !== 'custom') {
            $periodDays = $period === 'ytd' 
                ? $startDate->diff($endDate)->days 
                : (int) $period;
            
            $prevEndDate = (clone $startDate)->modify('-1 day');
            $prevStartDate = (clone $prevEndDate)->modify("-{$periodDays} days");
            
            $previousRfqs = $rfqRepository->createQueryBuilder('r')
                ->leftJoin('r.company', 'c')
                ->addSelect('c')
                ->where('r.status IN (:statuses)')
                ->andWhere('r.decisionDate >= :start AND r.decisionDate <= :end OR (r.decisionDate IS NULL AND r.createdAt >= :start AND r.createdAt <= :end)')
                ->setParameter('statuses', ['Won', 'Lost'])
                ->setParameter('start', $prevStartDate)
                ->setParameter('end', $prevEndDate)
                ->getQuery()
                ->getResult();
            
            $comparisonMetrics = $this->calculateWinLossMetrics($previousRfqs);
        }
        
        return $this->render('rfq/win_loss_analytics.html.twig', [
            'period' => $period,
            'custom_range' => $customRange,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'date_range_display' => $dateRangeDisplay,
            'compare_mode' => $compareMode,
            'comparison' => $comparisonMetrics,
            'total_decided' => $metrics['totalDecided'],
            'won_count' => $metrics['wonCount'],
            'lost_count' => $metrics['lostCount'],
            'won_value' => $metrics['wonValue'],
            'lost_value' => $metrics['lostValue'],
            'win_rate' => $metrics['winRate'],
            'loss_rate' => $metrics['lossRate'],
            'loss_reasons' => $metrics['lossReasons'],
            'competitors' => $metrics['competitors'],
            'lessons_learned' => $metrics['lessonsLearned'],
            'win_factors' => $metrics['winFactors'],
            'completed_rfqs' => $completedRfqs,
        ]);
    }
    
    /**
     * Calculate win/loss metrics from a collection of RFQs
     */
    private function calculateWinLossMetrics(array $rfqs): array
    {
        $wonCount = 0;
        $lostCount = 0;
        $wonValue = 0.0;
        $lostValue = 0.0;
        $lossReasons = [];
        $competitors = [];
        $lessonsLearned = [];
        $winFactorsList = [];
        
        foreach ($rfqs as $rfq) {
            /** @var RFQ $rfq */
            $value = (float) ($rfq->getEstimatedValue() ?? 0);
            
            if ($rfq->isWon()) {
                $wonCount++;
                $wonValue += $value;
                if ($rfq->getWinFactors()) {
                    $winFactorsList[] = [
                        'rfq' => $rfq,
                        'factors' => $rfq->getWinFactors(),
                    ];
                }
            } else {
                $lostCount++;
                $lostValue += $value;
                
                // Aggregate loss reasons
                $reason = $rfq->getLossReason() ?? 'unspecified';
                $lossReasons[$reason] = ($lossReasons[$reason] ?? 0) + 1;
                
                // Aggregate competitors
                if ($rfq->getCompetitorWon()) {
                    $competitor = $rfq->getCompetitorWon();
                    if (!isset($competitors[$competitor])) {
                        $competitors[$competitor] = ['count' => 0, 'value' => 0.0];
                    }
                    $competitors[$competitor]['count']++;
                    $competitors[$competitor]['value'] += $value;
                }
                
                // Collect lessons learned
                if ($rfq->getLessonsLearned()) {
                    $lessonsLearned[] = [
                        'rfq' => $rfq,
                        'lessons' => $rfq->getLessonsLearned(),
                    ];
                }
            }
        }
        
        // Calculate rates
        $totalDecided = $wonCount + $lostCount;
        $winRate = $totalDecided > 0 ? round(($wonCount / $totalDecided) * 100, 1) : 0;
        $lossRate = $totalDecided > 0 ? round(($lostCount / $totalDecided) * 100, 1) : 0;
        
        // Sort loss reasons by frequency
        arsort($lossReasons);
        
        // Sort competitors by value lost
        uasort($competitors, fn($a, $b) => $b['value'] <=> $a['value']);
        
        return [
            'wonCount' => $wonCount,
            'lostCount' => $lostCount,
            'wonValue' => $wonValue,
            'lostValue' => $lostValue,
            'totalDecided' => $totalDecided,
            'winRate' => $winRate,
            'lossRate' => $lossRate,
            'lossReasons' => $lossReasons,
            'competitors' => $competitors,
            'lessonsLearned' => array_slice($lessonsLearned, 0, 10),
            'winFactors' => array_slice($winFactorsList, 0, 10),
        ];
    }

    /**
     * Parse a user-supplied date string into a \DateTime, or null when invalid.
     */
    private function parseDateParam(?string $value, string $default = 'now'): ?\DateTime
    {
        if ($value === null || trim($value) === '') {
            $value = $default;
        }
        try {
            return new \DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }
}
