<#
.SYNOPSIS
    Builds a clean distribution folder and zip archive for STARZ Morocco CRM.

.DESCRIPTION
    Copies only the production-ready files into a dist directory located one level
    above the project root. Excludes heavy development artifacts and prepares a
    zip archive for the sysadmin team. Ensures platform-specific setup scripts and
    DEPLOYMENT.md are present in the package.
#>

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "STARZ Morocco CRM - Distribution Builder" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$projectRoot   = $PSScriptRoot | Split-Path # scripts folder parent (project root)
$workspaceRoot = Split-Path $projectRoot
$distRoot      = Join-Path $workspaceRoot "dist"
$distName      = "STARZ-Morocco-CRM-v1.0-Smart-Ops-Bundle"
$distFolder    = Join-Path $distRoot $distName
$zipPath       = Join-Path $distRoot "$distName.zip"
$tempFolder    = Join-Path ([System.IO.Path]::GetTempPath()) "starz-dist-$([Guid]::NewGuid().ToString('N'))"

Write-Host "Project root : $projectRoot"
Write-Host "Workspace    : $workspaceRoot"
Write-Host "Dist folder  : $distFolder"
Write-Host "Zip output   : $zipPath"
Write-Host "Temp staging : $tempFolder"
Write-Host ""

# Guard rails
if (-not (Test-Path (Join-Path $projectRoot "composer.json"))) {
    throw "composer.json not found. Run this script from inside the project repository."
}

# Ensure dist root exists
Write-Host "[1/6] Preparing dist directory" -ForegroundColor Yellow
New-Item -ItemType Directory -Path $distRoot -Force | Out-Null
if (Test-Path $distFolder) {
    Remove-Item -Path $distFolder -Recurse -Force
}
if (Test-Path $zipPath) {
    Remove-Item -Path $zipPath -Force
}
New-Item -ItemType Directory -Path $distFolder -Force | Out-Null
Write-Host "  ✓ Dist directory ready" -ForegroundColor Green
Write-Host ""

# Stage files
Write-Host "[2/6] Copying project files to temp" -ForegroundColor Yellow
New-Item -ItemType Directory -Path $tempFolder -Force | Out-Null
Copy-Item -Path (Join-Path $projectRoot '*') -Destination $tempFolder -Recurse -Force
Write-Host "  ✓ Files staged" -ForegroundColor Green
Write-Host ""

# Remove excluded directories
Write-Host "[3/6] Removing development artifacts" -ForegroundColor Yellow
$dirsToRemove = @('vendor', 'var', 'node_modules', 'stubs', '.git', '.vscode', '.idea', 'tests')
foreach ($dir in $dirsToRemove) {
    $target = Join-Path $tempFolder $dir
    if (Test-Path $target) {
        Remove-Item -Path $target -Recurse -Force -ErrorAction SilentlyContinue
        Write-Host "  - Removed directory: $dir" -ForegroundColor DarkGray
    }
}

$filesToRemove = @('.env', '.env.dev', '.env.test', '.env.local', '.gitignore', '.gitattributes', 'composer.phar', 'phpunit.xml', 'phpunit.xml.dist', 'package-lock.json')
foreach ($file in $filesToRemove) {
    $target = Join-Path $tempFolder $file
    if (Test-Path $target) {
        Remove-Item -Path $target -Force -ErrorAction SilentlyContinue
        Write-Host "  - Removed file: $file" -ForegroundColor DarkGray
    }
}
Write-Host "  ✓ Development artifacts removed" -ForegroundColor Green
Write-Host ""

# Recreate var structure
Write-Host "[4/6] Recreating writable directories" -ForegroundColor Yellow
$varDirs = @('var', 'var/cache', 'var/log')
foreach ($dir in $varDirs) {
    $path = Join-Path $tempFolder $dir
    if (-not (Test-Path $path)) {
        New-Item -ItemType Directory -Path $path -Force | Out-Null
        Write-Host "  - Created $dir" -ForegroundColor DarkGray
    }
}
Write-Host "  ✓ Writable directories ready" -ForegroundColor Green
Write-Host ""

# Ensure deployment docs exist
Write-Host "[5/6] Verifying deployment documentation" -ForegroundColor Yellow
$requiredFiles = @(
    'DEPLOYMENT.md',
    'Documentation/07-Deployment-Operations/PRODUCTION_DEPLOYMENT_GUIDE.md',
    'Documentation/07-Deployment-Operations/DEPLOYMENT_CHECKLIST.md',
    'scripts/setup-windows.ps1',
    'scripts/setup-linux.sh'
)
$missing = @()
foreach ($relPath in $requiredFiles) {
    $src = Join-Path $projectRoot $relPath
    if (-not (Test-Path $src)) {
        $missing += $relPath
    }
}
if ($missing.Count -gt 0) {
    Remove-Item -Path $tempFolder -Recurse -Force -ErrorAction SilentlyContinue
    throw "Missing required files: $($missing -join ', ')"
}
Write-Host "  ✓ Deployment assets confirmed" -ForegroundColor Green
Write-Host ""

# Move staged files into final dist folder
Write-Host "[6/6] Publishing distribution directory" -ForegroundColor Yellow
Copy-Item -Path (Join-Path $tempFolder '*') -Destination $distFolder -Recurse -Force
Write-Host "  ✓ Distribution folder populated" -ForegroundColor Green
Write-Host ""

# Create zip archive
Write-Host "Creating zip archive..." -ForegroundColor Yellow
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($distFolder, $zipPath)
$zipSizeMB = [math]::Round(((Get-Item $zipPath).Length / 1MB), 2)
Write-Host "  ✓ Zip created at $zipPath ($zipSizeMB MB)" -ForegroundColor Green
Write-Host ""

# Cleanup temp
Remove-Item -Path $tempFolder -Recurse -Force -ErrorAction SilentlyContinue

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Distribution ready:" -ForegroundColor Cyan
Write-Host "  Folder -> $distFolder" -ForegroundColor Green
Write-Host "  Zip    -> $zipPath" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
