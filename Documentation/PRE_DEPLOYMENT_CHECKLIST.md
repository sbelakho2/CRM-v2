# Pre-Deployment Checklist - December 8, 2025

## 📋 System Status Overview

**Project**: Starz Morocco CRM v2  
**Status**: ✅ READY FOR PRODUCTION DEPLOYMENT  
**Date**: December 8, 2025  
**Compilation Errors**: 0  
**Critical Issues**: 0  

---

## ✅ Completed Development Tasks

### Core CRM Features
- ✅ Company Management (Morocco + EU + US + UK regions)
- ✅ Contact Management with procurement roles
- ✅ Activity Tracking and logging
- ✅ RFQ Pipeline with kanban view
- ✅ Email Campaign system (5-touch sequences)
- ✅ Webinar management and attendee tracking
- ✅ Supplier Portal registration tracking
- ✅ Compliance Document management (21-doc pack)
- ✅ Dashboard with KPIs and analytics
- ✅ Lead management and review system

### Advanced Features (December 2025)
- ✅ **Contextual Guidance System**
  - 15+ smart notification methods
  - Auto-dismiss on action completion
  - Permanent dismissal tracking
  - Daily workflow reminders
  - Max 3 notifications display with "View All" page
  
- ✅ **Google Custom Search Integration**
  - Lead discovery via Google Custom Search API
  - Web-based search interface
  - Command-line search tool
  - Quota tracking and cost estimation
  - Duplicate prevention
  - Selective import (choose results)
  
- ✅ **Test Data Generation**
  - Complete test data command
  - Test user: test@starz.ma / test123
  - 15 companies, 30 contacts, 50 activities
  - 20 leads, 10 RFQs, 25 compliance docs

### Webcrawler System
- ✅ Lead Scoring Engine (7 weighted signals)
- ✅ Config-driven YAML setup
- ✅ GDPR-compliant email extraction
- ✅ Deduplication (Jaro-Winkler ≥0.92)
- ✅ Human-in-the-loop review UI
- ✅ Daily scheduler support
- ✅ CRM sync integration

---

## 🔍 Pre-Deployment Verification

### 1. Code Quality
- ✅ **Compilation Errors**: 0 errors found
- ✅ **Type Hints**: All nullable parameters explicit
- ✅ **PSR Standards**: Code follows Symfony best practices
- ✅ **Security**: CSRF, XSS, SQL injection protections in place

### 2. Database
- ✅ **Schema Validated**: All entities properly mapped
- ✅ **Migrations**: All migrations applied successfully
- ✅ **Relationships**: Foreign keys and associations correct
- ✅ **Indexes**: Optimized for performance

### 3. Configuration
- ✅ **Environment Variables**: All required vars documented in .env
- ⚠️ **Production .env**: Needs configuration (see below)
- ✅ **Service Registration**: All services in services.yaml
- ✅ **Routes**: All routes properly defined

### 4. Dependencies
- ✅ **Composer**: All packages installed and compatible
- ✅ **PHP Version**: 8.4.14+ required
- ✅ **Extensions**: pdo, mysql, xml, mbstring, intl, curl, zip
- ✅ **HTTP Client**: Symfony HTTP Client installed for Google API

### 5. Testing
- ✅ **Manual Testing**: All major features tested
- ✅ **Test Data**: Generation command working
- ✅ **Error Handling**: Graceful degradation implemented
- ✅ **User Flows**: Complete workflows verified

### 6. Documentation
- ✅ **User Guides**: QUICKSTART.md, WEBCRAWLER_README.md
- ✅ **Technical Docs**: CRAWLER_IMPLEMENTATION.md, GOOGLE_SEARCH_SETUP.md
- ✅ **Deployment Guide**: PRODUCTION_DEPLOYMENT_GUIDE.md (971 lines)
- ✅ **API Setup**: Complete Google API setup instructions

---

## ⚠️ Required Actions Before Deployment

### Critical Configuration (MUST DO)

#### 1. Environment Configuration
Create `.env.local` for production with:

```env
###> symfony/framework-bundle ###
APP_ENV=prod
APP_SECRET=CHANGE_ME_generate_a_fresh_secret
###< symfony/framework-bundle ###

###> doctrine/doctrine-bundle ###
DATABASE_URL="mysql://username:password@127.0.0.1:3306/crm_production?serverVersion=8.0"
###< doctrine/doctrine-bundle ###

###> symfony/mailer ###
MAILER_DSN=smtp://CHANGE_ME@CHANGE_ME:1025
MAILER_FROM_ADDRESS=noreply@starz.ma
MAILER_FROM_NAME="Starz Morocco CRM"
###< symfony/mailer ###

###> Google Custom Search API ###
GOOGLE_API_KEY=CHANGE_ME_google_api_key
GOOGLE_SEARCH_ENGINE_ID=your_search_engine_cx_id_here
###< Google Custom Search API ###
```

#### 2. Google Custom Search API Setup
Follow `Documentation/GOOGLE_SEARCH_SETUP.md`:
- [ ] Create Google Cloud project
- [ ] Enable Custom Search API
- [ ] Create API key with restrictions
- [ ] Create Custom Search Engine (cx ID)
- [ ] Configure search to "Search entire web"
- [ ] Add credentials to .env.local
- [ ] Test with dry-run search command

#### 3. Database Migration
```bash
# Production database
php bin/console doctrine:migrations:migrate --no-interaction

# Verify schema
php bin/console doctrine:schema:validate
```

#### 4. Cache and Permissions
```bash
# Clear and warm cache
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

# Set permissions
chmod -R 755 var/cache var/log
chown -R www-data:www-data var/cache var/log
```

#### 5. Create Admin User
```bash
php bin/console app:create-admin
# Follow prompts to create production admin account
```

---

## 🔐 Security Checklist

### Application Security
- ✅ **CSRF Protection**: Enabled in forms
- ✅ **XSS Protection**: Twig auto-escaping enabled
- ✅ **SQL Injection**: Using Doctrine ORM parameterized queries
- ✅ **Password Hashing**: Using Symfony's password hasher
- ⚠️ **APP_SECRET**: MUST generate new secret for production
- ⚠️ **HTTPS**: MUST configure SSL/TLS certificate

### API Security
- ⚠️ **Google API Key**: MUST restrict to server IP/domain
- ✅ **API Rate Limiting**: Built into GoogleSearchService
- ✅ **Error Handling**: No sensitive data in error messages
- ✅ **Session Security**: Secure cookies in production

### Server Security
- [ ] Firewall configured (ports 80, 443 only)
- [ ] SSH key-based authentication
- [ ] Fail2ban installed and configured
- [ ] Regular security updates scheduled
- [ ] Backup system configured

---

## 🚀 Deployment Steps

### 1. Server Preparation
```bash
# Install PHP 8.4+ and extensions
sudo apt update
sudo apt install php8.4-cli php8.4-fpm php8.4-mysql php8.4-xml php8.4-mbstring php8.4-intl php8.4-curl php8.4-zip

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install MySQL/MariaDB
sudo apt install mysql-server
```

### 2. Code Deployment
```bash
# Clone repository
git clone <repository-url> /var/www/crm
cd /var/www/crm

# Install dependencies (production only)
composer install --no-dev --optimize-autoloader

# Set permissions
sudo chown -R www-data:www-data .
sudo chmod -R 755 var
```

### 3. Database Setup
```bash
# Create database
mysql -u root -p
CREATE DATABASE crm_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'crm_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON crm_production.* TO 'crm_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Run migrations
php bin/console doctrine:migrations:migrate --no-interaction
```

### 4. Web Server Configuration

**Nginx** (`/etc/nginx/sites-available/crm`):
```nginx
server {
    listen 80;
    server_name crm.starz.ma;
    root /var/www/crm/public;

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
}
```

**Apache** (`.htaccess` already configured)

### 5. SSL/HTTPS Setup
```bash
# Install Certbot
sudo apt install certbot python3-certbot-nginx

# Get certificate
sudo certbot --nginx -d crm.starz.ma

# Auto-renewal
sudo certbot renew --dry-run
```

### 6. Cron Jobs
Add to crontab (`crontab -e`):
```cron
# Clear old sessions daily
0 2 * * * cd /var/www/crm && php bin/console cache:pool:clear cache.global_clearer

# Optional: Daily Google Search for new leads
0 9 * * * cd /var/www/crm && php bin/console app:search-companies "aerospace morocco" --limit=10 --import >> /var/log/crm-search.log 2>&1
```

---

## 🧪 Post-Deployment Testing

### Smoke Tests
```bash
# 1. Check environment
php bin/console about

# 2. Verify database connection
php bin/console doctrine:schema:validate

# 3. Test routes
php bin/console debug:router | grep -E "dashboard|company|lead"

# 4. Check services
php bin/console debug:container GoogleSearchService
php bin/console debug:container GuidanceNotificationService
```

### Manual Testing Checklist
- [ ] Login with admin account
- [ ] Dashboard loads without errors
- [ ] Create new company
- [ ] Add contact to company
- [ ] Log activity
- [ ] Create RFQ
- [ ] View guidance notifications
- [ ] Test lead discovery (if Google API configured)
- [ ] Check all navigation links
- [ ] Verify email sending (test campaign)

---

## 📊 Monitoring Setup

### Application Monitoring
- [ ] Configure error logging to `/var/log/crm/`
- [ ] Set up log rotation
- [ ] Monitor disk space usage
- [ ] Track database performance

### API Monitoring (Google Search)
- [ ] Monitor API quota usage
- [ ] Track API costs
- [ ] Set up billing alerts in Google Cloud Console
- [ ] Log all API calls for audit

---

## 🔄 Backup Strategy

### Database Backups
```bash
# Daily backup script
#!/bin/bash
mysqldump -u crm_user -p'password' crm_production > /backups/crm_$(date +%Y%m%d).sql
find /backups -name "crm_*.sql" -mtime +7 -delete
```

### File Backups
- [ ] Backup `/var/www/crm/var/` directory
- [ ] Backup `.env.local` configuration
- [ ] Backup uploaded files (if any)

---

## 📝 Known Limitations & Workarounds

### Google Custom Search API
- **Free Tier**: 100 queries/day limit
- **Workaround**: Enable billing for additional queries ($5/1000)
- **Status**: Configuration ready, credentials needed

### Guidance Notifications
- **Daily Reminders**: Generated once per day maximum
- **Reason**: Performance optimization to prevent dashboard timeouts
- **Status**: Working as designed

### Test Data
- **Purpose**: Development and QA only
- **Action**: Do NOT run `app:generate-test-data` in production
- **Status**: Command available but should not be used in prod

---

## ✅ Final Deployment Approval

### Code Quality: ✅ APPROVED
- 0 compilation errors
- All type hints explicit
- PSR standards followed
- Security measures in place

### Features: ✅ COMPLETE
- All core CRM features operational
- Guidance system working
- Google Search integration ready
- Webcrawler system documented
- Test data generation working

### Documentation: ✅ COMPLETE
- 8 comprehensive guides
- API setup instructions
- Deployment guide (971 lines)
- Troubleshooting documentation

### Security: ⚠️ REQUIRES CONFIGURATION
- Application security: ✅ Implemented
- API security: ⚠️ Credentials needed
- Server security: ⚠️ Production setup required
- SSL/HTTPS: ⚠️ Certificate needed

### Database: ✅ READY
- Schema validated
- Migrations prepared
- Performance optimized
- Backup strategy documented

---

## 🚦 Deployment Status: READY WITH CONDITIONS

### Can Deploy Now
- ✅ Core CRM system
- ✅ Guidance notifications
- ✅ Webcrawler integration
- ✅ All basic features

### Requires Configuration Before Full Operation
- ⚠️ Google Custom Search API credentials
- ⚠️ Production .env.local configuration
- ⚠️ SSL certificate
- ⚠️ Database credentials
- ⚠️ Email server (SMTP)

### Recommended Timeline
1. **Day 1**: Deploy core system without Google API
2. **Day 2-3**: Configure Google API credentials
3. **Week 1**: Monitor and optimize
4. **Week 2+**: Full operation with all features

---

## 📞 Support Resources

### Documentation
- `Documentation/PRODUCTION_DEPLOYMENT_GUIDE.md` - Complete deployment steps
- `Documentation/GOOGLE_SEARCH_SETUP.md` - Google API setup
- `Documentation/QUICKSTART.md` - Quick start guide

### Commands Reference
```bash
# View all available commands
php bin/console list app

# Check system status
php bin/console about

# Validate configuration
php bin/console debug:config
```

### Log Locations
- Application: `/var/www/crm/var/log/prod.log`
- Web Server: `/var/log/nginx/` or `/var/log/apache2/`
- Database: `/var/log/mysql/`

---

## 🎯 Success Metrics Post-Deployment

### Week 1 Goals
- [ ] Zero critical errors in logs
- [ ] All users can login and navigate
- [ ] Dashboard loads in <2 seconds
- [ ] Database queries optimized

### Month 1 Goals
- [ ] Google Search API integrated and tested
- [ ] 10+ new leads discovered via API
- [ ] Guidance notifications reducing support tickets
- [ ] User adoption rate >80%

---

**Prepared by**: Development Team  
**Date**: December 8, 2025  
**Status**: ✅ READY FOR PRODUCTION DEPLOYMENT  
**Next Action**: Configure production environment and deploy  

**APPROVED FOR DEPLOYMENT**: YES (with configuration requirements noted above)
