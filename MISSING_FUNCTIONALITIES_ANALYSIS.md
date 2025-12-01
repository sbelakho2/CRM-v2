# Missing Functionalities Analysis Report

**Date**: November 22, 2025  
**Project**: Starz Morocco CRM v2  
**Analysis Scope**: ABM Dashboard, Supplier Portal, Quote Copilot, Quote Estimator, Datasets, and Related Pages

---

## Executive Summary

This document analyzes the missing functionalities across 5 major system sections based on the existing documentation and codebase. The analysis reveals that while **frontend templates and controller structures exist**, most **backend business logic remains unimplemented**. The system has comprehensive documentation and TODO placeholders for future implementation.

**Overall Status**: 📊 **30-40% Complete** (Architecture ✅ Complete, Implementation ⏳ Pending)

---

## 1. ABM Dashboard

### Status: ⚠️ **40% Complete**

### What Exists ✅

**Architecture & Documentation:**

- ✅ Complete documentation (`ABM_PLAYBOOKS.md`)
- ✅ Controller with 6 routes defined (`AbmDashboardController.php`)
- ✅ 4 frontend templates created
- ✅ Service classes scaffolded (`AbmResolverService`, `PlaybookEngine`, `EngagementHeatMapService`)
- ✅ Database entities (`AbmAccount`, `AbmHit`, `Playbook`, `PlaybookRun`)
- ✅ Repository classes with query methods

**Completed Features:**

- ✅ Route definitions with TODO placeholders
- ✅ Database schema with proper indexes
- ✅ Template structure with Geist/Claude design

### Missing Functionalities ❌

#### 1.1 Core ABM Features (NOT IMPLEMENTED)

**Visitor De-Anonymization:**

- ❌ IP-to-Company resolution logic (`AbmResolverService::resolveIp()`)
- ❌ IpMap database population (no IP range data imported)
- ❌ WebEvent tracking JavaScript (not embedded in `base.html.twig`)
- ❌ Automated hit creation from web events
- ❌ ISP filtering (residential vs corporate IP detection)

**Dashboard Display:**

- ❌ Recent activity feed (last 24 hours)
- ❌ Top engaged accounts calculation
- ❌ Real-time visitor tracking
- ❌ Engagement metrics display
- ❌ Activity timeline visualization

**Account Management:**

- ❌ Account list with filters
- ❌ ICP (Ideal Customer Profile) scoring
- ❌ Engagement score calculation
- ❌ Account detail page with visit history
- ❌ Account-to-Company linking

#### 1.2 Playbook Automation (NOT IMPLEMENTED)

**Playbook Engine:**

- ❌ Trigger rule evaluation (`PlaybookEngine::evaluatePlaybooks()`)
- ❌ Condition matching logic (URL contains, score threshold, visit count)
- ❌ Action execution (create activity, send email, update lead score)
- ❌ Cooldown mechanism to prevent re-triggering
- ❌ Playbook run logging and audit trail

**Playbook Management UI:**

- ❌ Playbook creation form
- ❌ Trigger rule builder (visual/JSON editor)
- ❌ Action configuration interface
- ❌ Enable/disable toggle functionality
- ❌ Playbook performance metrics
- ❌ Test playbook with sample data

#### 1.3 Engagement Heat Map (NOT IMPLEMENTED)

- ❌ Heat map data generation (`EngagementHeatMapService`)
- ❌ Top 20 companies by engagement
- ❌ Color-coded visualization (red/yellow/green)
- ❌ Interactive tooltips
- ❌ Click-through to company detail

**Impact**: Medium-High priority. ABM is documented but non-functional without these implementations.

---

## 2. Supplier Portal Automation

### Status: ⚠️ **35% Complete**

### What Exists ✅

**Architecture & Documentation:**

- ✅ Complete documentation (`PORTAL_AUTOMATION.md`)
- ✅ Controller with 6 routes (`SupplierPortalController.php`)
- ✅ 2 frontend templates (index, detail)
- ✅ Service classes scaffolded (`PortalCrawlerService`, `OnboardingPackService`)
- ✅ Database entities (`SupplierPortal`, `PortalCandidate`, `OnboardingPack`)

### Missing Functionalities ❌

#### 2.1 Portal Discovery (NOT IMPLEMENTED)

**Web Crawling:**

- ❌ robots.txt parser (`PortalCrawlerService::analyzeRobotsTxt()`)
- ❌ Sitemap crawler (`crawlSitemap()`)
- ❌ Homepage link extraction with Symfony Panther
- ❌ Portal URL pattern detection (keywords: supplier, vendor, partner, portal)
- ❌ Vendor platform detection (Ariba, Coupa, Jaggaer, SAP SRM)

**Candidate Management:**

- ❌ PortalCandidate entity workflow
- ❌ Manual review UI for discovered portals
- ❌ Approve/reject actions
- ❌ Portal preview in iframe
- ❌ Conversion to SupplierPortal entity

#### 2.2 Form Field Mapping (NOT IMPLEMENTED)

**Field Detection:**

- ❌ Automatic form field detection (`detectFormFields()`)
- ❌ Field-to-CRM mapping interface
- ❌ `formFieldsJson` structure generation
- ❌ Selector (CSS/XPath) identification
- ❌ Label and placeholder extraction

**Mapping Configuration:**

- ❌ Field mapping UI (iframe + inspector panel)
- ❌ Dropdown to select CRM field mapping
- ❌ Required field marking
- ❌ Submit button selector configuration
- ❌ Success indicator configuration

#### 2.3 Onboarding Pack Generation (NOT IMPLEMENTED)

**Pack Creation:**

- ❌ Data extraction from Company entity (`extractDataFromCompany()`)
- ❌ OnboardingPack entity creation
- ❌ Pre-filled form data JSON generation
- ❌ Document attachment handling (certifications, W-9, capabilities)
- ❌ PDF pack generation

**Auto-Submit:**

- ❌ Form filling with Symfony Panther
- ❌ Field population logic (text, select, file upload)
- ❌ CAPTCHA detection and handling
- ❌ Form submission automation
- ❌ Success/failure detection
- ❌ Submission status tracking

#### 2.4 Credentials & Security (NOT IMPLEMENTED)

**Credentials Management:**

- ❌ Encryption/decryption service (AES-256)
- ❌ Credentials storage in `OnboardingPack.credentialsJson`
- ❌ Access control (ROLE_ADMIN only)
- ❌ Credential retrieval for follow-ups

**Compliance:**

- ❌ TOS (Terms of Service) review workflow
- ❌ TOS allowsAutomation flag checking
- ❌ Semi-automated mode (pre-fill + manual submit)
- ❌ Legal review tracking

#### 2.5 Monitoring & Alerts (NOT IMPLEMENTED)

- ❌ Submission success rate calculation
- ❌ Portal change detection (form structure changes)
- ❌ Alert emails for low success rates (<80%)
- ❌ Approval status polling
- ❌ Integration with external status APIs

**Impact**: High priority. Critical for scaling supplier onboarding process.

---

## 3. Quote Co-Pilot

### Status: ⚠️ **45% Complete**

### What Exists ✅

**Architecture & Documentation:**

- ✅ Controller with 5 routes (`QuoteCoPilotController.php`)
- ✅ 2 frontend templates (index, results)
- ✅ Service scaffolding (`QuoteCoPilotService`, `DfmLintService`, `CostingEngineService`)
- ✅ Quote entity with coverage tracking
- ✅ PDF generation skeleton (`UnifiedPdfGeneratorService`)

**Partially Implemented:**

- ⚙️ BOM upload handling (basic file validation)
- ⚙️ Quote creation and persistence
- ⚙️ Results display template

### Missing Functionalities ❌

#### 3.1 BOM Processing (NOT IMPLEMENTED)

**File Parsing:**

- ❌ CSV parser (`QuoteCoPilotService::parseBom()`)
- ❌ Excel parser (.xlsx, .xls support via PhpSpreadsheet)
- ❌ Column mapping (MPN, Quantity, Description, Manufacturer)
- ❌ Header detection and normalization
- ❌ Data validation (required fields, formats)

**BOM Normalization:**

- ❌ MPN cleaning (remove spaces, special chars)
- ❌ Manufacturer name standardization
- ❌ Quantity parsing (handle units like "100 pcs", "1K")
- ❌ Description field extraction

#### 3.2 API Waterfall Pricing (NOT IMPLEMENTED)

**Supplier APIs:**

- ❌ Mouser API integration (API key, endpoint, rate limits)
- ❌ DigiKey API integration
- ❌ Nexar API integration (aggregator)
- ❌ Alibaba API integration
- ❌ Internal Pricebook query
- ❌ Imputation logic (last resort pricing)

**Waterfall Logic:**

- ❌ API priority chain: Mouser → DigiKey → Nexar → Alibaba → Pricebook → Imputation
- ❌ Failover handling (timeout, rate limit, no match)
- ❌ Response caching (24-hour TTL per MPN+quantity)
- ❌ Parallel API calls for performance
- ❌ MOQ (Minimum Order Quantity) checking

**Coverage Calculation:**

- ❌ Coverage percentage formula: `(sourced_parts / total_parts) * 100`
- ❌ Coverage threshold checking (60% minimum for auto-publish)
- ❌ Missing parts report generation
- ❌ Alternative part suggestions

#### 3.3 DFM/DFA Linting (NOT IMPLEMENTED)

**Linting Rules:**

- ❌ `DfmLintService::lintBom()` implementation
- ❌ Obsolete part detection (manufacturer lifecycle status)
- ❌ Single-source risk flagging (only 1 supplier)
- ❌ Lead time warnings (>12 weeks)
- ❌ Cost threshold alerts (unit price >$100)
- ❌ Counterfeit risk scoring

**Severity Levels:**

- ❌ CRITICAL (obsolete, counterfeit)
- ❌ WARNING (single-source, long lead time)
- ❌ INFO (alternative parts available)

#### 3.4 Costing Engine (NOT IMPLEMENTED)

**Cost Calculation:**

- ❌ `CostingEngineService::calculateCosts()` implementation
- ❌ PCB cost calculation (layers, dimensions, surface finish)
- ❌ Assembly (ASM) cost calculation (SMT, THT, mixed)
- ❌ NRE cost calculation (stencil, fixture, programming)
- ❌ Testing cost calculation (AOI, X-ray, functional)

**Margin Application:**

- ❌ Tiered margin by customer (A: 15%, B: 20%, C: 25%)
- ❌ Volume discounts (100+: -5%, 500+: -10%, 1000+: -15%)
- ❌ Freight and handling markup
- ❌ Duty and VAT inclusion

#### 3.5 Quote Publishing (PARTIAL IMPLEMENTATION)

**Coverage Validation:**

- ⚙️ Coverage threshold check (≥60%) - basic logic exists
- ❌ Missing parts exception handling
- ❌ Manual pricing flow for low coverage

**Email Integration:**

- ❌ Quote email template generation
- ❌ Contact selection interface (modal shown, but no backend)
- ❌ Email sending logic (`MailerInterface` injected but not used)
- ❌ Email tracking (opens, clicks, downloads)

**PDF Generation:**

- ⚙️ Basic PDF skeleton exists (`UnifiedPdfGeneratorService::generateQuotePdf()`)
- ❌ Quote template rendering with mPDF
- ❌ BOM breakdown table
- ❌ Pricing summary
- ❌ DFM warnings section
- ❌ Terms and conditions footer

#### 3.6 Quote Versioning (NOT IMPLEMENTED)

- ❌ Quote revision tracking
- ❌ Change history (what changed between v1 and v2)
- ❌ Side-by-side comparison view
- ❌ Rollback to previous version
- ❌ Customer-facing quote version numbers (QT-2025-001-R02)

**Impact**: High priority. Core revenue-generating feature requires API integrations and costing logic.

---

## 4. Quote Estimator

### Status: ⚠️ **30% Complete**

### What Exists ✅

**Architecture & Documentation:**

- ✅ Controller with 4 routes (`QuoteEstimatorController.php`)
- ✅ 2 frontend templates (index, results)
- ✅ Destination country selection (50+ countries)
- ✅ Service class scaffolding (6 services)
- ✅ Estimate entity for storing results

### Missing Functionalities ❌

#### 4.1 HTS Classification (NOT IMPLEMENTED)

**Classification Service:**

- ❌ `HtsClassificationService::classifyBom()` implementation
- ❌ Product description → HTS code mapping
- ❌ Machine learning-based classification (optional)
- ❌ Manual HTS code entry and validation
- ❌ HTS code database (10-digit codes with descriptions)

**Classification Methods:**

- ❌ Keyword matching (e.g., "resistor" → 8533.21.00)
- ❌ Material-based classification (plastic, metal, electronic)
- ❌ Product category lookup tables
- ❌ Fuzzy matching for partial descriptions

#### 4.2 Route Selection (NOT IMPLEMENTED)

**Shipping Modes:**

- ❌ `RouteSelectionService::selectOptimalRoute()` implementation
- ❌ AIR freight calculation (weight-based)
- ❌ LCL (Less than Container Load) calculation
- ❌ FCL (Full Container Load) calculation (20ft, 40ft, 40ft HQ)
- ❌ Express courier (DHL, FedEx, UPS)

**Route Optimization:**

- ❌ Cost vs. transit time trade-off
- ❌ Volume-to-weight ratio (volumetric weight)
- ❌ Lane availability checking (Morocco → US, Morocco → EU)
- ❌ Seasonal pricing variations
- ❌ Carrier comparison (multiple carriers per route)

#### 4.3 FTA Eligibility (NOT IMPLEMENTED)

**FTA Checking:**

- ❌ `FtaEligibilityService::checkEligibility()` implementation
- ❌ Morocco-US FTA rules of origin
- ❌ Morocco-EU Association Agreement
- ❌ Morocco-Turkey FTA
- ❌ Certificate of Origin requirements

**Savings Calculation:**

- ❌ MFN (Most Favored Nation) duty rate lookup
- ❌ FTA preferential duty rate lookup
- ❌ Savings calculation: `MFN_rate - FTA_rate`
- ❌ Savings display in estimate results

#### 4.4 Duty Calculation (NOT IMPLEMENTED)

**Duty Rates:**

- ❌ `DutyCalculationService::calculateDuty()` implementation
- ❌ Ad valorem duty (percentage of value)
- ❌ Specific duty (per unit/weight)
- ❌ Compound duty (ad valorem + specific)
- ❌ Anti-dumping duty (ADD)
- ❌ Countervailing duty (CVD)

**Tariff Database:**

- ❌ US HTS tariff schedule import
- ❌ EU TARIC database import
- ❌ China CCC tariff import
- ❌ Tariff rate updates (quarterly/annual)

**VAT/GST Calculation:**

- ❌ EU VAT rates by country (DE: 19%, FR: 20%, etc.)
- ❌ UK VAT (20%)
- ❌ Canada GST/HST
- ❌ China VAT (13%)

#### 4.5 Freight Pricing (NOT IMPLEMENTED)

**Freight Calculation:**

- ❌ `FreightPricingService::calculateFreight()` implementation
- ❌ Weight-based pricing tables
- ❌ Volume-based pricing tables
- ❌ Dimensional weight calculation: `(L × W × H) / 5000`
- ❌ Fuel surcharge application
- ❌ Security surcharge
- ❌ Peak season surcharge

**Freight Tables:**

- ❌ Morocco → US lanes (Casablanca → LAX, JFK, MIA, etc.)
- ❌ Morocco → EU lanes (Tangier → Rotterdam, Hamburg, etc.)
- ❌ Morocco → APAC lanes
- ❌ Incoterms impact on pricing (EXW, FOB, CIF, DDP)

#### 4.6 Estimate PDF Generation (NOT IMPLEMENTED)

**PDF Content:**

- ❌ `UnifiedPdfGeneratorService::generateEstimatePdf()` implementation
- ❌ Estimate summary (total landed cost)
- ❌ Breakdown table (goods value, freight, duty, VAT, total)
- ❌ Route comparison table (AIR vs SEA)
- ❌ FTA savings display (if applicable)
- ❌ Assumptions and disclaimers footer

#### 4.7 Multi-Scenario Comparison (NOT IMPLEMENTED)

**Scenario Builder:**

- ❌ Compare EXW vs FOB vs CIF vs DDP
- ❌ Compare AIR vs LCL vs FCL
- ❌ Compare with/without FTA
- ❌ Side-by-side comparison table
- ❌ "Best option" recommendation

**What-If Analysis:**

- ❌ Adjust goods value (±10%, ±20%)
- ❌ Adjust weight/volume
- ❌ Change destination country
- ❌ Real-time recalculation

**Impact**: Medium priority. Useful for quick ballpark quotes but less critical than Quote Co-Pilot.

---

## 5. Dataset Management

### Status: ⚠️ **35% Complete**

### What Exists ✅

**Architecture & Documentation:**

- ✅ Complete documentation (`DATASET_VERSIONING.md`)
- ✅ Controller with 5 routes (`AdminDatasetController.php`)
- ✅ 3 frontend templates (index, import_form, history)
- ✅ Service class (`DatasetImportService`)
- ✅ DatasetVersion entity with UUID versioning
- ✅ Repository with version history queries

**Database Schema:**

- ✅ `dataset_version` table with indexes
- ✅ Version UUID, SHA-256 hash fields
- ✅ is_active flag for version management

### Missing Functionalities ❌

#### 5.1 Dataset Import (NOT IMPLEMENTED)

**CSV Import:**

- ❌ `DatasetImportService::importTariffRates()` implementation
- ❌ `DatasetImportService::importFreightTables()` implementation
- ❌ `DatasetImportService::importFxRates()` implementation
- ❌ CSV parsing and validation
- ❌ Row-by-row import with error handling
- ❌ Atomic transactions (rollback on failure)

**File Upload:**

- ❌ File upload handling in controller
- ❌ File type validation (CSV only)
- ❌ File size limits
- ❌ Temporary file storage
- ❌ File cleanup after import

**Version Creation:**

- ❌ UUID generation for version
- ❌ SHA-256 hash calculation
- ❌ DatasetVersion entity creation
- ❌ Record count tracking
- ❌ Metadata storage (import timestamp, user, description)

#### 5.2 Signature Verification (PARTIAL IMPLEMENTATION)

**SHA-256 Verification:**

- ⚙️ `verifySignature()` helper method exists (fully implemented)
- ❌ Integration into import workflow
- ❌ Signature file upload handling (.csv + .sha256)
- ❌ Verification failure alerts

#### 5.3 Dataset Rollback (NOT IMPLEMENTED)

**Rollback Logic:**

- ❌ `DatasetImportService::rollbackDataset()` implementation
- ❌ Target version selection UI
- ❌ Pre-rollback snapshot creation
- ❌ Active version deactivation
- ❌ Target version activation
- ❌ Rollback confirmation modal

**Rollback Workflow:**

- ❌ Validate target version exists
- ❌ Create snapshot of current active version
- ❌ Switch `is_active` flags
- ❌ Audit log entry
- ❌ Success/failure notifications

#### 5.4 Version Comparison (NOT IMPLEMENTED)

**Diff Tool:**

- ❌ Side-by-side version comparison
- ❌ Row-level diff (added, removed, modified)
- ❌ Field-level diff highlighting
- ❌ Summary statistics (X rows added, Y modified, Z deleted)
- ❌ Export diff report (CSV or PDF)

#### 5.5 Dataset Snapshot (NOT IMPLEMENTED)

**Snapshot Creation:**

- ❌ `DatasetImportService::snapshotDataset()` implementation
- ❌ Manual snapshot trigger UI
- ❌ Automatic snapshot before rollback
- ❌ Snapshot description/labeling
- ❌ Snapshot retention policy (keep last 10)

#### 5.6 Import History UI (PARTIAL IMPLEMENTATION)

**History Display:**

- ⚙️ Template exists (`history.html.twig`)
- ❌ Version timeline query implementation
- ❌ Filter by dataset type (tariff_rate, freight_table, fx_rate)
- ❌ Display: version UUID, import date, imported by, record count
- ❌ Quick actions (rollback, compare, download)

#### 5.7 Dataset Types Supported

**Tariff Rates:**

- ❌ HTS code, destination country, duty rate, VAT rate
- ❌ Import from harmonized tariff schedule files

**Freight Tables:**

- ❌ Origin, destination, mode (AIR/LCL/FCL), weight range, rate
- ❌ Import from freight rate spreadsheets

**FX Rates:**

- ❌ Currency pair (e.g., MAD/USD), exchange rate, as-of date
- ❌ Import from central bank feeds or financial APIs

**HTS Map Rules:**

- ❌ Product description → HTS code mapping
- ❌ Keyword → HTS code lookup table

**FTA Rules:**

- ❌ Country pair (origin, destination), HTS code, FTA rate, MFN rate
- ❌ Certificate of Origin requirements

**Route Preferences:**

- ❌ Product category, optimal shipping mode, transit time
- ❌ Business rules for route selection

#### 5.8 Automated Updates (NOT IMPLEMENTED)

**Scheduled Imports:**

- ❌ Cron job setup for daily/weekly imports
- ❌ DMZ → LAN file transfer automation (SFTP, S3, etc.)
- ❌ Automatic signature verification
- ❌ Import success/failure email notifications
- ❌ Slack/Teams integration for alerts

**External Data Sources:**

- ❌ US ITC tariff schedule API integration
- ❌ EU TARIC API integration
- ❌ ECB (European Central Bank) FX rate API
- ❌ Freight forwarder rate file parsing

**Impact**: Medium-High priority. Critical for accurate landed-cost calculations across the system.

---

## 6. Related Page Functionalities

### 6.1 Navigation & UI Enhancements (PARTIAL)

**Sidebar Navigation:**

- ⚙️ Menu items exist for all features
- ❌ Active state highlighting for all routes
- ❌ Badge notifications (e.g., pending leads count)
- ❌ Collapsible sidebar for mobile

**Notification System:**

- ❌ Bell icon with unread count
- ❌ Notification dropdown modal
- ❌ Real-time notifications (WebSocket or polling)
- ❌ Toast notifications for actions
- ❌ Notification preferences per user

**Mobile Enhancements:**

- ❌ Floating Action Button (FAB) for quick actions
- ❌ Mobile-optimized table views
- ❌ Touch-friendly controls
- ❌ Progressive Web App (PWA) support

### 6.2 Dashboard Integrations (PARTIAL)

**Main Dashboard:**

- ⚙️ Basic dashboard exists
- ❌ ABM widget showing top engaged accounts
- ❌ Quote Co-Pilot widget showing recent quotes
- ❌ Supplier Portal widget showing onboarding status
- ❌ Dataset health widget showing last update times

**KPI Metrics:**

- ❌ Total leads approved this week
- ❌ Quote conversion rate (quotes → orders)
- ❌ Supplier portal submission success rate
- ❌ Average landed cost accuracy (estimate vs actual)

### 6.3 Reporting & Analytics (NOT IMPLEMENTED)

**Reports:**

- ❌ Weekly ABM activity report
- ❌ Monthly quote volume report
- ❌ Supplier portal onboarding funnel
- ❌ Dataset version history report

**Export Formats:**

- ❌ PDF reports
- ❌ Excel exports
- ❌ CSV exports
- ❌ Email scheduled reports

### 6.4 User Management & Permissions (BASIC)

**User Roles:**

- ⚙️ Basic authentication exists
- ❌ Role-based access control (RBAC)
- ❌ Permissions per module (ABM, Quotes, Datasets, etc.)
- ❌ User activity audit log
- ❌ Session management

### 6.5 Settings & Configuration (NOT IMPLEMENTED)

**System Settings:**

- ❌ API keys management (Mouser, DigiKey, etc.)
- ❌ Email templates customization
- ❌ Default margins and pricing rules
- ❌ FTA rules configuration
- ❌ Dataset update schedules

**Company Profile:**

- ❌ Company details (legal name, tax ID, address)
- ❌ Certifications upload (ISO, AS9100, IATF)
- ❌ Logo and branding customization
- ❌ Default contact information

---

## 7. Priority Implementation Roadmap

### Phase 1: Critical Business Logic (Weeks 1-4)

**High Priority - Revenue Generating:**

1. **Quote Co-Pilot BOM Processing** (Week 1)

   - CSV/Excel parsing
   - MPN normalization
   - Basic imputation pricing

2. **Quote Co-Pilot PDF Generation** (Week 1)

   - mPDF integration
   - Quote template rendering
   - Email attachment

3. **Dataset Import - Tariff Rates** (Week 2)

   - CSV import logic
   - Version creation
   - Tariff rate entity population

4. **Dataset Import - Freight Tables** (Week 2)

   - Freight table import
   - Route-based queries

5. **Quote Estimator - Duty Calculation** (Week 3)

   - Basic duty rate lookup
   - VAT calculation
   - Landed cost formula

6. **Quote Estimator - Freight Pricing** (Week 3)

   - Weight-based pricing
   - Mode selection (AIR/SEA)

7. **Supplier Portal - Form Field Mapping** (Week 4)

   - Auto-detect form fields
   - Save `formFieldsJson`
   - Manual mapping UI

8. **Supplier Portal - Pack Generation** (Week 4)
   - Extract company data
   - Generate PDF pack
   - Store credentials (encrypted)

### Phase 2: ABM & Automation (Weeks 5-6)

**Medium Priority - Efficiency Gains:** 9. **ABM - Visitor De-Anonymization** (Week 5)

- IP-to-Company resolution
- IpMap database import
- WebEvent tracking JavaScript

10. **ABM - Dashboard Display** (Week 5)

    - Recent activity feed
    - Top engaged accounts
    - Metrics calculation

11. **ABM - Playbook Engine** (Week 6)

    - Trigger evaluation logic
    - Action execution
    - Cooldown mechanism

12. **ABM - Engagement Heat Map** (Week 6)
    - Heat map data service
    - SVG/CSS visualization
    - Interactive tooltips

### Phase 3: Advanced Features (Weeks 7-10)

**Lower Priority - Nice-to-Have:** 13. **Quote Co-Pilot - API Waterfall** (Weeks 7-8) - Mouser API integration - DigiKey API integration - Caching layer - Coverage calculation

14. **Quote Co-Pilot - DFM Linting** (Week 8)

    - Obsolete part detection
    - Single-source risk
    - Lead time warnings

15. **Quote Estimator - FTA Eligibility** (Week 9)

    - FTA rules database
    - Certificate of Origin logic
    - Savings calculation

16. **Dataset Management - Rollback** (Week 9)

    - Rollback logic
    - Snapshot creation
    - Version comparison

17. **Supplier Portal - Auto-Submit** (Week 10)

    - Symfony Panther integration
    - Form submission automation
    - CAPTCHA handling

18. **Notification System** (Week 10)
    - Bell icon + dropdown
    - Toast notifications
    - Email/Slack integration

### Phase 4: Polish & Optimization (Weeks 11-12)

**Quality & UX:** 19. **Mobile Enhancements** - FAB implementation - Touch-friendly controls - Responsive tables

20. **Reporting & Analytics**

    - PDF reports
    - Scheduled emails
    - Excel exports

21. **Performance Optimization**

    - Query optimization
    - Redis caching
    - Asset optimization

22. **Testing & Documentation**
    - Unit tests for services
    - Integration tests for APIs
    - User acceptance testing

---

## 8. Dependencies & Prerequisites

### Technical Prerequisites

**APIs & Services:**

- Mouser API key (Quote Co-Pilot)
- DigiKey API key (Quote Co-Pilot)
- Nexar API key (Quote Co-Pilot, optional)
- Alibaba API key (Quote Co-Pilot, optional)
- MaxMind GeoIP2 database (ABM de-anonymization)
- Clearbit Reveal API key (ABM, optional)

**Libraries & Packages:**

- `symfony/panther` (Supplier Portal automation)
- `mpdf/mpdf` (PDF generation)
- `phpoffice/phpspreadsheet` (Excel BOM parsing)
- `guzzlehttp/guzzle` (HTTP client for APIs)
- `symfony/cache` (API response caching)
- `predis/predis` (Redis integration)

**Data Files:**

- US HTS tariff schedule (CSV)
- EU TARIC database (CSV)
- IP-to-Company mapping (MaxMind GeoIP2 database)
- Freight rate tables (Morocco → Global)
- FTA rules database (Morocco FTAs)

**Infrastructure:**

- Redis server (caching, session storage)
- Mail server (SMTP or SendGrid/Mailgun)
- File storage (local or S3 for PDFs, uploads)
- Cron job scheduler (dataset updates, notifications)

### Business Prerequisites

**Process Definitions:**

- Quote approval workflow (who approves >$10K quotes?)
- Supplier portal onboarding SOP
- Dataset update schedule (weekly? monthly?)
- ABM playbook rules (when to trigger actions?)
- Email templates (quote emails, notifications)

**Data Preparation:**

- Import initial tariff rates dataset
- Import initial freight tables dataset
- Import initial FX rates dataset
- Populate company certifications (ISO, AS9100, etc.)
- Configure default pricing margins

---

## 9. Estimated Effort

### Development Time (by Module)

| Module                 | Total Hours | Complexity | Risk Level              |
| ---------------------- | ----------- | ---------- | ----------------------- |
| **Quote Co-Pilot**     | 120h        | High       | High (API dependencies) |
| - BOM Processing       | 20h         | Medium     | Low                     |
| - API Waterfall        | 40h         | High       | High                    |
| - DFM Linting          | 20h         | Medium     | Medium                  |
| - PDF Generation       | 15h         | Medium     | Low                     |
| - Email Integration    | 10h         | Low        | Low                     |
| - Costing Engine       | 15h         | Medium     | Medium                  |
| **Quote Estimator**    | 80h         | Medium     | Medium                  |
| - HTS Classification   | 20h         | Medium     | Medium                  |
| - Route Selection      | 15h         | Medium     | Low                     |
| - FTA Eligibility      | 15h         | Medium     | Medium                  |
| - Duty Calculation     | 15h         | Medium     | Low                     |
| - Freight Pricing      | 10h         | Low        | Low                     |
| - PDF Generation       | 5h          | Low        | Low                     |
| **ABM Dashboard**      | 70h         | Medium     | Medium                  |
| - IP Resolution        | 15h         | Medium     | Medium                  |
| - Dashboard Display    | 15h         | Low        | Low                     |
| - Playbook Engine      | 20h         | High       | High                    |
| - Heat Map             | 10h         | Medium     | Low                     |
| - Account Management   | 10h         | Low        | Low                     |
| **Supplier Portal**    | 90h         | High       | High (automation risk)  |
| - Portal Discovery     | 20h         | Medium     | Medium                  |
| - Form Mapping         | 20h         | High       | High                    |
| - Pack Generation      | 15h         | Medium     | Low                     |
| - Auto-Submit          | 25h         | High       | High                    |
| - Credentials          | 10h         | Medium     | Medium                  |
| **Dataset Management** | 60h         | Medium     | Low                     |
| - Import Logic         | 20h         | Medium     | Low                     |
| - Versioning           | 15h         | Medium     | Low                     |
| - Rollback             | 15h         | Medium     | Low                     |
| - UI Enhancement       | 10h         | Low        | Low                     |
| **Related Pages**      | 40h         | Low        | Low                     |
| - Navigation           | 10h         | Low        | Low                     |
| - Notifications        | 15h         | Medium     | Low                     |
| - Reporting            | 15h         | Medium     | Low                     |

**Total Estimated Effort**: **460 hours** (~11.5 weeks @ 40h/week or ~3 months @ 1 developer)

---

## 10. Risk Assessment

### High Risks 🔴

1. **API Availability & Reliability**

   - Mouser/DigiKey API downtime
   - Rate limit exhaustion
   - API cost overruns
   - **Mitigation**: Implement caching, fallback logic, budget monitoring

2. **Supplier Portal TOS Violations**

   - Automated submission may violate Terms of Service
   - Legal liability for scraping
   - **Mitigation**: Manual review of TOS, semi-automated mode, legal counsel

3. **Data Quality Issues**

   - Incorrect tariff rates → wrong duty calculations
   - Outdated freight tables → inaccurate quotes
   - **Mitigation**: Regular dataset updates, validation checks, human oversight

4. **IP-to-Company Accuracy**
   - Residential ISP false positives
   - VPN/proxy obfuscation
   - **Mitigation**: ISP filtering, confidence scoring, manual review

### Medium Risks 🟡

5. **Performance at Scale**

   - Slow API waterfall for large BOMs (100+ lines)
   - Database queries on large datasets
   - **Mitigation**: Parallel API calls, query optimization, Redis caching

6. **User Adoption**

   - Complex UIs may confuse users
   - Training required for new features
   - **Mitigation**: User testing, documentation, training sessions

7. **Integration Complexity**
   - Multiple APIs with different data formats
   - Mapping inconsistencies (MPN variations)
   - **Mitigation**: Normalization layer, test suites, error logging

### Low Risks 🟢

8. **PDF Generation Performance**

   - mPDF may be slow for large quotes
   - **Mitigation**: Background job processing, caching rendered PDFs

9. **Email Deliverability**
   - Quote emails may land in spam
   - **Mitigation**: SPF/DKIM setup, test with mail-tester.com

---

## 11. Success Criteria

### Functional Acceptance

**Quote Co-Pilot:**

- ✅ Can upload 100-line BOM CSV
- ✅ Achieves ≥60% coverage from APIs
- ✅ Generates quote PDF in <30 seconds
- ✅ Emails quote to customer contact

**Quote Estimator:**

- ✅ Calculates landed cost for 50+ countries
- ✅ Shows duty and VAT breakdown
- ✅ Displays FTA savings (if applicable)
- ✅ Generates estimate PDF in <10 seconds

**ABM Dashboard:**

- ✅ Identifies ≥70% of corporate visitors
- ✅ Shows last 24-hour activity feed
- ✅ Triggers playbook action (e.g., create lead) automatically
- ✅ Displays engagement heat map

**Supplier Portal:**

- ✅ Discovers supplier portal from domain
- ✅ Maps form fields (10+ fields)
- ✅ Generates onboarding pack PDF
- ✅ Auto-fills form (no submission yet, TOS review)

**Dataset Management:**

- ✅ Imports tariff rates CSV (10,000+ rows) in <2 minutes
- ✅ Creates versioned snapshot
- ✅ Rolls back to previous version successfully
- ✅ Shows import history with 10+ versions

### Performance Benchmarks

- BOM processing: <5 seconds per 100 lines
- API waterfall: <10 seconds for 50 parts (with caching)
- PDF generation: <30 seconds for 10-page quote
- Landed cost calculation: <2 seconds
- IP resolution: <100ms per lookup
- Dataset import: <2 minutes for 10K rows

### User Experience

- Task completion time reduced by 50% (vs manual process)
- Error rate <5% (incorrect calculations, failed submissions)
- User satisfaction score ≥4/5 in UAT survey

---

## 12. Next Steps & Recommendations

### Immediate Actions (This Week)

1. **Prioritize Modules**: Stakeholder meeting to confirm priority (Quote Co-Pilot first?)
2. **API Keys**: Obtain Mouser and DigiKey API keys, test rate limits
3. **Dataset Preparation**: Collect and format tariff rates, freight tables, FX rates
4. **Developer Assignment**: Assign 1-2 developers to start Phase 1

### Short-Term (Next 2 Weeks)

5. **Implement BOM Parsing**: Complete CSV and Excel parsing logic
6. **Basic Quote PDF**: Get Quote Co-Pilot generating simple PDFs
7. **Tariff Rate Import**: Enable dataset import for duty calculations
8. **User Testing**: Test BOM upload flow with 5 sales team members

### Mid-Term (Next 1-2 Months)

9. **API Integrations**: Integrate Mouser and DigiKey APIs
10. **ABM MVP**: Get visitor de-anonymization and dashboard working
11. **Supplier Portal Discovery**: Implement portal discovery and form mapping
12. **Performance Testing**: Load test with 1000+ BOM lines, 10 concurrent users

### Long-Term (Next 3-6 Months)

13. **Full ABM Playbooks**: Automated lead creation, email outreach
14. **Supplier Portal Auto-Submit**: After legal review, enable automation
15. **Advanced Reporting**: Build analytics dashboards and scheduled reports
16. **Mobile App**: Consider React Native or PWA for field sales

---

## 13. Conclusion

The Starz Morocco CRM system has **excellent architecture and documentation** but requires **significant backend implementation** to become fully functional. The codebase is well-structured with clear TODO placeholders, making it straightforward to implement missing features.

### Key Findings

**Strengths:**

- ✅ Comprehensive documentation (20+ MD files)
- ✅ Well-designed database schema
- ✅ Clean controller layer with routes
- ✅ Service class scaffolding
- ✅ Modern UI templates (Geist/Claude design)

**Gaps:**

- ❌ Business logic implementation (~60-70% missing)
- ❌ External API integrations (Mouser, DigiKey, MaxMind)
- ❌ Data import automation (tariff rates, freight tables)
- ❌ Advanced features (playbook engine, auto-submit)

**Recommendation**: Focus on **Quote Co-Pilot** and **Quote Estimator** first (revenue-generating), then **Dataset Management** (foundational), then **ABM Dashboard** and **Supplier Portal** (efficiency gains).

**Estimated Timeline**: 3 months with 1 full-time developer, or 6 weeks with 2 developers working in parallel.

---

**Document Version**: 1.0  
**Created**: November 22, 2025  
**Author**: AI Analysis based on Codebase Review  
**Status**: ✅ Complete Analysis
