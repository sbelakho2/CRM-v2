# StarzCRM – Production Deployment Guide

**Audience**: DevOps, SecOps, Release Manager  
**Revision**: April 14, 2026  
**Status**: ✅ LIVE in Production

---

## 0. Live Production Environment

> **Status:** ✅ LIVE  
> **Domain:** https://www.starzcrm.com  
> **Last verified:** April 14, 2026

---

### 0.1 Server Access

| Item | Value |
|------|-------|
| **Domain** | [https://www.starzcrm.com](https://www.starzcrm.com) |
| **VPS Provider** | Hetzner |
| **VPS IPv4** | `$PRODUCTION_HOST` |
| **VPS IPv6 Gateway** | `2a01:4f9:c012:a8e::/64` |
| **OS** | Ubuntu 24.04.3 LTS (Noble Numbat), kernel 6.8.0-90 |
| **Hostname** | `StarzMain` |
| **Resources** | 16 vCPU · 30 GB RAM · 150 GB SSD |
| **SSH User** | `root` (verified from local machine); `ubuntu` may still work when the key is authorized for that account |

### 0.2 SSH Key Setup

The VPS authenticates with an **Ed25519 SSH key** stored on the local development machine.

| Item | Value |
|------|-------|
| **Private key** | `$SSH_KEY` (local machine) |
| **Public key** | `$SSH_KEY.pub` |

**Preferred connect command (verified April 14, 2026):**
```bash
ssh -i $SSH_KEY -o IdentitiesOnly=yes root@$PRODUCTION_HOST
```

**SSH config shortcut (if present locally):**
```bash
ssh $PRODUCTION_SSH_ALIAS
```

**Alternate sudo-user path (legacy):**
```bash
ssh -i $SSH_KEY -o IdentitiesOnly=yes ubuntu@$PRODUCTION_HOST
```

> **Note:** Root access is the currently verified path from the main development machine. If the `ubuntu` login returns `Permission denied (publickey,password)`, use `root` or the `$PRODUCTION_SSH_ALIAS` alias.

**To add this key to a new machine:**
1. Copy `$SSH_KEY` and `$SSH_KEY.pub` to the new machine's `~/.ssh/` directory.
2. Set permissions: `chmod 600 $SSH_KEY && chmod 644 $SSH_KEY.pub`
3. Connect: `ssh -i $SSH_KEY -o IdentitiesOnly=yes root@$PRODUCTION_HOST`

---

### 0.3 Application Stack (Installed Versions)

| Component | Version | Config / Notes |
|-----------|---------|----------------|
| **PHP** | 8.4.18 (FPM) | Socket: `/run/php/php8.4-fpm.sock` |
| **Nginx** | 1.24.0 | Config: `/etc/nginx/sites-available/starzcrm` |
| **MySQL** | 8.0.45 | Local socket, managed by systemd |
| **Composer** | 2.x | `/usr/local/bin/composer` |
| **Certbot** | Installed | SSL auto-renewal via systemd timer |

> **Note (2026-08):** Node.js/Webpack Encore are no longer part of the stack — the frontend build toolchain (Encore/Tailwind/webpack.config.js) was removed. There is no `npm install` / `npm run build` step.

**Systemd service status (all `active`):**
```
nginx          → active
php8.4-fpm     → active
mysql          → active
```

---

### 0.4 Database

| Item | Value |
|------|-------|
| **Engine** | MySQL 8.0.45 |
| **Database name** | `starz_crm` |
| **User** | `crm_user` |
| **Password** | `<REDACTED-SET-YOUR-OWN>` |
| **Host** | `127.0.0.1:3306` |
| **DSN** | `mysql://crm_user:<REDACTED-SET-YOUR-OWN>@127.0.0.1:3306/starz_crm?serverVersion=8.0&charset=utf8mb4` |
| **Tables** | 77 tables (created via Doctrine migrations) |

**Access MySQL on server:**
```bash
ssh -i $SSH_KEY -o IdentitiesOnly=yes root@$PRODUCTION_HOST
sudo mysql starz_crm
# or with credentials (you will be prompted for the password):
mysql -u crm_user -p starz_crm
```

---

### 0.5 VPS File System Layout

```
/var/www/starzcrm/                    ← Application root (owner: www-data:www-data)
├── .env                              ← Base environment (committed, non-secret)
├── .env.local                        ← Production overrides (NOT committed, secrets here)
├── bin/
│   └── console                       ← Symfony CLI (chmod +x)
├── config/
│   ├── packages/                     ← Symfony bundle configs
│   ├── routes/                       ← Route definitions
│   ├── rules/                        ← WebCrawler rule packs
│   ├── bundles.php
│   ├── services.yaml
│   └── routes.yaml
├── external_data/                    ← Classifier data, company boost lists
├── migrations/                       ← Doctrine migration files
├── public/                           ← Nginx document root
│   └── index.php                     ← Symfony front controller
├── src/                              ← PHP source code
│   ├── Command/                      ← Console commands (app:create-admin, etc.)
│   ├── Controller/                   ← HTTP controllers
│   ├── Entity/                       ← Doctrine ORM entities
│   ├── Repository/                   ← Doctrine repositories
│   ├── Service/                      ← Business logic services
│   └── Kernel.php
├── templates/                        ← Twig templates
├── translations/                     ← i18n translation files
├── var/                              ← Runtime (owner: www-data:www-data)
│   ├── cache/prod/                   ← Compiled container, routes, templates
│   └── log/
│       └── prod.log                  ← Application log
├── vendor/                           ← Composer dependencies (installed on server)
├── assets/                           ← (removed 2026 — no frontend build toolchain)
├── composer.json / composer.lock
```

**Key file ownership rules:**
- `/var/www/starzcrm/` → `www-data:www-data` (755 dirs, 644 files)
- `/var/www/starzcrm/var/` → `www-data:www-data` (775) — PHP-FPM writes here
- `/var/www/starzcrm/bin/console` → must be `chmod +x`

---

### 0.6 Production Environment Config (`.env.local`)

This file lives at `/var/www/starzcrm/.env.local` on the VPS and is **never committed to Git**:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=CHANGE_ME_generate_a_fresh_secret

DATABASE_URL="mysql://crm_user:<REDACTED-SET-YOUR-OWN>@127.0.0.1:3306/starz_crm?serverVersion=8.0&charset=utf8mb4"

MAILER_DSN=smtp://CHANGE_ME@CHANGE_ME:1025
MAILER_FROM_ADDRESS=contact@starzelectronics.site
MAILER_FROM_NAME="Starz Electronics"

DEFAULT_LOCALE=en
DEFAULT_URI=https://www.starzcrm.com

MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
LOCK_DSN=flock

# Scraper proxy for web crawling (credentials redacted — set your own)
SCRAPER_PROXY_URL=http://<REDACTED-SET-YOUR-OWN>:<REDACTED-SET-YOUR-OWN>@geo.iproyal.com:12321

# Self-hosted SearXNG search instance (primary search engine)
SEARXNG_BASE_URL=http://127.0.0.1:8888
```

---

### 0.7 Nginx Configuration

Config file: `/etc/nginx/sites-available/starzcrm`  
Symlink: `/etc/nginx/sites-enabled/starzcrm`  
Default site: **removed** (`sites-enabled/default` deleted)

```nginx
# HTTPS server (port 443) — managed by Certbot
server {
    server_name www.starzcrm.com starzcrm.com;
    root /var/www/starzcrm/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    add_header Cache-Control "no-cache, no-store, must-revalidate" always;
    add_header Pragma "no-cache" always;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_busy_buffers_size 256k;
        fastcgi_read_timeout 600s;
        fastcgi_send_timeout 600s;
        fastcgi_connect_timeout 60s;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    error_log /var/log/nginx/starzcrm_error.log;
    access_log /var/log/nginx/starzcrm_access.log;
    client_max_body_size 20M;

    listen [::]:443 ssl ipv6only=on;   # managed by Certbot
    listen 443 ssl;                     # managed by Certbot
    ssl_certificate /etc/letsencrypt/live/www.starzcrm.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/www.starzcrm.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
}

# HTTP → HTTPS redirect (port 80)
server {
    listen 80;
    listen [::]:80;
    server_name www.starzcrm.com starzcrm.com;

    if ($host = www.starzcrm.com) { return 301 https://$host$request_uri; }
    if ($host = starzcrm.com) { return 301 https://$host$request_uri; }
    return 404;
}
```

---

### 0.8 SSL Certificate

| Item | Value |
|------|-------|
| **Provider** | Let's Encrypt (via Certbot) |
| **Domains** | `www.starzcrm.com`, `starzcrm.com` |
| **Certificate** | `/etc/letsencrypt/live/www.starzcrm.com/fullchain.pem` |
| **Private Key** | `/etc/letsencrypt/live/www.starzcrm.com/privkey.pem` |
| **Issued** | February 25, 2026 |
| **Expires** | May 26, 2026 |
| **Auto-renewal** | Yes — Certbot systemd timer runs automatically |

**Manual renewal (if needed):**
```bash
ssh -i $SSH_KEY -o IdentitiesOnly=yes root@$PRODUCTION_HOST
sudo certbot renew --dry-run    # test
sudo certbot renew              # force renew
```

---

### 0.9 Admin Login

| Item | Value |
|------|-------|
| **Login URL** | [https://www.starzcrm.com/login](https://www.starzcrm.com/login) |
| **Admin email** | `sadok.aaron@starzelectronics.com` |
| **Role** | `ROLE_ADMIN` |

**To create additional users:**
```bash
ssh -i $SSH_KEY -o IdentitiesOnly=yes root@$PRODUCTION_HOST
cd /var/www/starzcrm
sudo -u www-data php bin/console app:create-admin \
  --email=newuser@example.com \
  --password='<REDACTED-SET-YOUR-OWN>' \
  --firstName=John \
  --lastName=Doe
```

---

### 0.10 Common Operations Cheat Sheet

```bash
# ─── SSH Access ─────────────────────────────────────────────
ssh -i $SSH_KEY -o IdentitiesOnly=yes root@$PRODUCTION_HOST
ssh $PRODUCTION_SSH_ALIAS                           # if local SSH config is present

# ─── Service Management ────────────────────────────────────
sudo systemctl restart php8.4-fpm         # Restart PHP
sudo systemctl restart nginx              # Restart Nginx
sudo systemctl restart mysql              # Restart MySQL
sudo systemctl status php8.4-fpm nginx mysql  # Check all services

# ─── Symfony Console ───────────────────────────────────────
cd /var/www/starzcrm
sudo -u www-data php bin/console cache:clear --env=prod        # Clear cache
sudo -u www-data php bin/console cache:warmup --env=prod       # Warm cache
sudo -u www-data php bin/console app:migrations:safe-migrate --env=prod  # preflight + legacy preservation + migrate + verify
sudo -u www-data php bin/console app:create-admin              # Create admin (interactive)
php bin/console list                                            # List all commands

# ─── Logs ──────────────────────────────────────────────────
tail -f /var/www/starzcrm/var/log/prod.log              # Symfony app log
tail -f /var/log/nginx/starzcrm_error.log               # Nginx errors
tail -f /var/log/nginx/starzcrm_access.log              # Nginx access
journalctl -u php8.4-fpm -f                             # PHP-FPM systemd log
journalctl -u mysql -f                                  # MySQL systemd log

# ─── Frontend Assets ──────────────────────────────────────
# No frontend build step (Webpack Encore/Tailwind removed 2026)
cd /var/www/starzcrm
ls -la public/                   # Verify web root (templates render directly)

# ─── Database ─────────────────────────────────────────────
sudo mysql starz_crm                   # Quick MySQL access
sudo mysql starz_crm -e "SHOW TABLES;" # List tables
sudo mysqldump starz_crm > /tmp/backup_$(date +%Y%m%d).sql  # Backup

# ─── File Permissions Fix ─────────────────────────────────
sudo chown -R www-data:www-data /var/www/starzcrm
sudo find /var/www/starzcrm -type d -exec chmod 755 {} \;
sudo find /var/www/starzcrm -type f -exec chmod 644 {} \;
sudo chmod +x /var/www/starzcrm/bin/console
sudo chmod -R 775 /var/www/starzcrm/var
```

---

### 0.11 Full Deployment Procedure (from local machine)

Use this process to deploy code updates from the local development machine to the VPS.

**Prerequisites:**
- SSH key `$SSH_KEY` available on local machine
- Local project at `~/IdeaProjects/CRM-v2`

#### Step 1: Create deployment tarball

```bash
cd ~/IdeaProjects/CRM-v2

tar czf /tmp/crm-deploy.tar.gz \
  --exclude='./var' \
  --exclude='./vendor' \
  --exclude='./node_modules' \
  --exclude='./.git' \
  --exclude='./.env.local' \
  --exclude='./.env.test' \
  --exclude='./ml' \
  --exclude='./.venv' \
  --exclude='./models' \
  --exclude='./test-results' \
  --exclude='./playwright-report' \
  --exclude='./.phpunit.result.cache' \
  --exclude='./.output.txt' \
  --exclude='*.zip' \
  --exclude='*.xlsx' \
  --exclude='*.xls' \
  --exclude='*.pdf' \
  .

# Verify size — should be ~3-5 MB
ls -lh /tmp/crm-deploy.tar.gz
```

> **Why exclude `ml/` and `.venv/`?** These directories contain Python ML models (8 GB+) and are not needed for the web application.

#### Step 2: Upload to VPS

```bash
scp -i $SSH_KEY -o IdentitiesOnly=yes /tmp/crm-deploy.tar.gz root@$PRODUCTION_HOST:/tmp/
```

#### Step 3: Deploy on server

```bash
ssh -i $SSH_KEY -o IdentitiesOnly=yes root@$PRODUCTION_HOST
```

Then on the server:
```bash
cd /var/www/starzcrm

# Back up current .env.local (contains production secrets)
cp .env.local /tmp/.env.local.bak

# Extract new code
sudo rm -rf /var/www/starzcrm/*
cd /var/www/starzcrm
sudo tar xzf /tmp/crm-deploy.tar.gz

# Restore production config
sudo cp /tmp/.env.local.bak .env.local
sudo chmod +x bin/console

# Install PHP dependencies
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction

# (No frontend build step — Webpack Encore/Tailwind removed 2026)

# Run database migrations
sudo -u www-data php bin/console app:migrations:safe-migrate --env=prod  # NEVER raw doctrine:migrations:migrate on production data

# Fix file ownership
sudo chown -R www-data:www-data /var/www/starzcrm
sudo chmod -R 775 /var/www/starzcrm/var
sudo chmod +x /var/www/starzcrm/bin/console

# Clear and warm cache
sudo -u www-data php bin/console cache:clear --env=prod --no-debug
sudo -u www-data php bin/console cache:warmup --env=prod --no-debug

# Restart PHP-FPM to pick up changes
sudo systemctl restart php8.4-fpm
```

#### Step 4: Verify deployment

```bash
# From local machine:
curl -sI https://www.starzcrm.com | head -5
# Should return: HTTP/1.1 302 Found (redirecting to /login)

curl -sI https://www.starzcrm.com/login | head -3
# Should return: HTTP/1.1 200 OK
```

### 0.11A Targeted Hotfix Procedure (Twig, translations, public files)

Use this instead of the full deploy only when all of the following are true:
- only files under `templates/`, `translations/`, or `public/` changed
- no Doctrine migrations changed
- no `composer.lock` or environment files changed
- no `vendor/` reinstall or frontend rebuild is required

#### Local machine

Example hotfix bundle. Replace the file list with the actual changed files for your patch:

```bash
cd ~/IdeaProjects/CRM-v2

tar czf /tmp/crm-hotfix.tar.gz \
    templates/quote_copilot/index.html.twig \
    translations/messages.en.json \
    translations/messages.fr.json \
    translations/messages.ar.json \
    public/samples/quote-copilot-upload-template.csv \
    public/samples/quote-copilot-upload-spec.html

scp -i $SSH_KEY -o IdentitiesOnly=yes /tmp/crm-hotfix.tar.gz root@$PRODUCTION_HOST:/tmp/
ssh -i $SSH_KEY -o IdentitiesOnly=yes root@$PRODUCTION_HOST
```

#### Server

```bash
set -e

backup_dir=/tmp/crm-hotfix-backup-$(date +%Y%m%d-%H%M%S)
mkdir -p "$backup_dir/templates/quote_copilot" "$backup_dir/translations" "$backup_dir/public/samples"

cp /var/www/starzcrm/templates/quote_copilot/index.html.twig "$backup_dir/templates/quote_copilot/"
cp /var/www/starzcrm/translations/messages.en.json "$backup_dir/translations/"
cp /var/www/starzcrm/translations/messages.fr.json "$backup_dir/translations/"
cp /var/www/starzcrm/translations/messages.ar.json "$backup_dir/translations/"
cp /var/www/starzcrm/public/samples/quote-copilot-upload-template.csv "$backup_dir/public/samples/" 2>/dev/null || true
cp /var/www/starzcrm/public/samples/quote-copilot-upload-spec.html "$backup_dir/public/samples/" 2>/dev/null || true

cd /var/www/starzcrm
tar xzf /tmp/crm-hotfix.tar.gz -C /var/www/starzcrm

chown www-data:www-data \
    /var/www/starzcrm/templates/quote_copilot/index.html.twig \
    /var/www/starzcrm/translations/messages.en.json \
    /var/www/starzcrm/translations/messages.fr.json \
    /var/www/starzcrm/translations/messages.ar.json \
    /var/www/starzcrm/public/samples/quote-copilot-upload-template.csv \
    /var/www/starzcrm/public/samples/quote-copilot-upload-spec.html

sudo -u www-data php bin/console cache:clear --env=prod --no-debug
sudo -u www-data php bin/console cache:warmup --env=prod --no-debug
```

Verify the affected URLs immediately after the patch:

```bash
curl -sI https://www.starzcrm.com/login | head -3
curl -sI https://www.starzcrm.com/samples/quote-copilot-upload-template.csv | head -3
curl -sI https://www.starzcrm.com/samples/quote-copilot-upload-spec.html | head -3
```

---

### 0.12 Troubleshooting

| Problem | Solution |
|---------|----------|
| 502 Bad Gateway | `sudo systemctl restart php8.4-fpm` — PHP-FPM crashed |
| 500 Internal Server Error | Check `tail /var/www/starzcrm/var/log/prod.log` and `tail /var/log/nginx/starzcrm_error.log` |
| Permission denied on `var/` | `sudo chown -R www-data:www-data /var/www/starzcrm/var && sudo chmod -R 775 /var/www/starzcrm/var` |
| `bin/console` not executable | `sudo chmod +x /var/www/starzcrm/bin/console` |
| Class not found errors | `cd /var/www/starzcrm && sudo -u www-data composer dump-autoload --optimize` |
| Missing assets (broken CSS/JS) | No frontend build step exists (Encore/Tailwind removed 2026); verify `public/` files exist and clear cache |
| `tar: Ignoring unknown extended header keyword 'LIBARCHIVE.xattr.com.apple.provenance'` | Safe when the archive was created on macOS; Ubuntu still extracts the payload correctly |
| SSL certificate expired | `sudo certbot renew` |
| MySQL won't start | `sudo journalctl -u mysql -n 50` to check logs |
| Cache issues after deploy | `sudo -u www-data php bin/console cache:clear --env=prod` |
| `.env.local` missing after deploy | Restore from backup: `cp /tmp/.env.local.bak /var/www/starzcrm/.env.local` |

---

## 1. Fresh Server Setup (For New VPS)

This section covers setting up a brand new server from scratch.

### 1.1 System Requirements

**Host**: Ubuntu 24.04 LTS  
**Hardware**: 4+ vCPU, 8+ GB RAM, 80+ GB SSD, 1 Gbps NIC  
**Network**: Ports 22, 80, 443 open; SMTP 587 outbound; HTTPS enforced

### 1.2 Install Required Packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y php8.4-fpm php8.4-cli php8.4-intl php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-mysql php8.4-gd php8.4-bcmath \
  nginx mysql-server git unzip certbot python3-certbot-nginx
```

### 1.3 Create Application Directory

```bash
sudo mkdir -p /var/www/starzcrm
sudo chown www-data:www-data /var/www/starzcrm
```

### 1.4 Database Setup

```bash
sudo mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS starz_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'crm_user'@'localhost' IDENTIFIED BY '<REDACTED-SET-YOUR-OWN>';
GRANT ALL PRIVILEGES ON starz_crm.* TO 'crm_user'@'localhost';
FLUSH PRIVILEGES;
SQL
```

### 1.5 Configure Nginx

Create `/etc/nginx/sites-available/starzcrm`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name www.starzcrm.com starzcrm.com;
    root /var/www/starzcrm/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    error_log /var/log/nginx/starzcrm_error.log;
    access_log /var/log/nginx/starzcrm_access.log;
    client_max_body_size 20M;
}
```

Enable site:
```bash
sudo ln -s /etc/nginx/sites-available/starzcrm /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

### 1.6 SSL Certificate

```bash
sudo certbot --nginx -d www.starzcrm.com -d starzcrm.com
```

### 1.7 Deploy Application

Follow steps in section 0.11 to deploy the application code.

### 1.8 Run Initial Migrations

```bash
cd /var/www/starzcrm
sudo -u www-data php bin/console app:migrations:safe-migrate --env=prod  # NEVER raw doctrine:migrations:migrate on production data
```

### 1.9 Create Admin User

```bash
sudo -u www-data php bin/console app:create-admin \
  --email=sadok.aaron@starzelectronics.com \
  --password='<REDACTED-SET-YOUR-OWN>' \
  --firstName=Aaron \
  --lastName=Sadok
```

---

## 2. Background Jobs & Messenger

### 2.1 Messenger Consumer (Async Email)

For production, run the Messenger consumer to process async email:

```bash
# Manual start (for testing)
cd /var/www/starzcrm
sudo -u www-data php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M -v
```

### 2.2 Systemd Service (Recommended)

Create `/etc/systemd/system/starz-messenger.service`:

```ini
[Unit]
Description=StarzCRM Messenger Worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/starzcrm
ExecStart=/usr/bin/php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable and start:
```bash
sudo systemctl daemon-reload
sudo systemctl enable starz-messenger
sudo systemctl start starz-messenger
```

---

## 3. Backup & Monitoring

### 3.1 Daily Backup Script

Create `/usr/local/bin/backup-starz-crm.sh`:

```bash
#!/bin/bash
BACKUP_DIR="/var/backups/starz-crm"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="starz_crm"

mkdir -p $BACKUP_DIR

# Backup database
mysqldump $DB_NAME | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Keep only last 30 days
find $BACKUP_DIR -type f -mtime +30 -delete

echo "Backup completed: $DATE"
```

Schedule via cron:
```bash
sudo chmod +x /usr/local/bin/backup-starz-crm.sh
sudo crontab -e
# Add: 0 2 * * * /usr/local/bin/backup-starz-crm.sh >> /var/log/starz-crm-backup.log 2>&1
```

### 3.2 Monitoring Logs

```bash
# Application logs
tail -f /var/www/starzcrm/var/log/prod.log

# Nginx logs
tail -f /var/log/nginx/starzcrm_error.log

# System resources
htop
df -h /var/www/starzcrm
```

---

## 4. Verification & Testing

### 4.1 Health Checks

```bash
# Test routing
php bin/console debug:router --env=prod | head -20

# Test database
php bin/console doctrine:query:sql "SELECT 1" --env=prod

# Test external access
curl -sI https://www.starzcrm.com | head -5
```

### 4.2 Key URLs to Verify

| URL | Expected |
|-----|----------|
| `https://www.starzcrm.com/` | 302 redirect to `/login` |
| `https://www.starzcrm.com/login` | 200 OK, login page |
| `https://www.starzcrm.com/companies` | 200 OK (after login) |
| `https://www.starzcrm.com/rfq` | 200 OK (after login) |
| `https://www.starzcrm.com/email-campaigns` | 200 OK (after login) |

---

## 5. Security Hardening

### 5.1 File Permissions

```bash
sudo chmod 600 /var/www/starzcrm/.env.local
sudo chmod 600 /var/www/starzcrm/.env
```

### 5.2 Firewall (UFW)

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### 5.3 PHP Configuration

Edit `/etc/php/8.4/fpm/php.ini`:

```ini
expose_php = Off
display_errors = Off
log_errors = On
max_execution_time = 30
memory_limit = 256M
post_max_size = 20M
upload_max_filesize = 20M
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.4-fpm
```

---

## Appendix A – Quick Reference Commands

```bash
# SSH Access
ssh -i $SSH_KEY -o IdentitiesOnly=yes root@$PRODUCTION_HOST
ssh $PRODUCTION_SSH_ALIAS

# Restart services
sudo systemctl restart php8.4-fpm nginx mysql

# Clear Symfony cache
cd /var/www/starzcrm && sudo -u www-data php bin/console cache:clear --env=prod

# View logs
tail -f /var/www/starzcrm/var/log/prod.log

# Database backup
sudo mysqldump starz_crm > /tmp/backup_$(date +%Y%m%d).sql

# Deploy code
scp -i $SSH_KEY -o IdentitiesOnly=yes /tmp/crm-deploy.tar.gz root@$PRODUCTION_HOST:/tmp/

# Check SSL expiry
openssl x509 -in /etc/letsencrypt/live/www.starzcrm.com/fullchain.pem -noout -dates
```

---

## Appendix B – Documentation Location

All documentation is in `/var/www/starzcrm/Documentation/`:

| Document | Purpose |
|----------|---------|
| `PRODUCTION_DEPLOYMENT_GUIDE.md` | This file |
| `PROJECT_STATUS.md` | System overview |
| `LEADBOT_INTEGRATION.md` | Lead system details |
| `EMAIL_CAMPAIGN_QUICK_REFERENCE.md` | Email campaigns |
| `QUICKSTART.md` | Quick start guide |

---

**Deployment Complete!** ✅

The StarzCRM system is live at https://www.starzcrm.com

**Version**: 2.0  
**Last Updated**: February 25, 2026  
**Status**: ✅ Production-Ready
