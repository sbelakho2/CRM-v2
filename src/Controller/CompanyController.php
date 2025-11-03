<?php

namespace App\Controller;

use App\Entity\Company;
use App\Form\CompanyType;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/companies')]
class CompanyController extends AbstractController
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private EntityManagerInterface $entityManager
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

        // Build query
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

        // Get filter options
        $sectors = ['Automotive', 'Industrial', 'Aerospace', 'Rail', 'Renewables', 'Power Electronics'];
        $tiers = ['A', 'B', 'C'];
        $stages = ['Prospect', 'MQL', 'SQL', 'SQO', 'Proposal', 'Award'];
        $regions = ['Morocco - TAC', 'Morocco - TFZ', 'Morocco - AFZ Kenitra', 'Morocco - Casablanca', 'Morocco - Bouskoura', 'EU - Germany', 'EU - France', 'EU - Spain', 'EU - Italy'];

        return $this->render('company/index.html.twig', [
            'companies' => $companies,
            'sectors' => $sectors,
            'tiers' => $tiers,
            'stages' => $stages,
            'regions' => $regions,
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

            $this->addFlash('success', 'Company created successfully!');

            return $this->redirectToRoute('app_company_show', ['id' => $company->getId()]);
        }

        return $this->render('company/new.html.twig', [
            'company' => $company,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_company_show', methods: ['GET'])]
    public function show(Company $company): Response
    {
        // Retrieve all compliance documents for this company
        $documents = $company->getComplianceDocuments();
        
        return $this->render('company/show.html.twig', [
            'company' => $company,
            'documents' => $documents,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_company_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Company $company): Response
    {
        $form = $this->createForm(CompanyType::class, $company);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Company updated successfully!');

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

            $this->addFlash('success', 'Company deleted successfully!');
        }

        return $this->redirectToRoute('app_company_index');
    }
}
