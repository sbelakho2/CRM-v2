git clone <repository-url>
# Starz Morocco CRM

**Release**: V1 (Smart Ops Bundle)  
**Status**: ✅ Ready for production once SecOps rotates supplier API credentials (ticket SEC-1432)  
**Last Updated**: October 30, 2025

---

## Overview

Starz Morocco CRM centralises sales operations for the PCBA/EMS market with an emphasis on rapid quoting, proactive engagement, and mobile-first collaboration.

### Feature Highlights

- � **Smart Notification Center** – Real-time events, unread badges, cron + CLI orchestration
- ⚡ **Mobile Quick Actions** – Floating action button, keyboard shortcuts, device-aware menus
- � **Secure Authentication** – Session hardening, remember-me rotation, audit-ready logging
- �️ **Engagement Heat Map** – Visitor telemetry, hourly refresh jobs, hover insights
- 🤖 **Quote Co-Pilot** – Supplier waterfall (Nexar, Mouser, Digi-Key), BOM diffing, PDF exports

Legacy modules (companies, contacts, RFQ pipeline, compliance docs, webinars, dashboards) remain fully supported.

### Technology Stack

- **Framework**: Symfony 7.x
- **Language**: PHP 8.2+
- **Database**: MySQL or PostgreSQL (SQLite for local development)
- **Frontend**: Twig, Stimulus controllers, Tailwind utility styling
- **Tooling**: Webpack Encore, Symfony Messenger (async jobs optional)

---

## Documentation Map

- **📇 Master Index**: `Documentation/INDEX.md`
- **📘 System Overview**: `Documentation/01-Core/SYSTEM_OVERVIEW.md`
- **🚀 Quick Start**: `Documentation/01-Core/QUICKSTART.md`
- **🛠️ Deployment Suite**: `Documentation/07-Deployment-Operations/`
- **🧪 QA Evidence**: `Documentation/10-Quality-Assurance/`
- **📣 Executive Comms**: `Documentation/09-Project-Status/`

Priority reads for release:

1. `Documentation/07-Deployment-Operations/PRODUCTION_DEPLOYMENT_GUIDE.md`
2. `Documentation/07-Deployment-Operations/DEPLOYMENT_CHECKLIST.md`
3. `Documentation/10-Quality-Assurance/FINAL_TEST_SUMMARY.md`

---

## Testing Snapshot

- 42 automated PHPUnit tests + 6 Cypress smoke journeys
- 58 manual scripts across notifications, quick actions, auth, heat map, quote automation
- Performance: notifications API p95 82 ms; quote supplier waterfall p95 1.1 s; heat-map refresh job <4 s
- Security verification: CSRF, remember-me rotation, CSP headers, logout invalidation
- Full evidence archived in `Documentation/10-Quality-Assurance/TEST_EXECUTION_REPORT.md`

---

## Quick Start

### Local Developer Setup

```powershell
git clone <repository-url>
cd crm-starz-morocco

composer install

Copy-Item .env .env.local
# Update DATABASE_URL and MAILER_DSN inside .env.local

php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

npm install
npm run dev

symfony server:start -d
```

Open `https://127.0.0.1:8000` (Symfony server provides HTTPS by default) or use `http://127.0.0.1:8000` if running with built-in PHP server.

Background jobs for notifications and heat map:

```powershell
php bin/console app:check-notifications --user=1
php bin/console app:heatmap:refresh --env=dev
```

### Staging/Production Deployment

Follow `Documentation/07-Deployment-Operations/PRODUCTION_DEPLOYMENT_GUIDE.md` for end-to-end provisioning, secrets, cron, monitoring, and post-deploy verification.

---

## Project Structure

```
crm-starz-morocco/
├── Documentation/                 # Release docs, deployment runbooks, QA evidence
├── config/                        # Symfony configuration (packages, routes, messenger)
├── src/                           # Application source code
│   ├── Controller/                # HTTP controllers including notifications & quick actions
│   ├── Entity/                    # Doctrine entities (notifications, heat map, quote cache, …)
│   ├── Service/                   # Business logic (QuoteCoPilotService, NotificationService)
│   └── Command/                   # CLI commands (check-notifications, heatmap refresh, imports)
├── assets/                        # Stimulus controllers, Tailwind entrypoints
├── public/                        # Front controller and built assets
├── templates/                     # Twig views (FAB, heat map, dashboards)
├── var/                           # Cache, logs, file storage
└── webpack.config.js              # Webpack Encore configuration
```

---

## Operational Modules

- **Smart Notification Center** (`/notifications` API): Bell badge, modal, bulk mark-as-read, cron-driven refresh
- **Mobile Quick Actions** (persistent FAB): Context-aware shortcuts for create company/contact/RFQ, toggle heat map, launch Quote Co-Pilot
- **Quote Co-Pilot** (`/quotes/copilot`): BOM upload, supplier ranking, PDF export with VichUploader
- **Engagement Heat Map** (`/engagement/heat-map`): Aggregated visitor telemetry, drill-down filters, scheduled data refresh
- **Authentication Hardening**: Session TTL, remember-me rotation, audit log viewer (`/admin/security/audit`)

Legacy CRM flows (companies, contacts, RFQ, compliance, webinars, dashboards) remain unchanged from prior release.

---

## Data & Integrations

- **Primary DB**: MySQL 8 / PostgreSQL 15 (Doctrine migrations up to `Version20251030120000`)
- **Supplier APIs**: Nexar, Mouser, Digi-Key (credentials managed via Azure Key Vault; rotate before go-live)
- **Telemetry**: Heat map ingests `engagement_event` table via cron job `app:heatmap:refresh`
- **Notifications**: CLI `app:check-notifications` scheduled every 5 minutes
- **Assets**: Encore build pipeline (`npm run build` for production)

---

## Security & Compliance

- HTTPS enforced (HSTS recommended)
- CSRF, XSS, SQL injection safeguards via Symfony defaults
- Login throttling, remember-me token invalidation, audit logging
- GDPR compliance: consent logging, unsubscribe via email footer (Quote Co-Pilot communications reuse existing marketing stack)

---

## Support

- **Incident Response**: Contact DevOps rotation; see `Documentation/09-Project-Status/SYSTEM_RUNNING.md`
- **User Enablement**: `Documentation/08-User-Guides/NEW_USER_GUIDE.md`
- **QA & Regression**: `Documentation/10-Quality-Assurance/TESTING_INDEX.md`
- **Deployment History**: `Documentation/07-Deployment-Operations/PRODUCTION_DEPLOYMENT_REPORT.md`

Logs live under `var/log/` (per environment). Server logs remain in Apache/Nginx directories.

---

## Next Steps

1. Await SecOps confirmation on supplier credential rotation (SEC-1432)
2. Execute deployment checklist and capture transcript in `Documentation/07-Deployment-Operations/DEPLOYMENT_READY.md`
3. Run post-deploy smoke suite and log results in `Documentation/10-Quality-Assurance/TEST_EXECUTION_REPORT.md`

---

© 2025 Starz Morocco. All rights reserved.
