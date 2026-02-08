<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\ComplianceDocument;
use App\Repository\ComplianceDocumentRepository;
use App\Service\CompliancePackService;
use App\Service\GuidanceNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/compliance')]
#[IsGranted('ROLE_USER')]
class ComplianceController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ComplianceDocumentRepository $complianceDocumentRepository,
        private CompliancePackService $compliancePackService,
        private SluggerInterface $slugger,
        private GuidanceNotificationService $guidanceService
    ) {}

    #[Route('/company/{id}', name: 'app_compliance_company', methods: ['GET'])]
    public function companyCompliance(Company $company): Response
    {
        // Get or generate compliance checklist
        $documents = $this->complianceDocumentRepository->findBy(
            ['company' => $company],
            ['name' => 'ASC']
        );

        // If no documents exist, initialize them
        if (empty($documents)) {
            $this->compliancePackService->initializeCompliancePackForCompany($company);
            $documents = $this->complianceDocumentRepository->findBy(
                ['company' => $company],
                ['name' => 'ASC']
            );
        }

        $stats = $this->compliancePackService->getComplianceStats($company);

        return $this->render('compliance/company.html.twig', [
            'company' => $company,
            'documents' => $documents,
            'stats' => $stats,
        ]);
    }

    #[Route('/document/{id}/upload', name: 'app_compliance_upload', methods: ['POST'])]
    public function uploadDocument(Request $request, ComplianceDocument $document): Response
    {
        $file = $request->files->get('file');

        if ($file) {
            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $newFilename = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

            try {
                $uploadsDirectory = $this->getParameter('kernel.project_dir').'/var/uploads/compliance';
                
                if (!is_dir($uploadsDirectory)) {
                    mkdir($uploadsDirectory, 0777, true);
                }

                $file->move($uploadsDirectory, $newFilename);

                $document->setFilePath($newFilename);
                $document->setProvided(true);
                $document->setUploadedAt(new \DateTime());

                $this->entityManager->flush();

                // Auto-dismiss "add compliance" notification if it exists for this company
                $company = $document->getCompany();
                $companyId = $company->getId();
                $this->guidanceService->autoDismissNotifications("company_{$companyId}_add_compliance");

                // Provide guidance after document upload
                $this->guidanceService->afterComplianceDocumentUploaded(
                    $company->getName(),
                    $companyId
                );

                $this->addFlash('success', 'Document uploaded successfully!');
            } catch (FileException $e) {
                $this->addFlash('error', 'Error uploading file: ' . $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_compliance_company', [
            'id' => $document->getCompany()->getId()
        ]);
    }

    #[Route('/document/{id}/download', name: 'app_compliance_download', methods: ['GET'])]
    public function downloadDocument(ComplianceDocument $document): Response
    {
        if (!$document->getFilePath()) {
            throw $this->createNotFoundException('No file available for this document.');
        }

        $filePath = $this->getParameter('kernel.project_dir').'/var/uploads/compliance/'.$document->getFilePath();

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('File not found.');
        }

        return new BinaryFileResponse($filePath);
    }

    #[Route('/document/{id}/delete', name: 'app_compliance_delete', methods: ['POST'])]
    public function deleteDocument(Request $request, ComplianceDocument $document): Response
    {
        if ($this->isCsrfTokenValid('delete'.$document->getId(), $request->request->get('_token'))) {
            $companyId = $document->getCompany()->getId();

            // Delete physical file
            if ($document->getFilePath()) {
                $filePath = $this->getParameter('kernel.project_dir').'/var/uploads/compliance/'.$document->getFilePath();
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            // Reset document status
            $document->setFilePath(null);
            $document->setProvided(false);
            $document->setUploadedAt(null);
            $this->entityManager->flush();

            $this->addFlash('success', 'Document removed successfully!');
            
            return $this->redirectToRoute('app_compliance_company', ['id' => $companyId]);
        }

        return $this->redirectToRoute('app_company_index');
    }

    #[Route('/document/{id}/toggle-required', name: 'app_compliance_toggle_required', methods: ['POST'])]
    public function toggleRequired(Request $request, ComplianceDocument $document): Response
    {
        if ($this->isCsrfTokenValid('toggle'.$document->getId(), $request->request->get('_token'))) {
            $document->setRequired(!$document->isRequired());
            $this->entityManager->flush();

            $this->addFlash('success', 'Document requirement updated!');
        }

        return $this->redirectToRoute('app_compliance_company', [
            'id' => $document->getCompany()->getId()
        ]);
    }
    
    /**
     * Snooze compliance alerts for a document
     * 
     * Allows users to temporarily suppress alerts for a document while
     * acknowledging that an issue exists (e.g., renewal in progress).
     */
    #[Route('/document/{id}/snooze', name: 'app_compliance_snooze', methods: ['POST'])]
    public function snoozeDocument(Request $request, ComplianceDocument $document): Response
    {
        if ($this->isCsrfTokenValid('snooze'.$document->getId(), $request->request->get('_token'))) {
            $days = (int) $request->request->get('days', 7);
            $reason = $request->request->get('reason', '');
            
            // Validate days (min 1, max 90)
            $days = max(1, min(90, $days));
            
            // Get current user
            $user = $this->getUser();
            $snoozedBy = $user ? $user->getUserIdentifier() : 'unknown';
            
            $document->snooze($days, $reason ?: null, $snoozedBy);
            $this->entityManager->flush();
            
            $this->addFlash('success', sprintf(
                'Alerts snoozed for %d days until %s',
                $days,
                $document->getSnoozedUntil()->format('M j, Y')
            ));
        }
        
        // Redirect back to referrer or company compliance page
        $referer = $request->headers->get('referer');
        if ($referer && str_contains($referer, '/compliance/')) {
            return $this->redirect($referer);
        }
        
        return $this->redirectToRoute('app_compliance_company', [
            'id' => $document->getCompany()->getId()
        ]);
    }
    
    /**
     * Clear snooze for a document, re-enabling alerts immediately
     */
    #[Route('/document/{id}/unsnooze', name: 'app_compliance_unsnooze', methods: ['POST'])]
    public function unsnoozeDocument(Request $request, ComplianceDocument $document): Response
    {
        if ($this->isCsrfTokenValid('unsnooze'.$document->getId(), $request->request->get('_token'))) {
            $document->clearSnooze();
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Snooze cleared - alerts are now active.');
        }
        
        // Redirect back to referrer or company compliance page
        $referer = $request->headers->get('referer');
        if ($referer && str_contains($referer, '/compliance/')) {
            return $this->redirect($referer);
        }
        
        return $this->redirectToRoute('app_compliance_company', [
            'id' => $document->getCompany()->getId()
        ]);
    }

    #[Route('/overview', name: 'app_compliance_overview', methods: ['GET'])]
    public function overview(Request $request): Response
    {
        $sector = $request->query->get('sector');
        
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Company::class, 'c');

        if ($sector) {
            $queryBuilder->where('c.sector = :sector')
                ->setParameter('sector', $sector);
        }

        $companies = $queryBuilder->getQuery()->getResult();

        $complianceData = [];
        foreach ($companies as $company) {
            $stats = $this->compliancePackService->getComplianceStats($company);
            $complianceData[] = [
                'company' => $company,
                'stats' => $stats,
            ];
        }

        // Sort by completion percentage
        usort($complianceData, function($a, $b) {
            return $b['stats']['completion_percentage'] <=> $a['stats']['completion_percentage'];
        });

        return $this->render('compliance/overview.html.twig', [
            'compliance_data' => $complianceData,
            'current_sector' => $sector,
            'sectors' => [
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
            ],
        ]);
    }

    #[Route('/company/{id}/generate-pack', name: 'app_compliance_generate_pack', methods: ['POST'])]
    public function generatePack(Request $request, Company $company): Response
    {
        if ($this->isCsrfTokenValid('generate'.$company->getId(), $request->request->get('_token'))) {
            // Delete existing documents
            $existingDocs = $this->complianceDocumentRepository->findBy(['company' => $company]);
            foreach ($existingDocs as $doc) {
                $this->entityManager->remove($doc);
            }
            $this->entityManager->flush();

            // Generate new pack
            $this->compliancePackService->initializeCompliancePackForCompany($company);

            $this->addFlash('success', 'Compliance pack generated successfully!');
        }

        return $this->redirectToRoute('app_compliance_company', ['id' => $company->getId()]);
    }
}
