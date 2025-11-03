# Analytics & Visitor Tracking - System Administrator Implementation Guide

**Version**: 1.0  
**Last Updated**: October 29, 2025  
**Audience**: System Administrators, DevOps Engineers  
**Purpose**: Comprehensive guide to implement email analytics and website visitor IP tracking

---

## Table of Contents

1. [Overview](#overview)
2. [Email Analytics Implementation](#email-analytics-implementation)
3. [Website Visitor IP Tracking](#website-visitor-ip-tracking)
4. [Integration Points](#integration-points)
5. [Monitoring & Verification](#monitoring--verification)
6. [Troubleshooting](#troubleshooting)

---

## Overview

The Starz Morocco CRM system provides:

### Email Analytics Capabilities
- Campaign performance metrics (sent, opened, clicked, unsubscribed)
- Engagement timelines and cohort analysis
- A/B test result tracking with statistical significance
- Deliverability scoring and bounce handling
- Click-through rate analysis with device/client breakdown
- Best send time recommendations

### Website Visitor Tracking
- Visitor IP-to-company mapping (de-anonymization)
- Session tracking with URL paths
- Referrer source identification
- Engagement scoring based on pages visited
- Account-based marketing triggers on visitor behavior
- Activity timeline integration

**Tracked Websites**:
- starzelectronics.com
- starzenergies.com

---

## Email Analytics Implementation

### 1.1 Database Schema for Email Analytics

The system uses these tables for email tracking:

```sql
-- Campaign records
CREATE TABLE email_campaign (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    status ENUM('draft', 'scheduled', 'sending', 'completed', 'paused') DEFAULT 'draft',
    trigger_type VARCHAR(100),
    trigger_conditions JSON,
    ab_test_variants JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Individual send records with engagement tracking
CREATE TABLE email_send (
    id INT PRIMARY KEY AUTO_INCREMENT,
    campaign_id INT NOT NULL,
    contact_id INT NOT NULL,
    email_address VARCHAR(255) NOT NULL,
    status ENUM('pending', 'sent', 'bounced', 'complained') DEFAULT 'pending',
    scheduled_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    opened_at TIMESTAMP NULL,
    clicked_at TIMESTAMP NULL,
    is_replied BOOLEAN DEFAULT FALSE,
    retry_count INT DEFAULT 0,
    failure_reason TEXT,
    variant VARCHAR(50),
    device_type VARCHAR(50),
    email_client VARCHAR(100),
    opened_count INT DEFAULT 0,
    click_count INT DEFAULT 0,
    FOREIGN KEY (campaign_id) REFERENCES email_campaign(id),
    FOREIGN KEY (contact_id) REFERENCES contact(id),
    INDEX idx_campaign (campaign_id),
    INDEX idx_sent_at (sent_at),
    INDEX idx_opened_at (opened_at),
    INDEX idx_clicked_at (clicked_at)
);

-- Bounce and complaint tracking
CREATE TABLE email_bounce (
    id INT PRIMARY KEY AUTO_INCREMENT,
    send_id INT NOT NULL,
    bounce_type ENUM('hard', 'soft', 'complaint') NOT NULL,
    bounce_reason VARCHAR(255),
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (send_id) REFERENCES email_send(id),
    INDEX idx_send_id (send_id)
);

-- Click tracking with URL details
CREATE TABLE email_click (
    id INT PRIMARY KEY AUTO_INCREMENT,
    send_id INT NOT NULL,
    url VARCHAR(2048) NOT NULL,
    clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    FOREIGN KEY (send_id) REFERENCES email_send(id),
    INDEX idx_send_id (send_id),
    INDEX idx_clicked_at (clicked_at)
);

-- A/B test tracking
CREATE TABLE email_ab_test (
    id INT PRIMARY KEY AUTO_INCREMENT,
    campaign_id INT NOT NULL,
    variant_a_name VARCHAR(100),
    variant_b_name VARCHAR(100),
    metric_tracked VARCHAR(50) DEFAULT 'open_rate',
    winner VARCHAR(1),
    confidence_level DECIMAL(5,2),
    p_value DECIMAL(10,8),
    evaluated_at TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES email_campaign(id)
);

-- Segment membership (for targeting)
CREATE TABLE email_segment (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    filter_rules JSON NOT NULL,
    logic_operator ENUM('AND', 'OR') DEFAULT 'AND',
    last_calculated_at TIMESTAMP,
    contact_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 1.2 Email Tracking Implementation

**Step 1: Enable Tracking Parameters**

Update `.env.local`:

```bash
# Email tracking
EMAIL_TRACKING_ENABLED=true
EMAIL_TRACKING_DOMAIN=starz-morocco.com
EMAIL_TRACKING_PIXEL_URL=https://crm.starz-morocco.com/track/pixel
EMAIL_CLICK_TRACKING_URL=https://crm.starz-morocco.com/track/click
EMAIL_UNSUBSCRIBE_URL=https://crm.starz-morocco.com/unsubscribe

# Analytics aggregation
EMAIL_ANALYTICS_RETENTION_DAYS=365
EMAIL_BOUNCE_ARCHIVE_DAYS=30
EMAIL_STATS_CALC_INTERVAL=3600
```

**Step 2: Configure Tracking Routes**

Ensure these routes exist in `src/Controller/EmailTrackingController.php`:

```php
/**
 * Pixel tracking for email opens
 * URL: GET /track/pixel/{sendId}.gif
 */
public function trackPixel(int $sendId, Request $request): Response

/**
 * Click tracking and redirect
 * URL: GET /track/click/{sendId}?url=...
 */
public function trackClick(int $sendId, Request $request): RedirectResponse

/**
 * Bounce webhook receiver (from mail provider)
 * URL: POST /webhooks/bounces
 */
public function handleBounce(Request $request): Response

/**
 * Complaint webhook receiver (from mail provider)
 * URL: POST /webhooks/complaints
 */
public function handleComplaint(Request $request): Response
```

**Step 3: Set Up Webhook Receivers**

Configure webhooks from your email provider (SendGrid, SES, etc.):

```bash
# SendGrid Webhooks (Admin Dashboard)
POST https://crm.starz-morocco.com/webhooks/bounces
POST https://crm.starz-morocco.com/webhooks/complaints

# AWS SES SNS Topics
SNS Topic: arn:aws:sns:us-east-1:xxx:email-events
Endpoint: https://crm.starz-morocco.com/webhooks/ses-events
```

**Step 4: Configure Email Template Tracking**

Update Twig email templates to include tracking pixel:

```twig
{# In email templates #}
{% if emailSendId %}
    <img src="{{ trackingPixelUrl(emailSendId) }}" width="1" height="1" alt="" />
{% endif %}

{# For tracked links #}
<a href="{{ trackClickUrl(emailSendId, url) }}">Click here</a>
```

### 1.3 Analytics Dashboard Setup

**Step 1: Create Analytics Aggregation Job**

Create `src/Command/AggregateEmailStatsCommand.php`:

```php
/**
 * Hourly aggregation of email statistics
 * Usage: php bin/console app:aggregate-email-stats
 * 
 * Calculates:
 * - Overall campaign metrics (sent, opened, clicked, unsubscribed)
 * - Per-touch metrics (for drip campaigns)
 * - Deliverability scores
 * - Best send time recommendations
 */
```

**Step 2: Schedule Aggregation**

Configure in `config/packages/scheduler.yaml`:

```yaml
framework:
    messenger:
        buses:
            commands: ~
    
# Schedule periodic stats aggregation
messenger:
    routing:
        'App\Message\AggregateEmailStatsCommand': commands
```

Add to crontab:

```bash
# Aggregate email stats hourly
0 * * * * cd /var/www/crm && php bin/console app:aggregate-email-stats --env=prod

# Archive old tracking data (monthly)
0 3 1 * * cd /var/www/crm && php bin/console app:archive-email-tracking --env=prod
```

**Step 3: Cache Configuration**

Update `.env.local` for caching analytics:

```bash
# Redis for analytics caching (recommended for performance)
REDIS_URL=redis://localhost:6379/0
ANALYTICS_CACHE_TTL=300  # 5 minutes
TOP_CAMPAIGNS_CACHE_TTL=3600  # 1 hour
```

---

## Website Visitor IP Tracking

### 2.1 IP Tracking Architecture

**Components**:

1. **Tracking Script** - JavaScript on websites (starzelectronics.com, starzenergies.com)
2. **Event Receiver** - API endpoint to collect visitor data
3. **IP-to-Company Mapper** - Service to resolve company from IP
4. **ABM Playbook Engine** - Trigger actions on visitor behavior

### 2.2 Installation on Websites

**Step 1: Generate Tracking Script**

Generate unique tracking ID for each website in CRM:

```php
// In CRM admin panel
php bin/console app:generate-tracking-script starzelectronics.com
// Output: tracking_abc123.js

php bin/console app:generate-tracking-script starzenergies.com
// Output: tracking_def456.js
```

**Step 2: Install Tracking Script on starzelectronics.com**

Add to `<head>` section of website (all pages):

```html
<!-- Starz Electronics Visitor Tracking -->
<script>
(function() {
    var trackingId = 'abc123';  // Generated from CRM
    var crmHost = 'https://crm.starz-morocco.com';
    var pageTitle = document.title;
    var pageUrl = window.location.href;
    var referrer = document.referrer;
    var timestamp = new Date().toISOString();
    
    // Create visitor session if not exists
    var sessionId = localStorage.getItem('starz_session_id');
    if (!sessionId) {
        sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem('starz_session_id', sessionId);
    }
    
    // Send page view event
    var payload = {
        tracking_id: trackingId,
        session_id: sessionId,
        page_title: pageTitle,
        page_url: pageUrl,
        referrer: referrer,
        timestamp: timestamp,
        user_agent: navigator.userAgent,
        screen_resolution: window.screen.width + 'x' + window.screen.height
    };
    
    // Send event via beacon API (doesn't block navigation)
    if (navigator.sendBeacon) {
        navigator.sendBeacon(crmHost + '/api/v1/track/page-view', JSON.stringify(payload));
    } else {
        // Fallback to fetch
        fetch(crmHost + '/api/v1/track/page-view', {
            method: 'POST',
            body: JSON.stringify(payload),
            headers: {'Content-Type': 'application/json'},
            keepalive: true
        });
    }
})();
</script>
```

**Step 3: Install Tracking Script on starzenergies.com**

Same as above, but with `trackingId = 'def456'`:

```html
<!-- Starz Energies Visitor Tracking -->
<script>
(function() {
    var trackingId = 'def456';  // Different tracking ID
    // ... same code as above ...
})();
</script>
```

### 2.3 Event Receiver API Endpoint

Create `src/Controller/Api/VisitorTrackingController.php`:

```php
<?php

namespace App\Controller\Api;

use App\Entity\WebEvent;
use App\Service\IpToCompanyResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Receives visitor tracking events from websites
 */
#[Route('/api/v1/track')]
class VisitorTrackingController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private IpToCompanyResolver $ipResolver
    ) {}

    /**
     * Receive page view events
     * POST /api/v1/track/page-view
     */
    #[Route('/page-view', methods: ['POST'])]
    public function pageView(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        // Create web event record
        $event = new WebEvent();
        $event->setTrackingId($data['tracking_id']);
        $event->setSessionId($data['session_id']);
        $event->setPageTitle($data['page_title']);
        $event->setPageUrl($data['page_url']);
        $event->setReferrer($data['referrer']);
        $event->setTimestamp(new \DateTime($data['timestamp']));
        $event->setIpAddress($this->getClientIp($request));
        $event->setUserAgent($data['user_agent']);
        $event->setScreenResolution($data['screen_resolution']);

        // Resolve company from IP
        $company = $this->ipResolver->resolveCompany($event->getIpAddress());
        if ($company) {
            $event->setCompany($company);
        }

        // Persist event
        $this->em->persist($event);
        $this->em->flush();

        return $this->json(['status' => 'tracked']);
    }

    /**
     * Handle click events
     * POST /api/v1/track/click
     */
    #[Route('/click', methods: ['POST'])]
    public function click(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        $event = new WebEvent();
        $event->setTrackingId($data['tracking_id']);
        $event->setSessionId($data['session_id']);
        $event->setEventType('click');
        $event->setEventData($data);
        $event->setIpAddress($this->getClientIp($request));

        $company = $this->ipResolver->resolveCompany($event->getIpAddress());
        if ($company) {
            $event->setCompany($company);
        }

        $this->em->persist($event);
        $this->em->flush();

        return $this->json(['status' => 'tracked']);
    }

    private function getClientIp(Request $request): string
    {
        // Get real IP behind proxy/load balancer
        if ($request->server->get('HTTP_CLIENT_IP')) {
            return $request->server->get('HTTP_CLIENT_IP');
        } elseif ($request->server->get('HTTP_X_FORWARDED_FOR')) {
            return explode(',', $request->server->get('HTTP_X_FORWARDED_FOR'))[0];
        }
        return $request->getClientIp();
    }
}
```

### 2.4 IP-to-Company Resolution

Create `src/Service/IpToCompanyResolver.php`:

```php
<?php

namespace App\Service;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Repository\IpMapRepository;
use Psr\Log\LoggerInterface;

/**
 * Resolves visitor IP addresses to company names
 * Uses multiple data sources:
 * 1. IP Map cache (previously resolved IPs)
 * 2. MaxMind GeoIP2 database
 * 3. IP2Location API
 */
class IpToCompanyResolver
{
    public function __construct(
        private IpMapRepository $ipMapRepo,
        private CompanyRepository $companyRepo,
        private LoggerInterface $logger,
        private string $geoipDatabasePath,
        private string $ip2LocationApiKey
    ) {}

    /**
     * Resolve IP to company
     * @return Company|null
     */
    public function resolveCompany(string $ipAddress): ?Company
    {
        // 1. Check local cache
        $cachedMapping = $this->ipMapRepo->findByIp($ipAddress);
        if ($cachedMapping && $cachedMapping->getCompany()) {
            return $cachedMapping->getCompany();
        }

        // 2. Try GeoIP lookup (organization field)
        $orgName = $this->geoipLookup($ipAddress);
        if ($orgName) {
            $company = $this->companyRepo->findByNameFuzzy($orgName);
            if ($company) {
                $this->cacheMapping($ipAddress, $company);
                return $company;
            }
        }

        // 3. Try IP2Location API (more detailed)
        $result = $this->ip2LocationLookup($ipAddress);
        if ($result && isset($result['organization'])) {
            $company = $this->companyRepo->findByNameFuzzy($result['organization']);
            if ($company) {
                $this->cacheMapping($ipAddress, $company);
                return $company;
            }
        }

        return null;
    }

    private function geoipLookup(string $ipAddress): ?string
    {
        try {
            $reader = new \GeoIp2\Database\Reader($this->geoipDatabasePath);
            $response = $reader->enterprise($ipAddress);
            return $response->traits()->organizationName;
        } catch (\Exception $e) {
            $this->logger->debug("GeoIP lookup failed for $ipAddress: {$e->getMessage()}");
            return null;
        }
    }

    private function ip2LocationLookup(string $ipAddress): ?array
    {
        try {
            $response = file_get_contents(
                "https://api.ip2location.io/?key={$this->ip2LocationApiKey}&ip={$ipAddress}"
            );
            $data = json_decode($response, true);
            
            if ($data && $data['status'] === 'success') {
                return [
                    'organization' => $data['isp'] ?? $data['domain'] ?? null,
                    'country' => $data['country_code'],
                    'city' => $data['city'],
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude']
                ];
            }
        } catch (\Exception $e) {
            $this->logger->debug("IP2Location lookup failed for $ipAddress: {$e->getMessage()}");
        }
        return null;
    }

    private function cacheMapping(string $ipAddress, Company $company): void
    {
        // Store in ip_map table for future lookups
        $ipMap = new \App\Entity\IpMap();
        $ipMap->setIpAddress($ipAddress);
        $ipMap->setCompany($company);
        $ipMap->setResolvedAt(new \DateTime());
        // Save to database
    }
}
```

### 2.5 Real-Time Visitor Dashboard

Create routes for ABM dashboard:

```php
/**
 * Display current active visitors
 * GET /abm/live-visitors
 */
#[Route('/abm/live-visitors')]
public function liveVisitors(): Response
{
    // Fetch WebEvents from last 30 minutes
    $recentEvents = $this->eventRepo->findRecent(30);
    
    // Group by company and aggregate
    $visitorsByCompany = [];
    foreach ($recentEvents as $event) {
        if ($event->getCompany()) {
            $key = $event->getCompany()->getId();
            if (!isset($visitorsByCompany[$key])) {
                $visitorsByCompany[$key] = [
                    'company' => $event->getCompany(),
                    'page_views' => 0,
                    'last_activity' => null,
                    'visitor_ips' => []
                ];
            }
            $visitorsByCompany[$key]['page_views']++;
            $visitorsByCompany[$key]['last_activity'] = $event->getTimestamp();
            if (!in_array($event->getIpAddress(), $visitorsByCompany[$key]['visitor_ips'])) {
                $visitorsByCompany[$key]['visitor_ips'][] = $event->getIpAddress();
            }
        }
    }

    return $this->json(array_values($visitorsByCompany));
}
```

---

## Integration Points

### 3.1 Email Analytics → ABM Triggers

When email is opened or clicked, trigger ABM actions:

```php
// In EmailActivityLogger service
public function logEmailSend(EmailSend $send): void
{
    // ... log send ...

    // Trigger ABM action on open
    if ($send->isOpened()) {
        $this->playbook->handleEmailOpen($send->getContact()->getCompany());
    }

    // Trigger ABM action on click
    if ($send->isClicked()) {
        $this->playbook->handleEmailClick($send->getContact()->getCompany());
    }
}
```

### 3.2 Visitor IP Tracking → Email Campaigns

When visitor from specific company is detected, trigger campaigns:

```php
// In VisitorTrackingController
if ($company) {
    // Trigger "high engagement" campaigns for ABM accounts
    $this->emailTrigger->handleAbmHit($company, [
        'page_url' => $event->getPageUrl(),
        'session_duration' => $sessionDuration,
        'page_count' => $sessionPageCount
    ]);
}
```

### 3.3 Activity Timeline Integration

Both email and visitor events logged to unified timeline:

```php
// Activity record for email open
$activity = new Activity();
$activity->setType('email_open');
$activity->setCompany($company);
$activity->setDescription("Contact opened email campaign: {$campaign->getName()}");
$activity->setData(['email_send_id' => $send->getId(), 'open_time' => $openedAt]);
$this->em->persist($activity);

// Activity record for visitor
$activity = new Activity();
$activity->setType('website_visit');
$activity->setCompany($company);
$activity->setDescription("Visitor from {$company->getName()} visited {$pageTitle}");
$activity->setData(['page_url' => $pageUrl, 'ip_address' => $ipAddress]);
$this->em->persist($activity);
```

---

## Monitoring & Verification

### 4.1 Analytics Health Checks

Create monitoring script `scripts/check-analytics.php`:

```bash
#!/usr/bin/env php
<?php

// Check email tracking health
$checks = [
    'email_sends_last_hour' => "SELECT COUNT(*) as count FROM email_send WHERE sent_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
    'opens_tracked_today' => "SELECT COUNT(*) as count FROM email_send WHERE opened_at > DATE(NOW())",
    'bounces_processed' => "SELECT COUNT(*) as count FROM email_bounce WHERE received_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    'web_events_last_hour' => "SELECT COUNT(*) as count FROM web_event WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
    'abm_triggers_fired' => "SELECT COUNT(*) as count FROM activity WHERE type = 'abm_trigger' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
];

foreach ($checks as $name => $sql) {
    $result = $db->query($sql);
    $row = $result->fetch();
    echo "$name: {$row['count']}\n";
}
```

Run as cron job:

```bash
# Monitor analytics every 15 minutes
*/15 * * * * cd /var/www/crm && php scripts/check-analytics.php >> /var/log/crm-analytics.log 2>&1
```

### 4.2 Log Monitoring

Configure log monitoring in `/etc/rsyslog.d/30-crm.conf`:

```bash
# Email tracking logs
:programname, isequal, "crm" /var/log/crm/email-tracking.log
:programname, isequal, "crm" /var/log/crm/visitor-tracking.log
:programname, isequal, "crm" /var/log/crm/abm-triggers.log

# Stop forwarding to syslog
& stop
```

### 4.3 Database Optimization

Regular maintenance:

```bash
# Weekly optimization of analytics tables
0 2 * * 0 cd /var/www/crm && mysql -u crm_user -p starz_crm -e "
    OPTIMIZE TABLE email_send;
    OPTIMIZE TABLE email_click;
    OPTIMIZE TABLE web_event;
    OPTIMIZE TABLE activity;
    OPTIMIZE TABLE email_bounce;
" >> /var/log/crm-maintenance.log 2>&1
```

---

## Troubleshooting

### Issue: Email Opens Not Tracked

**Symptoms**: Emails sent but opened_at field remains NULL

**Causes & Solutions**:

1. **Tracking pixel not loading**
   ```bash
   # Check tracking pixel URL is accessible
   curl -v https://crm.starz-morocco.com/track/pixel/123.gif
   
   # Should return 1x1 GIF image, not error
   ```

2. **Email client blocking images**
   - Outlook/Gmail may block tracking pixels
   - Use click tracking as backup metric
   - Send test email and verify opens manually

3. **Database connection issue**
   ```bash
   # Verify email tracking endpoint is working
   php bin/console app:test-tracking-endpoint
   ```

### Issue: Visitor IP Not Resolving to Company

**Symptoms**: Web events created but company_id is NULL

**Solutions**:

1. **GeoIP database outdated**
   ```bash
   # Update MaxMind database
   cd /opt/geoip && ./update-geoip.sh
   ```

2. **IP resolution timeout**
   ```bash
   # Check API timeout settings
   cat config/services.yaml | grep -A 5 "ip_to_company_resolver"
   
   # Increase timeout if needed:
   # geoip_timeout: 2000ms  # 2 seconds
   ```

3. **Unknown company**
   ```bash
   # Check if company exists in database
   mysql> SELECT * FROM company WHERE name LIKE '%Yazaki%';
   
   # If not found, may need manual company import
   php bin/console app:import-companies
   ```

### Issue: Too Many Webhook Failures

**Symptoms**: Email bounces/complaints not showing in CRM

**Solutions**:

1. **Webhook authentication**
   ```bash
   # Verify webhook secret is correct
   grep EMAIL_WEBHOOK_SECRET .env.prod
   
   # Should match provider settings (SendGrid, SES, etc.)
   ```

2. **Firewall blocking webhooks**
   ```bash
   # Check if provider IP is whitelisted
   sudo ufw status
   sudo ufw allow from 160.153.0.0/16  # SendGrid IP range
   ```

3. **Queue processing stalled**
   ```bash
   # Check message queue
   php bin/console messenger:failed:show email_queue
   
   # Retry failed messages
   php bin/console messenger:failed:retry email_queue --limit=10
   ```

---

## Configuration Checklist for Sysadmin

### Email Analytics Setup

- [ ] Database tables created (email_send, email_click, email_bounce, email_ab_test)
- [ ] `.env.local` configured with tracking parameters
- [ ] Tracking routes implemented and tested
- [ ] Email provider webhooks configured (SendGrid/SES/etc.)
- [ ] Tracking pixel added to email templates
- [ ] Analytics aggregation cron job scheduled
- [ ] Redis cache configured (optional but recommended)
- [ ] Log files configured and monitored
- [ ] Database optimization scheduled weekly

### Website Visitor Tracking Setup

- [ ] Tracking scripts generated for both domains
- [ ] Scripts installed on starzelectronics.com (all pages)
- [ ] Scripts installed on starzenergies.com (all pages)
- [ ] Visitor API endpoint configured and tested
- [ ] IP-to-company resolution service deployed
- [ ] GeoIP2 database installed and updated
- [ ] IP2Location API key configured (optional)
- [ ] Web event storage tested
- [ ] ABM triggers configured for visitor events

### Monitoring & Maintenance

- [ ] Analytics health checks scheduled
- [ ] Log aggregation configured
- [ ] Backup strategy for analytics data
- [ ] Database maintenance schedule set
- [ ] Alert system for failed webhooks/tracking
- [ ] Regular GeoIP/IP database updates scheduled
- [ ] Performance monitoring configured

---

**Next Steps**: See [Production Deployment Guide](../07-Deployment-Operations/PRODUCTION_DEPLOYMENT_GUIDE.md) for full server setup, and [ABM_PLAYBOOKS.md](ABM_PLAYBOOKS.md) for automation configuration.
