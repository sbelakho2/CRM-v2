# Quote Co-Pilot Enhancements: Quantity-Based Pricing & Delivery Times

**Date:** October 29, 2025  
**Feature:** Quote Co-Pilot Service Enhancements  
**Enhancement Type:** API Integration - Pricing & Delivery Logic

---

## Overview

Enhanced the `QuoteCoPilotService` to support:
1. **Quantity-based pricing** with price breaks from suppliers
2. **Delivery time tracking** based on stock availability
3. **Multiple delivery scenarios** (in-stock, partial stock, factory order)

---

## Key Enhancements

### 1. Quantity-Based Pricing

**Problem:** Original design only captured single unit price, ignoring volume discounts.

**Solution:**
- APIs return price breaks: `[{qty: 1, price: 0.50}, {qty: 100, price: 0.45}, {qty: 500, price: 0.40}]`
- System selects appropriate tier based on BOM quantity
- Higher quantities = lower unit prices (standard supplier pricing model)
- All price breaks stored in JSON for quote optimization

**Implementation:**
```php
private function selectPriceForQuantity(array $priceBreaks, int $requestedQty): float
{
    // Sort and find highest tier that requestedQty qualifies for
    // Example: qty=250 selects 100+ tier at $0.45
}
```

### 2. Delivery Time Tracking

**Problem:** Original design only had single `leadTime` field, didn't account for stock availability.

**Solution:**
- Track 3 delivery scenarios:
  - **In-stock:** 3 days (immediate shipment)
  - **Partial stock:** 7 days + factory lead time (mixed fulfillment)
  - **Factory order:** Full factory lead time (14-84 days)
- Store both `inStockQuantity` and `factoryLeadTimeDays`
- Calculate realistic delivery based on requested quantity

**Implementation:**
```php
private function calculateLeadTime(int $requestedQty, array $availability): int
{
    if ($requestedQty <= $inStock) {
        return 3; // Ship from stock
    } elseif ($inStock > 0) {
        return 7 + $factoryLeadTime; // Partial + factory order
    } else {
        return $factoryLeadTime; // Full factory order
    }
}
```

### 3. Supplier-Specific Logic

#### Mouser API
```php
// Returns:
// - priceBreaks: [{qty: 1, price: 0.50}, {qty: 100, price: 0.45}, ...]
// - availability: {inStock: 500, factoryLeadTime: 14}

$selectedPrice = $this->selectPriceForQuantity($mouseResult['priceBreaks'], $bomLine['qty']);
$leadTime = $this->calculateLeadTime($bomLine['qty'], $mouseResult['availability']);
```

**Fields Captured:**
- `unitPrice` - Selected price for requested qty
- `priceBreaks` - JSON array of all price tiers
- `leadTimeDays` - Calculated delivery time
- `inStockQuantity` - Available stock
- `factoryLeadTimeDays` - Factory order lead time

#### DigiKey API
```php
// Returns:
// - pricing: [{breakQuantity: 1, unitPrice: 0.52}, {breakQuantity: 100, unitPrice: 0.47}, ...]
// - quantityAvailable: 1200
// - manufacturer: {leadTime: "12 weeks", standardLeadTime: 84}

$selectedPrice = $this->selectPriceForQuantity(
    array_map(fn($p) => ['qty' => $p['breakQuantity'], 'price' => $p['unitPrice']], $digikeyResult['pricing']),
    $bomLine['qty']
);
```

**DigiKey-Specific:**
- Different JSON structure (normalized to standard format)
- `quantityAvailable` instead of `inStock`
- `standardLeadTime` for factory orders

#### Alibaba API
```php
// Returns:
// - priceRanges: [{minQty: 100, maxQty: 999, price: 0.35}, {minQty: 1000, price: 0.30}]
// - deliveryTime: "15-25 days" (string format)

$selectedPrice = $this->selectPriceForQuantity(
    array_map(fn($p) => ['qty' => $p['minQty'], 'price' => $p['price']], $alibabaResult['priceRanges']),
    $bomLine['qty']
);
$leadTime = $this->parseAlibabaDeliveryTime($alibabaResult['deliveryTime']);
```

**Alibaba-Specific:**
- Range-based pricing (minQty/maxQty)
- String-based delivery times ("15-25 days", "3-5 weeks")
- No stock reporting (always factory orders)
- Parser extracts maximum days from range

#### Nexar API (Aggregator)
```php
// Returns multiple distributor offers:
// [{
//   distributor: 'Arrow',
//   priceBreaks: [...],
//   availability: {inStock: 300, factoryLeadTime: 21}
// }, ...]

$bestOffer = $this->selectBestNexarOffer($nexarResult['offers'], $bomLine['qty']);
```

**Nexar-Specific:**
- Aggregates multiple distributors (Arrow, Avnet, etc.)
- System selects best offer based on total cost + lead time
- Scoring: `(unitPrice * 1000) + (leadTime * 0.1)`
- Returns supplier name with "(via Nexar)" suffix

---

## BomLine Entity Schema (Future Implementation)

When creating the BomLine entity, include these fields:

```php
class BomLine
{
    // Existing fields
    private ?int $id = null;
    private int $quoteId;
    private string $designator;
    private string $mpn;
    private string $manufacturer;
    private int $qty;
    private ?string $description = null;
    
    // ENHANCED: Pricing fields
    private ?float $unitPrice = null;
    private ?string $priceBreaksJson = null; // JSON array of all price tiers
    
    // ENHANCED: Delivery time fields
    private ?int $leadTimeDays = null; // Calculated delivery time
    private ?int $inStockQuantity = null; // Available stock at supplier
    private ?int $factoryLeadTimeDays = null; // Factory order lead time
    
    // Existing sourcing fields
    private ?string $supplier = null;
    private ?string $sourceMethod = null; // MOUSER, DIGIKEY, NEXAR, ALIBABA, PRICEBOOK, IMPUTED
    private ?string $htsCode = null;
    private ?float $htsConfidence = null;
}
```

**Migration Required:**
```sql
ALTER TABLE bom_line ADD COLUMN price_breaks_json TEXT DEFAULT NULL;
ALTER TABLE bom_line ADD COLUMN in_stock_quantity INTEGER DEFAULT NULL;
ALTER TABLE bom_line ADD COLUMN factory_lead_time_days INTEGER DEFAULT NULL;
```

---

## Use Cases

### Use Case 1: In-Stock Order (Fast Delivery)
**Scenario:** Customer needs 50 units, supplier has 500 in stock

**Process:**
1. API returns: `{inStock: 500, factoryLeadTime: 14}`
2. System calculates: `50 <= 500` → **3 days** (ship from stock)
3. Price tier: 1-99 units at $0.50/unit
4. Quote shows: 50 units × $0.50 = $25, delivery in 3 days

### Use Case 2: Partial Stock (Mixed Fulfillment)
**Scenario:** Customer needs 300 units, supplier has 150 in stock

**Process:**
1. API returns: `{inStock: 150, factoryLeadTime: 14}`
2. System calculates: `300 > 150 but inStock > 0` → **21 days** (7 + 14)
3. Price tier: 100-499 units at $0.45/unit (volume discount applies)
4. Quote shows: 300 units × $0.45 = $135, delivery in 21 days
5. Note: Partial shipment (150 in 3 days, 150 in 21 days)

### Use Case 3: Factory Order (Long Lead)
**Scenario:** Customer needs 1000 units, supplier has 0 in stock

**Process:**
1. API returns: `{inStock: 0, factoryLeadTime: 42}`
2. System calculates: `inStock = 0` → **42 days** (full factory order)
3. Price tier: 500+ units at $0.40/unit (best volume discount)
4. Quote shows: 1000 units × $0.40 = $400, delivery in 42 days

### Use Case 4: Quantity Optimization
**Scenario:** System suggests increasing order qty for better pricing

**Current Order:** 95 units at $0.50/unit = $47.50 (1-99 tier)
**Optimized Order:** 100 units at $0.45/unit = $45.00 (100+ tier)

**Savings:** $2.50 for 5 extra units (better unit economics)

**Implementation:**
- UI shows price break thresholds
- "Order 5 more units to save $2.50" suggestion
- Automated optimization algorithm (future enhancement)

---

## API Response Examples

### Mouser API Response
```json
{
  "found": true,
  "mpn": "GRM155R71C104KA88D",
  "manufacturer": "Murata",
  "priceBreaks": [
    {"qty": 1, "price": 0.50},
    {"qty": 100, "price": 0.45},
    {"qty": 500, "price": 0.40},
    {"qty": 1000, "price": 0.35}
  ],
  "availability": {
    "inStock": 5000,
    "factoryLeadTime": 14
  }
}
```

### DigiKey API Response
```json
{
  "found": true,
  "digiKeyPartNumber": "490-3261-1-ND",
  "manufacturerPartNumber": "GRM155R71C104KA88D",
  "pricing": [
    {"breakQuantity": 1, "unitPrice": 0.52},
    {"breakQuantity": 100, "unitPrice": 0.47},
    {"breakQuantity": 500, "unitPrice": 0.42}
  ],
  "quantityAvailable": 12000,
  "manufacturer": {
    "leadTime": "12 weeks",
    "standardLeadTime": 84
  }
}
```

### Alibaba API Response
```json
{
  "found": true,
  "productName": "Ceramic Capacitor 100nF 16V 0402",
  "priceRanges": [
    {"minQty": 100, "maxQty": 999, "price": 0.35},
    {"minQty": 1000, "maxQty": 4999, "price": 0.30},
    {"minQty": 5000, "price": 0.25}
  ],
  "deliveryTime": "15-25 days",
  "moq": 100
}
```

### Nexar API Response
```json
{
  "found": true,
  "mpn": "GRM155R71C104KA88D",
  "offers": [
    {
      "distributor": "Arrow",
      "priceBreaks": [
        {"qty": 1, "price": 0.48},
        {"qty": 100, "price": 0.43}
      ],
      "availability": {
        "inStock": 3000,
        "factoryLeadTime": 21
      }
    },
    {
      "distributor": "Avnet",
      "priceBreaks": [
        {"qty": 1, "price": 0.51},
        {"qty": 100, "price": 0.46}
      ],
      "availability": {
        "inStock": 1500,
        "factoryLeadTime": 28
      }
    }
  ]
}
```

---

## Helper Methods

### selectPriceForQuantity()
**Purpose:** Find appropriate price tier for requested quantity

**Algorithm:**
1. Sort price breaks by quantity (ascending)
2. Iterate through breaks
3. Select highest tier where `requestedQty >= break.qty`
4. Stop when exceed requestedQty

**Example:**
- Price breaks: 1/$0.50, 100/$0.45, 500/$0.40
- Requested: 250 units
- Result: $0.45 (100+ tier)

### calculateLeadTime()
**Purpose:** Calculate realistic delivery time based on stock

**Logic:**
```
IF requestedQty <= inStock:
    RETURN 3 days (ship from stock)
ELSE IF inStock > 0:
    RETURN 7 + factoryLeadTime (mixed fulfillment)
ELSE:
    RETURN factoryLeadTime (full factory order)
```

### parseAlibabaDeliveryTime()
**Purpose:** Convert Alibaba string times to numeric days

**Patterns:**
- "15-25 days" → 25 (use maximum)
- "3-5 weeks" → 35 (5 weeks × 7 days)
- "30 days" → 30
- Unparseable → 30 (default)

### selectBestNexarOffer()
**Purpose:** Choose best distributor from Nexar aggregated results

**Scoring Formula:**
```
score = (unitPrice × 1000) + (leadTime × 0.1)
```

**Example:**
- Arrow: $0.48/unit, 10 days → score = 481
- Avnet: $0.46/unit, 21 days → score = 462.1
- **Winner:** Avnet (lower score = better)

---

## Exception Handling

### Quantity Too Low for Price Break
**Scenario:** Alibaba MOQ = 100, customer requests 50

**Handling:**
1. Create exception: `MOQ_NOT_MET`
2. Severity: `MEDIUM`
3. Message: "Minimum order quantity is 100 units (requested: 50)"
4. Suggestion: "Increase quantity to 100 or source from different supplier"

### Long Lead Time Warning
**Scenario:** Factory lead time = 120 days (> 12 weeks threshold)

**Handling:**
1. Create exception: `LONG_LEAD_TIME`
2. Severity: `HIGH`
3. Message: "Lead time exceeds 12 weeks (120 days)"
4. Blocks auto-publish (requires manual review)

### Stock Shortage
**Scenario:** Requested 500, only 100 in stock, factory lead time unknown

**Handling:**
1. Use partial stock calculation
2. Default factory lead time: 84 days
3. Create warning: `STOCK_SHORTAGE`
4. Severity: `LOW`
5. Message: "Partial stock available (100/500), remaining lead time estimated"

---

## Next Steps

When implementing the BomLine entity and Quote Co-Pilot feature:

1. **Add new fields to BomLine entity**
   - `priceBreaksJson` (TEXT)
   - `inStockQuantity` (INTEGER)
   - `factoryLeadTimeDays` (INTEGER)

2. **Create migration**
   - Add columns to `bom_line` table
   - Default values: NULL (optional fields)

3. **Update QuoteCoPilotService**
   - Implement API client methods (callMouserApi, callDigikeyApi, etc.)
   - Wire up helper methods
   - Test quantity-based pricing logic
   - Test delivery time calculations

4. **Create UI components**
   - Price breaks table in quote results
   - Delivery timeline visualization
   - Quantity optimization suggestions
   - Stock availability indicators

5. **Add business rules**
   - MOQ validation
   - Long lead time warnings
   - Auto-publish criteria (check lead times)
   - Quote optimization recommendations

---

## Impact Summary

**Before Enhancement:**
- Single unit price (no volume discounts)
- Single lead time (no stock awareness)
- Binary sourcing (found vs not found)

**After Enhancement:**
- ✅ Volume pricing with price breaks
- ✅ Quantity-aware pricing selection
- ✅ Stock-based delivery calculations
- ✅ Multiple delivery scenarios (in-stock, partial, factory)
- ✅ Supplier-specific parsing logic
- ✅ Nexar multi-distributor comparison
- ✅ Alibaba delivery time parsing
- ✅ Quote optimization opportunities

**Business Value:**
- More accurate quotes (volume discounts reflected)
- Realistic delivery timelines (stock awareness)
- Better supplier selection (price + lead time scoring)
- Customer optimization suggestions (quantity recommendations)
- Improved auto-publish accuracy (lead time validation)
