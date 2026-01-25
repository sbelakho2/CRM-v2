<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LLM Enrichment Service
 * 
 * Uses OpenAI or compatible LLM APIs to extract structured information
 * from unstructured text scraped from company websites.
 * 
 * Extracts:
 * - CEO/Decision maker names
 * - Primary contact emails
 * - Industry classification
 * - Company summary
 */
class LlmEnrichmentService
{
    private const DEFAULT_MODEL = 'gpt-3.5-turbo';
    private const MAX_TOKENS = 500;
    
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ?string $apiKey = null,
        private string $apiBaseUrl = 'https://api.openai.com/v1',
        private string $model = self::DEFAULT_MODEL
    ) {}
    
    /**
     * Check if the service is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Enrich lead data from scraped website content
     * 
     * @param string $companyName The company name
     * @param string $aboutText Scraped about/description text
     * @param array $contactNames Names found during scraping
     * @return array|null Enriched data or null on failure
     */
    public function enrichLeadFromScrapedContent(
        string $companyName,
        string $aboutText,
        array $contactNames = []
    ): ?array {
        if (!$this->isConfigured()) {
            $this->logger->debug('LLM enrichment skipped - not configured');
            return null;
        }
        
        $prompt = $this->buildExtractionPrompt($companyName, $aboutText, $contactNames);
        
        try {
            $response = $this->callLlm($prompt);
            return $this->parseResponse($response);
        } catch (\Exception $e) {
            $this->logger->error('LLM enrichment failed', [
                'company' => $companyName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extract contact information from raw text using LLM
     * 
     * @param string $text Raw text content
     * @return array Extracted contacts
     */
    public function extractContactsFromText(string $text): array
    {
        if (!$this->isConfigured()) {
            return [];
        }
        
        $prompt = <<<PROMPT
Extract all contact information from the following text. Return a JSON object with:
- "emails": array of email addresses found
- "phones": array of phone numbers found  
- "names": array of person names with their roles (e.g., {"name": "John Doe", "role": "CEO"})

Text:
{$text}

Return ONLY valid JSON, no other text.
PROMPT;
        
        try {
            $response = $this->callLlm($prompt);
            $data = json_decode($response, true);
            
            return is_array($data) ? $data : [];
            
        } catch (\Exception $e) {
            $this->logger->warning('Contact extraction failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Classify company industry/sector using LLM
     */
    public function classifyIndustry(string $companyName, string $description): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }
        
        $industries = implode(', ', [
            'Automotive', 'Aerospace', 'Industrial', 'Rail', 
            'Renewables', 'Power Electronics', 'Medical', 'Defense',
            'Consumer Electronics', 'Telecommunications', 'Other'
        ]);
        
        $prompt = <<<PROMPT
Classify this company into one of these industries: {$industries}

Company: {$companyName}
Description: {$description}

Reply with ONLY the industry name, nothing else.
PROMPT;
        
        try {
            $response = $this->callLlm($prompt);
            return trim($response);
        } catch (\Exception $e) {
            $this->logger->warning('Industry classification failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Build the extraction prompt
     */
    private function buildExtractionPrompt(string $companyName, string $aboutText, array $contactNames): string
    {
        $namesStr = !empty($contactNames) ? implode(', ', $contactNames) : 'None found';
        
        return <<<PROMPT
Analyze this company information and extract structured data.

Company Name: {$companyName}

About/Description:
{$aboutText}

Names found on website: {$namesStr}

Extract and return a JSON object with:
{
  "summary": "1-2 sentence company summary",
  "industry": "primary industry (Automotive, Aerospace, Industrial, Electronics, etc.)",
  "key_person": {
    "name": "most likely decision maker name if identifiable",
    "role": "their likely role (CEO, Procurement Manager, etc.)"
  },
  "primary_email_pattern": "likely email format like firstname.lastname@domain.com or info@domain.com",
  "company_size": "estimated size (small, medium, large) based on description",
  "confidence": "low, medium, or high - how confident you are in this analysis"
}

Return ONLY valid JSON, no markdown or explanation.
PROMPT;
    }

    /**
     * Call the LLM API
     */
    private function callLlm(string $prompt): string
    {
        $response = $this->httpClient->request('POST', $this->apiBaseUrl . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a data extraction assistant. Extract structured information from company website content. Always respond with valid JSON when asked.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => self::MAX_TOKENS,
                'temperature' => 0.1, // Low temperature for consistent extraction
            ],
            'timeout' => 30,
        ]);
        
        $data = $response->toArray();
        
        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Parse LLM response into structured data
     */
    private function parseResponse(string $response): ?array
    {
        // Try to parse as JSON
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to extract JSON from response
            if (preg_match('/\{[^}]+\}/s', $response, $matches)) {
                $data = json_decode($matches[0], true);
            }
        }
        
        if (!is_array($data)) {
            $this->logger->warning('Could not parse LLM response as JSON', [
                'response' => substr($response, 0, 200)
            ]);
            return null;
        }
        
        return $data;
    }
}
