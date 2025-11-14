# CRM Intelligent Webcrawler & Lead Discovery System

## Overview

An AI-powered lead discovery system that automatically finds highly relevant Morocco-based PCBA/electronics assembly buyers and EU Tier-1/Tier-2 companies sourcing in Morocco. The system delivers clean, deduped, enriched leads with intelligent scoring for efficient human triage.

### Success Metrics (60-day targets)

- **Precision @ top-50**: ≥75% approval rate on daily top 50 leads
- **Net-new targets**: ≥10 approved targets per week not already in CRM
- **Review efficiency**: ≤45 seconds median time per lead (thanks to enrichment & scoring)

### Key Capabilities

1. **Intelligent Lead Scoring** (0-100 scale)
2. **Automated Company Discovery** (Morocco free zones + EU Tier-1/2)
3. **Contact Finding** (procurement roles only, GDPR-compliant)
4. **Deduplication** (fuzzy matching + domain checking)
5. **Human-in-the-Loop** workflow (approve/deny with audit trail)
6. **CRM Sync** (automatic account/contact creation)

## How It Works

### Relevance Scoring Model

Each lead is scored 0-100 based on weighted signals:

### Relevance Scoring Model

Each lead is scored 0-100 based on weighted signals:

| Signal | Weight | Criteria |
|--------|--------|----------|
| **Geo** | 20 | Address/page mentions Morocco free zones (TAC, TFZ, Kenitra AFZ, Midparc, Bouskoura, Casablanca) |
| **Manufacturing Fit** | 20 | Keywords: PCBA, SMT, EMS, electronics assembly, contract manufacturing, power electronics, BMS |
| **Procurement Readiness** | 18 | Supplier portal, vendor registration, RFQ/RFP, quality requirements, PPAP, IMDS, RoHS/REACH |
| **Sector Fit** | 12 | Automotive, Aerospace, Rail, Industrial, Energy, Renewables, Medical Device, Data Center |
| **Morocco Evidence** | 15 | Supply news, press releases, job postings in Morocco, facility pages |
| **Contactability** | 8 | Role-based procurement/quality emails published, contact form, supplier portal |
| **Freshness** | 7 | Page updated in last 18 months |

**Thresholds:**
- **≥55**: Auto-recommend for approval
- **30-54**: Human review required
- **<30**: Auto-drop (too low relevance)

**Example Score Breakdown:**
```
Morocco Free Zone (20) + PCBA/SMT (15) + Supplier Portal (12) + 
Automotive (9) + Contact Form (4) + Fresh Content (7) = 67 → APPROVE
```

## Architecture

### Technology Stack

First, convert Tracker.xlsx to CSV format:
- Open Tracker.xlsx in Excel
- File > Save As > CSV (Comma delimited)
- Save as `Tracker.csv`

Then import:

```bash
php bin/console app:import-tracker "C:\Users\sadok\CRM Project\Tracker.csv"
```

Or generate a template for manual data entry:

```bash
php bin/console app:import-tracker --template
```

**What it imports:**
- Company names, sectors, locations
- Websites and LinkedIn URLs
- Account tiers (A/B/C) and pipeline stages
- Supplier portal information
- Portal registration status and dates
- Buyer contact information

### 2. Discover Companies Using Webcrawler

Discover companies in a specific sector:

```bash
php bin/console app:discover-companies --sector=Automotive
```

Discover companies in a sector and location:

```bash
php bin/console app:discover-companies --sector=Automotive --location="Tanger Free Zone"
```

Discover across all sectors (warning: generates many searches):

```bash
php bin/console app:discover-companies --all
```

**Available sectors:**
- Automotive
- Industrial
- Aerospace
- Rail
- Renewables
- Power Electronics

**Available locations:**
- TAC (Tanger Automotive City)
- TFZ (Tanger Free Zone)
- AFZ Kenitra (Atlantic Free Zone Kenitra)
- Casablanca/Midparc
- Bouskoura
- Nouaceur

**What it does:**
- Generates LinkedIn Sales Navigator search URLs
- Creates Google Dork queries for finding companies
- Searches for company websites
- Automatically saves discovered companies to database
- Avoids duplicates
- Tags companies with "Auto-discovered by webcrawler"

### 3. Find Contacts at Companies

Find procurement/purchasing contacts at a specific company:

```bash
# By company ID
php bin/console app:find-contacts 123

# By company name
php bin/console app:find-contacts "Yazaki Morocco"
```

**What it finds:**
- Procurement Engineers
- Purchasing Engineers
- Commodity Managers
- Buyers
- Supply Chain Managers
- Supplier Quality Engineers
- Category Managers

**Output:**
- LinkedIn search URLs for each role
- Google Dork queries for finding email addresses
- LinkedIn company profile search (if not already in database)

## How the Webcrawler Works

### Company Discovery Service
(`src/Service/WebCrawler/CompanyDiscoveryService.php`)

Main orchestrator that:
- Coordinates LinkedIn and Google searches
- Deduplicates results
- Saves companies to database
- Enriches existing company data

### LinkedIn Scraper Service
(`src/Service/WebCrawler/LinkedInScraperService.php`)

Generates LinkedIn search URLs for:
- Company profiles by sector and location
- Procurement contacts at specific companies
- Role-based searches

**Note:** For production, integrate with:
- LinkedIn Sales Navigator API
- RocketReach API
- Apollo.io API

### Google Dork Service
(`src/Service/WebCrawler/GoogleDorkService.php`)

Creates Google search queries using advanced operators:
- Find company websites
- Discover supplier portal registration pages
- Find procurement contact emails
- Search industry directories
- Locate company certifications (ISO, IATF, AS9100)

**Note:** For production, integrate with:
- Google Custom Search API
- SerpAPI
- ScraperAPI

### Tracker Import Service
(`src/Service/Import/TrackerImportService.php`)

Imports data from CSV files:
- Reads CSV with flexible column mapping
- Creates new companies
- Updates existing companies
- Imports supplier portal data
- Handles dates and validation
- Provides detailed import statistics

## CSV Import Format

Expected columns (case-insensitive, flexible naming):

| Column | Variations | Required | Example |
|--------|-----------|----------|---------|
| Company Name | company_name, name | Yes | "Yazaki Morocco" |
| Sector | sector, Industry | No | "Automotive" |
| Location | location, Physical Site, Region | No | "Tanger Free Zone" |
| Website | website, URL | No | "https://yazaki.com" |
| LinkedIn | linkedin, LinkedIn Company URL | No | "https://linkedin.com/company/yazaki" |
| Account Tier | Tier, tier, Priority | No | "A" |
| Pipeline Stage | Stage, stage, Status | No | "SQL" |
| Portal URL | portal_url, Supplier Portal | No | "https://supplier.yazaki.com" |
| Portal Registered | Registered | No | "Yes" |
| Portal ID | Account ID | No | "SUPP-001" |
| Submitted Date | Portal Submitted | No | "2024-01-15" |
| Approval Date | Portal Approved | No | "2024-02-01" |
| Buyer Name | Portal Contact | No | "John Doe" |
| Buyer Email | Portal Contact Email | No | "john@company.com" |
| Notes | notes, Source Notes | No | "Tier 1 supplier" |

## Workflow Examples

### 1. Import Existing Data from Tracker.xlsx

```bash
# Convert to CSV first in Excel, then:
php bin/console app:import-tracker "C:\Users\sadok\CRM Project\Tracker.csv"

# Review imported companies in web interface
# Visit: http://127.0.0.1:8000/companies
```

### 2. Discover New Automotive Companies

```bash
# Run discovery
php bin/console app:discover-companies --sector=Automotive

# System generates search URLs
# Visit URLs to find companies
# Add manually or use API integrations
```

### 3. Find Contacts at Imported Companies

```bash
# Find a company
# Visit: http://127.0.0.1:8000/companies

# Find contacts for company ID 5
php bin/console app:find-contacts 5

# Visit generated LinkedIn URLs
# Add contacts through web interface
```

### 4. Complete Sector Coverage

```bash
# Import existing data
php bin/console app:import-tracker Tracker.csv

# Discover companies in each sector
php bin/console app:discover-companies --sector=Automotive
php bin/console app:discover-companies --sector=Aerospace
php bin/console app:discover-companies --sector=Industrial
# etc...

# Find contacts for each company
# Use web interface to browse companies
# Click "Find Contacts" button on company detail page
```

## Production API Integrations

For fully automated operation, integrate these APIs:

### LinkedIn Sales Navigator API
- Automatic company profile discovery
- Contact data extraction
- Job title verification
- Connection status tracking

**Setup:**
1. Apply for LinkedIn Sales Navigator API access
2. Configure credentials in `.env`:
   ```
   LINKEDIN_API_KEY=your_key
   LINKEDIN_API_SECRET=your_secret
   ```

### RocketReach API
- Find email addresses
- Get phone numbers
- Verify contact information
- Enrich contact profiles

**Setup:**
```
ROCKETREACH_API_KEY=your_key
```

### Apollo.io API
- Contact database access
- Company enrichment
- Email verification
- Technographic data

**Setup:**
```
APOLLO_API_KEY=your_key
```

### Google Custom Search API
- Automated Google searches
- Structured result parsing
- Rate limit handling
- Result caching

**Setup:**
```
GOOGLE_API_KEY=your_key
GOOGLE_SEARCH_ENGINE_ID=your_id
```

## Automation & Scheduling

### Run Daily Discovery (Linux/Mac)

Add to crontab:

```bash
# Run discovery for each sector daily at 2 AM
0 2 * * * cd /path/to/crm && php bin/console app:discover-companies --sector=Automotive
30 2 * * * cd /path/to/crm && php bin/console app:discover-companies --sector=Aerospace
# etc...
```

### Run Weekly Discovery (Windows Task Scheduler)

1. Open Task Scheduler
2. Create Basic Task
3. Trigger: Weekly
4. Action: Start a program
5. Program: `php`
6. Arguments: `bin/console app:discover-companies --sector=Automotive`
7. Start in: `C:\Users\sadok\CRM Project\crm-starz-morocco`

## Logs

Webcrawler activity is logged to:
- `var/log/dev.log` (development)
- `var/log/prod.log` (production)

View logs:
```bash
tail -f var/log/dev.log | grep -i "crawler\|discovery\|import"
```

## Data Quality

The webcrawler includes:
- **Duplicate prevention**: Companies checked before insertion
- **Data validation**: Websites and emails validated
- **Source tracking**: All auto-discovered companies tagged with source
- **Manual verification**: Review recommended for all automated data
- **Update protection**: Existing manual data not overwritten

## Troubleshooting

### Import fails with "File not found"

Make sure to convert Tracker.xlsx to CSV first:
- Open in Excel
- Save As > CSV

### No companies discovered

The webcrawler generates search URLs but requires:
- API integrations for automation, OR
- Manual review of generated URLs

### Rate limiting

If using APIs:
- Implement delays between requests
- Use `sleep(2)` in discovery loops
- Configure API rate limits

## Next Steps

1. **Import your existing Tracker.xlsx data**
   ```bash
   php bin/console app:import-tracker Tracker.csv
   ```

2. **Review imported companies**
   - Visit http://127.0.0.1:8000/companies
   - Verify data accuracy
   - Update account tiers and stages

3. **Discover additional companies** (optional)
   ```bash
   php bin/console app:discover-companies --sector=Automotive
   ```

4. **Find contacts for companies**
   - Use web interface "Find Contacts" button
   - Or: `php bin/console app:find-contacts <company-id>`

5. **Set up API integrations** (production)
   - LinkedIn Sales Navigator
   - RocketReach
   - Apollo.io
   - Google Custom Search

## Support

For issues or questions:
- Check `var/log/dev.log` for errors
- Review command help: `php bin/console app:import-tracker --help`
- Verify CSV format matches template
