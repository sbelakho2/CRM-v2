# Google Custom Search API Setup Guide

This guide will help you set up Google Custom Search API for automated lead discovery in the CRM system.

> **Note (2026-08):** The CRM's **primary** search provider is now the self-hosted **SearXNG** instance (port 8888, `SEARXNG_BASE_URL` env var — see `config/services.yaml`, `SearchProviderInterface`). Google CSE is the **fallback** when SearXNG is unavailable. The setup steps below configure that fallback.

## Prerequisites

- Google Account
- Google Cloud Platform project
- Credit card for billing (after free tier)

## Step 1: Enable Google Custom Search API

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Navigate to **APIs & Services** > **Library**
4. Search for "Custom Search API"
5. Click **Enable**

## Step 2: Create API Credentials

1. Go to **APIs & Services** > **Credentials**
2. Click **Create Credentials** > **API Key**
3. Copy the API key
4. (Recommended) Click **Restrict Key**:
   - Under **API restrictions**, select "Custom Search API"
   - Under **Application restrictions**, choose appropriate option (HTTP referrers for web, IP addresses for server)
5. Save restrictions

## Step 3: Create Custom Search Engine

1. Go to [Google Programmable Search Engine](https://programmablesearchengine.google.com/)
2. Click **Add** or **Get Started**
3. Configure your search engine:
   - **Sites to search**: Select "Search the entire web"
   - **Name**: "CRM Lead Discovery"
   - Click **Create**
4. After creation, click **Control Panel**
5. Under **Basics** > **Search engine ID**, copy your **Search Engine ID** (cx parameter)

## Step 4: Configure CRM Environment

1. Open your `.env` file in the CRM project root
2. Add your credentials:

```env
GOOGLE_API_KEY=CHANGE_ME_google_api_key
GOOGLE_SEARCH_ENGINE_ID=your_search_engine_id_here
```

3. Save the file
4. Clear Symfony cache:

```bash
php bin/console cache:clear
```

## Step 5: Test the Integration

### Using Command Line

```bash
# Simple search
php bin/console app:search-companies "aerospace manufacturing morocco" --dry-run

# Search with import
php bin/console app:search-companies "automotive suppliers" --limit=10 --import

# Search by sector
php bin/console app:search-companies "electronics" --sector=aerospace --import
```

### Using Web Interface

1. Log into the CRM
2. Navigate to **Lead Discovery** from the sidebar
3. Enter a search query (e.g., "aerospace manufacturers morocco")
4. Select sector and number of results
5. Click **Search Companies**
6. Review results and click **Import Selected** or **Import All**

## API Quotas and Pricing

### Free Tier
- **100 queries per day** at no cost
- Resets daily at midnight Pacific Time

### Paid Tier
- **$5 per 1,000 queries**
- Billed monthly through Google Cloud
- No upper limit on queries

### Query Calculation
- Each API call = 1 query
- Maximum 10 results per call
- Requesting 50 results = 5 queries (5 × 10 results)

### Cost Examples

| Search Volume | Queries Used | Cost (after free tier) |
|---------------|--------------|------------------------|
| 10 searches × 10 results | 10 queries | Free |
| 50 searches × 20 results | 100 queries | Free |
| 200 searches × 10 results | 200 queries | $0.50 |
| 1,000 searches × 10 results | 1,000 queries | $4.50 |

## Usage Recommendations

### Optimize Query Count
1. **Start with free tier**: Test with 10-20 results per search
2. **Use specific keywords**: Better targeting = fewer wasted queries
3. **Filter before importing**: Review results to avoid duplicates
4. **Batch imports**: Schedule periodic bulk imports instead of real-time

### Best Practices
1. **Use sector filters**: Pre-qualify searches by industry
2. **Target specific regions**: Include location in queries (e.g., "Morocco", "Casablanca")
3. **Review before import**: Always preview results to avoid junk data
4. **Monitor quota usage**: Check the quota stats shown in search results

### Example Queries

#### Good Queries (Specific)
```
"aerospace manufacturers morocco"
"automotive parts suppliers casablanca"
"ISO 9001 certified electronics morocco"
"tier 1 aerospace suppliers tangier"
```

#### Poor Queries (Too Broad)
```
"companies"
"manufacturers"
"morocco business"
```

## Troubleshooting

### Error: "API key not valid"
- Verify API key is correct in `.env`
- Check that Custom Search API is enabled
- Ensure API key restrictions allow your server IP/domain

### Error: "Search engine ID not found"
- Verify search engine ID is correct
- Check search engine is active in Programmable Search console
- Ensure search engine is set to "Search the entire web"

### No Results Found
- Try broader search terms
- Remove location restrictions
- Check if search terms are too specific

### Rate Limit Exceeded
- You've exceeded 100 free queries for the day
- Enable billing in Google Cloud Console
- Wait until midnight PT for quota reset

### Duplicate Leads Created
- System automatically checks for duplicate websites
- Manually review imports before confirming
- Use "Import Selected" instead of "Import All"

## Advanced Configuration

### Customize Search Parameters

Edit `src/Service/GoogleSearchService.php`:

```php
// Add language restriction
'query' => [
    // ... existing parameters
    'lr' => 'lang_en', // English only
    'gl' => 'ma',      // Morocco country boost
],

// Add date restrictions (last 6 months)
'query' => [
    // ... existing parameters
    'dateRestrict' => 'm6',
],
```

### Create Custom Search Presets

Add preset methods in `GoogleSearchService`:

```php
public function searchCertifiedCompanies(string $certification, string $location = 'Morocco'): array
{
    $query = sprintf('"%s certified" manufacturers %s', $certification, $location);
    return $this->searchCompanies($query, 10);
}
```

## Monitoring and Analytics

### Track Usage
- Monitor daily quota usage in search results
- Review estimated costs before large imports
- Check Google Cloud Console for detailed billing

### Success Metrics
- Lead quality: Review → Qualified conversion rate
- Duplicate rate: Lower is better
- Cost per lead: Total API cost / New leads created

## Support and Resources

- [Google Custom Search API Documentation](https://developers.google.com/custom-search/v1/overview)
- [Programmable Search Engine Help](https://support.google.com/programmable-search/)
- [Google Cloud Pricing Calculator](https://cloud.google.com/products/calculator)
- [API Usage Dashboard](https://console.cloud.google.com/apis/dashboard)

## Security Notes

1. **Never commit API keys**: Keep `.env` out of version control
2. **Restrict API keys**: Always use API restrictions in Google Cloud
3. **Monitor usage**: Set up billing alerts in Google Cloud Console
4. **Rotate keys regularly**: Generate new keys every 6-12 months
5. **Use service accounts**: For production, use service account instead of API key

## Next Steps

After setup:
1. ✅ Test with dry-run searches
2. ✅ Import 5-10 test leads
3. ✅ Review lead quality
4. ✅ Configure billing alerts
5. ✅ Schedule regular discovery runs
6. ✅ Monitor conversion rates
