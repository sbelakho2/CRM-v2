# How to Use the Web Crawler (Complete Guide)

## ⚠️ Important: Current State

The web crawler **does NOT automatically scrape** websites. It:

- ✅ **Generates search URLs** for you to use
- ❌ **Does NOT automatically add companies** to the database
- ❌ **Returns 0 companies** because it needs manual input

## 🎯 What You Need to Know

### Why It Returns "Discovered 0 companies"

The crawler generates **search URLs** but you need to:

1. Visit those URLs manually
2. Find companies
3. Add them to the CRM manually at `/companies/new`

### To Make It Fully Automatic (Costs Money)

You would need to pay for these APIs:

- **LinkedIn Sales Navigator API** - $80-300/month (enterprise only)
- **RocketReach API** - $50-200/month for contact finding
- **Apollo.io API** - $100-400/month for company data
- **Google Custom Search API** - $5 per 1000 searches

**Total cost: $200-900/month** for automatic lead generation

## 📋 How to Use It NOW (Free Method)

### Step 1: Run the Command

```powershell
php bin/console app:discover-companies --sector=Automotive
```

### Step 2: Check the Log File

The command generates search URLs and logs them to:

```
var/log/dev.log
```

Open this file and look for lines like:

```
[INFO] GOOGLE SEARCH URLS - Copy and paste these into your browser:
[INFO] 🔍 https://www.google.com/search?q=...
[INFO] 🔗 https://www.linkedin.com/search/results/companies/?keywords=...
```

### Step 3: Visit the URLs

1. Copy the search URLs from the log file
2. Paste them into your browser
3. Manually browse the search results
4. Find companies that fit your criteria

### Step 4: Add Companies Manually

Go to your CRM:

```
http://127.0.0.1:8000/companies/new
```

Fill in the company information:

- Name
- Sector
- Website
- Location
- etc.

## 💡 Better Alternative: Import Existing Data

Instead of using the web crawler, you can **import your existing company list**:

### Option 1: Import from CSV

If you have a list of companies in Excel:

1. **Save your Excel file as CSV**

   - Open Excel file
   - File > Save As > CSV (Comma delimited)

2. **Import into CRM**

   ```powershell
   php bin/console app:import-tracker "path/to/your-file.csv"
   ```

3. **CSV Format**
   ```csv
   Company Name,Sector,Location,Website,LinkedIn
   TE Connectivity,Automotive,Tanger Free Zone,https://te.com,https://linkedin.com/company/te
   Yazaki Morocco,Automotive,TAC,https://yazaki.com,
   ```

### Option 2: Add Companies Through Web Interface

Simply go to:

```
http://127.0.0.1:8000/companies/new
```

And add companies one by one through the form.

## 🤖 How to Make Web Crawler Automatic (Advanced)

If you want to spend money on automation, here's what you need to do:

### 1. Get API Keys

Sign up for these services:

- **SerpAPI** - https://serpapi.com (Google search automation)
- **Apollo.io** - https://apollo.io (company data)

### 2. Add to .env File

```env
SERPAPI_KEY=your_serpapi_key_here
APOLLO_API_KEY=your_apollo_key_here
```

### 3. Update the Code

I can modify the `GoogleDorkService` and `LinkedInScraperService` to:

- Call these APIs
- Parse the results
- Automatically save companies to database

**Cost estimate:**

- SerpAPI: $50-200/month
- Apollo.io: $100-400/month
- **Total: $150-600/month**

## 📊 Recommended Workflow (Free)

For now, I recommend:

### 1. **Manual Company Entry**

- Go to `/companies/new`
- Add your existing customers/prospects
- Focus on quality over quantity

### 2. **Use the Leads Module**

- Someone needs to manually create leads
- Review them at `/leads/review`
- Convert approved leads to companies

### 3. **Import from Spreadsheet**

If you have a company list in Excel:

```powershell
php bin/console app:import-tracker your-companies.csv
```

### 4. **Focus on CRM Usage**

Instead of lead generation, focus on:

- Managing existing companies
- Tracking RFQs and quotes
- Email campaigns
- Contact management
- Activity tracking

## ❓ Quick FAQ

**Q: Why doesn't the crawler work automatically?**
A: Web scraping requires expensive APIs or violates websites' terms of service. The current implementation generates search URLs for manual review.

**Q: How do I actually get companies into the system?**
A: Either import from CSV, add manually through the web interface, or pay for API integrations.

**Q: Is there a free way to make it automatic?**
A: No. Free web scraping is unreliable and often violates terms of service. Manual entry or CSV import is better.

**Q: What should I use instead?**
A: Import your existing company data using the `app:import-tracker` command, then use the CRM features to manage them.

**Q: Can you make it automatic for me?**
A: Yes, but you'll need to pay for API services ($150-600/month). Let me know if you want me to integrate them.

## 🎯 Conclusion

**Current Reality:**

- Web crawler = Search URL generator only
- Need manual input to add companies
- Best for finding companies, not automating import

**Recommended Approach:**

1. Import your existing company list via CSV
2. Add new companies manually when you find them
3. Focus on using CRM features (RFQs, quotes, emails)
4. Only invest in automation if you need 100+ new leads/month

**Need Help?**

- Import CSV: `php bin/console app:import-tracker --template` (generates example)
- Add manually: Visit `/companies/new`
- Ask me to integrate paid APIs if you want full automation
