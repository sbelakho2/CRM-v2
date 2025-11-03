# Service API Reference

**Version**: 1.0
**Last Updated**: October 29, 2025

This document catalogs the major application services exposed within the Starz Morocco CRM codebase. Each entry summarizes responsibility, key public methods, and important dependencies. Use this guide when integrating new features or troubleshooting service interactions.

---

## Table of Contents

1. [Email Platform](#email-platform)
2. [Lead & ABM](#lead--abm)
3. [RFQ & Quote Management](#rfq--quote-management)
4. [Webcrawler & Data Acquisition](#webcrawler--data-acquisition)
5. [Document & Compliance](#document--compliance)
6. [Shared Utilities](#shared-utilities)

---

## Email Platform

| Service | Purpose | Notable Methods | Dependencies |
|---------|---------|-----------------|--------------|
| `EmailCampaignService` | Campaign lifecycle orchestration | `createCampaign`, `updateCampaign`, `launchCampaign`, `pauseCampaign`, `archiveCampaign` | `EntityManagerInterface`, `EmailSchedulerService`, `EmailAnalyticsService` |
| `EmailTemplateService` | Template CRUD and rendering | `createTemplate`, `updateTemplate`, `renderTemplate`, `validatePersonalizationTokens`, `sanitizeHtml`, `getTemplateStats` | `EntityManagerInterface`, `HtmlSanitizer`, `Security` |
| `EmailSegmentService` | Audience segmentation engine | `createSegment`, `updateSegment`, `getSegmentContacts`, `evaluateFilters`, `validateFilterRules`, `getAvailableFields` | `EntityManagerInterface`, `ContactRepository`, `CompanyRepository` |
| `EmailSchedulerService` | Queue creation & send time optimization | `scheduleCampaign`, `processCampaign`, `calculateOptimalSendTime`, `processQueue`, `cancelCampaign`, `getCampaignProgress` | `EntityManagerInterface`, `ClockInterface`, `EmailDeliverabilityService` |
| `EmailDeliverabilityService` | Bounce handling & DNS validation | `processBounce`, `processComplaint`, `isSuppressed`, `getSuppressionList`, `validateSpf`, `validateDkim`, `validateDmarc`, `getCampaignDeliverabilityScore`, `getBounceStatistics` | `EntityManagerInterface`, `DnsResolver`, `EmailConsentService` |
| `EmailAnalyticsService` | Engagement reporting | `getCampaignMetrics`, `getEngagementTimeline`, `analyzeAbTest`, `compareCampaigns`, `getBestTimeToSend`, `getFunnelAnalysis` | `EmailSendRepository`, `ClockInterface` |
| `EmailAbTestService` | Variant management & stats | `createTest`, `assignVariant`, `recordEvent`, `evaluate`, `declareWinner` | `EmailSendRepository`, `EmailAnalyticsService` |
| `EmailDripCampaignService` | Multi-step sequence management | `createDrip`, `addStep`, `updateStep`, `removeStep`, `enrollContact`, `advanceSequence`, `getSchedulePreview` | `EntityManagerInterface`, `ClockInterface`, `EmailSchedulerService` |
| `EmailCampaignTriggerService` | Event-driven automation | `handlePipelineStageChange`, `handleRfqSubmission`, `handleQuoteSent`, `handleLeadScoreChange`, `handleAbmHit`, `createTriggeredCampaign`, `getAvailableTriggerTypes` | `EntityManagerInterface`, `EmailSchedulerService`, `EmailDripCampaignService`, `LoggerInterface` |
| `EmailActivityLogger` | Unified activity timeline integration | `logEmailSend`, `updateEmailEngagement`, `logCampaignEvent`, `getEmailActivities`, `getCompanyEmailStats`, `getContactEmailActivities` | `EntityManagerInterface`, `Security` |
| `EmailConsentService` | Consent lifecycle management | `requestDoubleOptIn`, `confirmOptIn`, `hasConsent`, `unsubscribe`, `resubscribe`, `generateUnsubscribeLink`, `processUnsubscribeToken`, `exportContactData`, `deleteContactData` | `EntityManagerInterface`, `LoggerInterface`, `Uuid` |
| `EmailComplianceService` | Regulatory enforcement | `validateEmailCompliance`, `addComplianceFooter`, `hasPhysicalAddress`, `hasUnsubscribeLink`, `hasDeceptiveSubject`, `isCommercialEmail`, `preFlightCheck`, `getComplianceReport`, `setCompanyInfo` | `EntityManagerInterface`, `EmailConsentService` |

---

## Lead & ABM

| Service | Purpose | Notable Methods | Dependencies |
|---------|---------|-----------------|--------------|
| `LeadScoringService` | Dynamic lead scoring (0-100) | `calculateScore`, `evaluateSignals`, `getScoreBreakdown`, `updateLeadScore` | `LeadRepository`, `SignalWeightProvider` |
| `LeadAssignmentService` | Lead-to-owner routing | `assignLead`, `reassignLead`, `getAssignmentRules`, `applyManualOverride` | `LeadRepository`, `CompanyRepository`, `UserRepository` |
| `LeadConversionService` | Lead → Company conversion | `convertToCompany`, `prepareCompanyData`, `linkContacts`, `logConversionActivity` | `EntityManagerInterface`, `CompanyFactory`, `ActivityLogger` |
| `AbmPlaybookService` | Account-based marketing automation | `activatePlaybook`, `evaluateTriggers`, `scheduleActions`, `logOutcome` | `EntityManagerInterface`, `EmailCampaignTriggerService`, `TaskScheduler` |
| `PortalCrawlerService` | Supplier portal discovery | `scanIndustrySites`, `parsePortal`, `queueVerification`, `recordResult` | `HttpClientInterface`, `DomCrawler`, `EntityManagerInterface` |

---

## RFQ & Quote Management

| Service | Purpose | Notable Methods | Dependencies |
|---------|---------|-----------------|--------------|
| `RfqWorkflowService` | RFQ lifecycle automation | `createRfq`, `advanceStage`, `assignOwner`, `recordSubmission`, `attachDocument` | `EntityManagerInterface`, `NotificationService` |
| `QuoteEstimatorService` | Automated BOM estimation | `estimateCosts`, `breakdownByComponent`, `applyDiscounts`, `generateQuotePdf` | `PricingEngine`, `PdfGenerator`, `ExchangeRateService` |
| `QuoteCopilotService` | AI-assisted quote recommendations | `suggestQuantities`, `recommendVendors`, `simulateScenario`, `generateSummary` | `MachineLearningClient`, `HistoricalQuoteRepository` |

---

## Webcrawler & Data Acquisition

| Service | Purpose | Notable Methods | Dependencies |
|---------|---------|-----------------|--------------|
| `CompanyDiscoveryService` | Lead discovery crawl orchestration | `buildFrontier`, `enqueueJobs`, `persistDiscoveries`, `updateStatistics` | `EntityManagerInterface`, `HttpClientInterface`, `MessageBusInterface` |
| `LinkedInScraperService` | LinkedIn search support | `generateSearchUrls`, `parsePublicProfile`, `extractContactDetails` | `HttpClientInterface`, `DomCrawler` |
| `GoogleDorkService` | Advanced Google queries | `buildQuery`, `rotateKeywords`, `recordResult`, `detectDuplicates` | `HttpClientInterface`, `SearchPatternProvider` |
| `CrmSyncService` | Import crawler results into CRM | `prepareLead`, `preventDuplicates`, `syncToCompany`, `syncToContact`, `logSyncResult` | `EntityManagerInterface`, `LeadRepository`, `CompanyRepository` |
| `TrackerImportService` | Excel/CSV ingest | `parseTracker`, `validateRow`, `upsertCompany`, `upsertContact`, `reportResults` | `SpreadsheetReader`, `EntityManagerInterface` |

---

## Document & Compliance

| Service | Purpose | Notable Methods | Dependencies |
|---------|---------|-----------------|--------------|
| `ComplianceDocumentService` | Manage 21-document compliance pack | `uploadDocument`, `replaceDocument`, `markVerified`, `generateChecklist`, `getMissingDocuments` | `EntityManagerInterface`, `VichUploader`, `AuditLogger` |
| `DocumentViewerService` | Unified document viewer | `listDocuments`, `getPreview`, `streamDownload`, `deleteDocument` | `StorageManager`, `Security`, `EntityManagerInterface` |
| `PdfGenerationService` | Centralized PDF rendering | `renderQuote`, `renderEstimate`, `renderCompliancePack`, `storePdf`, `hashContent` | `Mpdf`, `Twig`, `Filesystem` |

---

## Shared Utilities

| Service | Purpose | Notable Methods | Dependencies |
|---------|---------|-----------------|--------------|
| `ActivityLogger` | Generic activity logging | `logActivity`, `attachMetadata`, `getCompanyTimeline`, `getContactTimeline` | `EntityManagerInterface`, `Security` |
| `NotificationService` | Email & in-app notifications | `notifyUsers`, `queueEmail`, `markRead`, `getUnread` | `MailerInterface`, `EntityManagerInterface`, `TemplateRenderer` |
| `TaskScheduler` | Background job coordination | `enqueue`, `scheduleDelayed`, `cancel`, `listScheduledJobs` | `MessageBusInterface`, `ClockInterface` |
| `AuditLogger` | Unified audit trail | `record`, `getEntriesForEntity`, `search`, `purge` | `EntityManagerInterface`, `Security` |
| `SettingsService` | System configuration | `get`, `set`, `delete`, `list`, `export` | `EntityManagerInterface`, `CacheInterface` |

---

## Usage Conventions

- All services are registered through Symfony's autowiring; prefer constructor injection.
- Public methods return DTOs or arrays; avoid exposing entities unless read-only.
- Long-running operations should dispatch Messenger jobs instead of blocking requests.
- Services interacting with external systems must emit structured logs and handle retries.
- Add PHPDoc blocks documenting parameters, return types, and side effects.

For complete class signatures and implementation details, inspect the source files under `src/Service/`. This reference will be updated as services evolve.
