# Autonomous Sales System V2 - Integration Guide

This document describes the Autonomous Sales System V2 implementation adapted from the original TypeScript/Node.js design to Symfony PHP for the CRM-v2 application.

## Overview

The Autonomous Sales System V2 is a sophisticated outbound email automation system that uses:

1. **Thompson Sampling** - Multi-armed bandit algorithm for optimal A/B testing
2. **Spintax Engine** - Dynamic email content generation with anti-spam measures
3. **Email Classification** - Naive Bayes + rule-based classification for inbox handling
4. **Competitor Detection** - "Sniper" targeting mode for competitive intelligence
5. **Closed-Loop Learning** - Feedback integration from email events
6. **Dynamic Competitor Learning** - Auto-discovery of competitors from scraped content
7. **ML-Style Personalization** - Embedding-based email personalization (ONNX-equivalent)

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    AutonomousSalesOrchestrator                   │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐ │
│  │ Thompson    │  │  Spintax    │  │     Email Classifier    │ │
│  │  Sampler    │  │  Engine     │  │  (Naive Bayes + Rules)  │ │
│  └─────────────┘  └─────────────┘  └─────────────────────────┘ │
│  ┌─────────────┐  ┌─────────────────────────────────────────┐  │
│  │ Competitor  │  │            Closed-Loop Learning         │  │
│  │  Detection  │  │    (Webhooks: open/click/reply/bounce)  │  │
│  └─────────────┘  └─────────────────────────────────────────┘  │
│  ┌─────────────────────────────┐  ┌───────────────────────────┐│
│  │   Competitor Learner        │  │  Email Personalization   ││
│  │   (Dynamic from scraping)   │  │  (ML Embeddings/Cosine)  ││
│  └─────────────────────────────┘  └───────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

## Components

### 1. Thompson Sampling (`ThompsonSamplerService`)

Multi-armed bandit implementation using Beta distribution for optimal exploration/exploitation balance.

**Entity:** `BanditArm`
- Tracks alpha (successes+1) and beta (failures+1) for each arm
- Supports arm types: `subject_line`, `cta`, `tone`, `opening`

**Methods:**
- `sampleBeta(alpha, beta)` - Sample from Beta distribution using Gamma trick
- `sampleAndSelect(armType)` - Select best arm using Thompson Sampling
- `recordOutcome(arm, success)` - Update arm statistics

### 2. Spintax Engine (`SpintaxEngineService`)

Dynamic content generation with anti-spam uniqueness checks.

**Entity:** `SpintaxTemplate`
- Stores spintax syntax: `{option1|option2|option3}`
- Variable placeholders: `{{variable_name}}`

**Syntax:**
```
{Hi|Hello|Hey} {{first_name}},

{I noticed|I came across} {{company_name}} {during my research|while reviewing companies}.
```

**Anti-Spam:**
- Minimum Levenshtein distance of 50 between variations
- Prevents adjacent emails from appearing too similar

### 3. Email Classification (`EmailClassifierService`)

Two-stage classification system for incoming emails.

**Entity:** `InboxMessage`
- Classifications: `interested`, `not_interested`, `unsubscribe`, `out_of_office`, `bounce`, `unknown`

**Classification Methods:**
1. **Rule-based (fast path)** - Pattern matching for common responses
2. **Naive Bayes (fallback)** - Probabilistic classification

**Human Review:**
- Messages with confidence < 70% flagged for human review
- Review queue accessible via API

### 4. Competitor Detection (`CompetitorDetectionService`)

"Sniper" targeting for leads using competitor products.

**Entity:** `CompetitorDetection`
- Tier 1 (Major EMS): Foxconn, Flex, Jabil, Celestica, Sanmina
- Tier 2 (Regional): Benchmark, Plexus, Fabrinet
- Tier 3 (Niche): Kimball, SVI, Key Tronic

**Score Boost:**
- Tier 1 competitors: +30 points
- Tier 2 competitors: +20 points
- Tier 3 competitors: +15 points

### 5. Dynamic Competitor Learning (`CompetitorLearnerService`)

Auto-discovers new competitors from scraped content.

**Entity:** `LearnedCompetitor`
- Stores dynamically discovered competitors
- Auto-promotes frequently detected competitors to higher tiers
- Confidence scoring based on detection frequency

**Features:**
- Seeds baseline competitors from hardcoded list
- Learns from Google Dork results during scraping
- Learns from LinkedIn data
- Learns from website content analysis
- Auto-increments detection count and confidence

**Auto-Promotion:**
- 20+ detections: Promote from Tier 3 → Tier 2
- 50+ detections: Promote from Tier 2 → Tier 1

### 6. ML Email Personalization (`EmailPersonalizationService`)

ONNX-equivalent personalization using embeddings and cosine similarity.

**Entity:** `PersonalizationProfile`
- Stores learned preferences per contact
- Feature embeddings (64 dimensions)
- Interaction history for learning

**Embedding Features (64D):**
- Dimensions 0-7: Industry features (automotive, aerospace, etc.)
- Dimensions 8-15: Role features (procurement, engineering, etc.)
- Dimensions 16-23: Company size features
- Dimensions 24-31: Geographic features
- Dimensions 32-47: Behavioral features
- Dimensions 48-63: Text features (TF-IDF style)

**Similarity-Based Learning:**
- Finds similar high-engagement profiles
- Learns optimal tone/content from successful profiles
- Cosine similarity threshold: 0.5

**Tone Options:**
- `formal` - "Dear John, We would be pleased to discuss..."
- `casual` - "Hi John, Would love to chat about..."
- `direct` - "John, Quick question:..."
- `friendly` - "Hello John, Thought you might be interested..."

### 7. Outbound Messages (`OutboundMessage`)

Tracks sent emails with arm IDs for closed-loop learning.

**Status Flow:**
```
pending → sent → delivered → opened → clicked → replied
                     ↓
                  bounced / failed
```

## API Endpoints

All endpoints are prefixed with `/api/autonomous`

### Health & Stats
- `GET /health` - System health check
- `GET /stats` - Comprehensive statistics

### Initialization
- `POST /initialize` - Seed default arms and templates

### Templates & Arms
- `GET /templates` - List all spintax templates
- `GET /arms` - List all bandit arms with stats

### Email Operations
- `POST /compose` - Compose a personalized email
- `POST /feedback` - Submit feedback for an arm

### Inbox Management
- `GET /inbox` - Get classified inbox messages
- `POST /classify` - Classify an incoming email
- `GET /inbox/review` - Messages pending human review

### Lead Scoring
- `POST /score` - Score leads with competitor boost
- `GET /competitor-leads` - Get leads by competitor tier

### Dynamic Competitors (NEW)
- `GET /competitors/learned` - List learned competitors
- `POST /competitors/seed` - Seed baseline competitors
- `POST /competitors/learn` - Learn from content
- `POST /competitors/{id}/verify` - Verify a competitor
- `GET /competitors/stats` - Competitor statistics

### ML Personalization (NEW)
- `POST /personalize` - Generate personalized email
- `GET /personalization/profile/{contactId}` - Get profile
- `POST /personalization/interaction` - Record interaction
- `GET /personalization/stats` - Personalization statistics

### Webhooks
- `POST /webhook/email-events` - Generic email event webhook
- `POST /webhook/sendgrid` - SendGrid-specific webhook

## Database Schema

### bandit_arms
```sql
CREATE TABLE bandit_arms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    arm_type VARCHAR(50) NOT NULL,
    arm_name VARCHAR(255) NOT NULL,
    arm_value LONGTEXT NOT NULL,
    alpha INT DEFAULT 1,
    beta INT DEFAULT 1,
    total_trials INT DEFAULT 0,
    total_successes INT DEFAULT 0,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME
);
```

### spintax_templates
```sql
CREATE TABLE spintax_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description LONGTEXT,
    template_type VARCHAR(50) DEFAULT 'email',
    subject_spintax LONGTEXT NOT NULL,
    body_spintax LONGTEXT NOT NULL,
    available_variables JSON NOT NULL,
    times_used INT DEFAULT 0,
    total_opens INT DEFAULT 0,
    total_replies INT DEFAULT 0,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME
);
```

### outbound_messages
```sql
CREATE TABLE outbound_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contact_id INT NOT NULL,
    subject_arm_id INT,
    template_id INT,
    subject LONGTEXT NOT NULL,
    body_text LONGTEXT NOT NULL,
    body_html LONGTEXT,
    variation_hash VARCHAR(64),
    status VARCHAR(20) DEFAULT 'pending',
    sent_at DATETIME,
    delivered_at DATETIME,
    opened_at DATETIME,
    clicked_at DATETIME,
    replied_at DATETIME,
    message_id VARCHAR(255),
    provider VARCHAR(50),
    outcome_recorded TINYINT(1) DEFAULT 0,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (contact_id) REFERENCES contacts(id),
    FOREIGN KEY (subject_arm_id) REFERENCES bandit_arms(id),
    FOREIGN KEY (template_id) REFERENCES spintax_templates(id)
);
```

### inbox_messages
```sql
CREATE TABLE inbox_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    in_reply_to_id INT,
    contact_id INT,
    from_email VARCHAR(255) NOT NULL,
    subject LONGTEXT,
    body_text LONGTEXT,
    classification VARCHAR(30),
    classification_confidence DECIMAL(5,2),
    classification_method VARCHAR(30),
    requires_human_review TINYINT(1) DEFAULT 0,
    human_reviewed_at DATETIME,
    reviewed_by VARCHAR(255),
    metadata JSON,
    received_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (in_reply_to_id) REFERENCES outbound_messages(id),
    FOREIGN KEY (contact_id) REFERENCES contacts(id)
);
```

### competitor_detections
```sql
CREATE TABLE competitor_detections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    competitor_domain VARCHAR(255) NOT NULL,
    competitor_name VARCHAR(100),
    competitor_tier INT DEFAULT 3,
    detected_in VARCHAR(50) DEFAULT 'website_analysis',
    detection_confidence INT DEFAULT 100,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    UNIQUE KEY unique_lead_competitor (lead_id, competitor_domain)
);
```

## Usage Examples

### 1. Compose a Personalized Email

```php
// Using the orchestrator service
$orchestrator = $container->get(AutonomousSalesOrchestratorService::class);

$message = $orchestrator->composeMessage(
    contact: $contact,
    templateId: 1,
    variables: [
        'first_name' => 'John',
        'company_name' => 'Acme Corp',
        'sender_name' => 'Sarah'
    ]
);

// $message contains:
// - subject (Thompson Sampled)
// - body (Spintax spun + personalized)
// - subjectArm (for feedback)
// - variationHash (for uniqueness tracking)
```

### 2. Record Email Event Feedback

```php
// When email is opened
$orchestrator->recordEmailEvent(
    messageId: 'msg_123',
    eventType: 'opened',
    metadata: ['timestamp' => time()]
);

// This automatically:
// - Updates outbound_message status
// - Records success for the Thompson Sampler arm
// - Updates template open statistics
```

### 3. Classify Incoming Email

```php
$classifier = $container->get(EmailClassifierService::class);

$result = $classifier->classify(
    subject: 'Re: Your email',
    body: 'Thanks for reaching out. We are interested in learning more about your Morocco facility.'
);

// $result = [
//     'classification' => 'interested',
//     'confidence' => 0.85,
//     'method' => 'naive_bayes',
//     'requiresHumanReview' => false
// ]
```

### 4. Score Leads with Competitor Boost

```php
$orchestrator = $container->get(AutonomousSalesOrchestratorService::class);

$scoredLeads = $orchestrator->scoreLeads([$lead1, $lead2, $lead3]);

// Each lead now has:
// - base_score (from existing LeadSalesAnalystService)
// - competitor_boost (from CompetitorDetectionService)
// - final_score (base + boost)
// - competitors (detected competitor list)
```

## Migration

Run the migration to create the new tables:

```bash
php bin/console doctrine:migrations:migrate
```

This will:
1. Create all 5 new tables
2. Seed default subject line arms
3. Seed default spintax templates

## Integration with Existing Systems

The Autonomous Sales System integrates with:

- **Existing Email Campaigns** - Uses the same Contact entity
- **Existing Lead Scoring** - Adds competitor boost to existing scores
- **Existing Webhooks** - Can process events from SendGrid/Mailgun
- **Existing LeadSalesAnalyst** - Leverages existing analysis for context

## Configuration

No additional configuration required. All services use Symfony autowiring.

Optional environment variables for tuning:
```env
# Thompson Sampling
THOMPSON_EXPLORATION_RATE=0.1  # Minimum exploration

# Spintax
SPINTAX_MIN_DISTANCE=50  # Levenshtein uniqueness threshold

# Classification
CLASSIFIER_CONFIDENCE_THRESHOLD=0.70  # Below this = human review
```
