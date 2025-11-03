<#!
.SYNOPSIS
    Bootstraps a Windows-based host for STARZ Morocco CRM.

.DESCRIPTION
    Installs the required Windows features and PHP dependencies.
    Run from an elevated PowerShell prompt on the target server.
#>

Write-Host "=== STARZ Morocco CRM :: Windows Setup ===" -ForegroundColor Cyan

# Enable IIS features if needed
$features = @(
    "IIS-WebServerRole",
    "IIS-WebServer",
    "IIS-ISAPIFilter",
    "IIS-ISAPIExtensions",
    "IIS-NetFxExtensibility45",
    "IIS-ASPNET45",
    "IIS-HttpCompressionStatic",
    "IIS-RequestFiltering"
)

Write-Host "Enabling IIS features..." -ForegroundColor Yellow
foreach ($feature in $features) {
    Enable-WindowsOptionalFeature -Online -FeatureName $feature -All -NoRestart -ErrorAction SilentlyContinue | Out-Null
}

# Install URL Rewrite module if missing
$rewritePath = "$env:ProgramFiles\IIS\URL Rewrite\URLRewrite.dll"
if (-not (Test-Path $rewritePath)) {
    Write-Host "Download and install URL Rewrite 2.1 from https://www.iis.net/downloads/microsoft/url-rewrite" -ForegroundColor Yellow
}

Write-Host "Install dependencies next:" -ForegroundColor Green
Write-Host " 1. Install PHP 8.4+ (x64, Thread Safe)" -ForegroundColor Gray
Write-Host " 2. Install Composer globally" -ForegroundColor Gray
Write-Host " 3. Install MySQL 8.0+" -ForegroundColor Gray
Write-Host " 4. Configure IIS site pointing to extracted dist folder" -ForegroundColor Gray
Write-Host " 5. Configure Application Pool -> .NET CLR: No Managed Code; Enable 32-bit: False" -ForegroundColor Gray
Write-Host " 6. Set folder permissions for the IIS AppPool identity on /var" -ForegroundColor Gray

Write-Host "Refer to DEPLOYMENT.md for follow-up steps." -ForegroundColor Cyan
