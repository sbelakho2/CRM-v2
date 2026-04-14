# STARZ Morocco CRM – Deployment Overview

This document summarizes the deployment flow for the SMART OPS Bundle release. Use it beside the detailed guides found under `Documentation/`.

## 0. Verified Production Access
- Preferred SSH path verified on April 14, 2026: `ssh -i ~/.ssh/hetzner-db-mac -o IdentitiesOnly=yes root@77.42.65.89`
- Optional local SSH alias: `ssh hetzner-apexintel`
- If `ubuntu@77.42.65.89` fails with `Permission denied (publickey,password)`, use the verified root path above.

## 1. Prerequisites
- PHP 8.4 with required extensions (`pdo_mysql`, `intl`, `mbstring`, `xml`, `curl`, `zip`, `gd`)
- Composer 2.x
- Node.js 20.x (only if rebuilding frontend assets)
- MySQL 8.0+ (or PostgreSQL equivalent)
- Supervisor or systemd for queue/cron workers
- A reverse proxy (Nginx or Apache with HTTPS)

## 2. High-Level Steps
1. **Extract** the distribution ZIP into `/var/www/starzcrm` for full releases.
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
8. **Use the targeted hotfix path** below instead of a full redeploy when only Twig templates, translations, or static files under `public/` changed.

## 3. Targeted Hotfix Path
Use this path for low-risk content changes only:
- Twig templates under `templates/`
- Translation JSON files under `translations/`
- Public static files under `public/`

Do not use this path when the release changes migrations, Composer dependencies, Node dependencies, Webpack config, environment files, or anything that requires rebuilding `vendor/` or `public/build/`.

Hotfix flow:
1. Package only the changed files into a small tarball from the local machine.
2. Upload that tarball to `/tmp/` on the VPS using the verified root SSH path.
3. Back up the live copies of those files on the server.
4. Extract the tarball in place under `/var/www/starzcrm`, fix ownership, and clear/warm the Symfony prod cache.
5. Verify `/login` and any affected public URLs immediately after the patch.

For exact commands, use section `0.11A` in `Documentation/07-Deployment-Operations/PRODUCTION_DEPLOYMENT_GUIDE.md`.

## 4. Post-Deployment Checklist
- [ ] HTTPS enforced and certificates validated
- [ ] Application login verified with production accounts
- [ ] Email sending confirmed (use SMTP smoke test)
- [ ] Background workers started (`CheckNotificationsCommand`, drip campaigns)
- [ ] Monitoring and backups enabled

For the full 28-step checklist, open `Documentation/07-Deployment-Operations/PRODUCTION_DEPLOYMENT_GUIDE.md`.
