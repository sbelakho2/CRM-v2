<?php

namespace App\Controller;

use App\Entity\ReportDefinition;
use App\Form\ReportDefinitionType;
use App\Repository\ReportDefinitionRepository;
use App\Service\ReportBuilderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/reports')]
#[IsGranted('ROLE_USER')]
class ReportController extends AbstractController
{
    public function __construct(
        private ReportDefinitionRepository $reportRepository,
        private ReportBuilderService $reportBuilder,
        private EntityManagerInterface $em
    ) {}
    
    #[Route('', name: 'report_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $dataSource = $request->query->get('source');
        $category = $request->query->get('category');
        
        $reports = $this->reportRepository->findAccessibleByUser($user, $dataSource, $category);
        $favorites = $this->reportRepository->findFavorites($user);
        $recent = $this->reportRepository->findRecent($user, 5);
        $statistics = $this->reportRepository->getStatistics($user);
        $categories = $this->reportRepository->findAllCategories();
        
        return $this->render('report/index.html.twig', [
            'reports' => $reports,
            'favorites' => $favorites,
            'recent' => $recent,
            'statistics' => $statistics,
            'categories' => $categories,
            'dataSources' => ReportDefinition::getDataSources(),
            'reportTypes' => ReportDefinition::getReportTypes(),
            'currentSource' => $dataSource,
            'currentCategory' => $category,
        ]);
    }
    
    #[Route('/new', name: 'report_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $report = new ReportDefinition();
        $report->setCreatedBy($this->getUser());
        
        // Pre-select data source if provided
        if ($source = $request->query->get('source')) {
            $report->setDataSource($source);
        }
        
        $form = $this->createForm(ReportDefinitionType::class, $report);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $this->reportRepository->save($report, true);
            
            $this->addFlash('success', 'Report created successfully.');
            return $this->redirectToRoute('report_builder', ['id' => $report->getId()]);
        }
        
        return $this->render('report/new.html.twig', [
            'form' => $form,
            'dataSources' => ReportDefinition::getDataSources(),
            'reportTypes' => ReportDefinition::getReportTypes(),
        ]);
    }
    
    #[Route('/{id}', name: 'report_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(ReportDefinition $report, Request $request): Response
    {
        if (!$report->canUserAccess($this->getUser())) {
            throw $this->createAccessDeniedException('You do not have access to this report.');
        }
        
        // Execute the report
        $runtimeFilters = $request->query->all('filter') ?? [];
        $results = $this->reportBuilder->executeReport($report, $runtimeFilters);
        
        // Format for chart if needed
        $chartData = null;
        if ($report->isChartReport() && $results['success']) {
            $chartData = $this->reportBuilder->formatForChart($results['data'], $report);
        }
        
        return $this->render('report/show.html.twig', [
            'report' => $report,
            'results' => $results,
            'chartData' => $chartData,
            'dateRangePresets' => ReportDefinition::getDateRangePresets(),
        ]);
    }
    
    #[Route('/{id}/builder', name: 'report_builder', methods: ['GET', 'POST'])]
    public function builder(ReportDefinition $report, Request $request): Response
    {
        if ($report->getCreatedBy()->getId() !== $this->getUser()->getId() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Only the report creator can edit this report.');
        }
        
        $fields = $this->reportBuilder->getFieldsForSource($report->getDataSource());
        
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            
            // Update columns
            if (isset($data['columns'])) {
                $columns = [];
                foreach ($data['columns'] as $col) {
                    if (!empty($col['field'])) {
                        $column = ['field' => $col['field']];
                        if (!empty($col['aggregation'])) {
                            $column['aggregation'] = $col['aggregation'];
                        }
                        if (!empty($col['alias'])) {
                            $column['alias'] = $col['alias'];
                        }
                        $columns[] = $column;
                    }
                }
                $report->setColumns($columns);
            }
            
            // Update filters
            if (isset($data['filters'])) {
                $filters = [];
                foreach ($data['filters'] as $filter) {
                    if (!empty($filter['field']) && !empty($filter['operator'])) {
                        $filters[] = [
                            'field' => $filter['field'],
                            'operator' => $filter['operator'],
                            'value' => $filter['value'] ?? null,
                        ];
                    }
                }
                $report->setFilters($filters);
            }
            
            // Update group by
            if (isset($data['groupBy'])) {
                $report->setGroupBy(array_filter($data['groupBy']));
            }
            
            // Update order by
            if (isset($data['orderBy'])) {
                $orderBy = [];
                foreach ($data['orderBy'] as $order) {
                    if (!empty($order['field'])) {
                        $orderBy[] = [
                            'field' => $order['field'],
                            'direction' => $order['direction'] ?? 'ASC',
                        ];
                    }
                }
                $report->setOrderBy($orderBy);
            }
            
            // Update chart config
            if ($report->isChartReport() && isset($data['chartConfig'])) {
                $report->setChartConfig($data['chartConfig']);
            }
            
            // Update date range
            if (isset($data['dateRangePreset'])) {
                $report->setDateRangePreset($data['dateRangePreset'] ?: null);
            }
            if (isset($data['dateField'])) {
                $report->setDateField($data['dateField'] ?: null);
            }
            if (isset($data['recordLimit'])) {
                $report->setRecordLimit($data['recordLimit'] ? (int) $data['recordLimit'] : null);
            }
            
            $this->em->flush();
            
            if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
                return new JsonResponse(['success' => true]);
            }
            
            $this->addFlash('success', 'Report configuration saved.');
        }
        
        // Get preview data
        $previewResults = $this->reportBuilder->executeReport($report);
        $chartData = null;
        if ($report->isChartReport() && $previewResults['success']) {
            $chartData = $this->reportBuilder->formatForChart($previewResults['data'], $report);
        }
        
        return $this->render('report/builder.html.twig', [
            'report' => $report,
            'fields' => $fields,
            'aggregations' => ReportDefinition::getAggregationTypes(),
            'dateRangePresets' => ReportDefinition::getDateRangePresets(),
            'previewResults' => $previewResults,
            'chartData' => $chartData,
            'suggestions' => $this->reportBuilder->getSuggestedReports($report->getDataSource()),
        ]);
    }
    
    #[Route('/{id}/edit', name: 'report_edit', methods: ['GET', 'POST'])]
    public function edit(ReportDefinition $report, Request $request): Response
    {
        if ($report->getCreatedBy()->getId() !== $this->getUser()->getId() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Only the report creator can edit this report.');
        }
        
        $form = $this->createForm(ReportDefinitionType::class, $report);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            
            $this->addFlash('success', 'Report updated successfully.');
            return $this->redirectToRoute('report_builder', ['id' => $report->getId()]);
        }
        
        return $this->render('report/edit.html.twig', [
            'report' => $report,
            'form' => $form,
            'dataSources' => ReportDefinition::getDataSources(),
            'reportTypes' => ReportDefinition::getReportTypes(),
        ]);
    }
    
    #[Route('/{id}/delete', name: 'report_delete', methods: ['POST'])]
    public function delete(ReportDefinition $report, Request $request): Response
    {
        if ($report->getCreatedBy()->getId() !== $this->getUser()->getId() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Only the report creator can delete this report.');
        }
        
        if ($this->isCsrfTokenValid('delete' . $report->getId(), $request->request->get('_token'))) {
            $this->reportRepository->remove($report, true);
            $this->addFlash('success', 'Report deleted successfully.');
        }
        
        return $this->redirectToRoute('report_index');
    }
    
    #[Route('/{id}/duplicate', name: 'report_duplicate', methods: ['POST'])]
    public function duplicate(ReportDefinition $report, Request $request): Response
    {
        if (!$report->canUserAccess($this->getUser())) {
            throw $this->createAccessDeniedException('You do not have access to this report.');
        }
        
        if ($this->isCsrfTokenValid('duplicate' . $report->getId(), $request->request->get('_token'))) {
            $newName = $report->getName() . ' (Copy)';
            $copy = $this->reportRepository->duplicate($report, $this->getUser(), $newName);
            
            $this->addFlash('success', 'Report duplicated successfully.');
            return $this->redirectToRoute('report_builder', ['id' => $copy->getId()]);
        }
        
        return $this->redirectToRoute('report_show', ['id' => $report->getId()]);
    }
    
    #[Route('/{id}/toggle-favorite', name: 'report_toggle_favorite', methods: ['POST'])]
    public function toggleFavorite(ReportDefinition $report, Request $request): Response
    {
        if (!$report->canUserAccess($this->getUser())) {
            throw $this->createAccessDeniedException();
        }
        
        $report->setIsFavorite(!$report->isFavorite());
        $this->em->flush();
        
        if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            return new JsonResponse([
                'success' => true,
                'isFavorite' => $report->isFavorite(),
            ]);
        }
        
        return $this->redirectToRoute('report_index');
    }
    
    #[Route('/{id}/export', name: 'report_export', methods: ['GET'])]
    public function export(ReportDefinition $report, Request $request): Response
    {
        if (!$report->canUserAccess($this->getUser())) {
            throw $this->createAccessDeniedException();
        }
        
        $format = $request->query->get('format', 'csv');
        $results = $this->reportBuilder->executeReport($report);
        
        if (!$results['success']) {
            $this->addFlash('error', 'Failed to generate report: ' . $results['error']);
            return $this->redirectToRoute('report_show', ['id' => $report->getId()]);
        }
        
        if ($format === 'csv') {
            $csv = $this->reportBuilder->exportToCsv($results['data'], $report);
            
            $response = new Response($csv);
            $response->headers->set('Content-Type', 'text/csv');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $this->sanitizeFilename($report->getName()) . '.csv"');
            
            return $response;
        }
        
        // JSON export
        return new JsonResponse([
            'report' => $report->getName(),
            'generatedAt' => (new \DateTimeImmutable())->format('c'),
            'data' => $results['data'],
        ]);
    }
    
    #[Route('/api/preview', name: 'report_api_preview', methods: ['POST'])]
    public function apiPreview(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['dataSource'])) {
            return new JsonResponse(['error' => 'Data source is required'], 400);
        }

        $dataSource = $data['dataSource'];
        $allowedSources = ['leads', 'companies', 'contacts', 'quotes', 'activities', 'rfqs'];
        if (!in_array($dataSource, $allowedSources, true)) {
            return new JsonResponse(['error' => 'Invalid data source'], 400);
        }
        
        // Create temporary report definition
        $report = new ReportDefinition();
        $report->setDataSource($dataSource);
        $report->setReportType($data['reportType'] ?? ReportDefinition::TYPE_TABLE);
        $report->setColumns($data['columns'] ?? []);
        $report->setFilters($data['filters'] ?? []);
        $report->setGroupBy($data['groupBy'] ?? []);
        $report->setOrderBy($data['orderBy'] ?? []);
        $report->setRecordLimit($data['limit'] ?? 100);
        $report->setCreatedBy($this->getUser());
        
        $results = $this->reportBuilder->executeReport($report);
        
        $chartData = null;
        if ($report->isChartReport() && $results['success']) {
            $chartData = $this->reportBuilder->formatForChart($results['data'], $report);
        }
        
        return new JsonResponse([
            'success' => $results['success'],
            'data' => array_slice($results['data'], 0, 20), // Limit preview
            'totalRows' => count($results['data']),
            'chartData' => $chartData,
            'error' => $results['error'] ?? null,
        ]);
    }
    
    #[Route('/api/fields/{dataSource}', name: 'report_api_fields', methods: ['GET'])]
    public function apiFields(string $dataSource): JsonResponse
    {
        $fields = $this->reportBuilder->getFieldsForSource($dataSource);
        
        if (empty($fields)) {
            return new JsonResponse(['error' => 'Invalid data source'], 400);
        }
        
        return new JsonResponse($fields);
    }
    
    #[Route('/api/operators/{fieldType}', name: 'report_api_operators', methods: ['GET'])]
    public function apiOperators(string $fieldType): JsonResponse
    {
        $operators = $this->reportBuilder->getOperatorsForFieldType($fieldType);
        
        return new JsonResponse($operators);
    }
    
    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }
}
