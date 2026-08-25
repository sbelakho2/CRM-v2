# CRM Starz Morocco - Complete User Guide for New Users

## Table of Contents
1. [Getting Started](#getting-started)
2. [System Overview](#system-overview)
3. [Dashboard & Navigation](#dashboard--navigation)
4. [Core Features](#core-features)
5. [Day-to-Day Tasks](#day-to-day-tasks)
6. [Best Practices](#best-practices)
7. [FAQs](#faqs)
8. [Support & Troubleshooting](#support--troubleshooting)

---

## Getting Started

### Welcome to CRM Starz Morocco!

CRM Starz Morocco is a sophisticated **Account-Based Marketing (ABM) and Supply Chain Management CRM** built with Symfony 7.3 and designed specifically for Moroccan manufacturing companies in free zones.

### Logging In

1. Navigate to `http://127.0.0.1:8000/` (development) or your deployed URL
2. Enter your **email** and **password**
3. Click "Sign In"
4. You'll be taken to the **Dashboard**

### First-Time Setup

**Default Credentials** (if provided by your admin):
- Email: `admin@crm-starz.dev`
- Password: (as configured by administrator)

⚠️ **Change your password immediately** after first login:
1. Click your **profile icon** (top-right)
2. Select **Settings**
3. Choose **Change Password**
4. Enter current password and new password (twice)
5. Click **Save**

---

## System Overview

### What This CRM Does

This is NOT a traditional contact management CRM. It's built for **Account-Based Marketing** with features specifically for:

✅ **Company Management** - Track target companies in Moroccan free zones  
✅ **Activity Management** - Log all interactions (calls, emails, meetings)  
✅ **Lead Management** - Discover and qualify new prospects  
✅ **ABM Dashboard** - Real-time view of account engagement  
✅ **Quote Management** - Generate and track quotes  
✅ **Supplier Portals** - Vendor relationship management  
✅ **Email Campaigns** - Bulk outreach and automation  
✅ **RFQ Pipeline** - Request for Quote workflow  

### Key Concepts

**Companies** → Your target accounts (supplier companies in Morocco)  
**Contacts** → People at those companies (procurement, buyers, engineers)  
**Activities** → Every interaction logged (calls, emails, meetings, LinkedIn)  
**Leads** → Prospects discovered by the system that need review  
**Quotes** → Pricing proposals sent to companies  
**Campaigns** → Email marketing to multiple contacts at once  

---

## Dashboard & Navigation

### Main Dashboard

When you log in, you'll see the **Main Dashboard** with:

**Top Cards:**
- 📊 **Total Companies** - Number of companies in your system
- 👥 **Total Contacts** - Total people across all companies
- 📈 **Activities This Month** - Interaction count
- 🎯 **Leads Pending Review** - Qualified prospects waiting approval

**Navigation Menu (Left Sidebar):**

```
📊 Dashboard
  ├─ Main Dashboard
  └─ ABM Dashboard
👥 Companies
  ├─ All Companies
  ├─ Add Company
  └─ Pipeline View
📞 Contacts
  ├─ All Contacts
  ├─ Add Contact
  └─ By Company
⚡ Activities
  ├─ Activity Log
  ├─ Timeline View
  └─ Log New Activity
💼 Leads
  ├─ Lead Management
  ├─ Lead Review
  └─ Approval Status
📧 Email Campaigns
  ├─ Active Campaigns
  ├─ Create Campaign
  └─ Performance
💰 Quotes
  ├─ All Quotes
  ├─ Create Quote
  └─ Quote History
📋 RFQ Pipeline
  ├─ RFQ Management
  └─ Status Tracking
🔧 Admin
  ├─ Settings
  ├─ User Management
  └─ System Tools
```

### ABM Dashboard (Most Important)

The **ABM Dashboard** shows your target accounts with:

- **Account Cards** displaying company name, engagement score, and status
- **Real-time metrics** on outreach effectiveness
- **Quick actions** to log activities or view details
- **Filter options** by sector, tier, or region

**Navigation:** `Dashboard` → `ABM Dashboard`

---

## Core Features

### 1. Companies Management

#### Adding a New Company

1. Navigate to **Companies** → **Add Company**
2. Fill in required fields:
   - **Company Name** (required)
   - **Website** (optional but recommended)
   - **Sector** (select from dropdown: Automotive, Industrial, etc.)
   - **Physical Site/Location** (Morocco free zone)
   - **LinkedIn URL** (if available)
   - **Account Tier** (A/B/C - A is highest priority)
   - **Pipeline Stage** (Prospect, Conversation, Proposal, Award)

3. Click **Save Company**

#### Viewing Company Details

1. Go to **Companies** → **All Companies**
2. Click on the company name (blue link)
3. You'll see:
   - Company information
   - Contact list (all people at that company)
   - Activity timeline
   - Document uploads
   - Related links (website, LinkedIn, Google Drive)

#### Editing a Company

1. On the company detail page, click **Edit**
2. Modify any fields
3. Click **Save Changes**

#### Deleting a Company

⚠️ **Careful!** Deletion is permanent.
1. On the company detail page, click **Delete**
2. Confirm the action
3. Company is removed (but activities remain for records)

---

### 2. Contacts Management

#### Adding a Contact

1. Go to **Contacts** → **Add Contact**
2. Enter details:
   - **Contact Name** (required)
   - **Email** (required)
   - **Phone** (optional)
   - **Title/Role** (e.g., "Procurement Manager")
   - **Company** (select from existing companies)
   - **Department** (Procurement, Engineering, etc.)
   - **LinkedIn Profile** (optional)

3. Click **Save Contact**

#### Viewing Contacts at a Company

1. Go to **Companies** and select a company
2. Scroll to **Contacts** section
3. See all people at that company
4. Click a contact name to view their details

#### Logging an Outreach Activity

1. Go to a contact's profile
2. Scroll to **Track Outreach**
3. This logs that you've contacted them
4. Details are recorded in your activity log

---

### 3. Activities (Interaction Logging)

Every interaction matters in ABM. Log everything!

#### Logging an Activity

1. Navigate to **Activities** → **Log Activity**
2. Select **Activity Type:**
   - **Call** - Phone conversation
   - **Email** - Email sent
   - **Meeting** - In-person or video
   - **LinkedIn** - LinkedIn message or connection
   - **Follow-up** - Follow-up task

3. Fill in details:
   - **Contact** (who you spoke with)
   - **Company** (which company)
   - **Description** (what was discussed)
   - **Outcome** (Successful, Follow-up Required, Not Interested)

4. Click **Log Activity**

#### Viewing Activities

**Timeline View:**
1. Go to **Activities** → **Timeline View**
2. See all activities in chronological order
3. Filter by type, date range, or company

**Activity List:**
1. Go to **Activities** → **Activity Log**
2. Sortable by date, type, or company
3. Click any activity to see full details

---

### 4. Leads Management

#### Understanding Leads

**Leads** are prospects discovered by the system (or manually added) that are:
- Not yet in your companies list
- Awaiting approval before conversion
- Scored by the system

#### Reviewing Leads

1. Go to **Leads** → **Lead Review**
2. Each lead shows:
   - Company name
   - Website
   - Lead score (0-100, higher is better)
   - Discovered information
   - Source data

3. You can:
   - **Approve** - Move to companies after review
   - **Deny** - Mark as not suitable (requires reason)
   - **Assign** - Assign to a sales rep

#### Converting a Lead to a Company

1. In lead review, click **Approve** if it looks good
2. Once approved, click **Convert to Company**
3. Lead becomes a new company in your system
4. All discovered data transfers over
5. Company appears in **Companies** list

#### Denying a Lead

1. Click **Deny**
2. Enter reason (e.g., "Not in target sector")
3. Lead is marked as rejected
4. You can review rejected leads later

---

### 5. Email Campaigns

#### Creating a Campaign

1. Go to **Email Campaigns** → **Create Campaign**
2. Set campaign details:
   - **Campaign Name** (required)
   - **Subject Line** (email subject)
   - **Email Body** (message content)
   - **Select Recipients** (choose contacts to send to)

3. Preview the email
4. Click **Schedule** or **Send Now**

#### Monitoring Campaign Performance

1. Go to **Email Campaigns** → **Active Campaigns**
2. See:
   - Sent count
   - Open rate
   - Click rate
   - Response rate

3. Click a campaign to see individual performance

#### Best Practices
- ✅ Personalize the subject line
- ✅ Keep emails short (under 200 words)
- ✅ Include clear call-to-action
- ✅ A/B test subject lines
- ❌ Don't send too many (max 1 per contact per week)

---

### 6. Quotes Management

#### Creating a Quote

1. Go to **Quotes** → **Create Quote**
2. Select the **Company** receiving the quote
3. Add line items:
   - **Product/Service Description**
   - **Quantity**
   - **Unit Price**
   - **Total**

4. System auto-calculates totals
5. Click **Generate PDF** to preview
6. Click **Send to Company**

#### Quote Statuses

- **Draft** - Not yet sent
- **Sent** - Awaiting response
- **Viewed** - Customer opened it
- **Won** - Customer accepted
- **Lost** - Customer rejected

#### Tracking Quote Progress

1. Go to **Quotes** → **All Quotes**
2. Each quote shows current status
3. Click a quote to see:
   - When it was sent
   - If customer viewed it
   - When it expires

---

### 7. RFQ Pipeline

RFQ = "Request for Quote" - when customers ask for pricing

#### Managing RFQs

1. Go to **RFQ Pipeline** to see all requests
2. RFQs move through stages:
   - **New** - Just received
   - **In Progress** - Being quoted
   - **Quoted** - Quote sent
   - **Negotiation** - Price discussion
   - **Won/Lost** - Decision made

3. Click an RFQ to:
   - Add notes
   - Create quote
   - Update status
   - Assign to team member

#### NDA & Compliance

Some RFQs require NDA (Non-Disclosure Agreement):
- ✅ Mark if NDA required
- ✅ Track NDA signature date
- ✅ Flag for legal review if needed

---

## Day-to-Day Tasks

### Morning Routine (5 minutes)

1. **Check Dashboard** for any urgent items
2. **Review Activities** from yesterday
3. **Look at Lead Review Queue** for new prospects
4. **Check email responses** in Email Campaigns

### During the Day

1. **Log Activities** every time you interact with a contact
2. **Follow up** on leads assigned to you
3. **Update Company Status** if pipeline stage changes
4. **Review incoming RFQs**

### End of Day (5 minutes)

1. **Log final activities** from the day
2. **Update company notes** if needed
3. **Schedule follow-ups** for tomorrow
4. **Review quotes** waiting for response

### Weekly Tasks

- Monday: Review lead approvals
- Tuesday: Create email campaign for outreach
- Wednesday: Check ABM engagement scores
- Thursday: Update RFQ statuses
- Friday: Weekly team review meeting

---

## Best Practices

### ✅ DO

- ✅ **Log every interaction** - Even 2-minute calls matter
- ✅ **Use descriptive notes** - "Discussed production capacity" not "talked"
- ✅ **Update company tiers** - Move good prospects to Tier A
- ✅ **Segment by region** - Use location filters effectively
- ✅ **Review leads weekly** - Don't let them pile up
- ✅ **Personalize outreach** - Reference specific company details
- ✅ **Keep contacts updated** - Add new people as you discover them
- ✅ **Use pipeline stages** - Move companies through the funnel

### ❌ DON'T

- ❌ Create duplicate companies - Search first!
- ❌ Log activities for others - Only log your own interactions
- ❌ Leave contacts with empty emails - Every contact needs email
- ❌ Ignore leads for weeks - Review and approve/deny them
- ❌ Send campaigns to all contacts at once - Segment them
- ❌ Delete companies - Just mark inactive in tier
- ❌ Forget to update pipeline stage - Keep workflow visible
- ❌ Leave quotes open forever - Follow up after 5 days

---

## FAQs

### Q: How do I find a specific company?

**A:** Use the search bar at the top of any page, or:
1. Go to **Companies** → **All Companies**
2. Use filters: Sector, Region, Pipeline Stage, Tier
3. Type company name in search

### Q: Can I export data?

**A:** Currently, export to CSV is available in:
- Companies list
- Contacts list
- Activities log
- Look for **Export** button

Future versions will support full data export.

### Q: How do I create a report?

**A:** Reports are auto-generated:
1. Go to **Dashboard** → **Main Dashboard**
2. Charts show key metrics
3. ABM Dashboard shows engagement metrics
4. Click any section to drill down

Manual reports can be created by exporting data to Excel.

### Q: What if I accidentally delete something?

**A:** Most deletions are soft deletes (marked inactive):
- Contact admin if you need recovery
- Deletions are logged in system audit trail
- Don't delete - mark as "inactive" instead

### Q: How do I assign leads to team members?

**A:** In Lead Review:
1. Click **Assign** on a lead
2. Select team member from dropdown
3. They'll see it in their queue
4. Assignment is tracked in activity log

### Q: Why is my company engagement score low?

**A:** Low score = few recent activities. Increase it by:
- ✅ Logging more activities (calls, emails, meetings)
- ✅ Sending email campaigns
- ✅ Updating company information
- ✅ Moving through pipeline stages

### Q: Can I schedule emails for later?

**A:** Yes! When creating a campaign:
1. Click **Schedule**
2. Choose date and time
3. Email sends automatically at that time

### Q: What's the difference between A, B, and C tier companies?

**A:**
- **Tier A** - High priority, high engagement, strategic accounts
- **Tier B** - Medium priority, good fit, growing opportunity
- **Tier C** - Lower priority, smaller opportunity, long-term

Move companies up as they progress through pipeline.

### Q: How do I track competitor activity?

**A:** Create a "Competitors" sector or use notes:
1. Add competitor companies
2. Log activities you learn about them
3. Use this to inform your strategy
4. (This feature is for intelligence only)

---

## Support & Troubleshooting

### Common Issues

#### "I can't log in"

1. Check your email is correct
2. Verify caps lock is OFF
3. Reset password (forgot password link)
4. Contact admin if issue persists

**Password Reset:**
1. Click "Forgot Password?" on login page
2. Enter your email
3. Check email for reset link
4. Click link and create new password

#### "I see a blank page"

1. Refresh browser (Ctrl + F5 or Cmd + Shift + R)
2. Clear browser cache
3. Try a different browser
4. Check your internet connection

#### "My changes didn't save"

1. Check for error message (red box)
2. Verify all required fields are filled
3. Try saving again
4. Contact admin if error persists

#### "I can't see a company I just added"

1. Refresh the page
2. Clear browser cache
3. The company should appear in list
4. Use search to find it quickly

#### "Activities won't save"

1. Make sure you selected a contact AND company
2. Check that description isn't empty
3. Refresh and try again
4. Check your internet connection

### Getting Help

**In-App Help:**
- Hover over any ❓ icons for tooltips
- Click **Help** button (if available) for context

**Contact Your Admin:**
- Email: admin@crm-starz.dev
- Or contact: Your designated CRM administrator
- Subject: "CRM Help - [Issue Description]"

**System Status:**
- Check system messages on Dashboard
- Look for maintenance notices
- Contact admin if system is down

---

## Advanced Features

### ABM Dashboard Features

The **ABM Dashboard** is your main view:

1. **Target Accounts** - Your tier-A companies with engagement tracking
2. **Playbooks** - Pre-built outreach sequences for different scenarios
3. **Analytics** - Engagement scoring and metrics

### Playbooks

Playbooks are automated outreach sequences:

1. Go to **ABM Dashboard** → **Playbooks**
2. Select a playbook (e.g., "First Contact", "Follow-up")
3. Click **Launch Playbook**
4. System sends automated activities
5. Track results in Analytics

### Activity Timeline

Visual representation of all interactions at a company:

1. Go to **Activities** → **Timeline View**
2. See chronological activity history
3. Hover over activities for details
4. Click to expand details

---

## System Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `/` | Focus search bar |
| `Ctrl + K` | Quick command palette |
| `Esc` | Close dialogs |
| `Enter` | Save forms |
| `Tab` | Navigate between fields |

---

## Data Privacy & Security

### Your Data is Protected

✅ HTTPS encryption (all data in transit)  
✅ Database encryption (data at rest)  
✅ Regular backups (daily)  
✅ Access controls (role-based)  
✅ Audit logging (all changes tracked)  

### What You Should Know

- Your login is personal - don't share
- Passwords are never stored in plain text
- All deletions are logged
- You can only see companies assigned to you (unless admin)
- Reports can be accessed by authorized users only

---

## Next Steps

### For New Users: First Week Checklist

- [ ] Complete login and change password
- [ ] Familiarize yourself with Dashboard
- [ ] Add 2-3 test companies
- [ ] Add contacts to those companies
- [ ] Log 5 practice activities
- [ ] Review the Lead Management section
- [ ] Create your first email campaign
- [ ] Ask admin any questions

### For Team Leads: Setup Checklist

- [ ] Configure email settings (SMTP)
- [ ] Set up team members and roles
- [ ] Import existing company data (if needed)
- [ ] Create initial playbooks
- [ ] Set up email templates
- [ ] Configure automations
- [ ] Train your team on this guide

---

## Version Information

**System:** CRM Starz Morocco  
**Version:** 1.0.0  
**Framework:** Symfony 7.4.15 (7.3.5 at the time this guide was written)  
**Database:** MySQL 8 (dev port 3308)  
**Built for:** Moroccan Manufacturing Supply Chain  

---

## Contact & Support

**For Technical Issues:**
- Email: admin@crm-starz.dev
- Response time: 24 hours

**For Feature Requests:**
- Submit via system feedback form
- Or email: features@crm-starz.dev

**For General Questions:**
- Check this guide first (Ctrl+F to search)
- Ask team lead
- Contact admin

---

**Last Updated:** October 29, 2025  
**Guide Version:** 1.0  
**For:** CRM Starz Morocco v1.0.0

---

## Appendix: Glossary

| Term | Definition |
|------|-----------|
| **ABM** | Account-Based Marketing - Targeting specific companies |
| **Lead** | Prospect company discovered by system needing review |
| **Tier** | Priority level of company (A/B/C) |
| **Pipeline Stage** | Position in sales cycle (Prospect → Award) |
| **RFQ** | Request for Quote from a customer |
| **Engagement Score** | Metric showing how active relationship is (0-100) |
| **Campaign** | Email marketing to multiple recipients |
| **Activity** | Logged interaction (call, email, meeting, etc.) |
| **Playbook** | Automated outreach sequence |
| **Contact** | Individual person at a company |

---

**Happy selling! 🚀**

For more detailed information on specific modules, see the documentation in the `Documentation/08-User-Guides/` folder.
