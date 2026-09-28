# STARZ Morocco CRM – Deployment Overview

This document summarizes the deployment flow for the SMART OPS Bundle release. Use it beside the detailed guides found under `Documentation/`.

## 0. Production Access (managed outside this repository)
- Host, deploy user, SSH key and alias are provisioned through the private ops
  secret store; they must never be committed here.
- Required variables (provided by the ops secret store at deploy time):
  - `PRODUCTION_HOST` — VPS address
  - `PRODUCTION_USER` — dedicated non-root deploy account
  - `SSH_KEY` — path to the deploy private key
- Deployments connect with:
  `ssh -i "$SSH_KEY" -o IdentitiesOnly=yes "$PRODUCTION_USER@$PRODUCTION_HOST"`
- Direct root logins for deployment are prohibited; use the dedicated deploy
  account and escalate only for targeted operational tasks.
- If any credential, host address or private key was previously committed to
  this repository, treat it as disclosed: rotate it rather than relying on
  Git history cleanup.

## 1. Prerequisites
- PHP >= 8.2 with required extensions (`pdo_mysql`, `intl`, `mbstring`, `xml`, `curl`, `zip`, `gd`)
- Composer 2.x
- MySQL 8.0+ (the application targets MySQL; no frontend build step is required — Encore/Webpack were removed)
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

Do not use this path when the release changes migrations, Composer dependencies, environment files, or anything that requires rebuilding `vendor/`.

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
