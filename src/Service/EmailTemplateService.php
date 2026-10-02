<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailTemplate;
use App\Repository\EmailTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service for managing email templates
 * 
 * Features:
 * - CRUD operations for templates
 * - Personalization token validation and rendering
 * - WYSIWYG editor support (HTML sanitization)
 * - Template preview generation
 * - Category-based organization
 * 
 * Security:
 * - All user-supplied values are HTML-escaped before injection into email body
 * - Dangerous HTML tags and attributes are stripped
 * - javascript: protocol is removed from all href attributes
 * - Event handler attributes (onclick, onerror, etc.) are stripped
 */
class EmailTemplateService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailTemplateRepository $templateRepository
    ) {}

    /**
     * Create a new email template
     *
     * @param list<string> $personalizationTokens
     */
    public function createTemplate(
        string $name,
        string $subjectLine,
        string $bodyHtml,
        ?string $bodyText = null,
        ?string $previewText = null,
        string $category = 'general',
        array $personalizationTokens = [],
        bool $isActive = true
    ): EmailTemplate {
        $template = new EmailTemplate();
        $template->setName($name);
        $template->setSubjectLine($subjectLine);
        $template->setBodyHtml($this->sanitizeHtml($bodyHtml));
        $template->setBodyText($bodyText ?? strip_tags($bodyHtml));
        $template->setPreviewText($previewText);
        $template->setCategory($category);
        $template->setPersonalizationTokens($personalizationTokens);
        $template->setIsActive($isActive);
        $template->setCreatedAt(new \DateTimeImmutable());
        $template->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $template;
    }

    /**
     * Update an existing template
     *
     * @param list<string>|null $personalizationTokens
     */
    public function updateTemplate(
        EmailTemplate $template,
        ?string $name = null,
        ?string $subjectLine = null,
        ?string $bodyHtml = null,
        ?string $bodyText = null,
        ?string $previewText = null,
        ?string $category = null,
        ?array $personalizationTokens = null,
        ?bool $isActive = null
    ): EmailTemplate {
        if ($name !== null) {
            $template->setName($name);
        }
        if ($subjectLine !== null) {
            $template->setSubjectLine($subjectLine);
        }
        if ($bodyHtml !== null) {
            $template->setBodyHtml($this->sanitizeHtml($bodyHtml));
        }
        if ($bodyText !== null) {
            $template->setBodyText($bodyText);
        }
        if ($previewText !== null) {
            $template->setPreviewText($previewText);
        }
        if ($category !== null) {
            $template->setCategory($category);
        }
        if ($personalizationTokens !== null) {
            $template->setPersonalizationTokens($personalizationTokens);
        }
        if ($isActive !== null) {
            $template->setIsActive($isActive);
        }

        $template->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $template;
    }

    /**
     * Delete a template (soft delete by marking inactive)
     */
    public function deleteTemplate(EmailTemplate $template, bool $hardDelete = false): void
    {
        if ($hardDelete) {
            $this->entityManager->remove($template);
        } else {
            $template->setIsActive(false);
            $template->setUpdatedAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();
    }

    /**
     * Clone a template with a new name
     */
    public function cloneTemplate(EmailTemplate $source, string $newName): EmailTemplate
    {
        $clone = new EmailTemplate();
        $clone->setName($newName);
        $clone->setSubjectLine($source->getSubjectLine() ?? '');
        $clone->setPreviewText($source->getPreviewText());
        $clone->setBodyHtml($source->getBodyHtml() ?? '');
        $clone->setBodyText($source->getBodyText());
        $clone->setCategory($source->getCategory());
        $clone->setPersonalizationTokens($source->getPersonalizationTokens() ?? []);
        $clone->setIsActive(true);
        $clone->setCreatedAt(new \DateTimeImmutable());
        $clone->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($clone);
        $this->entityManager->flush();

        return $clone;
    }

    /**
     * Get all active templates
     *
     * @return list<EmailTemplate>
     */
    public function getActiveTemplates(): array
    {
        return $this->templateRepository->findActive();
    }

    /**
     * Get templates by category
     *
     * @return list<EmailTemplate>
     */
    public function getTemplatesByCategory(string $category): array
    {
        return $this->templateRepository->findByCategory($category);
    }

    /**
     * Search templates by name
     *
     * @return list<EmailTemplate>
     */
    public function searchTemplates(string $query): array
    {
        return $this->templateRepository->searchByName($query);
    }

    /**
     * Validate personalization tokens in template content
     *
     * @return list<string> Array of validation errors (empty if valid)
     */
    public function validatePersonalizationTokens(EmailTemplate $template): array
    {
        $errors = [];
        $allowedTokens = $template->getPersonalizationTokens() ?? [];

        // Extract tokens from subject line
        $subjectTokens = $this->extractTokens($template->getSubjectLine() ?? '');

        // Extract tokens from body HTML
        $bodyTokens = $this->extractTokens($template->getBodyHtml() ?? '');
        
        // Combine all used tokens
        $usedTokens = array_unique(array_merge($subjectTokens, $bodyTokens));
        
        // Check for undefined tokens
        foreach ($usedTokens as $token) {
            if (!in_array($token, $allowedTokens)) {
                $errors[] = sprintf('Undefined token: {{%s}}', $token);
            }
        }
        
        return $errors;
    }

    /**
     * Render template with personalization data
     * 
     * All user-supplied values are HTML-escaped before injection into the
     * email body to prevent XSS attacks via personalization token values.
     * 
     * @param array<string, mixed> $data Associative array of token values
     * @return array{subject: string, html: string, text: string, previewText: string|null}
     */
    public function renderTemplate(EmailTemplate $template, array $data): array
    {
        $subject = $this->replaceTokens($template->getSubjectLine() ?? '', $data, false);
        $html = $this->replaceTokens($template->getBodyHtml() ?? '', $data, true);
        $text = $this->replaceTokens($template->getBodyText() ?? '', $data, false);

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'previewText' => $template->getPreviewText(),
        ];
    }

    /**
     * Generate preview with sample data
     *
     * @return array{subject: string, html: string, text: string, previewText: string|null}
     */
    public function generatePreview(EmailTemplate $template): array
    {
        $sampleData = $this->generateSampleData($template);
        return $this->renderTemplate($template, $sampleData);
    }

    /**
     * Extract personalization tokens from text
     * Returns array of token names (without curly braces)
     *
     * @return list<string>
     */
    private function extractTokens(string $text): array
    {
        preg_match_all('/\{\{([a-zA-Z0-9_.]+)\}\}/', $text, $matches);
        return $matches[1];
    }

    /**
     * Replace tokens in text with actual values.
     *
     * When $escapeForHtml is true, values are run through htmlspecialchars()
     * to prevent XSS injection via personalization data. This is critical
     * because token values often come from user-supplied contact/company data.
     *
     * For plain text contexts (subject line, text body), escaping is skipped
     * to preserve intended formatting.
     *
     * @param string $text The template text containing {{token}} placeholders
     * @param array<string, mixed> $data Associative array of token => value pairs
     * @param bool $escapeForHtml Whether to HTML-escape values (true for HTML body)
     * @return string The text with tokens replaced
     */
    private function replaceTokens(string $text, array $data, bool $escapeForHtml = true): string
    {
        $result = $text;

        foreach ($data as $key => $value) {
            // Support dot notation (e.g., contact.firstName)
            $token = '{{' . $key . '}}';

            // HTML-escape values when injecting into HTML body to prevent XSS
            $replacement = $escapeForHtml
                ? htmlspecialchars(self::scalarToString($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : self::scalarToString($value);

            $result = str_replace($token, $replacement, $result);
        }

        // Remove any unreplaced tokens (prevents {{malicious_code}} from being rendered)
        return preg_replace('/\{\{[a-zA-Z0-9_.]+\}\}/', '', $result) ?? $result;
    }

    /**
     * Weak-mode string coercion for scalar token values; non-scalars (which
     * previously hit a TypeError under strict_types) become an empty string.
     */
    private static function scalarToString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Generate sample data for preview
     *
     * @return array<string, string>
     */
    private function generateSampleData(EmailTemplate $template): array
    {
        $tokens = $template->getPersonalizationTokens() ?? [];
        $sampleData = [];
        
        foreach ($tokens as $token) {
            $sampleData[$token] = $this->getSampleValueForToken($token);
        }
        
        return $sampleData;
    }

    /**
     * Get sample value based on token name
     */
    private function getSampleValueForToken(string $token): string
    {
        // Common token patterns
        $samples = [
            'contact.firstName' => 'John',
            'contact.lastName' => 'Doe',
            'contact.email' => 'john.doe@example.com',
            'contact.phone' => '+1-555-0123',
            'company.name' => 'Acme Corporation',
            'company.industry' => 'Electronics Manufacturing',
            'company.website' => 'www.acme.com',
            'quote.number' => 'Q-2025-001',
            'quote.amount' => '$15,750.00',
            'quote.validUntil' => 'November 15, 2025',
            'user.firstName' => 'Sarah',
            'user.lastName' => 'Smith',
            'user.title' => 'Account Manager',
        ];
        
        return $samples[$token] ?? ucfirst(str_replace('.', ' ', $token));
    }

    /**
     * Sanitize HTML content for security
     * Removes dangerous tags and attributes
     * 
     * This provides defense-in-depth: even if a template editor
     * bypasses the WYSIWYG, dangerous content is stripped at storage time.
     */
    private function sanitizeHtml(string $html): string
    {
        // List of allowed tags
        $allowedTags = [
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'img',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'ol', 'li',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'div', 'span', 'hr',
        ];
        
        $allowedAttrs = [
            'href', 'src', 'alt', 'title', 'class', 'id', 'style',
            'width', 'height', 'align', 'border', 'cellpadding', 'cellspacing',
        ];
        
        // Strip tags not in allowed list
        $html = strip_tags($html, '<' . implode('><', $allowedTags) . '>');
        
        // Remove dangerous attributes (onclick, onerror, etc.) — case-insensitive
        $html = preg_replace('/\son\w+="[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace('/\son\w+=\'[^\']*\'/i', '', $html) ?? $html;
        
        // Also remove event handlers without quotes (e.g., onclick=alert(1))
        $html = preg_replace('/\son\w+\s*=\s*[^\s>]+/i', '', $html) ?? $html;
        
        // Remove javascript: protocol from href attributes
        $html = preg_replace('/href="javascript:[^"]*"/i', 'href="#"', $html) ?? $html;
        $html = preg_replace('/href=\'javascript:[^\']*\'/i', "href='#'", $html) ?? $html;
        
        // Remove data: URIs from src attributes (can be used for XSS)
        $html = preg_replace('/src="data:[^"]*"/i', 'src=""', $html) ?? $html;
        $html = preg_replace("/src='data:[^']*'/i", "src=''", $html) ?? $html;
        
        // Remove <iframe>, <script>, <object>, <embed>, <style> that may have survived strip_tags
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<iframe[^>]*>.*?<\/iframe>/is', '', $html) ?? $html;
        $html = preg_replace('/<object[^>]*>.*?<\/object>/is', '', $html) ?? $html;
        $html = preg_replace('/<embed[^>]*>.*?<\/embed>/is', '', $html) ?? $html;
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        
        return $html;
    }

    /**
     * Get template statistics
     *
     * @return array{campaignCount: int, emailsSent: int, firstUsed: \DateTimeInterface|null, lastUsed: \DateTimeInterface|null, tokenCount: int}
     */
    public function getTemplateStats(EmailTemplate $template): array
    {
        // Count campaigns using this template
        $campaignCount = $this->entityManager->createQuery(
            'SELECT COUNT(ec.id) FROM App\Entity\EmailCampaign ec WHERE ec.template = :template'
        )
        ->setParameter('template', $template)
        ->getSingleScalarResult();

        // Count emails sent using this template
        $emailsSent = $this->entityManager->createQuery(
            'SELECT COUNT(es.id) FROM App\Entity\EmailSend es 
             JOIN es.campaign ec 
             WHERE ec.template = :template'
        )
        ->setParameter('template', $template)
        ->getSingleScalarResult();

        // Get usage date range
        $usageRangeQuery = $this->entityManager->createQuery(
            'SELECT MIN(ec.createdAt) as firstUsed, MAX(ec.createdAt) as lastUsed 
             FROM App\Entity\EmailCampaign ec 
             WHERE ec.template = :template'
        )
        ->setParameter('template', $template);

        /** @var array{firstUsed: \DateTimeInterface|null, lastUsed: \DateTimeInterface|null}|null $usageRange */
        $usageRange = $usageRangeQuery
            ->getOneOrNullResult();

        return [
            'campaignCount' => (int) $campaignCount,
            'emailsSent' => (int) $emailsSent,
            'firstUsed' => $usageRange['firstUsed'] ?? null,
            'lastUsed' => $usageRange['lastUsed'] ?? null,
            'tokenCount' => count($template->getPersonalizationTokens() ?? []),
        ];
    }

    /**
     * Export template to JSON
     */
    public function exportTemplate(EmailTemplate $template): string
    {
        $data = [
            'name' => $template->getName(),
            'subjectLine' => $template->getSubjectLine(),
            'previewText' => $template->getPreviewText(),
            'bodyHtml' => $template->getBodyHtml(),
            'bodyText' => $template->getBodyText(),
            'category' => $template->getCategory(),
            'personalizationTokens' => $template->getPersonalizationTokens(),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * Import template from JSON
     */
    public function importTemplate(string $json, ?string $newName = null): EmailTemplate
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($json, true);
        
        if (!is_array($data) || $data === []) {
            throw new \InvalidArgumentException('Invalid JSON data');
        }

        $importedName = self::scalarToString($data['name'] ?? '');
        $tokens = $data['personalizationTokens'] ?? [];
        $subjectLine = self::scalarToString($data['subjectLine'] ?? '');
        $bodyHtml = self::scalarToString($data['bodyHtml'] ?? '');
        $bodyText = isset($data['bodyText']) && is_string($data['bodyText']) ? $data['bodyText'] : null;
        $previewText = isset($data['previewText']) && is_string($data['previewText']) ? $data['previewText'] : null;
        $category = self::scalarToString($data['category'] ?? 'general');

        return $this->createTemplate(
            name: $newName ?? ($importedName . ' (Imported)'),
            subjectLine: $subjectLine,
            bodyHtml: $bodyHtml,
            bodyText: $bodyText,
            previewText: $previewText,
            category: $category !== '' ? $category : 'general',
            personalizationTokens: is_array($tokens) ? array_values(array_filter($tokens, 'is_string')) : [],
            isActive: true
        );
    }
}
