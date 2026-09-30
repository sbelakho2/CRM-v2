<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Quote;
use App\Entity\BomLine;
use App\Repository\QuoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

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

    // Tier bounds: sane client input, bounded storage
    private const MAX_TIERS = 20;
    private const MAX_TIER_QUANTITY = 1_000_000;

    // Public rate limits (per token / per IP buckets)
    private const RATE_LIMIT_PER_TOKEN_PER_HOUR = 10;
    private const RATE_LIMIT_PER_IP_PER_HOUR = 30;
    
    public function __construct(
        private EntityManagerInterface $entityManager,
        private QuoteRepository $quoteRepository,
        private LoggerInterface $logger,
        private ?Security $security = null,
        private ?\App\Service\PricingEngine $pricingEngine = null,
    ) {}
    
    /**
     * Normalize client-supplied quantity tiers: integers only, 1..1,000,000,
     * unique, ascending, bounded count. Garbage input falls back to the
     * defaults rather than poisoning the stored quote configuration.
     *
     * @return list<int>
     */
    private function normalizeQuantityTiers(?array $quantityTiers): array
    {
        if (!is_array($quantityTiers) || $quantityTiers === []) {
            return self::DEFAULT_QUANTITY_TIERS;
        }

        $tiers = [];
        foreach ($quantityTiers as $tier) {
            if (is_int($tier) || (is_string($tier) && preg_match('/^\d+$/', $tier))) {
                $tier = (int) $tier;
            } elseif (is_float($tier) && floor($tier) === $tier) {
                $tier = (int) $tier;
            } else {
                continue; // non-integer garbage — dropped
            }
            if ($tier >= 1 && $tier <= self::MAX_TIER_QUANTITY) {
                $tiers[$tier] = $tier; // de-dupe
            }
        }

        $tiers = array_values($tiers);
        sort($tiers);

        if ($tiers === []) {
            return self::DEFAULT_QUANTITY_TIERS;
        }

        return array_slice($tiers, 0, self::MAX_TIERS);
    }

    /**
     * Guard: archived quotes are read-only. All interactive-mode MUTATIONS
     * route through this — the public token resolver already refuses them;
     * admin endpoints must not modify (or re-token) an archived record.
     */
    private function assertNotArchived(Quote $quote): void
    {
        if ($quote->isArchived()) {
            throw new \InvalidArgumentException('This quote is archived and read-only.');
        }
    }

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
        $this->assertNotArchived($quote);

        // Expiration: bounded 1..90 days (previously min() only — a
        // negative value minted an instantly-expired token).
        $expirationDays = max(1, min(self::MAX_TOKEN_VALIDITY_DAYS, $expirationDays));

        // Generate secure token
        $quote->generatePublicToken($expirationDays);
        $quote->setInteractiveEnabled(true);

        // Tiers: normalized (integers, 1..1,000,000, unique, ascending,
        // bounded count) — client arrays were previously stored verbatim.
        $tiers = $this->normalizeQuantityTiers($quantityTiers);

        // Ensure current quantity is included in tiers
        $currentQty = $quote->getQuantity();
        if ($currentQty && !in_array((int) $currentQty, $tiers, true)) {
            $tiers[] = (int) $currentQty;
            sort($tiers);
        }
        $tiers = array_values(array_slice($tiers, 0, self::MAX_TIERS));

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
        $this->assertNotArchived($quote);

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

        // Archived quotes are not publicly live even if a legacy token row
        // still resolves.
        if ($quote->isArchived()) {
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
            $tierResult = $this->calculatePricingForQuantity($bomData, $quantity, $currency);
            
            $tierPricing[] = [
                'quantity' => $quantity,
                'unit_price' => round($tierResult['unit_total'], 4),
                'material_subtotal' => round($tierResult['extended_total'], 2),
                'extended_price' => round((float) ($tierResult['canonical_total'] ?? $tierResult['extended_total']), 2),
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
        
        try {
            $companyName = $quote->getCompany()?->getName();
        } catch (\Doctrine\ORM\EntityNotFoundException) {
            $companyName = null;
        }

        return [
            'quote_number' => $quote->getQuoteNumber(),
            'company' => $companyName,
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
    /**
     * Price a quantity through the CANONICAL pricing model.
     *
     * Historical bug: this method summed raw material costs itself, so the
     * customer's live quote could diverge from the actual quote total (the
     * canonical QuoteCoPilot path applies PricingEngine::calculateQuoteTotals
     * with the standard 25% margin). Now the per-quantity material lines are
     * converted into the SAME processed-line shape the copilot pipeline
     * produces (extended_price per line) and priced by the one canonical
     * engine — same cost components, same margin rules, same currency.
     */
    private function calculatePricingForQuantity(array $bomData, int $quantity, ?string $currency = null): array
    {
        $unitTotal = 0.0;
        $extendedTotal = 0.0;
        $perUnitBreakdown = [];
        $allInStock = true;
        $anyStockKnown = true;
        $processedLines = [];

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

            $stock = $line['stock'] ?? null;
            $hasStockData = $stock !== null;
            if ($hasStockData && (int) $stock < $lineQty) {
                $allInStock = false;
            }
            if (!$hasStockData) {
                $anyStockKnown = false;
            }

            $perUnitBreakdown[] = [
                'mpn' => $line['mpn'] ?? 'Unknown',
                'description' => $line['description'] ?? '',
                'qty_per_unit' => $line['quantity_per_unit'] ?? 1,
                'unit_price' => round($unitPrice, 5),
                'extended' => round($lineExtended, 2),
                'stock_available' => $stock,
                'sufficient_stock' => $hasStockData ? ((int) $stock >= $lineQty) : null,
            ];

            // Canonical processed-line shape (what PricingEngine totals consume).
            $processedLines[] = [
                'mpn' => $line['mpn'] ?? 'Unknown',
                'description' => $line['description'] ?? '',
                'quantity' => $lineQty,
                'unit_price' => $unitPrice,
                'extended_price' => $lineExtended,
            ];
        }

        // CANONICAL totals: identical margin model to QuoteCoPilotService.
        $totals = $this->pricingEngine !== null
            ? $this->pricingEngine->calculateQuoteTotals($processedLines, 25.0, $currency)
            : null;

        return [
            'unit_total' => $unitTotal,
            // material subtotal when canonical engine unavailable (unit tests)
            'extended_total' => $totals['subtotal'] ?? $extendedTotal,
            // CANONICAL customer total (material + standard margin)
            'canonical_total' => $totals['total'] ?? null,
            'margin_percent' => $totals['margin_percent'] ?? null,
            'margin_amount' => $totals['margin_amount'] ?? null,
            'currency' => $totals['currency'] ?? $currency,
            'per_unit_breakdown' => $perUnitBreakdown,
            'stock_status' => !$anyStockKnown ? 'unknown_stock' : ($allInStock ? 'all_in_stock' : 'partial_stock'),
        ];
    }
    
    /**
     * Validate a stored price-break array: quantity/price pairs, positive
     * quantities, non-negative prices, bounded count. Anything malformed is
     * dropped — a corrupted snapshot must never produce a wrong quote.
     *
     * @return list<array{quantity: int, price: float}>
     */
    private function sanitizePriceBreaks(mixed $priceBreaks): array
    {
        if (!is_array($priceBreaks)) {
            return [];
        }

        $clean = [];
        foreach (array_slice($priceBreaks, 0, 50) as $break) {
            if (!is_array($break) || !isset($break['quantity'], $break['price'])) {
                continue;
            }
            $quantity = (int) $break['quantity'];
            $price = (float) $break['price'];
            if ($quantity >= 1 && $price >= 0.0) {
                $clean[] = ['quantity' => $quantity, 'price' => $price];
            }
        }

        usort($clean, fn ($a, $b) => $a['quantity'] <=> $b['quantity']);

        return $clean;
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
            $stock = $line['stock'] ?? null;
            $standardLeadTime = $line['leadtime_days'] ?? 14;
            
            $effectiveLeadTime = $standardLeadTime;
            
            // If stock is insufficient (KNOWN stock only), add procurement time
            if ($stock !== null && $stock < $lineQty) {
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
                // IMMUTABLE PRICING SNAPSHOT: the sourcing pipeline persists
                // the approved supplier price breaks into sourcing_data —
                // live tier pricing reprices against THAT snapshot (never
                // against volatile external APIs after issuance, and no
                // longer a flat qty-1 price scaled linearly).
                $sourcing = $line->getSourcingData() ?? [];
                $pricing = $this->sanitizePriceBreaks($sourcing['price_breaks'] ?? null);

                if ($pricing === []) {
                    $unitPrice = $line->getUnitPrice();
                    if ($unitPrice !== null) {
                        $pricing = [
                            ['quantity' => 1, 'price' => (float) $unitPrice],
                        ];
                    }
                }

                // Stock: only claimed when the sourcing snapshot actually
                // carries a number — a fabricated 0 made every line look
                // stock-constrained.
                $stock = isset($sourcing['stock']) && is_numeric($sourcing['stock'])
                    ? max(0, (int) $sourcing['stock'])
                    : null;

                $data[] = [
                    'mpn' => $line->getMpn(),
                    'manufacturer' => $line->getManufacturer(),
                    'description' => $line->getDescription(),
                    'quantity_per_unit' => $line->getQuantity(),
                    'pricing' => $pricing,
                    'stock' => $stock,
                    'leadtime_days' => $line->getLeadTimeDays() ?? 14,
                ];
            }
            return $data;
        }
        
        // Fallback to JSON data
        $bomJson = $quote->getBomDataJson();
        if ($bomJson) {
            $decoded = json_decode($bomJson, true);
            $lines = $decoded['lines'] ?? $decoded ?? [];

            // Normalize stored JSON lines into the pricing shape the tier
            // engine consumes — a flat unit_price becomes a qty-1 break.
            foreach ($lines as $i => $line) {
                if (!is_array($line)) {
                    continue;
                }
                if (empty($line['pricing']) && isset($line['unit_price']) && is_numeric($line['unit_price'])) {
                    $lines[$i]['pricing'] = [['quantity' => 1, 'price' => (float) $line['unit_price']]];
                }
            }

            return $lines;
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
        ?string $customerNotes = null,
        ?string $token = null,
        ?string $requestIp = null,
        ?string $userAgent = null
    ): array {
        if ($requestedQuantity <= 0 || $requestedQuantity > 1_000_000) {
            throw new \InvalidArgumentException('Requested quantity must be between 1 and 1,000,000.');
        }

        // RATE LIMITING (the PHPDoc promised it; now it exists): durable
        // buckets on the request ledger itself — 10/hour per token, 30/hour
        // per IP. CSRF is not abuse prevention.
        $this->assertRateLimit($token, $requestIp);

        // Calculate pricing for requested quantity (canonical engine)
        $bomData = $this->extractBomData($quote);
        $pricing = $this->calculatePricingForQuantity($bomData, $requestedQuantity, $quote->getCurrency());
        $leadTime = $this->estimateLeadTime($requestedQuantity, $bomData);

        // DURABLE persistence — the "submitted" response is a contract: the
        // request must exist in the database before the success is returned.
        // (Previously only a log line recorded it; log rotation destroyed
        // customer requests.)
        $request = new \App\Entity\QuoteCustomerRequest();
        $request->setQuote($quote);
        $request->setRequestType(\App\Entity\QuoteCustomerRequest::TYPE_QUANTITY_REQUEST);
        $request->setRequestedQuantity($requestedQuantity);
        $request->setEstimatedTotal(number_format((float) ($pricing['canonical_total'] ?? $pricing['extended_total']), 2, '.', ''));
        $request->setCurrency($pricing['currency'] ?? $quote->getCurrency());
        $request->setCustomerNotes($customerNotes);
        $request->setTokenFingerprint($token !== null ? hash('sha256', $token) : null);
        $request->setRequestIp($requestIp);
        $request->setUserAgent($userAgent !== null ? mb_substr($userAgent, 0, 255) : null);

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->logger->info('Customer quote request received', [
            'quote_id' => $quote->getId(),
            'quote_number' => $quote->getQuoteNumber(),
            'request_id' => $request->getId(),
            'requested_quantity' => $requestedQuantity,
            'estimated_total' => $request->getEstimatedTotal(),
        ]);

        // Surface the request in the SALES WORKFLOW: admins get a CRM
        // notification with the request ID, and the request itself is
        // discoverable in the quote-request queue (see QuoteRequestController).
        $this->notifyOperations(
            Notification::TYPE_QUOTE_REQUEST,
            sprintf(
                'New quote request #%d for %s: %d units (~%s %s)',
                (int) $request->getId(),
                (string) $quote->getQuoteNumber(),
                $requestedQuantity,
                $request->getEstimatedTotal(),
                (string) ($pricing['currency'] ?? $quote->getCurrency())
            ),
            (int) $quote->getId(),
            ['request_id' => $request->getId(), 'quantity' => $requestedQuantity]
        );

        return [
            'status' => 'request_received',
            'request_id' => $request->getId(),
            'quote_number' => $quote->getQuoteNumber(),
            'requested_quantity' => $requestedQuantity,
            'estimated_unit_price' => round($pricing['unit_total'], 4),
            'estimated_total' => (float) ($pricing['canonical_total'] ?? $pricing['extended_total']),
            'currency' => $pricing['currency'] ?? $quote->getCurrency(),
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
     *
     * When a public access token is supplied it is verified against the
     * quote (constant-time comparison + expiry check). Without a token, the
     * caller must be an authenticated user (admin flow) or the quote must
     * still carry a valid public token (i.e. it was retrieved through
     * getQuoteByToken() on the public route).
     */
    public function acceptQuote(Quote $quote, int $acceptedQuantity, array $customerInfo, ?string $token = null): array
    {
        // Archived quotes are read-only — public tokens already refuse them,
        // the authenticated flow must too.
        $this->assertNotArchived($quote);

        if ($token !== null) {
            if (!$quote->isTokenValid() || !hash_equals((string) ($quote->getPublicToken() ?? ''), $token)) {
                $this->logger->warning('Quote acceptance rejected: invalid or expired token', [
                    'quote_id' => $quote->getId(),
                    'quote_number' => $quote->getQuoteNumber(),
                ]);

                throw new AccessDeniedException('Invalid or expired quote access token.');
            }
        } elseif ($this->security !== null && !$this->security->isGranted('ROLE_USER')) {
            // No token supplied and the caller is not authenticated: the only
            // legitimate path is a quote that was fetched via the public
            // token flow and therefore still carries a valid token.
            if ($quote->getPublicToken() === null || !$quote->isTokenValid()) {
                $this->logger->warning('Quote acceptance rejected: no token and no authenticated user', [
                    'quote_id' => $quote->getId(),
                    'quote_number' => $quote->getQuoteNumber(),
                ]);

                throw new AccessDeniedException('Quote acceptance requires a valid access token or an authenticated user.');
            }
        }

        // Quantity validation: positive, sane bound, and the accepted
        // pricing must be AUTHORITATIVELY computed for that quantity (the
        // canonical engine) — never trust a client-supplied total.
        if ($acceptedQuantity <= 0 || $acceptedQuantity > 1_000_000) {
            throw new \InvalidArgumentException('Accepted quantity must be between 1 and 1,000,000.');
        }

        $bomData = $this->extractBomData($quote);
        $pricing = $this->calculatePricingForQuantity($bomData, $acceptedQuantity, $quote->getCurrency());
        $acceptedTotalFloat = (float) ($pricing['canonical_total'] ?? $pricing['extended_total']);

        // ACCEPTANCE POLICY — fail closed: a quote with no priced lines (no
        // BOM pricing, missing/zero unit prices) is not a commercially
        // acceptable offer. Accepting a 0.00 total would fabricate an order.
        if ($bomData === []) {
            throw new \InvalidArgumentException('This quote has no priced BOM lines and cannot be accepted.');
        }
        if ($acceptedTotalFloat <= 0.0) {
            throw new \InvalidArgumentException('This quote has no valid pricing and cannot be accepted. Please request a formal quote.');
        }

        $acceptedTotal = (string) number_format($acceptedTotalFloat, 2, '.', '');

        // Idempotent acceptance: an already-accepted quote keeps its
        // ORIGINAL acceptance record and returns it (no second event).
        // (getRepository can return null on unit-test mocks — fall back to
        // treating a non-accepted quote as a fresh acceptance.)
        /** @var \App\Repository\QuoteAcceptanceRepository|null $repository */
        $repository = $this->entityManager->getRepository(\App\Entity\QuoteAcceptance::class);
        $existing = ($repository instanceof \App\Repository\QuoteAcceptanceRepository)
            ? $repository->findLatestForQuote($quote)
            : null;
        if ($quote->getStatus() === 'accepted' && $existing !== null) {
            return [
                'status' => 'accepted',
                'acceptance_id' => $existing->getId(),
                'quote_number' => $quote->getQuoteNumber(),
                'accepted_quantity' => $existing->getAcceptedQuantity(),
                'accepted_total' => (float) $existing->getAcceptedTotal(),
                'idempotent_replay' => true,
                'message' => 'This quote has already been accepted.',
            ];
        }

        $acceptance = new \App\Entity\QuoteAcceptance();
        $acceptance->setQuote($quote);
        $acceptance->setAcceptedQuantity($acceptedQuantity);
        $acceptance->setAcceptedTotal($acceptedTotal);
        $acceptance->setCurrency($pricing['currency'] ?? $quote->getCurrency());
        $acceptance->setTokenFingerprint($token !== null ? hash('sha256', $token) : null);
        $acceptance->setPricingSnapshot([
            'unit_total' => $pricing['unit_total'],
            'material_subtotal' => $pricing['extended_total'],
            'canonical_total' => $pricing['canonical_total'],
            'margin_percent' => $pricing['margin_percent'],
            'margin_amount' => $pricing['margin_amount'],
            'quantity' => $acceptedQuantity,
        ]);
        $acceptance->setCustomerName(
            isset($customerInfo['name']) && is_string($customerInfo['name']) ? mb_substr($customerInfo['name'], 0, 255) : null
        );
        $acceptance->setCustomerEmail(
            isset($customerInfo['email']) && is_string($customerInfo['email']) ? mb_substr($customerInfo['email'], 0, 255) : null
        );
        $acceptance->setCustomerPhone(
            isset($customerInfo['phone']) && is_string($customerInfo['phone']) ? mb_substr($customerInfo['phone'], 0, 50) : null
        );
        $acceptance->setCustomerCompany(
            isset($customerInfo['company']) && is_string($customerInfo['company']) ? mb_substr($customerInfo['company'], 0, 255) : null
        );
        $acceptance->setPoNumber(
            isset($customerInfo['po_number']) && is_string($customerInfo['po_number']) ? mb_substr($customerInfo['po_number'], 0, 100) : null
        );

        // ONE flush boundary: acceptance + state transition commit together.
        // quote_acceptances.quote_id is UNIQUE at the database level — a
        // concurrent acceptance loses the race here and is converted into an
        // idempotent replay of the WINNER's record (no second acceptance,
        // no duplicate order event).
        $this->entityManager->persist($acceptance);
        $quote->setStatus('accepted');
        $quote->setQuantity($acceptedQuantity);
        $quote->setUpdatedAt(new \DateTime());

        // The follow-on sales/operations event lives in the SAME commit:
        // admin notifications + CRM activity (the promised order-confirmation
        // workflow — the customer message below is now TRUE).
        $this->queueAcceptanceNotifications($quote, $acceptedQuantity, $acceptedTotal, $pricing['currency'] ?? $quote->getCurrency());
        $activity = $this->buildAcceptanceActivity($quote, $acceptedQuantity, $acceptedTotal, $pricing['currency'] ?? $quote->getCurrency());
        if ($activity !== null) {
            $this->entityManager->persist($activity);
        }

        try {
            $this->entityManager->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // We lost the acceptance race — replay the winner's record.
            $this->entityManager->clear();
            /** @var \App\Entity\Quote $freshQuote */
            $freshQuote = $this->quoteRepository->find($quote->getId());
            $winner = ($this->entityManager->getRepository(\App\Entity\QuoteAcceptance::class) instanceof \App\Repository\QuoteAcceptanceRepository)
                ? $this->entityManager->getRepository(\App\Entity\QuoteAcceptance::class)->findLatestForQuote($freshQuote)
                : null;

            if ($winner !== null) {
                $this->logger->info('Concurrent quote acceptance resolved to existing record', [
                    'quote_id' => $quote->getId(),
                    'acceptance_id' => $winner->getId(),
                ]);

                return [
                    'status' => 'accepted',
                    'acceptance_id' => $winner->getId(),
                    'quote_number' => $freshQuote->getQuoteNumber(),
                    'accepted_quantity' => $winner->getAcceptedQuantity(),
                    'accepted_total' => (float) $winner->getAcceptedTotal(),
                    'idempotent_replay' => true,
                    'message' => 'This quote has already been accepted.',
                ];
            }

            // Unique violation without a visible winner should not happen —
            // surface it as a conflict.
            throw new \RuntimeException('Quote acceptance conflict, please retry.');
        }

        // Audit-log only structural IDs — never the customer-info payload
        // (unnecessary PII in logs).
        $this->logger->info('Quote accepted by customer', [
            'quote_id' => $quote->getId(),
            'quote_number' => $quote->getQuoteNumber(),
            'acceptance_id' => $acceptance->getId(),
            'accepted_quantity' => $acceptedQuantity,
            'via_public_token' => $token !== null,
        ]);

        return [
            'status' => 'accepted',
            'acceptance_id' => $acceptance->getId(),
            'quote_number' => $quote->getQuoteNumber(),
            'accepted_quantity' => $acceptedQuantity,
            'accepted_total' => (float) $acceptedTotal,
            'currency' => $pricing['currency'] ?? $quote->getCurrency(),
            'message' => 'Thank you for accepting this quote! Our team will reach out to begin the order process.',
            'next_steps' => [
                'Our sales team has been notified and will confirm your order',
                'Our engineering team will review requirements',
                'Production will be scheduled based on lead times',
            ],
        ];
    }
    
    /**
     * Public request rate limiting: durable buckets over the request ledger.
     *
     * @throws RateLimitExceeded when a bucket is exhausted
     */
    private function assertRateLimit(?string $token, ?string $requestIp): void
    {
        $since = (new \DateTime())->modify('-1 hour');
        $repository = $this->entityManager->getRepository(\App\Entity\QuoteCustomerRequest::class);

        if ($repository instanceof \App\Repository\QuoteCustomerRequestRepository) {
            if ($token !== null) {
                $fingerprint = hash('sha256', $token);
                if ($repository->countRecentByFingerprint($fingerprint, $since) >= self::RATE_LIMIT_PER_TOKEN_PER_HOUR) {
                    throw new RateLimitExceeded('Too many requests for this quote link. Please try again later.');
                }
            }
            if ($requestIp !== null && $repository->countRecentByIp($requestIp, $since) >= self::RATE_LIMIT_PER_IP_PER_HOUR) {
                throw new RateLimitExceeded('Too many requests from your network. Please try again later.');
            }
        }
    }

    /**
     * Notify the operations/admin queue in the CRM notification center.
     * (The data model has no per-quote sales owner — quotes and companies
     * only carry archivedBy — so the admin group IS the follow-up queue.)
     */
    private function notifyOperations(string $type, string $message, int $entityId, array $data = []): void
    {
        try {
            $admins = $this->entityManager->createQuery(
                "SELECT u FROM App\Entity\User u WHERE u.roles LIKE '%ROLE_ADMIN%' AND u.active = true"
            )?->getResult() ?? [];
        } catch (\Throwable) {
            $admins = []; // mocked EM or transient failure — never block the flow
        }

        foreach ($admins as $admin) {
            $notification = new Notification();
            $notification->setUser($admin);
            $notification->setType($type);
            $notification->setEntityType('quote');
            $notification->setEntityId($entityId);
            $notification->setMessage($message);
            $notification->setData($data ?: null);
            $this->entityManager->persist($notification);
        }
        $this->entityManager->flush();
    }

    /**
     * CRM activity recording the acceptance — the follow-on sales event.
     * Activity.user is NOT NULL, so ownerless quotes skip it (the
     * notification still records with a null user).
     */
    private function queueAcceptanceNotifications(Quote $quote, int $quantity, string $total, ?string $currency): void
    {
        try {
            $admins = $this->entityManager->createQuery(
                "SELECT u FROM App\Entity\User u WHERE u.roles LIKE '%ROLE_ADMIN%' AND u.active = true"
            )?->getResult() ?? [];
        } catch (\Throwable) {
            return; // mocked EM or transient failure — never block the flow
        }

        foreach ($admins as $admin) {
            $notification = new Notification();
            $notification->setUser($admin);
            $notification->setType(Notification::TYPE_QUOTE_ACCEPTED);
            $notification->setEntityType('quote');
            $notification->setEntityId((int) $quote->getId());
            $notification->setMessage(sprintf(
                'Quote %s ACCEPTED: %d units for %s %s',
                (string) $quote->getQuoteNumber(),
                $quantity,
                $total,
                (string) $currency
            ));
            $notification->setData(['acceptance_quantity' => $quantity, 'accepted_total' => $total]);
            $this->entityManager->persist($notification);
        }
    }

    private function buildAcceptanceActivity(Quote $quote, int $quantity, string $total, ?string $currency): ?\App\Entity\Activity
    {
        try {
            $company = $quote->getCompany();
        } catch (\Doctrine\ORM\EntityNotFoundException) {
            return null;
        }

        // Activity.user is NOT NULL — attribute to the acting user when the
        // acceptance came through an authenticated session; public-token
        // acceptances are covered by the admin notifications instead.
        $actingUser = $this->security?->getUser();
        if ($actingUser === null || $company === null) {
            return null;
        }

        $activity = new \App\Entity\Activity();
        $activity->setUser($actingUser);
        $activity->setCompany($company);
        $activity->setType('quote_accepted');
        $activity->setSubject(sprintf('Quote %s accepted (%d units, %s %s)', (string) $quote->getQuoteNumber(), $quantity, $total, (string) $currency));
        $activity->setDescription('Customer accepted the interactive live quote. Begin the order process.');
        $activity->setActivityDate(new \DateTime());

        return $activity;
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
        
        $safeCompanyName = function (Quote $q): ?string {
            try {
                return $q->getCompany()?->getName();
            } catch (\Doctrine\ORM\EntityNotFoundException) {
                return null;
            }
        };

        return [
            'active_interactive_quotes' => (int) $activeQuotes,
            'total_customer_views' => (int) $totalViews,
            'recently_viewed_count' => count($recentlyViewed),
            'recently_viewed' => array_map(fn(Quote $q) => [
                'quote_number' => $q->getQuoteNumber(),
                'company' => $safeCompanyName($q),
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
