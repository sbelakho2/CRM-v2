<?php

namespace App\Controller;

use App\Entity\Company;
use App\Form\CompanyType;
use App\Repository\CompanyRepository;
use App\Service\ExportService;
use App\Service\GuidanceNotificationService;
use App\Service\CountryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/companies')]
class CompanyController extends AbstractController
{
    public function __construct(
        private CompanyRepository $companyRepository,
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
        $sector = $request->query->get('sector');
        $tier = $request->query->get('tier');
        $stage = $request->query->get('stage');
        $region = $request->query->get('region');
        $search = $request->query->get('search');

        // Build query — only show approved & active companies (NOT discovered)
        $qb = $this->companyRepository->createQueryBuilder('c');
        $qb->andWhere('c.companyStatus IN (:statuses)')
           ->setParameter('statuses', [Company::STATUS_APPROVED, Company::STATUS_ACTIVE]);

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

        // Get filter options
        $sectors = [
            'Automotive',
            'Aerospace',
            'Industrial',
            'Rail',
            'Renewables',
            'Medical',
            'Defense',
            'Telecom',
            'HVAC',
            'Marine',
            'Power Electronics',
            'Consumer Electronics',
            'Data Center',
            'Energy Storage',
            'Other',
        ];
        $tiers = ['A', 'B', 'C'];
        $stages = ['Prospect', 'MQL', 'SQL', 'SQO', 'Proposal', 'Award'];
        $regions = $this->countryService->getRegionOptions([
            'Morocco - TAC' => 'Morocco - TAC',
            'Morocco - TFZ' => 'Morocco - TFZ',
            'Morocco - AFZ Kenitra' => 'Morocco - AFZ Kenitra',
            'Morocco - Casablanca' => 'Morocco - Casablanca',
            'Morocco - Bouskoura' => 'Morocco - Bouskoura',
            'EU - Germany' => 'EU - Germany',
            'EU - France' => 'EU - France',
            'EU - Spain' => 'EU - Spain',
            'EU - Italy' => 'EU - Italy',
        ]);

        return $this->render('company/index.html.twig', [
            'companies' => $companies,
            'sectors' => $sectors,
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
    public function show(Company $company): Response
    {
        // Check for incomplete profile and provide guidance
        $this->guidanceService->checkIncompleteCompanyProfile($company);

        return $this->render('company/show.html.twig', [
            'company' => $company,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_company_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Company $company): Response
    {
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
            $this->entityManager->remove($company);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('company.flash.deleted'));
        }

        // If it was a discovered company, go back to discovered list
        if ($request->request->get('_redirect') === 'discovered') {
            return $this->redirectToRoute('app_company_discovered');
        }

        return $this->redirectToRoute('app_company_index');
    }

    /**
     * List all companies with 'discovered' status — pending human review
     */
    #[Route('/discovered', name: 'app_company_discovered', methods: ['GET'])]
    public function discovered(Request $request): Response
    {
        $sector = $request->query->get('sector');
        $search = $request->query->get('search');

        $qb = $this->companyRepository->createQueryBuilder('c');
        $qb->andWhere('c.companyStatus = :status')
           ->setParameter('status', Company::STATUS_DISCOVERED);

        if ($sector) {
            $qb->andWhere('c.sector = :sector')
               ->setParameter('sector', $sector);
        }

        if ($search) {
            $qb->andWhere('c.name LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $qb->orderBy('c.createdAt', 'DESC');

        $companies = $qb->getQuery()->getResult();

        $sectors = Company::VALID_SECTORS;

        return $this->render('company/discovered.html.twig', [
            'companies' => $companies,
            'sectors' => $sectors,
            'current_sector' => $sector,
            'current_search' => $search,
        ]);
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
        $sector = $request->query->get('sector');
        $tier = $request->query->get('tier');
        $stage = $request->query->get('stage');
        $region = $request->query->get('region');
        $search = $request->query->get('search');

        $qb = $this->companyRepository->createQueryBuilder('c');

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
        $sector = $request->query->get('sector');
        $search = $request->query->get('search');

        $qb = $this->companyRepository->createQueryBuilder('c')
            ->leftJoin('c.contacts', 'ct')
            ->addSelect('ct')
            ->andWhere('c.companyStatus = :status')
            ->setParameter('status', Company::STATUS_DISCOVERED);

        if ($sector) {
            $qb->andWhere('c.sector = :sector')
               ->setParameter('sector', $sector);
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
}
