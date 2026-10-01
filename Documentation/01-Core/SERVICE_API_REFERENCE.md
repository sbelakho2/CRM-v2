# Service API Reference

**Version**: 2.0  
**Last Updated**: February 25, 2026

This document catalogs the major application services within the StarzCRM codebase. Each entry summarizes responsibility and location.

---

## Table of Contents

1. [Email Platform](#email-platform)
2. [Autonomous Sales & Personalization](#autonomous-sales--personalization)
3. [Quote & Pricing](#quote--pricing)
4. [Lead Discovery & Web Crawler](#lead-discovery--web-crawler)
5. [Competitor Intelligence](#competitor-intelligence)
6. [Document & Compliance](#document--compliance)
7. [Core Utilities](#core-utilities)

---

## Email Platform

All email services exist at `src/Service/Email*.php`:

| Service | Location | Purpose |
|---------|----------|---------|
| `EmailCampaignService` | `src/Service/EmailCampaignService.php` | Campaign lifecycle orchestration |
| `EmailTemplateService` | `src/Service/EmailTemplateService.php` | Template CRUD and rendering |
| `EmailSegmentService` | `src/Service/EmailSegmentService.php` | Audience segmentation engine |
| `EmailSchedulerService` | `src/Service/EmailSchedulerService.php` | Queue and send time optimization |
| `EmailDeliverabilityService` | `src/Service/EmailDeliverabilityService.php` | Bounce handling & DNS validation |
| `EmailAnalyticsService` | `src/Service/EmailAnalyticsService.php` | Engagement reporting |
| `EmailAbTestService` | `src/Service/EmailAbTestService.php` | A/B testing variant management |
| `EmailDripCampaignService` | `src/Service/EmailDripCampaignService.php` | Multi-step sequence management |
| `EmailCampaignTriggerService` | `src/Service/EmailCampaignTriggerService.php` | Event-driven automation |
| `EmailActivityLogger` | `src/Service/EmailActivityLogger.php` | Activity timeline integration |
| `EmailConsentService` | `src/Service/EmailConsentService.php` | GDPR consent management |
| `EmailComplianceService` | `src/Service/EmailComplianceService.php` | CAN-SPAM/regulatory enforcement |
| `EmailPersonalizationService` | `src/Service/EmailPersonalizationService.php` | ML-style personalization with embeddings |
| `EmailClassifierService` | `src/Service/EmailClassifierService.php` | Naive Bayes email classification |
| `SpintaxEngineService` | `src/Service/SpintaxEngineService.php` | Dynamic content with {option1|option2} syntax |

---

## Autonomous Sales & Personalization

| Service | Location | Purpose |
|---------|----------|---------|
| `AutonomousSalesOrchestratorService` | `src/Service/AutonomousSalesOrchestratorService.php` | Coordinates outbound email automation |
| `AutonomousSalesSettingsService` | `src/Service/AutonomousSalesSettingsService.php` | System settings and configuration |
| `SalesPipelineOrchestratorService` | `src/Service/SalesPipelineOrchestratorService.php` | Pipeline stage automation |
| `ThompsonSamplerService` | `src/Service/ThompsonSamplerService.php` | Multi-armed bandit A/B testing |
| `CadenceGovernorService` | `src/Service/CadenceGovernorService.php` | Email send rate limiting |
| `HourlyOptimizationService` | `src/Service/HourlyOptimizationService.php` | Time-based optimization |
| `PlaybookEngine` | `src/Service/PlaybookEngine.php` | ABM playbook execution |
| `AbmResolverService` | `src/Service/AbmResolverService.php` | Account-based marketing resolution |
| `LeadNurturingService` | `src/Service/LeadNurturingService.php` | Lead nurture sequences |

---

## Quote & Pricing

| Service | Location | Purpose |
|---------|----------|---------|
| `QuoteCoPilotService` | `src/Service/QuoteCoPilotService.php` | BOM → Quote automation with supplier API |
| `CostingEngineService` | `src/Service/CostingEngineService.php` | Component cost calculation |
| `PricingEngine` | `src/Service/PricingEngine.php` | Quote pricing logic |
| `InteractiveLiveQuoteService` | `src/Service/InteractiveLiveQuoteService.php` | Real-time quote builder |
| `BOMParser` | `src/Service/BOMParser.php` | Bill of Materials parsing |
| `FreightPricingService` | `src/Service/FreightPricingService.php` | Shipping cost calculation |
| `DutyCalculationService` | `src/Service/DutyCalculationService.php` | Import duty estimation |
| `HtsClassificationService` | `src/Service/HtsClassificationService.php` | HTS code classification |
| `FtaEligibilityService` | `src/Service/FtaEligibilityService.php` | Free Trade Agreement eligibility |
| `RiskAdjustedPricingService` | `src/Service/RiskAdjustedPricingService.php` | Risk-based pricing adjustments |
| `QuoteWinPredictorService` | `src/Service/QuoteWinPredictorService.php` | ML-based win probability |
| `CurrencyConversionService` | `src/Service/CurrencyConversionService.php` | Multi-currency support |
| `LiveFxRateFetcher` | `src/Service/LiveFxRateFetcher.php` | Real-time exchange rates |
| `UnifiedPdfGeneratorService` | `src/Service/UnifiedPdfGeneratorService.php` | PDF generation for quotes |
| `RfqVersioningService` | `src/Service/RfqVersioningService.php` | RFQ version control |

---

## Lead Discovery & Web Crawler

### Core Services

| Service | Location | Purpose |
|---------|----------|---------|
| `GoogleSearchService` | `src/Service/GoogleSearchService.php` | Google Custom Search + SearXNG integration |
| `FastWebScraperService` | `src/Service/FastWebScraperService.php` | Parallel web scraping |
| `DeepScrapingService` | `src/Service/DeepScrapingService.php` | Deep page content extraction |
| `HeadlessBrowserService` | `src/Service/HeadlessBrowserService.php` | JavaScript-rendered pages |
| `PortalCrawlerService` | `src/Service/PortalCrawlerService.php` | Supplier portal discovery |
| `ProxyRotationService` | `src/Service/ProxyRotationService.php` | Proxy management |
| `ScrapingFailSafeService` | `src/Service/ScrapingFailSafeService.php` | Fallback mechanisms |

### WebCrawler Subdirectory (`src/Service/WebCrawler/`)

| Service | Location | Purpose |
|---------|----------|---------|
| `CompanyDiscoveryService` | `WebCrawler/CompanyDiscoveryService.php` | Lead discovery orchestration |
| `GoogleDorkService` | `WebCrawler/GoogleDorkService.php` | Advanced search queries |
| `LeadScoringService` | `WebCrawler/LeadScoringService.php` | Lead scoring (0-100) |
| `CompanyClassifierService` | `WebCrawler/CompanyClassifierService.php` | Company classification |

### Contact Discovery (`src/Service/WebCrawler/Contact/`)

| Service | Location | Purpose |
|---------|----------|---------|
| `ContactQualityScorer` | `WebCrawler/Contact/ContactQualityScorer.php` | Contact data quality scoring |
| `LinkedInProfileParser` | `WebCrawler/Contact/LinkedInProfileParser.php` | LinkedIn profile parsing |

### Search Providers (`src/Service/WebCrawler/SearchProvider/`)

| Service | Purpose |
|---------|---------|
| `GoogleCSEProvider` | Google Custom Search Engine |
| `LocalSearxngProvider` | Self-hosted SearXNG |
| `BraveSearchProvider` | Brave Search API |
| `DuckDuckGoProvider` | DuckDuckGo scraping |

### Other WebCrawler Modules

| Directory | Purpose |
|-----------|---------|
| `WebCrawler/Classifier/` | Company/competitor classification |
| `WebCrawler/Crawl/` | Crawl governance and sitemap discovery |
| `WebCrawler/Evidence/` | Buyer evidence scoring |
| `WebCrawler/QualityGate/` | Golden dataset validation |
| `WebCrawler/Rules/` | Rule engine for lead scoring |
| `WebCrawler/Text/` | Language detection, text normalization |

---

## Competitor Intelligence

### CompCrawler Module (`src/Service/CompCrawler/`)

> **Correction (2026-08):** The entire `CompCrawler/` module was removed from the codebase during the 2026 refactoring (directory no longer exists). The table below is retained for historical reference only. Competitor detection now lives in `CompetitorDetectionService` / `CompetitorLearnerService`.

| Service | Location | Purpose |
|---------|----------|---------|
| ~~`CompChangeDetectorService`~~ | ~~`CompCrawler/CompChangeDetectorService.php`~~ | ~~Detect website changes~~ (removed) |
| ~~`CompDiscoveryService`~~ | ~~`CompCrawler/CompDiscoveryService.php`~~ | ~~Competitor discovery~~ (removed) |
| ~~`CompExtractionService`~~ | ~~`CompCrawler/CompExtractionService.php`~~ | ~~Data extraction~~ (removed) |
| ~~`CompIntelSyncService`~~ | ~~`CompCrawler/CompIntelSyncService.php`~~ | ~~Intelligence synchronization~~ (removed) |
| ~~`CompProfileCrawlerService`~~ | ~~`CompCrawler/CompProfileCrawlerService.php`~~ | ~~Profile crawling~~ (removed) |
| ~~`CompScoringService`~~ | ~~`CompCrawler/CompScoringService.php`~~ | ~~Competitor scoring~~ (removed) |
| ~~`CompVerificationService`~~ | ~~`CompCrawler/CompVerificationService.php`~~ | ~~Data verification~~ (removed) |

### Core Competitor Services

| Service | Location | Purpose |
|---------|----------|---------|
| `CompetitorDetectionService` | `src/Service/CompetitorDetectionService.php` | Detect competitors in content |
| `CompetitorLearnerService` | `src/Service/CompetitorLearnerService.php` | ML competitor learning |

---

## Document & Compliance

| Service | Location | Purpose |
|---------|----------|---------|
| `CompliancePackService` | `src/Service/CompliancePackService.php` | Compliance document pack |
| `ComplianceDocumentVersioningService` | `src/Service/ComplianceDocumentVersioningService.php` | Document versioning |
| `ComplianceExpiryReminderService` | `src/Service/ComplianceExpiryReminderService.php` | Expiry notifications |
| `DocumentManagerService` | `src/Service/DocumentManagerService.php` | Document management |
| `OnboardingPackService` | `src/Service/OnboardingPackService.php` | Customer onboarding packs |
| `DatasetImportService` | `src/Service/DatasetImportService.php` | Dataset import |

---

## Core Utilities

| Service | Location | Purpose |
|---------|----------|---------|
| `NotificationService` | `src/Service/NotificationService.php` | In-app notifications |
| `GuidanceNotificationService` | `src/Service/GuidanceNotificationService.php` | Contextual guidance |
| `KPITrackingService` | `src/Service/KPITrackingService.php` | Dashboard KPIs |
| `EngagementHeatMapService` | `src/Service/EngagementHeatMapService.php` | Engagement visualization |
| `ReportBuilderService` | `src/Service/ReportBuilderService.php` | Report generation |
| `CsvExportService` | `src/Service/CsvExportService.php` | CSV exports |
| `ExportService` | `src/Service/ExportService.php` | General exports |
| `CountryService` | `src/Service/CountryService.php` | Country/region data |
| `RegionStandardizationService` | `src/Service/RegionStandardizationService.php` | Region normalization |
| `CustomFieldService` | `src/Service/CustomFieldService.php` | Custom field management |
| `ActivityTemplateService` | `src/Service/ActivityTemplateService.php` | Activity templates |
| ~~`LlmService`~~ | ~~`src/Service/LlmService.php`~~ | ~~Local LLM integration~~ (removed 2026) |
| `LlmEnrichmentService` | `src/Service/LlmEnrichmentService.php` | AI enrichment |
| `GeminiContactExtractorService` | `src/Service/GeminiContactExtractorService.php` | Gemini API contact extraction |
| `ContactEnrichmentService` | `src/Service/ContactEnrichmentService.php` | Contact data enrichment |
| `AggressiveContactDiscoveryService` | `src/Service/AggressiveContactDiscoveryService.php` | Fast contact discovery |
| `CompanyDeduplicationService` | `src/Service/CompanyDeduplicationService.php` | Duplicate detection |
| `FollowUpReminderService` | `src/Service/FollowUpReminderService.php` | Follow-up scheduling |
| `WebinarService` | `src/Service/WebinarService.php` | Webinar management |
| `IssuingCompanyService` | `src/Service/IssuingCompanyService.php` | Issuing company data |
| `TrackerDataService` | `src/Service/TrackerDataService.php` | Tracker data management |

### Import Services (`src/Service/Import/`)

| Service | Purpose |
|---------|---------|
| `TrackerImportService` | Excel/CSV tracker import |
| `ExcelImportService` | Excel file import |

---

## Usage Conventions

- All services use Symfony autowiring; prefer constructor injection.
- Long-running operations dispatch Messenger jobs.
- Services interacting with external APIs emit structured logs.
- Add PHPDoc blocks documenting parameters and return types.

For implementation details, see source files in `src/Service/`.
