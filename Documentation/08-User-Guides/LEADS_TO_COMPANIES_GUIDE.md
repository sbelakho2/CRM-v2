# Adding Leads to Companies - User Guide

## 📋 Quick Overview

The Leads system allows you to review automatically discovered companies and add the good ones to your Companies list with one click.

---

## ✅ Step-by-Step Workflow

### Step 1: Review Daily Leads
1. Navigate to **Leads** → **Review Leads** (or click sidebar "Leads")
2. You'll see a list of provisional leads sorted by score
3. Use filters to focus:
   - **Region**: Morocco, US East, US Texas, EU, UK
   - **Status**: Pending, Approved, Denied
   - **Min Score**: 0-100

### Step 2: Approve Good Leads
For each lead, check:
- ✅ **Score** (70+ is excellent, 55+ is good)
- ✅ **Company name** and website
- ✅ **Location** matches your target region
- ✅ **Sectors** align with your focus
- ✅ **Contact info** available (emails, portal, form)

**Actions:**
- Click green **✓** to approve
- Click red **✗** to deny (with reason)

### Step 3: Add to Companies List
Once a lead is approved:
1. A blue button appears: **"Add to Companies"**
2. Click it
3. Confirm the action
4. **Done!** The lead is now a company in your CRM

### Step 4: View the Company
After conversion:
- Badge shows: **✓ In CRM**
- Click **"View Company"** to see the full record
- All lead data is transferred:
  - Company name & legal name
  - Website & location
  - Contact emails & portal URLs
  - Quality certifications
  - Source notes with lead score

---

## 🎯 What Gets Transferred to Companies

When you add a lead to Companies, the system automatically creates:

### Basic Info
- ✅ **Company Name** from lead
- ✅ **Legal Name** (if available)
- ✅ **Website URL**
- ✅ **Physical Site/Location**

### Contact Details
- ✅ **Contact Emails** (in source notes)
- ✅ **Supplier Portal URL** (in source notes)
- ✅ **Contact Form URL** (in source notes)

### Categorization
- ✅ **Sector** (from lead sector tags)
- ✅ **Account Tier** (based on lead score):
  - Score 70-100 → Tier A
  - Score 55-69 → Tier B
  - Score <55 → Tier C
- ✅ **Pipeline Stage**: "Prospect" (ready for outreach)

### Rich Context
- ✅ **Source Notes** including:
  - Lead ID & conversion date
  - Lead score (e.g., 87/100)
  - Region (e.g., MOROCCO, US_EAST)
  - All contact methods
  - Quality certifications (IATF, AS9100, etc.)
  - Auto-generated crawler notes

---

## 💡 Pro Tips

### Smart Filtering
1. **Start with Morocco** - Filter by region: "morocco" for primary batch
2. **High scores first** - Set min score to 70 for quick wins
3. **Pending only** - Status: "pending" to see unreviewed leads

### Batch Processing
1. **Morning routine** (08:00 or 08:30)
   - Check dashboard for new lead count
   - Filter by today's region
   - Approve all 70+ scores
   - Review 55-69 scores carefully
   - Add approved leads to Companies

2. **Friday conversion**
   - Filter: Status = "approved"
   - Click through and add all to Companies
   - Export list for team meeting

### Quality Control
- ✅ **Check portal URLs** - Direct portal = faster onboarding
- ✅ **Verify contact info** - At least one contact method
- ✅ **Match target sectors** - Don't add unrelated industries
- ✅ **Geographic relevance** - Confirm location makes sense

---

## 🚨 Common Scenarios

### "Lead must be approved before conversion"
**Solution**: Click the green ✓ button first to approve the lead, then click "Add to Companies"

### "Lead already converted to company"
**Good!** This means the lead is already in your CRM. Click "View Company" to see it.

### Duplicate company detected
If a lead looks like a duplicate:
1. Search the company name in **Companies** list
2. If it exists, click red ✗ to deny
3. Add reason: "Already in CRM"

### Missing contact information
Leads without contact info can still be added:
- System saves website URL
- You can manually research contacts later
- Portal URL might be available for registration

---

## 📊 Tracking Success

### Dashboard Metrics
Visit **Leads** → **Dashboard** to see:
- **Total leads** discovered
- **Pending review** count (action needed)
- **Approved** count (ready to add)
- **Precision @ Top-50** (quality metric)
- **Weekly approval rate**

### Regional Performance
Dashboard shows breakdown by region:
- Morocco, US East, US Texas, EU, UK
- Average score per region
- Total leads per region

---

## 🔄 Full Workflow Example

### Morning: New Morocco Leads (08:00)
```
1. Dashboard shows: "12 new leads - Morocco"
2. Go to Review Leads
3. Filter: Region = morocco, Status = pending
4. See 12 leads sorted by score

Lead #1: "Yazaki Morocco" - Score 87
  ✓ Automotive sector ✓
  ✓ TAC location ✓
  ✓ Supplier portal available ✓
  → Click green ✓ to approve

Lead #2: "Local bakery" - Score 23
  ✗ Not manufacturing
  → Click red ✗ to deny: "Wrong industry"

... repeat for all 12 ...

5. Result: 8 approved, 4 denied
6. Filter: Status = approved
7. Click "Add to Companies" for all 8
8. Export CSV for team
```

### Result
✅ 8 new companies in CRM  
✅ Ready for owner assignment  
✅ Ready for compliance pack prep  
✅ Ready for outreach campaigns  

---

## 🚀 Automated Outreach After Conversion

Once a lead is converted to a company, the CRM can automatically send email campaigns:

### Available Automation Triggers
- **Lead Conversion Complete**: Triggers "Welcome" email sequence
- **Pipeline Stage Change**: Auto-sends emails when you move company through pipeline
- **RFQ Submission**: Sends follow-up email when company submits RFQ
- **Quote Sent**: Sends quote follow-up email automatically
- **Lead Score Change**: Triggers nurture content when company scores increase

### Setting Up Automated Campaigns
1. Go to **Email Campaigns** → **New Campaign**
2. Select **Trigger Type** (e.g., "Lead Conversion")
3. Set trigger conditions
4. Select email template (or create new)
5. Configure recipient segment (all companies, by tier, etc.)
6. Click **Activate** - campaigns will send automatically

### Example: Welcome Sequence
**Trigger**: Lead → Company Conversion  
**Actions**:
- Day 0: Welcome email with company overview
- Day 3: Value proposition email
- Day 7: Case study/proof email
- Day 14: Special offer email
- Day 30: Check-in email

The system handles all sends automatically and tracks opens, clicks, and replies in the company activity timeline.

---

## 🎯 Success Criteria

A lead is good to add if:
- ✅ Score ≥ 55 (or ≥70 for auto-add)
- ✅ Manufacturing/PCBA related
- ✅ Target geographic region
- ✅ At least one contact method
- ✅ Not already in CRM
- ✅ Legitimate business (not spam/irrelevant)

---

## 📞 Questions?

- **Technical**: See `LEADBOT_INTEGRATION.md`
- **Scoring**: See `config/crawler_config.yaml`
- **Compliance**: See `CRAWLER_COMPLIANCE.md`

---

**Last Updated**: October 29, 2025  
**Version**: 2.0  
**Status**: All 37 Tasks Complete ✅
