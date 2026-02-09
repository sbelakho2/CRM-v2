<?php

namespace App\Service;

use App\Entity\SpintaxTemplate;
use App\Repository\SpintaxTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Spintax Engine Service
 * 
 * Generates unique email variations using spintax syntax for personalization.
 * 
 * Spintax Syntax:
 * - {option1|option2|option3} - Random selection from options
 * - {{variable}} - Variable substitution from context
 * 
 * Features:
 * - Levenshtein distance check ensures uniqueness
 * - History tracking prevents duplicate sends
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
class SpintaxEngineService
{
    private const MIN_LEVENSHTEIN_DISTANCE = 50; // Minimum distance for "unique" content

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SpintaxTemplateRepository $templateRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Spin spintax content (expand {option1|option2|option3} syntax)
     */
    public function spin(string $content): string
    {
        // Protect {{variable}} placeholders from being treated as spintax
        // Replace {{var}} with a sentinel that won't match the spintax pattern
        $placeholders = [];
        $content = preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use (&$placeholders) {
            $key = '%%PLACEHOLDER_' . count($placeholders) . '%%';
            $placeholders[$key] = $matches[0]; // Store original {{var}}
            return $key;
        }, $content);
        
        // Now spin {option1|option2} safely
        $pattern = '/\{([^{}]+)\}/';
        
        while (preg_match($pattern, $content)) {
            $content = preg_replace_callback($pattern, function ($matches) {
                // Split by pipe, handling nested content carefully
                $options = $this->splitOptions($matches[1]);
                
                if (empty($options)) {
                    return $matches[0]; // Return original if no valid options
                }
                
                // Return random option
                return $options[array_rand($options)];
            }, $content);
        }
        
        // Restore {{variable}} placeholders
        $content = str_replace(array_keys($placeholders), array_values($placeholders), $content);
        
        return $content;
    }

    /**
     * Split options by pipe, handling edge cases
     */
    private function splitOptions(string $content): array
    {
        // Simple split for non-nested content
        $options = array_map('trim', explode('|', $content));
        return array_filter($options, fn($o) => $o !== '');
    }

    /**
     * Personalize content by replacing {{variable}} placeholders
     */
    public function personalize(string $content, array $context): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($context) {
            $variable = $matches[1];
            return $context[$variable] ?? $matches[0]; // Keep placeholder if not found
        }, $content);
    }

    /**
     * Spin and personalize template content
     */
    public function spinAndPersonalize(
        string $subjectSpintax,
        string $bodySpintax,
        array $context
    ): array {
        // First spin (random selection)
        $subject = $this->spin($subjectSpintax);
        $body = $this->spin($bodySpintax);
        
        // Then personalize (variable substitution)
        $subject = $this->personalize($subject, $context);
        $body = $this->personalize($body, $context);
        
        // Detect unreplaced placeholders — log warning if any remain
        if (preg_match_all('/\{\{(\w+)\}\}/', $body . ' ' . $subject, $unreplaced)) {
            $this->logger->warning('Unreplaced placeholders detected in email output', [
                'placeholders' => array_unique($unreplaced[1]),
            ]);
        }
        
        // Generate variation hash for deduplication
        $variationHash = $this->generateVariationHash($subject, $body);
        
        return [
            'subject' => $subject,
            'body' => $body,
            'variationHash' => $variationHash,
        ];
    }

    /**
     * Generate unique variation with anti-spam check
     * 
     * Attempts to generate a unique variation with sufficient Levenshtein distance
     * from previous variations.
     */
    public function generateUnique(
        string $subjectSpintax,
        string $bodySpintax,
        array $context,
        array $previousVariations = [],
        int $maxAttempts = 10
    ): ?array {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $result = $this->spinAndPersonalize($subjectSpintax, $bodySpintax, $context);
            
            // Check uniqueness against previous variations
            if ($this->isUnique($result['body'], $previousVariations)) {
                $this->logger->debug('Generated unique variation', [
                    'attempt' => $attempt + 1,
                    'hash' => $result['variationHash'],
                ]);
                return $result;
            }
        }
        
        $this->logger->warning('Could not generate unique variation', [
            'maxAttempts' => $maxAttempts,
            'previousCount' => count($previousVariations),
        ]);
        
        return null;
    }

    /**
     * Check if content is unique compared to previous variations
     */
    public function isUnique(string $content, array $previousVariations): bool
    {
        foreach ($previousVariations as $previous) {
            $distance = $this->levenshteinDistance($content, $previous);
            
            if ($distance < self::MIN_LEVENSHTEIN_DISTANCE) {
                return false; // Too similar to a previous variation
            }
        }
        
        return true;
    }

    /**
     * Calculate Levenshtein distance between two strings
     * 
     * PHP's built-in levenshtein() is limited to 255 chars, so we use our own
     */
    public function levenshteinDistance(string $a, string $b): int
    {
        $lenA = strlen($a);
        $lenB = strlen($b);
        
        // For very long strings, use sampling to avoid memory issues
        if ($lenA > 500 || $lenB > 500) {
            return $this->approximateLevenshtein($a, $b);
        }
        
        if ($lenA === 0) return $lenB;
        if ($lenB === 0) return $lenA;
        
        // Create distance matrix
        $d = [];
        for ($i = 0; $i <= $lenA; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $lenB; $j++) {
            $d[0][$j] = $j;
        }
        
        // Calculate distances
        for ($i = 1; $i <= $lenA; $i++) {
            for ($j = 1; $j <= $lenB; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $d[$i][$j] = min(
                    $d[$i - 1][$j] + 1,      // Deletion
                    $d[$i][$j - 1] + 1,      // Insertion
                    $d[$i - 1][$j - 1] + $cost // Substitution
                );
            }
        }
        
        return $d[$lenA][$lenB];
    }

    /**
     * Approximate Levenshtein distance for long strings
     */
    private function approximateLevenshtein(string $a, string $b): int
    {
        // Sample both strings and calculate distance on samples
        $sampleSize = 200;
        $sampledA = substr($a, 0, $sampleSize);
        $sampledB = substr($b, 0, $sampleSize);
        
        // Scale up the result proportionally
        $sampleDistance = levenshtein($sampledA, $sampledB);
        $scaleFactor = max(strlen($a), strlen($b)) / $sampleSize;
        
        return (int) ($sampleDistance * $scaleFactor);
    }

    /**
     * Generate variation hash for deduplication
     */
    public function generateVariationHash(string $subject, string $body): string
    {
        return hash('sha256', $subject . '||' . $body);
    }

    /**
     * Preview multiple variations of a template
     */
    public function previewVariations(
        string $subjectSpintax,
        string $bodySpintax,
        array $context,
        int $count = 5
    ): array {
        $variations = [];
        $previousBodies = [];
        
        for ($i = 0; $i < $count; $i++) {
            // Use generateUnique to ensure each preview is distinct
            $result = $this->generateUnique($subjectSpintax, $bodySpintax, $context, $previousBodies);
            if ($result === null) {
                // Exhausted unique variations, fall back to regular spin
                $result = $this->spinAndPersonalize($subjectSpintax, $bodySpintax, $context);
            }
            $variations[] = $result;
            $previousBodies[] = $result['body'];
        }
        
        return $variations;
    }

    /**
     * Get active template and spin content
     */
    public function composeFromTemplate(
        SpintaxTemplate $template,
        array $context
    ): array {
        $result = $this->spinAndPersonalize(
            $template->getSubjectSpintax(),
            $template->getBodySpintax(),
            $context
        );
        
        // Track usage
        $template->incrementTimesUsed();
        $this->entityManager->flush();
        
        return array_merge($result, [
            'templateId' => $template->getId(),
            'templateName' => $template->getName(),
        ]);
    }

    /**
     * Seed default templates if none exist
     * 
     * Templates now use comprehensive personalization variables:
     * - {{value_prop}} / {{value_prop_short}} - Industry-specific value propositions
     * - {{pain_hook}} / {{pain_detail}} - Role-specific pain point targeting
     * - {{social_proof_stat}} / {{social_proof_full}} - Industry social proof
     * - {{cta}} - Engagement-adaptive call to action
     * - {{greeting}} / {{closing}} - Tone-appropriate openers/closers
     */
    public function seedDefaultTemplates(): array
    {
        $existing = $this->templateRepository->findActiveByType('email');
        
        if (!empty($existing)) {
            return $existing;
        }

        // ============================================================================
        // CIALDINI-OPTIMIZED EMAIL TEMPLATES
        // ============================================================================
        // Templates leverage all 7 principles of influence:
        // - Reciprocity: Lead with value ({{reciprocity}})
        // - Scarcity: Create urgency without false claims ({{scarcity}})
        // - Authority: Establish credibility factually ({{authority}})
        // - Consistency: Micro-commitments ({{consistency}})
        // - Liking: Build rapport through similarity ({{liking}})
        // - Social Proof: "Similar others" pattern ({{social_proof}})
        // - Unity: Shared identity and partnership ({{unity}})
        // 
        // Pre-suasion elements:
        // - {{presuasive_opener}}: Primes recipient mindset
        // - {{extend_impact}}: Future-oriented partnership framing
        // - {{geo_*}}: Geographic-specific value props
        // ============================================================================
        
        $defaultTemplates = [
            // ==================== FUSION-BASED INITIAL OUTREACH TEMPLATES ====================
            // All templates now use pre-fused content that weaves Cialdini elements naturally
            // instead of concatenating separate paragraphs (eliminates "Mad Libs" feel)
            
            [
                'name' => 'Initial Outreach - PCBA',
                'description' => 'First contact for PCBA manufacturing - natural conversation flow with fused Cialdini elements',
                'subjectSpintax' => '{Quick question about|Question re:|Regarding} {{company_name}} {sourcing|manufacturing|PCBA production}',
                'bodySpintax' => "{{greeting}},\n\n{{fused_intro}}\n\n{{fused_value}}\n\n{|{{starz_services}}}\n\n{|{{proof_request}}}\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'fused_intro', 'fused_value', 'starz_services', 'proof_request', 'consistency', 'industry'],
            ],
            [
                'name' => 'Initial Outreach - Technical',
                'description' => 'Technical-focused first contact - fused authority and social proof for credibility',
                'subjectSpintax' => '{Technical capabilities for|Engineering support for|R&D partnership with} {{company_name}}',
                'bodySpintax' => "{{greeting}},\n\n{Given your role in|As someone in|With your focus on} {{job_title_area}}, I thought you might appreciate this.\n\n{{fused_proof}}\n\n{{fused_value}}\n\n{|{{starz_services}}}\n\n{|{{proof_request}}}\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'job_title_area', 'fused_proof', 'fused_value', 'starz_services', 'proof_request', 'consistency'],
            ],
            [
                'name' => 'Initial Outreach - Cost Focus',
                'description' => 'Cost-focused first contact - fused geographic and scarcity elements',
                'subjectSpintax' => '{Cost optimization for|Sourcing alternative for|Competitive pricing for} {{company_name}} {assemblies|production}',
                'bodySpintax' => "{{greeting}},\n\n{{fused_intro}}\n\n{{geo_logistics}} {{geo_trade}}\n\n{|{{starz_services}}}\n\n{|{{proof_request}}}\n\n{{fused_close}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'fused_intro', 'geo_logistics', 'geo_trade', 'starz_services', 'proof_request', 'fused_close'],
            ],
            [
                'name' => 'Initial Outreach - Tier1 Auto',
                'description' => 'Specialized outreach for Tier 1 automotive - fused industry unity and authority',
                'subjectSpintax' => '{Automotive EMS partner for|Tier 1 supplier support for|Manufacturing partnership with} {{company_name}}',
                'bodySpintax' => "{{greeting}},\n\n{For automotive programs|In automotive supply chains|For automotive electronics teams}, partners are often evaluated on documentation, qualification steps, and supply continuity.\n\n{{fused_proof}}\n\n{{fused_value}}\n\n{|{{starz_services}}}\n\n{|{{proof_request}}}\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'fused_proof', 'fused_value', 'starz_services', 'proof_request', 'consistency'],
            ],
            
            // ==================== COMPETITOR DISPLACEMENT TEMPLATE ====================
            [
                'name' => 'Competitor Displacement',
                'description' => 'For prospects using a known competitor — highlight switching advantages',
                'subjectSpintax' => '{Alternative to|Complement to|Second source vs.} {{competitor_hook}} for {{company_name}}',
                'bodySpintax' => "{{greeting}},\n\n{I noticed|It looks like|I see that} {{company_name}} works with {{competitor_hook}}. {{competitor_pain}}\n\n{{competitor_diff}}\n\n{{fused_proof}}\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'competitor_hook', 'competitor_pain', 'competitor_diff', 'fused_proof', 'consistency'],
            ],

            // ==================== CABLE HARNESS TEMPLATE ====================
            [
                'name' => 'Initial Outreach - Cable Harness',
                'description' => 'First contact for cable assembly and wire harness services',
                'subjectSpintax' => '{Cable assembly capabilities for|Wire harness partnership with|Harness manufacturing for} {{company_name}}',
                'bodySpintax' => "{{greeting}},\n\n{{fused_intro}}\n\nWe can support cable assembly and wire harness programs from prototype through production scope, including overmolding, potting, and testing needs if required.\n\n{{fused_proof}}\n\n{|{{starz_services}}}\n\n{|{{proof_request}}}\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'fused_intro', 'fused_proof', 'starz_services', 'proof_request', 'consistency'],
            ],

            // ==================== FUSION-BASED FOLLOW-UP TEMPLATES ====================
            [
                'name' => 'Follow-up #1 - Value Add',
                'description' => 'First follow-up - naturally fused reciprocity with social proof',
                'subjectSpintax' => '{Re: |Following up: |Quick follow-up on }{{company_name}}',
                'bodySpintax' => "{{greeting}},\n\n{Just wanted to follow up|Circling back|Following up} with something {relevant|that might be useful|worth sharing}.\n\n{{fused_intro}}\n\n{{fused_proof}}\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'fused_intro', 'fused_proof', 'consistency'],
            ],
            [
                'name' => 'Follow-up #2 - Social Proof',
                'description' => 'Second follow-up - emphasizes similar others pattern with natural flow',
                'subjectSpintax' => '{Re: |Update on |Checking in: }{{company_name}} {manufacturing|sourcing}',
                'bodySpintax' => "{{greeting}},\n\n{Quick update|Wanted to share|Thought you might find this interesting}:\n\n{{fused_proof}}\n\n{{fused_value}}\n\n{If timing is better later|If now isn't ideal|If this quarter doesn't work}, {just let me know|happy to reconnect|I can follow up then}.\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'fused_proof', 'fused_value'],
            ],
            [
                'name' => 'Follow-up #3 - Final',
                'description' => 'Final follow-up - clear options with respect for autonomy',
                'subjectSpintax' => '{Last check-in|Final follow-up|Closing the loop}: {{company_name}}',
                'bodySpintax' => "{{first_name}},\n\n{I'll keep this short|Quick one|Last message from me on this}.\n\n{If|In case} {{pain_point}} {isn't a priority right now|isn't on your radar|isn't timely}, {totally understand|no worries|I get it}.\n\n{Just reply|Let me know|Drop me a line}:\n• {\"not now\"|\"later\"} - I'll check back {in 6 months|next quarter}\n• {\"interested\"|\"let's talk\"} - I'll send calendar options\n• {\"not a fit\"|\"remove me\"} - You won't hear from me again\n\n{Thanks for your time|Appreciate your consideration},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'pain_point'],
            ],
            
            // ==================== FUSION-BASED SPECIALIZED TEMPLATES ====================
            [
                'name' => 'Second Source Opportunity',
                'description' => 'For supply chain diversification - fused scarcity and unity',
                'subjectSpintax' => '{Second source for|Supply chain option for|Manufacturing partner for} {{company_name}} {assemblies|production}',
                'bodySpintax' => "{{greeting}},\n\n{{presuasive_opener}}\n\n{Companies managing supply chain risk often look for|The trend we're seeing is|What's driving conversations like this}:\n• {A qualified backup source|Supply chain diversification|Risk mitigation}\n• {{fused_intro}}\n\n{{fused_value}}\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'presuasive_opener', 'fused_intro', 'fused_value', 'consistency'],
            ],
            [
                'name' => 'Re-engagement - Previous Contact',
                'description' => 'For contacts who went cold - fused consistency and reciprocity',
                'subjectSpintax' => '{Checking back in|Reconnecting|Following up from earlier}: {{company_name}}',
                'bodySpintax' => "{{greeting}},\n\n{We connected|We spoke|You engaged with us} {a while back|previously|some time ago} about {{pain_point}}.\n\n{Since then|In the meantime|Recently}, {a few things have changed|there's been some progress|here's what's new}:\n\n{{fused_intro}}\n\n{{fused_proof}}\n\n{Has anything changed|Is this more relevant now|Would this be better timing} for {{company_name}}?\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'pain_point', 'fused_intro', 'fused_proof', 'consistency'],
            ],
            [
                'name' => 'Reciprocity First - Industry Insight',
                'description' => 'Leads with unconditional gift of value - pure reciprocity focus',
                'subjectSpintax' => '{{industry}} {manufacturing insight|market update|supply chain note} for {{company_name}}',
                'bodySpintax' => "{{greeting}},\n\n{{fused_intro}}\n\n{No ask here|Just wanted to share|Passing this along} - {hope it's useful|thought it might help|figured it could be valuable}.\n\n{If you ever want to discuss|Happy to explore|If you'd like to chat about} {{industry}} manufacturing challenges, {{consistency}}.\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'industry', 'fused_intro', 'consistency'],
            ],
            [
                'name' => 'Unity Approach - Partnership Focus',
                'description' => 'Emphasizes shared identity and partnership - fused unity and value',
                'subjectSpintax' => '{Partnership opportunity|Collaboration idea|Working together}: {{company_name}} + {{sender_company}}',
                'bodySpintax' => "{{greeting}},\n\n{{presuasive_opener}}\n\n{{fused_value}}\n\n{{fused_proof}}\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'sender_company', 'greeting', 'closing', 'presuasive_opener', 'fused_value', 'fused_proof', 'consistency'],
            ],
            [
                'name' => 'Geographic Advantage - Nearshore',
                'description' => 'Emphasizes Morocco nearshore advantages for European prospects',
                'subjectSpintax' => '{Nearshore advantage|European manufacturing alternative|North Africa facilities} for {{company_name}}',
                'bodySpintax' => "{{greeting}},\n\n{{presuasive_opener}}\n\n{For European companies|For UK/EU manufacturers|For organizations in your region}, {here's what stands out|the value proposition is clear|the benefits are significant}:\n\n{{geo_logistics}} {{geo_timezone}} {{geo_trade}}\n\n{{fused_proof}}\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'presuasive_opener', 'geo_logistics', 'geo_timezone', 'geo_trade', 'fused_proof', 'consistency'],
            ],
            
            // ==================== COLD OUTREACH VARIANTS (NATURAL FLOW) ====================
            [
                'name' => 'Fusion - Cold Outreach Natural',
                'description' => 'Uses fused elements for natural conversation flow - best for cold outreach',
                'subjectSpintax' => '{{curiosity_subject}}',
                'bodySpintax' => "{{greeting}},\n\n{{fused_intro}}\n\n{{fused_value}}\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'curiosity_subject', 'fused_intro', 'fused_value', 'consistency'],
            ],
            [
                'name' => 'Fusion - Warm Lead Follow-up',
                'description' => 'Natural flow for warm leads who have shown some interest',
                'subjectSpintax' => '{Re:|Following up on|Regarding} {{company_name}} {sourcing|manufacturing}',
                'bodySpintax' => "{{greeting}},\n\n{{fused_proof}}\n\n{{fused_value}}\n\n{{fused_close}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'fused_proof', 'fused_value', 'fused_close'],
            ],
            [
                'name' => 'Fusion - Curiosity Subject Cold',
                'description' => 'Curiosity-gap subject line with naturally flowing body',
                'subjectSpintax' => '{{curiosity_subject}}',
                'bodySpintax' => "{{greeting}},\n\n{{presuasive_opener}}\n\n{{fused_intro}}\n\n{{fused_proof}}\n\n{{consistency}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'curiosity_subject', 'presuasive_opener', 'fused_intro', 'fused_proof', 'consistency'],
            ],
            [
                'name' => 'Fusion - Geographic Cultural',
                'description' => 'Uses fused geographic and cultural elements for regional prospects',
                'subjectSpintax' => '{Nearshore partnership|Regional manufacturing} for {{company_name}}',
                'bodySpintax' => "{{greeting}},\n\n{{presuasive_opener}}\n\n{{geo_cultural}}\n\n{{fused_intro}}\n\n{{geo_logistics}} {{geo_trade}}\n\n{{fused_close}}\n\n{{closing}},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name', 'greeting', 'closing', 'presuasive_opener', 'geo_cultural', 'fused_intro', 'geo_logistics', 'geo_trade', 'fused_close'],
            ],
        ];

        $created = [];
        foreach ($defaultTemplates as $data) {
            $template = new SpintaxTemplate();
            $template->setName($data['name']);
            $template->setDescription($data['description']);
            $template->setSubjectSpintax($data['subjectSpintax']);
            $template->setBodySpintax($data['bodySpintax']);
            $template->setAvailableVariables($data['variables']);
            $template->setTemplateType('email');
            
            $this->entityManager->persist($template);
            $created[] = $template;
        }
        
        $this->entityManager->flush();
        
        $this->logger->info('Seeded default spintax templates', ['count' => count($created)]);
        
        return $created;
    }
}
