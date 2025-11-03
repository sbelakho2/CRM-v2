<?php

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
 */
class EmailTemplateService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailTemplateRepository $templateRepository
    ) {}

    /**
     * Create a new email template
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
        $clone->setSubjectLine($source->getSubjectLine());
        $clone->setPreviewText($source->getPreviewText());
        $clone->setBodyHtml($source->getBodyHtml());
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
     */
    public function getActiveTemplates(): array
    {
        return $this->templateRepository->findActive();
    }

    /**
     * Get templates by category
     */
    public function getTemplatesByCategory(string $category): array
    {
        return $this->templateRepository->findByCategory($category);
    }

    /**
     * Search templates by name
     */
    public function searchTemplates(string $query): array
    {
        return $this->templateRepository->searchByName($query);
    }

    /**
     * Validate personalization tokens in template content
     * 
     * @return array Array of validation errors (empty if valid)
     */
    public function validatePersonalizationTokens(EmailTemplate $template): array
    {
        $errors = [];
        $allowedTokens = $template->getPersonalizationTokens() ?? [];
        
        // Extract tokens from subject line
        $subjectTokens = $this->extractTokens($template->getSubjectLine());
        
        // Extract tokens from body HTML
        $bodyTokens = $this->extractTokens($template->getBodyHtml());
        
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
     * @param array $data Associative array of token values
     * @return array ['subject' => string, 'html' => string, 'text' => string]
     */
    public function renderTemplate(EmailTemplate $template, array $data): array
    {
        $subject = $this->replaceTokens($template->getSubjectLine(), $data);
        $html = $this->replaceTokens($template->getBodyHtml(), $data);
        $text = $this->replaceTokens($template->getBodyText() ?? '', $data);

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'previewText' => $template->getPreviewText(),
        ];
    }

    /**
     * Generate preview with sample data
     */
    public function generatePreview(EmailTemplate $template): array
    {
        $sampleData = $this->generateSampleData($template);
        return $this->renderTemplate($template, $sampleData);
    }

    /**
     * Extract personalization tokens from text
     * Returns array of token names (without curly braces)
     */
    private function extractTokens(string $text): array
    {
        preg_match_all('/\{\{([a-zA-Z0-9_.]+)\}\}/', $text, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Replace tokens in text with actual values
     */
    private function replaceTokens(string $text, array $data): string
    {
        $result = $text;
        
        foreach ($data as $key => $value) {
            // Support dot notation (e.g., contact.firstName)
            $token = '{{' . $key . '}}';
            $result = str_replace($token, (string) $value, $result);
        }
        
        // Remove any unreplaced tokens
        $result = preg_replace('/\{\{[a-zA-Z0-9_.]+\}\}/', '', $result);
        
        return $result;
    }

    /**
     * Generate sample data for preview
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
        
        // Remove dangerous attributes (onclick, onerror, etc.)
        $html = preg_replace('/\son\w+="[^"]*"/i', '', $html);
        $html = preg_replace('/\son\w+=\'[^\']*\'/i', '', $html);
        
        // Remove javascript: protocol
        $html = preg_replace('/href="javascript:[^"]*"/i', 'href="#"', $html);
        
        return $html;
    }

    /**
     * Get template statistics
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
        $usageRange = $this->entityManager->createQuery(
            'SELECT MIN(ec.createdAt) as firstUsed, MAX(ec.createdAt) as lastUsed 
             FROM App\Entity\EmailCampaign ec 
             WHERE ec.template = :template'
        )
        ->setParameter('template', $template)
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

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Import template from JSON
     */
    public function importTemplate(string $json, ?string $newName = null): EmailTemplate
    {
        $data = json_decode($json, true);
        
        if (!$data) {
            throw new \InvalidArgumentException('Invalid JSON data');
        }

        return $this->createTemplate(
            name: $newName ?? ($data['name'] . ' (Imported)'),
            subjectLine: $data['subjectLine'] ?? '',
            bodyHtml: $data['bodyHtml'] ?? '',
            bodyText: $data['bodyText'] ?? null,
            previewText: $data['previewText'] ?? null,
            category: $data['category'] ?? 'general',
            personalizationTokens: $data['personalizationTokens'] ?? [],
            isActive: true
        );
    }
}
