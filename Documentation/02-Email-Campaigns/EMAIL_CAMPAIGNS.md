# Email Campaign Platform Documentation

**Module**: Email Campaigns
**Status**: ✅ Production Ready
**Last Updated**: October 29, 2025

---

## 1. Module Overview

The email campaign platform delivers an end-to-end marketing automation stack built directly into the StarzCRM. It covers campaign planning, audience targeting, template design, execution, analytics, CRM automation, and regulatory compliance.

**Key Capabilities**
- Multi-touch drip campaigns (2-10 steps) with conditional branching
- A/B and multivariate testing for subject lines, send times, and content
- Visual segment builder with real-time audience counts
- Send time optimization using engagement history and time zones
- Triggered campaigns from CRM events (pipeline, RFQ, quotes, ABM, score changes)
- Consent management (double opt-in, unsubscribe handling, audit trail)
- Compliance enforcement (GDPR, CAN-SPAM, CASL)
- Deliverability monitoring (bounces, complaints, DNS checks, scoring)
- Activity timeline integration for account-based visibility

---

## 2. Data Model & Entities

| Entity | Purpose | Key Fields |
|--------|---------|------------|
| `EmailCampaign` | Stores high-level campaign definition | `name`, `status`, `type`, `triggerType`, `triggerConditions`, `subject`, `bodyHtml`, `fromEmail`, `fromName`, `scheduledAt`, `sentAt`, `createdAt`, `updatedAt`, `template`, `segment` |
| `EmailTemplate` | HTML and text templates with metadata | `name`, `subject`, `previewText`, `category`, `htmlContent`, `textContent`, `tokens`, `lastUsedAt`, `usageCount` |
| `EmailSegment` | Dynamic recipient definitions | `name`, `description`, `filterRules` (JSON), `logicOperator`, `lastCalculatedAt`, `contactCount` |
| `EmailSend` | Individual email send records | `campaign`, `contact`, `emailAddress`, `status`, `scheduledAt`, `sentAt`, `openedAt`, `clickedAt`, `isReplied`, `retryCount`, `failureReason`, `variant` |
| `EmailUnsubscribe` | Suppression tracking | `contact`, `email`, `reason`, `source`, `unsubscribedAt`, `token`, `tokenExpiresAt` |

**Extended Entities**
- `Contact` now contains `subscribed` and `leadScore`
- `Company` exposes helper accessors for industry, employee count, and revenue placeholders
- `Activity` tracks email sends and campaign events for unified timelines

---

## 3. Services & Responsibilities

### 3.1 Template & Content
- **`EmailTemplateService`** – CRUD, token extraction/validation, HTML sanitization, sample data generation, preview rendering, import/export
- **`EmailAbTestService`** – Variant management, population splitting, result tracking, significance testing (Chi-square) with confidence scoring
- **`EmailContentPersonalizer`** *(utility)* – Renders personalization tokens, handles fallbacks, escapes dangerous output

### 3.2 Audience & Scheduling
- **`EmailSegmentService`** – Filter validation, AND/OR evaluation engine, contact retrieval with pagination, operator dictionary, cached counts
- **`EmailSchedulerService`** – Campaign scheduling, queue creation, send time optimization, batch processing, retry logic, cancellation
- **`EmailDripCampaignService`** – Multi-touch workflow editor, delay calculations, conditional branching, shared throttling rules

### 3.3 Execution & Analytics
- **`EmailCampaignService`** – Orchestrates campaign lifecycle (draft → scheduled → sending → completed), command handlers, audit logging
- **`EmailDeliverabilityService`** – Bounce/complaint handling, suppression management, SPF/DKIM/DMARC validators, deliverability scoring, bounce dashboards
- **`EmailAnalyticsService`** – Engagement metrics, timeline charts, cohort comparison, funnel analysis, best send time analysis, top campaign reporting

### 3.4 Automation & CRM Integration
- **`EmailCampaignTriggerService`** – Creates/schedules campaigns based on CRM events: pipeline stage changes, RFQ submission, quote sent, lead score shifts, ABM activity
- **`EmailActivityLogger`** – Persists email interactions to `Activity` entities; updates engagement markers (opened, clicked, replied), aggregates stats per company
- **`EmailPlaybookService`** – Connects ABM playbooks with email campaigns, ensures no duplicate enrollment, coordinates follow-ups with sales tasks

### 3.5 Compliance & Consent
- **`EmailConsentService`** – Double opt-in flow, unsubscribe/resubscribe logic, audit trails, GDPR export, right-to-be-forgotten anonymization
- **`EmailComplianceService`** – Validates CAN-SPAM/CASL rules (physical address, unsubscribe, sender ID, deceptive subject detection, advertisement disclosure); injects compliance footer; pre-flight checks with consent validation

---

## 4. User Experience & UI Components

### 4.1 Campaign Wizard (5 Steps)
1. **Details** – Name, description, goals
2. **Template** – Library browser with thumbnail previews and inline creation
3. **Segment** – Visual filter builder with live count and reusable segments
4. **Schedule** – Immediate vs. scheduled send, optimization toggle, timezone display
5. **Review** – Summary card, pre-flight checklist, recipient totals, launch controls

### 4.2 Builders & Libraries
- **Template Builder** – TinyMCE WYSIWYG, token palette, subject & preview text editing, instant HTML/text sync, preview modal with sample data
- **Segment Builder** – Dynamic rule UI, field-typed operators, value controls, AND/OR group logic, rule serialization, real-time count via API
- **Template Library** – Grid view with usage stats, filtering, preview/edit actions
- **Segment Library** – Card view with contact totals, last recalculation, quick actions

### 4.3 Analytics & Monitoring
- Campaign analytics dashboard with overall metrics, per-touch performance, device trends, heatmaps, timeline charts
- AB testing dashboard with variant performance, statistical confidence, recommended winner
- Deliverability dashboard summarizing bounce trends, suppression reasons, domain health, DNS validation results

---

## 5. Automation Triggers

| Trigger | Source | Conditions | Action |
|---------|--------|------------|--------|
| Pipeline Stage Change | Company pipeline | Stage transitions (Prospect→MQL→SQL→SQO→Proposal→Award) | Schedule follow-up or drip enrollment |
| RFQ Submission | RFQ entity | Sector filters, minimum value thresholds | Send sector-specific nurture series |
| Quote Sent | Quote entity | Quote status updates, product lines | Launch quote follow-up sequence |
| Lead Score Change | Lead entity | Minimum score, minimum increase | Send personalized nurture/coaching content |
| ABM Hit | ABM activity | Page view count, session duration, engagement level | Trigger account-based playbook with multi-touch drip |

Each trigger prevents duplicate enrollment, respects global suppression, and logs actions for auditability.

---

## 6. Compliance & Deliverability

### 6.1 Consent Management
- Double opt-in emails with 7-day expiry tokens (`base64(email|timestamp)`)
- One-click unsubscribe tokens valid for 30 days
- Audit trail combining unsubscribe records and current consent status
- GDPR export (JSON) covering contact profile, consent state, engagement stats
- Right-to-be-forgotten via anonymization or hard delete

### 6.2 Regulatory Validation
- Physical address verification with regex heuristics and configurable defaults
- Unsubscribe link detection across HTML body and footer
- Sender identity verification (from name/email presence)
- Deceptive subject detection using keyword heuristics (free, urgent, fwd:, etc.)
- Commercial email detection to enforce advertisement disclosures
- Pre-flight checks produce detailed reports with consent, compliance, deliverability readiness

### 6.3 Deliverability Monitoring
- Hard vs. soft bounce classification (3 soft bounces escalate to hard)
- Complaint ingestion to auto-unsubscribe and suppress
- DNS record validators (SPF, DKIM, DMARC) with actionable feedback
- Deliverability score (0-100) derived from bounce & delivery rates with severity bands
- Suppression management UI with filtering and automated cleanup for expired entries

---

## 7. APIs & Integrations

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/email-campaigns/api/templates/preview` | POST | Render template preview with sample data |
| `/email-campaigns/api/segments/calculate` | POST | Return live contact count for filter rules |
| `/email-campaigns/api/segments/operators/{type}` | GET | Fetch operator list for field type |
| `/email-campaigns/api/drips/{id}/preview` | GET | (Task 35) Show drip timeline and delays |
| `/email-campaigns/api/ab-tests/{id}/result` | GET | Variant performance and recommended winner |

Campaign services also integrate with Messenger queues (`email_campaign_queue`) for asynchronous processing and leverage the global Activity stream for CRM visibility.

---

## 8. Testing Strategy

### 8.1 Automated Tests
- **Unit**: Template token parsing, segment evaluation, deliverability classifiers, consent token validation
- **Integration**: Campaign creation → scheduling → queue processing → analytics reconciliation
- **Functional**: UI wizard navigation, builder interactions, API responses, permission checks

### 8.2 Manual Validation
- Pre-flight checklist review before launch
- Seed list verification with real inboxes (Gmail, Outlook)
- Unsubscribe and resubscribe flows
- Double opt-in confirmation links
- DNS record validation in target domains

### 8.3 Monitoring
- Daily deliverability score checks with alerts below thresholds (<80 Fair, <65 Poor)
- Bounce and complaint anomaly detection (bounce >2%, complaint >0.1%)
- Trigger execution audit log review

---

## 9. Administration & Operations

- **Suppression List Maintenance**: Automatic cleanup of expired tokens plus manual removal tools
- **Content Governance**: Template approval states, version history, and cloning for reuse
- **Access Control**: Marketing role required for campaign edits; sales can view analytics
- **Localization**: Templates support multi-language tokens; segments filter by locale fields
- **Audit Logging**: Each campaign state change tracked with user, timestamp, and reason

---

## 10. Future Enhancements

1. **Predictive Send Windows** using ML models trained on historical engagement
2. **AI Subject Line Suggestions** integrated into template builder
3. **Preference Center** allowing contacts to choose topics/frequencies
4. **Real-time Webhooks** for external ESP integration
5. **Advanced Heatmaps** with device & geo segmentation

---

## 11. Reference Materials

- `src/Service/EmailTemplateService.php`
- `src/Service/EmailSegmentService.php`
- `src/Service/EmailSchedulerService.php`
- `src/Service/EmailDripCampaignService.php`
- `src/Service/EmailCampaignTriggerService.php`
- `src/Service/EmailActivityLogger.php`
- `src/Service/EmailConsentService.php`
- `src/Service/EmailComplianceService.php`
- `src/Service/EmailDeliverabilityService.php`
- `src/Service/EmailAnalyticsService.php`
- `templates/email_campaign/`

For UI walkthroughs and QA procedures, see `Documentation/EMAIL_CAMPAIGN_QUICK_REFERENCE.md` and `Documentation/EMAIL_CAMPAIGN_TESTING.md`.
