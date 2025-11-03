# Notification System - Integration Test Guide

**Date**: October 30, 2025  
**Status**: Ready for browser testing  
**Integration**: Complete (sidebar + JavaScript)

---

## 🚀 Quick Start Testing

### 1. Start Development Server

```bash
# In project directory
symfony server:start

# Or if using PHP built-in server
php -S localhost:8000 -t public
```

### 2. Open Application in Browser

```
http://localhost:8000
```

### 3. Check Browser Console

Open Developer Tools (F12) and check the Console tab. You should see:

```
[Notifications] System initialized
[Notifications] Polling started (every 30s)
```

If you see these messages, the JavaScript has loaded correctly! ✅

---

## 🧪 Manual Testing Checklist

### Visual Tests

- [ ] **Bell Icon Visible**: Notification bell appears in sidebar footer (above user info)
- [ ] **Bell Styling**: Bell button matches sidebar style (same hover effect as other nav items)
- [ ] **Badge Hidden**: Red badge is hidden when count is 0
- [ ] **Modal Hidden**: Modal is not visible on page load

### Interaction Tests

- [ ] **Click Bell**: Clicking bell opens modal
- [ ] **Click Again**: Clicking bell again closes modal
- [ ] **Click Outside**: Clicking anywhere outside modal closes it
- [ ] **ESC Key**: Pressing ESC key closes modal
- [ ] **Close Button**: X button in modal header closes modal

### API Tests

#### Test 1: Check API Endpoint (No Auth)
```bash
# Should return 401 Unauthorized (expected if not logged in)
curl -X GET http://localhost:8000/api/notifications
```

#### Test 2: Create Test Notification (Via CLI)
```bash
# Run notification check command
php bin/console app:check-notifications

# Check output - should detect events and create notifications
```

#### Test 3: Verify Database
```bash
# Connect to database
php bin/console dbal:run-sql "SELECT COUNT(*) FROM notification"

# Should return count > 0 if notifications were created
```

### Functional Tests

- [ ] **Fetch Notifications**: Modal shows "No notifications" if none exist
- [ ] **Show Badge**: Badge appears with correct count (after creating test notifications)
- [ ] **Render List**: Notifications display correctly in modal
- [ ] **Mark as Read**: Clicking notification marks it as read (badge updates)
- [ ] **Mark All**: "Mark all as read" button works
- [ ] **Navigation**: Clicking notification navigates to related entity

### Polling Tests

- [ ] **Auto-Refresh**: Wait 30 seconds, verify fetch happens (check console)
- [ ] **No Spam**: Only one fetch happens per 30-second interval
- [ ] **Stop on Auth Fail**: If 401 returned, polling stops

### Mobile Tests

- [ ] **Responsive Modal**: Modal resizes correctly on mobile (< 768px width)
- [ ] **Touch Works**: Modal opens/closes on mobile touch
- [ ] **No Overflow**: Modal doesn't overflow screen on mobile

---

## 🔧 Creating Test Notifications

### Option 1: Using CLI Command

```bash
# Generate notifications based on actual data
php bin/console app:check-notifications

# This will:
# - Scan for RFQs due soon
# - Check for opened emails
# - Detect quote views
# - Find lead approvals
```

### Option 2: Direct Database Insert

```sql
-- Insert test notification
INSERT INTO notification (user_id, type, entity_type, entity_id, message, icon, created_at)
VALUES (
    1, -- Replace with your user ID
    'RFQ_DUE',
    'RFQ',
    1, -- Replace with actual RFQ ID
    'RFQ #12345 is due tomorrow',
    '⏰',
    NOW()
);
```

### Option 3: Trigger Real Events

1. **RFQ Due Soon**: Create RFQ with due date = tomorrow
2. **Email Opened**: Send email campaign, open email in browser
3. **Quote Viewed**: Create quote, mark as viewed
4. **Lead Approved**: Create lead, change status to approved

---

## 📊 Expected Behaviors

### First Load (No Notifications)
```
✅ Bell icon visible
✅ Badge hidden
✅ Console: "System initialized"
✅ Console: "Polling started"
❌ No errors in console
```

### After Creating Test Notification
```
✅ Badge appears with count "1"
✅ Badge has red background
✅ Modal shows notification item
✅ Item has icon, message, timestamp
✅ Unread indicator (blue dot) visible
```

### After Clicking Notification
```
✅ API call to /api/notifications/{id}/read
✅ Notification marked as read (grayed out)
✅ Badge count decrements
✅ Blue dot disappears
```

### After Clicking "Mark All as Read"
```
✅ API call to /api/notifications/mark-all-read
✅ All notifications grayed out
✅ Badge disappears
✅ Blue dots disappear
```

---

## 🐛 Troubleshooting

### Bell Icon Not Showing

**Symptoms**: Sidebar loads, but no bell icon  
**Possible Causes**:
- Template include not working
- File path incorrect
- Cache issue

**Solutions**:
```bash
# Clear Symfony cache
php bin/console cache:clear

# Check template exists
ls templates/components/notification_bell.html.twig

# Restart server
symfony server:stop
symfony server:start
```

### JavaScript Not Loading

**Symptoms**: No console messages, clicking bell does nothing  
**Possible Causes**:
- JS file path incorrect
- Asset serving issue
- JavaScript error

**Solutions**:
```bash
# Check file exists
ls public/js/notification-system.js

# Check browser Network tab for 404 errors
# Look for: http://localhost:8000/js/notification-system.js

# Check browser Console tab for JS errors
```

### API Returns 401

**Symptoms**: Console shows "HTTP 401" errors  
**Possible Causes**:
- User not logged in
- Session expired
- Missing authentication

**Solutions**:
- Log in to the application
- Check user session is active
- Verify DashboardController has authentication

### Badge Not Updating

**Symptoms**: Notifications exist, but badge stays hidden  
**Possible Causes**:
- API not returning correct format
- JavaScript not parsing response
- Badge CSS issue

**Solutions**:
```bash
# Test API directly
curl -X GET http://localhost:8000/api/notifications \
  -H "Cookie: PHPSESSID=your_session_id" \
  -H "Accept: application/json"

# Expected response:
{
  "unread_count": 3,
  "notifications": [...]
}
```

### Modal Positioning Wrong

**Symptoms**: Modal appears in wrong location or off-screen  
**Possible Causes**:
- CSS variable `--sidebar-width` not matching
- Z-index conflicts
- Viewport too small

**Solutions**:
```css
/* Check in base.html.twig */
:root {
    --sidebar-width: 260px; /* Must match notification CSS */
}

/* Adjust modal positioning in notification_bell.html.twig */
.notification-modal-sidebar {
    left: calc(var(--sidebar-width) + 10px);
}
```

---

## ✅ Success Criteria

**Integration Complete** when all of these work:

1. ✅ Bell icon appears in sidebar
2. ✅ Clicking bell opens modal
3. ✅ Modal displays notifications (or "No notifications")
4. ✅ Badge shows correct unread count
5. ✅ Clicking notification marks as read
6. ✅ Polling happens every 30 seconds
7. ✅ No JavaScript errors in console
8. ✅ Works on desktop and mobile viewports

---

## 📝 Next Steps After Testing

Once all tests pass:

1. **Deploy to Production** (see DEPLOYMENT_CHECKLIST.md)
2. **Train Users** on notification system
3. **Monitor Performance** (API response times, polling frequency)
4. **Move to Feature #2** (Mobile Quick Actions, 6 hours)

---

## 🔗 Related Documentation

- `FRONTEND_INTEGRATION_GUIDE.md` - Component reference
- `NOTIFICATION_API_GUIDE.md` - API documentation
- `NOTIFICATION_TESTING_GUIDE.md` - Backend testing
- `DEPLOYMENT_CHECKLIST.md` - Production deployment
- `FEATURE_1_COMPLETE.md` - Complete feature documentation

---

**Status**: Ready for testing ✅  
**Last Updated**: October 30, 2025  
**Contact**: Development Team
