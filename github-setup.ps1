# GitHub Setup Script for CRM-v2
# 
# INSTRUCTIONS:
# 1. First, create a PRIVATE repository on GitHub:
#    - Go to https://github.com/new
#    - Repository name: CRM-v2
#    - Description: Symfony CRM System v2.0
#    - Visibility: PRIVATE
#    - Do NOT initialize with README
#    - Click "Create repository"
#
# 2. Replace YOUR_USERNAME below with your GitHub username
# 3. Run this script

$GITHUB_USERNAME = "YOUR_USERNAME"  # <-- REPLACE THIS

Write-Host "Setting up GitHub remote..." -ForegroundColor Cyan
git remote add origin "https://github.com/$GITHUB_USERNAME/CRM-v2.git"

Write-Host "Pushing to GitHub..." -ForegroundColor Cyan
git push -u origin main

Write-Host ""
Write-Host "✓ Successfully pushed to GitHub!" -ForegroundColor Green
Write-Host "Your private repository is at: https://github.com/$GITHUB_USERNAME/CRM-v2" -ForegroundColor Yellow
