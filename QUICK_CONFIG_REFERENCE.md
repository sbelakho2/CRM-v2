# Quick Configuration Reference Card

**Print this or bookmark it for quick access**

---

## 🚀 Get Started in 5 Steps

```powershell
# 1. Navigate to project
cd c:\Users\khaoula\Desktop\CRM-v2

# 2. Run database setup
php bin/console doctrine:migrations:migrate

# 3. Build frontend assets
npm install
npm run dev

# 4. Clear cache
php bin/console cache:clear

# 5. Start server
symfony server:start -d
```

**Then visit:** `http://127.0.0.1:8000`

---

## 📋 Configuration Files

| File                     | Purpose                              | Type          |
| ------------------------ | ------------------------------------ | ------------- |
| `.env`                   | Default config (version controlled)  | ✅ Committed  |
| `.env.local`             | Your local overrides (NOT committed) | ⚠️ Local Only |
| `.env.dev`               | Development-specific settings        | ✅ Committed  |
| `.env.example`           | Template for new developers          | ✅ Committed  |
| `config/packages/*.yaml` | Framework configuration              | ✅ Committed  |
| `config/services.yaml`   | Service definitions                  | ✅ Committed  |

---

## 🔑 Critical Environment Variables (in `.env.local`)

```env
# Database - REQUIRED
DATABASE_URL="mysql://root:Formation2020*@127.0.0.1:3306/starz_crm"

# Email - REQUIRED for campaigns
MAILER_DSN=smtp://support@starzcloud.ovh:password@smtp.infomaniak.com:587

# Background jobs - REQUIRED for async tasks
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

# Application - REQUIRED for security
APP_SECRET=bd3a7e9f4c2b1d8a5f6e9c3b7d2a1f8c
APP_ENV=dev
APP_DEBUG=1

# Localization - OPTIONAL
DEFAULT_LOCALE=en

# Supplier APIs - OPTIONAL (for Quote Co-Pilot)
NEXAR_API_KEY=your_key_here
MOUSER_API_KEY=your_key_here
```

---

## 🗂️ What Gets Created

| Component | Created By                    | When   | Location           |
| --------- | ----------------------------- | ------ | ------------------ |
| Database  | `doctrine:migrations:migrate` | Step 2 | MySQL server       |
| Tables    | Migrations                    | Step 2 | starz_crm database |
| Assets    | `npm run dev`                 | Step 3 | `public/build/`    |
| Cache     | `cache:clear`                 | Step 4 | `var/cache/prod`   |

---

## 🧪 Testing Commands

```powershell
# Test database connection
php bin/console doctrine:query:sql "SELECT VERSION();"

# Test email setup
php bin/console mailer:test admin@crm-starz.dev

# List all routes
php bin/console debug:router

# Check schema
php bin/console doctrine:schema:validate

# View logs
Get-Content var/log/dev.log -Tail 20
```

---

## 🐛 Common Issues & Fixes

| Issue                        | Fix                                                  |
| ---------------------------- | ---------------------------------------------------- |
| "Database connection failed" | Check DATABASE_URL in .env.local                     |
| "SMTP connection failed"     | Verify MAILER_DSN and network connectivity           |
| "Assets not found (404)"     | Run `npm run dev` then refresh browser               |
| "Tables don't exist"         | Run `php bin/console doctrine:migrations:migrate`    |
| "Login doesn't work"         | Create user: `php bin/console app:create-user ...`   |
| "Emails not sending"         | Start messenger: `php bin/console messenger:consume` |

---

## 📦 Background Jobs

```powershell
# Start consumer (run in separate terminal)
php bin/console messenger:consume -vvv

# Check queue status
php bin/console messenger:stats

# Process specific number of jobs
php bin/console messenger:consume --limit=10
```

**Why needed:**

- Email campaigns
- Notifications
- Data imports
- Scheduled tasks

---

## 🎨 Frontend Assets

```powershell
# Install dependencies
npm install

# Build for development
npm run dev

# Watch for changes (during active development)
npm run watch

# Build for production
npm run build
```

**Output:** `public/build/` directory

---

## 👤 User Management

```powershell
# Create admin user
php bin/console app:create-user \
  --email=admin@example.com \
  --password=securepassword \
  --role=ROLE_ADMIN

# Create regular user
php bin/console app:create-user \
  --email=user@example.com \
  --password=password \
  --role=ROLE_USER
```

---

## 🚀 Start/Stop Commands

```powershell
# Start Symfony dev server
symfony server:start -d

# View server logs
symfony server:log

# Stop server
symfony server:stop

# Using PHP's built-in server
php -S 127.0.0.1:8000 -t public
```

---

## 🔄 Cache Management

```powershell
# Clear all caches
php bin/console cache:clear

# Clear specific environment
php bin/console cache:clear --env=prod

# Pre-warm cache
php bin/console cache:warmup
```

---

## 📊 Database Commands

```powershell
# Create database
php bin/console doctrine:database:create

# Drop database
php bin/console doctrine:database:drop --force

# Show migration status
php bin/console doctrine:migrations:status

# Run all migrations
php bin/console doctrine:migrations:migrate

# Rollback one migration
php bin/console doctrine:migrations:migrate --prev

# Validate schema
php bin/console doctrine:schema:validate
```

---

## 🔧 Debugging

```powershell
# List all services
php bin/console debug:container

# List all routes
php bin/console debug:router

# View environment variables
php bin/console debug:dotenv

# Check config for a bundle
php bin/console config:dump framework
```

---

## 📍 Key URLs

| Page      | URL                | Purpose             |
| --------- | ------------------ | ------------------- |
| Dashboard | `/`                | Main dashboard      |
| Login     | `/login`           | User authentication |
| Companies | `/companies`       | Company management  |
| Contacts  | `/contacts`        | Contact management  |
| Leads     | `/leads`           | Lead management     |
| RFQ       | `/rfq`             | Quote pipeline      |
| Campaigns | `/email-campaigns` | Email marketing     |
| Webinars  | `/webinars`        | Event management    |
| Admin     | `/admin`           | Admin panel         |

---

## 💾 Data Files

| File                   | Purpose               | Format |
| ---------------------- | --------------------- | ------ |
| `tracker_template.csv` | Sample company import | CSV    |
| `var/log/dev.log`      | Application logs      | Text   |
| `var/cache/`           | Cached files          | Binary |
| `var/data/`            | Uploads, documents    | Mixed  |
| `public/uploads/`      | User uploads          | Mixed  |

---

## 🔐 Security Tips

1. **Never commit `.env.local`** - Use `.env.local.example` instead
2. **Keep APP_SECRET secret** - Generate random value
3. **Use strong passwords** - For database and app users
4. **Enable HTTPS** - In production (set SSL certificate path)
5. **Restrict file permissions** - `chmod 755 var/` on Linux

---

## 📚 Documentation Map

| Document                              | Read When                      |
| ------------------------------------- | ------------------------------ |
| `MISSING_CONFIG_SUMMARY.md`           | Just configured, want overview |
| `CONFIGURATION_SETUP.md`              | Setting up local environment   |
| `Documentation/QUICK_START.md`        | Need 5-minute intro            |
| `Documentation/01-Core/QUICKSTART.md` | Setting up for development     |
| `Documentation/NEW_USER_GUIDE.md`     | Learning all features          |
| `Documentation/INDEX.md`              | Searching for specific info    |

---

## 🆘 Getting Help

1. Check `var/log/dev.log` for error messages
2. Search `Documentation/FAQ.md` for common questions
3. Review relevant doc in `Documentation/` folder
4. Check `CONFIGURATION_SETUP.md` §12 (Troubleshooting)

---

## ⚡ Performance Tips

- Use `npm run watch` during development (auto-rebuild)
- Clear cache after `.env.local` changes
- Use MailHog for email testing (no real SMTP needed)
- Enable Redis for production Messenger
- Use `--no-dev` flag for production composer install

---

## 📅 Maintenance Schedule

| Task               | Frequency | Command                                     |
| ------------------ | --------- | ------------------------------------------- |
| Clear cache        | Daily     | `php bin/console cache:clear`               |
| Database backup    | Daily     | `mysqldump -u ... > backup.sql`             |
| Log rotation       | Weekly    | Configure in `config/packages/monolog.yaml` |
| Dependency updates | Monthly   | `composer update`                           |
| Security audit     | Quarterly | Review `src/` and `config/`                 |

---

**Last Updated:** November 3, 2025  
**Project:** CRM-v2  
**Status:** Development Ready
