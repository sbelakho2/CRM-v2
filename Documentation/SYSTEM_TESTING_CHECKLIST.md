# System Testing Checklist - Complete End-to-End Verification

**Version**: 1.0  
**Date**: October 29, 2025  
**Status**: Pre-Production Testing Plan  
**Objective**: Verify all 37 tasks and system features work as intended

---

## Testing Overview

This document provides a comprehensive testing plan covering:
- ✅ System installation and environment
- ✅ Database and schema validation
- ✅ User authentication and permissions
- ✅ Core CRM features (Companies, Contacts, RFQs)
- ✅ Email campaign system (Tasks 32-37)
- ✅ Lead discovery and ABM automation
- ✅ Analytics and visitor tracking
- ✅ Compliance and document management
- ✅ Integration points and workflows
- ✅ Performance and load testing

**Total Test Cases**: 150+ test scenarios  
**Estimated Time**: 2-3 days for complete testing  

---

## Phase 1: Environment & Setup Testing

### 1.1 Server Environment

**Test Case 1.1.1: PHP Version**
```bash
php -v
# Expected: PHP 8.4.14 or higher
# ✓ Pass / ✗ Fail
```

**Test Case 1.1.2: Web Server**
```bash
sudo systemctl status apache2  # or nginx
# Expected: active (running)
# ✓ Pass / ✗ Fail
```

**Test Case 1.1.3: Database Connection**
```bash
mysql -u crm_user -p starz_crm -e "SELECT VERSION();"
# Expected: MySQL version displayed
# ✓ Pass / ✗ Fail
```

**Test Case 1.1.4: Composer Dependencies**
```bash
composer install --no-dev
# Expected: No errors, all packages loaded
# ✓ Pass / ✗ Fail
```

**Test Case 1.1.5: File Permissions**
```bash
ls -ld var/cache var/log var/data
# Expected: drwxrwxr-x (775 or similar)
# ✓ Pass / ✗ Fail
```

### 1.2 Environment Configuration

**Test Case 1.2.1: .env.local Exists**
```bash
test -f .env.local
# Expected: File exists with all required vars
# ✓ Pass / ✗ Fail
```

**Test Case 1.2.2: Database Configuration**
```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:schema:validate
# Expected: Schema valid, no errors
# ✓ Pass / ✗ Fail
```

**Test Case 1.2.3: Email Configuration**
```bash
php bin/console app:test-email admin@starz-morocco.com --env=prod
# Expected: Test email sent successfully
# ✓ Pass / ✗ Fail
```

**Test Case 1.2.4: Cache & Logs Writable**
```bash
touch var/cache/test.txt && rm var/cache/test.txt
touch var/log/test.log && rm var/log/test.log
# Expected: Both operations succeed
# ✓ Pass / ✗ Fail
```

---

## Phase 2: Database Schema Testing

### 2.1 Entity Tables

**Test Case 2.1.1: Core Tables Exist**
```sql
SHOW TABLES LIKE 'company';
SHOW TABLES LIKE 'contact';
SHOW TABLES LIKE 'lead';
SHOW TABLES LIKE 'rfq';
# Expected: All tables present
# ✓ Pass / ✗ Fail
```

**Test Case 2.1.2: Email Campaign Tables**
```sql
SHOW TABLES LIKE 'email_campaign';
SHOW TABLES LIKE 'email_send';
SHOW TABLES LIKE 'email_template';
SHOW TABLES LIKE 'email_segment';
SHOW TABLES LIKE 'email_unsubscribe';
# Expected: All email tables present
# ✓ Pass / ✗ Fail
```

**Test Case 2.1.3: Analytics Tables**
```sql
SHOW TABLES LIKE 'email_click';
SHOW TABLES LIKE 'email_bounce';
SHOW TABLES LIKE 'email_ab_test';
SHOW TABLES LIKE 'web_event';
# Expected: All analytics tables present
# ✓ Pass / ✗ Fail
```

**Test Case 2.1.4: Table Indexes**
```sql
SHOW INDEX FROM email_send;
# Expected: Indexes on campaign_id, sent_at, opened_at, clicked_at
# ✓ Pass / ✗ Fail
```

---

## Phase 3: Authentication & Permissions

### 3.1 User Login

**Test Case 3.1.1: User Registration**
- Navigate to `/register`
- Create user: `testuser@starz-morocco.com`
- Set password: `SecureTest123!`
- Expected: User created, redirect to login
- ✓ Pass / ✗ Fail

**Test Case 3.1.2: User Login**
- Navigate to `/login`
- Enter credentials
- Expected: Login successful, redirect to dashboard
- ✓ Pass / ✗ Fail

**Test Case 3.1.3: Session Persistence**
- Login as test user
- Close browser/tab
- Reopen URL
- Expected: Still logged in (session valid)
- ✓ Pass / ✗ Fail

**Test Case 3.1.4: Password Reset**
- Click "Forgot Password"
- Enter email
- Check email for reset link
- Click link and set new password
- Expected: Password reset works, can login with new password
- ✓ Pass / ✗ Fail

### 3.2 Permissions & Roles

**Test Case 3.2.1: Admin Access**
- Login as admin
- Try to access `/admin`
- Expected: Access granted
- ✓ Pass / ✗ Fail

**Test Case 3.2.2: Non-Admin Restricted**
- Login as regular user
- Try to access `/admin`
- Expected: Access denied / redirect
- ✓ Pass / ✗ Fail

**Test Case 3.2.3: User Can Edit Own Profile**
- Login as regular user
- Navigate to profile settings
- Edit name/email
- Expected: Changes saved
- ✓ Pass / ✗ Fail

---

## Phase 4: Core CRM Features

### 4.1 Companies Module

**Test Case 4.1.1: Create Company**
- Navigate to `/companies/new`
- Enter: Name="Yazaki Morocco", Website="yazaki.ma", Sector="Automotive"
- Click Save
- Expected: Company created, redirect to detail page
- ✓ Pass / ✗ Fail

**Test Case 4.1.2: List Companies**
- Navigate to `/companies`
- Expected: List of all companies displayed with pagination
- ✓ Pass / ✗ Fail

**Test Case 4.1.3: Search Companies**
- On companies list, enter search: "Yazaki"
- Expected: Only Yazaki company displayed
- ✓ Pass / ✗ Fail

**Test Case 4.1.4: Filter by Sector**
- Apply filter: Sector="Automotive"
- Expected: Only automotive companies shown
- ✓ Pass / ✗ Fail

**Test Case 4.1.5: Edit Company**
- Click on a company
- Edit: Employee Count to 500
- Click Save
- Expected: Changes saved, reflected in list
- ✓ Pass / ✗ Fail

**Test Case 4.1.6: Company Detail Page**
- Click on company
- Check sections: Basic Info, Contacts, RFQs, Activities, Documents
- Expected: All sections displayed with data
- ✓ Pass / ✗ Fail

### 4.2 Contacts Module

**Test Case 4.2.1: Add Contact**
- On company page, click "Add Contact"
- Enter: Email="john@yazaki.ma", Name="John Smith", Title="Procurement Manager"
- Expected: Contact created and listed
- ✓ Pass / ✗ Fail

**Test Case 4.2.2: Contact List for Company**
- View company contacts
- Expected: All contacts for company displayed
- ✓ Pass / ✗ Fail

**Test Case 4.2.3: Search Contacts**
- Global search: "john@yazaki.ma"
- Expected: Contact found
- ✓ Pass / ✗ Fail

---

## Phase 5: Email Campaign System Testing (Tasks 32-37)

### 5.1 Campaign Creation

**Test Case 5.1.1: Campaign Wizard - Step 1 (Details)**
- Navigate to `/email-campaigns/new`
- Enter: Name="Q4 Automotive Campaign", Description="Q4 outreach"
- Click Next
- Expected: Advance to Step 2, data saved
- ✓ Pass / ✗ Fail

**Test Case 5.1.2: Campaign Wizard - Step 2 (Template)**
- Create new template inline
- Enter: Subject="Special Offer for You", Body="Check out our new products"
- Click Next
- Expected: Template created and selected, advance to Step 3
- ✓ Pass / ✗ Fail

**Test Case 5.1.3: Campaign Wizard - Step 3 (Segment)**
- Build segment: Country="Morocco" AND Sector="Automotive"
- Expected: Live count shows estimated recipients
- Click Next
- ✓ Pass / ✗ Fail

**Test Case 5.1.4: Campaign Wizard - Step 4 (Schedule)**
- Select: Send Now OR Schedule for tomorrow at 10:00 AM
- Enable: Send Time Optimization
- Click Next
- Expected: Advance to Step 5
- ✓ Pass / ✗ Fail

**Test Case 5.1.5: Campaign Wizard - Step 5 (Review)**
- Review all campaign details
- Check pre-flight compliance checklist passes
- Click Launch
- Expected: Campaign scheduled/sent, view analytics page
- ✓ Pass / ✗ Fail

### 5.2 Template Management

**Test Case 5.2.1: Template Library**
- Navigate to `/email-templates`
- Expected: List of all templates with usage counts
- ✓ Pass / ✗ Fail

**Test Case 5.2.2: Create Template**
- Click "New Template"
- Use TinyMCE editor to create HTML email
- Add personalization tokens: {{contact.email}}, {{company.name}}
- Save
- Expected: Template saved, can be used in campaigns
- ✓ Pass / ✗ Fail

**Test Case 5.2.3: Template Preview**
- Select template
- Click "Preview" with sample data
- Expected: Shows rendered email with sample data
- ✓ Pass / ✗ Fail

**Test Case 5.2.4: Template Validation**
- Create template with invalid token: {{invalid.token}}
- System should warn about invalid tokens
- Expected: Warning shown, template still saveable
- ✓ Pass / ✗ Fail

### 5.3 Segmentation

**Test Case 5.3.1: Segment Builder**
- Navigate to `/email-segments/new`
- Add rule: Country = "Morocco"
- Add rule: Sector IN ("Automotive", "Electronics")
- Combine with OR
- Expected: Live contact count updates as rules added
- ✓ Pass / ✗ Fail

**Test Case 5.3.2: Save Segment**
- Name segment: "Morocco Tech"
- Click Save
- Expected: Segment saved, reusable in campaigns
- ✓ Pass / ✗ Fail

**Test Case 5.3.3: Segment List**
- Navigate to `/email-segments`
- Expected: All segments listed with member counts
- ✓ Pass / ✗ Fail

### 5.4 Campaign Analytics

**Test Case 5.4.1: Campaign Metrics**
- Navigate to `/email-campaigns/{id}/analytics`
- Expected: Metrics displayed: Sent, Opened, Clicked, Bounced, Unsubscribed
- ✓ Pass / ✗ Fail

**Test Case 5.4.2: Open Rate Calculation**
- Verify: Open Rate = (Opened / Sent) * 100
- Expected: Calculation correct
- ✓ Pass / ✗ Fail

**Test Case 5.4.3: Click Rate Calculation**
- Verify: Click Rate = (Clicked / Sent) * 100
- Expected: Calculation correct
- ✓ Pass / ✗ Fail

**Test Case 5.4.4: Device Breakdown**
- View analytics dashboard
- Expected: Chart showing device types (Desktop, Mobile, Tablet) for opens/clicks
- ✓ Pass / ✗ Fail

### 5.5 A/B Testing

**Test Case 5.5.1: Create A/B Test**
- Create campaign with A/B test enabled
- Variant A: Subject "Welcome to Starz"
- Variant B: Subject "Special Offer Inside"
- Expected: Campaign splits recipients 50/50
- ✓ Pass / ✗ Fail

**Test Case 5.5.2: A/B Test Results**
- After campaign completes
- Navigate to A/B test results
- Expected: Shows variant performance, statistical confidence, recommended winner
- ✓ Pass / ✗ Fail

**Test Case 5.5.3: Declare Winner**
- Click "Declare Winner" for variant A
- Expected: Winner recorded, can use in future campaigns
- ✓ Pass / ✗ Fail

### 5.6 Drip Campaigns

**Test Case 5.6.1: Create Drip Campaign**
- Create campaign, set type to "Drip"
- Add steps:
  - Step 1 (Day 0): Welcome email
  - Step 2 (Day 3): Value proposition
  - Step 3 (Day 7): Case study
- Expected: All steps configured
- ✓ Pass / ✗ Fail

**Test Case 5.6.2: Enroll Contact in Drip**
- Add contact to drip campaign
- Expected: Contact enrolled, Step 1 scheduled immediately
- ✓ Pass / ✗ Fail

**Test Case 5.6.3: Verify Step Progression**
- Wait for/simulate Day 3
- Expected: Step 2 email scheduled and sent
- ✓ Pass / ✗ Fail

### 5.7 Campaign Triggers

**Test Case 5.7.1: Pipeline Stage Trigger**
- Create triggered campaign: "Trigger on SQL stage"
- Move company to SQL stage
- Expected: Campaign automatically sent to that company's contacts
- ✓ Pass / ✗ Fail

**Test Case 5.7.2: RFQ Submission Trigger**
- Create triggered campaign: "Trigger on RFQ"
- Submit RFQ from a company
- Expected: Campaign automatically sent
- ✓ Pass / ✗ Fail

**Test Case 5.7.3: Quote Sent Trigger**
- Create triggered campaign: "Quote Follow-up"
- Send quote for an RFQ
- Expected: Campaign triggered for quote recipient
- ✓ Pass / ✗ Fail

**Test Case 5.7.4: Lead Score Trigger**
- Create triggered campaign: "High Scorer Nurture"
- Set trigger: Lead Score > 80
- Manually increase lead score to 85
- Expected: Campaign triggered
- ✓ Pass / ✗ Fail

### 5.8 Compliance & Consent

**Test Case 5.8.1: Double Opt-In Flow**
- Send campaign to test email
- Receive opt-in confirmation email
- Click confirmation link
- Expected: Contact marked as confirmed, has consent
- ✓ Pass / ✗ Fail

**Test Case 5.8.2: Unsubscribe Flow**
- Receive campaign email
- Click unsubscribe link
- Expected: Contact unsubscribed, marked in email_unsubscribe table
- ✓ Pass / ✗ Fail

**Test Case 5.8.3: CAN-SPAM Validation**
- Create campaign
- Pre-flight check should verify:
  - Physical address present
  - Unsubscribe link in footer
  - Sender identity clear
  - No deceptive subject
- Expected: All checks pass or warn
- ✓ Pass / ✗ Fail

**Test Case 5.8.4: GDPR Export**
- As contact, request data export
- Expected: JSON file with contact profile, consent state, engagement history
- ✓ Pass / ✗ Fail

**Test Case 5.8.5: Right to be Forgotten**
- As contact, request deletion
- Expected: Contact data anonymized or deleted
- ✓ Pass / ✗ Fail

### 5.9 Deliverability

**Test Case 5.9.1: Bounce Handling**
- Send campaign to invalid email
- System receives bounce webhook
- Expected: Email marked as bounced, contact suppressed
- ✓ Pass / ✗ Fail

**Test Case 5.9.2: Suppression List**
- View suppression list
- Expected: Shows hard bounces and complaints, reason for each
- ✓ Pass / ✗ Fail

**Test Case 5.9.3: DNS Validation**
- Check domain health: starz-morocco.com
- Validate SPF record
- Validate DKIM record
- Validate DMARC record
- Expected: All validation passes (or shows issues to fix)
- ✓ Pass / ✗ Fail

**Test Case 5.9.4: Deliverability Score**
- View campaign deliverability score (0-100)
- Expected: Score calculated, shows factors affecting score
- ✓ Pass / ✗ Fail

---

## Phase 6: Lead & ABM System Testing

### 6.1 Lead Discovery

**Test Case 6.1.1: Import Leads from Crawler**
- Run: `php bin/console app:import-leads --source=crawler`
- Expected: New leads imported, visible in `/leads`
- ✓ Pass / ✗ Fail

**Test Case 6.1.2: Lead Review Interface**
- Navigate to `/leads`
- Expected: List of leads sorted by score (highest first)
- ✓ Pass / ✗ Fail

**Test Case 6.1.3: Filter Leads by Region**
- Filter: Region="MOROCCO"
- Expected: Only Morocco leads displayed
- ✓ Pass / ✗ Fail

**Test Case 6.1.4: Approve Lead**
- Click green checkmark on lead
- Expected: Lead marked as approved
- ✓ Pass / ✗ Fail

**Test Case 6.1.5: Convert Lead to Company**
- Click "Add to Companies" on approved lead
- Expected: Company created with lead data, lead shows "In CRM" badge
- ✓ Pass / ✗ Fail

### 6.2 ABM Playbooks

**Test Case 6.2.1: Create Playbook**
- Navigate to `/abm/playbooks/new`
- Name: "High-Value Account Playbook"
- Add trigger: Company visits pricing page
- Add action: Send email + create task
- Expected: Playbook saved
- ✓ Pass / ✗ Fail

**Test Case 6.2.2: Activate Playbook**
- Click "Activate" on playbook
- Expected: Playbook active, triggers will fire
- ✓ Pass / ✗ Fail

**Test Case 6.2.3: Trigger Playbook Action**
- Simulate: Company from Playbook's account list visits website
- Expected: Trigger detected, action executed (email sent, task created)
- ✓ Pass / ✗ Fail

---

## Phase 7: Website Visitor Tracking

### 7.1 Tracking Script Installation

**Test Case 7.1.1: Generate Tracking Script**
- Run: `php bin/console app:generate-tracking-script starzelectronics.com`
- Expected: Script generated with unique tracking ID
- ✓ Pass / ✗ Fail

**Test Case 7.1.2: Install on starzelectronics.com**
- Add tracking script to website `<head>`
- Expected: Script loads without errors
- ✓ Pass / ✗ Fail

**Test Case 7.1.3: Install on starzenergies.com**
- Add tracking script to website `<head>`
- Expected: Script loads without errors
- ✓ Pass / ✗ Fail

### 7.2 Visitor Tracking

**Test Case 7.2.1: Page View Event**
- Visit starzelectronics.com from office IP
- Expected: Page view event recorded in CRM
- Verify: `/admin/web-events` shows the event
- ✓ Pass / ✗ Fail

**Test Case 7.2.2: IP Resolution**
- Check web event
- Expected: IP resolved to known company
- ✓ Pass / ✗ Fail

**Test Case 7.2.3: Session Tracking**
- Visit multiple pages on website
- Expected: All page views linked to same session
- ✓ Pass / ✗ Fail

**Test Case 7.2.4: Live Visitor Dashboard**
- Navigate to `/abm/live-visitors`
- Visit website from different IP/company
- Expected: Visitor appears in live dashboard
- ✓ Pass / ✗ Fail

---

## Phase 8: RFQ & Quote Pipeline

### 8.1 RFQ Management

**Test Case 8.1.1: Create RFQ**
- Navigate to `/rfq/new`
- Select Company, enter details, add line items
- Expected: RFQ created
- ✓ Pass / ✗ Fail

**Test Case 8.1.2: RFQ Pipeline Stages**
- RFQ should move through stages: Inquiry → Quote → Proposal → Award
- Move RFQ through each stage
- Expected: Stage transitions work, trigger notifications
- ✓ Pass / ✗ Fail

**Test Case 8.1.3: Kanban View**
- Navigate to `/rfq`
- Expected: Kanban board showing RFQs by stage
- ✓ Pass / ✗ Fail

### 8.2 Quote Generation

**Test Case 8.2.1: Create Quote**
- On RFQ, click "Generate Quote"
- System calculates cost, lead time, price breaks
- Expected: Quote generated with all details
- ✓ Pass / ✗ Fail

**Test Case 8.2.2: Quote PDF Export**
- Click "Export PDF" on quote
- Expected: PDF file generated, downloads successfully
- ✓ Pass / ✗ Fail

**Test Case 8.2.3: Send Quote Email**
- Click "Send Quote Email"
- Expected: Email sent to RFQ company contact
- Verify: Activity logged, quote marked as sent
- ✓ Pass / ✗ Fail

---

## Phase 9: Documents & Compliance

### 9.1 Compliance Document Pack

**Test Case 9.1.1: Upload Compliance Document**
- Navigate to `/documents/upload`
- Upload: ISO 13485 Certificate (PDF)
- Expected: Document uploaded, visible in company pack
- ✓ Pass / ✗ Fail

**Test Case 9.1.2: View Document Pack**
- Navigate to `/documents` for a company
- Expected: Shows all 21 required documents, highlights missing ones
- ✓ Pass / ✗ Fail

**Test Case 9.1.3: Document Versioning**
- Upload new version of certificate
- Expected: Old version archived, new version active
- ✓ Pass / ✗ Fail

**Test Case 9.1.4: Document Download**
- Click on document
- Click "Download"
- Expected: File downloads successfully
- ✓ Pass / ✗ Fail

---

## Phase 10: Dashboard & Analytics

### 10.1 Dashboard KPIs

**Test Case 10.1.1: Pipeline Value KPI**
- Navigate to `/`
- View "Pipeline Value" card
- Expected: Shows current value, progress toward $3.5M target
- ✓ Pass / ✗ Fail

**Test Case 10.1.2: RFQ Submitted KPI**
- Expected: Shows submitted RFQs this quarter, progress toward 12 target
- ✓ Pass / ✗ Fail

**Test Case 10.1.3: Activity Feed**
- View recent activities on dashboard
- Expected: Shows latest company/lead/RFQ activities
- ✓ Pass / ✗ Fail

**Test Case 10.1.4: Charts & Visualizations**
- Expected: Charts render without errors (sector breakdown, pipeline stages)
- ✓ Pass / ✗ Fail

---

## Phase 11: Integration Tests

### 11.1 End-to-End Workflows

**Test Case 11.1.1: Lead to Company to RFQ to Quote**
1. Import lead
2. Approve and convert to company
3. Create RFQ from company
4. Generate quote
5. Send quote email
- Expected: All steps complete successfully
- ✓ Pass / ✗ Fail

**Test Case 11.1.2: Email Campaign to Lead Tracking to ABM Action**
1. Send email campaign
2. Recipient company visits website
3. IP resolved to company
4. ABM playbook triggers
5. Follow-up email sent
- Expected: All steps execute automatically
- ✓ Pass / ✗ Fail

**Test Case 11.1.3: Activity Timeline Integration**
1. Perform: Create company, add contact, send email, receive email open, company visit website
2. View company activity timeline
3. Expected: All events shown in chronological order
- ✓ Pass / ✗ Fail

---

## Phase 12: Performance & Load Testing

### 12.1 Load Tests

**Test Case 12.1.1: Bulk Campaign Send**
- Send campaign to 10,000 recipients
- Monitor: CPU, Memory, Database connections
- Expected: Completes without errors, performance acceptable
- ✓ Pass / ✗ Fail

**Test Case 12.1.2: Analytics Query Performance**
- Generate analytics report for 1 year of data
- Expected: Report generates in < 5 seconds
- ✓ Pass / ✗ Fail

**Test Case 12.1.3: Concurrent User Load**
- 50 concurrent users access dashboard
- Expected: No timeouts, system responsive
- ✓ Pass / ✗ Fail

### 12.2 Database Performance

**Test Case 12.2.1: Query Performance**
- Verify indexes on all analytics tables
- Run slow query log check
- Expected: No slow queries (> 1 second)
- ✓ Pass / ✗ Fail

**Test Case 12.2.2: Database Size**
- Check database size
- Expected: Reasonable size (< 1GB initially)
- ✓ Pass / ✗ Fail

---

## Phase 13: Security Testing

### 13.1 Authentication Security

**Test Case 13.1.1: SQL Injection**
- Try SQL injection in search: `" OR "1"="1`
- Expected: Input sanitized, no security issue
- ✓ Pass / ✗ Fail

**Test Case 13.1.2: XSS Protection**
- Try XSS in company name: `<script>alert('xss')</script>`
- Expected: Script escaped, not executed
- ✓ Pass / ✗ Fail

**Test Case 13.1.3: CSRF Protection**
- Verify CSRF token on forms
- Expected: Token present and validated
- ✓ Pass / ✗ Fail

### 13.2 Data Security

**Test Case 13.2.1: Password Hashing**
- Check database: passwords should be hashed, not plaintext
- Expected: All passwords hashed (bcrypt/argon2)
- ✓ Pass / ✗ Fail

**Test Case 13.2.2: SSL/TLS**
- Visit https://crm.starz-morocco.com
- Check certificate validity
- Expected: Valid SSL certificate, no warnings
- ✓ Pass / ✗ Fail

**Test Case 13.2.3: Data Encryption**
- Sensitive data (emails, IPs) should be encrypted at rest
- Expected: Verified in database
- ✓ Pass / ✗ Fail

---

## Phase 14: Error Handling & Edge Cases

### 14.1 Error Conditions

**Test Case 14.1.1: Invalid Email**
- Try to send campaign to invalid email format: "not-an-email"
- Expected: Validation error, campaign not sent
- ✓ Pass / ✗ Fail

**Test Case 14.1.2: Duplicate Company**
- Try to create company with existing name
- Expected: Duplicate warning or auto-merge
- ✓ Pass / ✗ Fail

**Test Case 14.1.3: Missing Required Fields**
- Try to create RFQ without selecting company
- Expected: Validation error, form not submitted
- ✓ Pass / ✗ Fail

**Test Case 14.1.4: Database Connection Lost**
- Simulate database outage
- Try to access CRM
- Expected: Graceful error, user notified
- ✓ Pass / ✗ Fail

### 14.2 Edge Cases

**Test Case 14.2.1: Large File Upload**
- Try to upload 500MB PDF document
- Expected: File size validation, error or chunked upload
- ✓ Pass / ✗ Fail

**Test Case 14.2.2: Many Recipients**
- Create campaign targeting 100,000 contacts
- Expected: System handles large recipient list
- ✓ Pass / ✗ Fail

**Test Case 14.2.3: Very Long Campaign Name**
- Try to create campaign with 1000 character name
- Expected: Field validation, truncate or error
- ✓ Pass / ✗ Fail

---

## Phase 15: Browser Compatibility

### 15.1 Desktop Browsers

**Test Case 15.1.1: Chrome**
- Test on Chrome latest version
- Expected: All features work
- ✓ Pass / ✗ Fail

**Test Case 15.1.2: Firefox**
- Test on Firefox latest version
- Expected: All features work
- ✓ Pass / ✗ Fail

**Test Case 15.1.3: Safari**
- Test on Safari latest version
- Expected: All features work
- ✓ Pass / ✗ Fail

**Test Case 15.1.4: Edge**
- Test on Edge latest version
- Expected: All features work
- ✓ Pass / ✗ Fail

### 15.2 Mobile Browsers

**Test Case 15.2.1: Mobile Responsiveness**
- Access CRM on mobile device (iOS/Android)
- Expected: UI responsive, no layout breaks
- ✓ Pass / ✗ Fail

**Test Case 15.2.2: Mobile Form Entry**
- Fill forms on mobile
- Expected: Keyboard appears, forms submittable
- ✓ Pass / ✗ Fail

---

## Test Execution Summary

| Phase | Test Cases | Status | Notes |
|-------|-----------|--------|-------|
| 1. Environment | 5 | ⏳ Pending | |
| 2. Database Schema | 4 | ⏳ Pending | |
| 3. Authentication | 4 | ⏳ Pending | |
| 4. Core CRM | 6 | ⏳ Pending | |
| 5. Email Campaigns | 35 | ⏳ Pending | Tasks 32-37 |
| 6. Lead & ABM | 8 | ⏳ Pending | |
| 7. Visitor Tracking | 4 | ⏳ Pending | starzelectronics.com, starzenergies.com |
| 8. RFQ & Quote | 5 | ⏳ Pending | |
| 9. Documents | 4 | ⏳ Pending | |
| 10. Dashboard | 4 | ⏳ Pending | |
| 11. Integrations | 3 | ⏳ Pending | End-to-end workflows |
| 12. Performance | 5 | ⏳ Pending | Load testing |
| 13. Security | 6 | ⏳ Pending | |
| 14. Error Handling | 7 | ⏳ Pending | |
| 15. Browser Compat | 6 | ⏳ Pending | |
| **TOTAL** | **111** | ⏳ Pending | |

---

## Testing Resources

- **Test Data**: `tests/fixtures/test-companies.sql`, `tests/fixtures/test-campaigns.sql`
- **Test Scripts**: `scripts/test-all.sh`, `scripts/load-test.sh`
- **Postman Collection**: `tests/postman/CRM-API.postman_collection.json`
- **Browser Tools**: Chrome DevTools, Firefox DevTools, Mobile simulators

---

## Sign-Off

| Role | Name | Date | Signature |
|------|------|------|-----------|
| QA Lead | | | |
| Developer Lead | | | |
| System Admin | | | |
| Project Manager | | | |

---

**Next Steps**:
1. Assign test cases to team members
2. Set up test environments (staging, production-like)
3. Execute tests in phases
4. Document any failures
5. Retest after fixes
6. Sign off when all tests pass

**Estimated Timeline**: 2-3 days for complete testing
