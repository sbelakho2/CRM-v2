# Starz Morocco CRM - Production Deployment Guide

**Version**: 1.0  
**Date**: October 28, 2025  
**For**: IT/DevOps Technician - Server Deployment  
**Platform**: Symfony 7.x, PHP 8.4+, SQLite/MySQL  
**Status**: Production-Ready  

---

## 📋 Table of Contents

1. [System Overview](#system-overview)
2. [Prerequisites](#prerequisites)
3. [Server Requirements](#server-requirements)
4. [Installation Steps](#installation-steps)
5. [Configuration](#configuration)
6. [Database Setup](#database-setup)
7. [Web Server Configuration](#web-server-configuration)
8. [Security Hardening](#security-hardening)
9. [SSL/HTTPS Setup](#ssl-https-setup)
10. [Email Configuration](#email-configuration)
11. [Backup & Monitoring](#backup--monitoring)
12. [Testing & Verification](#testing--verification)
13. [Troubleshooting](#troubleshooting)
14. [Maintenance](#maintenance)

---

## 🎯 System Overview

### What This System Does
**Starz Morocco CRM** is a comprehensive customer relationship management system for PCBA/EMS business operations with:

**Core Features:**
- 📊 **Company Management** - Track Morocco-focused PCBA/EMS buyers
- 👥 **Contact Management** - Procurement contacts with role tracking
- 📧 **Email Campaigns** - 5-touch automated sequences
- 🎯 **RFQ Pipeline** - Kanban-style opportunity tracking
- 📄 **Compliance Documents** - 21-document pack management
- 🎓 **Webinar System** - Virtual event management
- 💎 **Lead Discovery** - Multi-region provisional lead system (Morocco, US, EU, UK)
- 📈 **Dashboard & KPIs** - Real-time business intelligence

**Technology Stack:**
- **Backend**: Symfony 7.x (PHP Framework)
- **Frontend**: Twig templates with Tailwind CSS
- **Database**: SQLite (development) or MySQL/PostgreSQL (production recommended)
- **PHP Version**: 8.4.14+ required
- **Web Server**: Apache 2.4+ or Nginx 1.18+

**Users:** Sales team, operations staff, management  
**Data Scope:** Morocco + US + EU + UK markets  
**Language:** English with multilingual support (FR, DE, IT, ES, NL, PL, PT)

---

## 📦 Prerequisites

### Required Software

1. **PHP 8.4.14 or higher**
   ```bash
   php -v  # Should show 8.4.14 or later
   ```

2. **Composer** (PHP dependency manager)
   ```bash
   composer --version  # Should show 2.x
   ```

3. **Web Server**
   - Apache 2.4+ with mod_rewrite, OR
   - Nginx 1.18+

4. **Database** (choose one)
   - MySQL 8.0+ (recommended for production)
   - PostgreSQL 13+ (alternative)
   - SQLite 3.x (development only)

5. **PHP Extensions Required**
   ```bash
   php -m | grep -E 'pdo|pdo_mysql|pdo_sqlite|mbstring|xml|ctype|iconv|intl|json|tokenizer'
   ```
   
   Install if missing:
   ```bash
   # Ubuntu/Debian
   sudo apt install php8.4-cli php8.4-fpm php8.4-mysql php8.4-xml php8.4-mbstring php8.4-intl php8.4-curl php8.4-zip
   
   # CentOS/RHEL
   sudo yum install php84-cli php84-fpm php84-mysqlnd php84-xml php84-mbstring php84-intl php84-json
   ```

6. **Git** (for cloning repository)
   ```bash
   git --version
   ```

7. **SSL Certificate** (Let's Encrypt recommended)

---

## 🖥️ Server Requirements

### Minimum Specifications

| Resource | Minimum | Recommended |
|----------|---------|-------------|
| **CPU** | 2 cores | 4 cores |
| **RAM** | 4 GB | 8 GB |
| **Storage** | 20 GB | 50 GB SSD |
| **Bandwidth** | 100 Mbps | 1 Gbps |
| **OS** | Ubuntu 20.04+ / CentOS 8+ | Ubuntu 22.04 LTS |

### Network Requirements
- Port 80 (HTTP) - open
- Port 443 (HTTPS) - open
- Port 25/587 (SMTP) - open for email sending
- Firewall configured to allow web traffic
- Static IP address or domain name

### Domain Setup
- Domain/subdomain pointing to server IP
- Example: `crm.starz-morocco.com`
- DNS A record configured
- SSL certificate ready (Let's Encrypt or commercial)

---

## 🚀 Installation Steps

### Step 1: Create Application User

```bash
# Create dedicated user for the application
sudo useradd -m -s /bin/bash starzcrm
sudo passwd starzcrm

# Add to web server group
sudo usermod -a -G www-data starzcrm  # Apache/Nginx
```

### Step 2: Clone Repository

```bash
# Switch to application user
sudo su - starzcrm

# Clone to production directory
cd /var/www
git clone <repository-url> crm-starz-morocco
cd crm-starz-morocco

# Or if uploading files manually:
# Upload the entire crm-starz-morocco folder to /var/www/
```

### Step 3: Install Dependencies

```bash
cd /var/www/crm-starz-morocco

# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Verify installation
php bin/console --version
```

### Step 4: Set Permissions

```bash
# Set ownership
sudo chown -R starzcrm:www-data /var/www/crm-starz-morocco

# Set directory permissions
sudo chmod -R 755 /var/www/crm-starz-morocco

# Make var directory writable
sudo chmod -R 775 /var/www/crm-starz-morocco/var
sudo chmod -R 775 /var/www/crm-starz-morocco/public

# Verify
ls -la /var/www/crm-starz-morocco
```

---

## ⚙️ Configuration

### Step 1: Environment Configuration

```bash
cd /var/www/crm-starz-morocco

# Copy environment template
cp .env .env.local

# Edit production settings
nano .env.local
```

**Key Configuration Values:**

```bash
# .env.local

###> symfony/framework-bundle ###
APP_ENV=prod
APP_SECRET=<GENERATE_RANDOM_SECRET_HERE>
###< symfony/framework-bundle ###

###> doctrine/doctrine-bundle ###
# For MySQL (RECOMMENDED)
DATABASE_URL="mysql://crm_user:STRONG_PASSWORD@localhost:3306/starz_crm?serverVersion=8.0&charset=utf8mb4"

# For PostgreSQL (ALTERNATIVE)
# DATABASE_URL="postgresql://crm_user:STRONG_PASSWORD@localhost:5432/starz_crm?serverVersion=15&charset=utf8"

# For SQLite (NOT RECOMMENDED FOR PRODUCTION)
# DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
###< doctrine/doctrine-bundle ###

###> symfony/mailer ###
# SMTP Configuration (use your email provider)
MAILER_DSN=smtp://username:password@smtp.gmail.com:587
# OR for SendGrid: smtp://apikey:YOUR_API_KEY@smtp.sendgrid.net:587
# OR for Mailgun: mailgun://KEY:DOMAIN@default?region=us
###< symfony/mailer ###

# Application Settings
SITE_NAME="Starz Morocco CRM"
SITE_URL="https://crm.starz-morocco.com"
ADMIN_EMAIL="admin@starz-morocco.com"
SUPPORT_EMAIL="support@starz-morocco.com"
```

**Generate APP_SECRET:**
```bash
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
# Copy output to APP_SECRET in .env.local
```

### Step 2: Clear Cache

```bash
# Clear and warm up cache for production
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

---

## 🗄️ Database Setup

### Option A: MySQL (Recommended)

```bash
# 1. Login to MySQL
sudo mysql -u root -p

# 2. Create database and user
CREATE DATABASE starz_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'crm_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON starz_crm.* TO 'crm_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# 3. Update .env.local with MySQL credentials
# DATABASE_URL="mysql://crm_user:STRONG_PASSWORD_HERE@localhost:3306/starz_crm?serverVersion=8.0&charset=utf8mb4"

# 4. Create database schema
cd /var/www/crm-starz-morocco
php bin/console doctrine:schema:create --env=prod

# 5. Verify tables created
php bin/console doctrine:schema:validate --env=prod
```

### Option B: PostgreSQL (Alternative)

```bash
# 1. Switch to postgres user
sudo -u postgres psql

# 2. Create database and user
CREATE DATABASE starz_crm;
CREATE USER crm_user WITH ENCRYPTED PASSWORD 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON DATABASE starz_crm TO crm_user;
\q

# 3. Update .env.local with PostgreSQL credentials
# DATABASE_URL="postgresql://crm_user:STRONG_PASSWORD_HERE@localhost:5432/starz_crm?serverVersion=15&charset=utf8"

# 4. Create database schema
php bin/console doctrine:schema:create --env=prod
```

### Step 3: Import Initial Data (Optional)

If you have existing data from Tracker.xlsx:

```bash
# 1. Convert Excel to CSV (on local machine with Excel)
# Open Tracker.xlsx → Save As → CSV

# 2. Upload CSV to server
scp Tracker.csv starzcrm@server:/var/www/crm-starz-morocco/

# 3. Import data
cd /var/www/crm-starz-morocco
php bin/console app:import-tracker Tracker.csv --env=prod
```

### Step 4: Create Admin User

```bash
# Create first admin user
php bin/console app:create-user --env=prod

# Follow prompts:
# Email: admin@starz-morocco.com
# Password: <secure-password>
# Role: ROLE_ADMIN
```

---

## 🌐 Web Server Configuration

### Option A: Apache Configuration

**1. Create Virtual Host File:**

```bash
sudo nano /etc/apache2/sites-available/starz-crm.conf
```

**Content:**

```apache
<VirtualHost *:80>
    ServerName crm.starz-morocco.com
    ServerAlias www.crm.starz-morocco.com
    
    DocumentRoot /var/www/crm-starz-morocco/public
    DirectoryIndex index.php
    
    <Directory /var/www/crm-starz-morocco/public>
        AllowOverride All
        Require all granted
        FallbackResource /index.php
    </Directory>
    
    # Logging
    ErrorLog ${APACHE_LOG_DIR}/starz-crm-error.log
    CustomLog ${APACHE_LOG_DIR}/starz-crm-access.log combined
    
    # PHP Settings
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/var/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
```

**2. Enable Site & Modules:**

```bash
# Enable required modules
sudo a2enmod rewrite
sudo a2enmod proxy_fcgi
sudo a2enmod setenvif

# Enable PHP-FPM
sudo a2enconf php8.4-fpm

# Enable site
sudo a2ensite starz-crm.conf

# Disable default site
sudo a2dissite 000-default.conf

# Test configuration
sudo apache2ctl configtest

# Restart Apache
sudo systemctl restart apache2
```

### Option B: Nginx Configuration

**1. Create Server Block:**

```bash
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

### Step 1: Configure SMTP

Already done in `.env.local`:
```bash
MAILER_DSN=smtp://username:password@smtp.gmail.com:587
```

### Step 2: Test Email Sending

```bash
# Test email functionality
php bin/console app:test-email admin@starz-morocco.com --env=prod
```

### Step 3: Configure Email Templates

Edit `config/packages/mailer.yaml` if needed:

```yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
        envelope:
            sender: 'crm@starz-morocco.com'
```

---

## 💾 Backup & Monitoring

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
