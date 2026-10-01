<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\ComplianceDocument;
use App\Repository\ComplianceDocumentRepository;
use App\Service\ComplianceDocumentVersioningService;
use App\Service\CompliancePackService;
use App\Service\GuidanceNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/compliance')]
#[IsGranted('ROLE_USER')]
class ComplianceController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ComplianceDocumentRepository $complianceDocumentRepository,
        private CompliancePackService $compliancePackService,
        private ComplianceDocumentVersioningService $documentVersioningService,
        private SluggerInterface $slugger,
        private GuidanceNotificationService $guidanceService,
        private TranslatorInterface $translator,
        private LoggerInterface $logger
    ) {}

    #[Route('/company/{id}', name: 'app_compliance_company', methods: ['GET'])]
    public function companyCompliance(Company $company): Response
    {
        // Only active companies can enter compliance pipeline
        if (!$company->canEnterCompliance()) {
            $this->addFlash('warning', $this->translator->trans('compliance.flash.company_must_be_active', [
                '%company%' => $company->getName(),
                '%status%' => ucfirst($company->getCompanyStatus()),
            ]));
            return $this->redirectToRoute('app_company_index');
        }

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

    #[Route('/document/{id}/download', name: 'app_compliance_download', methods: ['GET'])]
    public function downloadDocument(ComplianceDocument $document): Response
    {
        $filePath = $this->resolveContainedPath($document->getFilePath());

        if ($filePath === null || !file_exists($filePath)) {
            throw $this->createNotFoundException($this->translator->trans('compliance.error.file_not_found'));
        }

        // Serve as a download (attachment) with an explicit Content-Type so that
        // HTML/SVG payloads can never render inline in the browser
        $contentTypes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        $extension = strtolower(pathinfo($document->getFilePath(), PATHINFO_EXTENSION));

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', $contentTypes[$extension] ?? 'application/octet-stream');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="' . basename($document->getFilePath()) . '"'
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/document/{id}/delete', name: 'app_compliance_delete', methods: ['POST'])]
    public function deleteDocument(Request $request, ComplianceDocument $document): Response
    {
        if ($this->isCsrfTokenValid('delete'.$document->getId(), $request->request->get('_token'))) {
            $companyId = $document->getCompany()->getId();

            // Reset the checklist row. The physical file is deliberately KEPT
            // when version records reference it: version history must never
            // point at deleted bytes. Only an unreferenced file (no version
            // rows) is removed, after the DB change is durably committed.
            $currentFile = $document->getFilePath();
            // Only keep bytes that a version row references BY EXACT
            // FILENAME — "has any versions" would also retain unrelated
            // files forever.
            $isReferencedByVersions = $currentFile !== null
                && $this->documentVersioningService->getVersions($document) !== []
                && in_array($currentFile, array_map(
                    static fn ($v) => $v->getFileName(),
                    $this->documentVersioningService->getVersions($document)
                ), true);

            $document->setFilePath(null);
            $document->setProvided(false);
            $document->setUploadedAt(null);
            $this->entityManager->flush();

            if (!$isReferencedByVersions) {
                $physicalPath = $this->resolveContainedPath($currentFile);
                if ($physicalPath !== null && is_file($physicalPath)) {
                    @unlink($physicalPath);
                }
            }

            $this->addFlash('success', $this->translator->trans('compliance.flash.document_removed'));

            return $this->redirectToRoute('app_compliance_company', ['id' => $companyId]);
        }

        return $this->redirectToRoute('app_company_index');
    }

    #[Route('/document/{id}/upload', name: 'app_compliance_upload', methods: ['POST'])]
    public function uploadDocument(Request $request, ComplianceDocument $document): Response
    {
        if (!$this->isCsrfTokenValid('upload'.$document->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $file = $request->files->get('file');
        if (!$file) {
            $this->addFlash('danger', $this->translator->trans('compliance.error.no_file'));
            return $this->redirectToRoute('app_compliance_company', ['id' => $document->getCompany()->getId()]);
        }

        $uploadDir = $this->getParameter('kernel.project_dir').'/var/uploads/compliance';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        try {
            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);

            // Enforce a strict extension + MIME allowlist (no sniffing, no SVG/HTML)
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
            $allowedMimeTypes = [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
            $extension = strtolower((string) $file->guessExtension());
            if (!in_array($extension, $allowedExtensions, true)) {
                throw new \RuntimeException('Invalid file type');
            }
            $detectedMime = $file->getMimeType();
            if ($detectedMime && $detectedMime !== 'application/octet-stream' && !in_array($detectedMime, $allowedMimeTypes, true)) {
                throw new \RuntimeException('Invalid file content type');
            }

            // Enforce a 10 MB upload limit
            if ($file->getSize() > 10 * 1024 * 1024) {
                throw new \RuntimeException('File too large (max 10 MB)');
            }

            // Compliance documents need unpredictable on-disk names: uniqid() is
                // time-based and guessable. 16 random bytes hex = 128 bits.
                $newFilename = $safeFilename.'-'.bin2hex(random_bytes(16)).'.'.$extension;

            $file->move($uploadDir, $newFilename);

            // Transactional replacement: the DB row is committed before the
            // old physical file is removed, and a failed commit rolls the row
            // back and removes the freshly uploaded file instead — the
            // database and filesystem can never disagree about which file is
            // current. A ComplianceDocumentVersion snapshot preserves history.
            $oldPath = $this->resolveContainedPath($document->getFilePath());

            try {
                $this->entityManager->wrapInTransaction(function () use ($document, $newFilename, $file, $detectedMime): void {
                    $document->setFilePath($newFilename);
                    $document->setProvided(true);
                    $document->setUploadedAt(new \DateTimeImmutable());

                    $this->documentVersioningService->createVersion(
                        $document,
                        $newFilename,
                        (int) $file->getSize(),
                        $detectedMime,
                        $this->getUser()?->getUserIdentifier(),
                        $document->getExpiryDate()
                    );
                });
            } catch (\Throwable $e) {
                // Commit failed: drop the orphaned upload so storage matches
                // the still-unchanged database state.
                @unlink($uploadDir.'/'.$newFilename);
                throw $e;
            }

            // Committed. The previous version's file is deliberately KEPT:
            // ComplianceDocumentVersion rows reference their exact filenames,
            // and deleting bytes would leave version history pointing at
            // nothing. Retention/purge is a separate explicit mechanism.

            $this->guidanceService->afterComplianceDocumentUploaded(
                (string) $document->getCompany()->getName(),
                (int) $document->getCompany()->getId()
            );

            $this->addFlash('success', $this->translator->trans('compliance.flash.document_uploaded', [
                '%name%' => $document->getName(),
            ]));
        } catch (\RuntimeException $e) {
            // Covers both Symfony's FileException (move failures) and the
            // validation RuntimeExceptions thrown above (extension/MIME/size).
            $this->logger->error('Compliance document upload failed', [
                'document_id' => $document->getId(),
                'error' => $e->getMessage(),
            ]);
            $this->addFlash('danger', $this->translator->trans('compliance.error.upload_failed'));
        }

        return $this->redirectToRoute('app_compliance_company', ['id' => $document->getCompany()->getId()]);
    }

    #[Route('/document/{id}/toggle-required', name: 'app_compliance_toggle_required', methods: ['POST'])]
    public function toggleRequired(Request $request, ComplianceDocument $document): Response
    {
        if ($this->isCsrfTokenValid('toggle'.$document->getId(), $request->request->get('_token'))) {
            $document->setRequired(!$document->isRequired());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('compliance.flash.requirement_updated'));
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
            
            $this->addFlash('success', $this->translator->trans('compliance.flash.alerts_snoozed', [
                '%days%' => $days,
                '%until%' => $document->getSnoozedUntil()->format('M j, Y'),
            ]));
        }
        
        // Redirect back to company compliance page
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
            
            $this->addFlash('success', $this->translator->trans('compliance.flash.snooze_cleared'));
        }
        
        // Redirect back to company compliance page
        return $this->redirectToRoute('app_compliance_company', [
            'id' => $document->getCompany()->getId()
        ]);
    }

    #[Route('/overview', name: 'app_compliance_overview', methods: ['GET'])]
    public function overview(Request $request): Response
    {
        /** @var string|int|float|bool|null $sector */
        $sector = $request->query->get('sector');
        
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Company::class, 'c')
            ->andWhere('c.companyStatus = :status')
            ->andWhere('c.archivedAt IS NULL')
            ->setParameter('status', Company::STATUS_ACTIVE);

        if ($sector) {
            $queryBuilder->andWhere('c.sector = :sector')
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

        [$sectors, $sectorLabels] = $this->buildSectorOptions();

        return $this->render('compliance/overview.html.twig', [
            'compliance_data' => $complianceData,
            'current_sector' => $sector,
            'sectors' => $sectors,
            'sector_labels' => $sectorLabels,
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

    #[Route('/company/{id}/generate-pack', name: 'app_compliance_generate_pack', methods: ['POST'])]
    public function generatePack(Request $request, Company $company): Response
    {
        if ($this->isCsrfTokenValid('generate'.$company->getId(), $request->request->get('_token'))) {
            // Reconciliation is idempotent and non-destructive: uploaded
            // files, expiry dates, approval status and document versions are
            // preserved. Never delete existing document rows here.
            $this->compliancePackService->initializeCompliancePackForCompany($company);

            $this->addFlash('success', $this->translator->trans('compliance.flash.pack_generated'));
        }

        return $this->redirectToRoute('app_compliance_company', ['id' => $company->getId()]);
    }

    /**
     * Resolve a stored relative file path to an absolute path contained
     * within the compliance upload directory. Returns null when the stored
     * value is empty; throws when the value escapes the upload directory
     * (legacy/imported rows must not be trusted to contain safe names).
     */
    private function resolveContainedPath(?string $relative): ?string
    {
        if ($relative === null || $relative === '') {
            return null;
        }

        $base = realpath($this->getParameter('kernel.project_dir').'/var/uploads/compliance');
        if ($base === false) {
            return null;
        }

        $candidate = realpath($base.'/'.$relative);

        if ($candidate === false || !str_starts_with($candidate, $base.DIRECTORY_SEPARATOR)) {
            throw $this->createAccessDeniedException('Stored file path escapes the compliance upload directory.');
        }

        return $candidate;
    }
}
