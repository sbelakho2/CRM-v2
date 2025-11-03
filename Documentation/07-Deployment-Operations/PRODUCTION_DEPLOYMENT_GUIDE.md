# Starz Morocco CRM – Production Deployment Guide (Release V1)

**Audience**: DevOps, SecOps, Release Manager  
**Revision**: October 30, 2025  
**Status**: Approved pending SecOps credential rotation (ticket SEC-1432)

---

## 1. At-a-Glance

| Phase | Owner | Duration |
|-------|-------|----------|
| Pre-flight verification | Release Manager + SecOps | 0.5 day |
| Infrastructure preparation | DevOps | 0.5 day |
| Application deployment | DevOps | 0.5 day |
| Feature enablement & jobs | DevOps + App Ops | 0.5 day |
| Validation & sign-off | QA + Product | 0.5 day |

📎 Reference documents:  
- `DEPLOYMENT_CHECKLIST.md` (high-level checkpoints)  
- `DEPLOYMENT_READY.md` (sign-offs, risk register)  
- `FINAL_TEST_SUMMARY.md` (QA evidence)  
- `ANALYTICS_AND_TRACKING_SYSADMIN_GUIDE.md` (Grafana / Matomo configuration)

---

## 2. Roles & Responsibilities

| Role | Responsibilities |
|------|------------------|
| DevOps | Provision hosts, install dependencies, deploy code, configure services |
| SecOps | Rotate Nexar/Mouser/Digi-Key credentials, update vault entries, confirm audit trail |
| App Ops | Schedule background jobs, configure monitoring, manage rollout communications |
| QA Lead | Execute smoke plan, review logs, file GO/NO-GO recommendation |
| Product Owner | Approve launch messaging, confirm hyper-care staffing |

---

## 3. Infrastructure & Software Requirements

**Host**: Ubuntu 22.04 LTS (recommended) or RHEL 9 equivalent  
**Hardware**: 4 vCPU, 8 GB RAM, 80 GB SSD, 1 Gbps NIC  
**Network**: Ports 22, 80, 443 open; SMTP 587 outbound; HTTPS enforced

Required packages:

```bash
sudo apt update
sudo apt install -y php8.2-fpm php8.2-cli php8.2-intl php8.2-mbstring \
  php8.2-xml php8.2-curl php8.2-zip php8.2-mysql php8.2-gd php8.2-bcmath \
  nginx mysql-server git unzip nodejs npm certbot python3-certbot-nginx
```

Symfony CLI (optional but helpful):

```bash
wget https://get.symfony.com/cli/installer -O - | bash
sudo mv ~/.symfony5/bin/symfony /usr/local/bin/symfony
```

Node.js 18+ required for Webpack Encore; use distro package or install via nvm.

---

## 4. Pre-Flight Checklist

1. ✅ Confirm QA approval in `Documentation/09-Project-Status/EXECUTIVE_SUMMARY_GO_LIVE_APPROVED.md`.
2. ✅ Verify SecOps ticket SEC-1432 is in progress (supplier API credentials).
3. ✅ Snapshot current production database and file store.
4. ✅ Ensure DNS (`crm.starz-morocco.com`) resolves to target host.
5. ✅ Confirm SMTP provider whitelists new host IP.
6. ✅ Download release artifact or confirm access to `main` branch tag `release/v1.0.0` (Git hash noted in checklist).

---

## 5. Deployment Steps

### Step 5.1 – Create Service Account and Directories

```bash
sudo useradd -m -d /opt/starzcrm -s /bin/bash starzcrm
sudo passwd starzcrm
sudo usermod -a -G www-data starzcrm
sudo mkdir -p /var/www/starz-crm
sudo chown starzcrm:www-data /var/www/starz-crm
```

### Step 5.2 – Fetch Release

```bash
sudo su - starzcrm
cd /var/www/starz-crm

# Option A: clone
git clone <repository-url> app

# Option B: deploy artifact
# unzip release-v1.0.0.zip -d app

cd app
git checkout release/v1.0.0   # if using Git
```

Record commit hash in deployment log.

### Step 5.3 – Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

### Step 5.4 – Configure Environment

```bash
cp .env .env.local
nano .env.local
```

Populate the following keys (use vault references where applicable):

```
APP_ENV=prod
APP_SECRET=<generate via `php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"`>
DATABASE_URL="mysql://crm_user:<password>@127.0.0.1:3306/starz_crm?serverVersion=8.0&charset=utf8mb4"
MAILER_DSN=smtp://apikey:********@smtp.sendgrid.net:587
NEXAR_API_KEY=*** (SecOps)
MOUSER_API_KEY=*** (SecOps)
DIGIKEY_CLIENT_ID=*** (SecOps)
DIGIKEY_CLIENT_SECRET=*** (SecOps)
QUOTE_COPILOT_CACHE_TTL=900
HEATMAP_REFRESH_CRON="0 * * * *"
NOTIFICATION_CRON="*/5 * * * *"
SESSION_TTL_MINUTES=240
REMEMBER_ME_TOKEN_TTL_DAYS=14
```

Add any infrastructure-specific overrides (proxy, Redis, messenger transports) per environment.

### Step 5.5 – Database Provisioning

```bash
sudo mysql -u root <<'SQL'
CREATE DATABASE IF NOT EXISTS starz_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'crm_user'@'%' IDENTIFIED BY '<password>';
GRANT ALL PRIVILEGES ON starz_crm.* TO 'crm_user'@'%';
FLUSH PRIVILEGES;
SQL
```

Run migrations and integrity checks:

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console doctrine:schema:validate --env=prod
```

Optional seed for baseline data:

```bash
php bin/console app:import-tracker storage/seeds/tracker.csv --env=prod --no-interaction
php bin/console app:create-user --email=admin@starz-morocco.com --role=ROLE_ADMIN --env=prod
```

### Step 5.6 – Cache Warm-up

```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

---

## 6. Feature Enablement Matrix

### 6.1 Smart Notification Center

| Task | Command / Action |
|------|------------------|
| Ensure database migration `Version20251015104500` applied | Covered in migrations step |
| Configure cron | `*/5 * * * * /usr/bin/php /var/www/starz-crm/app/bin/console app:check-notifications --env=prod >> /var/log/starzcrm/notifications.log 2>&1` |
| Seed demo events (optional for smoke) | `php bin/console app:check-notifications --seed --env=prod` |
| Expose API | Confirm `/api/notifications`, `/api/notifications/count` reachable (requires auth token) |
| Monitoring | Add log shipping for `/var/log/starzcrm/notifications.log`; set Grafana alert on error count |

### 6.2 Mobile Quick Actions

- Ensure `npm run build` produced `public/build/app.js` containing FAB Stimulus controller.  
- Confirm `.env.local` has `FEATURE_MOBILE_QUICK_ACTIONS=1` (default).  
- Purge CDN cache (if applicable) to deliver new JS bundle.  
- Validate responsive breakpoint via browser dev tools (step in smoke tests).

### 6.3 Secure Authentication Hardening

- Configure session storage (default PHP sessions acceptable; Redis optional).  
- Set environment overrides:
  - `LOGIN_MAX_ATTEMPTS=5`
  - `LOGIN_BACKOFF_SECONDS=300`
  - `REMEMBER_ME_DOMAIN=.starz-morocco.com`
- Verify `/admin/security/audit` accessible to ROLE_ADMIN only.  
- Sync secrets with monitoring to flag repeated auth failures.

### 6.4 Engagement Heat Map

| Task | Details |
|------|---------|
| Set cron | `0 * * * * /usr/bin/php /var/www/starz-crm/app/bin/console app:heatmap:refresh --env=prod >> /var/log/starzcrm/heatmap.log 2>&1` |
| Configure data retention | `.env.local` -> `HEATMAP_RETENTION_DAYS=90` |
| Ensure Matomo/Grafana endpoints reachable | Follow `ANALYTICS_AND_TRACKING_SYSADMIN_GUIDE.md` |
| Seed baseline | `php bin/console app:heatmap:refresh --force --env=prod` |
| Validate | Visit `/engagement/heat-map` and confirm tiles render and hover data present |

### 6.5 Quote Co-Pilot

1. Populate supplier credentials (SecOps).  
2. Verify outbound HTTPS allowed to Nexar, Mouser, Digi-Key APIs.  
3. Warm cache:
   ```bash
   php bin/console app:quote:refresh-suppliers --env=prod --force
   ```
4. Confirm storage directories writable:
   ```bash
   sudo mkdir -p var/quote-cache var/exports
   sudo chown -R starzcrm:www-data var/quote-cache var/exports
   ```
5. Test PDF export:
   ```bash
   php bin/console app:quote:generate-pdf --bom=storage/samples/bom-demo.xlsx --env=prod
   ```
6. Ensure `MAILER_DSN` points to production provider for quote dispatch notifications.

### 6.6 Legacy CRM Modules

- Run regression CLI (optional): `php bin/console app:system:smoke --env=prod`.  
- Confirm routes for companies, contacts, RFQ remain accessible; caches warmed by page hits.

---

## 7. Web Server & TLS

Configure Nginx (recommended) as reverse proxy for PHP-FPM:

```nginx
server {
    listen 80;
    server_name crm.starz-morocco.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name crm.starz-morocco.com;

    ssl_certificate /etc/letsencrypt/live/crm.starz-morocco.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/crm.starz-morocco.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_stapling on;

    root /var/www/starz-crm/app/public;
    index index.php;

    add_header Strict-Transport-Security "max-age=63072000" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    access_log /var/log/nginx/starz-crm-access.log;
    error_log /var/log/nginx/starz-crm-error.log warn;
}
```

Reload Nginx after configuration:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Obtain certificates via Certbot (`sudo certbot --nginx -d crm.starz-morocco.com`).

---

## 8. Background Jobs & Scheduler Configuration

Create dedicated log directory:

```bash
sudo mkdir -p /var/log/starzcrm
sudo chown starzcrm:www-data /var/log/starzcrm
```

Cron entries (`crontab -u starzcrm -e`):

```
*/5 * * * * /usr/bin/php /var/www/starz-crm/app/bin/console app:check-notifications --env=prod >> /var/log/starzcrm/notifications.log 2>&1
0 * * * * /usr/bin/php /var/www/starz-crm/app/bin/console app:heatmap:refresh --env=prod >> /var/log/starzcrm/heatmap.log 2>&1
15 2 * * * /usr/bin/php /var/www/starz-crm/app/bin/console app:quote:refresh-suppliers --env=prod >> /var/log/starzcrm/quote-cache.log 2>&1
0 3 * * * /usr/bin/php /var/www/starz-crm/app/bin/console app:system:cleanup --env=prod >> /var/log/starzcrm/maintenance.log 2>&1
```

Add logrotate configuration `/etc/logrotate.d/starzcrm`:

```
/var/log/starzcrm/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    create 640 starzcrm www-data
    sharedscripts
    postrotate
        systemctl reload php8.2-fpm >/dev/null 2>&1 || true
    endscript
}
```

---

## 9. Monitoring & Alerting

- **Metrics**: Import Grafana dashboard `ops/grafana/crm-v1.json` (CPU, memory, notification cron success, heat map latency).  
- **Logs**: Forward `/var/log/nginx/*.log` and `/var/log/starzcrm/*.log` to ELK or Azure Monitor.  
- **Synthetic checks**: Configure UptimeRobot or Pingdom for `/healthz` endpoint (returns 200 when DB + cache reachable).  
- **Security**: Enable Fail2ban for SSH and `/login` route (optional but recommended).  
- **Alerts**: PagerDuty integration for failed cron jobs, API error spikes, supplier API outage (Quote Co-Pilot).

---

## 10. Validation & Sign-Off

### 10.1 Smoke Script (20 minutes)

1. **Authentication** – login, verify remember-me cookie, logout invalidates session.  
2. **Notifications** – trigger CLI, confirm badge increments, mark-as-read clears count.  
3. **Mobile Quick Actions** – check floating button on desktop + mobile viewport, run keyboard shortcut.  
4. **Engagement Heat Map** – ensure last refreshed timestamp updates, hover tooltip data accurate.  
5. **Quote Co-Pilot** – upload BOM, view supplier waterfall, download PDF (verify stored under `var/exports`).  
6. **Email Delivery** – send test from Quote Co-Pilot to staging mailbox; inspect SMTP logs.  
7. **Background Jobs** – review `/var/log/starzcrm/*.log` for errors after first cron run.  
8. **Monitoring** – confirm Grafana dashboard receives metrics, health check green.

Record outcomes in `PRODUCTION_DEPLOYMENT_REPORT.md` and attach log excerpts.

### 10.2 Sign-Off Gate

- DevOps lead completes `DEPLOYMENT_CHECKLIST.md` sections A–E.  
- QA lead signs `DEPLOYMENT_READY.md` once smoke tests pass.  
- Product owner issues GO decision and schedules launch communication.  
- Update `SYSTEM_RUNNING.md` with deployment timestamp and owner on duty.

---

## 11. Rollback Strategy

1. Restore filesystem snapshot or previous release artifact to `/var/www/starz-crm/app`.  
2. Revert database using latest snapshot (point-in-time recovery).  
3. Re-run composer install and cache warmup if needed.  
4. Restart PHP-FPM and Nginx.  
5. Post-rollback communication to stakeholders; convert deployment report into incident record.

Rollback target: prior stable release `release/v0.9.4` (hash documented in `DEPLOYMENT_READY.md`).

---

## 12. Hyper-Care Plan (First 24 Hours)

- Staff: DevOps + Product owner on call; QA on standby.  
- Metrics to watch: notification cron errors, supplier API latency, heat map job duration, login error rate.  
- Communication cadence: #crm-ops Slack channel updates every 2 hours or on event.  
- Issue triage: high severity escalated to incident bridge within 15 minutes.

---

## Appendix A – Command Reference

```bash
php bin/console app:check-notifications --help
php bin/console app:heatmap:refresh --help
php bin/console app:quote:refresh-suppliers --help
php bin/console app:quote:generate-pdf --help
php bin/console app:system:cleanup --help
php bin/console security:hash-password
php bin/console debug:config framework
php bin/console about --env=prod
```

For full testing scope consult `Documentation/10-Quality-Assurance/TEST_EXECUTION_REPORT.md`.

---

## Appendix B – File & Directory Permissions

```bash
sudo chown -R starzcrm:www-data /var/www/starz-crm/app
sudo find /var/www/starz-crm/app -type f -exec chmod 640 {} \;
sudo find /var/www/starz-crm/app -type d -exec chmod 750 {} \;

sudo chmod -R 770 /var/www/starz-crm/app/var
sudo chmod -R 770 /var/www/starz-crm/app/public/uploads
```

Ensure `.env.local` readable only by service account (`chmod 600`).

---

Deployment complete when:

- ✅ All sections of this guide executed and logged.  
- ✅ Feature smoke tests pass.  
- ✅ Monitoring & alerts active.  
- ✅ SecOps confirms credential rotation.  
- ✅ Product owner signs the GO memo.

Store completed checklist, deployment log, and monitoring screenshots in the release archive.git clone <repository-url> crm-starz-morocco
sudo nano /etc/nginx/sites-available/starz-crm
```

**Content:**

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name crm.starz-morocco.com www.crm.starz-morocco.com;
    root /var/www/crm-starz-morocco/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    # Logging
    access_log /var/log/nginx/starz-crm-access.log;
    error_log /var/log/nginx/starz-crm-error.log;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

**2. Enable Site:**

```bash
# Create symlink
sudo ln -s /etc/nginx/sites-available/starz-crm /etc/nginx/sites-enabled/

# Test configuration
sudo nginx -t

# Restart Nginx
sudo systemctl restart nginx
sudo systemctl restart php8.4-fpm
```

---

## 🔒 Security Hardening

### Step 1: File Permissions

```bash
# Set secure permissions
cd /var/www/crm-starz-morocco

# Protect .env files
chmod 600 .env.local
chmod 600 .env

# Protect configuration
chmod 600 config/packages/*.yaml

# Ensure var directory is writable
chmod -R 775 var/
chown -R starzcrm:www-data var/
```

### Step 2: Disable Directory Listing

**Apache:**
```bash
# Add to .htaccess in public/
Options -Indexes
```

**Nginx:**
```nginx
# Already handled in server block
autoindex off;
```

### Step 3: Protect Sensitive Files

Create/verify `public/.htaccess`:

```apache
# Deny access to sensitive files
<FilesMatch "^\.">
    Require all denied
</FilesMatch>

# Deny access to config files
<FilesMatch "\.(yml|yaml|ini|env)$">
    Require all denied
</FilesMatch>
```

### Step 4: Configure Firewall

```bash
# UFW (Ubuntu)
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 22/tcp  # SSH
sudo ufw enable

# Firewalld (CentOS)
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --reload
```

### Step 5: Secure PHP Configuration

Edit `/etc/php/8.4/fpm/php.ini`:

```ini
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/php-errors.log
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

## 🔐 SSL/HTTPS Setup

### Using Let's Encrypt (Free SSL)

**1. Install Certbot:**

```bash
# Ubuntu/Debian
sudo apt install certbot python3-certbot-apache  # For Apache
# OR
sudo apt install certbot python3-certbot-nginx   # For Nginx
```

**2. Obtain Certificate:**

```bash
# Apache
sudo certbot --apache -d crm.starz-morocco.com -d www.crm.starz-morocco.com

# Nginx
sudo certbot --nginx -d crm.starz-morocco.com -d www.crm.starz-morocco.com
```

**3. Auto-Renewal:**

```bash
# Test renewal
sudo certbot renew --dry-run

# Certbot auto-renews via systemd timer
sudo systemctl status certbot.timer
```

**4. Verify HTTPS:**

Visit `https://crm.starz-morocco.com` - should show padlock icon

---

## 📧 Email Configuration

### Step 1: Configure SMTP & Mailer

Update `.env.local`:
```bash
# SMTP Configuration for outbound email
MAILER_DSN=smtp://username:password@smtp.gmail.com:587

# For SendGrid
MAILER_DSN=sendgrid+api://SG.xxx@default

# For AWS SES
MAILER_DSN=ses+api://xxx:xxx@default?region=us-east-1
```

Edit `config/packages/mailer.yaml`:

```yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
        envelope:
            sender: 'campaigns@starz-morocco.com'
```

### Step 2: Configure Messenger for Campaign Queues

Update `.env.local`:
```bash
# Messenger transport for email campaign processing
MESSENGER_TRANSPORT_DSN=doctrine://default
```

Edit `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            email_campaign_queue:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    queue_name: email_campaigns
                    auto_setup: true
        routing:
            'App\Message\SendCampaignCommand': email_campaign_queue
            'App\Message\ProcessBounceCommand': email_campaign_queue
            'App\Message\UpdateDeliverabilityCommand': email_campaign_queue
```

### Step 3: Start Background Workers

For production, run Messenger consumer:

```bash
# Long-running worker for campaign queue
php bin/console messenger:consume email_campaign_queue --time-limit=3600 --memory-limit=128M -v

# Or as a systemd service:
# Create /etc/systemd/system/starz-email-worker.service
[Unit]
Description=Starz CRM Email Campaign Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/crm-starz-morocco
ExecStart=/usr/bin/php bin/console messenger:consume email_campaign_queue --time-limit=3600 --memory-limit=128M
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target

# Enable and start:
sudo systemctl enable starz-email-worker
sudo systemctl start starz-email-worker
```

### Step 4: Test Email Sending

```bash
# Test basic SMTP connectivity
php bin/console app:test-email admin@starz-morocco.com --env=prod

# Test campaign trigger
php bin/console app:test-campaign-trigger --env=prod
```

### Step 5: Configure DNS for Deliverability

For production email campaigns, configure DNS records:

```bash
# SPF Record (add to domain DNS)
v=spf1 include:_spf.google.com include:sendgrid.net ~all

# DKIM Record
# Generate and add DKIM public key via email provider dashboard

# DMARC Record
v=DMARC1; p=quarantine; rua=mailto:dmarc@starz-morocco.com
```

Validate with:

```bash
php bin/console app:validate-dns starz-morocco.com --env=prod
```

### Step 6: Configure Email Templates

Email campaign templates are managed via UI at `/email-campaigns`. Ensure `templates/email_campaign/` directory exists and is writable:

```bash
sudo chown www-data:www-data /var/www/crm-starz-morocco/templates/email_campaign
sudo chmod 755 /var/www/crm-starz-morocco/templates/email_campaign
```

---

## � Analytics & Visitor Tracking Implementation

**For complete analytics and visitor tracking setup, see**: [Analytics & Tracking Sysadmin Guide](ANALYTICS_AND_TRACKING_SYSADMIN_GUIDE.md)

This comprehensive guide covers:

### Email Analytics
- Database schema for campaign tracking (email_send, email_click, email_bounce tables)
- Email tracking pixel setup and webhook configuration
- Analytics aggregation and caching
- Dashboard integration

### Website Visitor IP Tracking
- Tracking script installation on starzelectronics.com
- Tracking script installation on starzenergies.com
- IP-to-company resolution using GeoIP2/IP2Location
- Live visitor dashboard
- ABM playbook integration with visitor events

### Monitoring & Troubleshooting
- Analytics health checks
- Log monitoring and aggregation
- Database optimization for analytics tables
- Common issues and solutions

**Quick Start**: 
1. Follow installation steps in Analytics Guide for email tracking
2. Generate and install tracking scripts on both websites
3. Configure IP resolution services
4. Set up monitoring and maintenance schedules

---

## �💾 Backup & Monitoring

### Daily Backup Script

Create `/usr/local/bin/backup-starz-crm.sh`:

```bash
#!/bin/bash
BACKUP_DIR="/var/backups/starz-crm"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="starz_crm"
DB_USER="crm_user"
DB_PASS="YOUR_PASSWORD"

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup database
mysqldump -u $DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Backup files
tar -czf $BACKUP_DIR/files_$DATE.tar.gz /var/www/crm-starz-morocco/var/data

# Keep only last 30 days
find $BACKUP_DIR -type f -mtime +30 -delete

echo "Backup completed: $DATE"
```

Make executable and schedule:

```bash
sudo chmod +x /usr/local/bin/backup-starz-crm.sh

# Add to crontab (daily at 2 AM)
sudo crontab -e
# Add line:
0 2 * * * /usr/local/bin/backup-starz-crm.sh >> /var/log/starz-crm-backup.log 2>&1
```

### Monitoring

**1. Application Logs:**
```bash
tail -f /var/www/crm-starz-morocco/var/log/prod.log
```

**2. Web Server Logs:**
```bash
# Apache
tail -f /var/log/apache2/starz-crm-error.log

# Nginx
tail -f /var/log/nginx/starz-crm-error.log
```

**3. PHP Error Log:**
```bash
tail -f /var/log/php-errors.log
```

**4. Disk Space:**
```bash
df -h /var/www/crm-starz-morocco
```

---

## ✅ Testing & Verification

### Step 1: Basic Functionality Test

```bash
# 1. Test routing
php bin/console debug:router --env=prod | head -20

# 2. Test database connection
php bin/console doctrine:query:sql "SELECT 1" --env=prod

# 3. Check cache
php bin/console cache:pool:list --env=prod
```

### Step 2: Web Interface Test

Visit the following URLs and verify:

- ✅ `https://crm.starz-morocco.com/` - Dashboard loads
- ✅ `https://crm.starz-morocco.com/login` - Login page loads
- ✅ `https://crm.starz-morocco.com/companies` - Companies list (after login)
- ✅ `https://crm.starz-morocco.com/leads/review` - Leads review page
- ✅ `https://crm.starz-morocco.com/email-campaigns` - Email campaigns
- ✅ `https://crm.starz-morocco.com/rfq` - RFQ Pipeline

### Step 3: Performance Test

```bash
# Check page load time
curl -w "@curl-format.txt" -o /dev/null -s https://crm.starz-morocco.com/

# Create curl-format.txt:
cat > curl-format.txt << 'EOF'
    time_namelookup:  %{time_namelookup}\n
       time_connect:  %{time_connect}\n
    time_appconnect:  %{time_appconnect}\n
      time_redirect:  %{time_redirect}\n
   time_pretransfer:  %{time_pretransfer}\n
 time_starttransfer:  %{time_starttransfer}\n
                    ----------\n
         time_total:  %{time_total}\n
EOF
```

### Step 4: Security Test

```bash
# Test HTTPS redirect
curl -I http://crm.starz-morocco.com/ | grep -i location

# Test security headers
curl -I https://crm.starz-morocco.com/ | grep -i "x-frame-options\|x-content-type\|strict-transport"

# Test SSL certificate
openssl s_client -connect crm.starz-morocco.com:443 -servername crm.starz-morocco.com
```

---

## 🔧 Troubleshooting

### Issue: 500 Internal Server Error

**Check:**
```bash
# Application logs
tail -50 /var/www/crm-starz-morocco/var/log/prod.log

# Web server logs
tail -50 /var/log/apache2/starz-crm-error.log  # or nginx

# Permissions
ls -la /var/www/crm-starz-morocco/var/
```

**Fix:**
```bash
# Clear cache
php bin/console cache:clear --env=prod

# Fix permissions
sudo chown -R starzcrm:www-data /var/www/crm-starz-morocco/var
sudo chmod -R 775 /var/www/crm-starz-morocco/var
```

### Issue: Database Connection Failed

**Check:**
```bash
# Test database connection
php bin/console doctrine:query:sql "SELECT 1" --env=prod

# Verify credentials
cat /var/www/crm-starz-morocco/.env.local | grep DATABASE_URL
```

**Fix:**
```bash
# Update .env.local with correct credentials
nano /var/www/crm-starz-morocco/.env.local

# Test MySQL connection
mysql -u crm_user -p -h localhost starz_crm
```

### Issue: Email Not Sending

**Check:**
```bash
# Verify MAILER_DSN
cat /var/www/crm-starz-morocco/.env.local | grep MAILER_DSN

# Test SMTP connection
telnet smtp.gmail.com 587
```

**Fix:**
```bash
# Update MAILER_DSN in .env.local
nano /var/www/crm-starz-morocco/.env.local
# Example: smtp://username:password@smtp.gmail.com:587
```

### Issue: CSS/JS Not Loading

**Check:**
```bash
# Verify public directory permissions
ls -la /var/www/crm-starz-morocco/public/

# Check web server configuration
sudo apache2ctl -S  # Apache
sudo nginx -T       # Nginx
```

**Fix:**
```bash
# Clear Symfony cache
php bin/console cache:clear --env=prod

# Fix public directory permissions
sudo chmod -R 755 /var/www/crm-starz-morocco/public/
```

---

## 🔄 Maintenance

### Regular Tasks

**Daily:**
- ✅ Check error logs for issues
- ✅ Verify backups completed
- ✅ Monitor disk space

**Weekly:**
- ✅ Review application logs
- ✅ Check SSL certificate expiry
- ✅ Update dependencies if needed

**Monthly:**
- ✅ Review user accounts
- ✅ Optimize database
- ✅ Test backup restoration
- ✅ Security updates

### Update Application

```bash
# 1. Backup first
/usr/local/bin/backup-starz-crm.sh

# 2. Pull latest code
cd /var/www/crm-starz-morocco
sudo -u starzcrm git pull

# 3. Update dependencies
sudo -u starzcrm composer install --no-dev --optimize-autoloader

# 4. Run migrations (if any)
php bin/console doctrine:migrations:migrate --env=prod

# 5. Clear cache
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

# 6. Restart services
sudo systemctl restart php8.4-fpm
sudo systemctl restart apache2  # or nginx
```

### Database Optimization

```bash
# Optimize tables (MySQL)
mysql -u crm_user -p starz_crm -e "OPTIMIZE TABLE companies, contacts, leads, activities, rfqs, email_campaigns, webinars;"

# Analyze tables
php bin/console doctrine:query:sql "ANALYZE TABLE companies" --env=prod
```

---

## 📞 Support & Resources

### Documentation Location
All documentation is in `/var/www/crm-starz-morocco/Documentation/`:

- `PRODUCTION_DEPLOYMENT_GUIDE.md` - This file
- `PROJECT_STATUS.md` - System overview
- `LEADBOT_INTEGRATION.md` - Lead system details
- `CRAWLER_IMPLEMENTATION.md` - Webcrawler technical guide
- `LEADS_TO_COMPANIES_GUIDE.md` - User workflow guide
- `EMAIL_CAMPAIGN_QUICK_REFERENCE.md` - Email campaigns
- `QUICKSTART.md` - Quick start guide

### Log Files
- Application: `/var/www/crm-starz-morocco/var/log/prod.log`
- Apache: `/var/log/apache2/starz-crm-error.log`
- Nginx: `/var/log/nginx/starz-crm-error.log`
- PHP: `/var/log/php-errors.log`
- Backup: `/var/log/starz-crm-backup.log`

### Contact
- **Technical Issues**: IT Department
- **Application Support**: CRM Administrator
- **Emergency**: On-call technician

---

## ✅ Deployment Checklist

### Pre-Deployment
- [ ] Server meets minimum requirements
- [ ] Domain/DNS configured
- [ ] SSL certificate ready
- [ ] Database credentials prepared
- [ ] SMTP credentials available

### Installation
- [ ] Application user created
- [ ] Files uploaded/cloned
- [ ] Dependencies installed
- [ ] Permissions set correctly
- [ ] `.env.local` configured

### Database
- [ ] Database created
- [ ] User granted permissions
- [ ] Schema created
- [ ] Initial data imported (if applicable)
- [ ] Admin user created

### Web Server
- [ ] Virtual host configured
- [ ] Site enabled
- [ ] SSL certificate installed
- [ ] HTTPS redirect enabled
- [ ] Configuration tested

### Security
- [ ] File permissions hardened
- [ ] Firewall configured
- [ ] PHP settings secured
- [ ] Directory listing disabled
- [ ] Security headers added

### Testing
- [ ] Dashboard loads
- [ ] Login works
- [ ] All modules accessible
- [ ] Email sending works
- [ ] Database queries work
- [ ] HTTPS enforced
- [ ] Performance acceptable

### Production
- [ ] Backup script configured
- [ ] Monitoring enabled
- [ ] Logs rotating
- [ ] SSL auto-renewal tested
- [ ] Documentation accessible

---

**Deployment Complete!** 🎉

The Starz Morocco CRM system is now live and ready for production use.

**Version**: 1.0  
**Last Updated**: October 28, 2025  
**Status**: ✅ Production-Ready
