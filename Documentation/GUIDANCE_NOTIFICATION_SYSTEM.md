# Guidance Notification System

## Overview

The Guidance Notification System provides contextual, intelligent reminders to users as they work through the CRM. It helps ensure important follow-up actions are not forgotten and guides users through best practices.

## Key Features

### 1. **After Creating a Company**
When a user creates a new company, the system reminds them to:
- ✅ Add contacts to start building relationships
- ⚠️ Upload compliance documents (certifications, quality docs)
- ⚡ Log first activity to track engagement

### 2. **After Creating a Contact**
When a user adds a contact, the system suggests:
- 📞 Log first interaction to start tracking engagement
- 🔗 Connect on LinkedIn (if LinkedIn URL provided)

### 3. **After Logging an Activity**
Based on the activity outcome:
- **Follow-up Required**: ⏰ Reminds user to schedule follow-up
- **Successful Call/Meeting**: 🎯 Suggests sending follow-up email or creating quote

### 4. **After Converting a Lead**
When converting a lead to a company:
- ✅ Confirms successful conversion
- 👥 Reminds to add key contacts
- 📋 Reminds to request compliance documents

### 5. **Viewing a Company**
Checks for incomplete company profiles and alerts if missing:
- Website
- Sector
- Account tier
- Pipeline stage
- Contacts

### 6. **Daily Dashboard Reminders**
When visiting the dashboard, users see:
- 📋 Number of pending leads awaiting review
- 📝 Reminder to log activities if none today
- 💡 Suggestion to create email campaign (after 10+ recent activities)

### 7. **Compliance Expiry Alerts**
Proactively checks for:
- ⚠️ Compliance documents expiring within 30 days
- Shows top 3 companies needing attention

## Technical Implementation

### Service Class
**Location**: `src/Service/GuidanceNotificationService.php`

The service uses Symfony's session to store guidance notifications temporarily. Notifications are displayed once and then cleared.

### Display Component
**Location**: `templates/components/guidance_notifications.html.twig`

Automatically included in the base template for all authenticated pages. Shows notifications with:
- Color-coded by type (success/warning/info/tip)
- Appropriate icons
- Action buttons with links
- Auto-dismisses after display

### Integration Points

**Controllers Updated:**
- `CompanyController`: After company creation and when viewing
- `ContactController`: After contact creation
- `ActivityController`: After logging activity
- `DashboardController`: Daily workflow reminders

## Notification Types

### Success (Green)
Used for confirmations of completed actions:
```php
$this->guidanceService->afterLeadConverted($company, $lead);
```

### Warning (Amber)
Used for important reminders that require attention:
```php
$this->guidanceService->afterCompanyCreated($company);
```

### Info (Blue)
Used for helpful suggestions:
```php
$this->guidanceService->afterContactCreated($contact);
```

### Tip (Blue with lightbulb)
Used for best practice recommendations:
```php
$this->guidanceService->suggestEmailCampaign($user);
```

## Usage Examples

### Example 1: Company Creation Flow
```
User creates company "Acme Manufacturing"
  ↓
System shows 3 guidance notifications:
  1. "Add contacts to Acme Manufacturing" [Add Contact button]
  2. "Upload compliance documents for Acme Manufacturing" [Add Compliance button]
  3. "Log your first activity with Acme Manufacturing" [Log Activity button]
```

### Example 2: Activity Logging Flow
```
User logs phone call with "Follow-up Required" outcome
  ↓
System shows:
  "Schedule a follow-up activity for Acme Manufacturing"
  [Schedule Follow-up button]
```

### Example 3: Dashboard Daily Check
```
User visits dashboard in morning
  ↓
System checks and shows:
  1. "5 leads pending review" [Review Leads button]
  2. "No activities logged today" [Log Activity button]
  3. "2 compliance documents expiring soon for TechCorp" [Review Compliance button]
```

## Customization

### Adding New Guidance Notifications

To add a new guidance scenario, update `GuidanceNotificationService.php`:

```php
/**
 * Your new guidance method
 */
public function afterSomeAction(SomeEntity $entity): void
{
    $this->addGuidance(
        'info',                              // Type: success|warning|info|tip
        "Your guidance message here",         // Message to display
        "/path/to/action",                   // Optional: URL for action button
        'Button Label'                        // Optional: Button text
    );
}
```

Then call it from your controller:

```php
$this->guidanceService->afterSomeAction($entity);
```

### Disable Guidance Notifications

To disable guidance on a specific page, don't include the component:

```twig
{# Don't include guidance on this page #}
{% block body %}
    {# Your content without guidance #}
{% endblock %}
```

## Best Practices

### ✅ DO
- Use guidance to reinforce workflow best practices
- Provide actionable buttons when possible
- Keep messages concise and clear
- Use appropriate notification types (success/warning/info/tip)
- Show guidance immediately after the triggering action

### ❌ DON'T
- Don't overwhelm users with too many notifications at once
- Don't use guidance for error messages (use flash messages)
- Don't repeat the same guidance if user has already taken action
- Don't make guidance mandatory - it should be helpful, not blocking

## Future Enhancements

Potential improvements:
1. **User Preferences**: Allow users to customize or disable specific guidance types
2. **Smart Learning**: Track which guidance users act on and prioritize accordingly
3. **Onboarding Mode**: Enhanced guidance for first-time users
4. **Persistent Reminders**: Option to snooze and resurface important reminders
5. **Analytics**: Track guidance effectiveness and user engagement

## Dependencies

- Symfony Session component
- Doctrine ORM (for entity queries)
- Twig templating
- Tailwind CSS (for styling)

## Troubleshooting

### Notifications Not Showing
- Check that `guidance_notifications.html.twig` is included in your template
- Verify session is working correctly
- Ensure `GuidanceNotificationService` is injected in controller

### Notifications Showing Multiple Times
- Guidance notifications auto-clear after display
- If seeing duplicates, check for page refreshes or duplicate service calls

### Action Buttons Not Working
- Verify URL paths are correct
- Check route names match your routing configuration
- Ensure proper URL parameters are passed

## Related Documentation
- [NEW_USER_GUIDE.md](NEW_USER_GUIDE.md) - User workflow documentation
- [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) - System architecture
- [NotificationService.php](../src/Service/NotificationService.php) - Smart notifications (different system)

---

**Created**: December 4, 2025  
**Version**: 1.0  
**Status**: ✅ Production Ready
