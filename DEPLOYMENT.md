# STARZ Morocco CRM – Deployment Overview

This document summarizes the deployment flow for the SMART OPS Bundle release. Use it beside the detailed guides found under `Documentation/`.

## 1. Prerequisites
- PHP 8.4 with required extensions (`pdo_mysql`, `intl`, `mbstring`, `xml`, `curl`, `zip`, `gd`)
- Composer 2.x
- Node.js 20.x (only if rebuilding frontend assets)
- MySQL 8.0+ (or PostgreSQL equivalent)
- Supervisor or systemd for queue/cron workers
- A reverse proxy (Nginx or Apache with HTTPS)

## 2. High-Level Steps
1. **Extract** the distribution ZIP into `/var/www/crm-starz-morocco`.
2. **Copy** `.env.example` to `.env.local` and update credentials.
3. **Install** PHP dependencies using `composer install --no-dev --optimize-autoloader`.
4. **Warm up** cache and assets:
   ```bash
   php bin/console cache:clear --env=prod
   php bin/console cache:warmup --env=prod
   php bin/console assets:install --symlink --relative public
   ```
5. **Run migrations** and seed baseline data:
   ```bash
   php bin/console doctrine:database:create --if-not-exists --env=prod
   php bin/console doctrine:migrations:migrate --no-interaction --env=prod
   ```
6. **Configure web server** using the vhost templates located in `Documentation/07-Deployment-Operations/`.
7. **Schedule jobs** (cron or task scheduler) using `bin/console` commands described in the notification and email automation guides.

## 3. Post-Deployment Checklist
- [ ] HTTPS enforced and certificates validated
- [ ] Application login verified with production accounts
- [ ] Email sending confirmed (use SMTP smoke test)
- [ ] Background workers started (`CheckNotificationsCommand`, drip campaigns)
- [ ] Monitoring and backups enabled

For the full 28-step checklist, open `Documentation/07-Deployment-Operations/PRODUCTION_DEPLOYMENT_GUIDE.md`.
