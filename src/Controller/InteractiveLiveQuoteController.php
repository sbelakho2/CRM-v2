<?php

namespace App\Controller;

use App\Entity\Quote;
use App\Service\InteractiveLiveQuoteService;
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
#[IsGranted('ROLE_USER')]
class InteractiveLiveQuoteController extends AbstractController
{
    public function __construct(
        private InteractiveLiveQuoteService $liveQuoteService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    /**
     * View interactive quote (public access via token)
     */
    #[Route('/live/{token}', name: 'quote_live_view', methods: ['GET'])]
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
    public function calculateForQuantity(Request $request, string $token): JsonResponse
    {
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
    public function requestQuote(Request $request, string $token): JsonResponse
    {
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
        
        $result = $this->liveQuoteService->requestQuoteAtQuantity($quote, $quantity, $notes);
        
        return new JsonResponse($result);
    }
    
    /**
     * Customer accepts quote
     */
    #[Route('/live/{token}/accept', name: 'quote_live_accept', methods: ['POST'])]
    public function acceptQuote(Request $request, string $token): JsonResponse
    {
        $quote = $this->liveQuoteService->getQuoteByToken($token);
        
        if (!$quote) {
            return new JsonResponse([
                'error' => 'Quote not found or expired',
            ], Response::HTTP_NOT_FOUND);
        }
        
        $data = json_decode($request->getContent(), true);
        $quantity = (int) ($data['quantity'] ?? $quote->getQuantity());
        $customerInfo = [
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'po_number' => $data['po_number'] ?? null,
        ];
        
        $result = $this->liveQuoteService->acceptQuote($quote, $quantity, $customerInfo);
        
        return new JsonResponse($result);
    }
    
    // ==================== Admin Routes (Authenticated) ====================
    
    /**
     * Enable interactive mode for a quote
     */
    #[Route('/{id}/enable-interactive', name: 'quote_enable_interactive', methods: ['POST'])]
    public function enableInteractive(Request $request, int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        
        if (!$quote) {
            return new JsonResponse([
                'error' => 'Quote not found',
            ], Response::HTTP_NOT_FOUND);
        }
        
        $data = json_decode($request->getContent(), true);
        $quantityTiers = $data['quantity_tiers'] ?? null;
        $expirationDays = $data['expiration_days'] ?? 30;
        
        $result = $this->liveQuoteService->enableInteractiveMode($quote, $quantityTiers, $expirationDays);
        
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
    public function disableInteractive(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        
        if (!$quote) {
            return new JsonResponse([
                'error' => 'Quote not found',
            ], Response::HTTP_NOT_FOUND);
        }
        
        $this->liveQuoteService->disableInteractiveMode($quote);
        
        return new JsonResponse([
            'success' => true,
            'message' => 'Interactive mode disabled',
        ]);
    }
    
    /**
     * Get interactive quote statistics
     */
    #[Route('/interactive/stats', name: 'quote_interactive_stats', methods: ['GET'])]
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
    public function regenerateToken(Request $request, int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        
        if (!$quote) {
            return new JsonResponse([
                'error' => 'Quote not found',
            ], Response::HTTP_NOT_FOUND);
        }
        
        $data = json_decode($request->getContent(), true);
        $expirationDays = $data['expiration_days'] ?? 30;
        
        $result = $this->liveQuoteService->regenerateToken($quote, $expirationDays);
        
        return new JsonResponse([
            'success' => true,
            'message' => 'Token regenerated',
            'data' => $result,
        ]);
    }
}
