# Comprehensive Sales & Pipeline System Audit
**CRM v2 | February 11, 2026**

---

## Executive Summary

This Symfony CRM contains a **complex, multi-layered sales pipeline** with **significant architectural issues**:

1. **Two separate but interconnected pipelines**: Lead → Company and RFQ → Quote
2. **Overlapping workflows**: Discovery Pipeline, Lead Nurturing, Autonomous Sales all working on partially duplicated logic
3. **Unmapped entity relationships**: No "Opportunity" entity; unclear conversion path Lead→RFQ
4. **Inconsistent stage definitions**: Company pipeline stages (Prospect→Award) vs Lead nurturing stages (New→Converted) with ad-hoc mappings
5. **Dead code risks**: Multiple services computing lead scores and stage advancement without clear orchestration
6. **Missing critical glue**: No clear "Deal" or "Opportunity" entity to bridge Lead discovery to RFQ creation

---

## UI/UX Sweep — Appearance System (Feb 11, 2026)

**Scope**: New appearance customization controls (theme, accent, font size, density, reduced motion) and their runtime application.

**Primary Surfaces Reviewed**
- Profile preferences UI: [templates/profile/index.html.twig](templates/profile/index.html.twig)
- Runtime theme application: [templates/base.html.twig](templates/base.html.twig)
- Design tokens and density/reduced-motion styles: [public/css/sensei-rams.css](public/css/sensei-rams.css)
- Preference data model: [src/Entity/User.php](src/Entity/User.php)

### Findings (Minute-Level)

#### 1. Preference Entry UI (Profile → Preferences)
- **Labeling and hint consistency**: All new inputs are labeled and have hints, matching existing form conventions (label + widget + hint). ✅
- **Inline checkbox alignment**: `reducedMotion` uses the inline group and applies `rams-ml-2` to its label; consistent with existing checkbox patterns. ✅
- **Field grouping clarity**: Theme, accent, font size, density, reduced motion are all in the same preferences module with consistent spacing. ✅
- **Select placeholders**: Placeholders exist in the form config (e.g., `profile.font_size_placeholder`), but previously missing translations in FR/AR were a UX gap. **Fixed** in [translations/messages.fr.json](translations/messages.fr.json) and [translations/messages.ar.json](translations/messages.ar.json). ✅
- **Hint positioning**: The `reducedMotion` hint is outside the `.rams-form__group` container. This can slightly misalign spacing relative to other hints. ⚠️ (cosmetic)

#### 2. Runtime Theme & Appearance Initialization
- **Data attribute sourcing**: Preferences are read from `data-user-*` attributes on `<body>`; consistent with server-rendered persistence. ✅
- **Theme application logic**: `system` resolves to OS preference and listens for changes via `matchMedia`. ✅
- **Dark mode classing**: JS applies both `data-theme` and `.dark` for compatibility. ✅
- **Accent mapping**: Uses named palette with HSL/HEX mapping to mimic Management-Software. ✅
- **Accent scope**: CSS uses `--rams-accent` only in select components; primary buttons still use `--rams-orange` by design. This means accent changes affect highlights and borders but not primary actions. ⚠️ (intentional per earlier request, but worth noting to avoid expectation mismatch)
- **Initial render flash**: Theme is applied in a script at the end of `<body>`, which may create a brief flash of default light theme on slow devices. ⚠️ (performance/UX)

#### 3. Typography / Font Size Scaling
- **Font size application**: JS sets `documentElement.style.fontSize`. ✅
- **Effectiveness**: Many text sizes in the design system are defined in `px` tokens (`--text-sm: 14px`, etc.). If components use `var(--text-sm)` they will **not scale** with root font size changes. Font-size customization may only partially work where `rem` is used. ⚠️ (consistency)
- **Potential improvement**: Convert token scale to `rem` or compute `--text-*` from a `--base-font-size` variable. ⚠️

#### 4. Density System
- **Class application**: JS adds `density-comfortable` or `density-compact` to `<html>`. ✅
- **Coverage**: Only a subset of components (buttons, cards, sidebar links, tables) are affected. Many form fields and layout containers remain unchanged, leading to mixed-density UI. ⚠️ (incomplete coverage)
- **Comfortable defaults**: There are spacing variables for comfortable density, but most components don’t consume them yet. ⚠️

#### 5. Reduced Motion
- **User preference override**: `reduce-motion` class is applied when user toggles. ✅
- **System preference respect**: Global `prefers-reduced-motion` is also enforced for all users. ✅
- **Conflict behavior**: If a user disables reduced motion but OS preference is “reduce”, animations still reduce (by design). ✅

#### 6. Accessibility & Contrast
- **Dark theme contrast**: Updated tokens provide stronger contrast for text and background. ✅
- **Focus visibility**: Accent and primary colors remain visible; still dependent on whether controls use `--rams-accent` or `--rams-orange`. ✅
- **RTL compatibility**: Appearance settings are independent of direction; no RTL regressions observed in preference layout. ✅

### UX Risks / Edge Cases
1. **Partial font scaling** due to fixed `px` tokens. User may perceive “Font Size” as not working in some components. ⚠️
2. **Mixed density** where only a subset of UI reacts to `density-compact`, producing inconsistent spacing on larger screens. ⚠️
3. **Theme flash** on first paint if JS loads late. ⚠️
4. **Accent expectations**: Users may expect accent to recolor primary buttons; current behavior intentionally keeps them orange. ⚠️

### Recommendations (Non-blocking)
1. **Token refactor for font scaling**: Define `--base-font-size` and express `--text-*` in `rem` to ensure full-scale resizing. ✅ Recommended.
2. **Density coverage audit**: Extend density rules to inputs, lists, toolbars, and module paddings to avoid mixed-density layouts. ✅ Recommended.
3. **Prevent theme flash**: Inline a minimal pre-render script in `<head>` to set `data-theme` before paint. ✅ Recommended.
4. **Clarify accent behavior**: Add microcopy under accent picker to indicate it affects highlights/borders but not primary actions (unless desired otherwise). ✅ Recommended.

**Overall Quality**: **High** (behavioral parity achieved), with minor UX polish needed around font scaling, density coverage, and initial theme paint.

---

## Part 1: RFQ (Request For Quote) System

### Entity: `RFQ`
**File**: [src/Entity/RFQ.php](src/Entity/RFQ.php)

| Property | Type | Purpose | Notes |
|----------|------|---------|-------|
| `id` | int | Primary key | |
| `company` | Company (M:1) | Company issuing RFQ | References target customer company |
| `rfqNumber` | string(100) | Unique RFQ identifier | Manual or auto-generated |
| `rfqDate` | date | When RFQ was issued | |
| `type` | string(50) | RFQ category | "Standard RFQ", "NPI", "Framework Agreement" |
| `status` | string(50) | Current state | **Pending, Submitted, Won, Lost, In Review** |
| `ndaSent` | bool | NDA workflow step 1 | |
| `ndaDate` | date | When NDA sent | |
| `ndaExecuted` | bool | NDA workflow step 2 | |
| `estimatedValue` | decimal(15,2) | Deal size | |
| `currency` | string(10) | Currency for value | |
| `volumeAnnual` | int | Annual volume | |
| `sopDate` | date | Start of Production date | Future revenue indicator |
| `technicalScope` | text | Technical requirements | |
| `notes` | text | Free-form notes | |

**Win/Loss Intelligence Fields** (Rich competitive analysis):
- `lossReason` - Standardized reason (price, technical, capacity, etc.)
- `lossReasonDetail` - Free-text explanation
- `competitorWon` - Name of winning competitor
- `winningBidAmount` - Their price if known
- `lessonsLearned` - Post-mortem insights
- `winFactors` - Why we won (if success)
- `decisionDate` - When final decision made
- `awardDate` - When contract awarded (if won)

**Collections**:
- `lineItems` (1:M with RfqLineItem) - Individual parts/services requested
- `versions` (1:M with RfqVersion) - Change history/revisions

**Status Workflow**:
```
Pending → In Review → Submitted → Won
                   ↘ Lost
```

**RFQ ↔ Quote Relationship**:
- Quote has optional FK to RFQ
- RFQ has no FK to Quote (one-way relationship)
- **ISSUE**: No enforcement that every RFQ gets exactly one Quote

---

### Controller: `RFQController`

**File**: [src/Controller/RFQController.php](src/Controller/RFQController.php#L1-L100)

| Route | Method | Purpose | Key Logic |
|-------|--------|---------|-----------|
| `/rfqs/` | GET | Index/List all RFQs | Filters by type, status, company, NDA state |
| `/rfqs/pipeline` | GET | Kanban board view | Groups RFQs into 5 status columns (Pending, In Review, Submitted, Won, Lost) |
| `/rfqs/new` | GET/POST | Create RFQ | Pre-fills company from query param, logs guidance after creation |
| `/rfqs/{id}` | GET | Show RFQ details | |
| `/rfqs/{id}/edit` | GET/POST | Edit RFQ | |
| `/rfqs/{id}/delete` | POST | Delete RFQ | CSRF protected |
| `/rfqs/{id}/update-status` | POST | Change status | Triggers `GuidanceNotificationService` |
| `/rfqs/{id}/nda-sent` | POST | Mark NDA sent | Sets `ndaSent=true`, records date |
| `/rfqs/{id}/nda-executed` | POST | Mark NDA executed | Sets `ndaExecuted=true`, auto-sets sent if needed |
| `/rfqs/analytics/win-loss` | GET | Win/Loss dashboard | Analyzes completed RFQs (Won/Lost) by period, loss reasons, competitors, lessons learned |

**Key Services Used**:
- `GuidanceNotificationService` - Provides contextual help after RFQ actions
- `TranslatorInterface` - i18n support

**Win/Loss Analytics** (`winLossAnalytics` method):
- Supports period filtering: 30/90/180/365 days, YTD, custom range, all-time
- Period-over-period comparison
- Outputs:
  - Win/Loss count and values
  - Win rate %
  - Loss reasons aggregated with frequency
  - Top competitors by value lost
  - Lessons learned repository
  - Win factors patterns

---

### Templates: `templates/rfq/`

| Template | Purpose |
|----------|---------|
| `index.html.twig` | List view of all RFQs with filters |
| `pipeline.html.twig` | Kanban board with 5 status columns |
| `new.html.twig` | Create RFQ form |
| `edit.html.twig` | Edit RFQ form |
| `show.html.twig` | RFQ detail view with NDA workflow buttons |
| `win_loss_analytics.html.twig` | Win/Loss competitive intelligence dashboard |

---

### Quote Connection

**File**: [src/Entity/Quote.php](src/Entity/Quote.php#L1-L100)

Quote entity:
- Has optional FK `rfq` → points back to RFQ
- But is **independent entity** with company/contact owned
- Can exist without RFQ
- Can have multiple quotes per RFQ (not enforced)

**CRITICAL ISSUE**: 
- RFQ → Quote relationship is optional and one-way
- Nothing prevents RFQ with status="Won" from having no Quote
- Nothing prevents multiple Quotes from same RFQ
- No business logic enforces Quote creation when RFQ moves to "Submitted" status

---

### Workflow Summary: RFQ Creation to Quote

```
1. RFQ Created
   ↓
2. RFQ Status: Pending → In Review
   ↓
3. NDA Workflow (if needed)
   - Mark NDA sent (ndaSent=true)
   - Mark NDA executed (ndaExecuted=true)
   ↓
4. RFQ Submitted (status="Submitted")
   ↓ [NO AUTOMATION]
5. Quote Created (optional, manual creation)
   - Quote references RFQ via FK
   - Quote has own company/contact
   ↓
6. RFQ status → Won or Lost
   - Populate win/loss intelligence (reason, competitor, factors)
   - Set decisionDate and awardDate
```

**GAPS**:
- No automatic Quote creation
- No webhook/event when RFQ moves to "Submitted"
- No validation that Quote exists before marking RFQ as "Won"
- Win/Loss workflow is optional (fields can be left blank)

---

## Part 2: Lead System

### Entity: `Lead`

**File**: [src/Entity/Lead.php](src/Entity/Lead.php#L1-L500)

**Purpose**: Represents discovered prospect companies from web crawling/LinkedIn searches (NOT a person/contact).

| Property | Type | Purpose |
|----------|------|---------|
| `companyName` | string(255) | Name of prospect company |
| `legalName` | string(255) | Registered company name |
| `websiteRoot` | string(255) | Domain (deduplication key) |
| `leadUrl` | string(500) | LinkedIn/source URL |
| `siteLocation` | string(255) | Physical location (city/address) |
| `regionTag` | string(50) | Region classifier: morocco, us_east, us_texas, uk, eu_core, eu_nordics, eu_cee |
| `sectorTags` | json | Array of industry tags: ["automotive", "aerospace", "defense", ...] |
| `fitSignals` | json | Capabilities: {"pcba": true, "smt": true, "ems": true} |
| `qualityStack` | json | Certifications: ["IATF 16949", "AS9100", "ISO 13485"] |
| `contactEmailsPublic` | json | Scraped emails: ["procurement@...", "supplier@..."] |
| `contactFormUrl` | string(500) | Contact form endpoint |
| `hasContactForm` | bool | Contact form detected |
| `rfqRfpPageUrl` | string(500) | Supplier portal/RFQ page URL |
| `moroccoSignal` | bool | Indicates Morocco market interest |
| `defenseFlag` | bool | Defense sector flag |
| `leadScore` | int(0-100) | Qualification score |
| `reviewStatus` | string(50) | **pending, approved, denied** |
| `denyReason` | text | Why rejected |
| `alreadyInCrm` | bool | Linked to Company entity |
| `company` | Company (M:1) | Converted target (after approve + convert) |
| `source` | string(255) | Discovery source |
| `lastScrapedAt` | datetime | Last web scrape |
| `pagesScraped` | int | Number of pages crawled |

**Stage Indicators** (implicit states):
- `reviewStatus = 'pending'` → New lead, awaiting approval
- `reviewStatus = 'approved'` + `company = null` → Qualified but not converted
- `reviewStatus = 'approved'` + `company != null` → Converted to Company
- `reviewStatus = 'denied'` → Rejected

---

### Controller: `LeadController`

**File**: [src/Controller/LeadController.php](src/Controller/LeadController.php#L1-L200)

| Route | Method | Purpose |
|-------|--------|---------|
| `/leads/` | GET | Dashboard showing lead counts by status |
| `/leads/review` | GET | Review queue (QA interface) with filtering |
| `/leads/approve/{id}` | POST | Approve a lead (JSON AJAX) |
| `/leads/deny/{id}` | POST | Deny with reason (JSON AJAX) |
| `/leads/convert/{id}` | POST | Convert approved lead → Company entity |

**Approval Status Workflow**:
```
pending ──[approve]──→ approved
   ↓
  [deny]
   ↓
denied
```

**Conversion Logic** (from `convert` method):
1. Requires `reviewStatus = 'approved'`
2. Creates new **Company** entity
3. Maps Lead fields:
   - `companyName` → Company.name
   - `legalName` → Company.legalName
   - `websiteRoot` → Company.website
   - `siteLocation` → Company.physicalSite
   - `leadUrl` (if LinkedIn) → Company.linkedinCompanyUrl
4. Sets Company.pipelineStage = 'Prospect' (default)
5. Stores source notes with Lead ID, score, region
6. Adds sector tags, quality certifications, contact info

**CRITICAL ISSUE**: 
- Conversion creates Company but doesn't create RFQ
- No trigger to create first RFQ when Lead converts
- Lead and Company are different entities with partial data duplication

---

### Services

#### `LeadSalesAnalystService`

**File**: [src/Service/LeadSalesAnalystService.php](src/Service/LeadSalesAnalystService.php#L1-L300)

**Purpose**: AI-driven sales intelligence for leads

**Method**: `analyzeLead(Lead $lead): array`

**Analysis Dimensions**:

1. **Fit Scores** (0-100 weighted average):
   - Capability fit (40%): Does Lead's fitSignals match our services?
   - Certification fit (35%): Do their quality certifications match ours?
   - Sector fit (25%): Is their industry in target list?

2. **Pain Points**: Identifies likely challenges
   - QA struggles (hiring signals, recalls)
   - Capacity constraints (production hiring)
   - Cost pressure (margin signals)
   - Supply chain risk (diversification)
   - Time-to-market (NPI mentions)
   - Compliance complexity (audit mentions)

3. **Conversation Starters**: Generated talking points based on fit gaps

4. **Competitive Positioning**: How to position against competitors in their sector

5. **Decision Maker Targeting**: Who to contact based on signals

6. **Risk Assessment**: Deal blockers

7. **Next Steps**: Recommended approach (aggressive, measured, nurture, etc.)

**Outputs**:
- `overall_fit_score` (0-100)
- `fit_grade` (A/B/C/D/F)
- `pain_points[]` with pitch suggestions
- `priority_score`
- `recommended_approach`

**Key Insight**: Generates sales-ready intelligence from lead data, NOT a lead score used for conversion gating.

---

#### `LeadNurturingService`

**File**: [src/Service/LeadNurturingService.php](src/Service/LeadNurturingService.php#L1-I00)

**Purpose**: Automates lead lifecycle progression

**Lead Nurturing Stages** (different from Company pipeline stages):
```
new → contacted → engaged → qualified → opportunity → converted
                                              ↘ dormant
                                              ↘ lost
```

**Stage Transitions Based On**:
1. **Engagement Score** (0-100 calculated from):
   - Activity history (Email=5pts, Call=20pts, Meeting=30pts, Site Visit=50pts)
   - Lead signals (fitSignals, qualityStack) + bonuses
   - Contact availability (emails, forms, RFQ pages) + bonuses
   - Sector alignment + bonuses

2. **Inactivity Thresholds**:
   - new: 3 days → contacted
   - contacted: 7 days → engaged
   - engaged: 14 days → qualified
   - qualified: 21 days → opportunity
   - opportunity: 30 days → dormant

3. **Score Thresholds** (minimums):
   - contacted: 10
   - engaged: 25
   - qualified: 50
   - opportunity: 75

**Stage → Company Pipeline Mapping** (stage advancement updates Company):
```
Lead Stage         →  Company Stage
contacted          →  MQL
engaged            →  SQL
qualified          →  SQO
opportunity        →  Proposal
```

**Method**: `processAllLeads()` and `processLead(Lead $lead)`

**Side Effects**:
- Updates Lead.leadScore
- Updates Company.pipelineStage
- Schedules follow-ups
- Records actions taken

**CRITICAL ISSUE**:
- Two stage systems (Lead nurturing stages vs Company pipeline stages) with manual mapping
- Lead service modifies Company entity
- No orchestrator controlling both
- Can create inconsistencies if Company stage manually changed

---

### Conversion Lifecycle

```
1. Lead discovered (via DiscoveryPipeline)
   - reviewStatus: pending
   - leadScore: calculated
   
2. Lead approved (via LeadController.approve)
   - reviewStatus: approved
   - Potentially scored by LeadSalesAnalystService
   
3. Lead converted (via LeadController.convert)
   - New Company created
   - Lead.company = Company FK
   - Company.pipelineStage = 'Prospect'
   - Company starts in nurturing pipeline
   
4. LeadNurturingService processes Lead
   - Calculates engagementScore
   - Advances Lead nurturing stage if engagement increases or inactivity occurs
   - Maps to Company.pipelineStage
   
5. Lead reaches "opportunity" stage (75+ score)
   - Company.pipelineStage = 'Proposal'
   - But NO RFQ created
   
6. Manual RFQ creation (via RFQController.new)
   - User must manually create RFQ
   - RFQ.company = Company
   - RFQ.status = 'Pending'
```

**GAPS**:
- No automated RFQ creation trigger
- Lead.company and RFQ.company can diverge
- Lead scoring vs Company qualification are separate processes

---

## Part 3: Discovery Pipeline

### Controller: `DiscoveryPipelineController`

**File**: [src/Controller/DiscoveryPipelineController.php](src/Controller/DiscoveryPipelineController.php#L1-L100)

**Purpose**: Automated lead discovery and enrichment from web searches

**Routes**:
- `GET /discovery-pipeline` - Dashboard with sector/location filters
- `POST /discovery-pipeline/run` - Execute search pipeline
- (Additional routes in file)

**Workflow** (`runPipeline` method):
```
1. User selects Sector + Location
2. GoogleDorkService searches companies
3. Results deduplicated (by domain)
4. Unique domains imported as Lead entities
5. Auto-import flag enables straight-to-approval
6. Queue leads for deep scraping (async via Messenger)
7. Return import summary
```

**Configuration Options**:
- `enable_deep_scrape` - Queue for headless scraping (Panther)
- `enable_llm` - Use LLM for signal extraction
- `auto_import` - Skip manual review, auto-import all found

**Services Used**:
- `GoogleSearchService` - Google Custom Search API
- `GoogleDorkService` - Constructs specialized search queries
- `CompanyDiscoveryService` - Deduplication and Lead creation
- `CountryService` - Region resolution
- `MessageBusInterface` - Queue deep scrape jobs

---

### Templates: `templates/discovery_pipeline/`

| Template | Purpose |
|----------|---------|
| `index.html.twig` | Discovery dashboard with sector/location selectors and stats |

---

### Services

#### `PipelineForecastingService`

**File**: [src/Service/PipelineForecastingService.php](src/Service/PipelineForecastingService.php#L1-L200)

**Purpose**: Revenue forecasting based on pipeline stages

**Calculation**:
```
For each Company:
  1. Get deal value (estimateDealValue)
  2. Get pipeline stage probability (default: Prospect=5%, MQL=10%, SQL=25%, SQO=50%, Proposal=75%, Award=100%)
  3. Apply time decay (30 days=100%, 60 days=90%, 120 days=50%, 180+ days=25%)
  4. Apply tier multiplier (Tier A=1.2x, Tier B=1.0x, Tier C=0.8x)
  5. Weighted value = deal value × adjusted probability
  
Total forecast = sum of all weighted values
```

**Outputs**:
- `total_unweighted` - Sum of all deal sizes
- `total_weighted` - Revenue forecast
- `by_stage[]` - Breakdown per pipeline stage
- `by_quarter[]` - Expected close timing
- `by_sector[]` - By industry
- `deals[]` - Individual deal detail

**ISSUE**: 
- Forecasts only based on Company pipeline stage
- Doesn't directly use RFQ data (which has more precise status/values)
- RFQs are analyzed separately in KPI tracking

---

### Relationships

**Discovery Pipeline → Lead → Company**:
```
GoogleDork discovers domain
          ↓
Lead created (pending review)
          ↓ [approval]
Lead approved (but still separate)
          ↓ [conversion]
Company created
          ↓ [LeadNurturingService]
Company stage advances → triggers RFQ?
```

**CRITICAL FINDINGS**:
1. **Discovery Pipeline** creates Leads (prospects)
2. **Lead System** converts Leads to Companies
3. **RFQ System** is independent - must be manually created
4. No automatic connection between Lead discovery and RFQ creation
5. No "Opportunity" entity bridges the gap

---

## Part 4: Autonomous Sales System

### Controller: `AutonomousSalesDashboardController`

**File**: [src/Controller/AutonomousSalesDashboardController.php](src/Controller/AutonomousSalesDashboardController.php#L1-L200)

**Purpose**: Orchestrates autonomous email outreach system (AI-driven sales acceleration)

**Routes**:
- `GET /autonomous-sales` - System dashboard
- `POST /autonomous-sales/toggle` - Enable/disable system
- `POST /autonomous-sales/initialize` - Load templates, arms, competitor data
- `POST /autonomous-sales/seed` - Create default subject line variations
- `POST /autonomous-sales/score-leads` - Score all leads by purchase probability
- `POST /autonomous-sales/hourly-run` - Execute hourly optimization cycle

**Operations**:

| Operation | Purpose |
|-----------|---------|
| **Initialize** | Load email templates, Thompson Sampling arms (subject lines, send times), competitor signals |
| **Seed** | Create default subject line variants for A/B testing |
| **Score Leads** | Use ML to rank leads by likelihood to buy (integrates with Contact entity) |
| **Hourly Run** | Execute optimization cycle: personalize + gate + send + learn |

---

### Service: `AutonomousSalesOrchestratorService`

**File**: [src/Service/AutonomousSalesOrchestratorService.php](src/Service/AutonomousSalesOrchestratorService.php#L1-L300)

**Purpose**: Core orchestrator coordinating all autonomous sales modules

**Method**: `composeMessage(Contact $contact, array $context, ?$campaignId): array`

**Message Composition Pipeline**:

```
1. BUILD CONTEXT
   - Base: first_name, last_name, company_name, job_title
   - Personalization: value_prop, pain_hook, social_proof, CTA, tone
   - Persuasion (Cialdini): reciprocity, scarcity, authority, etc.
   - Geographic: timezone, trade culture
   
2. SELECT TEMPLATE
   - Choose from SpintaxTemplate pool
   - Based on contact, patterns, or performance
   
3. THOMPSON SAMPLING (subject line A/B test)
   - Sample from best-performing subject line arm
   - Per-industry bandit pools (automotive, aerospace, etc.)
   - 10% control group always gets baseline
   
4. VALUE PROP A/B TEST
   - Get variant by industry + content focus
   - Replace {{value_prop}} placeholder
   
5. COMPETITOR DISPLACEMENT HOOK
   - If competitor detected, insert displacement messaging
   
6. SPINTAX PROCESSING
   - Expand random variants: {{greeting|Hi|Hello}} → "Hi"
   - Personalize all {{variables}}
   - Generate unique variation
   
7. TONE ADJUSTMENT
   - Apply cold/warm/hot engagement transformations
   - Fix output quality issues
   
8. COPY LINT GATE (hard gate)
   - Reject if brand/deliverability rules violated
   
9. RETURN
   - subject, body, metadata (arm IDs, variation hash, etc.)
```

**Thompson Sampling Integration**:
- Per-campaign support (`campaignId` for multi-campaign A/B testing)
- Per-industry pools (`icpCluster`)
- 10% control group routing
- Captures decision trace for attribution

**Personalization Integration** (`EmailPersonalizationService`):
- Tone selection (formal/friendly/casual)
- Content length enforcement (based on engagement)
- Quality validation
- Curiosity subject line generation
- Persuasion context building (all 7 Cialdini principles)

**Competitor Displacement** (`CompetitorDetectionService`):
- Identifies if contact's company uses competitors
- Generates pain-point hooks
- Suggests differentiation angles

**ML Personalization** (`EmailPersonalizationService`):
- Content analysis (business vs technical)
- Role-based templates
- Engagement level assessment (cold/warm/hot)
- Industry-specific pain points

---

### Services Used

| Service | Purpose |
|---------|---------|
| `ThompsonSamplerService` | Bandit sampling for subject lines + send times |
| `SpintaxEngineService` | Message template expansion ({{}} substitution) |
| `EmailPersonalizationService` | ML-driven personalization (tone, content, CTA) |
| `EmailClassifierService` | Closed-loop learning (classifies responses) |
| `CompetitorDetectionService` | Identifies competitor signals |
| `LeadSalesAnalystService` | Sales intelligence (fit score, pain points) |
| `CadenceGovernorService` | Prevents message spam/frequency violations |
| `CopyLintService` | Brand compliance + deliverability validation |

---

### Hourly Optimization Cycle

**Service**: `HourlyOptimizationService`

10-stage optimization pipeline runs every hour:

| Stage | Purpose |
|-------|---------|
| 0 | Ensure baseline arm exists |
| 1 | Snapshot current system state |
| 2 | Select candidates for email |
| 3 | Personalize + apply QA gates |
| 4 | Execute send batch |
| 5 | Hourly evaluation (historical analysis) |
| 6 | Safety enforcement (spam checks) |
| 7 | Pruning + promotion (retire bad arms, elevate winners) |
| 8 | Adaptive refresh (update probabilities) |
| 9 | End-of-hour assertions (health checks) |
| 10 | Guaranteed outcome (determine optimization direction) |

**Outcomes**: 
- "improve" - System is optimizing well
- "stable" - No significant changes
- "degrade" - Performance declining
- "insufficient_data" - Not enough samples yet

---

### Relationships

**Autonomous Sales operates on**:
- `Contact` entity (email addresses, company affiliation)
- `Lead` entity (scoring for outreach prioritization)
- `SpintaxTemplate` (email body/subject variants)
- `BanditArm` (A/B test variants for subject lines, send times)
- `OutboundMessage` (tracking sent emails, responses)

**NOT directly on**:
- RFQ (no automatic RFQ from outreach)
- Quote (no quote from engagement)

**ISSUE**: 
- Autonomous Sales generates email outreach but doesn't create RFQs
- No trigger to create RFQ when Contact engages positively
- Engagement metrics are tracked separately from pipeline advancement

---

## Cross-System Analysis

### 1. Multiple Pipelines (Lead vs Company vs RFQ)

**Three independent pipeline systems exist**:

```
┌─────────────────────────────────────────────────────────────┐
│ LEAD NURTURING PIPELINE (LeadNurturingService)             │
│ new → contacted → engaged → qualified → opportunity → converted
│                                                      ↘ dormant
│ (Updates Lead entity, maps to Company stage)                │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ COMPANY PIPELINE STAGE (in Company entity)                  │
│ Prospect → MQL → SQL → SQO → Proposal → Award               │
│ (Used for forecasting, email triggers, tier assignments)    │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ RFQ WORKFLOW (RFQController)                                │
│ Pending → In Review → Submitted → Won/Lost                  │
│ (Independent status, not auto-created from Lead/Company)    │
└─────────────────────────────────────────────────────────────┘
```

**Issues**:
- Company pipeline stage and RFQ status are not synchronized
- A company at "Proposal" stage may have 0 RFQs or 5 RFQs
- Lead nurturing stage maps to Company, but neither maps to RFQ
- No entity unifies the three pipelines

---

### 2. Lead → Company → RFQ Conversion Path

**Current Flow**:
```
1. Discovery Pipeline finds Lead (pending review)
2. Reviewer approves Lead (via LeadController.approve)
3. Reviewer converts Lead → Company (via LeadController.convert)
4. LeadNurturingService tracks Lead/Company engagement
5. ???
6. Someone manually creates RFQ (via RFQController.new)
7. RFQ linked to same Company
```

**Missing Links**:
- No automatic RFQ creation when Lead qualifies
- No automatic Company → RFQ trigger
- No validation that RFQ exists before marking opportunities
- Autonomous Sales emails Leads/Contacts but doesn't create RFQs
- Win/Loss data sits only in RFQ, not fed back to Lead/Company scores

---

### 3. Overlapping Lead Scoring

**Three separate scoring/qualification mechanisms**:

1. **LeadNurturingService.calculateEngagementScore()**
   - Based on activities, fit signals, certifications, contact info
   - Updates Lead.leadScore
   - Ranges 0-100

2. **LeadSalesAnalystService.analyzeLead()**
   - Different algorithm (weights fit signals 40%, certs 35%, sectors 25%)
   - Returns `overall_fit_score` (not stored in Lead)
   - Generates sales intelligence (pain points, talking points)
   - Not used for conversion gating

3. **AutonomousSalesOrchestratorService.scoreLeads()**
   - "purchase likelihood" scoring
   - Stores scores in OutboundMessage tracking
   - Used to prioritize email outreach
   - Not stored in Lead entity

**Problem**:
- Three different scoring methods, no clear hierarchy
- Lead.leadScore updated by LeadNurturingService, not LeadSalesAnalystService
- No synchronization between them
- Unclear which score gates conversion to RFQ

---

### 4. Disconnected Email Outreach from RFQ Creation

**Autonomous Sales**:
- Scores Contacts by engagement likelihood
- Sends personalized emails with Thompson Sampling optimization
- Tracks response patterns
- Learns from engagement

**RFQ System**:
- Completely separate workflow
- Must be manually created
- No trigger when Contact engages with autonomous email
- No integration with email personalization or targeting

**Missing Integration**:
- No "trigger RFQ when Contact clicks email" automation
- No "convert engaged Contact to RFQ" workflow
- Email outreach and RFQ creation operate independently
- Autonomous sales may email competitor's employees without creating RFQs

---

### 5. Lack of "Opportunity" Entity

**System has**:
- Lead (prospect company discovery)
- Company (converted prospect)
- Contact (person at company)
- RFQ (specific quote request)
- Quote (pricing response)

**System is missing**:
- **Opportunity** (active deal/engagement with clear revenue potential)

**Current workaround**:
- Company entity repurposed as "opportunity"
- Company.pipelineStage used to indicate opportunity progress
- But Company can exist without RFQ, and RFQ can exist without Company history

**Consequences**:
- No clear definition of "deal" in the system
- Company table conflates prospects and active opportunities
- RFQ and Company are separate deal representations
- Forecasting must reconcile both (PipelineForecastingService uses Company, KPITrackingService uses RFQ)

---

### 6. Win/Loss Intelligence Isolated

**RFQ Win/Loss Data**:
- Rich competitive data: competitor names, pricing, loss reasons
- Stored in RFQ entity (lossReason, competitorWon, lessonsLearned, etc.)
- Accessible via win_loss_analytics route
- Used for competitive intelligence

**Problem**:
- Never fed back to Lead or Company scoring
- Not used to improve qualification logic
- Not used in sales assistant recommendations
- Disconnected from subsequent lead targeting

**Missing**:
- Automated learning loop: RFQ loss reason → improve Lead qualification
- Competitor tracking integrated with lead discovery
- Win patterns used to adjust outreach strategy

---

## Dead Code & Architectural Issues

### Issue 1: Multiple Lead Score Calculations (3 different algorithms)

**LeadNurturingService.calculateEngagementScore()** [Line 191+]:
```php
// Calculates from: activities, fitSignals, qualityStack, contact info
// Stores in: Lead.leadScore
// Used for: stage advancement gating
```

**LeadSalesAnalystService.analyzeLead()** [Line 113+]:
```php
// Calculates from: capability fit (40%), cert fit (35%), sector fit (25%)
// Stores in: Return value only (not persisted)
// Used for: sales intelligence, conversation starters
```

**AutonomousSalesOrchestratorService.scoreLeads()** [No direct score field shown, but referenced in docstring]:
```php
// Scores by: "purchase likelihood"
// Stores in: OutboundMessage tracking
// Used for: email outreach prioritization
```

**Recommendation**: Unify into single Lead.leadScore with clear semantics and update strategy.

---

### Issue 2: Stage Mapping Complexity

**LeadNurturingService** hardcodes stage mapping [Line 168-175]:
```php
$stageMap = [
    self::STAGE_CONTACTED => Company::STAGE_MQL,
    self::STAGE_ENGAGED => Company::STAGE_SQL,
    self::STAGE_QUALIFIED => Company::STAGE_SQO,
    self::STAGE_OPPORTUNITY => Company::STAGE_PROPOSAL,
];
```

**Problems**:
- Two separate stage enums not synchronized
- Mapping is scattered across code
- Manual updates to Company stage bypass LeadNurturingService
- No validation that stages are consistent

**Recommendation**: 
- Single stage enum with automatic translations
- Orchestrator service to coordinate all stage changes

---

### Issue 3: No RFQ Creation Automation

**Missing Workflow**:
```
Lead.leadScore ≥ 50 (qualified)
        ↓
    [NO AUTOMATION]
        ↓
Company.pipelineStage = 'SQO' (opportunity)
        ↓
    [NO AUTOMATION]
        ↓
Someone must manually create RFQ
```

**Manual Steps Required**:
1. Human visits RFQController.new
2. Selects Company from dropdown
3. Fills in RFQ details
4. Creates RFQ manually

**Recommendation**: 
- Event listener on Company stage change
- Auto-create RFQ when stage reaches SQO or Proposal
- Or explicit "Convert to RFQ" action in UI

---

### Issue 4: LeadSalesAnalystService Results Not Stored

**Method outputs**:
```php
return [
    'lead_id' => ...,
    'overall_fit_score' => ...,  // NOT in Lead.leadScore
    'fit_grade' => ...,          // Calculated but not stored
    'conversation_starters' => ...,
    'pain_points' => ...,
    ...
];
```

**Problem**:
- Analysis computed but not persisted
- Re-computed on every call (inefficient)
- Not available for filtering/sorting in UI
- Not used in automated workflows

**Recommendation**:
- Add `salesAnalysisJson` column to Lead
- Store complete analysis result
- Use fit_grade in review queue filtering

---

### Issue 5: PipelineForecastingService Ignores RFQ Data

**Current Implementation**:
```php
// src/Service/PipelineForecastingService.php
foreach ($companies as $company) {
    $dealValue = $this->estimateDealValue($company);
    // Uses Company data only, not RFQ
    $probability = $this->getStageProbability($stage);
    // Based on Company.pipelineStage only
}
```

**Problem**:
- RFQ data (status, estimated value) not considered
- Company with no RFQ treated same as Company with multiple RFQs
- More accurate forecast data in RFQ not used

**Better Approach**:
```
For each Company:
  Get associated RFQs
  If RFQ exists:
    Use RFQ.estimatedValue and RFQ.status for calculation
    Weight by RFQ count/recency
  Else:
    Fall back to Company.estimatedDealValue
```

---

### Issue 6: Autonomous Sales Email Outreach Disconnected

**Current**:
- Emails sent to Contacts with Thompson Sampling optimization
- Response tracking in separate system
- No trigger to create RFQ from engagement

**Ideal Integration**:
```
Contact receives email
    ↓
Contact opens/clicks
    ↓
Email classifier detects positive signal
    ↓
[MISSING] Automatically create RFQ for Contact's Company
    ↓
RFQ enters workflow
```

**Current Gap**: No event listener for email engagement → RFQ creation

---

### Issue 7: No Contact ↔ RFQ Relationship

**RFQ entity**:
```php
#[ORM\ManyToOne(targetEntity: Company::class)]
private ?Company $company = null;  // Has company
// No Contact reference
```

**Quote entity**:
```php
#[ORM\ManyToOne(targetEntity: Contact::class)]
private ?Contact $contact = null;  // Has contact
```

**Problem**:
- RFQ linked to Company (organization)
- Quote linked to Contact (person)
- No way to track which Contact issued RFQ
- Email personalization doesn't tie to RFQ creation
- Multiple Contacts at same Company can create duplicate RFQs

**Recommendation**: Add FK Contact to RFQ

---

## Summary of Rationality Issues

### ✗ **Not Rational**: Three Independent Lead Scoring Systems
- **LeadNurturingService** scores for stage advancement
- **LeadSalesAnalystService** scores for sales intelligence
- **AutonomousSalesOrchestratorService** scores for email prioritization
- **No coordination or validation** between them

### ✗ **Not Rational**: Lead Conversion Blocked After Company Creation
- Lead must be approved and converted to Company
- But nothing happens next
- Company sits in "Prospect" stage indefinitely
- User must manually create RFQ

### ✗ **Not Rational**: Two Pipeline Stage Systems
- **LeadNurturingService** has 8 stages (new, contacted, engaged, qualified, opportunity, converted, dormant, lost)
- **Company** has 6 stages (Prospect, MQL, SQL, SQO, Proposal, Award)
- Mapping only covers 4 Lead stages → Company stages
- No mapping for dormant, new, lost

### ✗ **Not Rational**: Email Outreach Doesn't Create Opportunities
- Autonomous Sales sends emails to Contacts
- Engagement is tracked
- But no RFQ or "opportunity" created automatically
- Sales reps must manually create RFQ after email

### ✗ **Not Rational**: Win/Loss Data Never Informs Future Leads
- RFQ losses include detailed competitor analysis
- These insights stored but never used
- Same Lead qualification mistakes repeated
- No feedback loop

### ✗ **Not Rational**: Forecast Uses Company Data, Not RFQ Data
- RFQ has accurate status and estimated value
- Company status is estimated (no guarantee RFQ exists)
- Forecasting ignores RFQ (most reliable source)
- Forecast inaccuracy acceptable when RFQ data available

### ✗ **Not Rational**: Contact Not Linked to RFQ
- Know who issued Quote (Contact FK)
- Don't know who issued RFQ (no Contact FK)
- Personalization can't tie email to RFQ
- Multiple Contacts can create duplicate RFQs

### ✗ **Not Rational**: LeadSalesAnalystService Results Not Stored
- Rich analysis computed (pain points, fit grade, talking points)
- Results discarded after method returns
- Analysis re-computed on every call
- Not available in UI for filtering/sorting

---

## Recommendations

### Short Term (Data Integrity)

1. **Add Contact FK to RFQ Entity**
   ```php
   #[ORM\ManyToOne(targetEntity: Contact::class)]
   private ?Contact $contact = null;
   ```

2. **Enforce RFQ Creation**
   - When Company reaches SQO stage: auto-create draft RFQ
   - Or add explicit "Create RFQ" action in Company detail view

3. **Unify Lead Scoring**
   - Decide: which algorithm is authoritative?
   - Store all three scores in Lead?
   - Or deprecate some methods?

4. **Add Opportunity Entity** (or repurpose Company)
   - Clarify: is Company always an "opportunity"?
   - Or should only opportunity-stage Companies be called opportunities?

### Medium Term (Workflow Integration)

5. **Create Master Orchestrator Service**
   ```php
   // PipelineOrchestratorService
   public function leadConvertedToCompany(Lead $lead, Company $company) { ... }
   public function companyAdvancedToStage(Company $company, string $newStage) { ... }
   public function rfqCreated(RFQ $rfq) { ... }
   ```
   - Centralized control of all stage transitions
   - Consistent validation

6. **Automate Lead → RFQ**
   - Create event listener: `CompanyStageAdvancedToSQO`
   - Auto-create initial RFQ draft
   - Let user refine before submitting

7. **Integrate Win/Loss Feedback Loop**
   - After RFQ marked Lost: analyze reason
   - Update LeadSalesAnalystService logic
   - Re-score similar leads with lessons learned

8. **Connect Email Engagement to RFQ**
   - When Contact engages with autonomous email
   - Trigger "create opportunity" workflow
   - Link new RFQ to Contact

### Long Term (Data Model)

9. **Add Opportunity Entity** (separate from Company)
   ```
   Company (prospect/customer master)
       ↓
   Opportunity (active engagement with revenue potential)
       ↓
   RFQ (specific request for quote)
   ```

10. **Consolidate Score Fields**
    ```php
    Lead:
      - leadScore (unified: 0-100)
      - leadGrade (A/B/C/D/F)
      - salesAnalysisJson (serialized LeadSalesAnalystService result)
      - lastScoredAt (timestamp)
    ```

11. **Unify Stage System**
    ```php
    enum PipelineStage {
        PROSPECT,      // Initial discovery
        ENGAGED,       // Contacted + showing interest
        QUALIFIED,     // Met qualification criteria
        OPPORTUNITY,   // Active deal with RFQ
        PROPOSAL,      // Quote issued
        NEGOTIATION,   // Terms being discussed
        WON,           // Contract awarded
        LOST,          // Deal ended
        DORMANT        // Inactive for 30+ days
    }
    ```

---

## Templates Summary

| System | Template | Purpose |
|--------|----------|---------|
| RFQ | index.html.twig | List all RFQs with filters |
| RFQ | pipeline.html.twig | Kanban board (Pending/In Review/Submitted/Won/Lost) |
| RFQ | new.html.twig | Create RFQ form |
| RFQ | edit.html.twig | Edit RFQ form |
| RFQ | show.html.twig | RFQ details + NDA workflow buttons |
| RFQ | win_loss_analytics.html.twig | Competitive intelligence dashboard |
| Discovery | index.html.twig | Lead discovery pipeline UI |
| Autonomous Sales | index.html.twig | Sales automation system status/control |

---

## Conclusions

### Current State
The CRM has sophisticated individual components:
- ✓ Rich lead scoring and discovery
- ✓ Intelligent email personalization and optimization
- ✓ Detailed RFQ tracking with win/loss analysis
- ✓ Advanced pipeline forecasting

### Critical Gaps
- ✗ No unified deal/opportunity concept
- ✗ Three separate lead scoring systems without coordination
- ✗ Email outreach and RFQ creation are disconnected
- ✗ No automatic workflow from Lead qualified → RFQ created
- ✗ Win/loss intelligence never feeds back to future lead qualification

### Recommendation
**Build a unified "Sales Workflow Orchestrator"** that:
1. Centralizes stage transitions across Lead, Company, and RFQ
2. Auto-creates RFQs when opportunities qualify
3. Links email engagement to RFQ creation
4. Feeds win/loss insights back into lead scoring
5. Maintains single source of truth for each deal's state

This would transform the system from a collection of point solutions into a cohesive sales pipeline.

---

*Audit completed: February 11, 2026*
*Auditor: AI Code Analysis*
