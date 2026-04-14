# Deterministic Crawler Replacement — Implementation Checklist

> Replaces the LLM-gated monolithic `GoogleDorkService` (17,076 lines) with a
> deterministic, auditable, non-LLM discovery pipeline. Each item below is
> implemented test-first: write a failing test, then implement to green.

## Architecture

```
SearchProvider ──▸ QueryTemplateBuilder ──▸ CandidateCollector
                                               │
                                               ▼
                                         DomainCrawler
                                               │
                                               ▼
                                         PageClassifier
                                               │
                                               ▼
                                    ManufacturingEvidenceScorer
                                               │
                                               ▼
                                      LocationProofVerifier
                                               │
                                               ▼
                                    UnifiedContactExtractor
                                               │
                                               ▼
                              DeterministicDiscoveryPipeline
                                       (orchestrator)
                                               │
                                               ▼
                                    CompanyDiscoveryService
                                      (persistence — kept)
```

## Namespace

All new code: `App\Service\WebCrawler\Pipeline\`
All new tests: `tests/Unit/Pipeline/`

---

## Checklist

### 1. QueryTemplateBuilder
- [x] **Test**: sector+location → capped query set (≤40 queries per run)
- [x] **Test**: unknown sector falls back to general manufacturing templates
- [x] **Test**: each query has type metadata (sector_specific | directory | industrial_zone | general)
- [x] **Test**: no duplicate queries in output
- [x] **Implement**: `QueryTemplateBuilder::buildQueries(?string $sector, ?string $location): array`

### 2. CandidateCollector
- [x] **Test**: deduplicates results by root domain across all queries
- [x] **Test**: rejects blocked TLDs (.gov, .edu, .mil, .int, .museum)
- [x] **Test**: rejects junk domain patterns (social media, directories, news aggregators)
- [x] **Test**: caps total candidates at configurable limit (default 200)
- [x] **Test**: records per-query result counts for observability
- [x] **Implement**: `CandidateCollector::collect(array $queries, SearchProviderInterface $provider): CandidateSet`

### 3. DomainCrawler
- [x] **Test**: fetches homepage for every candidate domain
- [x] **Test**: discovers and fetches up to 5 subpages (about, contact, team, careers, supplier)
- [x] **Test**: respects robots.txt disallow rules
- [x] **Test**: extracts structured data (JSON-LD, Open Graph, meta tags) from each page
- [x] **Test**: returns CrawledDomain with pages, structured data, and timing
- [x] **Implement**: `DomainCrawler::crawl(CandidateSet $candidates): array<string, CrawledDomain>`

### 4. PageClassifier
- [x] **Test**: classifies manufacturer with factory/production/assembly vocabulary → MANUFACTURER
- [x] **Test**: classifies distributor/dealer/reseller/trading company → DISTRIBUTOR (veto)
- [x] **Test**: classifies news/media/magazine/press → MEDIA (veto)
- [x] **Test**: classifies trade association/industry body/federation → ASSOCIATION (veto)
- [x] **Test**: classifies government/ministry/public agency → GOVERNMENT (veto)
- [x] **Test**: classifies university/research institute → ACADEMIC (veto)
- [x] **Test**: classifies EMS/contract manufacturer competitor → COMPETITOR (veto)
- [x] **Test**: classifies consulting/law/accounting/recruitment → SERVICES (veto)
- [x] **Test**: classifies directory/listing/marketplace → DIRECTORY (veto)
- [x] **Test**: classifies conference/expo/trade show → EVENT (veto)
- [x] **Test**: returns UNKNOWN when evidence is insufficient
- [x] **Test**: uses schema.org @type, URL patterns, page titles, body vocabulary
- [x] **Test**: scores across ALL crawled pages, not just homepage
- [x] **Implement**: `PageClassifier::classify(CrawledDomain $domain): PageClassification`

### 5. ManufacturingEvidenceScorer
- [x] **Test**: accumulates positive signals (factory, plant, assembly line, production, OEM tier)
- [x] **Test**: accumulates certification signals (ISO 9001, IATF 16949, AS9100, ISO 13485, etc.)
- [x] **Test**: accumulates capability signals (PCB, SMT, CNC, injection molding, wire harness, etc.)
- [x] **Test**: accumulates workforce/facility signals (employees, plant, warehouse, R&D center)
- [x] **Test**: hard-vetoes DISTRIBUTOR, MEDIA, ASSOCIATION, GOVERNMENT, ACADEMIC, COMPETITOR, SERVICES, DIRECTORY, EVENT classifications
- [x] **Test**: returns numeric score with breakdown by category
- [x] **Test**: threshold at 40 for accept (configurable)
- [x] **Implement**: `ManufacturingEvidenceScorer::score(CrawledDomain $domain, PageClassification $classification): EvidenceScore`

### 6. LocationProofVerifier
- [x] **Test**: accepts ccTLD match (.de for Germany, .fr for France, .ma for Morocco, etc.)
- [x] **Test**: accepts structured address in JSON-LD mentioning target country
- [x] **Test**: accepts phone number with correct country code
- [x] **Test**: accepts explicit city/region name in page body text
- [x] **Test**: rejects when only evidence is the search query echo in a snippet
- [x] **Test**: rejects when company is headquartered in a different country with no local office
- [x] **Test**: handles multi-location companies (accepts if ANY facility is in target location)
- [x] **Implement**: `LocationProofVerifier::verify(CrawledDomain $domain, string $targetLocation): LocationVerdict`

### 7. UnifiedContactExtractor
- [x] **Test**: extracts from JSON-LD Person/ContactPoint
- [x] **Test**: extracts from mailto: links with domain-matching emails
- [x] **Test**: extracts from tel: links
- [x] **Test**: extracts from LinkedIn profile URLs with name text
- [x] **Test**: extracts from team page DOM (name + title cards)
- [x] **Test**: derives name from email patterns (john.smith@domain.com)
- [x] **Test**: rejects generic emails (info@, sales@, contact@, support@)
- [x] **Test**: deduplicates contacts by name across all pages
- [x] **Test**: scores each contact and applies single threshold (≥30)
- [x] **Test**: ranks contacts by decision-maker relevance (procurement > exec > engineering > other)
- [x] **Implement**: `UnifiedContactExtractor::extract(CrawledDomain $domain): array<ExtractedContact>`

### 8. DeterministicDiscoveryPipeline
- [x] **Test**: runs full pipeline for a mock sector+location and returns structured results
- [x] **Test**: output format is compatible with CompanyDiscoveryService::saveDiscoveredCompanies()
- [x] **Test**: records funnel metrics at each stage (candidates → crawled → classified → scored → location-verified → with-contacts → output)
- [x] **Test**: respects configurable max output limit
- [x] **Test**: handles empty search results gracefully
- [x] **Test**: handles network failures at crawl stage gracefully (skip domain, continue)
- [x] **Implement**: `DeterministicDiscoveryPipeline::discover(?string $sector, ?string $location): array`

### 9. Integration
- [x] **Test**: CompanyDiscoveryService uses DeterministicDiscoveryPipeline instead of GoogleDorkService
- [x] **Test**: service config autowires correctly
- [x] **Implement**: wire new pipeline into CompanyDiscoveryService constructor and discoverCompanies()

### 10. Validation
- [x] **Test**: golden dataset entries classified correctly at ≥92% accuracy (snippet-level; full-crawl expected higher)
- [x] **Test**: audit thresholds met — accuracy 92.0%, precision 92.2%, recall 90.4%
- [x] **Test**: EMS competitors, consultants, and recruiters correctly rejected
- [x] **Implement**: run against golden_dataset_v2.yaml entries (225 entries, 104 PASS / 121 REJECT)

---

## Files Created

| File | Status |
|------|--------|
| `src/Service/WebCrawler/Pipeline/QueryTemplateBuilder.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/CandidateCollector.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/CandidateSet.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/DomainCrawler.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/CrawledDomain.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/CrawledPage.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/PageClassifier.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/PageClassification.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/ManufacturingEvidenceScorer.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/EvidenceScore.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/LocationProofVerifier.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/LocationVerdict.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/UnifiedContactExtractor.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/ExtractedContact.php` | ✅ |
| `src/Service/WebCrawler/Pipeline/DeterministicDiscoveryPipeline.php` | ✅ |
| `tests/Unit/Pipeline/QueryTemplateBuilderTest.php` | ✅ |
| `tests/Unit/Pipeline/CandidateCollectorTest.php` | ✅ |
| `tests/Unit/Pipeline/DomainCrawlerTest.php` | ✅ |
| `tests/Unit/Pipeline/PageClassifierTest.php` | ✅ |
| `tests/Unit/Pipeline/ManufacturingEvidenceScorerTest.php` | ✅ |
| `tests/Unit/Pipeline/LocationProofVerifierTest.php` | ✅ |
| `tests/Unit/Pipeline/UnifiedContactExtractorTest.php` | ✅ |
| `tests/Unit/Pipeline/DeterministicDiscoveryPipelineTest.php` | ✅ |
