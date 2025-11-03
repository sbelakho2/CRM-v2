# LeadBot Quick Reference

## 🚀 Access Points

| Page | URL | Purpose |
|------|-----|---------|
| Leads Home | `/leads` | Overview & quick stats |
| Review Leads | `/leads/review` | Daily review workflow |
| Dashboard | `/leads/dashboard` | KPIs & regional stats |
| Export | `/leads/export` | CSV download |

## 📊 Regional Tags

| Region | Tag Value | Drop Time |
|--------|-----------|-----------|
| Morocco | `morocco` | 08:00 Africa/Tunis |
| US East Coast | `us_east` | 08:30 America/New_York |
| US Texas | `us_texas` | 08:30 America/Chicago |
| EU Core | `eu_core` | 08:30 Europe/Paris |
| EU Nordics | `eu_nordics` | 08:30 Europe/Paris |
| EU CEE | `eu_cee` | 08:30 Europe/Paris |
| UK | `uk` | 08:30 Europe/London |

## 🎯 Lead Scores

| Score Range | Status | Badge Color | Action |
|-------------|--------|-------------|--------|
| 70-100 | Excellent | Green | Auto-recommend |
| 55-69 | Good | Yellow | Review recommended |
| 30-54 | Fair | Secondary | Manual review |
| 0-29 | Poor | Red | Auto-drop |

## ✅ Review Workflow

### Daily Routine (08:00 or 08:30)
1. **Check Dashboard** → See new lead count
2. **Filter by Region** → Select target region
3. **Sort by Score** → Top scores first
4. **Quick Review**:
   - Green badges (70+) → Approve quickly
   - Yellow badges (55-69) → Review carefully
   - Check portal URLs & contact info
5. **Actions**:
   - ✓ Approve → Moves to approved list
   - ✗ Deny → Add reason, removed from queue
6. **Convert Approved** → Click "Convert" to create Company
7. **Export** → Download CSV for team

## 🔍 Key Fields to Check

### High-Value Signals
- **Lead Score**: Higher = better fit
- **Region Tag**: Geographic match
- **Sector Tags**: Industry alignment
- **Supplier Portal URL**: Easy onboarding
- **Contact Emails**: Direct procurement contacts
- **Quality Stack**: Certifications (IATF, AS9100, CE)

### Red Flags
- No contact info (emails or forms)
- Score < 30 (too low)
- Already in CRM (duplicate)
- Defense flag (if not targeting defense)

## 🎨 UI Elements

### Status Badges
- 🟡 **Pending** - Awaiting review
- 🟢 **Approved** - Ready for conversion
- 🔴 **Denied** - Rejected with reason

### Action Buttons
- ✓ **Approve** - Mark as valid lead
- ✗ **Deny** - Reject with reason
- 🏢 **Convert** - Create Company entity
- 👁️ **View** - Open company record

## 📈 Success Metrics

### Target KPIs
- **Precision @ Top-50**: ≥75%
- **Median Review Time**: ≤45 seconds
- **Net-New Per Week**: ≥10 leads
- **Approval Rate**: Track weekly

### Where to Check
- **Dashboard** → Precision @ Top-50 card
- **Dashboard** → Weekly Performance card
- **Review Page** → Regional stats summary

## 🔧 Quick Commands

### Export Leads by Region
```
/leads/export?region=morocco&status=approved
```

### Filter Reviews
```
/leads/review?region=us_east&score_min=70&status=pending
```

## 💡 Pro Tips

1. **Focus on High Scores First** - 70+ scores have highest conversion
2. **Check Portal URLs** - Direct portal link = faster onboarding
3. **Review Morocco Leads Daily** - Primary batch at 08:00
4. **Batch Convert on Fridays** - Convert approved leads to companies
5. **Track Deny Reasons** - Helps improve crawler over time
6. **Use Regional Filters** - Focus on one region per session
7. **Export for Team Meetings** - CSV for weekly reviews

## 🚨 Troubleshooting

### No Leads Showing
- Check region filter (might be set to region with no leads)
- Check status filter (set to "Pending" to see new leads)
- Verify crawler has run (check dashboard for last run time)

### Can't Approve Lead
- Check if already approved/denied
- Verify you're logged in
- Clear browser cache if AJAX not working

### Duplicate Leads
- System checks dupe_key automatically
- Manual check: search company name in Companies list
- If duplicate, deny with reason "Already in CRM"

## 📞 Support

**Technical Issues**: Check `LEADBOT_INTEGRATION.md`  
**Configuration**: See `config/crawler_config.yaml`  
**Implementation Guide**: Read `CRAWLER_IMPLEMENTATION.md`  
**Compliance**: Review `CRAWLER_COMPLIANCE.md`  

---

**Last Updated**: October 28, 2025  
**Version**: 1.0  
**Status**: Production-Ready ✅
