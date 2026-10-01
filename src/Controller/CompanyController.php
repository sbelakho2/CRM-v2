<?php

namespace App\Controller;

use App\Entity\Company;
use App\Form\CompanyType;
use App\Repository\ActivityRepository;
use App\Repository\CompanyRepository;
use App\Service\ExportService;
use App\Service\GuidanceNotificationService;
use App\Service\CountryService;
use App\Service\Geo\RegionCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/companies')]
#[IsGranted('ROLE_USER')]
class CompanyController extends AbstractController
{
    private const EU_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE',
        'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    private const GCC_COUNTRY_CODES = ['AE', 'SA', 'QA', 'KW', 'OM', 'BH'];

    public function __construct(
        private CompanyRepository $companyRepository,
        private ActivityRepository $activityRepository,
        private EntityManagerInterface $entityManager,
        private ExportService $exportService,
        private GuidanceNotificationService $guidanceService,
        private CountryService $countryService,
        private TranslatorInterface $translator
    ) {}

    #[Route('', name: 'app_company_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Get filter parameters
        /** @var string|int|float|bool|null $sector */
        $sector = $request->query->get('sector');
        /** @var string|int|float|bool|null $tier */
        $tier = $request->query->get('tier');
        /** @var string|int|float|bool|null $stage */
        $stage = $request->query->get('stage');
        /** @var string|int|float|bool|null $region */
        $region = $request->query->get('region');
        /** @var string|int|float|bool|null $search */
        $search = $request->query->get('search');

        // Build query — only show approved & active companies (NOT discovered)
        $qb = $this->companyRepository->createQueryBuilder('c');
        $qb->andWhere('c.companyStatus IN (:statuses)')
           ->setParameter('statuses', [Company::STATUS_APPROVED, Company::STATUS_ACTIVE])
           ->andWhere('c.archivedAt IS NULL');

        if ($sector) {
            $qb->andWhere('c.sector = :sector')
               ->setParameter('sector', $sector);
        }

        if ($tier) {
            $qb->andWhere('c.accountTier = :tier')
               ->setParameter('tier', $tier);
        }

        if ($stage) {
            $qb->andWhere('c.pipelineStage = :stage')
               ->setParameter('stage', $stage);
        }

        if ($region) {
            $this->applyRegionFilter($qb, $region);
        }

        if ($search) {
            $qb->andWhere('c.name LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $qb->orderBy('c.name', 'ASC');

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 50;
        $qb->setMaxResults($limit)
           ->setFirstResult(($page - 1) * $limit);

        $companies = $qb->getQuery()->getResult();

        // Get filter options
        [$sectors, $sectorLabels] = $this->buildSectorOptions();
        $tiers = ['A', 'B', 'C'];
        $stages = ['Prospect', 'MQL', 'SQL', 'SQO', 'Proposal', 'Award'];
        $regions = $this->buildCompanyRegionOptions();

        return $this->render('company/index.html.twig', [
            'companies' => $companies,
            'sectors' => $sectors,
            'sector_labels' => $sectorLabels,
            'tiers' => $tiers,
            'stages' => $stages,
            'regions' => $regions,
            'region_labels' => $regions,
            'current_sector' => $sector,
            'current_tier' => $tier,
            'current_stage' => $stage,
            'current_region' => $region,
            'current_search' => $search,
        ]);
    }

    #[Route('/new', name: 'app_company_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = new Company();
        $form = $this->createForm(CompanyType::class, $company);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($company);
            $this->entityManager->flush();

            // Provide guidance for next steps
            $this->guidanceService->afterCompanyCreated($company);

            $this->addFlash('success', $this->translator->trans('company.flash.created'));

            return $this->redirectToRoute('app_company_show', ['id' => $company->getId()]);
        }

        return $this->render('company/new.html.twig', [
            'company' => $company,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_company_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        // Load company with contacts/activities/rfqs in one query — the
        // detail template iterates all three collections (avoids N+1 lazy loads)
        $company = $this->companyRepository->findWithDetails($id);

        if (!$company) {
            throw $this->createNotFoundException('Company not found');
        }

        // Archived companies are only reachable by admins (via the archive
        // page); ordinary users get a 404 rather than a live-looking page.
        if ($company->isArchived() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException('Company not found');
        }

        // Check for incomplete profile and provide guidance
        $this->guidanceService->checkIncompleteCompanyProfile($company);
        [, $sectorLabels] = $this->buildSectorOptions();

        // The "Recent Activity" panel shows the five most recent activities,
        // newest first — queried explicitly so ordering is deterministic and
        // independent of collection hydration order.
        $recentActivities = $this->activityRepository->findLatestByCompany($company, 5);

        return $this->render('company/show.html.twig', [
            'company' => $company,
            'recent_activities' => $recentActivities,
            'sector_labels' => $sectorLabels,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_company_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Company $company): Response
    {
        if ($company->isArchived() && !$this->isGranted('ROLE_ADMIN')) {
            $this->addFlash('error', 'This company is archived and cannot be edited.');

            return $this->redirectToRoute('app_company_index');
        }

        $form = $this->createForm(CompanyType::class, $company);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('company.flash.updated'));

            return $this->redirectToRoute('app_company_show', ['id' => $company->getId()]);
        }

        return $this->render('company/edit.html.twig', [
            'company' => $company,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_company_delete', methods: ['POST'])]
    public function delete(Request $request, Company $company): Response
    {
        if ($this->isCsrfTokenValid('delete' . $company->getId(), $request->request->get('_token'))) {
            // Companies are NEVER hard-deleted: their contacts, activities,
            // RFQs, quotes and compliance history would cascade away with
            // them. "Delete" archives instead; a super-admin can restore or
            // purge later through explicit maintenance tooling.
            $company->archive(
                $this->getUser(),
                (string) $request->request->get('reason', 'Archived from company list')
            );
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('company.flash.archived', [
                '%company%' => $company->getName(),
            ]));
        }

        // If it was a discovered company, go back to discovered list
        if ($request->request->get('_redirect') === 'discovered') {
            return $this->redirectToRoute('app_company_discovered');
        }

        return $this->redirectToRoute('app_company_index');
    }

    #[Route('/{id}/restore', name: 'app_company_restore', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function restore(Request $request, Company $company): Response
    {
        if ($this->isCsrfTokenValid('restore' . $company->getId(), $request->request->get('_token'))) {
            $company->restore();
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('company.flash.restored', [
                '%company%' => $company->getName(),
            ]));
        }

        return $this->redirectToRoute('app_company_archive_index');
    }

    /**
     * Admin-only view of archived companies, with restore actions.
     */
    #[Route('/archived', name: 'app_company_archive_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function archived(): Response
    {
        $companies = $this->companyRepository->createQueryBuilder('c')
            ->andWhere('c.archivedAt IS NOT NULL')
            ->orderBy('c.archivedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('company/archived.html.twig', [
            'companies' => $companies,
        ]);
    }

    /**
     * List all companies with 'discovered' status — pending human review
     */
    #[Route('/discovered', name: 'app_company_discovered', methods: ['GET'])]
    public function discovered(Request $request): Response
    {
        /** @var string|int|float|bool|null $sector */
        $sector = $request->query->get('sector');
        /** @var string|int|float|bool|null $region */
        $region = $request->query->get('region');
        /** @var string|int|float|bool|null $search */
        $search = $request->query->get('search');

        $qb = $this->companyRepository->createQueryBuilder('c');
        $qb->andWhere('c.companyStatus = :status')
           ->setParameter('status', Company::STATUS_DISCOVERED)
           ->andWhere('c.archivedAt IS NULL');

        if ($sector) {
            $qb->andWhere('c.sector = :sector')
               ->setParameter('sector', $sector);
        }

        if ($region) {
            $this->applyRegionFilter($qb, $region);
        }

        if ($search) {
            $qb->andWhere('c.name LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $qb->orderBy('c.createdAt', 'DESC');

        $companies = $qb->getQuery()->getResult();

        [$sectors, $sectorLabels] = $this->buildSectorOptions();
        $regions = $this->buildCompanyRegionOptions();

        return $this->render('company/discovered.html.twig', [
            'companies' => $companies,
            'sectors' => $sectors,
            'sector_labels' => $sectorLabels,
            'regions' => $regions,
            'current_sector' => $sector,
            'current_region' => $region,
            'current_search' => $search,
        ]);
    }

    private function buildSectorOptions(): array
    {
        $sectorKeys = [
            'Automotive' => 'company.sectors.automotive',
            'Aerospace' => 'company.sectors.aerospace',
            'Industrial' => 'company.sectors.industrial',
            'Rail' => 'company.sectors.rail',
            'Renewables' => 'company.sectors.renewables',
            'Medical' => 'company.sectors.medical',
            'Defense' => 'company.sectors.defense',
            'Telecom' => 'company.sectors.telecom',
            'HVAC' => 'company.sectors.hvac',
            'Marine' => 'company.sectors.marine',
            'Power Electronics' => 'company.sectors.power_electronics',
            'Consumer Electronics' => 'company.sectors.consumer_electronics',
            'Data Center' => 'company.sectors.data_center',
            'Energy Storage' => 'company.sectors.energy_storage',
            'Other' => 'company.sectors.other',
        ];

        $sectors = [];
        $labels = [];
        foreach ($sectorKeys as $value => $key) {
            $label = $this->translator->trans($key);
            $sectors[] = [
                'value' => $value,
                'label' => $label,
            ];
            $labels[$value] = $label;
        }

        return [$sectors, $labels];
    }

    /**
     * Approve a discovered company — moves it to the company list (but NOT compliance)
     */
    #[Route('/{id}/approve', name: 'app_company_approve', methods: ['POST'])]
    public function approve(Request $request, Company $company): Response
    {
        if ($this->isCsrfTokenValid('approve' . $company->getId(), $request->request->get('_token'))) {
            $company->setCompanyStatus(Company::STATUS_APPROVED);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('"%s" approved and added to your company list.', $company->getName()));
        }

        return $this->redirectToRoute('app_company_discovered');
    }

    /**
     * Activate a company — makes it eligible for the compliance pipeline
     */
    #[Route('/{id}/activate', name: 'app_company_activate', methods: ['POST'])]
    public function activate(Request $request, Company $company): Response
    {
        if ($this->isCsrfTokenValid('activate' . $company->getId(), $request->request->get('_token'))) {
            $company->setCompanyStatus(Company::STATUS_ACTIVE);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('"%s" is now active and eligible for compliance.', $company->getName()));
        }

        return $this->redirectToRoute('app_company_show', ['id' => $company->getId()]);
    }

    /**
     * Approve all discovered companies at once
     */
    #[Route('/approve-all', name: 'app_company_approve_all', methods: ['POST'])]
    public function approveAll(Request $request): Response
    {
        if ($this->isCsrfTokenValid('approve_all', $request->request->get('_token'))) {
            $discovered = $this->companyRepository->findBy(['companyStatus' => Company::STATUS_DISCOVERED]);
            foreach ($discovered as $company) {
                $company->setCompanyStatus(Company::STATUS_APPROVED);
            }
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('%d companies approved and added to your company list.', count($discovered)));
        }

        return $this->redirectToRoute('app_company_discovered');
    }

    #[Route('/export/{format}', name: 'app_company_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(Request $request, string $format): Response
    {
        // Get the same filters as index action
        /** @var string|int|float|bool|null $sector */
        $sector = $request->query->get('sector');
        /** @var string|int|float|bool|null $tier */
        $tier = $request->query->get('tier');
        /** @var string|int|float|bool|null $stage */
        $stage = $request->query->get('stage');
        /** @var string|int|float|bool|null $region */
        $region = $request->query->get('region');
        /** @var string|int|float|bool|null $search */
        $search = $request->query->get('search');

        $qb = $this->companyRepository->createQueryBuilder('c')
            ->andWhere('c.archivedAt IS NULL');

        if ($sector) {
            $qb->andWhere('c.sector = :sector')
               ->setParameter('sector', $sector);
        }

        if ($tier) {
            $qb->andWhere('c.accountTier = :tier')
               ->setParameter('tier', $tier);
        }

        if ($stage) {
            $qb->andWhere('c.pipelineStage = :stage')
               ->setParameter('stage', $stage);
        }

        if ($region) {
            $qb->andWhere('c.region = :region')
               ->setParameter('region', $region);
        }

        if ($search) {
            $qb->andWhere('c.name LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $qb->orderBy('c.name', 'ASC');

        $companies = $qb->getQuery()->getResult();

        // Generate export file
        $filepath = $this->exportService->exportCompanies($companies, $format);

        // Create response
        $response = new BinaryFileResponse($filepath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            basename($filepath)
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * Export discovered companies to Excel with enrichment data (contacts, addresses)
     */
    #[Route('/discovered/export/{format}', name: 'app_company_discovered_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function discoveredExport(Request $request, string $format = 'xlsx'): Response
    {
        /** @var string|int|float|bool|null $sector */
        $sector = $request->query->get('sector');
        /** @var string|int|float|bool|null $region */
        $region = $request->query->get('region');
        /** @var string|int|float|bool|null $search */
        $search = $request->query->get('search');

        $qb = $this->companyRepository->createQueryBuilder('c')
            ->leftJoin('c.contacts', 'ct')
            ->addSelect('ct')
            ->andWhere('c.companyStatus = :status')
            ->setParameter('status', Company::STATUS_DISCOVERED)
            ->andWhere('c.archivedAt IS NULL');

        if ($sector) {
            $qb->andWhere('c.sector = :sector')
               ->setParameter('sector', $sector);
        }

        if ($region) {
            $qb->andWhere('c.region = :region')
               ->setParameter('region', $region);
        }

        if ($search) {
            $qb->andWhere('c.name LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $qb->orderBy('c.createdAt', 'DESC');

        $companies = $qb->getQuery()->getResult();

        $filepath = $this->exportService->exportDiscoveredCompanies($companies, $format);

        $response = new BinaryFileResponse($filepath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            basename($filepath)
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function applyRegionFilter(QueryBuilder $qb, string $region): void
    {
        $selected = trim($region);
        if ($selected === '') {
            return;
        }

        $rawUpper = strtoupper($selected);

        // Round-9: canonical territories match every stored token that means
        // them (slug, legacy coarse code, spelled-out alias) — legacy rows
        // surface under the new canonical filter without data rewrites.
        $territoryTokens = RegionCatalog::filterTokensFor($selected);
        if ($territoryTokens !== null) {
            $qb->andWhere('(
                UPPER(c.region) IN (:territoryTokens)
                OR UPPER(c.country) IN (:territoryTokens)
            )')
               ->setParameter('territoryTokens', $territoryTokens);

            return;
        }

        $normalized = $this->countryService->normalizeRegionCode($selected) ?? $rawUpper;
        $normalizedUpper = strtoupper($normalized);

        if ($normalizedUpper === 'EU_REGION' || $normalizedUpper === 'EU') {
            $qb->andWhere('(
                UPPER(c.region) = :eu
                OR UPPER(c.region) = :euRegion
                OR UPPER(c.region) IN (:euCodes)
                OR UPPER(c.country) IN (:euCodes)
                OR c.region LIKE :euLegacy
                OR UPPER(c.region) = :euRaw
                OR UPPER(c.country) = :euRaw
            )')
               ->setParameter('eu', 'EU')
               ->setParameter('euRegion', 'EU_REGION')
               ->setParameter('euCodes', self::EU_COUNTRY_CODES)
               ->setParameter('euLegacy', 'EU - %')
               ->setParameter('euRaw', $rawUpper);

            return;
        }

        if ($normalizedUpper === 'GCC_REGION' || $normalizedUpper === 'GCC') {
            $qb->andWhere('(
                UPPER(c.region) = :gcc
                OR UPPER(c.region) = :gccRegion
                OR UPPER(c.region) IN (:gccCodes)
                OR UPPER(c.country) IN (:gccCodes)
                OR UPPER(c.region) = :gccRaw
                OR UPPER(c.country) = :gccRaw
            )')
               ->setParameter('gcc', 'GCC')
               ->setParameter('gccRegion', 'GCC_REGION')
               ->setParameter('gccCodes', self::GCC_COUNTRY_CODES)
               ->setParameter('gccRaw', $rawUpper);

            return;
        }

        $qb->andWhere('(
            UPPER(c.region) = :regionFilter
            OR UPPER(c.country) = :regionFilter
            OR UPPER(c.region) = :regionRaw
            OR UPPER(c.country) = :regionRaw
        )')
           ->setParameter('regionFilter', $normalizedUpper)
           ->setParameter('regionRaw', $rawUpper);
    }

    /**
     * Build company page region options from actual stored data.
     * Keeps legacy labels while also exposing normalized ISO/EU/GCC options.
     *
     * @return array<string, string>
     */
    private function buildCompanyRegionOptions(): array
    {
        // Round-9: the filter offers THE canonical territory catalog first —
        // exactly the same list the company form writes, in the same language.
        // Country + US-subdivision options follow (per-country granularity),
        // then any other values found in stored data.
        $options = [];
        foreach (RegionCatalog::territories() as $value => $labelKey) {
            $options[$value] = $this->translator->trans($labelKey);
        }

        // Per-country and US-subdivision granularity (raw ISO codes keep
        // matching through the filter's raw-code branch).
        foreach ($this->countryService->getRegionOptions() as $code => $label) {
            if (!isset($options[$code])) {
                $options[$code] = $label;
            }
        }

        $rows = $this->companyRepository->createQueryBuilder('cr')
            ->select('DISTINCT cr.region AS region, cr.country AS country')
            ->andWhere('(cr.region IS NOT NULL AND cr.region <> :empty) OR (cr.country IS NOT NULL AND cr.country <> :empty)')
            ->setParameter('empty', '')
            ->orderBy('cr.region', 'ASC')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $rawRegion = isset($row['region']) ? trim((string) $row['region']) : '';
            $rawCountry = isset($row['country']) ? trim((string) $row['country']) : '';

            foreach ([$rawRegion, $rawCountry] as $value) {
                if ($value === '') {
                    continue;
                }

                $normalized = $this->countryService->normalizeRegionCode($value);
                if ($normalized === 'EU_REGION') {
                    $normalized = 'EU';
                } elseif ($normalized === 'GCC_REGION') {
                    $normalized = 'GCC';
                }

                if ($normalized !== null) {
                    $options[$normalized] = $this->getRegionLabel($normalized);
                }

                // Keep raw values too, so legacy/custom tags remain directly selectable.
                $options[$value] = $value;
            }
        }

        natcasesort($options);

        return $options;
    }

    private function getRegionLabel(string $regionCode): string
    {
        $upper = strtoupper($regionCode);

        if ($upper === 'EU') {
            return 'Europe';
        }

        if ($upper === 'GCC') {
            return 'GCC';
        }

        return $this->countryService->getRegionName($upper) ?? $regionCode;
    }
}
