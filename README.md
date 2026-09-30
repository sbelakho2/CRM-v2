# Starz Morocco CRM v2

**Status**: ✅ Live in production (https://www.starzcrm.com)  
**Last Updated**: August 24, 2026

---

## Overview

Enterprise CRM platform for PCBA/EMS market with integrated Account-Based Marketing (ABM), marketing automation, and intelligent quote generation.

### Implemented Features ✅

#### Core CRM
- 📊 **Dashboard** – Real-time KPIs, charts, activity feeds
- 🏢 **Companies & Contacts** – Full relationship management
- 💰 **RFQ Pipeline** – Quote requests, status tracking
- 📧 **Email Campaigns** – Scheduling, templates, delivery tracking
- 🗓️ **Meeting Scheduler** – Public booking/cancellation via signed token links (no login required)

#### Security
- 🔐 Login throttling, remember-me, full role hierarchy
- 🔑 Signed public booking tokens; CSRF-protected state-changing routes
- 🛡️ Hardened uploads (mime/extension whitelist), URL-length guard in duplicate detection

#### ABM & Marketing Automation
- 🎯 **Visitor Intelligence** – IP-to-company resolution, engagement tracking
- 🤖 **Playbook Engine** – Automated triggers & actions (create_activity, send_email, update_score)
- 📈 **ABM Dashboard** – Recent hits, top accounts, engagement metrics
- 🕷️ **Web Crawler** – Lead generation, NPI tracking

#### Quote Co-Pilot
- 📋 **BOM Processing** – CSV/Excel upload with smart parsing
- 💲 **Multi-API Pricing** – Mouser → DigiKey → Nexar waterfall
- ⚡ **Auto-Pricing** – Price breaks, lead times, coverage calculation

### Technology Stack

- **Framework**: Symfony 7.4.15
- **Language**: PHP >= 8.2 (dev machine: 8.5)
- **Database**: MySQL 8 (port 3308)
- **Frontend**: Twig, Chart.js
- **Search**: Self-hosted SearXNG (port 8888, primary) with Google CSE fallback
- **APIs**: Mouser, DigiKey, Nexar (component pricing)
- **Async Processing**: Symfony Messenger (ready)

---

## Quick Start

### Local Setup

```powershell
# Install dependencies
composer install

# Configure environment
Copy-Item .env .env.local
# Edit .env.local with your DATABASE_URL and API credentials

# Setup database (development: empty databases, plain migrate is fine)
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# PRODUCTION databases: use the safe wrapper instead (preflight + legacy
# compliance preservation + post-migration verification):
#   php bin/console app:migrations:safe-migrate --env=prod

# Start development server
symfony server:start
# OR
php -S 127.0.0.1:8000 -t public
```

Open http://127.0.0.1:8000

### Default Credentials

Create your first user via console:
```powershell
php bin/console app:create-user admin@starz.ma --admin
```

---

## Project Structure

```
crm-c2/
├── Documentation/           # Organized documentation by topic
│   ├── 01-Core/            # System overview, architecture
│   ├── 02-Email-Campaigns/ # Email campaign guides
│   ├── 03-LeadBot-Webcrawler/
│   ├── 04-ABM-Automation/  # ABM & playbook docs
│   └── 08-User-Guides/     # End-user documentation
├── config/                 # Symfony configuration
├── src/
│   ├── Controller/         # HTTP controllers
│   ├── Entity/            # Doctrine entities (66)
│   ├── Service/           # Business logic
│   │   ├── AbmResolverService.php    # IP resolution
│   │   ├── PlaybookEngine.php        # Marketing automation
│   │   ├── PricingEngine.php         # Quote pricing
│   │   └── EmailSchedulerService.php # Email delivery
│   ├── Repository/        # Data access layer
│   └── Command/          # CLI commands
├── templates/            # Twig templates
├── migrations/          # Database migrations
└── public/             # Web root
```

---

## Core Services

### AbmResolverService
- IP-to-company resolution with DNS lookup
- ISP filtering (excludes residential IPs)
- Engagement scoring (0-100)
- Hit recording and analytics

### PlaybookEngine
- Trigger evaluation (JSON rules)
- Condition operators: =, !=, >, <, >=, <=, contains, in, not_in, regex
- Actions: create_activity, send_email, update_score
- Execution history and audit trail

### PricingEngine
- Multi-API waterfall (Mouser → DigiKey → Nexar)
- Price break calculation
- Lead time estimation
- Coverage percentage tracking

### EmailSchedulerService
- Timezone-aware scheduling
- Rate limiting
- Retry logic with exponential backoff
- Campaign progress tracking

---

## Database Schema

66 entities including:
- **Core**: Company, Contact, User, Activity
- **Sales**: Quote, RFQ, BomLine, QuoteLineItem
- **Marketing**: EmailCampaign, EmailTemplate, CampaignRecipient
- **ABM**: AbmAccount, AbmHit, IpMap, Playbook, PlaybookRun
- **Leads**: Lead, WebCrawlerSession, NpiAward

---

## API Integrations

### Component Pricing APIs
- **Mouser**: Primary pricing source
- **DigiKey**: Fallback pricing
- **Nexar**: Final fallback + BOM intelligence

### Configuration
Set in `.env.local`:
```env
MOUSER_API_KEY=your_key
DIGIKEY_CLIENT_ID=your_id
DIGIKEY_CLIENT_SECRET=your_secret
NEXAR_CLIENT_ID=your_id
NEXAR_CLIENT_SECRET=your_secret
```

---

## Testing

```powershell
# Run PHPUnit tests
php bin/phpunit

# Test BOM pricing
php bin/console app:test-bom-pricing sample_bom.csv

# Manually test pages
@("/", "/companies", "/contacts", "/email-campaigns", "/playbooks", "/webcrawler", "/abm-dashboard") | ForEach-Object {
    Invoke-WebRequest -Uri "http://127.0.0.1:8000$_" -UseBasicParsing
}
```

---

## Documentation

- **📇 Index**: `Documentation/INDEX.md`
- **🚀 Quick Start**: `Documentation/QUICKSTART.md`
- **📘 System Overview**: `Documentation/SYSTEM_OVERVIEW.md`
- **🎯 ABM Guide**: `Documentation/04-ABM-Automation/`
- **📧 Email Campaigns**: `Documentation/02-Email-Campaigns/`
- **🕷️ Web Crawler**: `Documentation/WEBCRAWLER_README.md`

---

## Development Status

### ✅ Completed (65-70%)
- Core CRM (companies, contacts, RFQ)
- Dashboard with KPIs
- Email campaigns with scheduling
- ABM visitor tracking pipeline
- IP resolution & company identification
- Playbook automation engine
- Quote Co-Pilot with multi-API pricing
- Web crawler for lead generation

### 🚧 In Progress (30%)
- Supplier portal automation
- Advanced freight pricing
- Additional reporting dashboards
- Performance optimization
- Extended test coverage

---

## Known Issues

None critical. System is production-ready for core CRM + ABM functionality.

---

## Support

For documentation questions, see `Documentation/FAQ.md`

---

© 2025 Starz Morocco. All rights reserved.
