# Starz Morocco CRM - Distribution Package Builder
# This script creates a clean ZIP file for production deployment

Write-Host "========================================"
Write-Host "Starz Morocco CRM - Distribution Builder"
Write-Host "========================================"
Write-Host ""

$projectRoot = $PSScriptRoot
$tempDir = Join-Path $env:TEMP "starz-crm-dist-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
$outputDir = $projectRoot
$zipFileName = "starz-crm-production-v1.0.zip"
$zipFilePath = Join-Path $outputDir $zipFileName

Write-Host "Project Root: $projectRoot"
Write-Host "Temporary Directory: $tempDir"
Write-Host "Output File: $zipFilePath"
Write-Host ""

# Create temporary directory
Write-Host "[1/6] Creating temporary directory..."
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null
Write-Host "OK - Temporary directory created"
Write-Host ""

# Copy all files to temp directory
Write-Host "[2/6] Copying project files..."
Copy-Item -Path "$projectRoot\*" -Destination $tempDir -Recurse -Force -Exclude @('.git')
Write-Host "OK - Files copied to temporary location"
Write-Host ""

# Read .distignore file and remove excluded files/folders
Write-Host "[3/6] Removing development files..."
$distIgnorePath = Join-Path $projectRoot ".distignore"

if (Test-Path $distIgnorePath) {
    $excludePatterns = Get-Content $distIgnorePath | Where-Object {
        $_ -match '\S' -and $_ -notmatch '^#'
    }

    $removedCount = 0
    foreach ($pattern in $excludePatterns) {
        $pattern = $pattern.Trim()

        # Remove leading slash and convert to PowerShell path
        $pattern = $pattern -replace '^/', ''
        $pattern = $pattern -replace '/', '\'

        # Skip gitkeep exclusion patterns
        if ($pattern -like '!*') {
            continue
        }

        # Handle wildcard patterns
        if ($pattern -like '*\*') {
            $fullPath = Join-Path $tempDir $pattern
            $items = Get-ChildItem -Path $fullPath -Recurse -Force -ErrorAction SilentlyContinue
            foreach ($item in $items) {
                Remove-Item -Path $item.FullName -Recurse -Force -ErrorAction SilentlyContinue
                $removedCount++
            }
        } elseif ($pattern -like '*.*') {
            # Handle file patterns like *.tmp
            $searchPath = Split-Path -Parent (Join-Path $tempDir $pattern)
            $fileName = Split-Path -Leaf $pattern
            if (Test-Path $searchPath) {
                $items = Get-ChildItem -Path $searchPath -Filter $fileName -Recurse -Force -ErrorAction SilentlyContinue
                foreach ($item in $items) {
                    Remove-Item -Path $item.FullName -Force -ErrorAction SilentlyContinue
                    $removedCount++
                }
            }
        } else {
            $fullPath = Join-Path $tempDir $pattern
            if (Test-Path $fullPath) {
                Remove-Item -Path $fullPath -Recurse -Force -ErrorAction SilentlyContinue
                $removedCount++
                Write-Host "  Removed: $pattern"
            }
        }
    }

    Write-Host "OK - Removed $removedCount development files/folders"
} else {
    Write-Host "WARNING - .distignore file not found, skipping exclusions"
}
Write-Host ""

# Create .env.example from the tracked template (placeholders only).
# NEVER copy the real .env into the distribution — it contains live secrets
# (APP_SECRET, GOOGLE_API_KEY, NEXAR_*, MOUSER_API_KEY, DIGIKEY_*, ...).
Write-Host "[4/6] Creating .env.example..."
$envPath = Join-Path $tempDir ".env"
$envExampleSource = Join-Path $projectRoot ".env.example"
$envExamplePath = Join-Path $tempDir ".env.example"

if (Test-Path $envExampleSource) {
    Copy-Item -Path $envExampleSource -Destination $envExamplePath -Force
    Write-Host "OK - Copied tracked .env.example (placeholders only)"
} else {
    Write-Host "WARNING - .env.example not found in project root"
}

if (Test-Path $envPath) {
    Remove-Item -Path $envPath -Force
    Write-Host "OK - Removed .env from distribution (contains real secrets)"
} else {
    Write-Host "OK - No .env file present"
}
Write-Host ""

# Create INSTALLATION.txt with quick instructions
Write-Host "[5/6] Creating INSTALLATION.txt..."
$installationContent = @"
================================================================================
STARZ MOROCCO CRM - PRODUCTION INSTALLATION
================================================================================

Version: 1.0
Date: $(Get-Date -Format 'MMMM dd, yyyy')

================================================================================
QUICK START FOR SYSADMIN
================================================================================

1. EXTRACT FILES
   - Extract this ZIP to: /var/www/crm-starz-morocco
   - Set ownership: chown -R www-data:www-data /var/www/crm-starz-morocco

2. INSTALL DEPENDENCIES
   - Install Composer: https://getcomposer.org/download/
   - Run: composer install --no-dev --optimize-autoloader

3. CONFIGURE ENVIRONMENT
   - Copy .env.example to .env.local
   - Edit .env.local with production settings:
     * APP_ENV=prod
     * APP_SECRET=<generate random 32-char string>
     * DATABASE_URL=mysql://user:pass@localhost:3306/starz_crm
     * MAILER_DSN=smtp://username:password@smtp.provider.com:587

4. SET UP DATABASE
   - Create database: CREATE DATABASE starz_crm;
   - Create user and grant privileges
   - Run migrations: php bin/console doctrine:migrations:migrate --env=prod

5. SET PERMISSIONS
   - chmod -R 755 /var/www/crm-starz-morocco
   - chmod -R 775 /var/www/crm-starz-morocco/var
   - chmod 600 /var/www/crm-starz-morocco/.env.local

6. CONFIGURE WEB SERVER
   - See Documentation/07-Deployment-Operations/PRODUCTION_DEPLOYMENT_GUIDE.md for:
     * Apache configuration
     * Nginx configuration
     * SSL setup
     * Security hardening

7. CLEAR CACHE
   - php bin/console cache:clear --env=prod
   - php bin/console cache:warmup --env=prod

================================================================================
IMPORTANT FILES
================================================================================

Documentation/
  ├── 07-Deployment-Operations/
  │   └── PRODUCTION_DEPLOYMENT_GUIDE.md  ← READ THIS FIRST!
  ├── 01-Core/
  │   ├── SYSTEM_OVERVIEW.md              ← System architecture
  │   └── QUICKSTART.md                   ← Quick setup guide
  └── INDEX.md                             ← Documentation index

.env.example                               ← Environment template
README.md                                  ← Project overview

================================================================================
SYSTEM REQUIREMENTS
================================================================================

- PHP 8.2 or higher
- MySQL 8.0+ (the application targets MySQL)
- Apache 2.4+ with mod_rewrite OR Nginx 1.18+
- Composer (latest)
- 4GB RAM minimum (8GB recommended)
- 20GB disk space minimum

PHP Extensions Required:
  - pdo, pdo_mysql, mbstring, xml, ctype, iconv, intl, json, tokenizer, curl

================================================================================
WHAT'S INCLUDED IN THIS PACKAGE
================================================================================

✓ Complete Symfony 7.4 application source code
✓ Configuration files (optimized for production)
✓ Comprehensive documentation (65+ files, ~180 pages)
✓ Database schema and migrations
✓ Email templates
✓ UI templates (Twig)
✓ Complete deployment guide

NOT INCLUDED (Install separately on production):
✗ PHP dependencies (vendor/) - Run: composer install
✗ Development tools
✗ Test data
✗ IDE configuration
✗ Git repository

NOTE: No frontend build step is required — Encore/Webpack/Tailwind were removed from the stack; Twig templates render directly.

================================================================================
DEPLOYMENT STEPS
================================================================================

1. Read Documentation/07-Deployment-Operations/PRODUCTION_DEPLOYMENT_GUIDE.md
2. Install Composer dependencies
3. Configure .env.local
4. Set up database
5. Configure web server
6. Test deployment
7. Follow complete checklist in deployment guide

================================================================================
ESTIMATED DEPLOYMENT TIME
================================================================================

Experienced sysadmin: 2-4 hours
First-time deployment: 4-6 hours

================================================================================
VERSION INFORMATION
================================================================================

Application Version: 1.0
Release Date: October 2025
Status: Production-Ready
PHP Version Required: 8.2+
Framework: Symfony 7.4.15

================================================================================
END OF INSTALLATION GUIDE
================================================================================

Next step: Open Documentation/07-Deployment-Operations/PRODUCTION_DEPLOYMENT_GUIDE.md
"@

Set-Content -Path (Join-Path $tempDir "INSTALLATION.txt") -Value $installationContent
Write-Host "OK - Created INSTALLATION.txt"
Write-Host ""

# Create ZIP file
Write-Host "[6/6] Creating distribution ZIP file..."

# Remove old ZIP if exists
if (Test-Path $zipFilePath) {
    Remove-Item -Path $zipFilePath -Force
    Write-Host "  Removed old ZIP file"
}

# Create ZIP (using .NET for better compatibility)
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($tempDir, $zipFilePath, 'Optimal', $false)

$zipSize = (Get-Item $zipFilePath).Length
$zipSizeMB = [math]::Round($zipSize / 1MB, 2)

Write-Host "OK - ZIP file created: $zipFileName ($zipSizeMB MB)"
Write-Host ""

# Clean up temporary directory
Write-Host "Cleaning up temporary files..."
Remove-Item -Path $tempDir -Recurse -Force
Write-Host "OK - Cleanup complete"
Write-Host ""

# Summary
Write-Host "========================================"
Write-Host "DISTRIBUTION PACKAGE CREATED!"
Write-Host "========================================"
Write-Host ""
Write-Host "Package Details:"
Write-Host "  File: $zipFileName"
Write-Host "  Size: $zipSizeMB MB"
Write-Host "  Location: $outputDir"
Write-Host ""
Write-Host "Package Contents:"
Write-Host "  [+] Complete application source code"
Write-Host "  [+] Configuration files"
Write-Host "  [+] 65+ documentation files (~180 pages)"
Write-Host "  [+] INSTALLATION.txt (quick start guide)"
Write-Host "  [+] .env.example (environment template)"
Write-Host ""
Write-Host "NOT Included (install on production):"
Write-Host "  [-] vendor/ (run: composer install)"
Write-Host "  [-] Development tools"
Write-Host "  [-] Cache and log files"
Write-Host "  [-] Real .env (secrets stay local; .env.example ships with placeholders)"
Write-Host ""
Write-Host "Note: No frontend build step exists (Encore/Webpack/Tailwind removed 2026)."
Write-Host "Next Steps for Sysadmin:"
Write-Host "  1. Transfer $zipFileName to production server"
Write-Host "  2. Extract to /var/www/crm-starz-morocco"
Write-Host "  3. Read INSTALLATION.txt for quick start"
Write-Host "  4. Follow Documentation/07-Deployment-Operations/PRODUCTION_DEPLOYMENT_GUIDE.md"
Write-Host ""
Write-Host "========================================"
Write-Host "Ready for deployment!"
Write-Host "========================================"
