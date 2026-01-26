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
            $result = $this->spinAndPersonalize($subjectSpintax, $bodySpintax, $context);
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
     */
    public function seedDefaultTemplates(): array
    {
        $existing = $this->templateRepository->findActiveByType('email');
        
        if (!empty($existing)) {
            return $existing;
        }

        $defaultTemplates = [
            [
                'name' => 'Initial Outreach - PCBA',
                'description' => 'First contact for PCBA manufacturing prospects',
                'subjectSpintax' => '{Quick question about|Question re:|Regarding} {{company_name}} {sourcing|manufacturing|production}',
                'bodySpintax' => "{Hi|Hello|Hey} {{first_name}},\n\n{I noticed|I came across|I saw} {{company_name}} {during my research|while reviewing companies in your sector|in my market research}.\n\n{We specialize in|Our expertise is in|We focus on} high-quality PCBA manufacturing from our Morocco facility, serving {automotive|industrial|aerospace} OEMs across Europe.\n\n{I'd love to|Would be great to|I'm curious to} understand if {{company_name}} {is exploring|considers|looks at} alternative sourcing options for electronic assemblies.\n\n{Would you be open to|Could we schedule|Any interest in} a brief call to discuss?\n\n{Best regards|Kind regards|Best},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name'],
            ],
            [
                'name' => 'Follow-up #1 - Value Add',
                'description' => 'First follow-up with additional value',
                'subjectSpintax' => '{Re: |Following up: |Quick follow-up on }{{company_name}}',
                'bodySpintax' => "{Hi|Hey} {{first_name}},\n\n{Just wanted to follow up|Circling back|Bumping this up} on my previous message.\n\n{A quick stat|One thing worth noting|What might interest you}: {our Morocco facility|we} {achieved|delivered} {98.5% first-pass yield|<50ppm defect rates} for {automotive|aerospace} clients last quarter.\n\n{Would this level of quality|Does this kind of performance} be relevant for {{company_name}}'s {requirements|needs|standards}?\n\n{Let me know|Happy to chat|Open to a call} when convenient.\n\n{Cheers|Best|Regards},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name'],
            ],
            [
                'name' => 'Follow-up #2 - Final',
                'description' => 'Final follow-up before closing sequence',
                'subjectSpintax' => '{Last attempt|Final check-in|Closing the loop}: {{company_name}}',
                'bodySpintax' => "{{first_name}},\n\n{I'll keep this short|Quick one|Last message from me on this}.\n\n{If nearshore PCBA manufacturing isn't a priority right now, totally understand.|I realize timing might not be right.|No worries if this isn't on your radar at the moment.}\n\n{Just reply|Let me know|Drop me a line} {\"not now\"|\"later this year\"|\"interested\"} and I'll {follow up accordingly|adjust my timing|note for future}.\n\n{Thanks for your time|Appreciate your consideration|Thanks either way},\n{{sender_name}}",
                'variables' => ['first_name', 'company_name', 'sender_name'],
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
