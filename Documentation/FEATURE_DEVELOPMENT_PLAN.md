# Feature Development Plan - Q4 2025

## Overview
Development plan for 6 high-impact features focused on deployment readiness and Starz infrastructure integration.

**Total Estimated Development Time:** ~40 hours  
**Deployment Strategy:** Phased rollout with infrastructure integration at each stage  
**Infrastructure Touches:** Email servers, Starz websites, analytics, notifications, webhooks

---

## Phase 1: Smart Notification System (Features #1-2)
**Estimated Time:** 10 hours  
**Priority:** ⭐⭐⭐ (Highest - improves daily UX)  
**Deployment Impact:** Database schema change + cache layer

### Feature #1: Notification Center - Backend
**Time:** 5 hours  
**Status:** Not Started

#### Tasks:
1. **Database Setup**
   - Create `Notification` entity (id, user_id, type, entity_id, entity_type, read_at, created_at)
   - Create `NotificationQueue` entity for async processing
   - Add migration: `CreateNotificationTables`
   - Index: (user_id, read_at) for quick queries

2. **NotificationService** (`src/Service/NotificationService.php`)
   - Detect RFQ deadlines (2 days before due_date)
   - Detect email replies (check EmailSend.opened_at)
   - Detect lead approvals (check Lead.status change)
   - Detect engagement drops (AbmResolverService comparison)
   - Detect quote views (Quote.viewed_at)
   - Create notification records via EntityManager
   - Batch query optimization (get all pending for user)

3. **NotificationCommand** (CLI for testing/scheduling)
   ```bash
   php bin/console app:check-notifications --user=1
   ```

4. **DashboardController Integration**
   - Add NotificationService injection
   - Add `/api/notifications` endpoint (GET - return 5 latest unread)
   - Add `/api/notifications/{id}/read` endpoint (PUT)
   - Response format: JSON with notification list

5. **Starz Infrastructure Integration**
   - Consider webhook to Starz analytics: `POST /api/events`
   - Enable push notification prep (Firebase Cloud Messaging ready)

#### Testing:
- Unit tests for each detection method
- Integration test: notification creation flow
- Performance test: 1000 notifications query time < 100ms

---

### Feature #2: Notification Center - Frontend
**Time:** 5 hours  
**Status:** Not Started

#### Tasks:
1. **Navbar Bell Icon** (`templates/base.html.twig`)
   - Add bell SVG icon (top-right, next to user menu)
   - CSS: Position relative, badge for unread count
   - Tailwind: `hover:bg-neutral-100 transition`
   - Badge: Red circle with white number

2. **Notification Dropdown Modal**
   - JavaScript: Fetch `/api/notifications` on page load
   - Modal HTML: List of 5 latest notifications
   - Each notification shows:
     - Icon (RFQ, email, quote, lead, etc.)
     - Title ("Quote Viewed" / "RFQ Due Tomorrow")
     - Company name
     - Time ago (e.g., "5 minutes ago")
     - "Mark as read" button
   - Tailwind styling with hover effects

3. **Toast Notifications** (Real-time events)
   - Install Alpine.js for reactivity
   - On page, show toast when:
     - Email reply received
     - Quote viewed
     - RFQ due soon (check every 30 mins)
   - Toast: 4-second auto-dismiss
   - Position: top-right

4. **Dashboard Badge Update**
   - Add "🔔 New Notifications (3)" banner on dashboard
   - Click to scroll to notifications section

5. **API Integration**
   - AJAX polling every 30 seconds (future: WebSocket)
   - Mark as read on click
   - Delete old notifications after 7 days

#### Testing:
- Browser test: bell icon appears and is clickable
- Test: notification list loads and updates
- Test: "Mark as read" works
- Test: Toast appears and disappears correctly

---

## Phase 2: Mobile Enhancement (Feature #3)
**Estimated Time:** 6 hours  
**Priority:** ⭐⭐ (Medium - improves mobile)  
**Deployment Impact:** No database changes, frontend only

### Feature #3: Mobile Quick Actions Bar
**Time:** 6 hours  
**Status:** Not Started

#### Tasks:
1. **Floating Action Button (FAB)** - Mobile only
   - CSS media query: only on screens < 768px
   - Tailwind: `fixed bottom-6 right-6 w-14 h-14`
   - Main button: Blue circular button with + icon
   - On click: reveal 4 sub-buttons (circular, stacked upward)
   - Buttons:
     - "Log Activity" → `/activities/new`
     - "Add Company" → `/companies/new`
     - "Add Contact" → `/contacts/new`
     - "Quick Search" → Focus search bar

2. **Enhanced Keyboard Shortcuts**
   - `/` = Focus global search (already works, enhance it)
   - `A` + `C` = Add Company (hold A, press C)
   - `A` + `E` = Add Contact (hold A, press E)
   - `L` = Log Activity
   - `?` = Show keyboard shortcut help modal
   - Disable when in text input

3. **Recent Companies LocalStorage**
   - Save last 5 companies viewed
   - LocalStorage key: `crm_recent_companies`
   - JSON: `[{id, name, url}, ...]`
   - Show dropdown when clicking search
   - Max 30KB storage

4. **Keyboard Shortcut Help Modal**
   - Show on `?` key
   - Tailwind modal with 2-column layout
   - List all shortcuts with descriptions
   - Close on Esc

5. **Accessibility Features**
   - Ensure FAB has aria-label
   - Keyboard navigation for FAB sub-buttons
   - High contrast for focus states

#### Testing:
- Mobile viewport test (375px): FAB appears, positioned correctly
- Desktop viewport test (1024px): FAB hidden
- Keyboard shortcut test: All 5 shortcuts work
- LocalStorage test: Recent companies saved/retrieved
- Accessibility test: Tab through all elements

---

## Phase 3: Analytics & Visualization (Features #4, #6)
**Estimated Time:** 10 hours  
**Priority:** ⭐⭐⭐ (High - improves decision-making)  
**Deployment Impact:** New service, ABM dashboard update, no schema changes

### Feature #4: Engagement Heat Map Dashboard
**Time:** 5 hours  
**Status:** Not Started

#### Tasks:
1. **EngagementHeatMapService** (`src/Service/EngagementHeatMapService.php`)
   - Use existing `AbmResolverService` for engagement scores
   - Get top 20 companies by engagement
   - Return: `[{id, name, score (0-100), color, activities_count, email_opens}]`
   - Cache result for 1 hour (Redis)

2. **Heat Map Template** (`templates/abm_dashboard/heat_map.html.twig`)
   - SVG or CSS grid: 20x20 grid (or 10x10 if simpler)
   - Each cell = 1 company (sorted by engagement)
   - Color coding:
     - Score 0-30: Red (#EF4444)
     - Score 30-60: Yellow (#FBBF24)
     - Score 60-100: Green (#10B981)
   - CSS hover effect: Show tooltip
   - Tooltip content: Company name, score, # activities, email opens

3. **Tooltip Implementation**
   - Tailwind: `group hover:bg-opacity-100`
   - Or JavaScript hover handler
   - Show on mouse over
   - Position: centered above/below cell
   - Z-index: high (above other elements)

4. **Integration into ABM Dashboard**
   - Add to ABM Dashboard page (right sidebar)
   - Title: "Engagement Heat Map"
   - Size: 400px × 400px
   - Refresh button: manual reload
   - Click cell: navigate to company detail page

5. **Starz Infrastructure Integration**
   - Send heatmap data to Starz analytics endpoint
   - Log top 3 engagement companies daily
   - Use for reporting/dashboards on Starz website

#### Testing:
- Unit test: `EngagementHeatMapService` returns correct data
- Integration test: Heat map renders with correct companies
- Test: Hover shows tooltip
- Test: Click navigates to company
- Performance: Heat map loads < 1 second

---

### Feature #6: Email Template Preview with Sample Data
**Time:** 5 hours  
**Status:** Not Started

#### Tasks:
1. **Enhance EmailTemplateService** (`src/Service/EmailTemplateService.php`)
   - Add method: `previewWithSampleData(EmailTemplate $template): string`
   - Pull random Company and Contact from database
   - Replace template variables:
     - `{{company.name}}` → real company name
     - `{{contact.name}}` → real contact name
     - `{{company.website}}` → real website
   - Return rendered HTML string
   - Handle missing variables gracefully

2. **Sample Data Fallback**
   - If no companies: Use hardcoded demo data
   - Demo data: "Example Corp" company, "John Doe" contact
   - Show in preview with gray background to indicate demo

3. **API Endpoint** - Update existing `apiTemplatePreview`
   - Route: `POST /email-campaigns/api/templates/preview`
   - Accept: `{template_id: 123}` or `{template_html: "...{{company.name}}"}`
   - Return: `{html: "<rendered html>", variables: ["company.name", "contact.name"], errors: []}`
   - Errors: Detect undefined variables like `{{undefined_field}}`

4. **Variable Detection**
   - Parse template for `{{variable.field}}` pattern
   - List used variables in response
   - Detect typos: `{{companiy.name}}` → flag as error
   - Show in preview as orange warning

5. **Frontend Modal**
   - Email Campaign template builder page
   - Add "Preview" button
   - Modal shows:
     - Preview HTML (in iframe for safety)
     - Used variables list
     - Errors/warnings section
     - "Copy HTML" button
   - Real-time preview on template update

6. **Starz Infrastructure Integration**
   - Email preview uses Starz branding (logo, colors)
   - Link to Starz sender domain settings
   - Preview shows "From: marketing@starz.ma" (if configured)

#### Testing:
- Unit test: Template rendering with sample data
- Test: Variables replaced correctly
- Test: Error detection (undefined variables)
- Test: API returns correct JSON
- Integration test: Modal loads and shows preview
- Test: "Copy HTML" button works

---

## Phase 4: Bulk Operations (Feature #5)
**Estimated Time:** 8 hours  
**Priority:** ⭐⭐⭐ (High - saves time)  
**Deployment Impact:** New service, LeadController update, no schema changes

### Feature #5: One-Click Lead Bulk Actions
**Time:** 8 hours  
**Status:** Not Started

#### Tasks:
1. **LeadBulkActionService** (`src/Service/LeadBulkActionService.php`)
   - Methods:
     - `bulkApprove(array $leadIds): int` → returns count updated
     - `bulkDeny(array $leadIds, string $reason): int`
     - `bulkAssign(array $leadIds, User $user): int`
     - `bulkAddTag(array $leadIds, array $tags): int`
     - `bulkEmail(array $leadIds, EmailTemplate $template): int` → queue emails
   - Each method logs audit trail
   - Return count of affected records
   - Error handling: partial success (e.g., 3 of 5 succeed)

2. **Lead Review Page Update** (`templates/lead/review.html.twig`)
   - Add checkbox column at left of each lead row
   - Checkbox ID: `lead_{id}`
   - "Select All" checkbox in header
   - JavaScript: Toggle all with header checkbox

3. **Bulk Action Toolbar**
   - Shows when >= 1 lead selected
   - Position: sticky top of table or floating
   - Displays: "3 leads selected"
   - Buttons:
     - "Approve All" (green, with confirmation modal)
     - "Deny All" (red, with reason textarea)
     - "Assign To" (dropdown of users)
     - "Add Tags" (input with autocomplete)
     - "Send Email" (dropdown of templates)

4. **Confirmation Modals**
   - Approve: "Approve 3 leads?" → Yes/No
   - Deny: "Deny 3 leads?" + textarea for reason
   - Assign: "Assign to Sarah?" + confirm
   - Email: "Send to 3 leads?" + template preview

5. **API Endpoints** - LeadController
   - `POST /leads/bulk/approve` → `{ids: [1,2,3]}`
   - `POST /leads/bulk/deny` → `{ids: [1,2,3], reason: "..."}`
   - `POST /leads/bulk/assign` → `{ids: [1,2,3], user_id: 5}`
   - `POST /leads/bulk/tags` → `{ids: [1,2,3], tags: ["hot", "priority"]}`
   - `POST /leads/bulk/email` → `{ids: [1,2,3], template_id: 12}`
   - All return: `{success: true, count: 3, message: "..."}`

6. **Success Feedback**
   - Toast notification: "✓ Approved 3 leads"
   - Rows fade out and remove from table
   - Counters update (pending count decreases)
   - Audit log entry for each action

7. **Starz Infrastructure Integration**
   - Log bulk actions to Starz audit system
   - Email sent via Starz SMTP server
   - Track in Starz analytics: "bulk approvals per day"

#### Testing:
- Unit test: `bulkApprove()` updates 5 leads correctly
- Unit test: Error handling (non-existent lead ID)
- Integration test: Checkboxes work, select all works
- Test: Confirmation modals appear
- Test: API endpoints return correct responses
- Test: Audit logs created
- Performance test: Bulk action on 100 leads < 2 seconds

---

## Infrastructure Integration Checklist

### Email Server Integration
- [ ] Notification emails configured (from notifications@starz.ma)
- [ ] Email template uses Starz branding
- [ ] SMTP server connection tested
- [ ] Rate limiting: max 100 emails/minute

### Website Integration
- [ ] Analytics endpoint available
- [ ] Webhook for lead notifications
- [ ] ABM dashboard data visible on Starz site
- [ ] Public API documentation (if needed)

### Database Considerations
- [ ] Connection pooling configured (if large scale)
- [ ] Backup strategy for new tables
- [ ] Migration rollback tested
- [ ] Data privacy compliance checked (GDPR, etc.)

### Monitoring & Logging
- [ ] New services logged to Starz monitoring system
- [ ] Error alerts configured
- [ ] Performance metrics tracked
- [ ] User audit trail complete

### Deployment Safety
- [ ] All changes in feature branches
- [ ] Code review checklist
- [ ] Database migrations tested on staging
- [ ] Rollback plan documented
- [ ] Announcement sent to Starz team

---

## Development Workflow

### Each Feature:
1. Create feature branch: `feature/notification-center`
2. Write tests first (TDD)
3. Implement service/controller
4. Update templates
5. Integration testing
6. Infrastructure integration
7. Documentation update
8. Code review
9. Merge to main
10. Deploy to staging
11. User acceptance testing
12. Deploy to production

### Deployment Timeline
- **Week 1:** Phase 1 (Notifications) - Deploy Friday EOD
- **Week 2:** Phase 2 (Mobile) - Deploy Friday EOD
- **Week 3:** Phase 3 & 4 (Analytics, Bulk) - Deploy Friday EOD
- **Week 4:** Buffer for fixes and optimizations

---

## Success Criteria

✅ All 6 features deployed and working  
✅ < 2 critical bugs found in production  
✅ User adoption > 80% within 2 weeks  
✅ Notification center used by 100% of sales team  
✅ Mobile quick actions used by > 50% on mobile  
✅ Bulk actions save > 5 hours/week for lead review  
✅ Heat map improves account focus by visible margin  
✅ Template preview reduces email errors by 90%  
✅ Zero infrastructure incidents  
✅ All team trained and comfortable with features

---

## Questions for Sadok

1. **Email Server:** Which SMTP server should notifications use? (starz.ma domain?)
2. **Starz Website:** Any analytics endpoints we should integrate with?
3. **User Preferences:** Should users be able to disable certain notifications?
4. **Webhooks:** Should we expose webhooks for external systems?
5. **Push Notifications:** Interest in mobile push notifications later?
6. **API Rate Limits:** Any rate limiting requirements for bulk operations?
7. **Reporting:** Should bulk actions feed into Starz reporting?
8. **Timezone:** What timezone for "due tomorrow" calculations?

---

**Last Updated:** October 30, 2025  
**Status:** Ready for Development  
**Owner:** Development Team  
**Target Launch:** November 28, 2025
