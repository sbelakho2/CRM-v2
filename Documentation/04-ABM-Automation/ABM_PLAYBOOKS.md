# ABM Playbook System - Workflow Automation

**Version:** 1.0  
**Last Updated:** October 29, 2025  
**Services:** `AbmResolverService`, `PlaybookEngine`

---

## Overview

The ABM (Account-Based Marketing) Playbook System is a workflow automation engine that triggers personalized actions based on visitor behavior and firmographic data.

### Core Concepts

**ABM (Account-Based Marketing):**
- Marketing strategy targeting specific high-value companies
- Personalized engagement based on company size, industry, behavior
- Moves beyond anonymous web analytics to named account tracking

**Visitor De-Anonymization:**
- Identify companies visiting the website (IP → Company mapping)
- Track page views, dwell time, content consumed
- Build engagement score per account

**Playbook:**
- Automated workflow with trigger conditions and actions
- Examples: "Send email when target account views pricing page 3+ times"
- Reduces manual SDR work, ensures consistent follow-up

---

## Architecture

### Components

1. **WebEvent:** Raw visitor data (IP, URL, timestamp, referrer)
2. **IpMap:** IP address → Company/ISP mapping database
3. **AbmAccount:** Enriched company data (size, industry, revenue, ICP fit score)
4. **AbmHit:** De-anonymized visit (WebEvent + Company + engagement metrics)
5. **Playbook:** Automation rule (trigger conditions + actions)
6. **PlaybookRun:** Execution log (what ran, when, outcome)
7. **Activity:** CRM activity created by playbook (call, email, task)

### Data Flow

```
Website Visitor
    ↓
JavaScript Tracker (sends page view)
    ↓
WebEvent Table (ip, url, timestamp)
    ↓
AbmResolverService (cron job every 5 min)
    ↓
Lookup IP in IpMap
    ↓
Found Company? → Create AbmHit
    ↓
PlaybookEngine Evaluation
    ↓
Match Playbook Trigger? → Execute Actions
    ↓
Create Activity, Send Email, Update Lead Score
```

---

## WebEvent Tracking

### JavaScript Tracker

**Embed in Base Template:**
```html
<!-- templates/base.html.twig -->
<script>
(function() {
    var endpoint = '/api/track';
    var data = {
        url: window.location.href,
        referrer: document.referrer,
        title: document.title,
        timestamp: new Date().toISOString()
    };
    
    navigator.sendBeacon(endpoint, JSON.stringify(data));
})();
</script>
```

**Controller Endpoint:**
```php
#[Route('/api/track', name: 'api_track', methods: ['POST'])]
public function track(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);
    
    $webEvent = new WebEvent();
    $webEvent->setIpAddress($request->getClientIp());
    $webEvent->setUrl($data['url']);
    $webEvent->setReferrer($data['referrer'] ?? null);
    $webEvent->setTitle($data['title'] ?? null);
    $webEvent->setVisitedAt(new \DateTime($data['timestamp']));
    $webEvent->setUserAgent($request->headers->get('User-Agent'));
    
    $this->entityManager->persist($webEvent);
    $this->entityManager->flush();
    
    return new JsonResponse(['status' => 'ok']);
}
```

### Privacy Considerations

**GDPR Compliance:**
- Do NOT track PII (personally identifiable information) without consent
- IP addresses are pseudonymous data (require cookie consent)
- Offer opt-out mechanism
- Anonymize IPs after 90 days

**Cookie Consent:**
```html
<!-- Show banner if user hasn't consented -->
<div id="cookie-banner" style="display: none;">
    We use cookies to track anonymous website usage. 
    <button onclick="acceptTracking()">Accept</button>
    <button onclick="rejectTracking()">Reject</button>
</div>

<script>
function acceptTracking() {
    localStorage.setItem('tracking_consent', 'true');
    document.getElementById('cookie-banner').style.display = 'none';
    // Enable tracking script
}

function rejectTracking() {
    localStorage.setItem('tracking_consent', 'false');
    document.getElementById('cookie-banner').style.display = 'none';
    // Disable tracking script
}

if (!localStorage.getItem('tracking_consent')) {
    document.getElementById('cookie-banner').style.display = 'block';
}
</script>
```

---

## IP → Company Resolution

### IpMap Database

**Entity:** `IpMap`

**Fields:**
- `ipStart` (e.g., "8.8.8.0")
- `ipEnd` (e.g., "8.8.8.255")
- `companyName` (e.g., "Google LLC")
- `companyDomain` (e.g., "google.com")
- `isp` (e.g., "Google Cloud")
- `asn` (Autonomous System Number, e.g., "AS15169")
- `country` (e.g., "US")
- `city` (e.g., "Mountain View")

**Data Sources:**
- **MaxMind GeoIP2 ISP Database:** $400/year, 95% accuracy for corporate IPs
- **IP2Location:** Free tier available, commercial for higher accuracy
- **Clearbit Reveal API:** $999/month, best for B2B SaaS
- **Kickfire:** $1,200/month, specialized in ABM

**Sample Data:**
```csv
ipStart,ipEnd,companyName,companyDomain,isp,asn,country,city
8.8.8.0,8.8.8.255,Google LLC,google.com,Google Cloud,AS15169,US,Mountain View
157.240.0.0,157.240.255.255,Meta Platforms Inc,meta.com,Meta,AS32934,US,Menlo Park
```

### Resolution Process

**Method:** `AbmResolverService::resolveIp(string $ip): ?AbmAccount`

**Algorithm:**
```php
public function resolveIp(string $ip): ?AbmAccount
{
    // Convert IP to long integer for range lookup
    $ipLong = ip2long($ip);
    
    // Find IpMap entry where ipStart <= ip <= ipEnd
    $ipMap = $this->ipMapRepo->createQueryBuilder('i')
        ->where('INET_ATON(i.ipStart) <= :ipLong')
        ->andWhere('INET_ATON(i.ipEnd) >= :ipLong')
        ->setParameter('ipLong', $ipLong)
        ->getQuery()
        ->getOneOrNullResult();
    
    if (!$ipMap) {
        return null; // Consumer ISP or unknown
    }
    
    // Filter out residential ISPs
    $residentialIsps = ['Comcast', 'AT&T', 'Verizon', 'Spectrum', 'Orange', 'BT'];
    if (in_array($ipMap->getIsp(), $residentialIsps)) {
        return null; // Likely personal browsing, not corporate
    }
    
    // Find or create AbmAccount
    $account = $this->abmAccountRepo->findOneBy(['domain' => $ipMap->getCompanyDomain()]);
    
    if (!$account) {
        $account = new AbmAccount();
        $account->setDomain($ipMap->getCompanyDomain());
        $account->setName($ipMap->getCompanyName());
        $account->setCountry($ipMap->getCountry());
        $account->setCity($ipMap->getCity());
        // Enrichment will happen later (Clearbit/ZoomInfo)
        
        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }
    
    return $account;
}
```

### SQLite IP Range Query Optimization

**Problem:** `INET_ATON()` is MySQL-specific, not available in SQLite

**Solution:** Store IP as INTEGER in SQLite

**Migration:**
```php
public function up(Schema $schema): void
{
    $this->addSql('ALTER TABLE ip_map ADD COLUMN ip_start_int INTEGER');
    $this->addSql('ALTER TABLE ip_map ADD COLUMN ip_end_int INTEGER');
    $this->addSql('UPDATE ip_map SET ip_start_int = CAST(ip_start AS INTEGER)');
    $this->addSql('UPDATE ip_map SET ip_end_int = CAST(ip_end AS INTEGER)');
    $this->addSql('CREATE INDEX idx_ip_range ON ip_map (ip_start_int, ip_end_int)');
}
```

**Updated Query:**
```php
$ipLong = ip2long($ip);

$ipMap = $this->ipMapRepo->createQueryBuilder('i')
    ->where('i.ipStartInt <= :ipLong')
    ->andWhere('i.ipEndInt >= :ipLong')
    ->setParameter('ipLong', $ipLong)
    ->getQuery()
    ->getOneOrNullResult();
```

---

## AbmHit Creation

**Entity:** `AbmHit`

**Fields:**
- `account` (ManyToOne → AbmAccount)
- `webEvent` (OneToOne → WebEvent)
- `url` (denormalized from WebEvent for performance)
- `visitedAt` (denormalized timestamp)
- `dwellTimeSeconds` (estimated time on page)
- `isKeyPage` (true if URL matches playbook criteria, e.g., "/pricing")
- `engagementScore` (0-100, calculated based on page importance)

**Creation Logic:**
```php
public function createAbmHit(WebEvent $webEvent, AbmAccount $account): AbmHit
{
    $hit = new AbmHit();
    $hit->setAccount($account);
    $hit->setWebEvent($webEvent);
    $hit->setUrl($webEvent->getUrl());
    $hit->setVisitedAt($webEvent->getVisitedAt());
    
    // Determine if key page
    $keyPages = ['/pricing', '/demo', '/case-studies', '/quote-estimator'];
    $isKeyPage = false;
    foreach ($keyPages as $page) {
        if (str_contains($webEvent->getUrl(), $page)) {
            $isKeyPage = true;
            break;
        }
    }
    $hit->setIsKeyPage($isKeyPage);
    
    // Calculate engagement score
    $score = match(true) {
        str_contains($webEvent->getUrl(), '/pricing') => 50,
        str_contains($webEvent->getUrl(), '/demo') => 60,
        str_contains($webEvent->getUrl(), '/quote-estimator') => 70,
        str_contains($webEvent->getUrl(), '/contact') => 80,
        default => 10
    };
    $hit->setEngagementScore($score);
    
    // Dwell time calculation (if available from subsequent page view)
    // TODO: Calculate in post-processing job
    
    $this->entityManager->persist($hit);
    $this->entityManager->flush();
    
    return $hit;
}
```

---

## Playbook Trigger Evaluation

### Playbook Entity

**Fields:**
- `name` (e.g., "High Intent - Pricing Page Viewed 3x")
- `isActive` (boolean)
- `triggerRules` (JSON, criteria for evaluation)
- `actions` (JSON, what to do when triggered)
- `cooldownHours` (prevent re-triggering, e.g., 24 hours)

**Example Playbook:**
```json
{
    "name": "High Intent - Pricing Page Viewed 3x",
    "isActive": true,
    "triggerRules": {
        "conditions": [
            {
                "field": "url",
                "operator": "contains",
                "value": "/pricing"
            },
            {
                "field": "visitCount",
                "operator": ">=",
                "value": 3
            },
            {
                "field": "account.icpFitScore",
                "operator": ">=",
                "value": 70
            }
        ],
        "logic": "AND"
    },
    "actions": [
        {
            "type": "create_activity",
            "params": {
                "activityType": "CALL",
                "assignTo": "sales_team",
                "notes": "Account {{account.name}} viewed pricing {{visitCount}} times. High intent!"
            }
        },
        {
            "type": "send_email",
            "params": {
                "template": "pricing_follow_up",
                "to": "{{contact.email}}",
                "subject": "Questions about our pricing?"
            }
        }
    ],
    "cooldownHours": 24
}
```

### Evaluation Engine

**Method:** `PlaybookEngine::evaluatePlaybooks(AbmHit $hit): void`

**Algorithm:**
```php
public function evaluatePlaybooks(AbmHit $hit): void
{
    $activePlaybooks = $this->playbookRepo->findBy(['isActive' => true]);
    
    foreach ($activePlaybooks as $playbook) {
        // Check cooldown
        if ($this->isInCooldown($playbook, $hit->getAccount())) {
            continue;
        }
        
        // Evaluate trigger rules
        if ($this->evaluateTriggerRules($playbook->getTriggerRules(), $hit)) {
            $this->executeActions($playbook, $hit);
            
            // Log playbook run
            $this->logPlaybookRun($playbook, $hit, 'SUCCESS');
        }
    }
}

private function evaluateTriggerRules(array $rules, AbmHit $hit): bool
{
    $conditions = $rules['conditions'] ?? [];
    $logic = $rules['logic'] ?? 'AND';
    
    $results = [];
    foreach ($conditions as $condition) {
        $results[] = $this->evaluateCondition($condition, $hit);
    }
    
    if ($logic === 'AND') {
        return !in_array(false, $results, true);
    } else { // OR
        return in_array(true, $results, true);
    }
}

private function evaluateCondition(array $condition, AbmHit $hit): bool
{
    $field = $condition['field'];
    $operator = $condition['operator'];
    $expectedValue = $condition['value'];
    
    // Extract actual value from hit
    $actualValue = match($field) {
        'url' => $hit->getUrl(),
        'engagementScore' => $hit->getEngagementScore(),
        'account.icpFitScore' => $hit->getAccount()?->getIcpFitScore(),
        'visitCount' => $this->countVisits($hit->getAccount(), $hit->getUrl()),
        default => null
    };
    
    // Evaluate operator
    return match($operator) {
        'contains' => str_contains($actualValue, $expectedValue),
        '=' => $actualValue == $expectedValue,
        '>=' => $actualValue >= $expectedValue,
        '<=' => $actualValue <= $expectedValue,
        '>' => $actualValue > $expectedValue,
        '<' => $actualValue < $expectedValue,
        default => false
    };
}
```

### Visit Count Calculation

**Method:** `PlaybookEngine::countVisits(AbmAccount $account, string $url): int`

**Implementation:**
```php
private function countVisits(AbmAccount $account, string $urlPattern): int
{
    // Count hits in last 30 days matching URL pattern
    $since = new \DateTime('-30 days');
    
    return $this->abmHitRepo->createQueryBuilder('h')
        ->select('COUNT(h.id)')
        ->where('h.account = :account')
        ->andWhere('h.url LIKE :urlPattern')
        ->andWhere('h.visitedAt >= :since')
        ->setParameter('account', $account)
        ->setParameter('urlPattern', '%' . $urlPattern . '%')
        ->setParameter('since', $since)
        ->getQuery()
        ->getSingleScalarResult();
}
```

---

## Playbook Actions

### 1. Create Activity

**Action Type:** `create_activity`

**Implementation:**
```php
private function executeCreateActivity(Playbook $playbook, AbmHit $hit, array $params): void
{
    $activity = new Activity();
    $activity->setType($params['activityType'] ?? 'TASK');
    $activity->setNotes($this->interpolate($params['notes'], $hit));
    $activity->setScheduledAt(new \DateTime($params['scheduledAt'] ?? 'now'));
    
    // Assign to user or team
    if (isset($params['assignTo'])) {
        $assignee = $this->userRepo->findOneBy(['username' => $params['assignTo']]);
        $activity->setAssignedTo($assignee);
    }
    
    // Link to account's company
    if ($company = $this->findCompanyByDomain($hit->getAccount()->getDomain())) {
        $activity->setCompany($company);
    }
    
    $this->entityManager->persist($activity);
    $this->entityManager->flush();
}

private function interpolate(string $template, AbmHit $hit): string
{
    $replacements = [
        '{{account.name}}' => $hit->getAccount()->getName(),
        '{{account.domain}}' => $hit->getAccount()->getDomain(),
        '{{url}}' => $hit->getUrl(),
        '{{visitCount}}' => $this->countVisits($hit->getAccount(), $hit->getUrl())
    ];
    
    return str_replace(array_keys($replacements), array_values($replacements), $template);
}
```

### 2. Send Email

**Action Type:** `send_email`

**Implementation:**
```php
private function executeSendEmail(Playbook $playbook, AbmHit $hit, array $params): void
{
    $template = $params['template'];
    $to = $this->interpolate($params['to'], $hit);
    $subject = $this->interpolate($params['subject'], $hit);
    
    // Find contact by domain
    $contact = $this->contactRepo->findOneBy(['email' => $to]);
    
    if (!$contact) {
        $this->logger->warning('Playbook email skipped: contact not found', [
            'email' => $to,
            'playbook' => $playbook->getName()
        ]);
        return;
    }
    
    // Send via EmailCampaign or direct SMTP
    $email = (new Email())
        ->from('sales@crm-starz.com')
        ->to($to)
        ->subject($subject)
        ->html($this->twig->render("email/{$template}.html.twig", [
            'contact' => $contact,
            'account' => $hit->getAccount()
        ]));
    
    $this->mailer->send($email);
}
```

### 3. Update Lead Score

**Action Type:** `update_lead_score`

**Implementation:**
```php
private function executeUpdateLeadScore(Playbook $playbook, AbmHit $hit, array $params): void
{
    $increment = $params['increment'] ?? 10;
    
    // Find or create lead
    $lead = $this->leadRepo->findOneBy(['domain' => $hit->getAccount()->getDomain()]);
    
    if (!$lead) {
        $lead = new Lead();
        $lead->setDomain($hit->getAccount()->getDomain());
        $lead->setCompanyName($hit->getAccount()->getName());
        $lead->setScore(0);
        $this->entityManager->persist($lead);
    }
    
    $newScore = $lead->getScore() + $increment;
    $lead->setScore($newScore);
    
    $this->entityManager->flush();
    
    $this->logger->info('Lead score updated', [
        'lead' => $lead->getId(),
        'oldScore' => $lead->getScore() - $increment,
        'newScore' => $newScore,
        'playbook' => $playbook->getName()
    ]);
}
```

### 4. Create RFQ

**Action Type:** `create_rfq`

**Implementation:**
```php
private function executeCreateRfq(Playbook $playbook, AbmHit $hit, array $params): void
{
    // Find company by domain
    $company = $this->findCompanyByDomain($hit->getAccount()->getDomain());
    
    if (!$company) {
        $this->logger->warning('Playbook RFQ skipped: company not found', [
            'domain' => $hit->getAccount()->getDomain(),
            'playbook' => $playbook->getName()
        ]);
        return;
    }
    
    $rfq = new Rfq();
    $rfq->setCompany($company);
    $rfq->setStatus('DISCOVERY');
    $rfq->setNotes($this->interpolate($params['notes'] ?? 'Auto-created by ABM playbook', $hit));
    $rfq->setCreatedAt(new \DateTime());
    
    $this->entityManager->persist($rfq);
    $this->entityManager->flush();
}
```

---

## Cooldown Mechanism

### Purpose
Prevent playbook from firing repeatedly for the same account within a short time period.

### Implementation

**Method:** `PlaybookEngine::isInCooldown(Playbook $playbook, AbmAccount $account): bool`

**Algorithm:**
```php
private function isInCooldown(Playbook $playbook, AbmAccount $account): bool
{
    $cooldownHours = $playbook->getCooldownHours();
    if ($cooldownHours === 0) {
        return false; // No cooldown
    }
    
    $since = new \DateTime("-{$cooldownHours} hours");
    
    $recentRun = $this->playbookRunRepo->createQueryBuilder('pr')
        ->where('pr.playbook = :playbook')
        ->andWhere('pr.account = :account')
        ->andWhere('pr.ranAt >= :since')
        ->andWhere('pr.status = :status')
        ->setParameter('playbook', $playbook)
        ->setParameter('account', $account)
        ->setParameter('since', $since)
        ->setParameter('status', 'SUCCESS')
        ->getQuery()
        ->getOneOrNullResult();
    
    return $recentRun !== null;
}
```

---

## ICP (Ideal Customer Profile) Scoring

### Purpose
Prioritize accounts that match your ideal customer profile.

### Scoring Criteria

**Factors (0-100 scale):**
- **Company Size:** 50-500 employees = 100, 10-50 = 70, <10 = 30
- **Industry:** Electronics Manufacturing = 100, Aerospace = 90, Automotive = 80
- **Revenue:** >$10M = 100, $1M-$10M = 70, <$1M = 40
- **Geography:** US/EU = 100, APAC = 80, Other = 60
- **Engagement:** High = +20, Medium = +10, Low = 0

**Example Calculation:**
```php
public function calculateIcpFitScore(AbmAccount $account): int
{
    $score = 0;
    
    // Company size (0-25 points)
    $employees = $account->getEmployeeCount();
    $score += match(true) {
        $employees >= 50 && $employees <= 500 => 25,
        $employees >= 10 && $employees < 50 => 18,
        $employees < 10 => 8,
        default => 15
    };
    
    // Industry (0-25 points)
    $industry = $account->getIndustry();
    $score += match($industry) {
        'Electronics Manufacturing' => 25,
        'Aerospace & Defense' => 23,
        'Automotive' => 20,
        'Industrial Automation' => 18,
        default => 10
    };
    
    // Revenue (0-25 points)
    $revenue = $account->getRevenueUsd();
    $score += match(true) {
        $revenue > 10_000_000 => 25,
        $revenue >= 1_000_000 => 18,
        $revenue > 0 => 10,
        default => 5 // Unknown revenue
    };
    
    // Geography (0-15 points)
    $country = $account->getCountry();
    $score += match($country) {
        'US', 'FR', 'DE', 'GB' => 15,
        'CN', 'JP', 'KR', 'SG' => 12,
        default => 8
    };
    
    // Engagement (0-10 points)
    $engagement = $this->calculateEngagement($account);
    $score += min(10, $engagement / 10); // Max 10 points
    
    return min(100, $score); // Cap at 100
}

private function calculateEngagement(AbmAccount $account): int
{
    $hits = $this->abmHitRepo->findBy([
        'account' => $account,
        'visitedAt >= ' => new \DateTime('-30 days')
    ]);
    
    return array_sum(array_map(fn($hit) => $hit->getEngagementScore(), $hits));
}
```

---

## ABM Dashboard

### Route
`/abm-dashboard`

### Features

**1. Account List**
- Show all de-anonymized accounts
- Columns: Company, Domain, Industry, Employee Count, ICP Fit Score, Last Visit, Total Visits
- Filters: ICP Score ≥70, Industry, Country
- Sort: By ICP Score (desc), Last Visit (desc)

**2. Account Detail Page**
- Timeline of all visits (URL, timestamp, dwell time)
- Engagement score trend chart
- Playbook runs triggered
- Linked CRM records (Company, Contacts, RFQs, Activities)
- "Convert to Company" button (if not already in CRM)

**3. Playbook Performance**
- List all playbooks with trigger count, success rate
- Edit playbook trigger rules and actions
- Enable/disable playbooks

---

## Testing

### Unit Tests

**Test: IP Resolution**
```php
public function testResolveIp(): void
{
    $service = new AbmResolverService(...);
    
    // Test Google IP
    $account = $service->resolveIp('8.8.8.8');
    $this->assertNotNull($account);
    $this->assertEquals('google.com', $account->getDomain());
    
    // Test residential ISP (should return null)
    $account = $service->resolveIp('192.168.1.1');
    $this->assertNull($account);
}
```

**Test: Playbook Evaluation**
```php
public function testEvaluatePlaybooks(): void
{
    $playbook = new Playbook();
    $playbook->setTriggerRules([
        'conditions' => [
            ['field' => 'url', 'operator' => 'contains', 'value' => '/pricing'],
            ['field' => 'visitCount', 'operator' => '>=', 'value' => 3]
        ],
        'logic' => 'AND'
    ]);
    
    $hit = new AbmHit();
    $hit->setUrl('/pricing');
    // Mock visitCount = 4
    
    $engine = new PlaybookEngine(...);
    $result = $engine->evaluateTriggerRules($playbook->getTriggerRules(), $hit);
    
    $this->assertTrue($result);
}
```

---

**Document End**
