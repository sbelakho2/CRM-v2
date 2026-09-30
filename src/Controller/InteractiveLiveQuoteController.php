<?php

namespace App\Controller;

use App\Entity\Quote;
use App\Service\InteractiveLiveQuoteService;
use App\Service\RateLimitExceeded;
use App\Service\PricingEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

/**
 * Interactive Live Quote Controller
 * 
 * Handles public (token-based) access to quotes for customers.
 * No authentication required - uses secure tokens instead.
 * 
 * Features:
 * - View quote with dynamic quantity adjustment
 * - Real-time price recalculation
 * - Accept/Request modifications
 * - Lead time estimates
 * 
 * Routes:
 * - GET  /quote/live/{token}              - View interactive quote
 * - GET  /quote/live/{token}/pricing      - Get pricing for all tiers (JSON)
 * - POST /quote/live/{token}/calculate    - Calculate for specific quantity
 * - POST /quote/live/{token}/request      - Request quote at quantity
 * - POST /quote/live/{token}/accept       - Accept quote
 * 
 * Admin Routes (authenticated):
 * - POST /quote/{id}/enable-interactive   - Enable interactive mode
 * - POST /quote/{id}/disable-interactive  - Disable interactive mode
 */
#[Route('/quote')]
class InteractiveLiveQuoteController extends AbstractController
{
    public function __construct(
        private InteractiveLiveQuoteService $liveQuoteService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    /**
     * Validate CSRF token for a JSON POST (header or body). Returns 403 response when invalid.
     */
    private function requireCsrf(Request $request): ?JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token');
        if (!$token) {
            $data = json_decode($request->getContent(), true);
            $token = is_array($data) ? ($data['_token'] ?? null) : null;
        }

        if (!$this->isCsrfTokenValid('quote_live', (string) $token)) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * View interactive quote (public access via token)
     */
    #[Route('/live/{token}', name: 'quote_live_view', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function viewLiveQuote(string $token): Response
    {
        $quote = $this->liveQuoteService->getQuoteByToken($token);
        
        if (!$quote) {
            return $this->render('quote_live/expired.html.twig', [
                'message' => 'This quote link has expired or is invalid.',
            ]);
        }
        
        // Get tier pricing
        $tierPricing = $this->liveQuoteService->calculateTierPricing($quote);

        // Safely resolve company name (company row may have been deleted)
        try {
            $companyName = $quote->getCompany()?->getName() ?? 'N/A';
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            $companyName = 'N/A';
        }

        return $this->render('quote_live/view.html.twig', [
            'quote' => $quote,
            'company_name' => $companyName,
            'tierPricing' => $tierPricing,
            'token' => $token,
        ]);
    }
    
    /**
     * Get pricing data for all tiers (AJAX endpoint)
     */
    #[Route('/live/{token}/pricing', name: 'quote_live_pricing', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function getPricing(string $token): JsonResponse
    {
        $quote = $this->liveQuoteService->getQuoteByToken($token);
        
        if (!$quote) {
            return new JsonResponse([
                'error' => 'Quote not found or expired',
            ], Response::HTTP_NOT_FOUND);
        }
        
        $tierPricing = $this->liveQuoteService->calculateTierPricing($quote);
        
        return new JsonResponse($tierPricing);
    }
    
    /**
     * Calculate pricing for a specific quantity
     */
    #[Route('/live/{token}/calculate', name: 'quote_live_calculate', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function calculateForQuantity(Request $request, string $token): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        $quote = $this->liveQuoteService->getQuoteByToken($token);
        
        if (!$quote) {
            return new JsonResponse([
                'error' => 'Quote not found or expired',
            ], Response::HTTP_NOT_FOUND);
        }
        
        $data = json_decode($request->getContent(), true);
        $quantity = (int) ($data['quantity'] ?? 0);
        
        if ($quantity <= 0) {
            return new JsonResponse([
                'error' => 'Invalid quantity',
            ], Response::HTTP_BAD_REQUEST);
        }
        
        $tierPricing = $this->liveQuoteService->calculateTierPricing($quote);
        
        // Find exact tier or interpolate
        $matchedTier = null;
        foreach ($tierPricing['tiers'] as $tier) {
            if ($tier['quantity'] === $quantity) {
                $matchedTier = $tier;
                break;
            }
        }
        
        // If no exact match, provide closest tier info
        if (!$matchedTier) {
            $closestTier = null;
            $closestDiff = PHP_INT_MAX;
            
            foreach ($tierPricing['tiers'] as $tier) {
                $diff = abs($tier['quantity'] - $quantity);
                if ($diff < $closestDiff) {
                    $closestDiff = $diff;
                    $closestTier = $tier;
                }
            }
            
            $matchedTier = $closestTier;
            $matchedTier['note'] = "Pricing shown for nearest standard tier ({$closestTier['quantity']} units). Request a custom quote for {$quantity} units.";
        }
        
        return new JsonResponse([
            'requested_quantity' => $quantity,
            'pricing' => $matchedTier,
            'available_tiers' => array_column($tierPricing['tiers'], 'quantity'),
        ]);
    }
    
    /**
     * Customer requests quote at specific quantity
     */
    #[Route('/live/{token}/request', name: 'quote_live_request', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function requestQuote(Request $request, string $token): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        $quote = $this->liveQuoteService->getQuoteByToken($token);
        
        if (!$quote) {
            return new JsonResponse([
                'error' => 'Quote not found or expired',
            ], Response::HTTP_NOT_FOUND);
        }
        
        $data = json_decode($request->getContent(), true);
        $quantity = (int) ($data['quantity'] ?? 0);
        $notes = $data['notes'] ?? null;
        
        if ($quantity <= 0) {
            return new JsonResponse([
                'error' => 'Invalid quantity',
            ], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            $result = $this->liveQuoteService->requestQuoteAtQuantity(
                $quote,
                $quantity,
                $notes,
                $token,
                $request->getClientIp(),
                $request->headers->get('User-Agent')
            );
        } catch (RateLimitExceeded $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($result);
    }
    
    /**
     * Customer accepts quote
     */
    #[Route('/live/{token}/accept', name: 'quote_live_accept', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function acceptQuote(Request $request, string $token): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        $quote = $this->liveQuoteService->getQuoteByToken($token);

        if (!$quote) {
            return new JsonResponse([
                'error' => 'Quote not found or expired',
            ], Response::HTTP_NOT_FOUND);
        }

        // Idempotency is handled IN the service: a replay returns the
        // ORIGINAL QuoteAcceptance record with its authoritative data.
        $data = json_decode($request->getContent(), true);
        $quantity = (int) ($data['quantity'] ?? $quote->getQuantity());
        $customerInfo = [
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'po_number' => $data['po_number'] ?? null,
        ];

        try {
            $result = $this->liveQuoteService->acceptQuote($quote, $quantity, $customerInfo, $token);
        } catch (\InvalidArgumentException $e) {
            // Archived quotes are a CONFLICT (409), other validation is a
            // bad request (400) — previously both surfaced as generic 500s
            // or misleading 400s.
            $isArchived = str_contains($e->getMessage(), 'archived');
            return new JsonResponse(
                ['error' => $e->getMessage()],
                $isArchived ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse($result);
    }
    
    // ==================== Admin Routes (Authenticated) ====================
    
    /**
     * Enable interactive mode for a quote
     */
    #[Route('/{id}/enable-interactive', name: 'quote_enable_interactive', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function enableInteractive(Request $request, int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }
        
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        
        if (!$quote) {
            return new JsonResponse([
                'error' => 'Quote not found',
            ], Response::HTTP_NOT_FOUND);
        }
        
        $data = json_decode($request->getContent(), true);
        $quantityTiers = $data['quantity_tiers'] ?? null;
        $expirationDays = $data['expiration_days'] ?? 30;
        
        try {
            $result = $this->liveQuoteService->enableInteractiveMode($quote, $quantityTiers, $expirationDays);
        } catch (\InvalidArgumentException $e) {
            // Archived quotes are read-only: explicit CONFLICT, not a 500.
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        
        return new JsonResponse([
            'success' => true,
            'message' => 'Interactive mode enabled',
            'data' => $result,
        ]);
    }
    
    /**
     * Disable interactive mode for a quote
     */
    #[Route('/{id}/disable-interactive', name: 'quote_disable_interactive', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function disableInteractive(Request $request, int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }
        
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        
        if (!$quote) {
            return new JsonResponse([
                'error' => 'Quote not found',
            ], Response::HTTP_NOT_FOUND);
        }
        
        try {
            $this->liveQuoteService->disableInteractiveMode($quote);
        } catch (\InvalidArgumentException $e) {
            // Archived quotes are read-only: explicit CONFLICT, not a 500.
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        
        return new JsonResponse([
            'success' => true,
            'message' => 'Interactive mode disabled',
        ]);
    }
    
    /**
     * Get interactive quote statistics
     */
    #[Route('/interactive/stats', name: 'quote_interactive_stats', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getInteractiveStats(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $stats = $this->liveQuoteService->getInteractiveQuoteStats();
        
        return new JsonResponse($stats);
    }
    
    /**
     * Regenerate expired token
     */
    #[Route('/{id}/regenerate-token', name: 'quote_regenerate_token', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function regenerateToken(Request $request, int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }
        
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        
        if (!$quote) {
            return new JsonResponse([
                'error' => 'Quote not found',
            ], Response::HTTP_NOT_FOUND);
        }
        
        $data = json_decode($request->getContent(), true);
        $expirationDays = (int) ($data['expiration_days'] ?? 30);

        try {
            $result = $this->liveQuoteService->regenerateToken($quote, $expirationDays);
        } catch (\InvalidArgumentException $e) {
            // Archived quotes are read-only: explicit CONFLICT, not a 500.
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Token regenerated',
            'data' => $result,
        ]);
    }
}
