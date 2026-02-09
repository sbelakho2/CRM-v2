<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gemini Flash Contact Extractor
 * 
 * Uses Google Gemini Flash 2.0 to intelligently extract structured contacts
 * from raw HTML snippets of team/leadership/about pages.
 * 
 * Why Gemini instead of regex?
 * - Handles wildly varying HTML structures across 3,900+ company websites
 * - Understands context: "John is our VP of Procurement" → structured contact
 * - Handles multilingual content (Arabic, French, German, Dutch, etc.)
 * - Handles messy DOM: nested divs, CSS-grid layouts, tabs, accordions
 * - Near-free: Gemini Flash has extremely generous free tier
 * 
 * Cost strategy: Each call extracts contacts from ~50KB of HTML.
 * At ~1 call per company, processing 3,922 companies ≈ 3,922 API calls.
 * Gemini Flash free tier: 1,500 req/day → ~3 days, or paid: ~$0.01/1M tokens.
 */
class GeminiContactExtractorService
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
    private const MAX_HTML_CHARS = 40000; // ~40KB per request to stay within token limits
    private const REQUEST_TIMEOUT = 30;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $geminiApiKey,
    ) {}

    /**
     * Check if the service is configured (has API key)
     */
    public function isConfigured(): bool
    {
        return !empty($this->geminiApiKey) && $this->geminiApiKey !== 'null';
    }

    /**
     * Extract contacts from raw HTML using Gemini Flash.
     * 
     * @param string $html    Raw HTML content (team sections, about page, etc.)
     * @param string $company Company name for context
     * @param string|null $domain Company domain for email validation
     * 
     * @return array Array of structured contacts:
     *   [['first_name', 'last_name', 'job_title', 'email', 'phone', 'linkedin_url'], ...]
     */
    public function extractContactsFromHtml(string $html, string $company, ?string $domain = null): array
    {
        if (!$this->isConfigured()) {
            $this->logger->warning('[GeminiExtractor] Not configured — skipping');
            return [];
        }

        if (empty(trim($html))) {
            return [];
        }

        // Clean and truncate HTML to fit token limits
        $cleanedHtml = $this->cleanHtml($html);
        if (strlen($cleanedHtml) > self::MAX_HTML_CHARS) {
            $cleanedHtml = substr($cleanedHtml, 0, self::MAX_HTML_CHARS);
        }

        if (strlen($cleanedHtml) < 50) {
            return []; // Too little content
        }

        $prompt = $this->buildExtractionPrompt($cleanedHtml, $company, $domain);

        try {
            $response = $this->callGemini($prompt);
            $contacts = $this->parseGeminiResponse($response);

            $this->logger->info('[GeminiExtractor] Extracted {n} contacts for {company}', [
                'n' => count($contacts),
                'company' => $company,
            ]);

            return $contacts;
        } catch (\Throwable $e) {
            $this->logger->warning('[GeminiExtractor] Extraction failed for {company}: {msg}', [
                'company' => $company,
                'msg' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Extract contacts from plain text (e.g., from Google Search snippets)
     */
    public function extractContactsFromText(string $text, string $company, ?string $domain = null): array
    {
        if (!$this->isConfigured() || strlen(trim($text)) < 20) {
            return [];
        }

        $prompt = <<<PROMPT
Extract all person contacts mentioned in this text. The company is "{$company}".

Text:
{$text}

Return ONLY a JSON array. Each element must have these fields:
- first_name (string, required)
- last_name (string, required)  
- job_title (string or null)
- email (string or null)
- phone (string or null)
- linkedin_url (string or null)

Rules:
- Only include REAL human names (not company names, product names, or department names)
- Only include people who work at or are associated with "{$company}"
- If no contacts found, return an empty array: []

JSON array:
PROMPT;

        try {
            $response = $this->callGemini($prompt);
            return $this->parseGeminiResponse($response);
        } catch (\Throwable $e) {
            $this->logger->debug('[GeminiExtractor] Text extraction failed: {msg}', ['msg' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Batch-enrich existing contacts with missing data using Gemini.
     * Replaces the broken OpenAI-based LLM enrichment.
     * 
     * @param array $candidates Array of contact arrays
     * @param string $company   Company name
     * @return array Enhanced candidates with filled-in fields
     */
    public function enrichCandidates(array $candidates, string $company): array
    {
        if (!$this->isConfigured() || empty($candidates)) {
            return $candidates;
        }

        // Build list of contacts that need enrichment
        $needsEnrichment = [];
        foreach ($candidates as $i => $c) {
            if (empty($c['job_title'])) {
                $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                if ($name) {
                    $needsEnrichment[$i] = $name;
                }
            }
        }

        if (empty($needsEnrichment)) {
            return $candidates;
        }

        $nameList = implode("\n", array_map(fn($n) => "- {$n}", $needsEnrichment));
        $prompt = <<<PROMPT
For the company "{$company}", I found these people but don't know their job titles.
Based on your knowledge, what are their most likely roles at this company?

People:
{$nameList}

Return ONLY a JSON array with objects containing:
- name (string, the person's full name exactly as given)
- job_title (string, their most likely role/title, or null if unknown)

Only include roles you're reasonably confident about. Don't guess wildly.
If you don't know someone's role, set job_title to null.

JSON array:
PROMPT;

        try {
            $response = $this->callGemini($prompt);
            $enriched = $this->parseGeminiResponse($response, 'name');

            // Build lookup
            $titleMap = [];
            foreach ($enriched as $e) {
                $key = mb_strtolower(trim($e['name'] ?? ''));
                if ($key && !empty($e['job_title'])) {
                    $titleMap[$key] = $e['job_title'];
                }
            }

            // Apply titles to candidates
            foreach ($needsEnrichment as $i => $name) {
                $key = mb_strtolower($name);
                if (isset($titleMap[$key])) {
                    $candidates[$i]['job_title'] = $titleMap[$key];
                    $candidates[$i]['_source'] = ($candidates[$i]['_source'] ?? '') . '+gemini';
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[GeminiExtractor] Enrichment failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $candidates;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────────────────────────

    private function buildExtractionPrompt(string $html, string $company, ?string $domain): string
    {
        $domainHint = $domain ? "\nThe company's email domain is @{$domain}." : '';

        return <<<PROMPT
Extract ALL person contacts from this HTML page content. The company is "{$company}".{$domainHint}

HTML content:
{$html}

Return ONLY a valid JSON array. Each element must have exactly these fields:
- first_name (string, required — the person's given/first name)
- last_name (string, required — the person's family/last name)
- job_title (string or null — their role/position at the company)
- email (string or null — their email address)
- phone (string or null — their phone number)
- linkedin_url (string or null — their LinkedIn profile URL)

CRITICAL RULES:
1. Only include REAL human person names — NOT company names, brand names, product names, or department names
2. Each person must have both first_name AND last_name (at least 2 name parts)
3. Only include people who appear to work at or be associated with "{$company}"
4. Extract job titles exactly as they appear (don't invent titles)
5. Extract emails/phones/LinkedIn only if explicitly present in the HTML
6. Handle non-English names correctly (Arabic, French, German, Dutch, etc.)
7. If NO contacts are found, return exactly: []
8. Do NOT wrap the JSON in markdown code blocks — return raw JSON only

JSON array:
PROMPT;
    }

    /**
     * Call Gemini Flash API
     */
    private function callGemini(string $prompt): string
    {
        $url = self::GEMINI_ENDPOINT . '?key=' . $this->geminiApiKey;

        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1, // Low temp for structured extraction
                    'maxOutputTokens' => 4096,
                    'topP' => 0.8,
                ],
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                ],
            ],
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        $data = $response->toArray();
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($text)) {
            throw new \RuntimeException('Empty Gemini response');
        }

        return $text;
    }

    /**
     * Parse Gemini's JSON response into structured contacts.
     * Handles markdown-wrapped JSON, extra whitespace, etc.
     */
    private function parseGeminiResponse(string $responseText, ?string $nameField = null): array
    {
        // Strip markdown code blocks if present
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $responseText);
        $cleaned = preg_replace('/\s*```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        // Find JSON array in the response
        $start = strpos($cleaned, '[');
        $end = strrpos($cleaned, ']');

        if ($start === false || $end === false || $end <= $start) {
            $this->logger->debug('[GeminiExtractor] No JSON array found in response', [
                'response_preview' => substr($responseText, 0, 200),
            ]);
            return [];
        }

        $json = substr($cleaned, $start, $end - $start + 1);
        $parsed = json_decode($json, true);

        if (!is_array($parsed)) {
            $this->logger->debug('[GeminiExtractor] Failed to parse JSON', [
                'json_preview' => substr($json, 0, 200),
                'json_error' => json_last_error_msg(),
            ]);
            return [];
        }

        // Validate and normalize each contact
        $contacts = [];
        foreach ($parsed as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // If parsing enrichment response (has 'name' field)
            if ($nameField && isset($entry[$nameField])) {
                $contacts[] = $entry;
                continue;
            }

            // Standard contact validation
            $firstName = trim($entry['first_name'] ?? '');
            $lastName = trim($entry['last_name'] ?? '');

            if (empty($firstName) || empty($lastName)) {
                continue;
            }

            // Reject obvious garbage
            if (strlen($firstName) > 50 || strlen($lastName) > 50) {
                continue;
            }
            if (preg_match('/\d{3,}/', $firstName . $lastName)) {
                continue; // Numbers in names
            }

            $contact = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'job_title' => !empty($entry['job_title']) ? trim($entry['job_title']) : null,
                'email' => null,
                'phone' => !empty($entry['phone']) ? trim($entry['phone']) : null,
                'linkedin_url' => null,
            ];

            // Validate email
            if (!empty($entry['email'])) {
                $email = strtolower(trim($entry['email']));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $contact['email'] = $email;
                }
            }

            // Validate LinkedIn URL
            if (!empty($entry['linkedin_url'])) {
                $liUrl = trim($entry['linkedin_url']);
                if (str_contains($liUrl, 'linkedin.com/in/')) {
                    $contact['linkedin_url'] = $liUrl;
                }
            }

            $contacts[] = $contact;
        }

        return $contacts;
    }

    /**
     * Clean HTML to reduce token count while preserving contact-relevant content.
     * Strips scripts, styles, SVGs, but keeps text and structural elements.
     */
    private function cleanHtml(string $html): string
    {
        // Remove script tags and their content
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html);
        // Remove style tags
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);
        // Remove SVG
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/si', '', $html);
        // Remove comments
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        // Remove noscript
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/si', '', $html);
        // Remove data attributes (noise)
        $html = preg_replace('/\s+data-[a-z0-9-]+="[^"]*"/i', '', $html);
        // Remove class attributes that are just CSS module hashes
        $html = preg_replace('/\s+class="[a-zA-Z0-9_-]{20,}"/i', '', $html);
        // Collapse whitespace
        $html = preg_replace('/\s+/', ' ', $html);
        // Remove empty tags
        $html = preg_replace('/<(div|span|p|section|article)\s*>\s*<\/\1>/i', '', $html);

        return trim($html);
    }
}
