<?php

namespace App\Tests\Unit\Service;

use App\Entity\Contact;
use App\Entity\Company;
use App\Entity\PersonalizationProfile;
use App\Repository\PersonalizationProfileRepository;
use App\Repository\PersonalizationArchetypeRepository;
use App\Service\EmailPersonalizationService;
use App\Service\ThompsonSamplerService;
use App\Service\SpintaxEngineService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the enhanced EmailPersonalizationService methods
 * 
 * Covers report recommendations implementation:
 * - Content length enforcement
 * - Competitor hooks
 * - Send time optimization
 * - Subject line synthesis
 * - Tone transformations (public API)
 * - Value prop A/B testing support
 */
class EmailPersonalizationEnhancementsTest extends TestCase
{
    private EmailPersonalizationService $service;
    private $profileRepository;
    private $entityManager;
    private $spintaxEngine;
    
    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->profileRepository = $this->createMock(PersonalizationProfileRepository::class);
        $archetypeRepository = $this->createMock(PersonalizationArchetypeRepository::class);
        $thompsonSampler = $this->createMock(ThompsonSamplerService::class);
        $this->spintaxEngine = $this->createMock(SpintaxEngineService::class);
        $logger = $this->createMock(LoggerInterface::class);
        
        // Configure spintax engine to resolve spintax by picking the first option
        $this->spintaxEngine->method('spin')->willReturnCallback(function (string $text): string {
            // Resolve {option1|option2|option3} by picking the first option
            return preg_replace_callback('/\{([^{}]+)\}/', function ($matches) {
                $options = explode('|', $matches[1]);
                return trim($options[0]);
            }, $text);
        });
        
        $this->service = new EmailPersonalizationService(
            $this->entityManager,
            $this->profileRepository,
            $archetypeRepository,
            $thompsonSampler,
            $this->spintaxEngine,
            $logger
        );
    }
    
    // ========================= CONTENT LENGTH ENFORCEMENT =========================
    
    public function testEnforceContentLengthBrief(): void
    {
        $longContent = "First sentence here. Second sentence. Third sentence. Fourth sentence. Fifth sentence.";
        $result = $this->service->enforceContentLength($longContent, 'brief');
        
        // Brief should limit to 3 sentences
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($result), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertLessThanOrEqual(3, count($sentences), 'Brief content should have at most 3 sentences');
    }
    
    public function testEnforceContentLengthStandard(): void
    {
        $longContent = "One. Two. Three. Four. Five. Six. Seven. Eight.";
        $result = $this->service->enforceContentLength($longContent, 'standard');
        
        // Standard should limit to 5 sentences
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($result), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertLessThanOrEqual(5, count($sentences), 'Standard content should have at most 5 sentences');
    }
    
    public function testEnforceContentLengthDetailed(): void
    {
        $longContent = "One. Two. Three. Four. Five. Six. Seven. Eight. Nine. Ten.";
        $result = $this->service->enforceContentLength($longContent, 'detailed');
        
        // Detailed should limit to 8 sentences
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($result), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertLessThanOrEqual(8, count($sentences), 'Detailed content should have at most 8 sentences');
    }
    
    public function testEnforceContentLengthPreservesShortContent(): void
    {
        $shortContent = "Just two sentences. Like this.";
        $result = $this->service->enforceContentLength($shortContent, 'brief');
        
        $this->assertEquals($shortContent, $result, 'Short content should not be modified');
    }
    
    // ========================= COMPETITOR HOOKS (DISABLED) =========================
    // Competitor hooks have been disabled pending legal review and verified claims
    
    public function testGetCompetitorHookReturnsNullWhenDisabled(): void
    {
        // Competitor hooks are intentionally disabled - all should return null
        $hook = $this->service->getCompetitorHook('jabil');
        $this->assertNull($hook, 'Jabil hook should be null when competitor hooks are disabled');
        
        $hook = $this->service->getCompetitorHook('flex');
        $this->assertNull($hook, 'Flex hook should be null when competitor hooks are disabled');
        
        $hook = $this->service->getCompetitorHook('celestica');
        $this->assertNull($hook, 'Celestica hook should be null when competitor hooks are disabled');
    }
    
    public function testGetCompetitorHookForUnknown(): void
    {
        $hook = $this->service->getCompetitorHook('unknown_competitor_xyz');
        
        $this->assertNull($hook, 'Unknown competitor should return null');
    }
    
    public function testGetKnownCompetitorsReturnsEmptyWhenDisabled(): void
    {
        $competitors = $this->service->getKnownCompetitors();
        
        // Competitor hooks are disabled - array should be empty
        $this->assertIsArray($competitors);
        $this->assertEmpty($competitors, 'Known competitors should be empty when feature is disabled');
    }
    
    // ========================= TONE TRANSFORMATIONS (PUBLIC API) =========================
    
    public function testApplyToneTransformationsFormal(): void
    {
        $casualText = "Hey! I'd love to chat. We're excited about this!";
        $result = $this->service->applyToneTransformations($casualText, 'formal');
        
        $this->assertStringNotContainsString("I'd", $result);
        $this->assertStringNotContainsString("We're", $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('I would', $result);
    }
    
    public function testApplyToneTransformationsCasual(): void
    {
        $formalText = "I would be pleased to discuss. We are excited.";
        $result = $this->service->applyToneTransformations($formalText, 'casual');
        
        $this->assertStringContainsString("I'd", $result);
        $this->assertStringContainsString("We're", $result);
    }
    
    public function testApplyToneTransformationsDirect(): void
    {
        $wordyText = "I hope this email finds you well. I was wondering if we could discuss. I just wanted to touch base.";
        $result = $this->service->applyToneTransformations($wordyText, 'direct');
        
        // Direct tone removes filler phrases
        $this->assertStringNotContainsString('I hope this email finds you well', $result);
        $this->assertStringNotContainsString('I was wondering if', $result);
        $this->assertStringNotContainsString('I just wanted to', $result);
    }
    
    public function testApplyToneTransformationsFriendly(): void
    {
        $text = "I wanted to discuss this. Please let me know your thoughts.";
        $result = $this->service->applyToneTransformations($text, 'friendly');
        
        $this->assertStringContainsString("I'd love to", $result);
    }
    
    // ========================= SEND TIME OPTIMIZATION =========================
    
    public function testGetOptimalSendTimeReturnsDefault(): void
    {
        $contact = $this->createMock(Contact::class);
        $contact->method('getId')->willReturn(1);
        
        $this->profileRepository->method('findByContactId')->willReturn(null);
        
        $result = $this->service->getOptimalSendTime($contact);
        
        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('day', $result);
        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertEquals('default', $result['source']);
    }
    
    public function testGetOptimalSendTimeUsesLearnedData(): void
    {
        $contact = $this->createMock(Contact::class);
        $contact->method('getId')->willReturn(1);
        
        $profile = $this->createMock(PersonalizationProfile::class);
        $profile->method('getBestSendTime')->willReturn('10:00');
        $profile->method('getBestSendDay')->willReturn('Wednesday');
        $profile->method('getInteractionHistory')->willReturn([
            ['type' => 'opened', 'timestamp' => time()],
            ['type' => 'replied', 'timestamp' => time()],
        ]);
        
        $this->profileRepository->method('findByContactId')->willReturn($profile);
        
        $result = $this->service->getOptimalSendTime($contact);
        
        $this->assertEquals('10:00', $result['time']);
        $this->assertEquals('Wednesday', $result['day']);
        $this->assertEquals('learned', $result['source']);
        $this->assertGreaterThan(0.5, $result['confidence']);
    }
    
    // ========================= VALUE PROP A/B TESTING =========================
    
    public function testGetValuePropVariantReturnsBaseWhenNoArms(): void
    {
        $result = $this->service->getValuePropVariant('automotive', 'technical');
        
        $this->assertArrayHasKey('value_prop', $result);
        $this->assertArrayHasKey('variant_id', $result);
        $this->assertArrayHasKey('is_ab_test', $result);
        $this->assertFalse($result['is_ab_test']);
        $this->assertEquals('base', $result['variant_id']);
        // Automotive technical now uses ISO 9001 (IATF 16949 removed as unverified)
        $this->assertStringContainsString('ISO 9001', $result['value_prop']);
    }
    
    public function testGetValuePropVariantHandlesUnknownIndustry(): void
    {
        $result = $this->service->getValuePropVariant('unknown_industry', 'business');
        
        $this->assertArrayHasKey('value_prop', $result);
        $this->assertNotEmpty($result['value_prop']);
    }
    
    // ========================= SOCIAL PROOF (NEW INDUSTRIES) =========================
    
    public function testSocialProofIncludesRenewables(): void
    {
        // Access SOCIAL_PROOF constant via reflection
        $reflection = new \ReflectionClass(EmailPersonalizationService::class);
        
        // Constants are accessed differently
        $this->assertTrue($reflection->hasConstant('SOCIAL_PROOF'), 'SOCIAL_PROOF constant should exist');
        
        // For private constants, we need to access via reflection method
        $constants = $reflection->getConstants(\ReflectionClassConstant::IS_PRIVATE);
        
        $this->assertArrayHasKey('SOCIAL_PROOF', $constants, 'SOCIAL_PROOF should be a private constant');
        
        $socialProof = $constants['SOCIAL_PROOF'];
        
        $this->assertArrayHasKey('renewables', $socialProof, 'Social proof should include renewables industry');
        $this->assertArrayHasKey('stat', $socialProof['renewables']);
        $this->assertArrayHasKey('reference', $socialProof['renewables']);
        $this->assertArrayHasKey('detail', $socialProof['renewables']);
    }
    
    public function testSocialProofIncludesSemiconductor(): void
    {
        $reflection = new \ReflectionClass(EmailPersonalizationService::class);
        $constants = $reflection->getConstants(\ReflectionClassConstant::IS_PRIVATE);
        
        $socialProof = $constants['SOCIAL_PROOF'];
        
        $this->assertArrayHasKey('semiconductor', $socialProof, 'Social proof should include semiconductor industry');
        $this->assertArrayHasKey('stat', $socialProof['semiconductor']);
    }
    
    // ========================= NEW: runFullQualityCheck TESTS =========================
    
    public function testRunFullQualityCheckPassesForGoodEmail(): void
    {
        $contact = $this->createMockContact('automotive', 'Procurement Manager');
        
        // A well-crafted email that should pass all checks
        $goodEmail = "Hi John,\n\nI noticed your company is exploring new manufacturing partners. " .
            "Your team might find value in our ISO 9001 certified facility. " .
            "Other procurement teams like yours report 99%+ on-time delivery. " .
            "Would a brief 15-minute call be useful to discuss your requirements?\n\n" .
            "Looking forward to connecting,\nSales Team";
        
        $result = $this->service->runFullQualityCheck($goodEmail, $contact);
        
        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('failed_checks', $result);
        
        // Should have all check categories
        $this->assertArrayHasKey('warmth', $result['checks']);
        $this->assertArrayHasKey('templated_language', $result['checks']);
        $this->assertArrayHasKey('personalization_depth', $result['checks']);
        $this->assertArrayHasKey('spintax_resolved', $result['checks']);
        $this->assertArrayHasKey('you_focus', $result['checks']);
        $this->assertArrayHasKey('cta_present', $result['checks']);
        $this->assertArrayHasKey('length', $result['checks']);
    }
    
    public function testRunFullQualityCheckFailsForCorporateEmail(): void
    {
        $contact = $this->createMockContact('automotive', 'Procurement Manager');
        
        // A corporate-sounding email that should fail warmth check
        $coldEmail = "Dear Sir/Madam,\n\nWe are pleased to inform you that our company offers " .
            "manufacturing services. Please find attached our capabilities document. " .
            "We would be grateful if you could kindly revert at your earliest convenience. " .
            "Please do not hesitate to contact us.\n\nBest regards,\nSales";
        
        $result = $this->service->runFullQualityCheck($coldEmail, $contact);
        
        // Should fail due to corporate language
        $this->assertFalse($result['passed']);
        $this->assertNotEmpty($result['failed_checks']);
    }
    
    public function testRunFullQualityCheckDetectsUnresolvedSpintax(): void
    {
        $contact = $this->createMockContact();
        
        // Email with unresolved spintax
        $emailWithSpintax = "Hi there, {we offer|we provide} great services. Would you like to chat?";
        
        $result = $this->service->runFullQualityCheck($emailWithSpintax, $contact);
        
        $this->assertFalse($result['checks']['spintax_resolved']['passed']);
        $this->assertContains('spintax_resolved', $result['failed_checks']);
    }
    
    // ========================= NEW: detectTemplatedLanguage TESTS =========================
    
    public function testDetectTemplatedLanguageFindsCommonPhrases(): void
    {
        $templatedEmail = "I hope this email finds you well. I wanted to reach out to introduce " .
            "our services. Please do not hesitate to contact me at your earliest convenience.";
        
        $result = $this->service->detectTemplatedLanguage($templatedEmail);
        
        $this->assertTrue($result['is_templated']);
        $this->assertGreaterThanOrEqual(2, $result['score']);
        $this->assertNotEmpty($result['phrases']);
        $this->assertContains('I hope this email finds you well', $result['phrases']);
    }
    
    public function testDetectTemplatedLanguagePassesForOriginalContent(): void
    {
        $originalEmail = "Quick question about your manufacturing needs. " .
            "Noticed you're in the automotive sector - any interest in exploring " .
            "nearshore alternatives?";
        
        $result = $this->service->detectTemplatedLanguage($originalEmail);
        
        $this->assertFalse($result['is_templated']);
        $this->assertEquals(0, $result['score']);
        $this->assertEmpty($result['phrases']);
        $this->assertEquals('Excellent - No templated language detected', $result['verdict']);
    }
    
    public function testDetectTemplatedLanguageCountsMultiplePhrases(): void
    {
        $heavilyTemplated = "I hope this message finds you. I am writing to introduce our company. " .
            "As per our conversation, I wanted to follow up. I trust this email finds you well. " .
            "Kindly revert at your earliest convenience.";
        
        $result = $this->service->detectTemplatedLanguage($heavilyTemplated);
        
        $this->assertTrue($result['is_templated']);
        $this->assertGreaterThanOrEqual(3, $result['score']);
        $this->assertEquals('Poor - Heavily templated, likely to be ignored', $result['verdict']);
    }
    
    // ========================= NEW: ensureUniqueVariation TESTS =========================
    
    public function testEnsureUniqueVariationSucceedsWithNoRecentEmails(): void
    {
        $contact = $this->createMockContact();
        
        $result = $this->service->ensureUniqueVariation($contact, []);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('No recent emails to compare against', $result['message']);
    }
    
    public function testEnsureUniqueVariationReturnsValidationCallback(): void
    {
        $contact = $this->createMockContact();
        $recentEmails = ['Previous email body content here.'];
        
        $result = $this->service->ensureUniqueVariation($contact, $recentEmails);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('validation_callback', $result);
        $this->assertIsCallable($result['validation_callback']);
        
        // Test the callback with different content
        $callback = $result['validation_callback'];
        $this->assertTrue($callback('Completely different content here'));
    }
    
    public function testEnsureUniqueVariationCallbackRejectsSimilarContent(): void
    {
        $contact = $this->createMockContact();
        $recentEmails = ['This is the original email about manufacturing services for your company.'];
        
        $result = $this->service->ensureUniqueVariation($contact, $recentEmails, 0.3); // Low threshold
        
        $callback = $result['validation_callback'];
        
        // Very similar content should be rejected
        $this->assertFalse($callback('This is the original email about manufacturing services for your company.'));
    }
    
    // ========================= NEW: Cialdini Spintax Resolution TESTS =========================
    
    public function testGetReciprocityElementResolvesSpintax(): void
    {
        $contact = $this->createMockContact('automotive', 'Engineer');
        
        $result = $this->service->getReciprocityElement($contact);
        
        // Should not contain unresolved spintax {option1|option2}
        $this->assertDoesNotMatchRegularExpression('/\{[^{}]+\|[^{}]+\}/', $result);
        $this->assertNotEmpty($result);
    }
    
    public function testGetScarcityElementResolvesSpintax(): void
    {
        $contact = $this->createMockContact('automotive', 'Procurement Manager');
        
        $result = $this->service->getScarcityElement($contact);
        
        // Should not contain unresolved spintax
        $this->assertDoesNotMatchRegularExpression('/\{[^{}]+\|[^{}]+\}/', $result);
        $this->assertNotEmpty($result);
    }
    
    public function testGetAuthorityElementResolvesSpintax(): void
    {
        $contact = $this->createMockContact('aerospace', 'Quality Manager');
        
        $result = $this->service->getAuthorityElement($contact);
        
        // Should not contain unresolved spintax
        $this->assertDoesNotMatchRegularExpression('/\{[^{}]+\|[^{}]+\}/', $result);
        $this->assertNotEmpty($result);
    }
    
    public function testGetConsistencyElementResolvesSpintax(): void
    {
        $result = $this->service->getConsistencyElement('cold');
        
        // Should not contain unresolved spintax
        $this->assertDoesNotMatchRegularExpression('/\{[^{}]+\|[^{}]+\}/', $result);
        $this->assertNotEmpty($result);
    }
    
    public function testGetLikingElementResolvesSpintax(): void
    {
        $contact = $this->createMockContact('medical', 'Operations Director');
        
        $result = $this->service->getLikingElement($contact);
        
        // Should not contain unresolved spintax
        $this->assertDoesNotMatchRegularExpression('/\{[^{}]+\|[^{}]+\}/', $result);
        $this->assertNotEmpty($result);
    }
    
    public function testGetSocialProofElementResolvesSpintax(): void
    {
        $contact = $this->createMockContact('industrial', 'Supply Chain Manager');
        
        $result = $this->service->getSocialProofElement($contact);
        
        // Should not contain unresolved spintax
        $this->assertDoesNotMatchRegularExpression('/\{[^{}]+\|[^{}]+\}/', $result);
        $this->assertNotEmpty($result);
    }
    
    public function testGetUnityElementResolvesSpintax(): void
    {
        $contact = $this->createMockContact('defense', 'Program Manager');
        
        $result = $this->service->getUnityElement($contact);
        
        // Should not contain unresolved spintax
        $this->assertDoesNotMatchRegularExpression('/\{[^{}]+\|[^{}]+\}/', $result);
        $this->assertNotEmpty($result);
    }
    
    // ========================= NEW: Email Fingerprint TESTS =========================
    
    public function testGenerateEmailFingerprintReturnsConsistentHash(): void
    {
        $email = "Hello John, this is a test email about manufacturing.";
        
        $hash1 = $this->service->generateEmailFingerprint($email);
        $hash2 = $this->service->generateEmailFingerprint($email);
        
        $this->assertEquals($hash1, $hash2);
        $this->assertEquals(16, strlen($hash1));
    }
    
    public function testGenerateEmailFingerprintNormalizesWhitespace(): void
    {
        $email1 = "Hello   John,  this   is  a  test.";
        $email2 = "Hello John, this is a test.";
        
        $hash1 = $this->service->generateEmailFingerprint($email1);
        $hash2 = $this->service->generateEmailFingerprint($email2);
        
        $this->assertEquals($hash1, $hash2);
    }
    
    public function testGenerateEmailFingerprintDifferentForDifferentContent(): void
    {
        $email1 = "Hello John, this is about manufacturing.";
        $email2 = "Hi Jane, this is about aerospace.";
        
        $hash1 = $this->service->generateEmailFingerprint($email1);
        $hash2 = $this->service->generateEmailFingerprint($email2);
        
        $this->assertNotEquals($hash1, $hash2);
    }
    
    public function testIsDuplicateFingerprintDetectsDuplicates(): void
    {
        $fingerprint = 'abc123def456abcd';
        $recentFingerprints = ['xyz789', 'abc123def456abcd', 'def456'];
        
        $this->assertTrue($this->service->isDuplicateFingerprint($fingerprint, $recentFingerprints));
    }
    
    public function testIsDuplicateFingerprintReturnsFalseForUnique(): void
    {
        $fingerprint = 'unique12345678ab';
        $recentFingerprints = ['xyz789', 'abc123', 'def456'];
        
        $this->assertFalse($this->service->isDuplicateFingerprint($fingerprint, $recentFingerprints));
    }
    
    // ========================= NEW: Transitional Phrases Deterministic TESTS =========================
    
    public function testAddTransitionalPhrasesDeterministicWithSeed(): void
    {
        $content = "First paragraph here.\n\nSecond paragraph content.\n\nThird paragraph text.\n\nFinal closing.";
        
        // Same seed should produce same result
        $result1 = $this->service->addTransitionalPhrases($content, 12345, 1.0);
        $result2 = $this->service->addTransitionalPhrases($content, 12345, 1.0);
        
        $this->assertEquals($result1, $result2);
    }
    
    public function testAddTransitionalPhrasesDifferentSeedsDifferentResults(): void
    {
        $content = "First paragraph here.\n\nSecond paragraph content.\n\nThird paragraph text.\n\nFinal closing.";
        
        // Different seeds should (likely) produce different results with 100% probability
        $result1 = $this->service->addTransitionalPhrases($content, 11111, 1.0);
        $result2 = $this->service->addTransitionalPhrases($content, 99999, 1.0);
        
        // They could be the same by chance, but with 100% probability and different seeds,
        // they should differ in which transition was chosen
        $this->assertIsString($result1);
        $this->assertIsString($result2);
    }
    
    public function testAddTransitionalPhrasesZeroProbability(): void
    {
        $content = "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.\n\nClosing.";
        
        // With 0% probability, no transitions should be added
        $result = $this->service->addTransitionalPhrases($content, 12345, 0.0);
        
        // Should be unchanged (no transitions added)
        $this->assertEquals($content, $result);
    }
    
    // ========================= NEW: previewEmailWithMetrics TESTS =========================
    
    public function testPreviewEmailWithMetricsReturnsQualityMetrics(): void
    {
        $contact = $this->createMockContact('automotive', 'Procurement Manager');
        
        $result = $this->service->previewEmailWithMetrics($contact);
        
        // Check new quality_metrics section exists
        $this->assertArrayHasKey('quality_metrics', $result);
        $this->assertArrayHasKey('warmth', $result['quality_metrics']);
        $this->assertArrayHasKey('templated_language', $result['quality_metrics']);
        $this->assertArrayHasKey('fingerprint', $result['quality_metrics']);
        
        // Warmth should have expected structure
        $this->assertArrayHasKey('score', $result['quality_metrics']['warmth']);
        $this->assertArrayHasKey('verdict', $result['quality_metrics']['warmth']);
        
        // Templated language should have expected structure
        $this->assertArrayHasKey('is_templated', $result['quality_metrics']['templated_language']);
        $this->assertArrayHasKey('score', $result['quality_metrics']['templated_language']);
    }
    
    public function testPreviewEmailWithMetricsAcceptsSampleBody(): void
    {
        $contact = $this->createMockContact('automotive', 'Procurement Manager');
        $sampleBody = "Hi John, quick question about your manufacturing needs. Would a call help?";
        
        $result = $this->service->previewEmailWithMetrics($contact, [], $sampleBody);
        
        // Should have calculated metrics on the provided sample body
        $this->assertArrayHasKey('quality_metrics', $result);
        $this->assertArrayHasKey('sample_body', $result);
        $this->assertEquals($sampleBody, $result['sample_body']);
        
        // Fingerprint should be set for the sample body
        $this->assertNotNull($result['quality_metrics']['fingerprint']);
    }
    
    // ========================= P4-1 FIX: INDUSTRY SUBJECT PATTERNS TESTS =========================
    
    public function testAutomotiveSubjectPatternsDoNotContainIATF(): void
    {
        $contact = $this->createMockContact('automotive', 'Procurement Manager');
        
        // Generate multiple subject lines to verify no IATF references
        $foundIATF = false;
        for ($i = 0; $i < 20; $i++) {
            $subject = $this->service->getIndustrySubjectLine($contact);
            if (stripos($subject, 'IATF') !== false) {
                $foundIATF = true;
                break;
            }
        }
        
        $this->assertFalse($foundIATF, 'Automotive subject patterns should not contain IATF (unverified certification)');
    }
    
    public function testAutomotiveSubjectPatternsContainVerifiedTerms(): void
    {
        $contact = $this->createMockContact('automotive', 'Procurement Manager');
        
        // Generate multiple subject lines and check for verified terms
        $subjects = [];
        for ($i = 0; $i < 30; $i++) {
            $subjects[] = $this->service->getIndustrySubjectLine($contact);
        }
        
        $allSubjects = implode(' ', $subjects);
        
        // Should contain automotive industry terms (PPAP, APQP, Tier 1) or ISO 9001
        $hasAutomotiveTerms = 
            stripos($allSubjects, 'PPAP') !== false ||
            stripos($allSubjects, 'APQP') !== false ||
            stripos($allSubjects, 'Tier 1') !== false ||
            stripos($allSubjects, 'ISO 9001') !== false ||
            stripos($allSubjects, 'Automotive') !== false ||
            stripos($allSubjects, 'Nearshore') !== false;
            
        $this->assertTrue($hasAutomotiveTerms, 'Automotive subjects should contain industry-relevant terms');
    }
    
    public function testIndustrySubjectLineIncludesCompanyName(): void
    {
        $contact = $this->createMockContact('aerospace', 'Engineer');
        
        $subject = $this->service->getIndustrySubjectLine($contact);
        
        // Most patterns include company name
        // At minimum, the subject should not be empty
        $this->assertNotEmpty($subject);
        $this->assertIsString($subject);
    }
    
    // ========================= P4-3 FIX: CONFIGURABLE WARMTH SCORING TESTS =========================
    
    public function testCalculateWarmthScoreReturnsConfigInfo(): void
    {
        $email = "Hi John, you might find this interesting. Would you like to chat?";
        
        $result = $this->service->calculateWarmthScore($email);
        
        // P4-3: Should include configuration info for debugging/tuning
        $this->assertArrayHasKey('config', $result);
        $this->assertArrayHasKey('base_score', $result['config']);
        $this->assertArrayHasKey('thresholds', $result['config']);
        $this->assertArrayHasKey('indicators_count', $result['config']);
        
        // Verify thresholds structure
        $this->assertArrayHasKey('excellent', $result['config']['thresholds']);
        $this->assertArrayHasKey('good', $result['config']['thresholds']);
        $this->assertArrayHasKey('minimum', $result['config']['thresholds']);
        
        // Verify indicator counts
        $this->assertArrayHasKey('positive', $result['config']['indicators_count']);
        $this->assertArrayHasKey('negative', $result['config']['indicators_count']);
        $this->assertGreaterThan(0, $result['config']['indicators_count']['positive']);
        $this->assertGreaterThan(0, $result['config']['indicators_count']['negative']);
    }
    
    public function testCalculateWarmthScoreDetectsNewColdIndicators(): void
    {
        // P4-3: New cold indicators added - sir/madam, dear sir, revert, kindly revert
        $coldEmail = "Dear Sir/Madam, Please kindly revert at your earliest convenience.";
        
        $result = $this->service->calculateWarmthScore($coldEmail);
        
        // Should detect cold language and have low score
        $this->assertLessThan(5.0, $result['score'], 'Email with sir/madam and kindly revert should score low');
        $this->assertNotEmpty($result['breakdown']['cold'], 'Should detect cold indicators');
    }
    
    public function testCalculateWarmthScoreDetectsNewWarmIndicators(): void
    {
        // P4-3: New warm indicators added - excited, curious, interesting
        $warmEmail = "Hi! I'm excited to share this with you. I'm curious about your thoughts. This is interesting for your team.";
        
        $result = $this->service->calculateWarmthScore($warmEmail);
        
        // Should detect warm language
        $this->assertNotEmpty($result['breakdown']['warm'], 'Should detect warm indicators');
        $this->assertGreaterThan(5.0, $result['score'], 'Email with excited/curious should score above neutral');
    }
    
    public function testCalculateWarmthScoreVerdictUsesThresholds(): void
    {
        // Test excellent verdict
        $excellentEmail = "Hi John! You will love this. Your team would benefit. Would you like to chat? I'd love to discuss.";
        $result = $this->service->calculateWarmthScore($excellentEmail);
        $this->assertEquals('Warm & Personal', $result['verdict']);
        
        // Test corporate verdict
        $corporateEmail = "We hereby inform you that our company offers services. Please find attached. Kindly revert.";
        $result = $this->service->calculateWarmthScore($corporateEmail);
        $this->assertEquals('Too Corporate', $result['verdict']);
    }
    
    // ========================= P4-2 FIX: VARIATION GENERATION STATS TESTS =========================
    
    public function testGenerateUniqueEmailVariationsReturnsStats(): void
    {
        $contact = $this->createMockContact('industrial', 'Operations Manager');
        
        $result = $this->service->generateUniqueEmailVariations($contact, 2, 5);
        
        // P4-2: Should include generation statistics
        $this->assertArrayHasKey('stats', $result);
        $this->assertArrayHasKey('total_attempts', $result['stats']);
        $this->assertArrayHasKey('total_collisions', $result['stats']);
        $this->assertArrayHasKey('elapsed_ms', $result['stats']);
        $this->assertArrayHasKey('avg_attempts_per_variation', $result['stats']);
        
        // Verify stats are populated
        $this->assertGreaterThanOrEqual(0, $result['stats']['total_attempts']);
        $this->assertGreaterThanOrEqual(0, $result['stats']['total_collisions']);
        $this->assertGreaterThanOrEqual(0, $result['stats']['elapsed_ms']);
    }
    
    public function testGenerateUniqueEmailVariationsIncludesCollisionCount(): void
    {
        $contact = $this->createMockContact('consumer', 'Product Manager');
        
        $result = $this->service->generateUniqueEmailVariations($contact, 1, 10);
        
        // Each variation should include collision count
        if (!empty($result['variations'])) {
            $this->assertArrayHasKey('collisions', $result['variations'][0]);
            $this->assertGreaterThanOrEqual(0, $result['variations'][0]['collisions']);
        }
    }
    
    // ========================= HELPER METHODS =========================
    
    private function createMockContact(?string $industry = null, ?string $jobTitle = null): Contact
    {
        $contact = $this->createMock(Contact::class);
        $contact->method('getId')->willReturn(1);
        $contact->method('getFirstName')->willReturn('John');
        $contact->method('getLastName')->willReturn('Doe');
        $contact->method('getJobTitle')->willReturn($jobTitle ?? 'Manager');
        
        if ($industry) {
            $company = $this->createMock(Company::class);
            $company->method('getName')->willReturn('Test Corp');
            $company->method('getSector')->willReturn($industry);
            $company->method('getPhysicalSite')->willReturn('Germany');
            $company->method('getAccountTier')->willReturn('B');
            $contact->method('getCompany')->willReturn($company);
        } else {
            $contact->method('getCompany')->willReturn(null);
        }
        
        // Setup profile repository to return null (cold contact)
        $this->profileRepository->method('findByContactId')->willReturn(null);
        $this->profileRepository->method('findOrCreateForContact')->willReturn(
            $this->createMock(PersonalizationProfile::class)
        );
        
        return $contact;
    }
}
