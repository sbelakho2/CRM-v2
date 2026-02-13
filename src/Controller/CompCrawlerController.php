<?php

namespace App\Controller;

use App\Entity\Competitor;
use App\Entity\CompetitorWatchlist;
use App\Repository\CompetitorChangeEventRepository;
use App\Repository\CompetitorRepository;
use App\Repository\CompetitorWatchlistRepository;
use App\Service\CompCrawler\CompScoringService;
use App\Service\RegionStandardizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/comp-crawler')]
#[IsGranted('ROLE_USER')]
class CompCrawlerController extends AbstractController
{
    public function __construct(
        private readonly CompetitorRepository $competitorRepo,
        private readonly CompetitorChangeEventRepository $changeEventRepo,
        private readonly CompetitorWatchlistRepository $watchlistRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    // ─── Dashboard ─────────────────────────────────────────────────────────────

    #[Route('', name: 'comp_crawler_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $stats = $this->competitorRepo->getDashboardStats();
        $recentChanges = $this->changeEventRepo->findRecent(10, null, 14);
        $topThreats = $this->competitorRepo->findFiltered(['status' => 'verified'], 'threatScore', 'DESC', 5);

        return $this->render('comp_crawler/dashboard.html.twig', [
            'stats' => $stats,
            'recent_changes' => $recentChanges,
            'top_threats' => $topThreats,
        ]);
    }

    // ─── Competitor List ───────────────────────────────────────────────────────

    #[Route('/list', name: 'comp_crawler_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filters = [
            'status' => $request->query->get('status'),
            'type' => $request->query->get('type'),
            'region' => $request->query->get('region'),
            'country' => $request->query->get('country'),
            'search' => $request->query->get('q'),
            'minThreat' => $request->query->get('minThreat') ? (int) $request->query->get('minThreat') : null,
            'minOverlap' => $request->query->get('minOverlap') ? (int) $request->query->get('minOverlap') : null,
            'proofGrade' => $request->query->get('proofGrade'),
            'directness' => $request->query->get('directness'),
        ];

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $sortBy = $request->query->get('sort', 'threatScore');
        $sortDir = $request->query->get('dir', 'DESC');

        $competitors = $this->competitorRepo->findFiltered($filters, $sortBy, $sortDir, $limit, $offset);
        $total = $this->competitorRepo->countFiltered($filters);
        $totalPages = max(1, (int) ceil($total / $limit));

        // Build region options from RegionStandardizationService labels + any extra regions in DB
        $regionOptions = $this->buildRegionOptions();

        return $this->render('comp_crawler/list.html.twig', [
            'competitors' => $competitors,
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'statuses' => [
                Competitor::STATUS_CANDIDATE,
                Competitor::STATUS_VERIFIED,
                Competitor::STATUS_MONITORING,
                Competitor::STATUS_ARCHIVED,
                Competitor::STATUS_REJECTED,
            ],
            'types' => Competitor::VALID_TYPES,
            'regions' => $regionOptions,
        ]);
    }

    // ─── Competitor Detail ─────────────────────────────────────────────────────

    #[Route('/{id}', name: 'comp_crawler_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(Competitor $competitor): Response
    {
        $changeEvents = $this->changeEventRepo->findByCompetitor($competitor->getId(), 30);

        return $this->render('comp_crawler/detail.html.twig', [
            'competitor' => $competitor,
            'change_events' => $changeEvents,
        ]);
    }

    // ─── Change Feed ───────────────────────────────────────────────────────────

    #[Route('/changes', name: 'comp_crawler_changes', methods: ['GET'])]
    public function changes(Request $request): Response
    {
        $days = $request->query->getInt('days', 30);
        $severity = $request->query->get('severity');
        $limit = 50;

        $events = $this->changeEventRepo->findRecent($limit, null, $days);

        if ($severity) {
            $events = array_filter($events, fn($e) => $e->getSeverity() === $severity);
        }

        return $this->render('comp_crawler/changes.html.twig', [
            'events' => $events,
            'days' => $days,
            'severity_filter' => $severity,
        ]);
    }

    // ─── Watchlists ────────────────────────────────────────────────────────────

    #[Route('/watchlists', name: 'comp_crawler_watchlists', methods: ['GET'])]
    public function watchlists(): Response
    {
        $watchlists = $this->watchlistRepo->findBy(
            [],
            ['createdAt' => 'DESC']
        );

        return $this->render('comp_crawler/watchlists.html.twig', [
            'watchlists' => $watchlists,
        ]);
    }

    // ─── API: Quick Status Update ──────────────────────────────────────────────

    #[Route('/{id}/status', name: 'comp_crawler_update_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateStatus(Competitor $competitor, Request $request): JsonResponse
    {
        $status = $request->request->get('status');
        if (!in_array($status, [
            Competitor::STATUS_CANDIDATE,
            Competitor::STATUS_VERIFIED,
            Competitor::STATUS_MONITORING,
            Competitor::STATUS_ARCHIVED,
            Competitor::STATUS_REJECTED,
            Competitor::STATUS_SEED_ONLY,
        ])) {
            return new JsonResponse(['error' => 'Invalid status'], 400);
        }

        $competitor->setStatus($status);
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'status' => $status]);
    }

    // ─── API: Stats JSON ───────────────────────────────────────────────────────

    #[Route('/api/stats', name: 'comp_crawler_api_stats', methods: ['GET'])]
    public function apiStats(): JsonResponse
    {
        $stats = $this->competitorRepo->getDashboardStats();
        return new JsonResponse($stats);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Build region filter options: primary markets first, then other regions from DB.
     * Returns [ 'code' => 'Label', ... ]
     */
    private function buildRegionOptions(): array
    {
        // Primary regions for Starz Electronics (always shown, in this order)
        $primaryRegions = [
            'morocco'    => 'Morocco',
            'tunisia'    => 'Tunisia',
            'egypt'      => 'Egypt',
            'eu_west'    => 'EU — West (FR, BE, NL)',
            'eu_central' => 'EU — Central (DE, AT, CH, PL)',
            'eu_south'   => 'EU — South (ES, IT, PT)',
            'eu_north'   => 'EU — North (Nordics)',
            'uk'         => 'United Kingdom & Ireland',
            'us_east'    => 'US — East Coast',
            'us_west'    => 'US — West Coast',
            'us_central' => 'US — Central / Midwest',
            'us_south'   => 'US — South / Texas',
        ];

        // Discover any extra region codes stored in the DB that aren't in primary list
        $conn = $this->em->getConnection();
        $rows = $conn->fetchFirstColumn("SELECT DISTINCT regions FROM competitors WHERE regions IS NOT NULL AND regions != '[]'");
        $extraLabels = RegionStandardizationService::REGION_LABELS;
        $extras = [];
        foreach ($rows as $json) {
            $codes = json_decode($json, true) ?: [];
            foreach ($codes as $code) {
                if (!isset($primaryRegions[$code]) && !isset($extras[$code])) {
                    $extras[$code] = $extraLabels[$code] ?? ucwords(str_replace('_', ' ', $code));
                }
            }
        }
        asort($extras);

        return array_merge($primaryRegions, $extras);
    }
}
