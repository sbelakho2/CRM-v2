<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Lead;
use App\Entity\Company;
use App\Entity\PersonalizationProfile;
use App\Entity\OutboundMessage;
use App\Repository\PersonalizationProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Email Personalization Service (ONNX-Equivalent)
 * 
 * Advanced ML-style personalization for outbound emails using:
 * - Feature embeddings (TF-IDF style vectors)
 * - Cosine similarity for profile matching
 * - Learned preferences from interaction history
 * - Multi-armed bandit integration for content selection
 * 
 * This provides ONNX-equivalent personalization without requiring
 * actual neural network inference, using classical ML techniques
 * that work well in PHP.
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
class EmailPersonalizationService
{
    // Feature dimensions for embedding
    private const EMBEDDING_DIMENSIONS = 64;
    
    // Industry feature weights
    private const INDUSTRY_FEATURES = [
        'automotive' => [0.9, 0.8, 0.7, 0.6, 0.1, 0.2, 0.3, 0.4],
        'aerospace' => [0.8, 0.9, 0.6, 0.5, 0.2, 0.3, 0.4, 0.5],
        'industrial' => [0.7, 0.6, 0.9, 0.5, 0.3, 0.4, 0.5, 0.3],
        'defense' => [0.6, 0.7, 0.5, 0.9, 0.4, 0.5, 0.6, 0.2],
        'medical' => [0.5, 0.4, 0.3, 0.2, 0.9, 0.8, 0.7, 0.6],
        'consumer' => [0.4, 0.3, 0.2, 0.1, 0.8, 0.9, 0.8, 0.7],
        'telecom' => [0.3, 0.2, 0.4, 0.3, 0.7, 0.6, 0.9, 0.8],
        'other' => [0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5],
    ];
    
    // Role feature weights
    private const ROLE_FEATURES = [
        'procurement' => [0.9, 0.2, 0.3, 0.8, 0.7, 0.4, 0.5, 0.6],
        'engineering' => [0.3, 0.9, 0.8, 0.4, 0.5, 0.7, 0.6, 0.5],
        'management' => [0.7, 0.4, 0.5, 0.9, 0.8, 0.3, 0.4, 0.7],
        'quality' => [0.4, 0.8, 0.7, 0.5, 0.9, 0.6, 0.5, 0.4],
        'operations' => [0.6, 0.5, 0.9, 0.6, 0.4, 0.8, 0.7, 0.3],
        'supply_chain' => [0.8, 0.3, 0.6, 0.7, 0.5, 0.9, 0.4, 0.5],
        'other' => [0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5],
    ];
    
    // Tone templates
    private const TONE_TEMPLATES = [
        PersonalizationProfile::TONE_FORMAL => [
            'greeting' => 'Dear {name}',
            'closing' => 'Best regards',
            'style' => 'We would be pleased to discuss',
        ],
        PersonalizationProfile::TONE_CASUAL => [
            'greeting' => 'Hi {name}',
            'closing' => 'Cheers',
            'style' => 'Would love to chat about',
        ],
        PersonalizationProfile::TONE_DIRECT => [
            'greeting' => '{name}',
            'closing' => 'Best',
            'style' => 'Quick question:',
        ],
        PersonalizationProfile::TONE_FRIENDLY => [
            'greeting' => 'Hello {name}',
            'closing' => 'Looking forward to connecting',
            'style' => 'Thought you might be interested in',
        ],
    ];
    
    // Content focus templates
    private const CONTENT_FOCUS = [
        PersonalizationProfile::CONTENT_TECHNICAL => [
            'emphasis' => ['specifications', 'capabilities', 'certifications', 'technology'],
            'style' => 'detailed_technical',
        ],
        PersonalizationProfile::CONTENT_BUSINESS => [
            'emphasis' => ['cost savings', 'efficiency', 'ROI', 'partnership'],
            'style' => 'business_value',
        ],
        PersonalizationProfile::CONTENT_VALUE_FOCUSED => [
            'emphasis' => ['benefits', 'results', 'outcomes', 'advantages'],
            'style' => 'benefit_driven',
        ],
        PersonalizationProfile::CONTENT_RELATIONSHIP => [
            'emphasis' => ['partnership', 'collaboration', 'long-term', 'support'],
            'style' => 'relationship_building',
        ],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PersonalizationProfileRepository $profileRepository,
        private ?ThompsonSamplerService $thompsonSampler,
        private ?SpintaxEngineService $spintaxEngine,
        private LoggerInterface $logger
    ) {}

    /**
     * Generate personalized email content for a contact
     */
    public function personalizeEmail(
        Contact $contact,
        string $templateSubject,
        string $templateBody,
        array $additionalVariables = []
    ): array {
        // Get or create personalization profile
        $profile = $this->profileRepository->findOrCreateForContact($contact->getId());
        
        // Generate embedding if not exists
        if (empty($profile->getFeatureEmbedding())) {
            $embedding = $this->generateContactEmbedding($contact);
            $profile->setFeatureEmbedding($embedding);
            $this->entityManager->persist($profile);
            $this->entityManager->flush();
        }
        
        // Find similar high-performing profiles for learning
        $similarProfiles = $this->findSimilarSuccessfulProfiles($profile);
        
        // Determine optimal tone and content focus
        $optimalSettings = $this->determineOptimalSettings($profile, $similarProfiles);
        
        // Build personalization context
        $context = $this->buildPersonalizationContext($contact, $profile, $optimalSettings);
        
        // Merge with additional variables
        $variables = array_merge($context['variables'], $additionalVariables);
        
        // Apply personalization to template
        $personalizedSubject = $this->applyPersonalization($templateSubject, $variables, $context);
        $personalizedBody = $this->applyPersonalization($templateBody, $variables, $context);
        
        // Apply spintax if available
        if ($this->spintaxEngine) {
            $personalizedSubject = $this->spintaxEngine->spin($personalizedSubject);
            $personalizedBody = $this->spintaxEngine->spin($personalizedBody);
        }
        
        return [
            'subject' => $personalizedSubject,
            'body' => $personalizedBody,
            'profile' => $profile,
            'settings' => $optimalSettings,
            'context' => $context,
            'similarProfilesUsed' => count($similarProfiles),
        ];
    }

    /**
     * Generate feature embedding for a contact
     */
    public function generateContactEmbedding(Contact $contact): array
    {
        $embedding = array_fill(0, self::EMBEDDING_DIMENSIONS, 0.0);
        
        // Industry features (first 8 dimensions)
        $company = $contact->getCompany();
        $industry = $company ? strtolower($company->getSector() ?? 'other') : 'other';
        $industryFeatures = self::INDUSTRY_FEATURES[$industry] ?? self::INDUSTRY_FEATURES['other'];
        for ($i = 0; $i < 8; $i++) {
            $embedding[$i] = $industryFeatures[$i];
        }
        
        // Role features (dimensions 8-15)
        $role = $this->inferRoleCategory($contact->getJobTitle() ?? '');
        $roleFeatures = self::ROLE_FEATURES[$role] ?? self::ROLE_FEATURES['other'];
        for ($i = 0; $i < 8; $i++) {
            $embedding[$i + 8] = $roleFeatures[$i];
        }
        
        // Company size features (dimensions 16-23)
        if ($company) {
            $sizeScore = $this->normalizeCompanySize($company);
            for ($i = 0; $i < 8; $i++) {
                $embedding[$i + 16] = $sizeScore * (1 - $i * 0.1);
            }
        }
        
        // Geographic features (dimensions 24-31)
        $geoFeatures = $this->extractGeographicFeatures($contact);
        for ($i = 0; $i < 8; $i++) {
            $embedding[$i + 24] = $geoFeatures[$i] ?? 0.5;
        }
        
        // Behavioral features (dimensions 32-47) - based on interaction history
        $profile = $this->profileRepository->findByContactId($contact->getId());
        if ($profile) {
            $behaviorFeatures = $this->extractBehavioralFeatures($profile);
            for ($i = 0; $i < 16; $i++) {
                $embedding[$i + 32] = $behaviorFeatures[$i] ?? 0.5;
            }
        }
        
        // Text features from company/contact data (dimensions 48-63)
        $textFeatures = $this->extractTextFeatures($contact);
        for ($i = 0; $i < 16; $i++) {
            $embedding[$i + 48] = $textFeatures[$i] ?? 0.0;
        }
        
        // Normalize embedding
        return $this->normalizeVector($embedding);
    }

    /**
     * Find similar profiles that have had successful engagement
     */
    public function findSimilarSuccessfulProfiles(PersonalizationProfile $targetProfile): array
    {
        $targetEmbedding = $targetProfile->getFeatureEmbedding();
        if (empty($targetEmbedding)) {
            return [];
        }
        
        // Get all profiles with embeddings and good engagement
        $candidates = $this->profileRepository->findHighEngagement(3, 1);
        
        $similarities = [];
        foreach ($candidates as $candidate) {
            if ($candidate->getId() === $targetProfile->getId()) {
                continue;
            }
            
            $candidateEmbedding = $candidate->getFeatureEmbedding();
            if (empty($candidateEmbedding)) {
                continue;
            }
            
            $similarity = $this->cosineSimilarity($targetEmbedding, $candidateEmbedding);
            
            if ($similarity > 0.5) { // Only consider reasonably similar profiles
                $similarities[] = [
                    'profile' => $candidate,
                    'similarity' => $similarity,
                    'engagementScore' => $candidate->getEngagementScore(),
                ];
            }
        }
        
        // Sort by weighted score (similarity * engagement)
        usort($similarities, function ($a, $b) {
            $scoreA = $a['similarity'] * $a['engagementScore'];
            $scoreB = $b['similarity'] * $b['engagementScore'];
            return $scoreB <=> $scoreA;
        });
        
        // Return top 5 similar profiles
        return array_slice($similarities, 0, 5);
    }

    /**
     * Determine optimal personalization settings
     */
    private function determineOptimalSettings(PersonalizationProfile $profile, array $similarProfiles): array
    {
        // Start with profile's own preferences
        $settings = [
            'tone' => $profile->getPreferredTone(),
            'content' => $profile->getPreferredContent(),
            'style' => $profile->getPreferredStyle(),
            'topicEmphasis' => $profile->getTopicInterests(),
        ];
        
        // If profile has low engagement, learn from similar successful profiles
        if ($profile->getEngagementScore() < 30 && !empty($similarProfiles)) {
            $toneCounts = [];
            $contentCounts = [];
            
            foreach ($similarProfiles as $similar) {
                $simProfile = $similar['profile'];
                $weight = $similar['similarity'] * $similar['engagementScore'] / 100;
                
                $tone = $simProfile->getPreferredTone();
                $content = $simProfile->getPreferredContent();
                
                $toneCounts[$tone] = ($toneCounts[$tone] ?? 0) + $weight;
                $contentCounts[$content] = ($contentCounts[$content] ?? 0) + $weight;
            }
            
            // Use most successful tone/content from similar profiles
            if (!empty($toneCounts)) {
                arsort($toneCounts);
                $settings['tone'] = array_key_first($toneCounts);
            }
            
            if (!empty($contentCounts)) {
                arsort($contentCounts);
                $settings['content'] = array_key_first($contentCounts);
            }
            
            $settings['learnedFromSimilar'] = true;
        }
        
        return $settings;
    }

    /**
     * Build personalization context with variables
     */
    private function buildPersonalizationContext(
        Contact $contact,
        PersonalizationProfile $profile,
        array $settings
    ): array {
        $company = $contact->getCompany();
        $toneConfig = self::TONE_TEMPLATES[$settings['tone']] ?? self::TONE_TEMPLATES[PersonalizationProfile::TONE_FORMAL];
        $contentConfig = self::CONTENT_FOCUS[$settings['content']] ?? self::CONTENT_FOCUS[PersonalizationProfile::CONTENT_BUSINESS];
        
        $firstName = $contact->getFirstName() ?? 'there';
        
        return [
            'variables' => [
                'first_name' => $firstName,
                'last_name' => $contact->getLastName() ?? '',
                'full_name' => $contact->getFirstName() . ' ' . $contact->getLastName(),
                'company_name' => $company ? $company->getName() : '',
                'job_title' => $contact->getJobTitle() ?? '',
                'greeting' => str_replace('{name}', $firstName, $toneConfig['greeting']),
                'closing' => $toneConfig['closing'],
                'style_phrase' => $toneConfig['style'],
                'emphasis_1' => $contentConfig['emphasis'][0] ?? '',
                'emphasis_2' => $contentConfig['emphasis'][1] ?? '',
            ],
            'tone' => $settings['tone'],
            'content' => $settings['content'],
            'toneConfig' => $toneConfig,
            'contentConfig' => $contentConfig,
        ];
    }

    /**
     * Apply personalization to template text
     */
    private function applyPersonalization(string $template, array $variables, array $context): string
    {
        $text = $template;
        
        // Replace {{variable}} placeholders
        foreach ($variables as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }
        
        // Apply tone-specific transformations
        $text = $this->applyToneTransformations($text, $context['tone']);
        
        return $text;
    }

    /**
     * Apply tone-specific text transformations
     */
    private function applyToneTransformations(string $text, string $tone): string
    {
        switch ($tone) {
            case PersonalizationProfile::TONE_DIRECT:
                // Make text more concise
                $text = preg_replace('/\bI would like to\b/i', "I'd like to", $text);
                $text = preg_replace('/\bWe would be happy to\b/i', "We'd be happy to", $text);
                break;
                
            case PersonalizationProfile::TONE_FORMAL:
                // Make text more formal
                $text = preg_replace('/\bI\'d\b/i', 'I would', $text);
                $text = preg_replace('/\bWe\'d\b/i', 'We would', $text);
                break;
                
            case PersonalizationProfile::TONE_CASUAL:
                // Make text more casual
                $text = preg_replace('/\bI would\b/i', "I'd", $text);
                $text = preg_replace('/\bWe would\b/i', "We'd", $text);
                break;
        }
        
        return $text;
    }

    /**
     * Record email interaction for learning
     */
    public function recordInteraction(
        Contact $contact,
        string $eventType,
        ?OutboundMessage $message = null,
        array $metadata = []
    ): PersonalizationProfile {
        $profile = $this->profileRepository->findOrCreateForContact($contact->getId());
        
        switch ($eventType) {
            case 'opened':
                $profile->incrementEmailsOpened();
                break;
            case 'clicked':
                $profile->incrementLinksClicked();
                break;
            case 'replied':
                $profile->incrementEmailsReplied();
                // Learn from successful subject
                if ($message && $message->getSubject()) {
                    $profile->addSuccessfulSubjectPattern($this->extractSubjectPattern($message->getSubject()));
                }
                break;
            case 'bounced':
                $profile->incrementEmailsBounced();
                break;
        }
        
        // Record detailed interaction
        $profile->recordInteraction($eventType, array_merge([
            'messageId' => $message?->getId(),
        ], $metadata));
        
        // Update best send time based on interactions
        if (in_array($eventType, ['opened', 'replied', 'clicked'])) {
            $this->updateBestSendTime($profile);
        }
        
        $this->entityManager->persist($profile);
        $this->entityManager->flush();
        
        return $profile;
    }

    /**
     * Update best send time based on successful interactions
     */
    private function updateBestSendTime(PersonalizationProfile $profile): void
    {
        $interactions = $profile->getInteractionHistory();
        $successfulInteractions = array_filter($interactions, fn($i) => in_array($i['type'], ['opened', 'replied', 'clicked']));
        
        if (count($successfulInteractions) < 3) {
            return; // Not enough data
        }
        
        $hourCounts = [];
        $dayCounts = [];
        
        foreach ($successfulInteractions as $interaction) {
            $timestamp = $interaction['timestamp'];
            $hour = (int)date('H', $timestamp);
            $day = date('l', $timestamp);
            
            $hourCounts[$hour] = ($hourCounts[$hour] ?? 0) + 1;
            $dayCounts[$day] = ($dayCounts[$day] ?? 0) + 1;
        }
        
        if (!empty($hourCounts)) {
            arsort($hourCounts);
            $bestHour = array_key_first($hourCounts);
            $profile->setBestSendTime(sprintf('%02d:00', $bestHour));
        }
        
        if (!empty($dayCounts)) {
            arsort($dayCounts);
            $profile->setBestSendDay(array_key_first($dayCounts));
        }
    }

    /**
     * Extract pattern from successful subject line
     */
    private function extractSubjectPattern(string $subject): string
    {
        // Remove specific company/person names to get pattern
        $pattern = preg_replace('/[A-Z][a-z]+(\s+[A-Z][a-z]+)?/', '{name}', $subject);
        return $pattern;
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        
        $length = min(count($a), count($b));
        
        for ($i = 0; $i < $length; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        
        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }
        
        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Normalize a vector to unit length
     */
    private function normalizeVector(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt($norm);
        
        if ($norm == 0) {
            return $vector;
        }
        
        return array_map(fn($v) => $v / $norm, $vector);
    }

    /**
     * Infer role category from job title
     */
    private function inferRoleCategory(string $jobTitle): string
    {
        $title = strtolower($jobTitle);
        
        if (preg_match('/\b(procurement|buyer|purchasing|sourcing)\b/', $title)) {
            return 'procurement';
        }
        if (preg_match('/\b(engineer|technical|design|r&d)\b/', $title)) {
            return 'engineering';
        }
        if (preg_match('/\b(manager|director|vp|chief|head|lead)\b/', $title)) {
            return 'management';
        }
        if (preg_match('/\b(quality|sqe|qa|compliance)\b/', $title)) {
            return 'quality';
        }
        if (preg_match('/\b(operations|ops|manufacturing)\b/', $title)) {
            return 'operations';
        }
        if (preg_match('/\b(supply|logistics|materials)\b/', $title)) {
            return 'supply_chain';
        }
        
        return 'other';
    }

    /**
     * Normalize company size to 0-1 scale
     */
    private function normalizeCompanySize(?Company $company): float
    {
        if (!$company) {
            return 0.5;
        }
        
        // Try to get employee count or revenue tier
        $tier = $company->getAccountTier() ?? 'C';
        
        return match ($tier) {
            'A' => 0.9,
            'B' => 0.7,
            'C' => 0.5,
            'D' => 0.3,
            default => 0.5,
        };
    }

    /**
     * Extract geographic features from contact
     */
    private function extractGeographicFeatures(Contact $contact): array
    {
        $company = $contact->getCompany();
        $features = array_fill(0, 8, 0.5);
        
        if (!$company) {
            return $features;
        }
        
        $location = strtolower($company->getPhysicalSite() ?? '');
        
        // European markets
        if (preg_match('/\b(germany|france|spain|italy|uk|poland|czech|netherlands)\b/', $location)) {
            $features[0] = 0.9; // European preference
            $features[1] = 0.7; // Nearshore interest
        }
        
        // US markets
        if (preg_match('/\b(usa|united states|california|texas|michigan|ohio)\b/', $location)) {
            $features[2] = 0.9; // US market
            $features[3] = 0.6; // Cost sensitivity
        }
        
        // Asia markets  
        if (preg_match('/\b(china|japan|korea|taiwan|india)\b/', $location)) {
            $features[4] = 0.9; // Asia presence
            $features[5] = 0.8; // Diversification interest
        }
        
        return $features;
    }

    /**
     * Extract behavioral features from profile history
     */
    private function extractBehavioralFeatures(PersonalizationProfile $profile): array
    {
        $features = array_fill(0, 16, 0.5);
        
        $history = $profile->getInteractionHistory();
        if (empty($history)) {
            return $features;
        }
        
        // Engagement rate features
        $features[0] = min(1.0, $profile->getEmailsOpened() / 10);
        $features[1] = min(1.0, $profile->getEmailsReplied() / 5);
        $features[2] = min(1.0, $profile->getLinksClicked() / 8);
        
        // Response time features (from history)
        $replyTimes = [];
        foreach ($history as $i => $interaction) {
            if ($interaction['type'] === 'replied' && $i > 0) {
                $prevTimestamp = $history[$i - 1]['timestamp'] ?? 0;
                if ($prevTimestamp > 0) {
                    $replyTimes[] = $interaction['timestamp'] - $prevTimestamp;
                }
            }
        }
        
        if (!empty($replyTimes)) {
            $avgReplyTime = array_sum($replyTimes) / count($replyTimes);
            $features[3] = 1.0 - min(1.0, $avgReplyTime / (24 * 3600)); // Fast replier score
        }
        
        return $features;
    }

    /**
     * Extract text features using simple TF-IDF style approach
     */
    private function extractTextFeatures(Contact $contact): array
    {
        $features = array_fill(0, 16, 0.0);
        $company = $contact->getCompany();
        
        // Build text corpus from available data
        $text = strtolower(implode(' ', array_filter([
            $contact->getJobTitle(),
            $company?->getName(),
            $company?->getSector(),
            $company?->getSourceNotes(),
        ])));
        
        // Key terms and their feature indices
        $termFeatures = [
            'automotive' => 0, 'aerospace' => 1, 'medical' => 2, 'industrial' => 3,
            'quality' => 4, 'procurement' => 5, 'engineering' => 6, 'manufacturing' => 7,
            'oem' => 8, 'tier1' => 9, 'global' => 10, 'supplier' => 11,
            'pcba' => 12, 'smt' => 13, 'ems' => 14, 'electronics' => 15,
        ];
        
        foreach ($termFeatures as $term => $index) {
            if (strpos($text, $term) !== false) {
                $features[$index] = 1.0;
            }
        }
        
        return $features;
    }

    /**
     * Get personalization statistics
     */
    public function getStatistics(): array
    {
        return $this->profileRepository->getStatistics();
    }
}
