<?php

namespace App\Controller;

use App\Entity\SupplierPortal;
use App\Entity\PortalCandidate;
use App\Entity\OnboardingPack;
use App\Service\PortalCrawlerService;
use App\Service\OnboardingPackService;
use App\Service\UnifiedPdfGeneratorService;
use App\Service\TrackerDataService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

/**
 * SupplierPortalController
 * 
 * Supplier portal automation for customer procurement systems.
 * 
 * Features:
 * - Portal discovery from company domains
 * - Compliance checking (robots.txt, TOS)
 * - Vendor platform detection (Ariba, Coupa, Jaggaer, etc.)
 * - Onboarding pack generation (tax docs, capabilities, certifications)
 * - Automated form submission
 * - Submission tracking and status updates
 * 
 * Routes:
 * - GET  /supplier-portal              - Portal list
 * - GET  /supplier-portal/{id}         - Portal detail
 * - POST /supplier-portal/discover     - Discover portal from domain
 * - POST /supplier-portal/{id}/onboard - Generate onboarding pack
 * - POST /supplier-portal/{id}/submit  - Submit onboarding pack
 */
#[Route('/supplier-portal')]
#[IsGranted('ROLE_USER')]
class SupplierPortalController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PortalCrawlerService $portalCrawler,
        private OnboardingPackService $onboardingPack,
        private UnifiedPdfGeneratorService $pdfGenerator,
        private TrackerDataService $trackerData,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {}

    /**
     * Portal list with discovery status
     */
    #[Route('', name: 'supplier_portal_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $suppliers = $this->trackerData->getSuppliers();
        $stats = $this->trackerData->getStatistics();

        $q = trim((string) $request->query->get('q', ''));
        $status = mb_strtolower(trim((string) $request->query->get('status', '')));

        if ('' !== $q || '' !== $status) {
            $suppliers = array_values(array_filter($suppliers, static function (array $supplier) use ($q, $status): bool {
                if ('' !== $status && mb_strtolower((string) ($supplier['status'] ?? '')) !== $status) {
                    return false;
                }
                if ('' !== $q) {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        (string) ($supplier['name'] ?? ''),
                        (string) ($supplier['region'] ?? ''),
                        (string) ($supplier['contact_name'] ?? ''),
                        (string) ($supplier['email'] ?? ''),
                    ])));
                    if (!str_contains($haystack, mb_strtolower($q))) {
                        return false;
                    }
                }

                return true;
            }));
        }

        return $this->render('supplier_portal/index.html.twig', [
            'suppliers' => array_slice($suppliers, 0, 50),
            'stats' => $stats,
            'q' => $q,
            'status' => $status,
        ]);
    }

    /**
     * Portal detail with onboarding history
     */
    #[Route('/{id}', name: 'supplier_portal_detail', methods: ['GET'])]
    public function detail(int $id): Response
    {
        $suppliers = $this->trackerData->getSuppliers();
        $supplier = null;
        
        foreach ($suppliers as $s) {
            if ($s['id'] == $id) {
                $supplier = $s;
                break;
            }
        }
        
        if (!$supplier) {
            throw $this->createNotFoundException('Supplier not found');
        }
        
        return $this->render('supplier_portal/detail.html.twig', [
            'supplier' => $supplier
        ]);
    }

    /**
     * Discover portal from company domain
     */
    #[Route('/discover', name: 'supplier_portal_discover', methods: ['POST'])]
    public function discover(Request $request): Response
    {
        // 1. Get company name and domain from request
        if (!$this->isCsrfTokenValid('supplier_portal_discover', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $companyName = $request->request->get('company_name');
        $domain = $request->request->get('domain');
        
        if (!$companyName) {
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.provide_company'));
            return $this->redirectToRoute('supplier_portal_index');
        }
        
        // 2. Discover portals
        try {
            $discoveredPortals = $this->portalCrawler->discoverPortals($companyName, $domain);
            
            if (empty($discoveredPortals)) {
                $this->addFlash('warning', $this->translator->trans('supplier_portal.flash.no_portals_found'));
                return $this->redirectToRoute('supplier_portal_index');
            }
            
            // 3. Create portal entities for discovered portals
            $createdCount = 0;
            $companyId = $this->requireValidCompanyId($request);
            if ($companyId === null) {
                return $this->redirectToRoute('supplier_portal_index');
            }
            
            foreach ($discoveredPortals as $portalData) {
                $portal = $this->portalCrawler->createPortal($portalData, $companyId);
                if ($portal) {
                    $createdCount++;
                }
            }
            
            $this->addFlash('success', $this->translator->trans('supplier_portal.flash.portals_discovered', ['%count%' => $createdCount]));
            return $this->redirectToRoute('supplier_portal_index');
            
        } catch (\Exception $e) {
            $this->logger->error('Portal discovery failed', ['exception' => $e]);
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.discovery_failed', ['%message%' => 'Operation failed. Please try again.']));
            return $this->redirectToRoute('supplier_portal_index');
        }
    }

    /**
     * Generate onboarding pack for portal
     */
    #[Route('/{id}/onboard', name: 'supplier_portal_onboard', methods: ['POST'])]
    public function onboard(int $id, Request $request): Response
    {
        // 1. Get company ID from request
        if (!$this->isCsrfTokenValid('supplier_portal_onboard', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $companyId = $this->requireValidCompanyId($request);
        if ($companyId === null) {
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $id]);
        }
        
        // 2. Get pack type
        $packType = $request->request->get('pack_type', 'FULL'); // FULL, QUICK, CUSTOM
        if (!in_array($packType, ['FULL', 'QUICK', 'CUSTOM'], true)) {
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.invalid_pack_type'));
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $id]);
        }
        $customFields = $request->request->all('custom_fields') ?? [];
        
        try {
            // 3. Generate onboarding pack
            $packData = $this->onboardingPack->generatePack(
                $companyId,
                $packType,
                $customFields
            );
            
            // 4. Redirect with success message
            $this->addFlash('success', $this->translator->trans('supplier_portal.flash.pack_generated', [
                '%packId%' => $packData['packId'],
                '%fileName%' => basename($packData['pdfPath'])
            ]));
            
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $id]);
            
        } catch (\Exception $e) {
            $this->logger->error('Onboarding pack generation failed', ['exception' => $e]);
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.pack_failed', ['%message%' => 'Operation failed. Please try again.']));
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $id]);
        }
    }

    /**
     * Submit onboarding pack to portal
     */
    #[Route('/{id}/submit', name: 'supplier_portal_submit', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function submit(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('supplier_portal_submit_' . $id, $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // 1. Get pack ID and credentials
        $packId = $request->request->get('pack_id');
        if (!$packId) {
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.pack_id_required'));
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $id]);
        }
        
        // 2. Get credentials if provided
        // Credentials are used ONLY for the transient portal form submission below
        // (HTTP POST to the vendor's login URL). They are never persisted,
        // logged, or echoed back to the client.
        $credentials = [
            'username' => $request->request->get('username'),
            'password' => $request->request->get('password')
        ];
        
        try {
            // 3. Submit pack to portal
            $result = $this->onboardingPack->submitToPortal(
                (int)$packId,
                $id,
                $credentials
            );
            
            // 4. Display result
            if ($result['success']) {
                $this->addFlash('success', $this->translator->trans('supplier_portal.flash.pack_submitted'));
            } else {
                $this->addFlash('warning', $this->translator->trans('supplier_portal.flash.submission_pending'));
            }
            
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $id]);
            
        } catch (\Exception $e) {
            $this->logger->error('Onboarding pack submission failed', ['portal_id' => $id, 'exception' => $e]);
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.submission_failed'));
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $id]);
        }
    }

    /**
     * Download onboarding pack PDF
     */
    #[Route('/{portalId}/pack/{packId}/pdf', name: 'supplier_portal_pack_pdf', methods: ['GET'])]
    public function downloadPackPdf(int $portalId, int $packId): Response
    {
        $portal = $this->entityManager->getRepository(SupplierPortal::class)->find($portalId);
        if (!$portal) {
            throw $this->createNotFoundException('Supplier portal not found');
        }

        $pack = $this->entityManager->getRepository(OnboardingPack::class)->find($packId);
        if (!$pack) {
            throw $this->createNotFoundException('Onboarding pack not found');
        }

        if ($pack->getStatus() === 'PENDING_PDF') {
            $this->addFlash('warning', $this->translator->trans('supplier_portal.flash.pdf_pending'));
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $portalId]);
        }

        // (Re)generate the PDF from the pack entity so the served file is
        // always the real generated artifact — never a guessed path.
        try {
            $pdfPath = $this->pdfGenerator->generateOnboardingPackPdf($pack);
        } catch (\Exception $e) {
            $this->logger->error('Onboarding pack PDF generation failed', ['pack_id' => $packId, 'exception' => $e]);
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.pdf_failed', ['%message%' => 'Operation failed. Please try again.']));
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $portalId]);
        }

        if (!is_file($pdfPath)) {
            $this->logger->error('Onboarding pack PDF missing after generation', ['pack_id' => $packId, 'path' => $pdfPath]);
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.pdf_failed', ['%message%' => 'Operation failed. Please try again.']));
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $portalId]);
        }

        return $this->file($pdfPath, sprintf('onboarding_pack_%d.pdf', $packId));
    }

    /**
     * Validate the company_id POST parameter: it must be present, numeric and
     * reference an existing company. Returns the validated id, or null after
     * flashing an error (caller is responsible for the redirect).
     */
    private function requireValidCompanyId(Request $request): ?int
    {
        $companyId = $request->request->get('company_id');
        if ($companyId === null || !is_numeric($companyId) || (int) $companyId < 1) {
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.company_required'));
            return null;
        }

        $companyId = (int) $companyId;
        $company = $this->entityManager->getRepository(\App\Entity\Company::class)->find($companyId);
        if (!$company) {
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.company_not_found'));
            return null;
        }

        return $companyId;
    }
}
