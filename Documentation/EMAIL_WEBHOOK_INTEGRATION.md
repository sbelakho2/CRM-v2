# Email Tracking Integration Guide

## Overview

This guide shows you how to integrate automatic reply and bounce tracking using email service provider webhooks.

## What's Included

✅ **Webhook endpoints for 3 major email providers:**

- Mailgun (recommended - free tier available)
- SendGrid (popular choice)
- Postmark (excellent deliverability)
- Generic endpoint for custom integrations

✅ **Automatic tracking for:**

- Email Opens
- Link Clicks
- Email Bounces
- Spam Complaints
- Delivery Status

## Option 1: Mailgun (Recommended - FREE)

### Why Mailgun?

- **Free Tier**: 5,000 emails/month free
- **Easy Setup**: Simple webhook configuration
- **Good Deliverability**: High inbox placement rates
- **Great Documentation**: Well-documented API

### Setup Steps:

#### 1. Create Mailgun Account

1. Go to https://www.mailgun.com/
2. Sign up for free account
3. Verify your domain (or use sandbox domain for testing)

#### 2. Configure SMTP in `.env`

```env
MAILER_DSN=smtp://postmaster@YOUR_DOMAIN:YOUR_API_KEY@smtp.mailgun.org:587
MAILER_FROM_ADDRESS=noreply@YOUR_DOMAIN
```

Example with sandbox domain:

```env
MAILER_DSN=smtp://postmaster@sandboxXXXXX.mailgun.org:your-api-key@smtp.mailgun.org:587
MAILER_FROM_ADDRESS=noreply@sandboxXXXXX.mailgun.org
```

#### 3. Set Up Webhook in Mailgun Dashboard

1. Go to **Sending** → **Webhooks**
2. Click **Add webhook**
3. Set webhook URL: `https://yourdomain.com/webhook/email/mailgun`
4. Select events:
   - ✅ delivered
   - ✅ opened
   - ✅ clicked
   - ✅ bounced
   - ✅ complained
   - ✅ unsubscribed

#### 4. Test Webhook

```bash
# Send test email from your campaign
# Check logs to verify webhook is receiving events
tail -f var/log/dev.log
```

---

## Option 2: SendGrid

### Why SendGrid?

- **Free Tier**: 100 emails/day free
- **Popular**: Widely used and trusted
- **Advanced Features**: A/B testing, analytics
- **Good Support**: Extensive documentation

### Setup Steps:

#### 1. Create SendGrid Account

1. Go to https://sendgrid.com/
2. Sign up for free account
3. Verify your sender identity

#### 2. Configure SMTP in `.env`

```env
MAILER_DSN=smtp://apikey:YOUR_API_KEY@smtp.sendgrid.net:587
MAILER_FROM_ADDRESS=noreply@yourdomain.com
```

#### 3. Set Up Webhook

1. Go to **Settings** → **Mail Settings** → **Event Webhook**
2. Enable Event Webhook
3. HTTP Post URL: `https://yourdomain.com/webhook/email/sendgrid`
4. Select events:
   - ✅ Delivered
   - ✅ Opened
   - ✅ Clicked
   - ✅ Bounced
   - ✅ Spam Reports

#### 4. Add Custom Arguments

SendGrid requires you to add custom arguments in the email. This is already done in `EmailCampaignService.php` via headers.

---

## Option 3: Postmark

### Why Postmark?

- **Best Deliverability**: Industry-leading inbox placement
- **Free Tier**: 100 emails/month free
- **Fast**: Very quick email delivery
- **Clean Interface**: User-friendly dashboard

### Setup Steps:

#### 1. Create Postmark Account

1. Go to https://postmarkapp.com/
2. Sign up for free account
3. Create a server (e.g., "CRM Production")

#### 2. Configure SMTP in `.env`

```env
MAILER_DSN=smtp://YOUR_SERVER_TOKEN:YOUR_SERVER_TOKEN@smtp.postmarkapp.com:587
MAILER_FROM_ADDRESS=noreply@yourdomain.com
```

#### 3. Set Up Webhook

1. Go to your Server → **Webhooks**
2. Add webhook URL: `https://yourdomain.com/webhook/email/postmark`
3. Select events:
   - ✅ Open
   - ✅ Click
   - ✅ Bounce
   - ✅ Spam Complaint
   - ✅ Delivery

---

## For Localhost Testing

### Option A: Use ngrok (Easiest)

1. **Install ngrok**: https://ngrok.com/download
2. **Run ngrok**:
   ```bash
   ngrok http 8000
   ```
3. **Copy the HTTPS URL** (e.g., `https://abc123.ngrok.io`)
4. **Use in webhook configuration**:
   ```
   https://abc123.ngrok.io/webhook/email/mailgun
   ```

### Option B: Use LocalTunnel

```bash
npm install -g localtunnel
lt --port 8000
```

### Option C: Deploy to Production

If you deploy to a server with public domain, use:

```
https://yourdomain.com/webhook/email/mailgun
```

---

## Testing the Integration

### 1. Test Webhook Endpoint

```bash
# Test with curl
curl -X POST https://yourdomain.com/webhook/email/generic \
  -H "Content-Type: application/json" \
  -d '{
    "email_send_id": 1,
    "event": "opened"
  }'
```

### 2. Send Test Email

1. Go to your campaign
2. Send email to yourself
3. Open the email
4. Click a link
5. Check the campaign page - status should update automatically!

### 3. Check Logs

```bash
# Watch logs in real-time
tail -f var/log/dev.log | grep webhook

# Or in PowerShell
Get-Content var/log/dev.log -Wait -Tail 50 | Select-String "webhook"
```

---

## How It Works

### Email Flow with Webhooks:

```
1. You send campaign email
   ↓
2. Email sent via Mailgun/SendGrid/Postmark
   ↓
3. Recipient opens email
   ↓
4. Email provider detects open
   ↓
5. Email provider sends webhook to your server
   POST /webhook/email/mailgun
   {
     "event": "opened",
     "user-variables": {
       "email_send_id": 123
     }
   }
   ↓
6. Your webhook controller receives event
   ↓
7. Database updated: opened = 1
   ↓
8. Campaign page shows green dot ✅
```

### Custom Headers Added to Emails:

Every email now includes:

- `X-Email-Send-ID`: Database ID of email_sends record
- `X-Campaign-ID`: Campaign ID
- `X-Touch-Number`: Which touch (1-5)
- `X-Mailgun-Variables`: JSON data available in webhooks

These headers allow webhooks to identify which email was opened/clicked/bounced.

---

## Webhook Endpoints

All webhook endpoints are publicly accessible (no authentication required):

| Provider | Endpoint                  | Method |
| -------- | ------------------------- | ------ |
| Mailgun  | `/webhook/email/mailgun`  | POST   |
| SendGrid | `/webhook/email/sendgrid` | POST   |
| Postmark | `/webhook/email/postmark` | POST   |
| Generic  | `/webhook/email/generic`  | POST   |

---

## Security Considerations

### Webhook Authentication (Optional)

For production, you should verify webhooks are from the actual provider:

#### Mailgun Signature Verification:

```php
// In EmailWebhookController.php mailgun() method
$signature = $request->request->get('signature');
$timestamp = $signature['timestamp'] ?? '';
$token = $signature['token'] ?? '';
$receivedSignature = $signature['signature'] ?? '';

$expectedSignature = hash_hmac('sha256', $timestamp . $token, 'YOUR_WEBHOOK_SIGNING_KEY');

if (!hash_equals($expectedSignature, $receivedSignature)) {
    return new Response('Invalid signature', 403);
}
```

#### SendGrid Signature Verification:

SendGrid sends `X-Twilio-Email-Event-Webhook-Signature` header.

---

## Comparison Table

| Feature               | Mailgun     | SendGrid | Postmark  |
| --------------------- | ----------- | -------- | --------- |
| **Free Tier**         | 5,000/month | 100/day  | 100/month |
| **Setup Difficulty**  | Easy        | Medium   | Easy      |
| **Deliverability**    | Good        | Good     | Excellent |
| **Webhook Tracking**  | ✅ Yes      | ✅ Yes   | ✅ Yes    |
| **Open Tracking**     | ✅ Yes      | ✅ Yes   | ✅ Yes    |
| **Click Tracking**    | ✅ Yes      | ✅ Yes   | ✅ Yes    |
| **Bounce Tracking**   | ✅ Yes      | ✅ Yes   | ✅ Yes    |
| **Price (5k emails)** | Free        | $15/mo   | $10/mo    |

**Recommendation**: Start with **Mailgun** for the generous free tier and easy setup.

---

## Troubleshooting

### Webhooks Not Working?

1. **Check webhook URL is public**

   - Must be accessible from internet (not localhost)
   - Use ngrok for local testing

2. **Check logs**

   ```bash
   tail -f var/log/dev.log
   ```

3. **Verify webhook endpoint**

   ```bash
   curl -X POST https://yourdomain.com/webhook/email/generic \
     -H "Content-Type: application/json" \
     -d '{"email_send_id":1,"event":"opened"}'
   ```

4. **Check email service dashboard**

   - Most providers show webhook delivery status
   - Check for failed webhook attempts

5. **Verify email headers**
   - Send test email to yourself
   - View email source/headers
   - Confirm `X-Email-Send-ID` header is present

### Still Not Working?

Contact me with:

- Email service provider name
- Webhook logs from `var/log/dev.log`
- Email headers from test email
- Webhook delivery status from provider dashboard

---

## Next Steps

1. ✅ Choose an email provider (Mailgun recommended)
2. ✅ Sign up and get API credentials
3. ✅ Update `.env` with SMTP settings
4. ✅ Configure webhook in provider dashboard
5. ✅ Send test email and verify tracking works
6. ✅ (Optional) Add webhook signature verification for security

---

## Current Status

✅ **Already Implemented:**

- Webhook controllers for all major providers
- Custom headers in emails for tracking
- Public webhook routes (no auth required)
- Logging for debugging

⚠️ **You Need to Do:**

- Choose and sign up for email provider
- Configure SMTP in `.env`
- Set up webhook URL in provider dashboard
- Test with real emails

---

## Questions?

Common questions:

**Q: Do I need to pay?**
A: No! All three providers have free tiers. Mailgun gives 5,000 emails/month free.

**Q: Can I use my current SMTP?**
A: Only if your SMTP provider supports webhooks. Infomaniak (your current provider) doesn't support webhooks, so you'd need to switch to Mailgun/SendGrid/Postmark for automatic tracking.

**Q: What about localhost?**
A: Use ngrok to expose your localhost to the internet temporarily for webhook delivery.

**Q: Is my data secure?**
A: Yes. Webhooks only contain email metadata (open/click events), not email content. You can add signature verification for extra security.

**Q: Do I lose my current tracking?**
A: No! Pixel/click tracking still works. Webhooks are an additional/alternative method that's more reliable.
