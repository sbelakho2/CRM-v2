<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Company;
use App\Entity\Contact;

/**
 * Activity Template Service
 * 
 * Provides pre-built activity templates for common sales scenarios:
 * - Email templates with personalization
 * - Call scripts
 * - Meeting agendas
 * - Follow-up structures
 */
class ActivityTemplateService
{
    // Template categories
    public const CATEGORY_OUTREACH = 'outreach';
    public const CATEGORY_FOLLOWUP = 'followup';
    public const CATEGORY_MEETING = 'meeting';
    public const CATEGORY_PROPOSAL = 'proposal';
    public const CATEGORY_NURTURE = 'nurture';
    
    // EMS-specific templates
    private const TEMPLATES = [
        // Initial outreach
        'initial_outreach' => [
            'category' => self::CATEGORY_OUTREACH,
            'name' => 'Initial Outreach Email',
            'subject' => 'Partnership Opportunity - EMS Manufacturing in North Africa',
            'body' => <<<'TEMPLATE'
Dear {{contact_name}},

I noticed {{company_name}}'s focus on {{sector}} and wanted to reach out about a potential manufacturing partnership.

We specialize in electronics manufacturing services with capabilities including:
• SMT assembly with full AOI and X-ray inspection
• {{certification_match}} certified production
• Competitive pricing through Free Zone advantages in Morocco and Tunisia
• Supply chain diversification from single-source risk

Would you have 15 minutes this week to discuss how we might support {{company_name}}'s manufacturing needs?

Best regards,
{{sender_name}}
TEMPLATE,
            'type' => 'email',
            'tags' => ['cold_outreach', 'introduction'],
        ],
        
        'follow_up_email' => [
            'category' => self::CATEGORY_FOLLOWUP,
            'name' => 'Follow-up Email',
            'subject' => 'Following up - {{company_name}}',
            'body' => <<<'TEMPLATE'
Dear {{contact_name}},

I wanted to follow up on my previous message regarding electronics manufacturing support.

Given {{company_name}}'s position in the {{sector}} market, I believe there could be significant value in exploring how our North Africa-based facilities could support your production needs.

Key benefits for {{company_name}}:
• {{benefit_1}}
• {{benefit_2}}
• {{benefit_3}}

Would you be available for a brief call to explore this further?

Best regards,
{{sender_name}}
TEMPLATE,
            'type' => 'email',
            'tags' => ['follow_up'],
        ],
        
        'call_prep_notes' => [
            'category' => self::CATEGORY_OUTREACH,
            'name' => 'Call Preparation Notes',
            'subject' => 'Call with {{contact_name}} - {{company_name}}',
            'body' => <<<'TEMPLATE'
## Pre-Call Research
- Company: {{company_name}}
- Contact: {{contact_name}}, {{contact_title}}
- Sector: {{sector}}
- Key Certifications: {{certifications}}

## Talking Points
1. Introduction and rapport building
2. Understanding their current manufacturing setup
3. Key pain points to explore:
   - Supply chain challenges
   - Capacity constraints
   - Quality requirements
   - Cost pressures
4. Our value proposition
5. Next steps

## Questions to Ask
- What's your current EMS partner situation?
- What challenges are you facing with your supply chain?
- What certifications do you require from your suppliers?
- What's your timeline for any upcoming projects?

## Objection Handling
- "We have existing suppliers" → Discuss diversification value
- "Morocco is too far" → Highlight logistics partnerships and lead times
- "Need specific certifications" → Reference our {{certification_match}}
TEMPLATE,
            'type' => 'call',
            'tags' => ['preparation', 'call'],
        ],
        
        'meeting_agenda' => [
            'category' => self::CATEGORY_MEETING,
            'name' => 'Meeting Agenda',
            'subject' => 'Meeting Agenda - {{company_name}}',
            'body' => <<<'TEMPLATE'
## Meeting: {{company_name}} - {{meeting_type}}
**Date:** {{meeting_date}}
**Attendees:** {{attendees}}

### Agenda

1. **Introductions** (5 min)
   - Team introductions
   - Meeting objectives

2. **{{company_name}} Overview** (10 min)
   - Current manufacturing needs
   - Key challenges and priorities
   - Upcoming projects

3. **Our Capabilities Presentation** (15 min)
   - Facility overview
   - Technical capabilities
   - Quality certifications
   - Case studies in {{sector}}

4. **Discussion & Q&A** (15 min)
   - Address specific requirements
   - Technical deep-dive as needed

5. **Next Steps** (5 min)
   - Action items
   - Timeline for follow-up
   - Potential site visit or technical review

### Materials to Share
- [ ] Company presentation
- [ ] Relevant case studies
- [ ] Certification copies
- [ ] Pricing framework (if appropriate)
TEMPLATE,
            'type' => 'meeting',
            'tags' => ['meeting', 'agenda'],
        ],
        
        'proposal_cover' => [
            'category' => self::CATEGORY_PROPOSAL,
            'name' => 'Proposal Cover Letter',
            'subject' => 'Proposal - {{project_name}} - {{company_name}}',
            'body' => <<<'TEMPLATE'
Dear {{contact_name}},

Thank you for the opportunity to submit our proposal for {{project_name}}.

Based on our discussions, we understand {{company_name}} requires:
{{requirements_summary}}

Our proposal includes:
• Detailed pricing for requested volumes
• Technical approach and manufacturing methodology
• Quality assurance plan aligned with {{certifications}}
• Proposed timeline and milestones
• Terms and conditions

**Proposal Validity:** 30 days from date of submission
**Estimated Lead Time:** {{lead_time}} weeks from order confirmation

We are confident in our ability to deliver exceptional quality while providing competitive pricing through our Free Zone advantages in Morocco and Tunisia.

Please don't hesitate to reach out with any questions. We would welcome the opportunity to present our proposal in person if helpful.

Best regards,
{{sender_name}}
TEMPLATE,
            'type' => 'email',
            'tags' => ['proposal', 'quote'],
        ],
        
        'check_in' => [
            'category' => self::CATEGORY_NURTURE,
            'name' => 'Check-in Email',
            'subject' => 'Checking in - {{company_name}}',
            'body' => <<<'TEMPLATE'
Dear {{contact_name}},

I hope this message finds you well.

I wanted to check in and see how things are progressing at {{company_name}}. Since we last spoke, we've {{recent_development}}.

I thought this might be relevant given your focus on {{sector}}.

Are there any upcoming projects or manufacturing needs where we could potentially support {{company_name}}?

Best regards,
{{sender_name}}
TEMPLATE,
            'type' => 'email',
            'tags' => ['nurture', 'check_in'],
        ],
        
        'meeting_summary' => [
            'category' => self::CATEGORY_MEETING,
            'name' => 'Meeting Summary',
            'subject' => 'Meeting Summary - {{company_name}} - {{meeting_date}}',
            'body' => <<<'TEMPLATE'
Dear {{contact_name}},

Thank you for taking the time to meet with us today. Here's a summary of our discussion:

## Key Discussion Points
{{discussion_points}}

## Action Items
| Item | Owner | Due Date |
|------|-------|----------|
| {{action_1}} | {{owner_1}} | {{due_1}} |
| {{action_2}} | {{owner_2}} | {{due_2}} |

## Next Steps
{{next_steps}}

## Timeline
{{timeline}}

Please let me know if I've missed anything or if you have any questions.

Best regards,
{{sender_name}}
TEMPLATE,
            'type' => 'email',
            'tags' => ['meeting', 'summary'],
        ],
        
        'rfq_acknowledgment' => [
            'category' => self::CATEGORY_PROPOSAL,
            'name' => 'RFQ Acknowledgment',
            'subject' => 'RFQ Received - {{rfq_number}} - {{company_name}}',
            'body' => <<<'TEMPLATE'
Dear {{contact_name}},

Thank you for sending the RFQ for {{project_description}}.

We confirm receipt and are currently reviewing the requirements. Here are the key details we've noted:

**RFQ Reference:** {{rfq_number}}
**Items:** {{item_count}} line items
**Target Volumes:** {{volumes}}
**Required Certifications:** {{certifications}}

Our team will complete the technical review and provide our quotation by {{quote_due_date}}.

If we have any technical questions during our review, we'll reach out promptly.

Best regards,
{{sender_name}}
TEMPLATE,
            'type' => 'email',
            'tags' => ['rfq', 'acknowledgment'],
        ],
        
        'site_visit_invitation' => [
            'category' => self::CATEGORY_MEETING,
            'name' => 'Site Visit Invitation',
            'subject' => 'Invitation to Visit Our Facility - {{company_name}}',
            'body' => <<<'TEMPLATE'
Dear {{contact_name}},

Following our recent discussions, I would like to invite you and your team to visit our manufacturing facilities in Morocco and Tunisia.

**Why Visit?**
• See our {{certifications}} certified production lines in action
• Meet our engineering and quality teams
• Review our capabilities firsthand
• Discuss technical requirements in detail

**Visit Agenda:**
- Facility tour (production floor, test labs, warehouse)
- Technical deep-dive session
- Quality systems review
- Working lunch with leadership team
- Q&A and next steps discussion

**Logistics:**
We can arrange transportation from Casablanca airport. Typical visits are 4-6 hours, though we can customize based on your interests.

What dates would work best for your team in the coming weeks?

Best regards,
{{sender_name}}
TEMPLATE,
            'type' => 'email',
            'tags' => ['site_visit', 'invitation'],
        ],
        
        'lost_deal_feedback' => [
            'category' => self::CATEGORY_FOLLOWUP,
            'name' => 'Lost Deal Feedback Request',
            'subject' => 'Quick Question - {{project_name}}',
            'body' => <<<'TEMPLATE'
Dear {{contact_name}},

I understand that {{company_name}} has decided to go in a different direction for {{project_name}}.

While we're disappointed not to have won this opportunity, we greatly value the experience of working with your team through the evaluation process.

If you have a moment, I'd appreciate any feedback on our proposal. Understanding where we fell short helps us improve for future opportunities:

• Was pricing a significant factor?
• Were there technical capability gaps?
• Did timeline or lead times play a role?
• Any other factors we should be aware of?

We'd welcome the opportunity to work with {{company_name}} on future projects and remain committed to continuously improving our offerings.

Thank you again for the opportunity to compete for your business.

Best regards,
{{sender_name}}
TEMPLATE,
            'type' => 'email',
            'tags' => ['lost_deal', 'feedback'],
        ],
    ];
    
    /**
     * Get all available templates
     */
    public function getTemplates(): array
    {
        return self::TEMPLATES;
    }
    
    /**
     * Get templates by category
     */
    public function getTemplatesByCategory(string $category): array
    {
        return array_filter(self::TEMPLATES, fn($t) => $t['category'] === $category);
    }
    
    /**
     * Get a specific template
     */
    public function getTemplate(string $templateId): ?array
    {
        return self::TEMPLATES[$templateId] ?? null;
    }
    
    /**
     * Render a template with data
      * @param array<string|int, mixed> $data
     */
    public function renderTemplate(string $templateId, array $data): ?array
    {
        $template = $this->getTemplate($templateId);
        if (!$template) {
            return null;
        }
        
        $rendered = [
            'subject' => $this->replaceVariables($template['subject'], $data),
            'body' => $this->replaceVariables($template['body'], $data),
            'type' => $template['type'],
            'template_id' => $templateId,
        ];
        
        return $rendered;
    }
    
    /**
     * Render template with Company and Contact context
      * @param array<string|int, mixed> $additionalData
     */
    public function renderForCompany(
        string $templateId,
        Company $company,
        ?Contact $contact = null,
        array $additionalData = []
    ): ?array {
        $data = [
            'company_name' => $company->getName(),
            'sector' => $company->getSector() ?? 'your industry',
            'certifications' => implode(', ', $this->getCompanyCertifications($company)),
            'certification_match' => $this->findCertificationMatch($company),
        ];
        
        if ($contact) {
            $data['contact_name'] = $contact->getFullName() ?? $contact->getFirstName() ?? 'there';
            $data['contact_title'] = $contact->getJobTitle() ?? '';
        } else {
            $data['contact_name'] = 'there';
            $data['contact_title'] = '';
        }
        
        // Merge additional data
        $data = array_merge($data, $additionalData);
        
        // Generate benefits based on company profile
        $benefits = $this->generateBenefits($company);
        $data['benefit_1'] = $benefits[0] ?? '';
        $data['benefit_2'] = $benefits[1] ?? '';
        $data['benefit_3'] = $benefits[2] ?? '';
        
        return $this->renderTemplate($templateId, $data);
    }
    
    /**
     * Create an Activity entity from a template
      * @param array<string|int, mixed> $additionalData
     */
    public function createActivityFromTemplate(
        string $templateId,
        Company $company,
        ?Contact $contact = null,
        array $additionalData = []
    ): ?Activity {
        $rendered = $this->renderForCompany($templateId, $company, $contact, $additionalData);
        
        if (!$rendered) {
            return null;
        }
        
        $activity = new Activity();
        $activity->setCompany($company);
        $activity->setContact($contact);
        $activity->setType($this->mapTemplateTypeToActivityType($rendered['type']));
        $activity->setSubject($rendered['subject']);
        $activity->setNotes($rendered['body']);
        $activity->setActivityDate(new \DateTime());
        
        return $activity;
    }
    
    /**
     * Get suggested templates based on company state and history
     */
    public function suggestTemplates(Company $company, ?string $lastActivityType = null): array
    {
        $suggestions = [];
        
        // If no previous activity, suggest outreach
        if (!$lastActivityType) {
            $suggestions[] = [
                'template_id' => 'initial_outreach',
                'reason' => 'No previous contact - start with introduction',
                'priority' => 'high',
            ];
            return $suggestions;
        }
        
        // Suggest based on last activity
        $templateSuggestions = match ($lastActivityType) {
            'email' => ['follow_up_email', 'call_prep_notes'],
            'call' => ['meeting_agenda', 'follow_up_email'],
            'meeting' => ['meeting_summary', 'proposal_cover'],
            'proposal_sent' => ['check_in', 'site_visit_invitation'],
            'site_visit' => ['proposal_cover', 'meeting_summary'],
            default => ['check_in', 'follow_up_email'],
        };
        
        foreach ($templateSuggestions as $templateId) {
            $template = $this->getTemplate($templateId);
            if ($template) {
                $suggestions[] = [
                    'template_id' => $templateId,
                    'template_name' => $template['name'],
                    'reason' => sprintf('Logical follow-up after %s', $lastActivityType),
                    'priority' => 'medium',
                ];
            }
        }
        
        return $suggestions;
    }
    
    /**
     * Get template categories
     */
    public function getCategories(): array
    {
        return [
            self::CATEGORY_OUTREACH => 'Initial Outreach',
            self::CATEGORY_FOLLOWUP => 'Follow-up',
            self::CATEGORY_MEETING => 'Meetings',
            self::CATEGORY_PROPOSAL => 'Proposals & Quotes',
            self::CATEGORY_NURTURE => 'Nurturing',
        ];
    }
    
    // Private helpers
    
    private function replaceVariables(string $text, array $data): string
    {
        foreach ($data as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }
        
        // Clean up any remaining placeholders
        $text = preg_replace('/\{\{[^}]+\}\}/', '[TBD]', $text);
        
        return $text;
    }
    
    /**
     * Certification names from the company's provided compliance documents.
     * Company has no qualityStack property; compliance documents are the
     * source of truth for certifications.
     *
     * @return string[]
     */
    private function getCompanyCertifications(Company $company): array
    {
        $certifications = [];
        foreach ($company->getComplianceDocuments() as $document) {
            $name = $document->getName();
            if ($name && $document->isProvided()) {
                $certifications[] = $name;
            }
        }

        return array_values(array_unique($certifications));
    }

    private function findCertificationMatch(Company $company): string
    {
        $certifications = array_map('strtolower', $this->getCompanyCertifications($company));
        $sector = strtolower($company->getSector() ?? '');
        
        // Match certification to sector
        if (in_array('automotive', [$sector]) || in_array('iatf 16949', $certifications)) {
            return 'Automotive Quality';
        }
        if (in_array('aerospace', [$sector]) || in_array('as9100', $certifications)) {
            return 'AS9100';
        }
        if (in_array('medical', [$sector]) || in_array('iso 13485', $certifications)) {
            return 'ISO 13485';
        }
        
        return 'ISO 9001';
    }
    
    private function generateBenefits(Company $company): array
    {
        $sector = strtolower($company->getSector() ?? '');
        
        $sectorBenefits = match ($sector) {
            'automotive' => [
                'ISO 9001 certified production with automotive-grade quality processes',
                'High-mix, medium-volume capability ideal for Tier 1/2 supply chains',
                'Morocco FTA advantages for EU market access',
            ],
            'aerospace' => [
                'AS9100 certified manufacturing with full traceability',
                'Secure facility meeting defense and aerospace security requirements',
                'Specialized inspection including X-ray for complex assemblies',
            ],
            'industrial' => [
                'Robust designs for harsh industrial environments',
                'Box build and full system integration capabilities',
                'Flexible volume from prototype to production',
            ],
            default => [
                'ISO 9001 certified quality management system',
                'Morocco Free Zone cost advantages (40-60% vs Western Europe)',
                'Strategic location bridging EU and African markets',
            ],
        };
        
        return $sectorBenefits;
    }
    
    private function mapTemplateTypeToActivityType(string $templateType): string
    {
        return match ($templateType) {
            'email' => 'Email',
            'call' => 'Call',
            'meeting' => 'Meeting',
            default => 'Note',
        };
    }
}
