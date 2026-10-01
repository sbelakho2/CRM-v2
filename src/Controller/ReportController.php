<?php

namespace App\Controller;

use App\Entity\ReportDefinition;
use App\Entity\User;
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
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User not authenticated.');
        }
        /** @var string|int|float|bool|null $dataSource */
        $dataSource = $request->query->get('source');
        /** @var string|int|float|bool|null $category */
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
        $newUser = $this->getUser();
        if (!$newUser instanceof User) {
            throw $this->createAccessDeniedException('User not authenticated.');
        }
        $report->setCreatedBy($newUser);
        
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
        $showUser = $this->getUser();
        if (!$showUser instanceof User) {
            throw $this->createAccessDeniedException('You do not have access to this report.');
        }
        if (!$report->canUserAccess($showUser)) {
            throw $this->createAccessDeniedException('You do not have access to this report.');
        }

        // Execute the report
        $runtimeFilters = $request->query->all('filter');
        /** @var array{success: false, error: string, data: list<never>, meta: array<string, mixed>}|array{success: true, data: list<array<string, mixed>>, meta: array<string, mixed>} $results */
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
        if (!$this->canManageReport($report)) {
            $this->addFlash('error', 'Only the report creator can edit this report.');
            return $this->redirectToRoute('report_show', ['id' => $report->getId()]);
        }
        
        $dataSource = $report->getDataSource() ?? '';
        /** @var array<string, array<string, mixed>> $fields */
        $fields = $this->reportBuilder->getFieldsForSource($dataSource);

        // Flat list of {property, label, type} entries for the builder palette
        $availableFields = [];
        foreach ($fields as $property => $field) {
            $availableFields[] = [
                'property' => $property,
                'label' => $field['label'] ?? $property,
                'type' => $field['type'] ?? 'string',
            ];
        }
        
        if ($request->isMethod('POST')) {
            $builderToken = $request->request->get('_token');
            if (!\is_string($builderToken) || !$this->isCsrfTokenValid('report_builder', $builderToken)) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $data = $request->request->all();

            // Update columns. Aliases become DQL result identifiers — only
            // plain identifier shapes are stored; the engine regenerates
            // anything else server-side.
            $columnsParam = $data['columns'] ?? null;
            if (\is_array($columnsParam)) {
                $validColumnFields = array_keys($fields);
                $columns = [];
                foreach ($columnsParam as $col) {
                    if (!\is_array($col)) {
                        continue;
                    }
                    $fieldRaw = $col['field'] ?? '';
                    $field = \is_scalar($fieldRaw) ? (string) $fieldRaw : '';
                    if ($field !== '' && in_array($field, $validColumnFields, true)) {
                        $column = ['field' => $field];
                        if (!empty($col['aggregation'])) {
                            $column['aggregation'] = $col['aggregation'];
                        }
                        if (!empty($col['alias']) && is_string($col['alias'])
                            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col['alias'])) {
                            $column['alias'] = $col['alias'];
                        }
                        $columns[] = $column;
                    }
                }
                $report->setColumns($columns);
            }

            // Update filters. Field + operator are validated against the
            // SAME whitelist the engine enforces at execution time — invalid
            // rows are dropped at save time instead of silently skipped later.
            $filtersParam = $data['filters'] ?? null;
            if (\is_array($filtersParam)) {
                $validFields = array_keys($fields);
                $validOperators = [
                    'equals', 'not_equals', 'contains', 'not_contains', 'starts_with',
                    'ends_with', 'greater_than', 'less_than', 'greater_or_equal',
                    'less_or_equal', 'is_null', 'is_not_null', 'in', 'not_in', 'between',
                ];
                $filters = [];
                foreach ($filtersParam as $filter) {
                    if (!\is_array($filter)) {
                        continue;
                    }
                    $filterFieldRaw = $filter['field'] ?? '';
                    $field = \is_scalar($filterFieldRaw) ? (string) $filterFieldRaw : '';
                    $filterOperatorRaw = $filter['operator'] ?? '';
                    $operator = \is_scalar($filterOperatorRaw) ? (string) $filterOperatorRaw : '';
                    if ($field !== '' && in_array($field, $validFields, true)
                        && in_array($operator, $validOperators, true)) {
                        $filters[] = [
                            'field' => $field,
                            'operator' => $operator,
                            'value' => $filter['value'] ?? null,
                        ];
                    }
                }
                $report->setFilters($filters);
            }

            // Update group by
            $groupByParam = $data['groupBy'] ?? null;
            if (\is_array($groupByParam)) {
                $report->setGroupBy(array_filter($groupByParam));
            }

            // Update order by — direction normalized to ASC|DESC only.
            $orderByParam = $data['orderBy'] ?? null;
            if (\is_array($orderByParam)) {
                $validFields = array_keys($fields);
                $orderBy = [];
                foreach ($orderByParam as $order) {
                    if (!\is_array($order)) {
                        continue;
                    }
                    $orderFieldRaw = $order['field'] ?? '';
                    $field = \is_scalar($orderFieldRaw) ? (string) $orderFieldRaw : '';
                    if ($field !== '' && in_array($field, $validFields, true)) {
                        $directionRaw = $order['direction'] ?? 'ASC';
                        $direction = strtoupper(\is_scalar($directionRaw) ? (string) $directionRaw : 'ASC');
                        $orderBy[] = [
                            'field' => $field,
                            'direction' => in_array($direction, ['ASC', 'DESC'], true) ? $direction : 'ASC',
                        ];
                    }
                }
                $report->setOrderBy($orderBy);
            }

            // Update chart config
            if ($report->isChartReport() && isset($data['chartConfig'])) {
                $chartConfigParam = $data['chartConfig'];
                $report->setChartConfig(\is_array($chartConfigParam) ? $chartConfigParam : null);
            }

            // Update date range
            if (isset($data['dateRangePreset'])) {
                $presetParam = $data['dateRangePreset'];
                $preset = \is_string($presetParam) ? $presetParam : '';
                $report->setDateRangePreset($preset ?: null);
            }
            if (isset($data['dateField'])) {
                // Date-range anchors must be whitelisted date/datetime fields.
                $dateFieldRaw = $data['dateField'];
                $dateField = \is_scalar($dateFieldRaw) ? (string) $dateFieldRaw : '';
                $report->setDateField(
                    $dateField !== '' && $this->reportBuilder->isValidDateField($dateField, $dataSource)
                        ? $dateField
                        : null
                );
            }
            if (isset($data['recordLimit'])) {
                $recordLimitRaw = $data['recordLimit'];
                $report->setRecordLimit(
                    $recordLimitRaw && \is_numeric($recordLimitRaw) ? (int) $recordLimitRaw : null
                );
            }
            
            $this->em->flush();
            
            if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
                return new JsonResponse(['success' => true]);
            }
            
            $this->addFlash('success', 'Report configuration saved.');
        }
        
        // Get preview data (rows normalized for template rendering —
        // DateTime objects and arrays are stringified server-side).
        /** @var array{success: false, error: string, data: list<never>, meta: array<string, mixed>}|array{success: true, data: list<array<string, mixed>>, meta: array<string, mixed>} $previewResults */
        $previewResults = $this->reportBuilder->executeReport($report);
        if (!empty($previewResults['success']) && !empty($previewResults['data'])) {
            $previewResults['data'] = array_map(
                fn (array $row) => array_map(
                    static fn ($value) => match (true) {
                        $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
                        is_array($value) => json_encode($value),
                        is_bool($value) => $value ? 'Yes' : 'No',
                        default => $value,
                    },
                    $row
                ),
                $previewResults['data']
            );
        }
        $chartData = null;
        if ($report->isChartReport() && $previewResults['success']) {
            $chartData = $this->reportBuilder->formatForChart($previewResults['data'], $report);
        }
        
        // Semantic filter controls: operators per field type (date → date
        // comparison ops, boolean → yes/no, numeric → no free text).
        $operatorsByType = [];
        foreach (['string', 'integer', 'decimal', 'datetime', 'boolean'] as $type) {
            $operatorsByType[$type] = $this->reportBuilder->getOperatorsForFieldType($type);
        }

        return $this->render('report/builder.html.twig', [
            'report' => $report,
            'fields' => $fields,
            'availableFields' => $availableFields,
            'aggregations' => ReportDefinition::getAggregationTypes(),
            'operatorsByType' => $operatorsByType,
            'dateRangePresets' => ReportDefinition::getDateRangePresets(),
            'previewResults' => $previewResults,
            'chartData' => $chartData,
            'suggestions' => $this->reportBuilder->getSuggestedReports($dataSource),
        ]);
    }
    
    #[Route('/{id}/edit', name: 'report_edit', methods: ['GET', 'POST'])]
    public function edit(ReportDefinition $report, Request $request): Response
    {
        if (!$this->canManageReport($report)) {
            $this->addFlash('error', 'Only the report creator can edit this report.');
            return $this->redirectToRoute('report_show', ['id' => $report->getId()]);
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
        if (!$this->canManageReport($report)) {
            $this->addFlash('error', 'Only the report creator can delete this report.');
            return $this->redirectToRoute('report_index');
        }
        
        $deleteToken = $request->request->get('_token');
        if (\is_string($deleteToken) && $this->isCsrfTokenValid('delete' . $report->getId(), $deleteToken)) {
            $this->reportRepository->remove($report, true);
            $this->addFlash('success', 'Report deleted successfully.');
        }
        
        return $this->redirectToRoute('report_index');
    }
    
    #[Route('/{id}/duplicate', name: 'report_duplicate', methods: ['POST'])]
    public function duplicate(ReportDefinition $report, Request $request): Response
    {
        $dupUser = $this->getUser();
        if (!$dupUser instanceof User) {
            throw $this->createAccessDeniedException('You do not have access to this report.');
        }
        if (!$report->canUserAccess($dupUser)) {
            throw $this->createAccessDeniedException('You do not have access to this report.');
        }

        $dupToken = $request->request->get('_token');
        if (\is_string($dupToken) && $this->isCsrfTokenValid('duplicate' . $report->getId(), $dupToken)) {
            $newName = $report->getName() . ' (Copy)';
            $copy = $this->reportRepository->duplicate($report, $dupUser, $newName);
            
            $this->addFlash('success', 'Report duplicated successfully.');
            return $this->redirectToRoute('report_builder', ['id' => $copy->getId()]);
        }
        
        return $this->redirectToRoute('report_show', ['id' => $report->getId()]);
    }
    
    #[Route('/{id}/toggle-favorite', name: 'report_toggle_favorite', methods: ['POST'])]
    public function toggleFavorite(ReportDefinition $report, Request $request): Response
    {
        $favUser = $this->getUser();
        if (!$favUser instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$report->canUserAccess($favUser)) {
            throw $this->createAccessDeniedException();
        }

        $token = $request->headers->get('X-CSRF-Token')
            ?? $request->request->get('_token')
            ?? $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('report_toggle_favorite', (string) $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $report->toggleFavoriteBy($favUser);
        $this->em->flush();

        if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            return new JsonResponse([
                'success' => true,
                'isFavorite' => $report->isFavoritedBy($favUser),
            ]);
        }

        return $this->redirectToRoute('report_index');
    }
    
    #[Route('/{id}/export', name: 'report_export', methods: ['GET'])]
    public function export(ReportDefinition $report, Request $request): Response
    {
        $exportUser = $this->getUser();
        if (!$exportUser instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$report->canUserAccess($exportUser)) {
            throw $this->createAccessDeniedException();
        }

        $format = $request->query->get('format', 'csv');
        /** @var array{success: false, error: string, data: list<never>, meta: array<string, mixed>}|array{success: true, data: list<array<string, mixed>>, meta: array<string, mixed>} $results */
        $results = $this->reportBuilder->executeReport($report);
        
        if (!$results['success']) {
            $this->addFlash('error', 'Failed to generate report: ' . $results['error']);
            return $this->redirectToRoute('report_show', ['id' => $report->getId()]);
        }
        
        if ($format === 'csv') {
            $csv = $this->reportBuilder->exportToCsv($results['data'], $report);
            
            $response = new Response($csv);
            $response->headers->set('Content-Type', 'text/csv');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $this->sanitizeFilename($report->getName() ?? 'report') . '.csv"');
            
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
        $apiUser = $this->getUser();
        if (!$apiUser instanceof User) {
            throw $this->createAccessDeniedException('User not authenticated.');
        }

        $token = $request->headers->get('X-CSRF-Token');
        if (!$token) {
            /** @var mixed $body */
            /** @var array<string, mixed>|null $body */
            /** @var array<string, mixed>|null $body */
            $body = json_decode($request->getContent(), true);
            $bodyToken = \is_array($body) ? ($body['_token'] ?? null) : null;
            $token = \is_string($bodyToken) ? $bodyToken : null;
        }
        if (!$this->isCsrfTokenValid('report_api_preview', $token)) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        /** @var mixed $data */
        /** @var array<string, mixed>|null $data */
        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Data source is required'], 400);
        }

        if (!isset($data['dataSource'])) {
            return new JsonResponse(['error' => 'Data source is required'], 400);
        }

        $dataSourceRaw = $data['dataSource'];
        $dataSource = \is_scalar($dataSourceRaw) ? (string) $dataSourceRaw : '';
        // THE canonical source list (ReportBuilderService::ENTITY_MAP) — the
        // previous plural alias list accepted sources the engine then
        // rejected, and 'activities' which was never a source at all.
        if (!$this->reportBuilder->supportsDataSource($dataSource)) {
            return new JsonResponse([
                'error' => 'Invalid data source',
                'supported' => array_keys(ReportDefinition::getDataSources()),
            ], 400);
        }

        // Create temporary report definition
        $report = new ReportDefinition();
        $report->setDataSource($dataSource);
        $reportTypeParam = $data['reportType'] ?? null;
        $report->setReportType(\is_string($reportTypeParam) && $reportTypeParam !== '' ? $reportTypeParam : ReportDefinition::TYPE_TABLE);
        $columnsParam = $data['columns'] ?? null;
        $report->setColumns(\is_array($columnsParam) ? $columnsParam : []);
        $filtersParam = $data['filters'] ?? null;
        $report->setFilters(\is_array($filtersParam) ? $filtersParam : []);
        $groupByParam = $data['groupBy'] ?? null;
        $report->setGroupBy(\is_array($groupByParam) ? $groupByParam : []);
        $orderByParam = $data['orderBy'] ?? null;
        $report->setOrderBy(\is_array($orderByParam) ? $orderByParam : []);
        $limitParam = $data['limit'] ?? null;
        $report->setRecordLimit(\is_numeric($limitParam) ? (int) $limitParam : 100);
        $report->setCreatedBy($apiUser);

        /** @var array{success: false, error: string, data: list<never>, meta: array<string, mixed>}|array{success: true, data: list<array<string, mixed>>, meta: array<string, mixed>} $results */
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
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) ?? $name;
    }

    /**
     * Only the report creator (or an admin) may manage a report.
     * Reports whose creator row was deleted can only be managed by admins.
     */
    private function canManageReport(ReportDefinition $report): bool
    {
        $creator = $report->getCreatedBy();
        if ($creator === null) {
            return $this->isGranted('ROLE_ADMIN');
        }

        $currentUser = $this->getUser();

        return ($currentUser instanceof User && $creator->getId() === $currentUser->getId()) || $this->isGranted('ROLE_ADMIN');
    }
}
