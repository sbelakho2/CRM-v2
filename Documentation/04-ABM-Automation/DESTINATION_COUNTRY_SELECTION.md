# Destination Country Selection Enhancement

**Date:** October 29, 2025  
**Enhancement:** Operator Destination Country Selection  
**Affected Components:** QuoteEstimatorController, QuoteCoPilotController

---

## Overview

Enhanced both Quote Estimator and Quote Co-Pilot features to allow operators to select destination countries for landed-cost calculations and shipping estimates.

---

## Changes Made

### 1. QuoteEstimatorController

**Enhanced Methods:**
- `index()` - Now passes comprehensive country list to template
- `getCountryList()` - Expanded from 7 to 50+ countries

**Country Coverage:**
- **North America:** US, CA, MX (3 countries)
- **Europe:** FR, DE, GB, IT, ES, NL, BE, PL, SE, NO, CH, AT, IE, DK, FI, PT, CZ, RO, GR (19 countries)
- **Middle East & Africa:** MA, EG, ZA, AE, SA, IL, TR, KE, NG (9 countries)
- **Asia Pacific:** CN, JP, KR, IN, SG, MY, TH, VN, ID, PH, TW, HK, AU, NZ (14 countries)
- **South America:** BR, AR, CL, CO, PE (5 countries)

**Total:** 50 countries

**Form Fields:**
```php
[
    'originCountry' => 'MA',           // Default: Morocco
    'destinationCountry' => 'REQUIRED', // Operator selects
    'weightKg' => 'float',
    'volumeM3' => 'float',
    'htsCode' => 'string',             // Optional
    'goodsValue' => 'float',
    'incoterm' => 'EXW|FOB|CIF|DDP'
]
```

### 2. QuoteCoPilotController

**Enhanced Methods:**
- `index()` - Now passes country list for destination selection
- `process()` - Validates destination country parameter
- `getCountryList()` - Added same comprehensive country list

**New Form Parameters:**
```php
[
    'bomFile' => 'UploadedFile',       // REQUIRED
    'quantity' => 'int',
    'destinationCountry' => 'REQUIRED', // NEW - operator selects
    'originCountry' => 'MA',           // Default: Morocco
    'pcbLayers' => 'int',
    'pcbDimensionX' => 'float',
    'pcbDimensionY' => 'float',
    'runDfm' => 'bool',
    'autoPublish' => 'bool'
]
```

---

## Use Cases

### Use Case 1: Quote Estimator - US Shipment
**Scenario:** Operator needs landed-cost estimate for US customer

**Process:**
1. Navigate to Quote Estimator
2. Select Origin: Morocco (default)
3. **Select Destination: United States** ← Operator choice
4. Enter weight: 25 kg
5. Enter volume: 0.5 m³
6. Enter goods value: $5,000
7. Select incoterm: FOB
8. Calculate

**Result:**
- Freight cost calculated for MA → US route
- Import duties calculated using US tariff rates
- FTA eligibility checked (Morocco-US FTA if applicable)
- Total landed cost: Goods + Freight + Duty + VAT

### Use Case 2: Quote Co-Pilot - Multiple Destinations
**Scenario:** Same BOM, different destinations for comparison

**Process:**
1. Upload BOM file (electronics assembly)
2. Enter quantity: 500 units
3. **First Quote - Destination: Germany**
   - Process BOM → Get pricing from Mouser/DigiKey
   - Calculate landed cost for DE
   - EU duties + VAT
4. **Second Quote - Destination: China**
   - Same BOM, different destination
   - Calculate landed cost for CN
   - CN duties + import fees

**Result:**
- Two quotes with different landed costs
- Operator can compare profitability by destination
- Customer can choose optimal shipping destination

### Use Case 3: FTA Optimization
**Scenario:** Leverage Morocco FTAs for better pricing

**Destinations with Morocco FTAs:**
- United States (Morocco-US FTA)
- European Union (Morocco-EU Association Agreement)
- Turkey (Morocco-Turkey FTA)
- Egypt (Agadir Agreement)
- Jordan, Tunisia (Pan-Arab FTAs)

**Process:**
1. Operator selects destination: **United States**
2. System checks Morocco-US FTA eligibility
3. If eligible:
   - Calculate duty with FTA rate (0% or reduced)
   - Calculate duty with MFN rate (standard)
   - Show savings: $500 duty saved with FTA
4. If not eligible:
   - Show standard MFN duty
   - Suggest alternative sourcing

---

## Technical Implementation

### Country List Format
```php
private function getCountryList(): array
{
    return [
        'US' => 'United States',
        'CA' => 'Canada',
        'MX' => 'Mexico',
        // ... 47 more countries
    ];
}
```

**Benefits:**
- ISO 3166-1 alpha-2 country codes (standard)
- Human-readable names for dropdown
- Easy to extend (add more countries)
- Consistent across both features

### Template Integration
```twig
{# templates/quote_estimator/index.html.twig #}
<select name="destination_country" required>
    <option value="">Select destination...</option>
    {% for code, name in countries %}
        <option value="{{ code }}">{{ name }}</option>
    {% endfor %}
</select>
```

### Validation
```php
// QuoteEstimatorController::calculate()
if (!$data['destinationCountry'] || $data['weightKg'] <= 0) {
    $this->addFlash('error', 'Please fill in all required fields');
    return $this->redirectToRoute('quote_estimator_index');
}

// QuoteCoPilotController::process()
if (!$params['destinationCountry']) {
    $this->addFlash('error', 'Please select a destination country');
    return $this->redirectToRoute('quote_copilot_index');
}
```

---

## Future Enhancements

### 1. Regional Grouping
**Improvement:** Group countries by region in dropdown

```html
<select name="destination_country">
    <optgroup label="North America">
        <option value="US">United States</option>
        <option value="CA">Canada</option>
        <option value="MX">Mexico</option>
    </optgroup>
    <optgroup label="Europe">
        <option value="FR">France</option>
        <!-- ... -->
    </optgroup>
</select>
```

**Benefits:**
- Easier navigation for operators
- Better UX with 50+ countries
- Visual organization

### 2. Recent Destinations
**Improvement:** Show operator's recently used destinations

```php
// Store in session or user preferences
$recentDestinations = ['US', 'FR', 'DE'];

return $this->render('quote_estimator/index.html.twig', [
    'countries' => $this->getCountryList(),
    'recentDestinations' => $recentDestinations
]);
```

**UI:**
```
Quick Select: [US] [FR] [DE]

Or choose from all countries:
[Dropdown with all 50 countries]
```

### 3. Default Destination by Customer
**Improvement:** Auto-select destination based on customer's country

```php
// If customer selected, pre-fill destination
$customer = $this->getCustomer($customerId);
$defaultDestination = $customer->getCountry(); // 'US'

return $this->render('quote_estimator/index.html.twig', [
    'countries' => $this->getCountryList(),
    'defaultDestination' => $defaultDestination
]);
```

### 4. Multi-Destination Comparison
**Improvement:** Calculate landed costs for multiple destinations simultaneously

```php
// Quote Co-Pilot results page
$destinations = ['US', 'FR', 'CN'];
$comparisons = [];

foreach ($destinations as $dest) {
    $comparisons[$dest] = [
        'freight' => $this->calculateFreight($quote, $dest),
        'duty' => $this->calculateDuty($quote, $dest),
        'total' => $this->calculateTotal($quote, $dest)
    ];
}
```

**UI:**
```
| Destination | Freight | Duty  | Total   |
|-------------|---------|-------|---------|
| USA         | $500    | $120  | $5,620  |
| France      | $450    | $200  | $5,650  |
| China       | $380    | $0    | $5,380  | ← Best option
```

### 5. Country-Specific Warnings
**Improvement:** Show import restrictions by country

```php
$countryWarnings = [
    'CN' => 'Electronics require CCC certification',
    'BR' => 'High import duties (avg 60%) + complex customs',
    'IN' => 'GST 18% + potential anti-dumping duties'
];
```

### 6. Database-Driven Country List
**Improvement:** Move country list to database table

```sql
CREATE TABLE country (
    code VARCHAR(2) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    region VARCHAR(50),
    active BOOLEAN DEFAULT TRUE,
    has_fta BOOLEAN DEFAULT FALSE,
    import_restrictions TEXT
);
```

**Benefits:**
- Easy to add/remove countries without code changes
- Can track FTA status per country
- Store country-specific metadata
- Support multi-language country names

---

## Impact Summary

**Before Enhancement:**
- Fixed country list (7 countries)
- No destination selection in Quote Co-Pilot
- Limited global coverage

**After Enhancement:**
- ✅ 50+ countries supported
- ✅ Comprehensive regional coverage (5 continents)
- ✅ Destination selection in both Quote Estimator and Quote Co-Pilot
- ✅ FTA-aware routing and duty calculation
- ✅ Operator can compare landed costs by destination
- ✅ Support for global supply chain scenarios

**Business Value:**
- Support global customer base
- Accurate landed-cost calculations for any destination
- FTA optimization opportunities
- Multi-destination quote comparison
- Better pricing for international customers
