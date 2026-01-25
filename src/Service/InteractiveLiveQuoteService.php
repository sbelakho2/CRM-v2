<?php

namespace App\Service;

use App\Entity\Quote;
use App\Entity\BomLine;
use App\Repository\QuoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Interactive Live Quote Service
 * 
 * Enables customers to view and interact with quotes in real-time:
 * - Secure token-based access (no login required)
 * - Dynamic quantity adjustment with instant price recalculation
 * - Price break visualization
 * - Lead time estimates per quantity tier
 * - Accept/Request Changes workflow
 * 
 * Security:
 * - Cryptographically secure tokens (256-bit)
 * - Token expiration (configurable, default 30 days)
 * - Rate limiting on API endpoints
 * - View tracking and audit logging
 * 
 * Example workflow:
 * 1. Sales rep generates shareable link for a quote
 * 2. Customer opens link (no login needed)
 * 3. Customer toggles between quantity tiers (100, 500, 1000, 5000)
 * 4. Prices update dynamically based on price breaks
 * 5. Customer can accept or request modifications
 */
class InteractiveLiveQuoteService
{
    // Default quantity tiers for interactive quotes
    private const DEFAULT_QUANTITY_TIERS = [100, 250, 500, 1000, 2500, 5000, 10000];
    
    // Maximum token validity in days
    private const MAX_TOKEN_VALIDITY_DAYS = 90;
    
    public function __construct(
        private EntityManagerInterface $entityManager,
        private QuoteRepository $quoteRepository,
        private PricingEngine $pricingEngine,
        private LoggerInterface $logger
    ) {}
    
    /**
     * Enable interactive mode for a quote and generate public token
     * 
     * @param Quote $quote The quote to enable
     * @param array|null $quantityTiers Custom quantity tiers (or use defaults)
     * @param int $expirationDays Token validity period
     * @return array{token: string, url: string, expires_at: string}
     */
    public function enableInteractiveMode(
        Quote $quote,
        ?array $quantityTiers = null,
        int $expirationDays = 30
    ): array {
        // Validate expiration
        $expirationDays = min($expirationDays, self::MAX_TOKEN_VALIDITY_DAYS);
        
        // Generate secure token
        $quote->generatePublicToken($expirationDays);
        $quote->setInteractiveEnabled(true);
        
        // Set quantity tiers
        $tiers = $quantityTiers ?? self::DEFAULT_QUANTITY_TIERS;
        
        // Ensure current quantity is included in tiers
        $currentQty = $quote->getQuantity();
        if ($currentQty && !in_array($currentQty, $tiers)) {
            $tiers[] = $currentQty;
            sort($tiers);
        }
        
        $quote->setQuantityOptions($tiers);
        
        $this->entityManager->flush();
        
        $this->logger->info('Interactive mode enabled for quote', [
            'quote_id' => $quote->getId(),
            'quote_number' => $quote->getQuoteNumber(),
            'expires_at' => $quote->getTokenExpiresAt()->format('c'),
            'tiers' => $tiers,
        ]);
        
        return [
            'token' => $quote->getPublicToken(),
            'url' => '/quote/live/' . $quote->getPublicToken(),
            'expires_at' => $quote->getTokenExpiresAt()->format('c'),
            'quantity_tiers' => $tiers,
        ];
    }
    
    /**
     * Disable interactive mode and revoke token
     */
    public function disableInteractiveMode(Quote $quote): void
    {
        $quote->setPublicToken(null);
        $quote->setTokenExpiresAt(null);
        $quote->setInteractiveEnabled(false);
        
        $this->entityManager->flush();
        
        $this->logger->info('Interactive mode disabled for quote', [
            'quote_id' => $quote->getId(),
        ]);
    }
    
    /**
     * Get quote by public token (for customer access)
     * 
     * @param string $token The public access token
     * @return Quote|null The quote if valid, null otherwise
     */
    public function getQuoteByToken(string $token): ?Quote
    {
        $quote = $this->quoteRepository->findOneBy(['publicToken' => $token]);
        
        if (!$quote) {
            return null;
        }
        
        // Validate token hasn't expired
        if (!$quote->isTokenValid()) {
            $this->logger->warning('Attempted access with expired token', [
                'quote_id' => $quote->getId(),
                'expired_at' => $quote->getTokenExpiresAt()?->format('c'),
            ]);
            return null;
        }
        
        // Track view
        $quote->incrementViewCount();
        $this->entityManager->flush();
        
        return $quote;
    }
    
    /**
     * Calculate pricing for all available quantity tiers
     * 
     * @param Quote $quote The quote to calculate
     * @return array Pricing for each tier with details
     */
    public function calculateTierPricing(Quote $quote): array
    {
        $tiers = $quote->getQuantityOptions() ?? self::DEFAULT_QUANTITY_TIERS;
        $bomLines = $quote->getBomLines();
        $currency = $quote->getCurrency();
        
        // Get BOM data (either from entity or stored JSON)
        $bomData = $this->extractBomData($quote);
        
        $tierPricing = [];
        
        foreach ($tiers as $quantity) {
            $tierResult = $this->calculatePricingForQuantity($bomData, $quantity);
            
            $tierPricing[] = [
                'quantity' => $quantity,
                'unit_price' => round($tierResult['unit_total'], 4),
                'extended_price' => round($tierResult['extended_total'], 2),
                'per_unit_breakdown' => $tierResult['per_unit_breakdown'],
                'savings_vs_minimum' => $this->calculateSavings($tierPricing, $tierResult),
                'lead_time_estimate' => $this->estimateLeadTime($quantity, $bomData),
                'stock_status' => $tierResult['stock_status'],
                'currency' => $currency,
            ];
        }
        
        // Add price break recommendations
        foreach ($tierPricing as $index => &$tier) {
            if ($index < count($tierPricing) - 1) {
                $nextTier = $tierPricing[$index + 1];
                $tier['next_break'] = [
                    'quantity' => $nextTier['quantity'],
                    'additional_qty' => $nextTier['quantity'] - $tier['quantity'],
                    'new_unit_price' => $nextTier['unit_price'],
                    'savings_per_unit' => round($tier['unit_price'] - $nextTier['unit_price'], 4),
                ];
            }
        }
        
        return [
            'quote_number' => $quote->getQuoteNumber(),
            'company' => $quote->getCompany()?->getName(),
            'base_quantity' => $quote->getQuantity(),
            'currency' => $currency,
            'tiers' => $tierPricing,
            'bom_line_count' => count($bomData),
            'valid_until' => $quote->getTokenExpiresAt()?->format('Y-m-d'),
            'interactive_enabled' => $quote->isInteractiveEnabled(),
        ];
    }
    
    /**
     * Calculate pricing for a specific quantity
     */
    private function calculatePricingForQuantity(array $bomData, int $quantity): array
    {
        $unitTotal = 0.0;
        $extendedTotal = 0.0;
        $perUnitBreakdown = [];
        $allInStock = true;
        
        foreach ($bomData as $line) {
            $priceBreaks = $line['pricing'] ?? [];
            $lineQty = ($line['quantity_per_unit'] ?? 1) * $quantity;
            
            // Get unit price for this quantity
            $unitPrice = $this->getUnitPriceForQuantity($priceBreaks, $lineQty);
            $lineExtended = $unitPrice * $lineQty;
            
            // Track per-assembly-unit cost
            $perUnitCost = $unitPrice * ($line['quantity_per_unit'] ?? 1);
            $unitTotal += $perUnitCost;
            $extendedTotal += $lineExtended;
            
            $stock = $line['stock'] ?? 0;
            if ($stock < $lineQty) {
                $allInStock = false;
            }
            
            $perUnitBreakdown[] = [
                'mpn' => $line['mpn'] ?? 'Unknown',
                'description' => $line['description'] ?? '',
                'qty_per_unit' => $line['quantity_per_unit'] ?? 1,
                'unit_price' => round($unitPrice, 5),
                'extended' => round($lineExtended, 2),
                'stock_available' => $stock,
                'sufficient_stock' => $stock >= $lineQty,
            ];
        }
        
        return [
            'unit_total' => $unitTotal,
            'extended_total' => $extendedTotal,
            'per_unit_breakdown' => $perUnitBreakdown,
            'stock_status' => $allInStock ? 'all_in_stock' : 'partial_stock',
        ];
    }
    
    /**
     * Get unit price based on price breaks
     */
    private function getUnitPriceForQuantity(array $priceBreaks, int $quantity): float
    {
        if (empty($priceBreaks)) {
            return 0.0;
        }
        
        usort($priceBreaks, fn($a, $b) => ($a['quantity'] ?? 0) <=> ($b['quantity'] ?? 0));
        
        $applicablePrice = $priceBreaks[0]['price'] ?? 0;
        
        foreach ($priceBreaks as $break) {
            if ($quantity >= ($break['quantity'] ?? 0)) {
                $applicablePrice = $break['price'] ?? $applicablePrice;
            }
        }
        
        return (float) $applicablePrice;
    }
    
    /**
     * Calculate savings compared to minimum quantity tier
     */
    private function calculateSavings(array $existingTiers, array $currentResult): array
    {
        if (empty($existingTiers)) {
            return [
                'amount' => 0,
                'percent' => 0,
            ];
        }
        
        $minTier = $existingTiers[0];
        $minUnitPrice = $minTier['unit_price'];
        $currentUnitPrice = $currentResult['unit_total'];
        
        if ($minUnitPrice <= 0) {
            return ['amount' => 0, 'percent' => 0];
        }
        
        $savingsPerUnit = $minUnitPrice - $currentUnitPrice;
        $savingsPercent = ($savingsPerUnit / $minUnitPrice) * 100;
        
        return [
            'amount' => round($savingsPerUnit, 4),
            'percent' => round($savingsPercent, 1),
        ];
    }
    
    /**
     * Estimate lead time based on quantity and stock levels
     */
    private function estimateLeadTime(int $quantity, array $bomData): array
    {
        $maxLeadTimeDays = 0;
        $constrainingPart = null;
        
        foreach ($bomData as $line) {
            $lineQty = ($line['quantity_per_unit'] ?? 1) * $quantity;
            $stock = $line['stock'] ?? 0;
            $standardLeadTime = $line['leadtime_days'] ?? 14;
            
            $effectiveLeadTime = $standardLeadTime;
            
            // If stock is insufficient, add procurement time
            if ($stock < $lineQty) {
                $shortfall = $lineQty - $stock;
                // Estimate additional lead time based on shortfall
                $additionalDays = min(60, (int)($shortfall / 100) + 14);
                $effectiveLeadTime = $standardLeadTime + $additionalDays;
            }
            
            if ($effectiveLeadTime > $maxLeadTimeDays) {
                $maxLeadTimeDays = $effectiveLeadTime;
                $constrainingPart = $line['mpn'] ?? 'Unknown';
            }
        }
        
        // Add assembly time
        $assemblyDays = 5 + (int)($quantity / 500); // Base 5 days + volume factor
        $totalLeadTime = $maxLeadTimeDays + $assemblyDays;
        
        return [
            'total_days' => $totalLeadTime,
            'procurement_days' => $maxLeadTimeDays,
            'assembly_days' => $assemblyDays,
            'constraining_part' => $constrainingPart,
            'estimate_date' => (new \DateTime())->modify("+{$totalLeadTime} days")->format('Y-m-d'),
            'confidence' => $maxLeadTimeDays <= 21 ? 'high' : ($maxLeadTimeDays <= 42 ? 'medium' : 'low'),
        ];
    }
    
    /**
     * Extract BOM data from quote
     */
    private function extractBomData(Quote $quote): array
    {
        // First try to get from BOM lines entity
        $bomLines = $quote->getBomLines();
        
        if ($bomLines->count() > 0) {
            $data = [];
            foreach ($bomLines as $line) {
                $data[] = [
                    'mpn' => $line->getMpn(),
                    'manufacturer' => $line->getManufacturer(),
                    'description' => $line->getDescription(),
                    'quantity_per_unit' => $line->getQuantity(),
                    'pricing' => $line->getPricing() ?? [],
                    'stock' => $line->getStock() ?? 0,
                    'leadtime_days' => $line->getLeadTimeDays() ?? 14,
                ];
            }
            return $data;
        }
        
        // Fallback to JSON data
        $bomJson = $quote->getBomDataJson();
        if ($bomJson) {
            $decoded = json_decode($bomJson, true);
            return $decoded['lines'] ?? $decoded ?? [];
        }
        
        return [];
    }
    
    /**
     * Customer requests a quote at a specific quantity
     * 
     * @param Quote $quote The original quote
     * @param int $requestedQuantity The desired quantity
     * @param string|null $customerNotes Optional notes from customer
     * @return array The quote request details
     */
    public function requestQuoteAtQuantity(
        Quote $quote,
        int $requestedQuantity,
        ?string $customerNotes = null
    ): array {
        // Calculate pricing for requested quantity
        $bomData = $this->extractBomData($quote);
        $pricing = $this->calculatePricingForQuantity($bomData, $requestedQuantity);
        $leadTime = $this->estimateLeadTime($requestedQuantity, $bomData);
        
        // Log the request
        $this->logger->info('Customer quote request received', [
            'quote_id' => $quote->getId(),
            'quote_number' => $quote->getQuoteNumber(),
            'requested_quantity' => $requestedQuantity,
            'estimated_total' => $pricing['extended_total'],
            'customer_notes' => $customerNotes,
        ]);
        
        return [
            'status' => 'request_received',
            'quote_number' => $quote->getQuoteNumber(),
            'requested_quantity' => $requestedQuantity,
            'estimated_unit_price' => round($pricing['unit_total'], 4),
            'estimated_total' => round($pricing['extended_total'], 2),
            'estimated_lead_time' => $leadTime,
            'customer_notes' => $customerNotes,
            'message' => 'Your request has been submitted. A sales representative will contact you within 24 hours with a formal quote.',
            'next_steps' => [
                'A sales rep will review your request',
                'You will receive a formal quote via email',
                'The quote will be valid for 30 days',
            ],
        ];
    }
    
    /**
     * Customer accepts the quote
     */
    public function acceptQuote(Quote $quote, int $acceptedQuantity, array $customerInfo): array
    {
        // Update quote status
        $quote->setStatus('accepted');
        $quote->setQuantity($acceptedQuantity);
        $quote->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
        
        $this->logger->info('Quote accepted by customer', [
            'quote_id' => $quote->getId(),
            'quote_number' => $quote->getQuoteNumber(),
            'accepted_quantity' => $acceptedQuantity,
            'customer_info' => $customerInfo,
        ]);
        
        return [
            'status' => 'accepted',
            'quote_number' => $quote->getQuoteNumber(),
            'accepted_quantity' => $acceptedQuantity,
            'message' => 'Thank you for accepting this quote! Our team will reach out to begin the order process.',
            'next_steps' => [
                'You will receive an order confirmation email',
                'Our engineering team will review requirements',
                'Production will be scheduled based on lead times',
            ],
        ];
    }
    
    /**
     * Get interactive quote statistics for dashboard
     */
    public function getInteractiveQuoteStats(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        // Get quotes with interactive mode enabled
        $activeQuotes = $qb->select('COUNT(q.id)')
            ->from(Quote::class, 'q')
            ->where('q.interactiveEnabled = true')
            ->andWhere('q.tokenExpiresAt > :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getSingleScalarResult();
        
        // Get total views
        $qb = $this->entityManager->createQueryBuilder();
        $totalViews = $qb->select('SUM(q.viewCount)')
            ->from(Quote::class, 'q')
            ->where('q.interactiveEnabled = true')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
        
        // Get recently viewed
        $qb = $this->entityManager->createQueryBuilder();
        $recentlyViewed = $qb->select('q')
            ->from(Quote::class, 'q')
            ->where('q.lastViewedAt IS NOT NULL')
            ->andWhere('q.lastViewedAt > :threshold')
            ->setParameter('threshold', (new \DateTime())->modify('-7 days'))
            ->orderBy('q.lastViewedAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
        
        return [
            'active_interactive_quotes' => (int) $activeQuotes,
            'total_customer_views' => (int) $totalViews,
            'recently_viewed_count' => count($recentlyViewed),
            'recently_viewed' => array_map(fn(Quote $q) => [
                'quote_number' => $q->getQuoteNumber(),
                'company' => $q->getCompany()?->getName(),
                'last_viewed' => $q->getLastViewedAt()?->format('Y-m-d H:i'),
                'view_count' => $q->getViewCount(),
            ], $recentlyViewed),
        ];
    }
    
    /**
     * Regenerate expired token
     */
    public function regenerateToken(Quote $quote, int $expirationDays = 30): array
    {
        return $this->enableInteractiveMode($quote, $quote->getQuantityOptions(), $expirationDays);
    }
}
