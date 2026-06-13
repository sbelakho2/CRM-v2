<?php

namespace App\Service;

use App\Entity\Quote;
use App\Repository\PcbCurveRepository;
use App\Repository\AsmCurveRepository;
use App\Repository\NreTableRepository;
use App\Repository\CapacityCalendarRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CostingEngineService
 * 
 * Manufacturing cost calculation engine for PCB fabrication, assembly, and NRE.
 * 
 * Cost components:
 * - PCB fabrication: Based on board area, layer count, material (FR4/polyimide), surface finish (HASL/ENIG)
 * - Assembly (SMT/THT): Based on component count, side count (single/double), package complexity
 * - NRE (tooling): Stencils, fixtures, programming, first article inspection
 * - Capacity booking: Reserve production slots in CapacityCalendar
 * 
 * Pricing curves:
 * - PcbCurve: $/sqcm by layer count + complexity modifiers
 * - AsmCurve: $/component + setup cost + package multipliers (BGA 2x, QFN 1.5x)
 * - NreTable: Flat rates for stencils, fixtures, programming
 * 
 * Used by:
 * - QuoteCoPilotController for component pricing pipeline
 * - Quote detail page for cost breakdown display
 * - UnifiedPdfGeneratorService for cost breakdown PDF
 */
class CostingEngineService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PcbCurveRepository $pcbCurveRepository,
        private AsmCurveRepository $asmCurveRepository,
        private NreTableRepository $nreTableRepository,
        private CapacityCalendarRepository $capacityCalendarRepository
    ) {}

    /**
     * Calculate PCB fabrication cost
     * 
     * @param array $pcbSpec - PCB specification
     *   - width: float (mm)
     *   - height: float (mm)
     *   - layers: int (2, 4, 6, 8, 10, etc.)
     *   - material: string (FR4, POLYIMIDE)
     *   - surfaceFinish: string (HASL, ENIG, OSP)
     *   - qty: int
     * 
     * @return array{
     *   unitCost: float,
     *   setupCost: float,
     *   totalCost: float,
     *   areaPerBoard: float,
     *   pricePerSqcm: float
     * }
     */
    public function calculatePcbCost(array $pcbSpec): array
    {
        // TODO: Implement PCB cost calculation
        // 
        // Steps:
        // 1. Calculate board area:
        //    $areaPerBoard = ($pcbSpec['width'] / 10) * ($pcbSpec['height'] / 10); // Convert mm² to cm²
        // 
        // 2. Query PcbCurve for base price per sqcm:
        //    $curve = $this->pcbCurveRepository->findOneBy([
        //        'layerCount' => $pcbSpec['layers']
        //    ], ['asof' => 'DESC']);
        //    
        //    if (!$curve) {
        //        throw new \RuntimeException("No pricing curve found for {$pcbSpec['layers']} layers");
        //    }
        //    
        //    $basePricePerSqcm = $curve->getPricePerSqcm();
        // 
        // 3. Apply complexity modifiers:
        //    $multiplier = 1.0;
        //    
        //    // Material modifier
        //    if ($pcbSpec['material'] === 'POLYIMIDE') {
        //        $multiplier *= 1.5; // Polyimide is 50% more expensive
        //    }
        //    
        //    // Surface finish modifier
        //    if ($pcbSpec['surfaceFinish'] === 'ENIG') {
        //        $multiplier *= 1.2; // ENIG is 20% more expensive than HASL
        //    } elseif ($pcbSpec['surfaceFinish'] === 'OSP') {
        //        $multiplier *= 0.95; // OSP is 5% cheaper
        //    }
        // 
        // 4. Calculate unit cost:
        //    $pricePerSqcm = $basePricePerSqcm * $multiplier;
        //    $unitCost = $areaPerBoard * $pricePerSqcm;
        // 
        // 5. Apply quantity discounts:
        //    $qty = $pcbSpec['qty'];
        //    if ($qty >= 1000) {
        //        $unitCost *= 0.7; // 30% discount for qty >= 1000
        //    } elseif ($qty >= 100) {
        //        $unitCost *= 0.85; // 15% discount for qty >= 100
        //    }
        // 
        // 6. Add setup cost (tooling, CAM, inspection):
        //    $setupCost = $curve->getSetupCost() ?? 150.00; // Default $150 setup
        // 
        // 7. Return cost breakdown:
        //    return [
        //        'unitCost' => round($unitCost, 2),
        //        'setupCost' => round($setupCost, 2),
        //        'totalCost' => round(($unitCost * $qty) + $setupCost, 2),
        //        'areaPerBoard' => round($areaPerBoard, 2),
        //        'pricePerSqcm' => round($pricePerSqcm, 2)
        //    ];

        // 1. Calculate board area (convert mm² to cm²)
        $width = $pcbSpec['width'] ?? 100;
        $height = $pcbSpec['height'] ?? 100;
        $areaPerBoard = ($width / 10) * ($height / 10);
        
        // 2. Query PcbCurve for base price
        $layers = $pcbSpec['layers'] ?? 2;
        $curve = $this->pcbCurveRepository->createQueryBuilder('p')
            ->where('p.layerCount = :layers')
            ->setParameter('layers', $layers)
            ->orderBy('p.asof', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        // Default pricing if no curve found
        $basePricePerSqcm = $curve?->getPricePerSqcm() ?? 0.15;
        $setupCostBase = $curve?->getSetupCost() ?? 150.00;
        
        // 3. Apply complexity multipliers
        $multiplier = 1.0;
        
        $material = $pcbSpec['material'] ?? 'FR4';
        if ($material === 'POLYIMIDE') {
            $multiplier *= 1.5;
        }
        
        $surfaceFinish = $pcbSpec['surfaceFinish'] ?? 'HASL';
        if ($surfaceFinish === 'ENIG') {
            $multiplier *= 1.2;
        } elseif ($surfaceFinish === 'OSP') {
            $multiplier *= 0.95;
        }
        
        // 4. Calculate unit cost (base, before quantity discount)
        $pricePerSqcm = $basePricePerSqcm * $multiplier;
        $unitCost = $areaPerBoard * $pricePerSqcm;
        
        // 5. Apply quantity discounts
        // Fix H6: Apply discount to the LINE ITEM TOTAL (unitCost × qty), NOT to the unit cost
        // before multiplication. Previously the discount was applied to unitCost which was then
        // multiplied by qty in totalCost — this double-applied the discount.
        $qty = $pcbSpec['qty'] ?? 1;
        $lineTotal = $unitCost * $qty;
        if ($qty >= 1000) {
            $lineTotal *= 0.7; // 30% discount for qty >= 1000
        } elseif ($qty >= 100) {
            $lineTotal *= 0.85; // 15% discount for qty >= 100
        }
        // Recalculate unit cost after discount
        $discountedUnitCost = $lineTotal / $qty;
        
        // 6. Setup cost
        $setupCost = $setupCostBase;
        
        // 7. Return cost breakdown
        return [
            'unitCost' => round($discountedUnitCost, 2),
            'setupCost' => round($setupCost, 2),
            'totalCost' => round($lineTotal + $setupCost, 2),
            'areaPerBoard' => round($areaPerBoard, 2),
            'pricePerSqcm' => round($pricePerSqcm, 4)
        ];
    }

    /**
     * Calculate assembly (SMT/THT) cost
     * 
     * @param array $asmSpec - Assembly specification
     *   - componentCount: int
     *   - sideCount: int (1 or 2)
     *   - packageComplexity: array (package types with counts)
     *     - ['0402' => 50, '0603' => 30, 'BGA-256' => 2, 'QFN-64' => 5]
     *   - qty: int
     * 
     * @return array{
     *   unitCost: float,
     *   setupCost: float,
     *   totalCost: float,
     *   pricePerComponent: float
     * }
     */
    public function calculateAsmCost(array $asmSpec): array
    {
        // TODO: Implement assembly cost calculation
        // 
        // Steps:
        // 1. Query AsmCurve for base price per component:
        //    $curve = $this->asmCurveRepository->findOneBy([], ['asof' => 'DESC']);
        //    if (!$curve) {
        //        throw new \RuntimeException("No assembly pricing curve found");
        //    }
        //    
        //    $basePricePerComponent = $curve->getPricePerComponent();
        // 
        // 2. Apply package complexity multipliers:
        //    $totalCost = 0.0;
        //    $packageMultipliers = [
        //        '0201' => 1.5,  // Smallest passive, difficult to place
        //        '0402' => 1.2,
        //        '0603' => 1.0,  // Baseline
        //        '0805' => 1.0,
        //        'QFN' => 1.5,   // Fine-pitch packages
        //        'BGA' => 2.0,   // Most complex
        //        'SOIC' => 1.1,
        //        'TSSOP' => 1.2,
        //        'DIP' => 1.3    // THT requires wave soldering
        //    ];
        //    
        //    foreach ($asmSpec['packageComplexity'] as $pkg => $count) {
        //        $multiplier = $packageMultipliers[$pkg] ?? 1.0;
        //        $totalCost += $count * ($basePricePerComponent * $multiplier);
        //    }
        // 
        // 3. Apply side count multiplier:
        //    if ($asmSpec['sideCount'] === 2) {
        //        $totalCost *= 1.4; // Double-sided assembly is 40% more expensive
        //    }
        // 
        // 4. Calculate unit cost:
        //    $qty = $asmSpec['qty'];
        //    $unitCost = $totalCost;
        // 
        // 5. Apply quantity discounts:
        //    if ($qty >= 1000) {
        //        $unitCost *= 0.75;
        //    } elseif ($qty >= 100) {
        //        $unitCost *= 0.9;
        //    }
        // 
        // 6. Add setup cost (programming, first article, fixtures):
        //    $setupCost = $curve->getSetupCost() ?? 250.00;
        // 
        // 7. Return cost breakdown:
        //    return [
        //        'unitCost' => round($unitCost, 2),
        //        'setupCost' => round($setupCost, 2),
        //        'totalCost' => round(($unitCost * $qty) + $setupCost, 2),
        //        'pricePerComponent' => round($basePricePerComponent, 2)
        //    ];

        // 1. Query AsmCurve for base price
        $curve = $this->asmCurveRepository->createQueryBuilder('a')
            ->orderBy('a.asof', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        $basePricePerComponent = $curve?->getPricePerComponent() ?? 0.05;
        $setupCostBase = $curve?->getSetupCost() ?? 250.00;
        
        // 2. Apply package complexity multipliers
        $packageMultipliers = [
            '0201' => 1.5,
            '0402' => 1.2,
            '0603' => 1.0,
            '0805' => 1.0,
            '1206' => 0.9,
            'QFN' => 1.5,
            'BGA' => 2.0,
            'SOIC' => 1.1,
            'TSSOP' => 1.2,
            'SSOP' => 1.2,
            'DIP' => 1.3,
            'SOT' => 1.0,
        ];
        
        $totalCost = 0.0;
        $packageComplexity = $asmSpec['packageComplexity'] ?? [];
        
        foreach ($packageComplexity as $pkg => $count) {
            $multiplier = $packageMultipliers[$pkg] ?? 1.0;
            $totalCost += $count * ($basePricePerComponent * $multiplier);
        }
        
        // If no package data, use component count
        if ($totalCost == 0 && isset($asmSpec['componentCount'])) {
            $totalCost = $asmSpec['componentCount'] * $basePricePerComponent;
        }
        
        // 3. Apply side count multiplier
        $sideCount = $asmSpec['sideCount'] ?? 1;
        if ($sideCount === 2) {
            $totalCost *= 1.4;
        }
        
        // 4. Calculate unit cost (base, before quantity discount)
        $unitCost = $totalCost;
        
        // 5. Apply quantity discounts
        // Fix H6: Apply discount to the LINE ITEM TOTAL (unitCost × qty), NOT to the unit cost
        // before multiplication. Previously the discount was applied to unitCost which was then
        // multiplied by qty in totalCost — this double-applied the discount.
        $qty = $asmSpec['qty'] ?? 1;
        $lineTotal = $unitCost * $qty;
        if ($qty >= 1000) {
            $lineTotal *= 0.75; // 25% discount for qty >= 1000
        } elseif ($qty >= 100) {
            $lineTotal *= 0.9;  // 10% discount for qty >= 100
        }
        // Recalculate unit cost after discount
        $discountedUnitCost = $lineTotal / $qty;
        
        // 6. Setup cost
        $setupCost = $setupCostBase;
        
        // 7. Return cost breakdown
        return [
            'unitCost' => round($discountedUnitCost, 2),
            'setupCost' => round($setupCost, 2),
            'totalCost' => round($lineTotal + $setupCost, 2),
            'pricePerComponent' => round($basePricePerComponent, 4)
        ];
    }

    /**
     * Calculate NRE (Non-Recurring Engineering) costs
     * 
     * @param array $nreItems - NRE items to include
     *   - stencil: bool (SMT stencil required)
     *   - fixture: bool (Test fixture required)
     *   - programming: bool (Firmware programming required)
     *   - firstArticle: bool (First article inspection required)
     * 
     * @return array{
     *   stencilCost: float,
     *   fixtureCost: float,
     *   programmingCost: float,
     *   firstArticleCost: float,
     *   totalNre: float
     * }
     */
    public function calculateNre(array $nreItems): array
    {
        // TODO: Implement NRE cost calculation
        // 
        // Steps:
        // 1. Query NreTable for item costs:
        //    $nreRates = [];
        //    $items = ['stencil', 'fixture', 'programming', 'firstArticle'];
        //    
        //    foreach ($items as $item) {
        //        $rate = $this->nreTableRepository->findOneBy([
        //            'itemType' => strtoupper($item)
        //        ], ['asof' => 'DESC']);
        //        
        //        $nreRates[$item] = $rate ? $rate->getCost() : 0.0;
        //    }
        // 
        // 2. Calculate total NRE:
        //    $costs = [
        //        'stencilCost' => $nreItems['stencil'] ? $nreRates['stencil'] : 0.0,
        //        'fixtureCost' => $nreItems['fixture'] ? $nreRates['fixture'] : 0.0,
        //        'programmingCost' => $nreItems['programming'] ? $nreRates['programming'] : 0.0,
        //        'firstArticleCost' => $nreItems['firstArticle'] ? $nreRates['firstArticle'] : 0.0
        //    ];
        //    
        //    $totalNre = array_sum($costs);
        // 
        // 3. Return NRE breakdown:
        //    return array_merge($costs, ['totalNre' => round($totalNre, 2)]);

        // 1. Query NreTable for item costs
        $nreRates = [];
        $items = ['stencil', 'fixture', 'programming', 'firstArticle'];
        
        foreach ($items as $item) {
            $rate = $this->nreTableRepository->createQueryBuilder('n')
                ->where('n.itemType = :type')
                ->setParameter('type', strtoupper($item))
                ->orderBy('n.asof', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            
            // Default costs if not found in database
            $defaults = [
                'stencil' => 150.00,
                'fixture' => 500.00,
                'programming' => 200.00,
                'firstArticle' => 300.00
            ];
            
            $nreRates[$item] = $rate?->getCost() ?? $defaults[$item];
        }
        
        // 2. Calculate total NRE based on requested items
        $costs = [
            'stencilCost' => ($nreItems['stencil'] ?? false) ? $nreRates['stencil'] : 0.0,
            'fixtureCost' => ($nreItems['fixture'] ?? false) ? $nreRates['fixture'] : 0.0,
            'programmingCost' => ($nreItems['programming'] ?? false) ? $nreRates['programming'] : 0.0,
            'firstArticleCost' => ($nreItems['firstArticle'] ?? false) ? $nreRates['firstArticle'] : 0.0
        ];
        
        $totalNre = array_sum($costs);
        
        // 3. Return NRE breakdown
        return array_merge($costs, ['totalNre' => round($totalNre, 2)]);
    }

    /**
     * Check production capacity and book slot
     * 
     * @param \DateTime $requestedDate - Requested production date
     * @param int $quantityBoards - Quantity of boards to produce
     * 
     * @return array{
     *   available: bool,
     *   confirmedDate: \DateTime|null,
     *   capacityRemaining: int|null,
     *   slotId: int|null
     * }
     */
    public function checkCapacity(\DateTime $requestedDate, int $quantityBoards): array
    {
        // TODO: Implement capacity checking
        // 
        // Steps:
        // 1. Query CapacityCalendar for requested date:
        //    $slot = $this->capacityCalendarRepository->findOneBy([
        //        'productionDate' => $requestedDate
        //    ]);
        // 
        // 2. Check if slot exists and has capacity:
        //    if (!$slot) {
        //        // No slot defined for this date
        //        return [
        //            'available' => false,
        //            'confirmedDate' => null,
        //            'capacityRemaining' => null,
        //            'slotId' => null
        //        ];
        //    }
        //    
        //    $capacityRemaining = $slot->getMaxBoards() - $slot->getBookedBoards();
        //    
        //    if ($capacityRemaining < $quantityBoards) {
        //        // Not enough capacity
        //        return [
        //            'available' => false,
        //            'confirmedDate' => null,
        //            'capacityRemaining' => $capacityRemaining,
        //            'slotId' => $slot->getId()
        //        ];
        //    }
        // 
        // 3. Return availability:
        //    return [
        //        'available' => true,
        //        'confirmedDate' => $requestedDate,
        //        'capacityRemaining' => $capacityRemaining,
        //        'slotId' => $slot->getId()
        //    ];

        // 1. Query CapacityCalendar for requested date
        $slot = $this->capacityCalendarRepository->findOneBy([
            'productionDate' => $requestedDate
        ]);
        
        // 2. Check if slot exists and has capacity
        if (!$slot) {
            return [
                'available' => false,
                'confirmedDate' => null,
                'capacityRemaining' => null,
                'slotId' => null
            ];
        }
        
        $capacityRemaining = $slot->getMaxBoards() - $slot->getBookedBoards();
        
        if ($capacityRemaining < $quantityBoards) {
            return [
                'available' => false,
                'confirmedDate' => null,
                'capacityRemaining' => $capacityRemaining,
                'slotId' => $slot->getId()
            ];
        }
        
        // 3. Return availability
        return [
            'available' => true,
            'confirmedDate' => $requestedDate,
            'capacityRemaining' => $capacityRemaining,
            'slotId' => $slot->getId()
        ];
    }

    /**
     * Book production slot in capacity calendar
     * 
     * @param int $slotId - CapacityCalendar slot ID
     * @param int $quantityBoards - Quantity to book
     * @param int $quoteId - Quote ID
     * 
     * @return bool - True if booking successful
     */
    public function bookCapacity(int $slotId, int $quantityBoards, int $quoteId): bool
    {
        // TODO: Implement capacity booking
        // 
        // Steps:
        // 1. Get capacity slot:
        //    $slot = $this->capacityCalendarRepository->find($slotId);
        //    if (!$slot) {
        //        throw new \RuntimeException("Capacity slot $slotId not found");
        //    }
        // 
        // 2. Check capacity still available:
        //    $capacityRemaining = $slot->getMaxBoards() - $slot->getBookedBoards();
        //    if ($capacityRemaining < $quantityBoards) {
        //        return false; // Capacity no longer available
        //    }
        // 
        // 3. Update booked_boards:
        //    $slot->setBookedBoards($slot->getBookedBoards() + $quantityBoards);
        // 
        // 4. Add quote ID to bookings JSON:
        //    $bookings = json_decode($slot->getBookingsJson() ?? '[]', true);
        //    $bookings[] = [
        //        'quoteId' => $quoteId,
        //        'quantity' => $quantityBoards,
        //        'bookedAt' => (new \DateTime())->format('Y-m-d H:i:s')
        //    ];
        //    $slot->setBookingsJson(json_encode($bookings));
        // 
        // 5. Flush changes:
        //    $this->entityManager->flush();
        //    return true;

        // 1. Get capacity slot
        $slot = $this->capacityCalendarRepository->find($slotId);
        if (!$slot) {
            throw new \RuntimeException("Capacity slot $slotId not found");
        }
        
        // 2. Check capacity still available
        $capacityRemaining = $slot->getMaxBoards() - $slot->getBookedBoards();
        if ($capacityRemaining < $quantityBoards) {
            return false;
        }
        
        // 3. Update booked_boards
        $slot->setBookedBoards($slot->getBookedBoards() + $quantityBoards);
        
        // 4. Add quote ID to bookings JSON
        $bookings = json_decode($slot->getBookingsJson() ?? '[]', true);
        $bookings[] = [
            'quoteId' => $quoteId,
            'quantity' => $quantityBoards,
            'bookedAt' => (new \DateTime())->format('Y-m-d H:i:s')
        ];
        $slot->setBookingsJson(json_encode($bookings));
        
        // 5. Flush changes
        $this->entityManager->flush();
        return true;
    }

    /**
     * Get total manufacturing cost (PCB + ASM + NRE)
     * 
     * @param array $pcbSpec - PCB specification
     * @param array $asmSpec - Assembly specification
     * @param array $nreItems - NRE items
     * 
     * @return array{
     *   pcbCost: float,
     *   asmCost: float,
     *   nreCost: float,
     *   totalMfgCost: float,
     *   breakdown: array
     * }
     */
    public function getTotalMfgCost(array $pcbSpec, array $asmSpec, array $nreItems): array
    {
        // 1. Calculate each component cost
        $pcbCost = $this->calculatePcbCost($pcbSpec);
        $asmCost = $this->calculateAsmCost($asmSpec);
        $nreCost = $this->calculateNre($nreItems);
        
        // 2. Sum total manufacturing cost
        $totalMfgCost = $pcbCost['totalCost'] + $asmCost['totalCost'] + $nreCost['totalNre'];
        
        // 3. Return comprehensive breakdown
        return [
            'pcbCost' => $pcbCost['totalCost'],
            'asmCost' => $asmCost['totalCost'],
            'nreCost' => $nreCost['totalNre'],
            'totalMfgCost' => round($totalMfgCost, 2),
            'breakdown' => [
                'pcb' => $pcbCost,
                'asm' => $asmCost,
                'nre' => $nreCost
            ]
        ];
    }
}
