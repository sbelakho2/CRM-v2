# Quick Start – StarzCRM Release V1

**Audience**: Developers, QA, product owners validating the Smart Ops bundle locally  
**Last Updated**: October 30, 2025

---

## 0. Prerequisites

- PHP 8.2+ with `intl`, `mbstring`, `pdo_mysql` or `pdo_pgsql`
- Composer 2.x
- Node.js 18+ and npm 9+
- Symfony CLI (for HTTPS dev server) – optional but recommended
- MySQL 8 / PostgreSQL 15 (or SQLite for quick trials)

Check versions:

```powershell
php -v
composer --version
npm --version
```

---

## 1. Clone & Bootstrap

```powershell
cd "C:\Users\sadok\CRM Project"
git clone <repository-url> crm-starz-morocco
cd crm-starz-morocco

composer install
npm install

Copy-Item .env .env.local
notepad .env.local   # update DATABASE_URL, MAILER_DSN, APP_SECRET
```

Generate a secret if needed:

```powershell
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

---

## 2. Database & Seeds

```powershell
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# Optional demo content (Tracker extract)
php bin/console app:import-tracker "..\Tracker.csv"

# Create a local admin
php bin/console app:create-user --email=admin@local.test --role=ROLE_ADMIN
```

SQLite shortcut:

```powershell
php bin/console doctrine:database:create --if-not-exists --env=dev
php bin/console doctrine:migrations:migrate --env=dev
```

---

## 3. Start Services

> **Correction (2026-08):** The frontend build toolchain (Webpack Encore/Tailwind) was removed — there is no `npm install` / `npm run dev` / `npm run build` step. Twig templates render directly.

```powershell
# Start Symfony dev server (HTTPS) in background
symfony server:start -d

# Alternative: built-in PHP server
php -S 127.0.0.1:8000 -t public
```

Background jobs (run in separate PowerShell tabs):

```powershell
# Smart notifications (every 5 minutes in production; manual run locally)
php bin/console app:check-notifications --user=1

# Engagement heat map refresh
php bin/console app:heatmap:refresh

# Quote supplier cache warm-up (optional)
php bin/console app:quote:refresh-suppliers
```

---

## 4. Feature Smoke Checklist

1. **Login & Authentication Hardening**
   - Visit `https://127.0.0.1:8000/login`
   - Confirm remember-me cookie generates & rotates on logout/login
   - Check audit log at `/admin/security/audit`

2. **Smart Notification Center**
   - Run `php bin/console app:check-notifications` to populate seed events
   - Visit dashboard; ensure badge count updates without refresh (Stimulus polling)
   - Mark-all-as-read and verify API `/api/notifications/count`

3. **Mobile Quick Actions**
   - Toggle browser mobile emulation → floating action button adapts
   - Keyboard shortcut `Shift + Q` opens menu on desktop
   - Quick create company/contact flows launch modals

4. **Engagement Heat Map**
   - Execute `php bin/console app:heatmap:refresh`
   - Open `/engagement/heat-map`; hover tooltips show latest telemetry
   - Filters (date range, geography) respond instantly

5. **Quote Co-Pilot**
   - Navigate to `/quotes/copilot`
   - Upload sample BOM (see `docs/samples/bom-demo.xlsx` if available)
   - Confirm supplier waterfall (Nexar, Mouser, Digi-Key) renders and PDF export works

---

## 5. Useful Commands

```powershell
php bin/console cache:clear
php bin/console messenger:consume notifications --limit=10
php bin/console debug:router | Select-String notifications
php bin/console list app
```

---

## 6. Integration Notes

- **Supplier Credentials**: stored in environment vars `NEXAR_API_KEY`, `MOUSER_API_KEY`, `DIGIKEY_CLIENT_ID/SECRET`. Use `.env.local` for local dev.
- **Email Delivery**: configure `MAILER_DSN`; local testing can use `smtp://localhost:1025` (Mailpit via `docker compose up -d`).
- **Cron Emulation**: use Windows Task Scheduler to run `php bin/console app:check-notifications` every 5 minutes and `app:heatmap:refresh` hourly.
- **Analytics**: follow `Documentation/07-Deployment-Operations/ANALYTICS_AND_TRACKING_SYSADMIN_GUIDE.md` if validating Matomo/Grafana dashboards locally.

---

## 7. Troubleshooting

- **Symfony server fails to start**: run `symfony server:stop` then retry; ensure port 8000 free.
- **Database errors**: check DSN inside `.env.local`; rerun migrations.
- **Missing assets**: no build step exists (Encore/Tailwind removed) — templates render directly; clear cache `php bin/console cache:clear`.
- **Supplier API timeouts**: set mock mode `QUOTE_COPILOT_MOCK=1` in `.env.local` for deterministic outputs.
- **Notifications not appearing**: ensure user has related entities; run CLI with `--force-demo` flag if available (see command help).

Logs live under `var/log/dev.log`; tail them via PowerShell:

```powershell
Get-Content var/log/dev.log -Wait
```

---

## 8. Next Steps

1. Complete regression sweeps via `Documentation/10-Quality-Assurance/TESTING_INDEX.md`
2. Review deployment checklist (`Documentation/07-Deployment-Operations/DEPLOYMENT_CHECKLIST.md`)
3. Coordinate with SecOps for supplier credential rotation before staging promotion

Need a deeper dive? Start with `Documentation/INDEX.md` for the full documentation map.
