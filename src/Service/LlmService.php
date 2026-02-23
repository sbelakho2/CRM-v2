<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Local LLM Service — talks to llama.cpp server running Qwen2.5-3B-Instruct.
 *
 * Provides structured classification for:
 *   - Company verification (manufacturer vs. trader/distributor)
 *   - Email intent classification
 *   - Sales conversation generation
 *
 * The llama.cpp server runs on 127.0.0.1:8081 as a systemd service.
 * Model: Qwen2.5-3B-Instruct-Q4_K_M (~2 GB, ~11 tok/s on 6-core CPU)
 */
class LlmService
{
    private const BASE_URL = 'http://127.0.0.1:8081';
    private const TIMEOUT = 90; // seconds — generous for CPU inference (homepage text is large)
    private const MAX_RETRIES = 2;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    // COMPANY CLASSIFICATION — used by GoogleDorkService verification
    // ═══════════════════════════════════════════════════════════════

    private const COMPANY_SYSTEM_PROMPT = <<<'PROMPT'
You classify companies for a B2B sales team at Starz Electronics — a contract manufacturer based in Morocco providing:
  - Wire harness / cable assembly
  - EMS (Electronics Manufacturing Services) / PCB assembly
  - Injection molding
  - CNC machining / precision machining / tooling
  - Industrial automation assembly

We seek companies that MANUFACTURE physical products and could BUY these services from us (= our PROSPECTS). Content may be in English, French, Arabic, or German — you MUST handle all four languages equally.

ACCEPT ONLY (prospects — they buy our services):
- Companies that MAKE/PRODUCE/ASSEMBLE physical products in their OWN FACTORY
- Automotive OEMs, tier-2/3 manufacturers, appliance makers, industrial equipment makers
- Multinational manufacturers with a confirmed factory in the target country
- Companies producing electronics, vehicles, machinery, medical devices, aerospace parts

REJECT ALL of these (be VERY strict — when in doubt, REJECT):
1. TRADERS/DISTRIBUTORS: Trading companies, importers, distributors, resellers, general trading, wholesalers, agents (name contains "Trading", "Import", "Export", "Distribution", "Négoce", "تجارة", "Handel", "Grossiste")
2. COMPETITORS: Companies that PROVIDE wire harness assembly, EMS, CNC machining, injection molding, automation assembly, contract manufacturing, sous-traitance, câblage, usinage, fabrication de faisceaux — these SELL the same services as Starz, REJECT them
3. NEWS/MEDIA: News sites, newspapers, magazines, online portals, TV/radio, press agencies (أخبار/إعلام, actualités/presse, Nachrichten/Zeitung)
4. GOVERNMENT: Government bodies, ministries, national agencies, investment promotion agencies, regulatory bodies, national offices, state-owned enterprises NOT manufacturing products (e.g. "Office National", "Agence Nationale", "Groupe Chimique Tunisien", وزارة, هيئة, مكتب وطني, "Invest in [country]")
5. ASSOCIATIONS/NGOs: Trade associations, chambers, federations, professional bodies, IEEE chapters, student organizations (chambres syndicales, fédérations, غرف تجارية, IEEE)
6. JOB PORTALS: Job boards, recruitment sites, career portals, classifieds (emploi, recrutement, offres d'emploi, وظائف)
7. DIRECTORIES/PORTALS: Business directories, listing sites, yellow pages, comparison sites
8. EVENTS: Conference/expo/exhibition/salon/foire/معرض organizers
9. SERVICES-ONLY: AI/SaaS/software companies, consulting firms, logistics/shipping/freight/forwarding, law firms, marketing agencies
10. DEALERSHIPS: Car dealerships, spare parts shops, auto repair, concessionnaires, وكلاء سيارات
11. WRONG INDUSTRY: Food/beverage, cosmetics/beauty, real estate, banking/insurance, agriculture (unless they manufacture agricultural machinery), mining/extraction (chemicals, phosphates, oil/gas), solar panel installation (not manufacturing)
12. SUPPLIERS: Companies that supply raw materials, components, or commodities but don't manufacture end products — chemical producers, steel traders, packaging suppliers, logistics providers

CRITICAL RULES:
- Government-owned chemical/mining companies (e.g. GCT, OCP) are NOT manufacturers we can sell to — REJECT
- Investment promotion agencies (e.g. "Invest in Tunisia", "FIPA") are NOT companies — REJECT
- A .gov or .nat domain is almost always government — REJECT
- Student organizations (IEEE chapters, university clubs) are NOT companies — REJECT
- "Groupe" in name does NOT mean manufacturer — check what they actually do

LOCATION: Having local presence means an office or FACTORY in the target country — not just selling to it.

Respond with ONLY a valid JSON object, no other text before or after.
PROMPT;

    /**
     * Classify a company as a potential EMS buyer.
     *
     * @param string      $name     Company name
     * @param string      $domain   Domain name
     * @param string      $snippet  Search snippet or homepage excerpt (first ~500 chars)
     * @param string|null $location Target country (e.g. "Egypt")
     *
     * @return array{verdict: string, is_manufacturer: bool, has_local_presence: bool, reason: string, confidence: float}|null
     */
    public function classifyCompany(string $name, string $domain, string $snippet, ?string $location = null): ?array
    {
        $locationClause = $location
            ? "Does this company have confirmed manufacturing/operational presence in {$location} (factory, plant, office there — not just exports to {$location})?"
            : '';

        $userPrompt = <<<PROMPT
Name: {$name}
Domain: {$domain}
Snippet: {$snippet}

Classify this company. Consider:
1. Does it MAKE physical products of ANY kind in its own factory? (cars, electronics, appliances, toys, machinery, medical devices, food products, packaging, textiles — ANY physical goods = is_manufacturer: true)
2. Is it a COMPETITOR (provides wire harness, EMS, CNC machining, injection molding, or automation assembly services)? Competitors are still manufacturers, but verdict = REJECT.
3. Is it a news site, trade show, government portal, association, or trader/distributor?
{$locationClause}
IMPORTANT: is_manufacturer means "does this company produce/assemble ANY physical products in a factory" — even toy makers, food processors, textile mills, etc. are manufacturers. Set true if they make things.
Respond: {"verdict": "ACCEPT" or "REJECT", "is_manufacturer": true/false, "has_local_presence": true/false, "reason": "one sentence", "confidence": 0.0-1.0}
PROMPT;

        $result = $this->chat(self::COMPANY_SYSTEM_PROMPT, $userPrompt, 0.1, 150);
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
    // HOMEPAGE ANALYSIS — deep classification from homepage HTML text
    // ═══════════════════════════════════════════════════════════════

    /**
     * Analyze stripped homepage text to determine if company is a manufacturer
     * with presence in the target location. Used as a second opinion after
     * the regex-based homepage classifier.
     *
     * @param string      $homepageText Strip-tags'd homepage text (first ~1500 chars)
     * @param string      $name         Company name
     * @param string      $domain       Domain
     * @param string|null $location     Target country
     *
     * @return array{verdict: string, is_manufacturer: bool, has_local_presence: bool, business_type: string, reason: string, confidence: float}|null
     */
    private const HOMEPAGE_SYSTEM_PROMPT = <<<'PROMPT'
You analyze company homepages for a B2B sales team at Starz Electronics — a contract manufacturer in Morocco providing wire harness assembly, EMS/PCB assembly, injection molding, CNC machining, and industrial automation assembly.

You will receive homepage text that may be in English, French, Arabic, or German. You MUST handle all four languages to correctly classify the company.

CLASSIFY the company into one of these business_type values:
- "manufacturer" — Makes physical products in own factory (automotive parts, electronics, appliances, machinery, medical devices, aerospace). These are our PROSPECTS. ACCEPT.
- "competitor" — Provides same services as Starz (wire harness, EMS, CNC machining, injection molding, automation assembly, contract manufacturing, sous-traitance, câblage, usinage, فابريكا). REJECT.
- "distributor" — Distributes/resells products, trading company. REJECT.
- "trader" — Import/export, general trading. REJECT.
- "media" — News site, newspaper, magazine, TV, radio, online portal, actualités, أخبار, Nachrichten. REJECT.
- "event" — Trade show, salon, exhibition, foire, معرض, Messe. REJECT.
- "association" — Trade association, chamber, fédération, غرفة تجارية, Verband, IEEE chapter, student org. REJECT.
- "government" — Government body, ministry, official portal, national agency, investment promotion, state enterprise, وزارة, حكومة, agence nationale, office national. REJECT.
- "software" — SaaS, IT services, consulting. REJECT.
- "services" — Non-manufacturing services (logistics, cleaning, consulting, marketing, law). REJECT.
- "dealership" — Auto dealer, spare parts shop, concessionnaire, وكيل سيارات. REJECT.
- "job_portal" — Job board, recruitment site, career portal, emploi, recrutement. REJECT.
- "directory" — Business directory, listing site, yellow pages. REJECT.
- "education" — University, research institute, student organization, école. REJECT.
- "chemical_mining" — Chemical processing, phosphate extraction, mining, oil/gas — NOT a buyer of EMS services. REJECT.
- "supplier" — Raw material/commodity supplier (steel, packaging, chemicals) — not a manufacturer of end products. REJECT.
- "other" — Anything else not fitting above. REJECT.

Only ACCEPT companies classified as "manufacturer".
CRITICAL: When in doubt, REJECT. Better to miss a prospect than to accept junk.
Respond with ONLY a valid JSON object.
PROMPT;

    public function analyzeHomepage(string $homepageText, string $name, string $domain, ?string $location = null): ?array
    {
        $textTruncated = mb_substr(trim($homepageText), 0, 1500);
        $locationClause = $location
            ? "Does the homepage content confirm manufacturing/operational presence in {$location}?"
            : '';

        $userPrompt = <<<PROMPT
Company: {$name}
Domain: {$domain}
Homepage text (truncated):
{$textTruncated}

Analyze this homepage. The text may be in English, French, Arabic, or German.
{$locationClause}
IMPORTANT: is_manufacturer = true means the company MAKES/PRODUCES/ASSEMBLES ANY physical products in a factory (toys, electronics, cars, food, machinery, textiles, plastics — anything). Even if they are NOT in our target industry, set is_manufacturer=true if they produce physical goods.
Respond: {"verdict": "ACCEPT" or "REJECT", "is_manufacturer": true/false, "has_local_presence": true/false, "business_type": "manufacturer|competitor|distributor|trader|media|event|association|government|software|services|dealership|other", "reason": "one sentence", "confidence": 0.0-1.0}
PROMPT;

        $result = $this->chat(self::HOMEPAGE_SYSTEM_PROMPT, $userPrompt, 0.1, 150);
        if ($result === null) {
            return null;
        }

        return $this->parseJsonResponse($result, ['verdict', 'is_manufacturer', 'reason', 'confidence']);
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
                        'model' => 'qwen2.5-3b',
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
