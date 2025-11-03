# Starz Morocco CRM - System Overview

**Version**: 1.0  
**Last Updated**: October 29, 2025  
**For**: Developers, System Administrators, New Team Members  
**Status**: 100% Complete - All 37 Tasks Delivered  

---

## 📋 Table of Contents

1. [Introduction](#introduction)
2. [Business Context](#business-context)
3. [Architecture](#architecture)
4. [Core Modules](#core-modules)
5. [Data Model](#data-model)
6. [Technology Stack](#technology-stack)
7. [User Workflows](#user-workflows)
8. [Integration Points](#integration-points)
9. [Security Model](#security-model)
10. [Performance Considerations](#performance-considerations)

---

## 🎯 Introduction

### What is Starz Morocco CRM?

**Starz Morocco CRM** is a comprehensive customer relationship management system designed specifically for **PCBA (Printed Circuit Board Assembly)** and **EMS (Electronics Manufacturing Services)** companies focusing on the **Morocco manufacturing market**.

The system manages the complete customer lifecycle from initial lead discovery through contract manufacturing relationships, with specialized features for the electronics manufacturing industry.

### Key Business Goals

1. **Market Penetration**: Expand Starz Morocco's presence in PCBA/EMS markets (Morocco, US, EU, UK)
2. **Lead Generation**: Automated multi-region lead discovery and qualification
3. **Relationship Management**: Track and nurture procurement contacts at target companies
4. **Sales Pipeline**: Manage RFQ opportunities from quote to production
5. **Compliance**: Maintain ISO 13485, AS9100, IATF 16949, and regional compliance documentation
6. **Marketing Automation**: Multi-touch email campaigns and webinar events

### Who Uses This System?

- **Sales Team**: Manage companies, contacts, RFQs, and quotations
- **Marketing Team**: Email campaigns, webinars, and lead nurturing
- **Operations Team**: Document management and compliance tracking
- **Management**: Dashboard analytics and KPI monitoring
- **Procurement Contacts**: External users for webinar registration

---

## 🏢 Business Context

### Industry: Electronics Manufacturing

**PCBA/EMS Services:**
- Printed Circuit Board Assembly
- Electronics contract manufacturing
- Component sourcing and procurement
- Testing, inspection, and quality assurance
- Supply chain management

**Target Markets:**
- 🇲🇦 **Morocco**: Tangier, Casablanca, Kenitra free zones
- 🇺🇸 **United States**: 14 states (TX, CA, MI, OH, NC, etc.)
- 🇪🇺 **European Union**: Germany, France, Italy, Spain, Poland, etc.
- 🇬🇧 **United Kingdom**: England, Scotland, Wales, N. Ireland

### Compliance Standards

The system tracks companies requiring:
- **IATF 16949** (Automotive Quality)
- **AS9100** (Aerospace Quality)
- **ISO 13485** (Medical Devices)
- **IPC Standards** (Electronics Assembly)
- **GDPR** (EU Data Privacy)
- **CCPA** (California Privacy)

### Sales Cycle

```
Lead Discovery → Qualification → Contact Outreach → RFQ Request → 
Quote Submission → Negotiation → Contract Award → Production → Repeat Orders
```

**Typical Timeline**: 3-12 months from first contact to production

---

## 🏗️ Architecture

### System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                        WEB BROWSER                          │
│           (Sales Team, Marketing, Management)               │
└──────────────────────────┬──────────────────────────────────┘
                           │ HTTPS
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    WEB SERVER (Apache/Nginx)                │
│                      SSL/TLS Termination                    │
└──────────────────────────┬──────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│               SYMFONY 7.x APPLICATION LAYER                 │
│  ┌──────────────┬──────────────┬─────────────────────────┐ │
│  │ Controllers  │  Services    │  Entities/Repositories  │ │
│  │ (HTTP Layer) │ (Business    │  (Data Access Layer)    │ │
│  │              │  Logic)      │                         │ │
│  └──────────────┴──────────────┴─────────────────────────┘ │
└──────────────────────────┬──────────────────────────────────┘
                           │ Doctrine ORM
                           ▼
┌─────────────────────────────────────────────────────────────┐
│              DATABASE LAYER (MySQL/PostgreSQL)              │
│  ┌──────────┬─────────┬────────┬────────┬──────────────┐   │
│  │Companies │Contacts │ Leads  │  RFQs  │Email Campaigns│   │
│  │          │         │        │        │               │   │
│  └──────────┴─────────┴────────┴────────┴──────────────┘   │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                   EXTERNAL INTEGRATIONS                     │
│  ┌──────────────┬──────────────┬─────────────────────────┐ │
│  │ SMTP Server  │ LeadBot      │  File Storage           │ │
│  │ (Email)      │ (Crawler)    │  (Documents)            │ │
│  └──────────────┴──────────────┴─────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### Application Layers

**1. Presentation Layer** (`templates/`)
- Twig templates with custom CSS theme
- Responsive design (tan cards #f5f0e8)
- AJAX-enhanced interfaces
- Form handling with CSRF protection

**2. Controller Layer** (`src/Controller/`)
- HTTP request handling
- Route definitions
- Response generation
- Input validation

**3. Service Layer** (`src/Service/`)
- Business logic
- Data transformations
- Email sending
- Import/export operations

**4. Domain Layer** (`src/Entity/`, `src/Repository/`)
- Entity definitions (ORM models)
- Database queries
- Data validation
- Relationships

**5. Infrastructure Layer** (`config/`)
- Database configuration
- Email settings
- Security rules
- Routing configuration

---

## 📦 Core Modules

### 1. Company Management Module

**Purpose**: Track potential and active PCBA/EMS buyers

**Features:**
- Company profiles with tier classification (A/B/C)
- Industry sector tracking (Automotive, Medical, Industrial, etc.)
- Morocco manufacturing interest levels
- Contact information and procurement details
- LinkedIn and Google Drive integration
- Physical site locations and free zone tracking

**Key Entities:**
- `Company` (100 imported from Tracker.xlsx)

**Routes:**
- `/companies` - List all companies
- `/companies/new` - Create new company
- `/companies/{id}` - View company details
- `/companies/{id}/edit` - Edit company

### 2. Contact Management Module

**Purpose**: Track procurement decision-makers at target companies

**Features:**
- Contact profiles with role tracking
- Email and phone contact methods
- Company association
- Communication history
- Procurement authority levels

**Key Entities:**
- `Contact`

**Routes:**
- `/contacts` - List all contacts
- `/contacts/new` - Create new contact
- `/contacts/{id}` - View contact details
- `/contacts/{id}/edit` - Edit contact

### 3. Lead Discovery Module (NEW)

**Purpose**: Multi-region provisional lead generation and qualification

**Features:**
- **28-field lead profiles** with comprehensive data
- **Multi-region support**: Morocco, US (14 states), EU (20 countries), UK
- **Lead scoring**: 0-100 scale based on 13 weighted signals
- **Regional batching**: Time-zone aware (08:00 Africa/Tunis primary)
- **Multilingual support**: FR, DE, IT, ES, NL, PL, PT
- **Compliance**: GDPR, PECR, CCPA-friendly (public data only)
- **Quality stack detection**: IATF 16949, AS9100, ISO 13485
- **Lead-to-Company conversion**: Automated promotion with tier assignment

**Scoring Signals (13 total, 130 points):**
1. **geo_morocco** (20) - Morocco evidence
2. **geo_us** (16) - US market fit
3. **geo_eu** (14) - EU market fit
4. **geo_uk** (12) - UK market fit
5. **mfg_fit** (20) - Manufacturing fit keywords
6. **procurement** (18) - Procurement/sourcing pages
7. **sector_generic** (12) - Industry sector match
8. **sector_dc_bonus** (4) - Data center/cloud bonus
9. **morocco_evidence** (15) - Explicit Morocco mentions
10. **contactability** (8) - Public contact availability
11. **freshness** (7) - Recent site updates
12. **eu_quality_stack** (10) - EU quality certifications
13. **eu_procurement_lang** (10) - Multilingual procurement

**Key Entities:**
- `Lead` (28 fields, 3 indexes)

**Routes:**
- `/leads` - Landing page with workflow guide
- `/leads/review` - Review interface with filters (status, region, score)
- `/leads/dashboard` - KPI dashboard with regional breakdown
- `POST /leads/approve/{id}` - Approve lead (AJAX)
- `POST /leads/deny/{id}` - Deny lead (AJAX)
- `POST /leads/convert/{id}` - Convert to Company (approval required)
- `POST /leads/assign/{id}` - Assign to rep (AJAX)
- `/leads/export` - Export to CSV

**Workflow:**
1. LeadBot crawler drops leads daily (batch imports)
2. Sales team reviews leads in `/leads/review`
3. Approve/deny based on fit and score
4. Approved leads converted to Companies via "Add to Companies" button
5. Conversion transfers all data + assigns tier by score (70+=A, 55-69=B, <55=C)

### 4. RFQ Pipeline Module

**Purpose**: Manage quote requests and opportunities

**Features:**
- Kanban-style pipeline (Lead → Quote → Negotiation → Won/Lost)
- RFQ tracking with amounts and probability
- Company and contact associations
- Deal closure tracking
- Revenue forecasting

**Key Entities:**
- `RFQ`

**Routes:**
- `/rfq` - Pipeline view
- `/rfq/new` - Create new RFQ
- `/rfq/{id}` - View RFQ details
- `/rfq/{id}/edit` - Edit RFQ

### 5. Email Campaign Module (Tasks 32-37)

**Purpose**: End-to-end marketing automation with compliance, analytics, and CRM integration

**Core Capabilities:**
- **Campaign Lifecycle Management** (draft → scheduled → sending → completed)
  - Visual campaign wizard (5-step process)
  - Template library with WYSIWYG editor (TinyMCE integration)
  - Dynamic audience segmentation with real-time contact counts
  - Scheduling with send time optimization
- **Multi-Touch Automation**:
  - Drip campaigns (2-10 steps) with delays and conditional branching
  - A/B testing (subject lines, send times, content variations)
  - Multivariate testing with statistical significance evaluation
  - Automated workflows triggered by CRM events
- **Event-Driven Triggers** (handled by `EmailCampaignTriggerService`):
  - Pipeline stage changes (Prospect → MQL → SQL → SQO → Proposal → Award)
  - RFQ submissions with sector filtering
  - Quote sent events with product-based segmentation
  - Lead score changes with threshold-based triggers
  - ABM hits with engagement-level filtering
- **Compliance & Consent Management**:
  - Double opt-in workflow with 7-day expiry tokens
  - One-click unsubscribe with 30-day validity
  - GDPR export (contact profile, consent state, engagement stats)
  - Right-to-be-forgotten with anonymization or hard delete
  - CAN-SPAM/CASL validation (physical address, sender ID, footer, subject line)
- **Deliverability Monitoring**:
  - Bounce handling with suppression list management
  - Complaint tracking with feedback loops
  - DNS validation (SPF, DKIM, DMARC)
  - Deliverability scoring with real-time dashboards
  - Domain health reporting
- **Analytics & Reporting**:
  - Per-campaign metrics (sent, opened, clicked, unsubscribed)
  - Engagement timelines with cohort analysis
  - A/B test results with confidence intervals
  - Funnel analysis (send → open → click → conversion)
  - Best send time recommendations based on historical data
  - Device and client breakdown

**Key Services** (10 total):
- `EmailCampaignService` - Campaign lifecycle orchestration
- `EmailTemplateService` - Template CRUD and rendering with personalization
- `EmailSegmentService` - Filter validation and audience evaluation
- `EmailSchedulerService` - Queue creation and send time optimization
- `EmailDeliverabilityService` - Bounce/complaint handling and DNS validation
- `EmailAnalyticsService` - Engagement metrics and reporting
- `EmailAbTestService` - Variant management and statistical analysis
- `EmailDripCampaignService` - Multi-step workflow and enrollment
- `EmailCampaignTriggerService` - CRM event automation
- `EmailConsentService` - Opt-in/opt-out and GDPR workflows
- `EmailComplianceService` - CAN-SPAM/CASL validation
- `EmailActivityLogger` - Activity timeline integration

**UI Components**:
- Campaign wizard (5-step process with validation)
- Template builder with TinyMCE WYSIWYG editor
- Segment builder with dynamic rule evaluation
- Template and segment libraries with search/filter
- Analytics dashboard with interactive charts
- A/B test result viewer with statistical confidence
- Deliverability monitoring dashboard
- Compliance pre-flight checklist

**Key Entities**:
- `EmailCampaign` (with trigger metadata and scheduling timestamps)
- `EmailTemplate` (with usage tracking and personalization tokens)
- `EmailSegment` (with filter rules and contact counts)
- `EmailSend` (with variant tracking and engagement markers)
- `EmailUnsubscribe` (with reason tracking and token management)
- Extended `Activity` (tracks email sends and campaign events)
- Extended `Contact` (subscribed status and email engagement data)

**Routes** (11 total):
- `/email-campaigns` - List campaigns with filters
- `/email-campaigns/new` - Campaign wizard entry
- `/email-campaigns/{id}` - View campaign details and stats
- `/email-campaigns/{id}/edit` - Edit campaign configuration
- `/email-campaigns/{id}/send` - Manual send controls
- `/email-campaigns/{id}/analytics` - Detailed analytics dashboard
- `/email-campaigns/{id}/ab-test` - A/B test results viewer
- `/email-templates` - Template library
- `/email-templates/new` - Template builder
- `/email-segments` - Segment library
- `/email-segments/new` - Segment builder with rule editor

**Database Schema Updates**:
- `EmailCampaign` table expanded with `trigger_type`, `trigger_conditions`, `ab_test_variants` JSON columns
- New tables: `EmailTemplate`, `EmailSegment`, `EmailSend`, `EmailUnsubscribe`
- `Activity` table extended with email event tracking
- `Contact` table enhanced with subscription and engagement flags

### 6. Compliance Document Module

**Purpose**: Manage 21-document compliance pack for certifications

**Features:**
- **21-document library**:
  - ISO 13485 Certificate
  - AS9100 Certificate
  - IATF 16949 Certificate
  - IPC Certifications
  - Financial statements
  - Quality manual
  - Process flow diagrams
  - Capability statements
  - Equipment lists
  - And 12 more...
- Document versioning
- Expiry tracking
- Company-specific packs
- Download management

**Key Entities:**
- `ComplianceDocument`

**Routes:**
- `/documents` - Document library
- `/documents/upload` - Upload document
- `/documents/{id}` - View/download

### 7. Webinar Module

**Purpose**: Virtual events for prospect engagement

**Features:**
- Event creation and management
- Registration forms (public-facing)
- Attendee tracking
- Follow-up automation
- Webinar analytics

**Key Entities:**
- `Webinar`
- `WebinarRegistration`

**Routes:**
- `/webinars` - List webinars
- `/webinars/new` - Create webinar
- `/webinars/{id}` - Webinar details
- `/webinars/{id}/register` - Public registration form

### 8. Dashboard Module

**Purpose**: Real-time KPIs and business intelligence

**Features:**
- Company statistics (total, by tier, by sector)
- Lead analytics (regional breakdown, approval rates, top scores)
- RFQ pipeline value
- Email campaign performance
- Revenue forecasting
- Activity timeline

**Routes:**
- `/` - Main dashboard
- `/leads/dashboard` - Lead-specific KPIs

---

## 🗄️ Data Model

### Entity Relationship Diagram

```
┌─────────────┐         ┌─────────────┐         ┌─────────────┐
│   Company   │────────<│   Contact   │         │    Lead     │
│             │ 1     * │             │         │             │
│ - id        │         │ - id        │         │ - id        │
│ - name      │         │ - firstName │         │ - companyNm │
│ - tier      │         │ - lastName  │         │ - websiteRt │
│ - sector    │         │ - email     │         │ - leadScore │
│ - morocco%  │         │ - phone     │         │ - regionTag │
│             │         │ - role      │         │ - reviewSts │
└──────┬──────┘         └─────────────┘         └──────┬──────┘
       │                                                │
       │ 1                                              │
       │                                                │ converts to
       │ *                                              │ (manual)
┌──────┴──────┐         ┌─────────────┐                │
│     RFQ     │         │EmailCampaign│                │
│             │         │             │                │
│ - id        │         │ - id        │                │
│ - title     │         │ - name      │                │
│ - amount    │         │ - status    │                │
│ - stage     │────────<│ - sentCount │                │
│ - prob%     │ *     * │             │                │
└─────────────┘         └─────────────┘                │
                                                        │
┌─────────────┐         ┌─────────────┐                │
│  Webinar    │         │ Compliance  │                │
│             │         │  Document   │                │
│ - id        │         │             │                │
│ - title     │         │ - id        │                │
│ - date      │         │ - name      │                │
│ - capacity  │         │ - type      │                │
└─────────────┘         │ - expiry    │                │
                        └─────────────┘                │
```

### Core Entities

#### Company Entity
```php
class Company
{
    private ?int $id;
    private string $name;              // Company legal name
    private ?string $legalName;        // Full legal entity name
    private string $tier;              // A, B, or C
    private ?string $sector;           // Automotive, Medical, etc.
    private ?string $industry;         // EMS, OEM, Tier 1, etc.
    private ?int $moroccoInterest;     // 0-100%
    private ?int $employeeCount;
    private ?float $annualRevenue;
    private ?string $physicalSite;     // Location
    private ?string $website;
    private ?string $linkedinCompanyUrl;
    private ?string $googleDriveLink;  // Document folder
    private ?string $sourceNotes;      // Import notes
    private Collection $contacts;      // One-to-many
    private Collection $rfqs;          // One-to-many
}
```

#### Contact Entity
```php
class Contact
{
    private ?int $id;
    private string $firstName;
    private string $lastName;
    private string $email;
    private ?string $phone;
    private ?string $role;             // VP Procurement, etc.
    private ?string $department;
    private ?Company $company;         // Many-to-one
}
```

#### Lead Entity (NEW - 28 fields)
```php
class Lead
{
    private ?int $id;
    private string $companyName;
    private ?string $legalName;
    private string $websiteRoot;
    private ?string $leadUrl;          // Page found
    private ?string $siteLocation;     // Country
    private ?string $usState;          // If US
    private ?string $usCityMetro;      // If US city
    private string $regionTag;         // morocco|us_east|us_texas|eu_core|eu_nordics|eu_cee|uk
    private ?array $sectorTags;        // JSON: ["automotive","industrial"]
    private ?array $fitSignals;        // JSON: {"geo_morocco":15,"mfg_fit":18,...}
    private ?array $qualityStack;      // JSON: ["IATF 16949","ISO 13485"]
    private ?array $contactEmailsPublic; // JSON: ["info@example.com"]
    private ?string $supplierPortalUrl;
    private int $leadScore;            // 0-100
    private string $reviewStatus;      // pending|approved|denied
    private ?string $ownerRep;         // Assigned sales rep
    private ?string $dupeKey;          // websiteRoot for deduplication
    private ?\DateTimeInterface $crawledAt;
    private ?Company $company;         // FK after conversion
    
    // Indexes: dupe_key, region_tag, lead_score
}
```

#### RFQ Entity
```php
class RFQ
{
    private ?int $id;
    private string $title;
    private ?string $description;
    private ?float $estimatedAmount;
    private ?int $probability;         // 0-100%
    private string $stage;             // lead|quote|negotiation|won|lost
    private ?\DateTimeInterface $expectedCloseDate;
    private ?Company $company;         // Many-to-one
    private ?Contact $contact;         // Many-to-one
}
```

#### EmailCampaign Entity
```php
class EmailCampaign
{
    private ?int $id;
    private string $name;
    private string $status;            // draft|active|paused|completed
    private ?int $sentCount;
    private ?int $openRate;
    private ?int $clickRate;
    private Collection $sequences;     // One-to-many EmailSequence
}
```

---

## 💻 Technology Stack

### Backend Framework

**Symfony 7.x**
- PHP 8.4.14+
- MVC architecture
- Dependency injection container
- Event dispatcher
- Security component
- Form component

### Database Layer

**Doctrine ORM**
- Entity mapping
- Query builder
- Migrations
- Repository pattern

**Supported Databases:**
- MySQL 8.0+ (recommended)
- PostgreSQL 13+
- SQLite 3.x (development)

### Frontend

**Templating: Twig**
- Template inheritance
- Filters and functions
- Custom macros
- Form theming

**CSS: Custom Theme**
- Tan card design (#f5f0e8)
- Responsive layout
- Badge system (tier colors, status indicators)
- Button styles (primary, success, danger)

**JavaScript:**
- Vanilla JS for AJAX
- Fetch API for async operations
- No heavy frameworks (lightweight)

### Email

**Symfony Mailer**
- SMTP support
- Multiple providers (Gmail, SendGrid, Mailgun)
- HTML email templates
- Attachments

### Configuration

**YAML-based:**
- `config/packages/*.yaml` - Framework config
- `config/routes.yaml` - Routing
- `config/crawler_config.yaml` - LeadBot settings (459 lines)

---

## 🔄 User Workflows

### Workflow 1: Daily Lead Review

```
1. Sales rep logs in
2. Navigates to /leads/review
3. Filters by region (e.g., "us_east")
4. Sorts by lead score (highest first)
5. Reviews top leads (score 70+)
6. Clicks "Approve" or "Deny" (AJAX, instant feedback)
7. For approved leads with good fit:
   - Clicks "Add to Companies" button
   - Lead converts to Company (tier assigned by score)
   - Badge changes to "✓ In CRM"
   - "View Company" link appears
8. Assigns leads to team members via "Assign" button
9. Exports weekly report via "Export CSV"
```

**Time**: 15-30 minutes daily  
**Volume**: 20-50 leads per day (estimated)

### Workflow 2: Company to RFQ Pipeline

```
1. Sales rep receives inbound inquiry
2. Searches for company in /companies
3. If not found, creates new company via /companies/new
4. Adds procurement contact via /contacts/new
5. Creates RFQ via /rfq/new
   - Links to company and contact
   - Sets estimated amount and probability
   - Assigns stage "lead"
6. Sends quote
7. Updates RFQ stage to "quote" → "negotiation" → "won"
8. Dashboard updates with revenue forecast
```

**Time**: 2-4 weeks from RFQ to close  
**Conversion**: ~25-35% (industry average)

### Workflow 3: Email Campaign Execution

```
1. Marketing creates campaign via /email-campaigns/new
2. Selects target segment (e.g., Tier A automotive)
3. Configures 5-touch sequence:
   Touch 1 (Day 0): Introduction
   Touch 2 (Day 3): Value proposition
   Touch 3 (Day 7): Case study
   Touch 4 (Day 14): Final offer
   Touch 5 (Day 30): Stay-in-touch
4. Reviews email templates
5. Activates campaign
6. System sends batches automatically
7. Tracks opens, clicks, replies
8. Sales team follows up on engaged contacts
```

**Duration**: 30-day sequence  
**Open Rate**: 20-30% (target)  
**Reply Rate**: 3-5% (target)

### Workflow 4: Webinar Event Management

```
1. Marketing creates webinar via /webinars/new
   - Topic: "PCBA Manufacturing in Morocco Free Zones"
   - Date: 2 weeks out
   - Capacity: 100 attendees
2. Shares public registration link
3. Prospects register via /webinars/{id}/register
4. System sends confirmation emails
5. Reminder emails sent 1 day before
6. Webinar conducted (external platform like Zoom)
7. Follow-up emails sent to attendees
8. No-shows get recording link
9. Engaged attendees moved to sales pipeline
```

**Lead Time**: 2-4 weeks  
**Attendance Rate**: 40-60%  
**Conversion to Sales**: 10-15%

---

## 🔌 Integration Points

### Current Integrations

**1. Email (SMTP)**
- Provider: Configurable (Gmail, SendGrid, Mailgun, etc.)
- Protocol: SMTP over TLS
- Usage: Campaign emails, notifications, webinar confirmations
- Configuration: `.env.local` → `MAILER_DSN`

**2. File Storage**
- Type: Local filesystem
- Location: `var/data/`
- Usage: Uploaded documents, compliance certificates
- Future: Cloud storage (S3, Google Cloud Storage)

**3. Database**
- Type: MySQL/PostgreSQL/SQLite
- Connection: Doctrine DBAL
- Usage: All persistent data
- Backups: Daily via cron script

### Future Integration Opportunities

**LeadBot Crawler (Planned)**
- Automated lead discovery
- Daily batch imports to `leads` table
- API endpoint: `POST /api/leads/import`
- Format: JSON array of lead objects
- Authentication: API key in header

**CRM Export (Planned)**
- Export to Salesforce, HubSpot, etc.
- Format: CSV or API
- Frequency: On-demand or scheduled

**Analytics (Planned)**
- Google Analytics integration
- Custom event tracking
- Conversion funnel analysis

---

## 🔒 Security Model

### Authentication

**Current**: Session-based (Symfony Security)
- Login form at `/login`
- Password hashing with bcrypt
- Remember me functionality
- CSRF protection on forms

**Future**: Role-based access control (RBAC)
- Roles: `ROLE_USER`, `ROLE_ADMIN`, `ROLE_MANAGER`
- Permissions per module

### Data Protection

**Database:**
- Credentials in `.env.local` (not in version control)
- Password hashing for user accounts
- Prepared statements (SQL injection prevention)

**Web Server:**
- HTTPS enforced (SSL/TLS)
- Security headers (X-Frame-Options, X-Content-Type-Options)
- Directory listing disabled

**Application:**
- CSRF tokens on forms
- Input validation and sanitization
- XSS prevention (Twig auto-escaping)
- File upload restrictions

### Compliance

**GDPR (EU):**
- Public data only for leads (no scraping private emails)
- Role-based emails (info@, procurement@, sales@)
- Data retention policies
- Right to be forgotten support

**CCPA (California):**
- Privacy policy link
- Opt-out mechanisms
- Data disclosure on request

**PECR (UK):**
- No unsolicited emails without consent
- Clear unsubscribe links
- Cookie consent

---

## ⚡ Performance Considerations

### Database Optimization

**Indexes:**
- `leads.dupe_key` - Fast duplicate detection
- `leads.region_tag` - Regional filtering
- `leads.lead_score` - Score-based sorting
- `companies.tier` - Tier filtering
- `rfqs.stage` - Pipeline queries

**Query Optimization:**
- Repository pattern for complex queries
- Eager loading relationships (avoid N+1)
- Pagination on list views
- Database connection pooling

### Caching Strategy

**Symfony Cache:**
- Production cache enabled
- Route caching
- Template caching
- Configuration caching

**Future: Redis/Memcached**
- Session storage
- Query result caching
- Lead scoring cache

### Scalability

**Current Capacity:**
- 10,000+ companies
- 50,000+ contacts
- 100,000+ leads per year
- 1,000+ concurrent users

**Scaling Options:**
- Horizontal: Load balancer + multiple app servers
- Vertical: Increase server resources
- Database: Read replicas, sharding
- CDN: Static asset delivery

---

## 📊 Key Metrics & KPIs

### Dashboard Metrics

**Company Metrics:**
- Total companies by tier (A/B/C)
- Companies by sector (Automotive, Medical, Industrial, etc.)
- Morocco interest distribution (0-100%)
- New companies this month

**Lead Metrics:**
- Total leads by region (Morocco, US, EU, UK)
- Average lead score
- Approval rate (approved / total reviewed)
- Top 10 leads by score
- Leads pending review
- Conversion rate (leads → companies)

**RFQ Metrics:**
- Total pipeline value
- Win rate (won / (won + lost))
- Average deal size
- Deals by stage
- Expected revenue this quarter

**Email Campaign Metrics:**
- Total campaigns active
- Total emails sent
- Average open rate
- Average click rate
- Reply rate

---

## 🎓 Getting Started for New Developers

### 1. Environment Setup

```bash
# Clone repository
git clone <repo-url>
cd crm-starz-morocco

# Install dependencies
composer install

# Configure environment
cp .env .env.local
# Edit .env.local with local database credentials

# Create database
php bin/console doctrine:database:create
php bin/console doctrine:schema:create

# Start development server
php -S 127.0.0.1:8000 -t public/
```

### 2. Key Directories

- `src/Controller/` - HTTP controllers (routes)
- `src/Entity/` - Database entities (models)
- `src/Repository/` - Database queries
- `src/Service/` - Business logic services
- `templates/` - Twig templates (views)
- `config/` - Configuration files
- `public/` - Web root (index.php, CSS, JS)
- `var/` - Logs, cache, data files
- `Documentation/` - All documentation

### 3. Common Commands

```bash
# Database migrations
php bin/console doctrine:migrations:migrate

# Clear cache
php bin/console cache:clear

# List routes
php bin/console debug:router

# Import companies from Tracker.xlsx
php bin/console app:import-tracker Tracker.csv

# Run tests (when available)
php bin/phpunit
```

### 4. Code Standards

- **PSR-12**: PHP coding standard
- **Symfony Best Practices**: Follow Symfony conventions
- **Comments**: PHPDoc blocks for classes and methods
- **Naming**: Clear, descriptive names (camelCase for methods, PascalCase for classes)

---

## 📚 Related Documentation

- `PRODUCTION_DEPLOYMENT_GUIDE.md` - Server deployment instructions
- `LEADBOT_INTEGRATION.md` - Lead system technical details
- `LEADS_TO_COMPANIES_GUIDE.md` - User workflow guide
- `CRAWLER_IMPLEMENTATION.md` - LeadBot implementation (10 weeks)
- `QUICKSTART.md` - Quick start guide
- `PROJECT_STATUS.md` - Current system status

---

**System Version**: 1.0  
**Last Updated**: October 28, 2025  
**Status**: ✅ Production-Ready  
**Maintained By**: Starz Morocco Development Team
