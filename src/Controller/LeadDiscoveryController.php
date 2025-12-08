<?php

namespace App\Controller;

use App\Entity\Lead;
use App\Service\GoogleSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/lead-discovery')]
class LeadDiscoveryController extends AbstractController
{
    public function __construct(
        private GoogleSearchService $googleSearchService,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'lead_discovery_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('lead_discovery/index.html.twig');
    }

    #[Route('/search', name: 'lead_discovery_search', methods: ['POST'])]
    public function search(Request $request): Response
    {
        $query = $request->request->get('query');
        $limit = (int)$request->request->get('limit', 10);
        $sector = $request->request->get('sector');

        if (empty($query)) {
            $this->addFlash('error', 'Please enter a search query.');
            return $this->redirectToRoute('lead_discovery_index');
        }

        try {
            // Perform search
            if ($sector && $sector !== 'all') {
                $results = $this->googleSearchService->searchBySector($sector, 'Morocco', $limit);
            } else {
                $results = $this->googleSearchService->searchCompanies($query, min($limit, 10));
            }

            // Calculate quota
            $quota = $this->googleSearchService->estimateQuota(1, $limit);

            // Store results in session for import
            $request->getSession()->set('search_results', $results['results']);
            $request->getSession()->set('search_query', $query);

            return $this->render('lead_discovery/results.html.twig', [
                'results' => $results['results'],
                'totalResults' => $results['totalResults'] ?? 0,
                'searchTime' => $results['searchTime'] ?? 0,
                'query' => $query,
                'quota' => $quota,
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Search failed: ' . $e->getMessage());
            return $this->redirectToRoute('lead_discovery_index');
        }
    }

    #[Route('/import', name: 'lead_discovery_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $results = $request->getSession()->get('search_results', []);
        $query = $request->getSession()->get('search_query', 'Unknown');
        $selectedIndices = $request->request->all()['selected'] ?? [];

        if (empty($results) || empty($selectedIndices)) {
            $this->addFlash('warning', 'No results to import.');
            return $this->redirectToRoute('lead_discovery_index');
        }

        $imported = 0;
        $skipped = 0;

        foreach ($selectedIndices as $index) {
            if (!isset($results[$index])) {
                continue;
            }

            $result = $results[$index];
            $website = $this->googleSearchService->extractWebsite($result);

            // Check for duplicates
            if ($website) {
                $existing = $this->entityManager->getRepository(Lead::class)
                    ->findOneBy(['website' => $website]);

                if ($existing) {
                    $skipped++;
                    continue;
                }
            }

            // Create lead
            $lead = new Lead();
            $lead->setCompanyName($this->cleanCompanyName($result['title']));
            $lead->setWebsite($website);
            $lead->setDescription($result['snippet']);
            $lead->setSource('Google Search: ' . $query);
            $lead->setReviewStatus('new');
            $lead->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($lead);
            $imported++;
        }

        $this->entityManager->flush();

        // Clear session
        $request->getSession()->remove('search_results');
        $request->getSession()->remove('search_query');

        if ($imported > 0) {
            $this->addFlash('success', "Successfully imported {$imported} lead(s).");
        }
        if ($skipped > 0) {
            $this->addFlash('info', "Skipped {$skipped} duplicate(s).");
        }

        return $this->redirectToRoute('app_lead_index');
    }

    #[Route('/import-all', name: 'lead_discovery_import_all', methods: ['POST'])]
    public function importAll(Request $request): Response
    {
        $results = $request->getSession()->get('search_results', []);
        $query = $request->getSession()->get('search_query', 'Unknown');

        if (empty($results)) {
            $this->addFlash('warning', 'No results to import.');
            return $this->redirectToRoute('lead_discovery_index');
        }

        $imported = 0;
        $skipped = 0;

        foreach ($results as $result) {
            $website = $this->googleSearchService->extractWebsite($result);

            if ($website) {
                $existing = $this->entityManager->getRepository(Lead::class)
                    ->findOneBy(['website' => $website]);

                if ($existing) {
                    $skipped++;
                    continue;
                }
            }

            $lead = new Lead();
            $lead->setCompanyName($this->cleanCompanyName($result['title']));
            $lead->setWebsite($website);
            $lead->setDescription($result['snippet']);
            $lead->setSource('Google Search: ' . $query);
            $lead->setReviewStatus('new');
            $lead->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($lead);
            $imported++;
        }

        $this->entityManager->flush();

        $request->getSession()->remove('search_results');
        $request->getSession()->remove('search_query');

        if ($imported > 0) {
            $this->addFlash('success', "Successfully imported {$imported} lead(s).");
        }
        if ($skipped > 0) {
            $this->addFlash('info', "Skipped {$skipped} duplicate(s).");
        }

        return $this->redirectToRoute('app_lead_index');
    }

    private function cleanCompanyName(string $title): string
    {
        $title = preg_replace('/\s*[-|]\s*.+$/', '', $title);
        return trim($title);
    }
}
