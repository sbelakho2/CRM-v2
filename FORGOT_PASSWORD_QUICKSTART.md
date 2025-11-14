# Forgot Password - Quick Start Guide

## ✅ Feature Complete!

The "Forgot Password" option has been successfully added to your CRM login page.

---

## 🎯 What Users See

### 1. Login Page (`/login`)

```
┌─────────────────────────────────────┐
│     STARZ Morocco CRM              │
│   Sign in to your account          │
│                                     │
│  ┌───────────────────────────────┐ │
│  │ Email address                 │ │
│  └───────────────────────────────┘ │
│  ┌───────────────────────────────┐ │
│  │ Password                      │ │
│  └───────────────────────────────┘ │
│                                     │
│  Forgot your password? ← NEW LINK  │
│                                     │
│  ┌───────────────────────────────┐ │
│  │      Sign in                  │ │
│  └───────────────────────────────┘ │
└─────────────────────────────────────┘
```

### 2. Forgot Password Page (`/forgot-password`)

```
┌─────────────────────────────────────┐
│     Reset Your Password            │
│  Enter your email address and      │
│  we'll send you a link to reset    │
│                                     │
│  ┌───────────────────────────────┐ │
│  │ Email address                 │ │
│  └───────────────────────────────┘ │
│                                     │
│  ┌───────────────────────────────┐ │
│  │   Send Reset Link             │ │
│  └───────────────────────────────┘ │
│                                     │
│      Back to Login                 │
└─────────────────────────────────────┘
```

### 3. Email Received

```
Subject: Password Reset Request - STARZ Morocco CRM

🔐 Password Reset Request

Hello [Name],

We received a request to reset your password.

┌─────────────────────────────┐
│   Reset My Password        │
└─────────────────────────────┘

⏰ This link will expire in 1 hour.
```

### 4. Reset Password Page (`/reset-password/{token}`)

```
┌─────────────────────────────────────┐
│    Create New Password             │
│  Please enter your new password    │
│                                     │
│  New Password                      │
│  ┌───────────────────────────────┐ │
│  │ ••••••••                      │ │
│  └───────────────────────────────┘ │
│  Must be at least 8 characters     │
│                                     │
│  Confirm Password                  │
│  ┌───────────────────────────────┐ │
│  │ ••••••••                      │ │
│  └───────────────────────────────┘ │
│                                     │
│  ┌───────────────────────────────┐ │
│  │    Reset Password             │ │
│  └───────────────────────────────┘ │
│                                     │
│      Back to Login                 │
└─────────────────────────────────────┘
```

---

## 🚀 Testing It Now

### Quick Test (5 minutes)

1. **Open your CRM login page:**

   ```
   https://127.0.0.1:8000/login
   ```

2. **Click "Forgot your password?"**

   - Should see the forgot password form

3. **Enter a valid user email and submit**

   - Should show success message
   - Should redirect to login page

4. **Check your email inbox**

   - Should receive password reset email
   - Click the "Reset My Password" button

5. **Enter new password (8+ characters)**

   - Enter same password in both fields
   - Click "Reset Password"

6. **Login with new password**
   - Success! ✅

---

## 📋 Technical Details

### New Routes Available

- `GET/POST /forgot-password` - Request password reset
- `GET/POST /reset-password/{token}` - Reset password with token

### Database Changes

```sql
-- New columns in users table
reset_token VARCHAR(100) DEFAULT NULL
reset_token_expires_at DATETIME DEFAULT NULL

-- New index for performance
INDEX IDX_reset_token ON users (reset_token)
```

### Security Features

✅ Secure random tokens (64 hex characters)  
✅ 1-hour token expiration  
✅ Single-use tokens  
✅ Email enumeration protection  
✅ Password validation (min 8 chars)  
✅ CSRF protection

---

## 🎨 Customization Options

### Change Token Expiration Time

Edit `SecurityController.php` line ~52:

```php
// Default: 1 hour
$user->setResetTokenExpiresAt(new \DateTime('+1 hour'));

// Change to 30 minutes:
$user->setResetTokenExpiresAt(new \DateTime('+30 minutes'));

// Change to 2 hours:
$user->setResetTokenExpiresAt(new \DateTime('+2 hours'));
```

### Change Password Requirements

Edit `SecurityController.php` line ~82:

```php
// Default: minimum 8 characters
if (strlen($password) < 8) {
    // Error message
}

// Change to 12 characters:
if (strlen($password) < 12) {
    $this->addFlash('error', 'Password must be at least 12 characters long.');
}
```

### Customize Email Template

Edit `templates/emails/reset_password.html.twig`:

- Change colors
- Add company logo
- Modify text/styling
- Add additional security tips

---

## 📧 Email Configuration

Make sure your `.env` file has correct email settings:

```bash
# Email settings (already configured)
MAILER_DSN=***REMOVED***
MAILER_FROM_ADDRESS=contact@starzelectronics.site
MAILER_FROM_NAME="Starz Electronics"
```

Test email sending:

```bash
php bin/console swiftmailer:email:send --to=your@email.com
```

---

## 🔧 Troubleshooting

### "Forgot your password?" link not showing

- Clear cache: `php bin/console cache:clear`
- Refresh browser: Ctrl+F5

### Email not received

1. Check spam folder
2. Verify email settings in `.env`
3. Check logs: `var/log/dev.log`
4. Test SMTP connection

### "Invalid or expired token" error

- Token expires after 1 hour
- Request a new reset link
- Each token can only be used once

### Can't access /forgot-password page

- Clear cache: `php bin/console cache:clear`
- Check routes: `php bin/console debug:router | grep forgot`

---

## ✨ What's Next?

The feature is **ready to use** right now! Consider these future enhancements:

1. **Rate Limiting** - Prevent abuse
2. **Email Queue** - Async email sending
3. **Password History** - Prevent password reuse
4. **Two-Factor Auth** - Extra security layer
5. **Audit Logging** - Track reset attempts

---

## 📚 Documentation

Full documentation available at:

- `Documentation/FORGOT_PASSWORD_FEATURE.md` - Complete technical docs
- This file - Quick start guide

---

**Status:** ✅ Ready for Production Use  
**Implementation Date:** November 13, 2025  
**No Additional Setup Required**

Try it now: https://127.0.0.1:8000/login
