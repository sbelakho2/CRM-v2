# PowerShell script to optimize all Twig templates with reusable class variables
# This script adds class variables and replaces hardcoded class strings

$templates = @(
    "templates\contact\edit.html.twig",
    "templates\company\new.html.twig",
    "templates\company\edit.html.twig",
    "templates\activity\new.html.twig",
    "templates\activity\edit.html.twig",
    "templates\webinar\new.html.twig",
    "templates\webinar\edit.html.twig",
    "templates\playbook\new.html.twig",
    "templates\playbook\edit.html.twig",
    "templates\admin\user\new.html.twig",
    "templates\admin\user\edit.html.twig"
)

$variablesBlock = @'
{# Reusable class variables #}
{% set label_class = 'block text-xs font-medium text-neutral-700 uppercase tracking-wide mb-2' %}
{% set input_class = 'w-full px-4 py-2.5 border border-neutral-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors' %}
{% set error_class = 'mt-1 text-xs text-red-600' %}

'@

$labelClassOld = "'label_attr': {{'class': 'block text-xs font-medium text-neutral-700 uppercase tracking-wide mb-2'}}"
$labelClassNew = "'label_attr': {{'class': label_class}}"

$inputClassOld = "'attr': {{'class': 'w-full px-4 py-2.5 border border-neutral-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors'}}"
$inputClassNew = "'attr': {{'class': input_class}}"

$errorClassOld = "'attr': {{'class': 'mt-1 text-xs text-red-600'}}"
$errorClassNew = "'attr': {{'class': error_class}}"

Write-Host "Starting Twig template optimization..." -ForegroundColor Green
Write-Host ""

foreach ($template in $templates) {
    $filePath = Join-Path $PSScriptRoot "..\$template"
    
    if (Test-Path $filePath) {
        Write-Host "Processing: $template" -ForegroundColor Yellow
        
        $content = Get-Content $filePath -Raw
        
        # Check if already optimized
        if ($content -match "{% set label_class") {
            Write-Host "  ✓ Already optimized, skipping..." -ForegroundColor Gray
            continue
        }
        
        # Count replacements before
        $labelCount = ([regex]::Matches($content, [regex]::Escape($labelClassOld))).Count
        $inputCount = ([regex]::Matches($content, [regex]::Escape($inputClassOld))).Count
        $errorCount = ([regex]::Matches($content, [regex]::Escape($errorClassOld))).Count
        
        # Add variables after {% block body %}
        $content = $content -replace "(?s)({% block body %})", "`$1`n$variablesBlock"
        
        # Replace class strings
        $content = $content -replace [regex]::Escape($labelClassOld), $labelClassNew
        $content = $content -replace [regex]::Escape($inputClassOld), $inputClassNew
        $content = $content -replace [regex]::Escape($errorClassOld), $errorClassNew
        
        # Save file
        Set-Content $filePath -Value $content -NoNewline
        
        Write-Host "  ✓ Optimized! Replaced: $labelCount labels, $inputCount inputs, $errorCount errors" -ForegroundColor Green
    }
    else {
        Write-Host "  ✗ File not found: $filePath" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "Optimization complete!" -ForegroundColor Green
Write-Host ""
Write-Host "Summary of changes:" -ForegroundColor Cyan
Write-Host "- Added reusable class variables to each template" -ForegroundColor White
Write-Host "- Replaced hardcoded class strings with variables" -ForegroundColor White
Write-Host "- Improved maintainability and reduced code duplication" -ForegroundColor White
