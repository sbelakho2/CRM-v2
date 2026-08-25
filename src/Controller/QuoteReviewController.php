<?php

namespace App\Controller;

use App\Entity\Quote;
use App\Entity\BomLine;
use App\Repository\QuoteRepository;
use App\Repository\BomLineRepository;
use App\Service\PricingEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Log\LoggerInterface;

/**
 * QuoteReviewController
 * 
 * Handles the Draft Review Workflow for Quote CoPilot.
 * Allows users to:
 * - Review part matches with confidence scores
 * - Verify or reject auto-matched parts
 * - Manually enter prices for unmatched/low-confidence parts
 * - Approve quotes for publishing
 * 
 * Workflow: BOM Upload → Draft Quote → REVIEW → Approved Quote → Published
 */
#[Route('/quote-review')]
#[IsGranted('ROLE_USER')]
class QuoteReviewController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private QuoteRepository $quoteRepository,
        private BomLineRepository $bomLineRepository,
        private PricingEngine $pricingEngine,
        private LoggerInterface $logger,
        private TranslatorInterface $translator
    ) {}

    /**
     * Validate the CSRF token (X-CSRF-Token header, or _token/_csrf_token body field).
     * Returns a 403 JsonResponse when invalid, null when valid.
     */
    private function requireCsrf(Request $request): ?JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token');
        if (!$token) {
            $data = json_decode($request->getContent(), true);
            $token = is_array($data) ? ($data['_token'] ?? $data['_csrf_token'] ?? null) : null;
        }

        if (!$this->isCsrfTokenValid('quote_review', (string) $token)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        return null;
    }

    /**
     * List quotes pending review
     */
    #[Route('', name: 'quote_review_index', methods: ['GET'])]
    public function index(): Response
    {
        // Get quotes that need review (draft status with unverified lines)
        $quotesNeedingReview = $this->quoteRepository->createQueryBuilder('q')
            ->select('q', 'COUNT(b.id) as total_lines', 'SUM(CASE WHEN b.requiresReview = true AND b.manuallyVerified = false THEN 1 ELSE 0 END) as review_count')
            ->leftJoin('q.bomLines', 'b')
            ->leftJoin('q.company', 'c')
            ->addSelect('c')
            ->addSelect('COALESCE(c.name, :missingCompany) AS company_name')
            ->setParameter('missingCompany', $this->translator->trans('common.n_a'))
            ->where('q.status IN (:statuses)')
            ->setParameter('statuses', ['draft', 'pending_review'])
            ->groupBy('q.id')
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('quote_review/index.html.twig', [
            'quotes' => $quotesNeedingReview,
        ]);
    }

    /**
     * Review a specific quote's BOM lines
     */
    #[Route('/{id}', name: 'quote_review_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function review(int $id): Response
    {
        $row = $this->quoteRepository->createQueryBuilder('q')
            ->leftJoin('q.company', 'c')
            ->addSelect('c')
            ->addSelect('COALESCE(c.name, :na) AS company_name')
            ->setParameter('na', $this->translator->trans('common.n_a'))
            ->andWhere('q.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$row) {
            throw $this->createNotFoundException('Quote not found');
        }

        $quote = $row[0] ?? $row;

        $company = $quote->getCompany();
        if (!$company) {
            throw $this->createAccessDeniedException('Quote has no associated company.');
        }
        $companyName = is_array($row) ? ($row['company_name'] ?? null) : null;

        // Get BOM lines grouped by review status
        $bomLines = $this->bomLineRepository->findBy(
            ['quote' => $quote],
            ['requiresReview' => 'DESC', 'confidenceScore' => 'ASC', 'lineNumber' => 'ASC']
        );

        // Calculate review statistics
        $stats = $this->calculateReviewStats($bomLines);

        return $this->render('quote_review/detail.html.twig', [
            'quote' => $quote,
            'company_name' => $companyName,
            'bomLines' => $bomLines,
            'stats' => $stats,
        ]);
    }

    /**
     * Verify a single BOM line match
     */
    #[Route('/line/{id}/verify', name: 'quote_review_verify_line', methods: ['POST'])]
    public function verifyLine(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('quote_review_verify_line_' . $id, $data['_csrf_token'] ?? '')) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        try {
        $bomLine = $this->bomLineRepository->find($id);
        if (!$bomLine) {
            return new JsonResponse(['error' => 'BOM line not found'], 404);
        }

        
        $bomLine->setManuallyVerified(true);
        $bomLine->setRequiresReview(false);
        $bomLine->setVerifiedBy($this->getUser()?->getUserIdentifier() ?? 'system');
        $bomLine->setVerifiedAt(new \DateTime());
        
        if (isset($data['notes'])) {
            $bomLine->setManualNotes($data['notes']);
        }
        
        $bomLine->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        $this->logger->info('BOM line verified', [
            'line_id' => $id,
            'mpn' => $bomLine->getMpn(),
            'verified_by' => $bomLine->getVerifiedBy()
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => 'Part match verified',
            'line' => $this->serializeBomLine($bomLine)
        ]);
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            return new JsonResponse(['success' => false, 'error' => 'Related company has been deleted'], 400);
        }
    }

    /**
     * Apply manual price override to a BOM line
     */
    #[Route('/line/{id}/override', name: 'quote_review_override_line', methods: ['POST'])]
    public function overrideLine(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('quote_review_override_line_' . $id, $data['_csrf_token'] ?? '')) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        try {
        $bomLine = $this->bomLineRepository->find($id);
        if (!$bomLine) {
            return new JsonResponse(['error' => 'BOM line not found'], 404);
        }

        
        if (!isset($data['unit_price']) || !is_numeric($data['unit_price'])) {
            return new JsonResponse(['error' => 'Valid unit_price is required'], 400);
        }

        $unitPrice = (float) $data['unit_price'];
        if ($unitPrice < 0) {
            return new JsonResponse(['error' => 'Price cannot be negative'], 400);
        }

        $bomLine->setManualUnitPrice((string) $unitPrice);
        $bomLine->setExtendedPrice((string) ($unitPrice * $bomLine->getQuantity()));
        $bomLine->setProcurementSource('manual');
        $bomLine->setManuallyVerified(true);
        $bomLine->setRequiresReview(false);
        $bomLine->setVerifiedBy($this->getUser()?->getUserIdentifier() ?? 'system');
        $bomLine->setVerifiedAt(new \DateTime());
        
        if (isset($data['notes'])) {
            $bomLine->setManualNotes($data['notes']);
        }
        
        // Save the source URL for manual prices
        if (isset($data['source_url']) && !empty($data['source_url'])) {
            $bomLine->setPriceSourceUrl($data['source_url']);
        }
        
        // Update confidence to reflect manual verification
        $bomLine->setConfidenceScore(100);
        $bomLine->setConfidenceLevel('HIGH');
        $bomLine->setConfidenceReasons(['Manually priced and verified']);
        $bomLine->setConfidenceWarnings([]);
        
        $bomLine->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        // Recalculate quote totals
        $this->recalculateQuoteTotals($bomLine->getQuote());

        $this->logger->info('BOM line price overridden', [
            'line_id' => $id,
            'mpn' => $bomLine->getMpn(),
            'manual_price' => $unitPrice,
            'verified_by' => $bomLine->getVerifiedBy()
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => 'Price override applied',
            'line' => $this->serializeBomLine($bomLine),
            'quote_totals' => $this->getQuoteTotals($bomLine->getQuote())
        ]);
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            return new JsonResponse(['success' => false, 'error' => 'Related company has been deleted'], 400);
        }
    }

    /**
     * Reject a part match and flag for re-sourcing
     */
    #[Route('/line/{id}/reject', name: 'quote_review_reject_line', methods: ['POST'])]
    public function rejectLine(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('quote_review_reject_line_' . $id, $data['_csrf_token'] ?? '')) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        try {
        $bomLine = $this->bomLineRepository->find($id);
        if (!$bomLine) {
            return new JsonResponse(['error' => 'BOM line not found'], 404);
        }

        // Clear the API-sourced pricing
        $bomLine->setUnitPrice(null);
        $bomLine->setExtendedPrice(null);
        $bomLine->setProcurementSource(null);
        $bomLine->setManuallyVerified(false);
        $bomLine->setRequiresReview(true);
        $bomLine->setHasException(true);
        $bomLine->setExceptionReason($data['reason'] ?? 'Part match rejected by reviewer');
        
        // Update confidence
        $bomLine->setConfidenceScore(0);
        $bomLine->setConfidenceLevel('VERY_LOW');
        $bomLine->setConfidenceReasons(['Auto-match rejected']);
        $bomLine->setConfidenceWarnings(['Manual sourcing required']);
        
        $bomLine->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        // Recalculate quote totals
        $this->recalculateQuoteTotals($bomLine->getQuote());

        $this->logger->warning('BOM line match rejected', [
            'line_id' => $id,
            'mpn' => $bomLine->getMpn(),
            'reason' => $bomLine->getExceptionReason()
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => 'Part match rejected',
            'line' => $this->serializeBomLine($bomLine)
        ]);
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            return new JsonResponse(['success' => false, 'error' => 'Related company has been deleted'], 400);
        }
    }

    /**
     * Bulk verify all high-confidence matches
     */
    #[Route('/{id}/verify-all-high', name: 'quote_review_verify_all_high', methods: ['POST'])]
    public function verifyAllHighConfidence(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('quote_review_verify_all_high_' . $id, $data['_csrf_token'] ?? '')) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        try {
        $quote = $this->quoteRepository->find($id);
        if (!$quote) {
            return new JsonResponse(['error' => 'Quote not found'], 404);
        }

        $verifiedCount = 0;
        $user = $this->getUser()?->getUserIdentifier() ?? 'system';
        $now = new \DateTime();

        foreach ($quote->getBomLines() as $bomLine) {
            if ($bomLine->getConfidenceLevel() === 'HIGH' && 
                !$bomLine->isManuallyVerified() &&
                $bomLine->isRequiresReview()) {
                
                $bomLine->setManuallyVerified(true);
                $bomLine->setRequiresReview(false);
                $bomLine->setVerifiedBy($user);
                $bomLine->setVerifiedAt($now);
                $bomLine->setUpdatedAt($now);
                $verifiedCount++;
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Bulk verified high-confidence matches', [
            'quote_id' => $id,
            'verified_count' => $verifiedCount,
            'verified_by' => $user
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => sprintf('Verified %d high-confidence matches', $verifiedCount),
            'verified_count' => $verifiedCount
        ]);
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            return new JsonResponse(['success' => false, 'error' => 'Related company has been deleted'], 400);
        }
    }

    /**
     * Approve quote for publishing (all lines must be verified or manually priced)
     */
    #[Route('/{id}/approve', name: 'quote_review_approve', methods: ['POST'])]
    public function approveQuote(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('quote_review_approve_' . $id, $data['_csrf_token'] ?? '')) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        try {
        $quote = $this->quoteRepository->find($id);
        if (!$quote) {
            return new JsonResponse(['error' => 'Quote not found'], 404);
        }

        // Check if all lines are verified
        $unverifiedLines = [];
        $unpricedLines = [];

        foreach ($quote->getBomLines() as $bomLine) {
            if ($bomLine->isRequiresReview() && !$bomLine->isManuallyVerified()) {
                $unverifiedLines[] = $bomLine->getMpn() ?? 'Line #' . $bomLine->getLineNumber();
            }
            
            if ($bomLine->getEffectiveUnitPrice() === null) {
                $unpricedLines[] = $bomLine->getMpn() ?? 'Line #' . $bomLine->getLineNumber();
            }
        }

        if (!empty($unverifiedLines)) {
            return new JsonResponse([
                'error' => 'Some lines require review',
                'unverified_lines' => array_slice($unverifiedLines, 0, 5),
                'unverified_count' => count($unverifiedLines)
            ], 400);
        }

        if (!empty($unpricedLines)) {
            return new JsonResponse([
                'error' => 'Some lines have no price',
                'unpriced_lines' => array_slice($unpricedLines, 0, 5),
                'unpriced_count' => count($unpricedLines)
            ], 400);
        }

        // Approve the quote
        $quote->setStatus('approved');
        $quote->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        $this->logger->info('Quote approved', [
            'quote_id' => $id,
            'approved_by' => $this->getUser()?->getUserIdentifier() ?? 'system'
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => 'Quote approved and ready for publishing',
            'redirect' => $this->generateUrl('quote_copilot_results', ['id' => $id])
        ]);
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            return new JsonResponse(['success' => false, 'error' => 'Related company has been deleted'], 400);
        }
    }

    /**
     * Re-run pricing for a specific line (try to find better match)
     */
    #[Route('/line/{id}/reprice', name: 'quote_review_reprice_line', methods: ['POST'])]
    public function repriceLine(int $id, Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        try {
            return $this->doRepriceLine($id, $request);
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            return new JsonResponse(['success' => false, 'error' => 'Related company has been deleted'], 400);
        }
    }

    private function doRepriceLine(int $id, Request $request): JsonResponse
    {
        $bomLine = $this->bomLineRepository->find($id);
        if (!$bomLine) {
            return new JsonResponse(['error' => 'BOM line not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        
        // Allow user to provide a corrected MPN
        $mpnToSearch = $data['mpn'] ?? $bomLine->getOriginalMpn() ?? $bomLine->getMpn();
        $manufacturer = $data['manufacturer'] ?? $bomLine->getManufacturer();
        $description = $bomLine->getBomDescription() ?? $bomLine->getDescription();

        // Re-run pricing engine
        $result = $this->pricingEngine->getPricing($mpnToSearch, $manufacturer, $description);

        if ($result) {
            $bomLine->setMatchedMpn($result['mpn']);
            $bomLine->setManufacturer($result['manufacturer'] ?? $bomLine->getManufacturer());
            $bomLine->setDescription($result['description'] ?? $bomLine->getDescription());
            $bomLine->setUnitPrice((string) $this->pricingEngine->calculateUnitPrice(
                $result['pricing'] ?? [],
                $bomLine->getQuantity()
            ));
            $bomLine->setExtendedPrice((string) ((float)$bomLine->getUnitPrice() * $bomLine->getQuantity()));
            $bomLine->setProcurementSource($result['source']);
            $bomLine->setLeadTimeDays($result['leadtime_days'] ?? null);
            $bomLine->setAvailability($result['stock'] > 0 ? 'In-Stock' : 'Factory');
            
            // Update confidence
            $confidence = $result['confidence'] ?? [];
            $bomLine->setConfidenceScore($confidence['score'] ?? 0);
            $bomLine->setConfidenceLevel($confidence['level'] ?? 'MEDIUM');
            $bomLine->setConfidenceReasons($confidence['reasons'] ?? []);
            $bomLine->setConfidenceWarnings($confidence['warnings'] ?? []);
            $bomLine->setRequiresReview($confidence['requiresReview'] ?? true);
            
            $bomLine->setManuallyVerified(false);
            $bomLine->setHasException(false);
            $bomLine->setExceptionReason(null);
        } else {
            // Still no match found
            $bomLine->setHasException(true);
            $bomLine->setExceptionReason('Part not found with MPN: ' . $mpnToSearch);
            $bomLine->setRequiresReview(true);
            $bomLine->setConfidenceScore(0);
            $bomLine->setConfidenceLevel('VERY_LOW');
        }

        $bomLine->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        // Recalculate quote totals
        $this->recalculateQuoteTotals($bomLine->getQuote());

        return new JsonResponse([
            'success' => true,
            'found' => $result !== null,
            'message' => $result ? 'Pricing found' : 'No pricing found',
            'line' => $this->serializeBomLine($bomLine)
        ]);
    }
    
    /**
     * Select an alternative part from the alternatives list
     * 
     * This allows users to switch to a different part that was found
     * during the multi-distributor search.
     */
    #[Route('/line/{id}/select-alternative', name: 'quote_review_select_alternative', methods: ['POST'])]
    public function selectAlternative(int $id, Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        try {
        $bomLine = $this->bomLineRepository->find($id);
        if (!$bomLine) {
            return new JsonResponse(['error' => 'BOM line not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['mpn'])) {
            return new JsonResponse(['error' => 'MPN is required'], 400);
        }

        $altMpn = $data['mpn'];
        $source = $data['source'] ?? 'mouser';

        // Get pricing for the alternative part from the specified source
        $result = $this->pricingEngine->getPricingFromSource($altMpn, $source, $bomLine->getManufacturer());

        if (!$result) {
            return new JsonResponse([
                'error' => 'Could not get pricing for alternative part',
                'mpn' => $altMpn
            ], 404);
        }

        // Store the original MPN if not already stored
        if (!$bomLine->getOriginalMpn()) {
            $bomLine->setOriginalMpn($bomLine->getMpn());
        }

        // Update the BOM line with the alternative
        $bomLine->setMatchedMpn($result['mpn']);
        $bomLine->setMpn($altMpn); // Update the current MPN
        
        if (isset($result['manufacturer'])) {
            $bomLine->setManufacturer($result['manufacturer']);
        }
        if (isset($result['description'])) {
            $bomLine->setDescription($result['description']);
        }
        
        // Calculate pricing
        $unitPrice = $this->calculateUnitPriceFromPricing($result['pricing'] ?? [], $bomLine->getQuantity());
        $bomLine->setUnitPrice((string) $unitPrice);
        $bomLine->setExtendedPrice((string) ($unitPrice * $bomLine->getQuantity()));
        $bomLine->setProcurementSource($source);
        
        // Update availability
        if (isset($result['leadtime_days'])) {
            $bomLine->setLeadTimeDays($result['leadtime_days']);
        }
        $bomLine->setAvailability(($result['stock'] ?? 0) > 0 ? 'In-Stock' : 'Factory');
        
        // Update confidence (manual selection = verified)
        $bomLine->setConfidenceScore(95); // High confidence since manually selected
        $bomLine->setConfidenceLevel('HIGH');
        $bomLine->setConfidenceReasons([
            'Alternative part manually selected by reviewer',
            'Original MPN: ' . ($bomLine->getOriginalMpn() ?? 'N/A')
        ]);
        $bomLine->setConfidenceWarnings([]);
        $bomLine->setRequiresReview(false);
        
        // Update lifecycle info if available
        if (isset($result['lifecycle_warning'])) {
            $bomLine->setLifecycleWarning($result['lifecycle_warning']);
        }
        if (isset($result['lifecycle_status'])) {
            $bomLine->setLifecycleStatus($result['lifecycle_status']);
        }
        
        // Store search URL for transparency
        if (isset($result['search_url'])) {
            $bomLine->setDistributorSearchUrl($result['search_url']);
        }
        
        // Update source URL if provided
        if (isset($result['product_url'])) {
            $bomLine->setPriceSourceUrl($result['product_url']);
        }
        
        // Mark as verified since user made explicit selection
        $bomLine->setManuallyVerified(true);
        $bomLine->setVerifiedBy($this->getUser()?->getUserIdentifier() ?? 'system');
        $bomLine->setVerifiedAt(new \DateTime());
        $bomLine->setManualNotes('Alternative selected: ' . $altMpn . ' from ' . $source);
        
        $bomLine->setHasException(false);
        $bomLine->setExceptionReason(null);
        $bomLine->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();

        // Recalculate quote totals
        $this->recalculateQuoteTotals($bomLine->getQuote());

        $this->logger->info('Alternative part selected', [
            'line_id' => $id,
            'original_mpn' => $bomLine->getOriginalMpn(),
            'new_mpn' => $altMpn,
            'source' => $source,
            'unit_price' => $unitPrice,
            'selected_by' => $bomLine->getVerifiedBy()
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => 'Alternative part selected: ' . $altMpn,
            'line' => $this->serializeBomLine($bomLine),
            'quote_totals' => $this->getQuoteTotals($bomLine->getQuote())
        ]);
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            return new JsonResponse(['success' => false, 'error' => 'Related company has been deleted'], 400);
        }
    }
    
    /**
     * Helper to calculate unit price from price breaks
     */
    private function calculateUnitPriceFromPricing(array $priceBreaks, int $quantity): float
    {
        if (empty($priceBreaks)) {
            return 0.0;
        }
        
        usort($priceBreaks, fn($a, $b) => $a['quantity'] <=> $b['quantity']);
        $applicablePrice = $priceBreaks[0]['price'];
        
        foreach ($priceBreaks as $break) {
            if ($quantity >= $break['quantity']) {
                $applicablePrice = $break['price'];
            } else {
                break;
            }
        }
        
        return (float) $applicablePrice;
    }

    /**
     * Calculate review statistics for a set of BOM lines
     */
    private function calculateReviewStats(array $bomLines): array
    {
        $stats = [
            'total_lines' => count($bomLines),
            'verified_count' => 0,
            'requires_review_count' => 0,
            'no_price_count' => 0,
            'high_confidence' => 0,
            'medium_confidence' => 0,
            'low_confidence' => 0,
            'very_low_confidence' => 0,
            'total_value' => 0.0,
            'sourced_value' => 0.0,
            // Lifecycle warning counts
            'lifecycle_critical' => 0,
            'lifecycle_warning' => 0,
            // Alternative parts count
            'with_alternatives' => 0,
        ];

        foreach ($bomLines as $line) {
            if ($line->isManuallyVerified()) {
                $stats['verified_count']++;
            }
            
            if ($line->isRequiresReview() && !$line->isManuallyVerified()) {
                $stats['requires_review_count']++;
            }
            
            if ($line->getEffectiveUnitPrice() === null) {
                $stats['no_price_count']++;
            }
            
            switch ($line->getConfidenceLevel()) {
                case 'HIGH':
                    $stats['high_confidence']++;
                    break;
                case 'MEDIUM':
                    $stats['medium_confidence']++;
                    break;
                case 'LOW':
                    $stats['low_confidence']++;
                    break;
                case 'VERY_LOW':
                    $stats['very_low_confidence']++;
                    break;
            }
            
            $effectivePrice = $line->getEffectiveExtendedPrice();
            if ($effectivePrice !== null) {
                $stats['sourced_value'] += $effectivePrice;
            }
            
            // Track lifecycle warnings
            $lifecycleWarning = $line->getLifecycleWarning();
            if ($lifecycleWarning === 'critical') {
                $stats['lifecycle_critical']++;
            } elseif ($lifecycleWarning === 'warning') {
                $stats['lifecycle_warning']++;
            }
            
            // Track alternatives
            if ($line->hasAlternatives()) {
                $stats['with_alternatives']++;
            }
        }

        $stats['verification_percent'] = $stats['total_lines'] > 0
            ? round(($stats['verified_count'] / $stats['total_lines']) * 100, 1)
            : 0;

        $stats['ready_for_approval'] = 
            $stats['requires_review_count'] === 0 && 
            $stats['no_price_count'] === 0;
            
        // Lifecycle health percentage
        $stats['lifecycle_health_percent'] = $stats['total_lines'] > 0
            ? round((($stats['total_lines'] - $stats['lifecycle_critical'] - $stats['lifecycle_warning']) / $stats['total_lines']) * 100, 1)
            : 100;

        return $stats;
    }

    /**
     * Serialize a BOM line for JSON response
     */
    private function serializeBomLine(BomLine $line): array
    {
        return [
            'id' => $line->getId(),
            'line_number' => $line->getLineNumber(),
            'mpn' => $line->getMpn(),
            'original_mpn' => $line->getOriginalMpn(),
            'matched_mpn' => $line->getMatchedMpn(),
            'manufacturer' => $line->getManufacturer(),
            'description' => $line->getDescription(),
            'quantity' => $line->getQuantity(),
            'unit_price' => $line->getUnitPrice(),
            'manual_unit_price' => $line->getManualUnitPrice(),
            'effective_unit_price' => $line->getEffectiveUnitPrice(),
            'extended_price' => $line->getExtendedPrice(),
            'effective_extended_price' => $line->getEffectiveExtendedPrice(),
            'source' => $line->getProcurementSource(),
            'confidence_score' => $line->getConfidenceScore(),
            'confidence_level' => $line->getConfidenceLevel(),
            'confidence_reasons' => $line->getConfidenceReasons(),
            'confidence_warnings' => $line->getConfidenceWarnings(),
            'requires_review' => $line->isRequiresReview(),
            'manually_verified' => $line->isManuallyVerified(),
            'verified_by' => $line->getVerifiedBy(),
            'verified_at' => $line->getVerifiedAt()?->format('Y-m-d H:i:s'),
            'manual_notes' => $line->getManualNotes(),
            'has_exception' => $line->isHasException(),
            'exception_reason' => $line->getExceptionReason(),
            'lead_time_days' => $line->getLeadTimeDays(),
            'availability' => $line->getAvailability(),
            // New fields for lifecycle and alternatives
            'lifecycle_status' => $line->getLifecycleStatus(),
            'lifecycle_warning' => $line->getLifecycleWarning(),
            'alternative_parts' => $line->getAlternativeParts(),
            'has_alternatives' => $line->hasAlternatives(),
            'alternative_count' => $line->getAlternativeCount(),
            'price_source_url' => $line->getPriceSourceUrl(),
            'distributor_search_url' => $line->getDistributorSearchUrl(),
        ];
    }

    /**
     * Recalculate quote totals after line changes
     */
    private function recalculateQuoteTotals(Quote $quote): void
    {
        $totalCost = 0.0;
        $sourcedCount = 0;
        $totalLines = 0;

        foreach ($quote->getBomLines() as $line) {
            $totalLines++;
            $effectivePrice = $line->getEffectiveExtendedPrice();
            if ($effectivePrice !== null) {
                $totalCost += $effectivePrice;
                $sourcedCount++;
            }
        }

        $quote->setTotalCost((string) round($totalCost, 2));
        $quote->setCoveragePercent((string) ($totalLines > 0 ? round(($sourcedCount / $totalLines) * 100, 2) : 0));
        $quote->setUpdatedAt(new \DateTime());
    }

    /**
     * Get quote totals for JSON response
     */
    private function getQuoteTotals(Quote $quote): array
    {
        return [
            'total_cost' => $quote->getTotalCost(),
            'coverage_percent' => $quote->getCoveragePercent(),
            'currency' => $quote->getCurrency(),
        ];
    }
}
