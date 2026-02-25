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

We seek companies that MANUFACTURE physical products and could BUY our services. Content may be in English, French, Arabic, or German.

═══ ACCEPT (manufacturer) ═══
Companies that DESIGN/PRODUCE physical products in their own factories:
- Automotive OEMs & tier suppliers (Bosch, Valeo, Continental)
- Industrial sensor/automation makers (Schneider, Sick, Omron, Pepperl+Fuchs)
- Semiconductor fabs (NXP, Infineon, STMicro, TI)
- Test instruments (Rohde & Schwarz, Keysight)
- Aerospace/defence electronics (Thales, Safran, Airbus)
- Medical devices (Dräger, B.Braun)
- Connector OEMs (TE, Amphenol, Harting) — "cable assembly" = their PRODUCT → ACCEPT
- Any company with own factory producing tangible goods
Keywords: "designs and manufactures", "our factory/plant", "produces", "production facility" → ACCEPT

═══ REJECT (26 categories — NOT our buyers) ═══
SERVICES: distributors/traders, EMS competitors (Jabil, Flex, Celestica), system integrators, IT/management consulting, logistics/freight, recruitment/staffing, MRO/overhaul, facilities management
MEDIA/INFO: news sites, trade publications, market research firms, directories/portals, events/trade shows
ORGANIZATIONS: government agencies, trade associations/NGOs, certification bodies (TÜV, SGS), universities
WRONG INDUSTRY: food/beverage, chemicals/fertilizers, oil/gas/mining/cement, construction/real estate, banking/insurance, telecom operators, airlines/tourism, recycling/waste
OTHER: dealerships/auto repair shops, equipment suppliers to our industry (Komax), law firms, pure SaaS, e-commerce/marketplaces

═══ RULES ═══
- Semiconductor/sensor/instrument company = manufacturer → ACCEPT
- "Contract manufacturing" or "manufacturing services" = COMPETITOR → REJECT
- Connector OEM mentioning cable assembly → it's their PRODUCT → ACCEPT
- Wire processing machine makers (Komax) → supply our industry but NOT buyers → REJECT
- Food/chemical/mining "manufacturers" → wrong industry, never buy EMS → REJECT
- Associations REPRESENT manufacturers but aren't manufacturers → REJECT

═══ EXAMPLES ═══
Schneider Electric → ACCEPT (makes PLCs, switchgear in own factories)
NXP Semiconductors → ACCEPT (semiconductor fab producing chips)
Jabil → REJECT (EMS competitor — same services we sell)
Arrow Electronics → REJECT (distributor, doesn't manufacture)
Komax → REJECT (wire machine maker, our industry supplier, not buyer)
Bureau Veritas → REJECT (certification body)
ZVEI → REJECT (trade association)
Danone → REJECT (food manufacturer — wrong industry)

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
            ? "\nDoes this company have confirmed manufacturing/operational presence in {$location}?"
            : '';

        $sectorClause = '';
        if ($sector !== null) {
            $sectorClause = $this->buildSectorClause($sector);
        }

        $userPrompt = <<<PROMPT
Name: {$name}
Domain: {$domain}
Snippet: {$snippet}

Classify this company. Consider:
1. Does it MAKE physical products of ANY kind in its own factory?
2. Is it a COMPETITOR (provides wire harness, EMS, CNC machining, injection molding, or automation assembly services)? Competitors = REJECT.
3. Is it a news site, trade show, government portal, association, trader/distributor, system integrator, research centre?
{$sectorClause}{$locationClause}
Respond: {"verdict": "ACCEPT" or "REJECT", "is_manufacturer": true/false, "has_local_presence": true/false, "reason": "one sentence", "confidence": 0.0-1.0}
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
            ? "\nDoes this company have confirmed manufacturing/operational presence in {$location}?"
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

Classify this company based on its homepage content. Consider:
1. Does it MAKE physical products of ANY kind in its own factory?
2. Is it a COMPETITOR (provides wire harness, EMS, CNC machining, injection molding, or automation assembly services)? Competitors = REJECT.
3. Is it a news site, trade show, government portal, association, trader/distributor, system integrator, research centre?
{$sectorClause}{$locationClause}
Respond: {"verdict": "ACCEPT" or "REJECT", "is_manufacturer": true/false, "has_local_presence": true/false, "reason": "one sentence", "confidence": 0.0-1.0}
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
            'Automotive' => "We are searching specifically for companies in the AUTOMOTIVE sector.\n"
                . "ACCEPT only companies that MANUFACTURE automotive parts, components, vehicles, or assemblies in their OWN FACTORIES.\n"
                . "This means they must DESIGN, ENGINEER, or PRODUCE physical automotive products — not just sell/distribute/resell them.\n"
                . "\n"
                . "ACCEPT examples (they manufacture in own factories): Valeo (lighting, wipers, sensors), Faurecia/Forvia (seats, exhaust, interiors), "
                . "Plastic Omnium (bumpers, fuel systems), Michelin (tyres), Continental (brake systems, electronics), "
                . "Hella (automotive lighting, electronics), any company with phrases like 'our plant', 'we produce', 'we manufacture', "
                . "'our production line', 'our R&D and manufacturing'.\n"
                . "\n"
                . "REJECT these categories — they are NOT manufacturers even if they deal in automotive parts:\n"
                . "- DISTRIBUTORS / WHOLESALERS: Companies that buy parts from manufacturers and resell them (Alliance Automotive Group, Autodistribution, LKQ, Mobivia)\n"
                . "- AUTO PARTS SHOPS / RETAILERS: Online or physical stores selling spare parts to consumers (Oscaro, Car Parts France, Auto Parts Online, Pièces Auto 24)\n"
                . "- CAR DEALERSHIPS / RENTAL: Companies that sell or rent cars (not parts manufacturers)\n"
                . "- REPAIR / GARAGE / SERVICE: Car repair shops, MOT centers, garage chains (Midas, Norauto, Feu Vert)\n"
                . "- LOGO / BRAND / DESIGN SITES: e.g. 1000logos.net — websites about brand logos, NOT manufacturers\n"
                . "- INVESTMENT / FINANCIAL: Investment firms or funds that invest in automotive companies (Nordfranceinvest, BpiFrance)\n"
                . "- TRADE ASSOCIATIONS / DIRECTORIES: CCFA, PFA, FIEV — they represent the industry but don't manufacture\n"
                . "- NEWS / MEDIA / BLOGS: Automotive news sites, review sites, information portals\n"
                . "\n"
                . "Products that qualify as automotive manufacturing: brake systems, wiring harnesses, car seats, dashboard components, "
                . "engine parts, transmission components, automotive sensors, automotive lighting, automotive plastics/composites, "
                . "exhaust systems, fuel systems, steering components, suspension, body panels, powertrain, electronics/ECUs, tyres.\n"
                . "\n"
                . "REJECT companies that manufacture products for OTHER industries (solar inverters, home appliances, building materials, agricultural equipment) EVEN IF they are manufacturers.\n"
                . "A solar panel/inverter company is NOT automotive. A wind turbine company is NOT automotive. A home electronics company is NOT automotive.\n"
                . "\n"
                . "KEY RULE: If you cannot confirm from the snippet/text that the company MANUFACTURES automotive products in its own factory, REJECT it. "
                . "When in doubt, REJECT. The company name alone is not enough — look for evidence of manufacturing.",
            'Aerospace' => "We are searching specifically for companies in the AEROSPACE sector.\n"
                . "ACCEPT only companies that manufacture aerospace/defence parts, aircraft components, avionics, satellites, or provide Tier 1/2/3 aerospace supply.\n"
                . "REJECT companies that manufacture products for OTHER industries EVEN IF they are manufacturers.",
            'Medical' => "We are searching specifically for companies in the MEDICAL DEVICE sector.\n"
                . "ACCEPT only companies that manufacture medical devices, diagnostic equipment, surgical instruments, implants, or hospital equipment.\n"
                . "REJECT companies that manufacture products for OTHER industries EVEN IF they are manufacturers.",
            'Industrial' => "We are searching specifically for companies in the INDUSTRIAL/AUTOMATION sector.\n"
                . "ACCEPT companies that manufacture industrial automation equipment, sensors, control systems, motors, or factory equipment.\n"
                . "REJECT companies that manufacture products for unrelated industries.",
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
