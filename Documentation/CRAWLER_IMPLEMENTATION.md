# Morocco PCBA Lead Discovery Crawler - Implementation Guide

## Executive Summary

This document outlines the production-ready implementation of an intelligent web crawler that automatically discovers Morocco-based PCBA/electronics assembly companies and EU Tier-1/Tier-2 buyers sourcing in Morocco.

**Success Criteria (60 days):**
- Precision @ top-50 ≥ 75%
- ≥10 net-new approved targets/week
- ≤45 seconds median review time per lead

## Phase 1: Core Infrastructure (Weeks 1-2)

### 1.1 Repository Structure

```
crm-starz-morocco/
├── leadbot/
│   ├── crawler/              # Scrapy spiders & pipelines
│   │   ├── spiders/
│   │   │   ├── morocco_spider.py
│   │   │   └── supplier_portal_spider.py
│   │   ├── settings.py       # Polite crawler settings
│   │   ├── pipelines.py      # Processing pipeline
│   │   └── middlewares.py
│   ├── parsers/              # HTML extraction
│   │   ├── address_extractor.py
│   │   ├── email_extractor.py
│   │   └── contact_parser.py
│   ├── scoring/              # Lead scoring engine
│   │   ├── scorer.py         # Main scoring logic
│   │   └── feature_extractor.py
│   ├── dedupe/               # Deduplication
│   │   ├── fuzzy_matcher.py  # Jaro-Winkler ≥0.92
│   │   └── crm_checker.py
│   ├── enrichment/           # Optional API integrations
│   │   ├── clearbit_adapter.py
│   │   └── firmographics.py
│   ├── etl/                  # Output writers
│   │   ├── csv_writer.py
│   │   ├── xlsx_writer.py
│   │   └── email_notifier.py
│   ├── ui/                   # Review interface
│   │   ├── streamlit_app.py  # Lightweight review UI
│   │   └── templates/
│   ├── crm/                  # Symfony CRM integration
│   │   ├── sync_service.php
│   │   └── entity_mapper.php
│   ├── jobs/                 # Scheduling
│   │   ├── daily_run.py
│   │   └── airflow_dag.py
│   └── tests/
└── config/
    └── crawler_config.yaml   # All weights/keywords/zones
```

### 1.2 Scrapy Configuration (Polite & Compliant)

**File: `leadbot/crawler/settings.py`**

```python
# Polite Crawler Settings
BOT_NAME = 'starz-leadbot'
USER_AGENT = 'Starz-Leadbot/1.0 (+support@starz-morocco.com)'

# Respect robots.txt
ROBOTSTXT_OBEY = True

# Politeness settings
DOWNLOAD_DELAY = 2.5                    # 2.5 seconds between requests
CONCURRENT_REQUESTS_PER_DOMAIN = 1      # 1 request at a time per domain
CONCURRENT_DOMAINS = 2                  # Max 2 domains simultaneously
AUTOTHROTTLE_ENABLED = True
AUTOTHROTTLE_START_DELAY = 2
AUTOTHROTTLE_MAX_DELAY = 10
DOWNLOAD_TIMEOUT = 30

# Retry settings
RETRY_ENABLED = True
RETRY_TIMES = 3
RETRY_HTTP_CODES = [500, 502, 503, 504, 522, 524, 408, 429]

# Cache settings (respect ETags & Last-Modified)
HTTPCACHE_ENABLED = True
HTTPCACHE_EXPIRATION_SECS = 86400  # 24 hours
HTTPCACHE_POLICY = 'scrapy.extensions.httpcache.RFC2616Policy'

# Logging
LOG_LEVEL = 'INFO'
LOG_FILE = '/var/crm/logs/crawler.log'
```

## Phase 2: Seed Sources & Frontier Building (Week 2)

### 2.1 High-Quality Seeds

**Morocco Free Zone Directories:**
- Tangier Automotive City (TAC) tenant list
- Tanger Med Zones company directory
- Atlantic Free Zone Kenitra listings
- Midparc/Bouskoura industrial parks
- AMDIE (Morocco investment agency) public listings
- AMICA (automotive association) member pages

**OEM/Tier-1 Supplier Pages:**
- Major automotive OEMs' "Suppliers" pages
- EU Tier-1s with "Locations" showing Morocco
- Electronics distributors' vendor lists

**Procurement Pages:**
- Public "Become a Supplier" portals
- RFQ/RFP submission pages
- Supplier quality requirement pages

### 2.2 Frontier Expansion

**File: `leadbot/crawler/spiders/morocco_spider.py`**

```python
import scrapy
from scrapy.linkextractors import LinkExtractor

class MoroccoSpider(scrapy.Spider):
    name = 'morocco_discovery'
    
    # Load seeds from config
    start_urls = load_seed_urls()
    
    # Extract links matching patterns
    link_extractor = LinkExtractor(
        allow=(
            r'/suppliers?',
            r'/become-a-supplier',
            r'/vendor-registration',
            r'/locations?',
            r'/manufacturing',
            r'/morocco',
            r'/facilities',
            r'/quality',
        ),
        deny=(
            r'/login',
            r'/account',
            r'/cart',
            r'/checkout',
        )
    )
    
    def parse(self, response):
        # Extract company data
        lead = self.extract_lead_data(response)
        
        if lead:
            yield lead
        
        # Follow relevant links
        for link in self.link_extractor.extract_links(response):
            yield scrapy.Request(link.url, callback=self.parse)
```

## Phase 3: Parsing & Feature Extraction (Weeks 3-4)

### 3.1 Morocco Location Extractor

**File: `leadbot/parsers/address_extractor.py`**

```python
import re
from typing import List, Dict

class MoroccoLocationExtractor:
    """Extract Morocco free zone locations from HTML"""
    
    # Regex patterns for Morocco zones
    ZONE_PATTERNS = [
        r'tangier automotive city|tac\b',
        r'tanger med|tmz\b',
        r'tangier free zone|tfz\b',
        r'kenitra|atlantic free zone|afz',
        r'midparc|bouskoura',
        r'casablanca free zone',
        r'zone franche',
    ]
    
    def extract_locations(self, html: str) -> List[Dict]:
        """Find all Morocco location mentions"""
        locations = []
        html_lower = html.lower()
        
        for pattern in self.ZONE_PATTERNS:
            matches = re.finditer(pattern, html_lower, re.IGNORECASE)
            for match in matches:
                # Extract context (50 chars before/after)
                start = max(0, match.start() - 50)
                end = min(len(html), match.end() + 50)
                context = html[start:end]
                
                locations.append({
                    'zone': match.group(),
                    'context': context.strip(),
                    'position': match.start()
                })
        
        return locations
```

### 3.2 Contact Email Extractor (GDPR-Compliant)

**File: `leadbot/parsers/email_extractor.py`**

```python
import re
from typing import List

class ContactEmailExtractor:
    """Extract role-based emails only (GDPR-compliant)"""
    
    # Only allowed role-based prefixes
    ROLE_PREFIXES = [
        'purchasing', 'procurement', 'supplier', 'vendors',
        'quality', 'sourcing', 'supply', 'sales', 'info', 'contact'
    ]
    
    def extract_emails(self, html: str) -> List[str]:
        """Extract only role-based emails from public pages"""
        # Find all emails
        email_pattern = r'\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b'
        all_emails = re.findall(email_pattern, html)
        
        # Filter to role-based only
        role_based = []
        for email in all_emails:
            prefix = email.split('@')[0].lower()
            if any(role in prefix for role in self.ROLE_PREFIXES):
                role_based.append(email.lower())
        
        return list(set(role_based))  # Dedupe
    
    def extract_contact_form(self, html: str) -> str:
        """Extract contact form URL"""
        # Look for contact/inquiry forms
        form_patterns = [
            r'<form[^>]*action=["\']([^"\']*contact[^"\']*)["\']',
            r'<form[^>]*action=["\']([^"\']*inquiry[^"\']*)["\']',
            r'<form[^>]*action=["\']([^"\']*supplier[^"\']*)["\']',
        ]
        
        for pattern in form_patterns:
            match = re.search(pattern, html, re.IGNORECASE)
            if match:
                return match.group(1)
        
        return None
```

## Phase 4: Deduplication (Week 4)

### 4.1 Fuzzy Matching

**File: `leadbot/dedupe/fuzzy_matcher.py`**

```python
from jellyfish import jaro_winkler_similarity

class LeadDeduplicator:
    """Deduplicate leads using fuzzy matching"""
    
    SIMILARITY_THRESHOLD = 0.92  # Jaro-Winkler threshold
    
    def normalize_company_name(self, name: str) -> str:
        """Normalize company name for matching"""
        # Remove common suffixes
        suffixes = ['ltd', 'llc', 'inc', 'corp', 'sa', 'sarl', 's.a.r.l']
        normalized = name.lower().strip()
        
        for suffix in suffixes:
            normalized = re.sub(rf'\b{suffix}\b\.?', '', normalized)
        
        # Remove punctuation except spaces
        normalized = re.sub(r'[^\w\s]', '', normalized)
        
        # Normalize whitespace
        normalized = ' '.join(normalized.split())
        
        return normalized
    
    def is_duplicate(self, lead1: dict, lead2: dict) -> bool:
        """Check if two leads are duplicates"""
        # Domain exact match
        if lead1.get('website_root') == lead2.get('website_root'):
            return True
        
        # Fuzzy name match
        name1 = self.normalize_company_name(lead1['company_name'])
        name2 = self.normalize_company_name(lead2['company_name'])
        
        similarity = jaro_winkler_similarity(name1, name2)
        
        return similarity >= self.SIMILARITY_THRESHOLD
    
    def dedupe_leads(self, leads: List[dict]) -> List[dict]:
        """Remove duplicates from lead list"""
        unique = []
        seen_keys = set()
        
        for lead in leads:
            # Create dupe key
            normalized_name = self.normalize_company_name(lead['company_name'])
            domain = lead.get('website_root', '').lower()
            dupe_key = f"{normalized_name}|{domain}"
            
            # Check if seen
            if dupe_key not in seen_keys:
                # Check fuzzy match against existing
                is_dupe = False
                for existing in unique:
                    if self.is_duplicate(lead, existing):
                        # Merge features and keep higher score
                        if lead['lead_score'] > existing['lead_score']:
                            unique.remove(existing)
                            unique.append(lead)
                        is_dupe = True
                        break
                
                if not is_dupe:
                    unique.append(lead)
                    seen_keys.add(dupe_key)
        
        return unique
```

## Phase 5: Review UI (Week 5)

### 5.1 Streamlit Review Interface

**File: `leadbot/ui/streamlit_app.py`**

```python
import streamlit as st
import pandas as pd
from datetime import datetime

st.set_page_config(page_title="Lead Review Dashboard", layout="wide")

# Load today's leads
@st.cache_data
def load_leads():
    date_str = datetime.now().strftime("%Y-%m-%d")
    return pd.read_csv(f"/var/crm/provisional/leads/{date_str}/Provisional_Leads.csv")

leads = load_leads()

# Header
st.title("🎯 Daily Lead Review - Morocco PCBA Discovery")
st.caption(f"**{datetime.now().strftime('%B %d, %Y')}** | {len(leads)} leads")

# Metrics
col1, col2, col3, col4 = st.columns(4)
col1.metric("Total Leads", len(leads))
col2.metric("Recommend", len(leads[leads['recommendation'] == 'approve']))
col3.metric("Review", len(leads[leads['recommendation'] == 'review']))
col4.metric("Auto-Drop", len(leads[leads['recommendation'] == 'drop']))

# Filters
score_filter = st.slider("Minimum Score", 0, 100, 55)
sector_filter = st.multiselect("Sector", options=leads['sector_tags'].unique())

# Filter leads
filtered = leads[leads['lead_score'] >= score_filter]
if sector_filter:
    filtered = filtered[filtered['sector_tags'].isin(sector_filter)]

# Display leads
for idx, lead in filtered.iterrows():
    with st.expander(f"**{lead['company_name']}** - Score: {lead['lead_score']}", expanded=idx<10):
        # Score breakdown
        st.markdown("**Score Breakdown:**")
        breakdown = eval(lead['score_breakdown'])  # JSON string to dict
        
        cols = st.columns(7)
        for i, (key, data) in enumerate(breakdown.items()):
            with cols[i % 7]:
                st.metric(key.title(), data['score'], f"/{data['weight']}")
        
        # Lead details
        col1, col2 = st.columns(2)
        
        with col1:
            st.markdown("**Details:**")
            st.write(f"📍 Location: {lead['site_location']}")
            st.write(f"🏭 Sector: {lead['sector_tags']}")
            st.write(f"🌐 Website: [{lead['website_root']}]({lead['website_root']})")
            
            if lead['supplier_portal_url']:
                st.write(f"🔗 [Supplier Portal]({lead['supplier_portal_url']})")
            if lead['rfq_rfp_page_url']:
                st.write(f"📋 [RFQ Page]({lead['rfq_rfp_page_url']})")
        
        with col2:
            st.markdown("**Contact Info:**")
            if lead['contact_emails_public']:
                st.write(f"✉️ Emails: {lead['contact_emails_public']}")
            if lead['contact_form_url']:
                st.write(f"📝 [Contact Form]({lead['contact_form_url']})")
            
            st.markdown("**Signals:**")
            st.caption(lead['fit_signals'])
        
        # Auto-generated notes
        st.info(lead['notes_auto'])
        
        # Action buttons
        col1, col2, col3 = st.columns([1,1,4])
        
        with col1:
            if st.button("✅ Approve", key=f"approve_{idx}"):
                # TODO: Call CRM sync API
                st.success("Approved! Creating CRM record...")
        
        with col2:
            deny_reason = st.selectbox(
                "Deny", 
                ["", "Not a fit", "No Morocco link", "Service mismatch", "Duplicate", "Other"],
                key=f"deny_{idx}"
            )
            if deny_reason:
                # TODO: Log denial
                st.warning(f"Denied: {deny_reason}")

# Summary stats
st.sidebar.header("Performance Metrics")
st.sidebar.metric("Precision @ Top-50", "TBD")
st.sidebar.metric("Avg Review Time", "TBD")
st.sidebar.metric("Approval Rate", "TBD")
```

## Phase 6: Daily Scheduling (Week 6)

### 6.1 Airflow DAG

**File: `leadbot/jobs/airflow_dag.py`**

```python
from airflow import DAG
from airflow.operators.python import PythonOperator
from airflow.providers.postgres.operators.postgres import PostgresOperator
from datetime import datetime, timedelta
import pytz

# Morocco time zone
MOROCCO_TZ = pytz.timezone('Africa/Tunis')

default_args = {
    'owner': 'crm-team',
    'depends_on_past': False,
    'email': ['crm@starz-morocco.com', 'sales-ops@starz-morocco.com'],
    'email_on_failure': True,
    'email_on_retry': False,
    'retries': 2,
    'retry_delay': timedelta(minutes=5),
}

dag = DAG(
    'morocco_lead_discovery',
    default_args=default_args,
    description='Daily Morocco PCBA lead discovery',
    schedule_interval='0 8 * * *',  # 08:00 Africa/Tunis
    start_date=datetime(2025, 10, 28, tzinfo=MOROCCO_TZ),
    catchup=False,
    tags=['leads', 'morocco', 'discovery'],
)

# Task 1: Build crawl frontier
frontier_task = PythonOperator(
    task_id='build_frontier',
    python_callable=build_crawl_frontier,
    dag=dag,
)

# Task 2: Run crawler
crawl_task = PythonOperator(
    task_id='run_crawler',
    python_callable=run_scrapy_crawler,
    dag=dag,
)

# Task 3: Extract & score
extract_task = PythonOperator(
    task_id='extract_and_score',
    python_callable=extract_and_score_leads,
    dag=dag,
)

# Task 4: Deduplicate
dedupe_task = PythonOperator(
    task_id='deduplicate',
    python_callable=deduplicate_leads,
    dag=dag,
)

# Task 5: Enrich (optional)
enrich_task = PythonOperator(
    task_id='enrich_leads',
    python_callable=enrich_with_apis,
    dag=dag,
)

# Task 6: Generate output files
output_task = PythonOperator(
    task_id='generate_outputs',
    python_callable=generate_csv_xlsx,
    dag=dag,
)

# Task 7: Send email notification
notify_task = PythonOperator(
    task_id='send_notification',
    python_callable=send_email_summary,
    dag=dag,
)

# Task dependencies
frontier_task >> crawl_task >> extract_task >> dedupe_task >> enrich_task >> output_task >> notify_task
```

## Phase 7: CRM Integration (Week 7)

### 7.1 Symfony CRM Sync Service

**File: `src/Service/WebCrawler/CRMSyncService.php`**

```php
<?php

namespace App\Service\WebCrawler;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Activity;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class CRMSyncService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CompanyRepository $companyRepo,
        private LoggerInterface $logger
    ) {}

    /**
     * Sync approved lead to CRM (idempotent)
     */
    public function syncApprovedLead(array $lead): Company
    {
        // Check if exists by domain
        $company = $this->companyRepo->findOneBy([
            'website' => $lead['website_root']
        ]);

        $isNew = false;
        if (!$company) {
            $company = new Company();
            $isNew = true;
        }

        // Map lead data to Company entity
        $company->setName($lead['company_name']);
        $company->setLegalName($lead['legal_name'] ?? null);
        $company->setWebsite($lead['website_root']);
        $company->setSector($lead['sector_tags'][0] ?? 'Industrial');
        $company->setPhysicalSite($lead['site_location'] ?? null);
        $company->setPipelineStage('Prospect');
        $company->setAccountTier('C'); // Default
        
        // Tag as leadbot-discovered
        $company->setSourceNotes(
            "Auto-discovered by Leadbot on " . date('Y-m-d') .
            "\nScore: {$lead['lead_score']}" .
            "\nCampaign: MoroccoFZ-2025Q4" .
            "\nBatch: " . date('Y-m-d') .
            "\n\nSignals: " . implode(', ', $lead['fit_signals'])
        );

        $this->em->persist($company);
        $this->em->flush();

        // Create placeholder contact if role-based email exists
        if (!empty($lead['contact_emails_public'])) {
            $this->createContactPlaceholder($company, $lead);
        }

        // Create follow-up task
        $this->createFollowUpTask($company, $lead);

        $this->logger->info("CRM sync completed", [
            'company_id' => $company->getId(),
            'company_name' => $company->getName(),
            'is_new' => $isNew
        ]);

        return $company;
    }

    private function createContactPlaceholder(Company $company, array $lead): void
    {
        // Create role-based contact
        $contact = new Contact();
        $contact->setCompany($company);
        $contact->setEmail($lead['contact_emails_public'][0]);
        $contact->setTitle('Procurement (Role-based)');
        $contact->setSource('Leadbot Auto-Discovery');
        $contact->setVerified(false);
        
        $this->em->persist($contact);
    }

    private function createFollowUpTask(Company $company, array $lead): void
    {
        // Create activity/task
        $activity = new Activity();
        $activity->setCompany($company);
        $activity->setActivityType('Task');
        $activity->setSubject('Find buyer/SQE contact');
        $activity->setDescription(
            "Lead Score: {$lead['lead_score']}\n" .
            "Supplier Portal: {$lead['supplier_portal_url']}\n" .
            "Next steps:\n" .
            "1. Verify company relevance\n" .
            "2. Find procurement/quality contact\n" .
            "3. Submit compliance pack"
        );
        $activity->setActivityDate(new \DateTime('+2 days'));
        
        $this->em->persist($activity);
    }
}
```

## Compliance & Privacy Checklist

- [ ] Respect robots.txt on all domains
- [ ] Rate limit to 0.2-0.5 RPS per domain
- [ ] No scraping of login-gated content
- [ ] No automated LinkedIn scraping (use official APIs)
- [ ] Only collect role-based or publicly listed emails
- [ ] Business data only (no personal phone numbers)
- [ ] Encrypt data at rest
- [ ] Maintain Do-Not-Crawl list
- [ ] Honor removal requests within 48 hours
- [ ] Log all decisions for audit trail
- [ ] GDPR-compliant data retention (90 days)

## Monitoring & Quality Assurance

### Daily Metrics to Track

```sql
-- Precision @ Top-50
SELECT 
    COUNT(CASE WHEN review_status = 'approved' THEN 1 END) * 100.0 / 50 as precision
FROM leads
WHERE batch_date = CURRENT_DATE
  AND lead_rank <= 50;

-- Approval Rate
SELECT 
    COUNT(CASE WHEN review_status = 'approved' THEN 1 END) * 100.0 / COUNT(*) as approval_rate
FROM leads
WHERE batch_date >= CURRENT_DATE - INTERVAL '7 days';

-- Median Review Time
SELECT PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY review_time_seconds) as median_time
FROM lead_reviews
WHERE review_date >= CURRENT_DATE - INTERVAL '7 days';
```

### Weekly Quality Audit

1. Random sample 10 leads
2. Manual verification of relevance
3. Check for false positives
4. Review denied leads
5. Adjust weights/keywords as needed

## Next Steps

1. **Week 1-2**: Set up Scrapy infrastructure + config
2. **Week 3-4**: Implement parsers + scoring engine
3. **Week 5**: Build review UI (Streamlit)
4. **Week 6**: Set up daily scheduler (Airflow/cron)
5. **Week 7**: Integrate with Symfony CRM
6. **Week 8**: Testing & QA
7. **Week 9**: Pilot run (Sales Ops review)
8. **Week 10**: Production launch

## Support & Resources

- **Config**: `config/crawler_config.yaml`
- **Logs**: `/var/crm/logs/crawler.log`
- **Output**: `/var/crm/provisional/leads/YYYY-MM-DD/`
- **Review UI**: `http://localhost:8501` (Streamlit)
- **Docs**: This file + Crawler Reqs.txt

Contact: support@starz-morocco.com
