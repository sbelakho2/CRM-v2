# Quick Start: Import Tracker.xlsx and Use Webcrawler

## Step 1: Convert Tracker.xlsx to CSV

1. Open `C:\Users\sadok\CRM Project\Tracker.xlsx` in Excel
2. Click **File** > **Save As**
3. Choose **CSV (Comma delimited) (*.csv)**
4. Save as `C:\Users\sadok\CRM Project\Tracker.csv`

## Step 2: Import Data to CRM

```powershell
cd "C:\Users\sadok\CRM Project\crm-starz-morocco"
php bin/console app:import-tracker "C:\Users\sadok\CRM Project\Tracker.csv"
```

**What happens:**
- Creates/updates companies from your Excel file
- Imports supplier portal data
- Sets account tiers and pipeline stages
- Shows import statistics

## Step 3: Start the Server

```powershell
cd "C:\Users\sadok\CRM Project\crm-starz-morocco"
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd 'C:\Users\sadok\CRM Project\crm-starz-morocco'; php -S 127.0.0.1:8000 -t public"
```

Then visit: **http://127.0.0.1:8000**

## Step 4: Discover New Companies (Optional)

Find companies in specific sectors:

```powershell
# Automotive sector
php bin/console app:discover-companies --sector=Automotive

# Aerospace in Casablanca
php bin/console app:discover-companies --sector=Aerospace --location=Casablanca

# All sectors (takes time!)
php bin/console app:discover-companies --all
```

**Output:**
- LinkedIn search URLs for companies
- Google search URLs
- Automatically saves discovered companies

## Step 5: Find Contacts at Companies

```powershell
# By company name
php bin/console app:find-contacts "Yazaki Morocco"

# By company ID
php bin/console app:find-contacts 5
```

**Output:**
- LinkedIn search URLs for procurement contacts
- Google Dork URLs for finding emails
- List of target job titles

## All Available Commands

```powershell
# List all CRM commands
php bin/console list app

# Available commands:
app:import-tracker          # Import from Tracker.xlsx (CSV)
app:discover-companies      # Find new companies
app:find-contacts          # Find contacts at company
app:create-user            # Create CRM user

# Get help for any command
php bin/console app:import-tracker --help
```

## Web Interface URLs

After starting the server:

- **Dashboard**: http://127.0.0.1:8000/
- **Companies**: http://127.0.0.1:8000/companies
- **Contacts**: http://127.0.0.1:8000/contacts
- **RFQ Pipeline**: http://127.0.0.1:8000/rfq
- **Email Campaigns**: http://127.0.0.1:8000/email-campaigns
- **Webinars**: http://127.0.0.1:8000/webinars

## Common Workflows

### Import and Browse
1. Convert Tracker.xlsx to CSV
2. Run `php bin/console app:import-tracker Tracker.csv`
3. Start server
4. Browse companies at http://127.0.0.1:8000/companies

### Find Contacts
1. Go to company detail page
2. Note the company ID from URL
3. Run `php bin/console app:find-contacts <id>`
4. Visit generated LinkedIn URLs
5. Add contacts through web interface

### Discover New Companies
1. Run discovery: `php bin/console app:discover-companies --sector=Automotive`
2. Review log output for search URLs
3. Visit URLs manually or integrate APIs
4. Found companies auto-saved to database

## File Locations

- **Import template**: `crm-starz-morocco/tracker_template.csv`
- **Logs**: `crm-starz-morocco/var/log/dev.log`
- **Database**: `crm-starz-morocco/var/data.db`
- **Services**: `crm-starz-morocco/src/Service/WebCrawler/`
- **Commands**: `crm-starz-morocco/src/Command/`

## Troubleshooting

**Import fails?**
- Make sure you converted to CSV first
- Check file path is correct
- Verify CSV has header row

**No companies found by webcrawler?**
- This is normal! The webcrawler generates search URLs
- For automation, integrate LinkedIn/Google APIs
- For now, visit URLs manually and add companies via web interface

**Server won't start?**
- Kill existing PHP processes: `Get-Process | Where-Object {$_.ProcessName -eq "php"} | Stop-Process -Force`
- Check port 8000 is available
- Try different port: `php -S 127.0.0.1:8080 -t public`

## Next Steps

1. ✅ Import your Tracker.xlsx data
2. ✅ Start the server and browse companies
3. ✅ Use webcrawler to discover new companies
4. ✅ Find contacts at target companies
5. 📋 Set up API integrations (production)
6. 📋 Schedule automated discovery (cron/Task Scheduler)
7. 📋 Build email outreach campaigns

See **WEBCRAWLER_README.md** for detailed documentation.
