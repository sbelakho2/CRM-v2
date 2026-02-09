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
        private TranslatorInterface $translator
    ) {}

    /**
     * Portal list with discovery status
     */
    #[Route('', name: 'supplier_portal_index', methods: ['GET'])]
    public function index(): Response
    {
        $suppliers = $this->trackerData->getSuppliers(10);
        $stats = $this->trackerData->getStatistics();
        
        return $this->render('supplier_portal/index.html.twig', [
            'suppliers' => $suppliers,
            'stats' => $stats
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
            $companyId = $request->request->get('company_id', 1); // Default to 1 if not provided
            
            foreach ($discoveredPortals as $portalData) {
                $portal = $this->portalCrawler->createPortal($portalData, $companyId);
                if ($portal) {
                    $createdCount++;
                }
            }
            
            $this->addFlash('success', $this->translator->trans('supplier_portal.flash.portals_discovered', ['%count%' => $createdCount]));
            return $this->redirectToRoute('supplier_portal_index');
            
        } catch (\Exception $e) {
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.discovery_failed', ['%message%' => $e->getMessage()]));
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
        $companyId = $request->request->get('company_id', 1);
        
        // 2. Get pack type
        $packType = $request->request->get('pack_type', 'FULL'); // FULL, QUICK, CUSTOM
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
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.pack_failed', ['%message%' => $e->getMessage()]));
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $id]);
        }
    }

    /**
     * Submit onboarding pack to portal
     */
    #[Route('/{id}/submit', name: 'supplier_portal_submit', methods: ['POST'])]
    public function submit(int $id, Request $request): Response
    {
        // 1. Get pack ID and credentials
        $packId = $request->request->get('pack_id');
        if (!$packId) {
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.pack_id_required'));
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $id]);
        }
        
        // 2. Get credentials if provided
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
                $this->addFlash('success', 'supplier_portal.flash.pack_submitted');
            } else {
                $this->addFlash('warning', 'supplier_portal.flash.submission_pending');
            }
            
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $id]);
            
        } catch (\Exception $e) {
            $this->addFlash('error', 'supplier_portal.flash.submission_failed');
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $id]);
        }
    }

    /**
     * Download onboarding pack PDF
     */
    #[Route('/{portalId}/pack/{packId}/pdf', name: 'supplier_portal_pack_pdf', methods: ['GET'])]
    public function downloadPackPdf(int $portalId, int $packId): Response
    {
        try {
            // 1. Get pack status
            $packStatus = $this->onboardingPack->getPackStatus($packId);
            
            // 2. Check if PDF exists (for demo, create a simple response)
            $pdfPath = sprintf('public/uploads/onboarding/onboarding_pack_%d_%s.pdf', 
                $packId, 
                date('Ymd')
            );
            
            if (!file_exists($pdfPath)) {
                throw $this->createNotFoundException('PDF not yet generated');
            }
            
            // 3. Return PDF response
            return $this->file($pdfPath, sprintf('onboarding_pack_%d.pdf', $packId));
            
        } catch (\Exception $e) {
            $this->addFlash('error', $this->translator->trans('supplier_portal.flash.pdf_failed', ['%message%' => $e->getMessage()]));
            return $this->redirectToRoute('supplier_portal_detail', ['id' => $portalId]);
        }
    }
}
