<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Local LLM Service — talks to llama.cpp server running Qwen2.5-7B-Instruct.
 *
 * Provides structured classification for:
 *   - Company verification (manufacturer vs. trader/distributor)
 *   - Email intent classification
 *   - Sales conversation generation
 *
 * The llama.cpp server runs on 127.0.0.1:8081 as a systemd service.
 * Model: Qwen2.5-7B-Instruct-Q4_K_M (~4.7 GB, ~8 tok/s on 6-core CPU)
 *
 * Trained prompt achieves 100% accuracy on 225-entry golden dataset v2.
 */
class LlmService
{
    private const BASE_URL = 'http://127.0.0.1:8081';
    private const TIMEOUT = 60; // seconds — 14-thread + compressed prompt = ~15-20s per call
    private const MAX_RETRIES = 2;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    // COMPANY CLASSIFICATION — used by GoogleDorkService verification
    // ═══════════════════════════════════════════════════════════════

    private const COMPANY_SYSTEM_PROMPT = <<<'PROMPT'
You classify companies for a B2B sales team at Starz Electronics — a contract manufacturer (wire harness, EMS/PCB assembly, injection molding, CNC machining, automation assembly) based in Morocco.

We seek companies that MANUFACTURE physical products and could BUY our services.

CRITICAL RULE: Base your classification ONLY on what the snippet ACTUALLY SAYS. Never assume or infer facts not stated in the text. If the snippet does not explicitly confirm the company manufactures products, you MUST REJECT.

═══ ACCEPT (manufacturer — ONLY with evidence in snippet) ═══
Companies the snippet confirms DESIGN/PRODUCE physical products in own factories:
- Automotive OEMs & tier suppliers (Bosch, Valeo, Continental)
- Industrial sensor/automation makers (Schneider, Sick, Omron)
- Semiconductor fabs (NXP, Infineon, STMicro)
- Aerospace/defence electronics (Thales, Safran, Airbus)
- Medical devices, test instruments, connector OEMs
- Any company with CONFIRMED own factory producing tangible goods
Evidence needed: "manufactures", "our factory", "production facility", "designs and produces"

═══ REJECT (NOT our buyers) ═══
ALWAYS REJECT these — even if they mention manufacturing in their name:
- Trade associations / industry bodies (ACEA, CLEPA, FIEV, ZVEI, VDA) — they REPRESENT manufacturers but are NOT manufacturers
- Distributors, traders, wholesalers, retailers
- EMS competitors (Jabil, Flex, Celestica)
- News sites, blogs, YouTube channels, media, trade publications
- Market research firms, directories, portals, events/trade shows
- Government agencies, universities, certification bodies (TÜV, SGS)
- Auto dealerships, repair shops, car rental
- RC model / hobby / toy companies (NOT real automotive)
- Equipment suppliers to our industry (Komax wire machines)
- Food, chemicals, oil/gas, mining, construction, banking, telecom
- Websites ABOUT an industry (industry reports, trend analysis) — NOT manufacturers
- Any company where the snippet does NOT confirm they make products

═══ EXAMPLES ═══
Valeo → ACCEPT (Tier 1 automotive supplier, manufactures sensors, lighting)
Schneider Electric → ACCEPT (makes PLCs, switchgear in own factories)
NXP Semiconductors → ACCEPT (semiconductor fab producing chips)
ACEA → REJECT (European Automobile Manufacturers' ASSOCIATION — not a manufacturer)
FIEV → REJECT (French automotive suppliers federation — association, not manufacturer)
Jabil → REJECT (EMS competitor)
Arrow Electronics → REJECT (distributor)
HOBBYTECH → REJECT (RC model cars — toy/hobby, not real automotive)
Komax → REJECT (wire machine maker — our industry supplier)
Bureau Veritas → REJECT (certification body)
Motor1 → REJECT (automotive news website)
Danone → REJECT (food — wrong industry)

DEFAULT: When in doubt → REJECT. Only ACCEPT when the snippet gives CLEAR evidence of manufacturing.

Respond with ONLY a valid JSON object, no other text.
PROMPT;

    /**
     * Classify a company as a potential EMS buyer.
     *
     * @param string      $name     Company name
     * @param string      $domain   Domain name
     * @param string      $snippet  Search snippet or homepage excerpt (first ~500 chars)
     * @param string|null $location Target country (e.g. "Egypt")
     * @param string|null $sector   Target sector (e.g. "Automotive") — when set, reject companies outside this sector
     *
     * @return array{verdict: string, is_manufacturer: bool, has_local_presence: bool, reason: string, confidence: float}|null
     */
    public function classifyCompany(string $name, string $domain, string $snippet, ?string $location = null, ?string $sector = null): ?array
    {
        $locationClause = $location
            ? "\nLOCATION RULE: This search targets {$location}. REJECT if the company is NOT in {$location}. If the snippet mentions another country but NOT {$location}, REJECT."
            : '';

        $sectorClause = '';
        if ($sector !== null) {
            $sectorClause = $this->buildSectorClause($sector);
        }

        $userPrompt = <<<PROMPT
Name: {$name}
Domain: {$domain}
Snippet: {$snippet}

Classify based ONLY on what the snippet says:
1. Does the snippet confirm this company MANUFACTURES physical products in its own factory?
2. Is it a competitor (EMS, wire harness services, CNC machining services)? → REJECT
3. Is it a news site, blog, trade association, directory, trade show, government portal? → REJECT
4. If the snippet doesn't explicitly confirm manufacturing → REJECT
{$sectorClause}{$locationClause}
Respond: {"verdict": "ACCEPT" or "REJECT", "is_manufacturer": true/false, "has_local_presence": true/false, "reason": "one sentence citing snippet evidence", "confidence": 0.0-1.0}
PROMPT;

        $result = $this->chat(self::COMPANY_SYSTEM_PROMPT, $userPrompt, 0.1, 80);
        if ($result === null) {
            return null;
        }

        return $this->parseJsonResponse($result, ['verdict', 'is_manufacturer', 'reason', 'confidence']);
    }

    // ═══════════════════════════════════════════════════════════════
    // EMAIL CLASSIFICATION — used by EmailClassifierService
    // ═══════════════════════════════════════════════════════════════

    private const EMAIL_SYSTEM_PROMPT = <<<'PROMPT'
You classify incoming email replies for a B2B sales team. The team sends outreach emails to potential manufacturing customers. Classify each reply into exactly ONE category.

Categories:
- INTERESTED: Wants to learn more, asks questions, requests a meeting/call/quote, positive sentiment
- NOT_INTERESTED: Declines, says no thanks, wrong person, not relevant, negative sentiment
- UNSUBSCRIBE: Explicitly asks to be removed from mailing list, stop emails
- OUT_OF_OFFICE: Auto-reply, vacation, will be back on [date]
- BOUNCE: Delivery failure, mailbox full, address not found
- FORWARD: Forwarding to someone else, "I'm not the right person but try..."
- UNKNOWN: Cannot determine intent

Respond with ONLY a valid JSON object, no other text.
PROMPT;

    /**
     * Classify an email reply's intent.
     *
     * @return array{category: string, confidence: float, reason: string, next_action: string}|null
     */
    public function classifyEmail(string $subject, string $body, ?string $senderName = null): ?array
    {
        $bodyTruncated = mb_substr(trim($body), 0, 1500);
        $senderInfo = $senderName ? "From: {$senderName}\n" : '';

        $userPrompt = <<<PROMPT
{$senderInfo}Subject: {$subject}
Body:
{$bodyTruncated}

Classify this email reply. Respond: {"category": "INTERESTED|NOT_INTERESTED|UNSUBSCRIBE|OUT_OF_OFFICE|BOUNCE|FORWARD|UNKNOWN", "confidence": 0.0-1.0, "reason": "one sentence", "next_action": "suggested next step for the sales rep"}
PROMPT;

        $result = $this->chat(self::EMAIL_SYSTEM_PROMPT, $userPrompt, 0.1, 200);
        if ($result === null) {
            return null;
        }

        return $this->parseJsonResponse($result, ['category', 'confidence', 'reason']);
    }

    // ═══════════════════════════════════════════════════════════════
    // SALES CONVERSATION STARTERS — used by LeadSalesAnalystService
    // ═══════════════════════════════════════════════════════════════

    private const SALES_SYSTEM_PROMPT = <<<'PROMPT'
You help B2B sales reps at Starz Electronics, an EMS (Electronics Manufacturing Services) company in Morocco. Starz provides PCB assembly, cable harness manufacturing, electronic box-build, and testing services.

Generate brief, personalized conversation starters and pain-point predictions based on the company profile. Be specific and actionable. Write in a professional but warm tone.

Respond with ONLY a valid JSON object.
PROMPT;

    /**
     * Generate conversation starters and pain-point predictions for a lead.
     *
     * @return array{conversation_starters: string[], pain_points: string[], value_proposition: string}|null
     */
    public function generateSalesInsights(string $companyName, string $sector, string $location, array $capabilities = [], array $certifications = []): ?array
    {
        $capsStr = !empty($capabilities) ? 'Known capabilities: ' . implode(', ', $capabilities) : '';
        $certsStr = !empty($certifications) ? 'Certifications: ' . implode(', ', $certifications) : '';

        $userPrompt = <<<PROMPT
Company: {$companyName}
Sector: {$sector}
Location: {$location}
{$capsStr}
{$certsStr}

Generate: {"conversation_starters": ["3 specific openers for cold outreach"], "pain_points": ["3 likely procurement pain points for this type of company"], "value_proposition": "One paragraph on why Starz EMS is a good fit for this company"}
PROMPT;

        $result = $this->chat(self::SALES_SYSTEM_PROMPT, $userPrompt, 0.7, 400);
        if ($result === null) {
            return null;
        }

        return $this->parseJsonResponse($result, ['conversation_starters', 'pain_points', 'value_proposition']);
    }

    // ═══════════════════════════════════════════════════════════════
    // CONTACT EXTRACTION — extract person contacts from page text
    // ═══════════════════════════════════════════════════════════════

    private const CONTACT_EXTRACTION_PROMPT = <<<'PROMPT'
You extract person contacts from company web page text for a B2B sales team.
Find ALL named persons mentioned — especially leadership, executives, founders, managers, and key contacts.
Handle names in English, French, Arabic, German, and Dutch.

Rules:
- Only extract REAL human person names (not company names, brand names, product names, or department names)
- Each person MUST have both first_name AND last_name (at least 2 name parts)
- Extract job titles, emails, phones, LinkedIn URLs ONLY when explicitly present in the text
- Handle non-English names correctly (e.g. Arabic names, French accented names)
- If no contacts found, return exactly: []

Respond with ONLY a valid JSON array, no other text:
[{"first_name":"...","last_name":"...","job_title":"...or null","email":"...or null","phone":"...or null","linkedin_url":"...or null"}]
PROMPT;

    /**
     * Extract person contacts from cleaned page text using the local LLM.
     *
     * This complements regex-based extraction by catching contacts in
     * non-standard layouts, multilingual content, and complex DOM structures
     * that regex patterns miss.
     *
     * @param string      $text        Cleaned page text (strip_tags'd, max ~2000 chars)
     * @param string      $companyName Company name for context
     * @param string|null $domain      Company domain for email context
     *
     * @return array<array{first_name: string, last_name: string, job_title: ?string, email: ?string, phone: ?string, linkedin_url: ?string, source: string}>
     */
    public function extractContactsFromText(string $text, string $companyName, ?string $domain = null): array
    {
        $textTruncated = mb_substr(trim($text), 0, 2000);
        if (mb_strlen($textTruncated) < 50) {
            return [];
        }

        $domainHint = $domain ? " (domain: {$domain})" : '';

        $userPrompt = <<<PROMPT
Company: {$companyName}{$domainHint}

Page text:
{$textTruncated}

Extract all person contacts from this text. JSON array:
PROMPT;

        $result = $this->chat(self::CONTACT_EXTRACTION_PROMPT, $userPrompt, 0.1, 500);
        if ($result === null) {
            return [];
        }

        return $this->parseContactArrayResponse($result);
    }

    /**
     * Parse a JSON array response containing contact entries.
     *
     * @return array<array{first_name: string, last_name: string, job_title: ?string, email: ?string, phone: ?string, linkedin_url: ?string, source: string}>
     */
    private function parseContactArrayResponse(string $content): array
    {
        // Strip markdown code blocks
        $content = trim($content);
        if (str_starts_with($content, '```json')) {
            $content = substr($content, 7);
        } elseif (str_starts_with($content, '```')) {
            $content = substr($content, 3);
        }
        if (str_ends_with($content, '```')) {
            $content = substr($content, 0, -3);
        }
        $content = trim($content);

        // Find JSON array boundaries
        $start = strpos($content, '[');
        $end = strrpos($content, ']');
        if ($start === false || $end === false || $end <= $start) {
            $this->logger->debug('LLM contact extraction: no JSON array in response', [
                'preview' => mb_substr($content, 0, 200),
            ]);
            return [];
        }

        $json = substr($content, $start, $end - $start + 1);
        $parsed = json_decode($json, true);
        if (!is_array($parsed)) {
            $this->logger->debug('LLM contact extraction: JSON parse failed', [
                'error' => json_last_error_msg(),
            ]);
            return [];
        }

        // Validate and normalize each contact
        $contacts = [];
        foreach ($parsed as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $firstName = trim($entry['first_name'] ?? '');
            $lastName = trim($entry['last_name'] ?? '');

            if (empty($firstName) || empty($lastName)) {
                continue;
            }

            // Reject garbage names
            if (mb_strlen($firstName) > 50 || mb_strlen($lastName) > 50) {
                continue;
            }
            if (preg_match('/\d{3,}/', $firstName . $lastName)) {
                continue;
            }

            $contact = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'job_title' => !empty($entry['job_title']) ? trim((string) $entry['job_title']) : null,
                'email' => null,
                'phone' => !empty($entry['phone']) ? trim((string) $entry['phone']) : null,
                'linkedin_url' => null,
                'source' => 'llm_extraction',
            ];

            // Validate email
            if (!empty($entry['email'])) {
                $email = strtolower(trim((string) $entry['email']));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $contact['email'] = $email;
                }
            }

            // Validate LinkedIn URL
            if (!empty($entry['linkedin_url'])) {
                $liUrl = trim((string) $entry['linkedin_url']);
                if (str_contains($liUrl, 'linkedin.com/in/')) {
                    $contact['linkedin_url'] = $liUrl;
                }
            }

            $contacts[] = $contact;
        }

        if (!empty($contacts)) {
            $this->logger->info('LLM extracted {n} contacts', ['n' => count($contacts)]);
        }

        return $contacts;
    }

    // ═══════════════════════════════════════════════════════════════
    // HOMEPAGE ANALYSIS — deep classification from homepage HTML text
    // ═══════════════════════════════════════════════════════════════

    /**
     * Analyze stripped homepage text to determine if company is a manufacturer.
     * Uses the same trained classification knowledge as classifyCompany() but
     * with homepage text as input for richer context.
     *
     * Called from GoogleDorkService::llmSecondOpinion() during verification
     * phase when homepage text is available.
     *
     * @param string      $homepageText Strip-tags'd homepage text (first ~1500 chars)
     * @param string      $name         Company name
     * @param string      $domain       Domain
     * @param string|null $location     Target country
     * @param string|null $sector       Target sector (e.g. "Automotive") — when set, reject companies outside this sector
     *
     * @return array{verdict: string, is_manufacturer: bool, has_local_presence: bool, reason: string, confidence: float}|null
     */
    public function analyzeHomepage(string $homepageText, string $name, string $domain, ?string $location = null, ?string $sector = null): ?array
    {
        $textTruncated = mb_substr(trim($homepageText), 0, 1500);
        $locationClause = $location
            ? "\nLOCATION RULE: This search targets {$location}. REJECT if the company is NOT in {$location}. If the text mentions another country but NOT {$location}, REJECT."
            : '';

        $sectorClause = '';
        if ($sector !== null) {
            $sectorClause = $this->buildSectorClause($sector);
        }

        $userPrompt = <<<PROMPT
Name: {$name}
Domain: {$domain}
Homepage text (truncated):
{$textTruncated}

Classify based ONLY on what the homepage text says:
1. Does the text confirm this company MANUFACTURES physical products in its own factory?
2. Is it a competitor (EMS, wire harness services, CNC machining services)? → REJECT
3. Is it a news site, blog, trade association, directory, trade show, government portal? → REJECT
4. If the text doesn't explicitly confirm manufacturing → REJECT
{$sectorClause}{$locationClause}
Respond: {"verdict": "ACCEPT" or "REJECT", "is_manufacturer": true/false, "has_local_presence": true/false, "reason": "one sentence citing text evidence", "confidence": 0.0-1.0}
PROMPT;

        // Reuse the same trained COMPANY_SYSTEM_PROMPT — same classification logic,
        // just with homepage text instead of a search snippet as input.
        $result = $this->chat(self::COMPANY_SYSTEM_PROMPT, $userPrompt, 0.1, 80);
        if ($result === null) {
            return null;
        }

        return $this->parseJsonResponse($result, ['verdict', 'is_manufacturer', 'reason', 'confidence']);
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTOR FILTERING — dynamic sector-awareness for classification
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build a sector-awareness clause for the user prompt.
     * When the discovery is targeting a specific sector (e.g. Automotive),
     * this instructs the LLM to REJECT companies outside that sector.
     */
    private function buildSectorClause(?string $sector): string
    {
        if ($sector === null || $sector === '') {
            return '';
        }

        $sectorMap = [
            'Automotive' => "SECTOR FILTER: AUTOMOTIVE.\n"
                . "ACCEPT only companies that MANUFACTURE automotive parts/components/vehicles in their OWN FACTORIES (e.g. brake systems, wiring harnesses, seats, sensors, lighting, plastics, exhaust, transmission).\n"
                . "REJECT: distributors/wholesalers, auto parts shops/retailers, car dealerships/rental, repair/garage chains, "
                . "logo/brand websites, investment firms, trade associations, news/media, non-automotive manufacturers.\n"
                . "KEY: If the snippet doesn't confirm the company MANUFACTURES automotive products, REJECT. Name alone is not enough.",
            'Aerospace' => "SECTOR FILTER: AEROSPACE.\n"
                . "ACCEPT only companies that manufacture aerospace/defence parts, aircraft components, avionics, satellites, or Tier 1/2/3 aerospace supply.\n"
                . "REJECT non-aerospace manufacturers.",
            'Medical' => "SECTOR FILTER: MEDICAL DEVICES.\n"
                . "ACCEPT only companies that manufacture medical devices, diagnostic equipment, surgical instruments, implants, or hospital equipment.\n"
                . "REJECT non-medical manufacturers.",
            'Industrial' => "SECTOR FILTER: INDUSTRIAL/AUTOMATION.\n"
                . "ACCEPT companies that manufacture industrial automation equipment, sensors, control systems, motors, or factory equipment.\n"
                . "REJECT unrelated industries.",
        ];

        return "\n" . ($sectorMap[$sector] ?? "We are searching for companies in the {$sector} sector. ACCEPT only companies relevant to this sector.");
    }

    // ═══════════════════════════════════════════════════════════════
    // INFRASTRUCTURE — HTTP calls to llama.cpp server
    // ═══════════════════════════════════════════════════════════════

    /**
     * Check if the LLM server is available.
     */
    public function isAvailable(): bool
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . '/health', [
                'timeout' => 3,
            ]);
            $data = $response->toArray(false);
            return ($data['status'] ?? '') === 'ok';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Send a chat completion request to the local llama.cpp server.
     */
    private function chat(string $systemPrompt, string $userPrompt, float $temperature = 0.1, int $maxTokens = 150): ?string
    {
        $startTime = microtime(true);

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->httpClient->request('POST', self::BASE_URL . '/v1/chat/completions', [
                    'timeout' => self::TIMEOUT,
                    'json' => [
                        'model' => 'qwen2.5-7b',
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $userPrompt],
                        ],
                        'temperature' => $temperature,
                        'max_tokens' => $maxTokens,
                        'stream' => false,
                    ],
                ]);

                $data = $response->toArray(false);
                $content = $data['choices'][0]['message']['content'] ?? null;

                $elapsed = round((microtime(true) - $startTime) * 1000);
                $tokens = $data['usage']['total_tokens'] ?? 0;

                $this->logger->debug('LLM response', [
                    'elapsed_ms' => $elapsed,
                    'tokens' => $tokens,
                    'prompt_len' => mb_strlen($userPrompt),
                ]);

                return $content;
            } catch (\Throwable $e) {
                $this->logger->warning('LLM request failed', [
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage(),
                ]);
                if ($attempt < self::MAX_RETRIES) {
                    usleep(500000); // 500ms before retry
                }
            }
        }

        return null;
    }

    /**
     * Parse a JSON response from the LLM, with validation.
     */
    private function parseJsonResponse(string $content, array $requiredKeys): ?array
    {
        // The LLM sometimes wraps JSON in markdown code blocks
        $content = trim($content);
        if (str_starts_with($content, '```json')) {
            $content = substr($content, 7);
        } elseif (str_starts_with($content, '```')) {
            $content = substr($content, 3);
        }
        if (str_ends_with($content, '```')) {
            $content = substr($content, 0, -3);
        }
        $content = trim($content);

        // Try to extract JSON from potential surrounding text
        if (!str_starts_with($content, '{')) {
            if (preg_match('/\{[^}]+\}/s', $content, $m)) {
                $content = $m[0];
            }
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            $this->logger->warning('LLM returned non-JSON response', [
                'content' => mb_substr($content, 0, 200),
            ]);
            return null;
        }

        // Validate required keys
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                $this->logger->warning('LLM response missing required key', [
                    'missing_key' => $key,
                    'keys' => array_keys($data),
                ]);
                return null;
            }
        }

        return $data;
    }
}
