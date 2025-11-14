# CRM-v2 Configuration Setup Guide

**Last Updated:** November 3, 2025  
**Status:** Setup Instructions  
**Audience:** Developers, DevOps

---

## Overview

This guide walks you through the complete configuration of CRM-v2 for local development, staging, and production environments.

---

## ✅ Configuration Checklist

- [ ] **Database Configuration** - Connect to MySQL/PostgreSQL
- [ ] **Email/Mailer Setup** - Configure SMTP for sending emails
- [ ] **Messenger Configuration** - Enable background job processing
- [ ] **Supplier API Keys** - (Optional) Set up Quote Co-Pilot
- [ ] **Environment Variables** - Verify all configs in `.env.local`
- [ ] **Database Setup** - Create database and run migrations
- [ ] **Assets Build** - Compile frontend assets
- [ ] **Test Server** - Start and verify application

---

## 1. Database Configuration

### 1.1 Verify MySQL Connection

The `.env.local` file is already configured with:

```env
DATABASE_URL="mysql://root:Formation2020*@127.0.0.1:3306/starz_crm?serverVersion=8&charset=utf8mb4"
```

**To test the connection:**

```powershell
# Test MySQL is running and accessible
mysql -h 127.0.0.1 -u root -p"Formation2020*" -e "SELECT VERSION();"
```

If this fails, update the credentials in `.env.local` with your actual MySQL credentials.

### 1.2 Create Database and Run Migrations

```powershell
cd c:\Users\khaoula\Desktop\CRM-v2

# Create the database
php bin/console doctrine:database:create

# Run all pending migrations
php bin/console doctrine:migrations:migrate
```

**Expected Output:**

```
Creating database "starz_crm"...
Database created successfully.

Doctrine Migrations

                    Migration

[notice] Executing 1_0 (2025-10-28 21:43:33)
[notice] Executing 2_0 (2025-10-29 09:08:52)
[notice] Executing 3_0 (2025-10-29 15:10:00)
[notice] Executing 4_0 (2025-10-29 15:40:00)
[notice] Executing 5_0 (2025-10-30 12:00:00)
[notice] Executing 6_0 (2025-11-03 08:16:52)

[notice] finished in 2.45s, used 43M memory
```

### 1.3 Verify Database Schema

```powershell
# Check if all tables were created
php bin/console doctrine:schema:validate
```

**Expected Output:**

```
[OK] The schema is in sync with the database.
```

---

## 2. Email/Mailer Configuration

### 2.1 Current Configuration

The `.env.local` is pre-configured with Infomaniak SMTP:

```env
MAILER_DSN=smtp://support@starzcloud.ovh:password@smtp.infomaniak.com:587
```

### 2.2 Testing Email Configuration

To test if email sending works:

```powershell
# Send a test email via Symfony
php bin/console mailer:test admin@crm-starz.dev
```

### 2.3 Alternative Email Providers

Choose one that fits your needs:

**Option A: MailHog (Recommended for Local Development)**

MailHog is a local email testing tool that intercepts emails without sending them.

1. Download MailHog from https://github.com/mailhog/MailHog/releases
2. Run it:
   ```powershell
   ./MailHog.exe
   # MailHog now runs at http://127.0.0.1:1025 (SMTP) and http://127.0.0.1:8025 (Web UI)
   ```
3. Update `.env.local`:
   ```env
   MAILER_DSN=smtp://localhost:1025
   ```
4. Now all emails sent by the app will be captured in the MailHog UI at `http://127.0.0.1:8025`

**Option B: SendGrid**

1. Create a free account at https://sendgrid.com/
2. Get your API key from Settings → API Keys
3. Update `.env.local`:
   ```env
   MAILER_DSN=sendgrid://YOUR_SENDGRID_API_KEY@default
   ```

**Option C: Gmail**

1. Enable 2-factor authentication on your Gmail account
2. Generate an App Password: https://myaccount.google.com/apppasswords
3. Update `.env.local`:
   ```env
   MAILER_DSN=smtp://your_email@gmail.com:YOUR_APP_PASSWORD@smtp.gmail.com:587
   ```

### 2.4 Verify Email Configuration

```powershell
# Clear cache to pick up new MAILER_DSN
php bin/console cache:clear
```

---

## 3. Messenger/Background Jobs Configuration

### 3.1 Current Configuration

The `.env.local` is already configured to use the Doctrine transport:

```env
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
```

This uses your database to queue background tasks like:

- Email campaign sending
- Notification generation
- Data imports

### 3.2 Running the Message Consumer

To process queued jobs, run the messenger consumer in a separate terminal:

```powershell
# Run the messenger worker (processes jobs as they arrive)
php bin/console messenger:consume -vvv

# To run only 10 messages then exit:
php bin/console messenger:consume --limit=10

# To run for 5 minutes then exit:
php bin/console messenger:consume --time-limit=300
```

**Expected Output:**

```
[2025-11-03 10:30:15] Starting messenger:consume on the default transport.
[2025-11-03 10:30:15] Retrying handler "App\MessageHandler\EmailCampaignHandler" with 0 retries left
[2025-11-03 10:30:17] Message handled: EmailCampaignMessage (1 ms)
```

### 3.3 For Production: Use Redis

For production, replace the Doctrine transport with Redis for better performance:

1. Install Redis (Linux/Mac) or WSL (Windows)
2. Start Redis:
   ```powershell
   # Windows: Use WSL2 subsystem or Docker
   # redis-server
   ```
3. Update `.env.local`:
   ```env
   MESSENGER_TRANSPORT_DSN=redis://localhost:6379/messages
   ```

---

## 4. Supplier API Keys (Quote Co-Pilot Feature)

### 4.1 Optional Configuration

The Quote Co-Pilot feature requires API keys from component suppliers. This is **optional** for development.

### 4.2 Getting Free Sandbox Keys

**For Development/Testing:**

1. **Nexar** (Free sandbox account)

   - Sign up: https://developer.nexar.com/
   - Get API key from dashboard
   - Add to `.env.local`:
     ```env
     NEXAR_API_KEY=your_nexar_sandbox_key_here
     ```

2. **Mouser** (Free API key)

   - Sign up: https://www.mouser.com/api/
   - Request API key
   - Add to `.env.local`:
     ```env
     MOUSER_API_KEY=your_mouser_api_key_here
     ```

3. **Digi-Key** (Free OAuth2)
   - Sign up: https://developer.digikey.com/
   - Create OAuth2 credentials
   - Add to `.env.local`:
     ```env
     DIGIKEY_CLIENT_ID=your_client_id_here
     DIGIKEY_CLIENT_SECRET=your_client_secret_here
     ```

### 4.3 Testing Quote Co-Pilot

If you haven't added the API keys, you can still test with mock data:

```env
# In .env.local, add:
QUOTE_COPILOT_MOCK=1
```

This will return dummy component prices for testing without needing real API keys.

---

## 5. Verify All Configuration

### 5.1 Check Environment Variables

```powershell
# List all environment variables used by the app
php bin/console debug:dotenv

# Check if API keys are loaded
php bin/console config:dump framework | findstr mailer

# List all routes
php bin/console debug:router
```

### 5.2 Test Database Connection

```powershell
# Execute a test query
php bin/console doctrine:query:sql "SELECT VERSION();"
```

### 5.3 Clear Cache (Important!)

```powershell
# Clear all caches after making changes to .env.local
php bin/console cache:clear --env=dev
```

---

## 6. Database Setup & Seeding

### 6.1 Create Test Admin User

```powershell
# Create an admin account for testing
php bin/console app:create-user --email=admin@crm-starz.dev --password=password123 --role=ROLE_ADMIN
```

### 6.2 Import Sample Data (Optional)

If you have a `tracker_template.csv` file:

```powershell
# Import companies and contacts from CSV
php bin/console app:import-tracker tracker_template.csv
```

### 6.3 Seed Demo Notifications (Optional)

```powershell
# Generate sample notification data for testing
php bin/console app:check-notifications --force-demo
```

---

## 7. Build Frontend Assets

### 7.1 Install Node Dependencies

```powershell
# Install npm packages
npm install
```

**Expected Output:**

```
added 456 packages in 2m
```

### 7.2 Build Assets for Development

```powershell
# Build frontend assets (CSS, JavaScript, images)
npm run dev
```

**Expected Output:**

```
▲ [webpack 5.x.x] compiled successfully (xxx ms)
```

### 7.3 Watch Mode (Optional)

For active development, run assets in watch mode:

```powershell
# Run in separate terminal - rebuilds on file changes
npm run watch
```

---

## 8. Start Development Server

### 8.1 Using Symfony CLI (Recommended)

```powershell
# Start the HTTPS dev server
symfony server:start -d

# Show logs in real-time
symfony server:log
```

**Expected Output:**

```
Web server listening on https://127.0.0.1:8000
...
```

Then visit: **https://127.0.0.1:8000**

### 8.2 Using Built-in PHP Server

```powershell
# Alternative: Use PHP's built-in server
php -S 127.0.0.1:8000 -t public
```

Then visit: **http://127.0.0.1:8000**

---

## 9. Test the Application

### 9.1 Login

1. Navigate to `http://127.0.0.1:8000/login`
2. Login with:
   - Email: `admin@crm-starz.dev`
   - Password: `password123` (or what you set)

### 9.2 Verify Features

1. **Dashboard** - http://127.0.0.1:8000/ (should show KPIs)
2. **Companies** - http://127.0.0.1:8000/companies (should be empty or show imported data)
3. **Contacts** - http://127.0.0.1:8000/contacts (should be empty or show imported data)
4. **Email Campaigns** - http://127.0.0.1:8000/email-campaigns (should show feature)
5. **Leads** - http://127.0.0.1:8000/leads (should show lead management UI)

### 9.3 Check Logs

```powershell
# View recent log entries
Get-Content var/log/dev.log -Tail 20

# Or tail in real-time:
Get-Content var/log/dev.log -Wait
```

---

## 10. Run Background Jobs (Messenger)

### 10.1 Start Messenger Consumer

In a **separate terminal**, run:

```powershell
# Process queued messages (email campaigns, notifications, etc.)
php bin/console messenger:consume -vvv
```

This must run continuously on production for async tasks to work.

---

## 11. Configuration for Different Environments

### Development (.env.local)

```env
APP_ENV=dev
APP_DEBUG=1
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
MAILER_DSN=smtp://localhost:1025  # MailHog for testing
```

### Staging (.env.staging)

```env
APP_ENV=staging
APP_DEBUG=0
MESSENGER_TRANSPORT_DSN=redis://redis-staging:6379/messages
MAILER_DSN=sendgrid://your_staging_key@default
DATABASE_URL="mysql://staging_user:password@mysql-staging:3306/starz_crm_staging"
```

### Production (.env.prod)

```env
APP_ENV=prod
APP_DEBUG=0
MESSENGER_TRANSPORT_DSN=redis://redis-prod:6379/messages
MAILER_DSN=sendgrid://your_prod_key@default
DATABASE_URL="mysql://prod_user:secure_password@mysql-prod:3306/starz_crm_prod"
NEXAR_API_KEY=your_production_key
# ... other production keys from secure vault
```

---

## 12. Troubleshooting

### Issue: "Database connection failed"

**Solution:**

```powershell
# Check MySQL is running
netstat -an | findstr 3306

# Verify credentials in .env.local
# Test connection:
mysql -h 127.0.0.1 -u root -p"Formation2020*" -e "SELECT 1;"
```

### Issue: "MAILER_DSN is invalid"

**Solution:**

```powershell
# Clear cache and try again
php bin/console cache:clear

# Test email configuration
php bin/console mailer:test admin@crm-starz.dev
```

### Issue: "Assets not loading (404 errors)"

**Solution:**

```powershell
# Rebuild assets
npm run dev

# Clear cache
php bin/console cache:clear
```

### Issue: "Migrations fail"

**Solution:**

```powershell
# Check migration status
php bin/console doctrine:migrations:status

# Rollback one migration
php bin/console doctrine:migrations:migrate --prev

# Try again
php bin/console doctrine:migrations:migrate
```

---

## 13. Next Steps

1. ✅ Complete the configuration checklist above
2. ✅ Start the dev server
3. ✅ Login and explore the interface
4. ✅ Review `Documentation/NEW_USER_GUIDE.md` for feature walkthroughs
5. ✅ Read `Documentation/QUICK_START.md` for common workflows

---

## 14. Quick Reference Commands

```powershell
# Database
php bin/console doctrine:database:create              # Create database
php bin/console doctrine:migrations:migrate           # Run migrations
php bin/console doctrine:schema:validate              # Check schema

# Cache
php bin/console cache:clear                          # Clear cache
php bin/console cache:warmup                         # Pre-warm cache

# Users
php bin/console app:create-user --email=x --password=y --role=ROLE_ADMIN

# Development
symfony server:start -d                              # Start server
symfony server:log                                   # Show logs
npm run dev                                          # Build assets
npm run watch                                        # Watch mode

# Debugging
php bin/console debug:router                         # List routes
php bin/console debug:container                      # List services
php bin/console debug:dotenv                         # Show env vars

# Background Jobs
php bin/console messenger:consume -vvv               # Process jobs
php bin/console messenger:stats                      # Job queue stats
```

---

## 📞 Need Help?

- 📖 **Read:** `Documentation/INDEX.md` - Full documentation map
- 📖 **Read:** `Documentation/QUICK_START.md` - 5-minute quickstart
- 🔍 **Check:** `var/log/dev.log` - Application logs
- 💬 **Ask:** Your team lead or admin

---

**Status:** ✅ Configuration Complete  
**Last Updated:** November 3, 2025
