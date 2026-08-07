# Distribution Package - Deployment Instructions

## 📦 Package Information

**File**: `starz-crm-production-v1.0.zip`  
**Size**: ~1 MB (source code only, no dependencies)  
**Version**: 1.0  
**Created**: October 29, 2025  
**Status**: Production-Ready  

---

## 🎯 For the System Administrator

This ZIP file contains everything needed to deploy the Starz Morocco CRM to your production server.

### What's Included

✅ **Complete Application Code**
- Symfony 7.x application structure
- All PHP source files (src/)
- Configuration files (config/)
- Database entity definitions
- UI templates (templates/)
- Public web files (public/)

✅ **Complete Documentation** (15 files, ~180 pages)
- `Documentation/PRODUCTION_DEPLOYMENT_GUIDE.md` ← **START HERE**
- `Documentation/SYSTEM_OVERVIEW.md` - Technical overview
- `Documentation/README.md` - Documentation index
- All user and technical guides

✅ **Deployment Essentials**
- `INSTALLATION.txt` - Quick start guide
- `.env.example` - Environment configuration template
- Database migration files
- Email templates
- Lead system configuration (459-line config file)

### What's NOT Included (Install on Server)

❌ **PHP Dependencies** (`vendor/` folder)
- Install with: `composer install --no-dev --optimize-autoloader`
- Composer will download ~20MB of Symfony and other packages

❌ **Development Files**
- node_modules/
- Test files
- Development cache
- Local database files

---

## 🚀 Quick Deployment Steps

### 1. Transfer to Server

```bash
# Upload ZIP to server
scp starz-crm-production-v1.0.zip user@your-server:/tmp/

# SSH into server
ssh user@your-server
```

### 2. Extract Files

```bash
# Extract to web directory
cd /var/www
sudo unzip /tmp/starz-crm-production-v1.0.zip -d crm-starz-morocco

# Set ownership
sudo chown -R www-data:www-data crm-starz-morocco
```

### 3. Install Dependencies

```bash
cd /var/www/crm-starz-morocco

# Install Composer (if not installed)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install PHP dependencies
composer install --no-dev --optimize-autoloader
```

### 4. Configure Environment

```bash
# Copy environment template
cp .env.example .env.local

# Edit with production settings
nano .env.local

# Required changes:
# - APP_ENV=prod
# - APP_SECRET=CHANGE_ME_generate_a_fresh_secret 32-char random string>
# - DATABASE_URL=mysql://user:pass@localhost:3306/starz_crm
# - MAILER_DSN=smtp://CHANGE_ME@CHANGE_ME:1025
```

### 5. Set Up Database

```bash
# Create database
mysql -u root -p
CREATE DATABASE starz_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'crm_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON starz_crm.* TO 'crm_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Run migrations
php bin/console doctrine:schema:create --env=prod
```

### 6. Configure Web Server

See `Documentation/PRODUCTION_DEPLOYMENT_GUIDE.md` Section 7 for:
- Apache virtual host configuration
- Nginx server block configuration
- SSL/HTTPS setup with Let's Encrypt

### 7. Set Permissions

```bash
chmod -R 755 /var/www/crm-starz-morocco
chmod -R 775 /var/www/crm-starz-morocco/var
chmod 600 /var/www/crm-starz-morocco/.env.local
```

### 8. Clear Cache

```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

### 9. Test Deployment

Visit `https://your-domain.com` and verify:
- ✅ Dashboard loads
- ✅ Login page works
- ✅ All modules accessible

---

## 📖 Complete Documentation

**After extracting, read these files in order:**

1. **INSTALLATION.txt** - Quick start (inside ZIP)
2. **Documentation/PRODUCTION_DEPLOYMENT_GUIDE.md** - Complete deployment guide
3. **Documentation/SYSTEM_OVERVIEW.md** - System architecture
4. **Documentation/README.md** - Documentation index

---

## ⏱️ Estimated Deployment Time

- **Experienced sysadmin**: 2-4 hours
- **First-time deployment**: 4-6 hours

---

## 🆘 Need Help?

All answers are in the documentation:

| Question | See Document |
|----------|--------------|
| How do I install this? | Documentation/PRODUCTION_DEPLOYMENT_GUIDE.md |
| What are the server requirements? | Documentation/PRODUCTION_DEPLOYMENT_GUIDE.md (Section 3) |
| How do I configure Apache/Nginx? | Documentation/PRODUCTION_DEPLOYMENT_GUIDE.md (Section 7) |
| How do I set up SSL? | Documentation/PRODUCTION_DEPLOYMENT_GUIDE.md (Section 9) |
| What does this system do? | Documentation/SYSTEM_OVERVIEW.md |
| How do I troubleshoot issues? | Documentation/PRODUCTION_DEPLOYMENT_GUIDE.md (Section 13) |

---

## ✅ Deployment Checklist

The complete 28-item deployment checklist is in:  
`Documentation/PRODUCTION_DEPLOYMENT_GUIDE.md` (bottom of file)

Quick checklist:
- [ ] Server meets requirements (PHP 8.4+, MySQL 8+)
- [ ] Files extracted to /var/www/crm-starz-morocco
- [ ] Dependencies installed (composer install)
- [ ] .env.local configured with production settings
- [ ] Database created and migrated
- [ ] Web server configured (Apache/Nginx)
- [ ] SSL certificate installed
- [ ] Permissions set correctly
- [ ] Cache cleared
- [ ] System tested and verified

---

## 📊 What You're Getting

### System Features

- **Company Management** - 100 companies already imported from Tracker.xlsx
- **Lead Discovery** - Multi-region lead system (Morocco, US, EU, UK)
- **Lead Scoring** - 0-100 scoring with 13 weighted signals
- **RFQ Pipeline** - Quote opportunity tracking
- **Email Campaigns** - 5-touch automated sequences
- **Compliance Documents** - 21-document pack management
- **Webinars** - Virtual event management
- **Dashboard** - Real-time KPIs and analytics

### Technical Stack

- Symfony 7.x (PHP framework)
- Doctrine ORM (database layer)
- Twig (templating engine)
- MySQL/PostgreSQL support
- Responsive web interface

---

## 🔒 Security Notes

The `.env.local` file contains sensitive credentials. Make sure to:

1. ✅ Set `APP_ENV=prod` (not dev or test)
2. ✅ Generate a unique 32-character `APP_SECRET`
3. ✅ Use strong database passwords
4. ✅ Set file permissions: `chmod 600 .env.local`
5. ✅ Never commit `.env.local` to version control
6. ✅ Enable HTTPS with valid SSL certificate
7. ✅ Configure firewall to allow only ports 80, 443, 22

---

## 📞 Post-Deployment

After successful deployment:

1. **Create Admin User**:
   ```bash
   php bin/console app:create-user --env=prod
   ```

2. **Set Up Backups** - See deployment guide Section 11

3. **Configure Monitoring** - See deployment guide Section 11

4. **Test All Features**:
   - Login
   - View companies (100 should be there)
   - Access lead review page
   - Test email sending
   - Verify all routes work

---

## 🎓 Training Your Team

User documentation is included:

- `Documentation/QUICKSTART.md` - For end users
- `Documentation/LEADS_TO_COMPANIES_GUIDE.md` - Lead workflow guide
- `Documentation/EMAIL_CAMPAIGN_QUICK_REFERENCE.md` - Email campaigns

---

**Ready to deploy!** Start with extracting the ZIP and reading `INSTALLATION.txt` and the full deployment guide.

**Version**: 1.0  
**Status**: ✅ Production-Ready  
**Support**: All documentation included in ZIP
