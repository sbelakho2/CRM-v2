# Quick Start: Enable Automatic Reply/Bounce Tracking

## ✅ What I Just Implemented For You

1. **Webhook Controller** - Receives events from email providers
2. **4 Webhook Endpoints**:
   - `/webhook/email/mailgun` - For Mailgun
   - `/webhook/email/sendgrid` - For SendGrid
   - `/webhook/email/postmark` - For Postmark
   - `/webhook/email/generic` - For testing/custom
3. **Email Headers** - Every email now includes tracking IDs
4. **Public Access** - Webhooks don't require authentication
5. **Logging** - All webhook events are logged for debugging

## 🚀 Quick Setup (5 minutes)

### Option 1: Mailgun (RECOMMENDED - 5,000 free emails/month)

1. **Sign up**: https://www.mailgun.com/ (Free)

2. **Get your credentials** from Mailgun dashboard:

   - Go to **Sending** → **Domain settings** → **SMTP credentials**
   - Copy your SMTP username and password

3. **Update `.env` file**:

   ```env
   # Replace your current MAILER_DSN with:
   MAILER_DSN=smtp://postmaster@YOUR_DOMAIN:YOUR_PASSWORD@smtp.mailgun.org:587
   MAILER_FROM_ADDRESS=noreply@YOUR_DOMAIN
   ```

   Example (sandbox domain for testing):

   ```env
   MAILER_DSN=smtp://postmaster@sandbox123abc.mailgun.org:abc123def456@smtp.mailgun.org:587
   MAILER_FROM_ADDRESS=noreply@sandbox123abc.mailgun.org
   ```

4. **Set up webhook** (for production, use ngrok for localhost):

   - Go to **Sending** → **Webhooks**
   - Add webhook: `https://yourdomain.com/webhook/email/mailgun`
   - Select all events (opened, clicked, bounced, etc.)

5. **Test**:
   - Send a campaign email
   - Open it
   - Check campaign page - it should auto-update! ✅

### For Localhost Testing: Use ngrok

```bash
# 1. Download ngrok: https://ngrok.com/download
# 2. Run ngrok
ngrok http 8000

# 3. Copy the HTTPS URL (e.g., https://abc123.ngrok.io)
# 4. Use in Mailgun webhook: https://abc123.ngrok.io/webhook/email/mailgun
```

## 📋 What You'll Get

### Before (Current):

- ✅ Click tracking works (URL redirect)
- ⚠️ Open tracking (pixel - blocked by some email clients)
- ❌ Reply tracking (manual only)
- ❌ Bounce tracking (manual only)

### After (With Webhooks):

- ✅ Click tracking (works better via webhook)
- ✅ Open tracking (more reliable via webhook)
- ✅ Reply tracking (automatic via webhook) **NEW!**
- ✅ Bounce tracking (automatic via webhook) **NEW!**
- ✅ Spam complaint tracking **NEW!**
- ✅ Delivery confirmation **NEW!**

## 🔍 How to Test

### 1. Test Webhook Endpoint (Works Now!)

```bash
# In PowerShell
Invoke-WebRequest -Uri "http://127.0.0.1:8000/webhook/email/generic" `
  -Method POST `
  -ContentType "application/json" `
  -Body '{"email_send_id":8,"event":"replied"}'
```

### 2. Check Database

```bash
php bin/console dbal:run-sql "SELECT id, opened, clicked, replied FROM email_sends WHERE id = 8"
```

You should see `replied = 1` now!

### 3. Check Campaign Page

Go to your campaign page and you'll see a purple dot next to the email! 🟣

## 📊 Comparison: Current vs With Webhooks

| Tracking Type          | Current (Pixel/URL)              | With Webhooks   | Best?    |
| ---------------------- | -------------------------------- | --------------- | -------- |
| **Opens**              | 🟡 Sometimes (if images enabled) | ✅ Always       | Webhooks |
| **Clicks**             | ✅ Yes                           | ✅ Yes          | Both     |
| **Replies**            | ❌ Manual only                   | ✅ Automatic    | Webhooks |
| **Bounces**            | ❌ Manual only                   | ✅ Automatic    | Webhooks |
| **Spam Reports**       | ❌ No                            | ✅ Yes          | Webhooks |
| **Works on localhost** | ✅ Yes                           | ⚠️ Needs ngrok  | -        |
| **Requires setup**     | ❌ No                            | ⚠️ Yes (5 min)  | -        |
| **Cost**               | Free                             | Free (5k/month) | Both     |

## 💡 Recommendation

**Use BOTH methods together:**

- Keep current pixel/URL tracking (works without extra setup)
- Add webhook tracking (more reliable + bounce/reply detection)

This way you get:

- **Maximum reliability** - If pixel fails, webhook catches it
- **Bounce/Reply tracking** - Only webhooks can do this
- **No downside** - They work together seamlessly

## 🎯 Next Steps

1. **Choose**: Mailgun (recommended) or SendGrid or Postmark
2. **Sign up**: Takes 2 minutes
3. **Update `.env`**: Copy/paste SMTP credentials
4. **Set webhook**: Point to your domain (or ngrok for testing)
5. **Test**: Send email and watch it auto-track!

## 📚 Full Documentation

See `/Documentation/EMAIL_WEBHOOK_INTEGRATION.md` for:

- Detailed setup for all 3 providers
- Production deployment guide
- Security best practices
- Troubleshooting tips

## ❓ Questions?

**Q: Do I need to change my current setup?**
A: No! Current tracking still works. Webhooks are additional/optional.

**Q: What if I don't set up webhooks?**
A: Everything works as before. Manual marking for replies/bounces.

**Q: Is this secure?**
A: Yes. Webhooks are public (like tracking pixels) but only contain event data, not email content.

**Q: Does this work with Infomaniak?**
A: No, Infomaniak doesn't support webhooks. But Mailgun has 5k free emails/month!

## ✅ Ready to Go!

All code is already implemented. Just:

1. Sign up for Mailgun
2. Update `.env`
3. Set webhook URL
4. Done! Automatic tracking enabled 🎉
