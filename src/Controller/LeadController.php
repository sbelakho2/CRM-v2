<?php

namespace App\Controller;

use App\Entity\Lead;
use App\Entity\Company;
use App\Repository\LeadRepository;
use App\Service\GuidanceNotificationService;
use App\Service\LeadAnalysisService;
use App\Service\SalesPipelineOrchestratorService;
use App\Service\CountryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/leads')]
#[IsGranted('ROLE_USER')]
class LeadController extends AbstractController
{
    public function __construct(
        private GuidanceNotificationService $guidanceService,
        private SalesPipelineOrchestratorService $pipelineOrchestrator,
        private CountryService $countryService,
        private LeadAnalysisService $leadAnalysisService,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'app_lead_index')]
    public function index(LeadRepository $leadRepo): Response
    {
        // Count by review status
        $totalLeads = $leadRepo->count([]);
        $pendingCount = $leadRepo->count(['reviewStatus' => 'pending']);
        $approvedCount = $leadRepo->count(['reviewStatus' => 'approved']);
        $deniedCount = $leadRepo->count(['reviewStatus' => 'denied']);

        // Count by nurturing stage (new, contacted, qualified, converted, rejected)
        $stageCounts = $leadRepo->createQueryBuilder('l')
            ->select('l.nurturingStage AS stage, COUNT(l.id) AS count')
            ->where('l.nurturingStage IS NOT NULL')
            ->groupBy('l.nurturingStage')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        // Count by score range
        $scoreRanges = [
            'range_0_25'  => [0, 25],
            'range_25_50' => [25, 50],
            'range_50_75' => [50, 75],
            'range_75_100'=> [75, 100],
        ];
        $scoreCounts = [];
        foreach ($scoreRanges as $key => [$min, $max]) {
            $qb = $leadRepo->createQueryBuilder('l')
                ->select('COUNT(l.id)')
                ->where('l.leadScore >= :min AND l.leadScore < :max')
                ->setParameter('min', $min)
                ->setParameter('max', $max);
            if ($max === 100) {
                $qb = $leadRepo->createQueryBuilder('l')
                    ->select('COUNT(l.id)')
                    ->where('l.leadScore >= :min AND l.leadScore <= :max')
                    ->setParameter('min', $min)
                    ->setParameter('max', $max);
            }
            $scoreCounts[$key] = (int) $qb->getQuery()->getSingleScalarResult();
        }

        // Count by source
        $sourceCounts = $leadRepo->createQueryBuilder('l')
            ->select('l.source AS source, COUNT(l.id) AS count')
            ->where('l.source IS NOT NULL')
            ->groupBy('l.source')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        // Recent activity (last 7 days) — using native SQL for DATE() function
        $sevenDaysAgo = new \DateTime('-7 days');
        $conn = $this->entityManager->getConnection();
        $sql = 'SELECT DATE(l.created_at) AS activity_date, COUNT(l.id) AS count
                FROM leads l
                WHERE l.created_at >= :since
                GROUP BY activity_date
                ORDER BY activity_date ASC';
        $recentActivity = $conn->fetchAllAssociative($sql, [
            'since' => $sevenDaysAgo->format('Y-m-d H:i:s'),
        ]);

        // Recent leads for table
        $recentLeads = $leadRepo->findBy([], ['createdAt' => 'DESC'], 15);

        $qualityDistribution = $this->leadAnalysisService->getLeadQualityDistribution();
        $sourceEffectiveness = $this->leadAnalysisService->getSourceEffectiveness();
        $funnelAnalytics = $this->leadAnalysisService->getFunnelAnalytics();
        $geographicDistribution = $this->leadAnalysisService->getGeographicDistribution();
        $actionableInsights = $this->leadAnalysisService->getActionableInsights();

        return $this->render('lead/index.html.twig', [
            'total_leads' => $totalLeads,
            'pending_count' => $pendingCount,
            'approved_count' => $approvedCount,
            'denied_count' => $deniedCount,
            'stage_counts' => $stageCounts,
            'score_counts' => $scoreCounts,
            'source_counts' => $sourceCounts,
            'recent_activity' => $recentActivity,
            'recent_leads' => $recentLeads,
            'quality_distribution' => $qualityDistribution,
            'source_effectiveness' => $sourceEffectiveness,
            'funnel_analytics' => $funnelAnalytics,
            'geographic_distribution' => $geographicDistribution,
            'actionable_insights' => $actionableInsights,
        ]);
    }

    #[Route('/review', name: 'app_lead_review')]
    public function review(Request $request, LeadRepository $leadRepo): Response
    {
        $regionFilter = $request->query->get('region', 'all');
        $statusFilter = $request->query->get('status', 'pending');
        $scoreMin = $request->query->get('score_min', 0);

        $qb = $leadRepo->createQueryBuilder('l');

        if ($statusFilter !== 'all') {
            $qb->andWhere('l.reviewStatus = :status')
               ->setParameter('status', $statusFilter);
        }

        if ($regionFilter !== 'all') {
            $qb->andWhere('l.regionTag = :region')
               ->setParameter('region', $regionFilter);
        }

        if ($scoreMin > 0) {
            $qb->andWhere('l.leadScore >= :scoreMin')
               ->setParameter('scoreMin', $scoreMin);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(10, (int) $request->query->get('limit', 100)));
        $offset = ($page - 1) * $limit;

        $leads = $qb->orderBy('l.leadScore', 'DESC')
                    ->setMaxResults($limit)
                    ->setFirstResult($offset)
                    ->getQuery()
                    ->getResult();

        // Get regional statistics
        $regionStats = $leadRepo->getStatsByRegion();
        $regionOptions = $this->countryService->getRegionOptions();
        foreach ($regionStats as $stat) {
            $tag = $stat['regionTag'] ?? null;
            if ($tag && !isset($regionOptions[$tag])) {
                $regionOptions[$tag] = strtoupper((string) $tag);
            }
        }

        return $this->render('lead/review.html.twig', [
            'leads' => $leads,
            'region_filter' => $regionFilter,
            'status_filter' => $statusFilter,
            'score_min' => $scoreMin,
            'region_stats' => $regionStats,
            'region_options' => $regionOptions,
        ]);
    }

    #[Route('/approve/{id}', name: 'app_lead_approve', methods: ['POST'])]
    public function approve(Request $request, Lead $lead, EntityManagerInterface $em): JsonResponse
    {
        // Validate CSRF token from header or body for AJAX requests
        $token = $request->headers->get('X-CSRF-TOKEN') 
            ?? $request->request->get('_token')
            ?? (json_decode($request->getContent(), true)['_token'] ?? null);
        
        if (!$this->isCsrfTokenValid('lead_action_' . $lead->getId(), $token)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid CSRF token',
            ], 403);
        }

        $lead->setReviewStatus('approved');
        $lead->setUpdatedAt(new \DateTime());
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Lead approved successfully',
            'lead_id' => $lead->getId(),
        ]);
    }

    #[Route('/deny/{id}', name: 'app_lead_deny', methods: ['POST'])]
    public function deny(Request $request, Lead $lead, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        
        // Validate CSRF token from header or body for AJAX requests
        $token = $request->headers->get('X-CSRF-TOKEN') 
            ?? $request->request->get('_token')
            ?? ($data['_token'] ?? null);
        
        if (!$this->isCsrfTokenValid('lead_action_' . $lead->getId(), $token)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid CSRF token',
            ], 403);
        }

        $reason = $data['reason'] ?? 'Not a good fit';

        $lead->setReviewStatus('denied');
        $lead->setDenyReason($reason);
        $lead->setUpdatedAt(new \DateTime());
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Lead denied',
            'lead_id' => $lead->getId(),
        ]);
    }

    #[Route('/convert/{id}', name: 'app_lead_convert', methods: ['POST'])]
    public function convert(Request $request, Lead $lead, EntityManagerInterface $em): JsonResponse
    {
        // Validate CSRF token from header or body for AJAX requests
        $token = $request->headers->get('X-CSRF-TOKEN') 
            ?? $request->request->get('_token')
            ?? (json_decode($request->getContent(), true)['_token'] ?? null);
        
        if (!$this->isCsrfTokenValid('lead_action_' . $lead->getId(), $token)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid CSRF token',
            ], 403);
        }

        // Check if already converted
        if ($lead->getCompany()) {
            return $this->json([
                'success' => false,
                'message' => 'Lead already converted to company',
            ], 400);
        }

        // Require approval before conversion
        if ($lead->getReviewStatus() !== 'approved') {
            return $this->json([
                'success' => false,
                'message' => 'Lead must be approved before conversion. Please approve it first.',
            ], 400);
        }

        // Wrap the whole conversion (company creation + enrichment writes +
        // draft RFQ) in a single transaction so it commits atomically.
        $em->beginTransaction();
        try {
            // Create new company from lead with all available data
            $company = new Company();
            $company->setName($lead->getCompanyName());
            
            // Set legal name if available
            if ($lead->getLegalName()) {
                $company->setLegalName($lead->getLegalName());
            }
            
            // Set website
            if ($lead->getWebsiteRoot()) {
                $company->setWebsite($lead->getWebsiteRoot());
            }
            
            // Set physical site/location
            if ($lead->getSiteLocation()) {
                $company->setPhysicalSite($lead->getSiteLocation());
            }
            
            // Set LinkedIn if available
            if ($lead->getLeadUrl() && str_contains($lead->getLeadUrl(), 'linkedin.com')) {
                $company->setLinkedinCompanyUrl($lead->getLeadUrl());
            }
            
            // Build comprehensive source notes
            $notes = "Converted from Lead #" . $lead->getId() . " on " . (new \DateTime())->format('Y-m-d H:i') . "\n";
            $notes .= "Lead Score: " . $lead->getLeadScore() . "/100\n";
            $notes .= "Region: " . strtoupper($lead->getRegionTag() ?? 'Unknown') . "\n";
            
            if ($lead->getContactEmailsPublic()) {
                $notes .= "Contact Emails: " . implode(', ', $lead->getContactEmailsPublic()) . "\n";
            }
            
            if ($lead->getSupplierPortalUrl()) {
                $notes .= "Supplier Portal: " . $lead->getSupplierPortalUrl() . "\n";
            }
            
            if ($lead->getContactFormUrl()) {
                $notes .= "Contact Form: " . $lead->getContactFormUrl() . "\n";
            }
            
            if ($lead->getQualityStack() && count($lead->getQualityStack()) > 0) {
                $notes .= "Quality Certifications: " . implode(', ', $lead->getQualityStack()) . "\n";
            }
            
            if ($lead->getNotesAuto()) {
                $notes .= "\nAuto-Generated Notes:\n" . $lead->getNotesAuto();
            }
            
            $company->setSourceNotes($notes);
            
            // Set account tier based on lead score
            if ($lead->getLeadScore() >= 70) {
                $company->setAccountTier('A');
            } elseif ($lead->getLeadScore() >= 55) {
                $company->setAccountTier('B');
            } else {
                $company->setAccountTier('C');
            }
            
            // Set pipeline stage
            $company->setPipelineStage('Prospect');
            
            // Set sector from tags
            if ($lead->getSectorTags() && count($lead->getSectorTags()) > 0) {
                $company->setSector(ucfirst($lead->getSectorTags()[0]));
            } else {
                $company->setSector('General Manufacturing');
            }
            
            $company->setCreatedAt(new \DateTime());
            $em->persist($company);

            // Link lead to company
            $lead->setCompany($company);
            $lead->setAlreadyInCrm(true);
            $lead->setUpdatedAt(new \DateTime());

            $em->flush();
            
            // After flush, update CRM record ID
            $lead->setCrmRecordId((string)$company->getId());
            $em->flush();

            // Auto-create draft RFQ and advance pipeline stage
            $draftRfq = $this->pipelineOrchestrator->afterLeadConverted($company, $lead);
            $em->flush();

            $em->commit();

            // Provide guidance after lead conversion
            $this->guidanceService->afterLeadConverted($company, $lead);
        } catch (\Throwable $e) {
            $em->rollback();
            throw $e;
        }

        return $this->json([
            'success' => true,
            'message' => 'Lead converted to company successfully! Company added to CRM.',
            'company_id' => $company->getId(),
            'lead_id' => $lead->getId(),
        ]);
    }

    #[Route('/assign/{id}', name: 'app_lead_assign', methods: ['POST'])]
    public function assign(Request $request, Lead $lead, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        
        // Validate CSRF token from header or body for AJAX requests
        $token = $request->headers->get('X-CSRF-TOKEN') 
            ?? $request->request->get('_token')
            ?? ($data['_token'] ?? null);
        
        if (!$this->isCsrfTokenValid('lead_action_' . $lead->getId(), $token)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid CSRF token',
            ], 403);
        }

        $owner = $data['owner'] ?? null;

        if (!$owner) {
            return $this->json(['success' => false, 'message' => 'Owner required'], 400);
        }

        $lead->setOwnerRep($owner);
        $lead->setUpdatedAt(new \DateTime());
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Lead assigned successfully',
            'lead_id' => $lead->getId(),
            'owner' => $owner,
        ]);
    }

    #[Route('/dashboard', name: 'app_lead_dashboard')]
    public function dashboard(LeadRepository $leadRepo): Response
    {
        // Get overall stats
        $totalLeads = $leadRepo->count([]);
        $pendingLeads = $leadRepo->count(['reviewStatus' => 'pending']);
        $approvedLeads = $leadRepo->count(['reviewStatus' => 'approved']);
        $deniedLeads = $leadRepo->count(['reviewStatus' => 'denied']);

        // Get regional breakdown
        $regionStats = $leadRepo->getStatsByRegion();

        // Get top leads by score
        $topLeads = $leadRepo->getTopLeads(20);

        // Get recent approval rates (last 7 days)
        $since = new \DateTime('-7 days');
        $weeklyStats = $leadRepo->getApprovalRate(null, $since);

        // Calculate precision @ top-50
        $top50 = $leadRepo->getTopLeads(50);
        $top50Approved = array_filter($top50, fn($l) => $l->getReviewStatus() === 'approved');
        $precisionTop50 = count($top50) > 0 ? (count($top50Approved) / count($top50)) * 100 : 0;

        $qualityDistribution = $this->leadAnalysisService->getLeadQualityDistribution();
        $sourceEffectiveness = $this->leadAnalysisService->getSourceEffectiveness();
        $geographicDistribution = $this->leadAnalysisService->getGeographicDistribution();
        $funnelAnalytics = $this->leadAnalysisService->getFunnelAnalytics();
        $actionableInsights = $this->leadAnalysisService->getActionableInsights();

        return $this->render('lead/dashboard.html.twig', [
            'total_leads' => $totalLeads,
            'pending_leads' => $pendingLeads,
            'approved_leads' => $approvedLeads,
            'denied_leads' => $deniedLeads,
            'region_stats' => $regionStats,
            'top_leads' => $topLeads,
            'weekly_stats' => $weeklyStats,
            'precision_top50' => round($precisionTop50, 1),
            'quality_distribution' => $qualityDistribution,
            'source_effectiveness' => $sourceEffectiveness,
            'geographic_distribution' => $geographicDistribution,
            'funnel_analytics' => $funnelAnalytics,
            'actionable_insights' => $actionableInsights,
        ]);
    }

    #[Route('/export', name: 'app_lead_export')]
    public function export(Request $request, LeadRepository $leadRepo): Response
    {
        $region = $request->query->get('region', 'all');
        $status = $request->query->get('status', 'all');

        $qb = $leadRepo->createQueryBuilder('l');

        if ($region !== 'all') {
            $qb->andWhere('l.regionTag = :region')
               ->setParameter('region', $region);
        }

        if ($status !== 'all') {
            $qb->andWhere('l.reviewStatus = :status')
               ->setParameter('status', $status);
        }

        $leads = $qb->orderBy('l.leadScore', 'DESC')
                    ->getQuery()
                    ->getResult();

        // Generate CSV with BOM for UTF-8 and formula injection protection
        $csv = "\xEF\xBB\xBFCompany Name,Legal Name,Website,Region,Score,Status,Location,Contact Emails,Portal URL,Last Seen\n";
        
        foreach ($leads as $lead) {
            $csv .= sprintf(
                '"%s","%s","%s","%s",%d,"%s","%s","%s","%s","%s"' . "\n",
                $this->sanitizeCsvField($lead->getCompanyName() ?? ''),
                $this->sanitizeCsvField($lead->getLegalName() ?? ''),
                $this->sanitizeCsvField($lead->getWebsiteRoot() ?? ''),
                $this->sanitizeCsvField($lead->getRegionTag() ?? ''),
                $lead->getLeadScore() ?? 0,
                $this->sanitizeCsvField($lead->getReviewStatus() ?? ''),
                $this->sanitizeCsvField($lead->getSiteLocation() ?? ''),
                $this->sanitizeCsvField(implode('; ', $lead->getContactEmailsPublic() ?? [])),
                $this->sanitizeCsvField($lead->getSupplierPortalUrl() ?? ''),
                $this->sanitizeCsvField($lead->getLastSeen() ? $lead->getLastSeen()->format('Y-m-d') : '')
            );
        }

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="leads_export_' . date('Y-m-d') . '.csv"');

        return $response;
    }

    private function sanitizeCsvField(string $value): string
    {
        $value = str_replace('"', '""', $value);
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t"], true)) {
            if ($value[0] !== "\t") {
                return "\t" . $value;
            }
        }
        return $value;
    }
}
