<?php

namespace App\Service\WebCrawler;

use Psr\Log\LoggerInterface;

/**
 * LOCAL company classifier — uses a Gemini-generated knowledge base
 * but makes ZERO API calls at runtime. All classification is pure PHP.
 *
 * Architecture:
 * 1. Knowledge base (external_data/classifier_knowledge.json) was generated
 *    once by Gemini Flash — it contains 500+ labeled patterns across 9 categories.
 * 2. This service loads the knowledge base at construction time (cached in memory).
 * 3. classifyCompany() scores a candidate using feature extraction + knowledge
 *    base matching. Pure string matching, no network calls.
 *
 * The knowledge base makes this classifier MUCH smarter than hand-coded regexes
 * because Gemini's understanding of business types informed the patterns.
 * But Gemini is NOT called during discovery — it was only used to TRAIN the local model.
 */
class CompanyClassifierService
{
    private array $kb = [];  // Knowledge base loaded from JSON
    private bool $loaded = false;

    public function __construct(
        private LoggerInterface $logger,
        private string $projectDir,
    ) {
        $this->loadKnowledgeBase();
    }

    /**
     * Load the Gemini-generated knowledge base from disk (one-time).
     */
    private function loadKnowledgeBase(): void
    {
        $path = $this->projectDir . '/external_data/classifier_knowledge.json';
        if (!file_exists($path)) {
            $this->logger->warning('CompanyClassifier: knowledge base not found at ' . $path);
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('CompanyClassifier: invalid JSON in knowledge base');
            return;
        }

        $this->kb = $data;
        $this->loaded = true;

        // Pre-compile lowercase versions for fast matching
        $this->kb['_buyer_kw_lower'] = array_map('strtolower', $this->kb['buyer_keywords'] ?? []);
        $this->kb['_reject_kw_lower'] = array_map('strtolower', $this->kb['reject_keywords'] ?? []);
        $this->kb['_giant_names_lower'] = array_map('strtolower', $this->kb['giant_oem_names'] ?? []);
        $this->kb['_distributor_lower'] = array_map('strtolower', $this->kb['distributor_indicators'] ?? []);

        $this->logger->debug('CompanyClassifier: knowledge base loaded', [
            'buyer_keywords' => count($this->kb['buyer_keywords'] ?? []),
            'reject_keywords' => count($this->kb['reject_keywords'] ?? []),
        ]);
    }

    /**
     * Classify a company candidate using the local knowledge base.
     *
     * Returns an associative array:
     *   'verdict'  => 'BUYER' | 'REJECT' | 'UNCERTAIN'
     *   'score'    => int (positive=buyer, negative=reject)
     *   'reasons'  => array of strings explaining the score
     *
     * This method makes ZERO network calls. All matching is local.
     */
    public function classifyCompany(string $name, string $snippet = '', string $title = '', string $domain = ''): array
    {
        if (!$this->loaded) {
            return ['verdict' => 'UNCERTAIN', 'score' => 0, 'reasons' => ['Knowledge base not loaded']];
        }

        $score = 0;
        $reasons = [];

        $text = strtolower(trim($snippet . ' ' . $title . ' ' . $name));
        $nameLower = strtolower(trim($name));
        $domainLower = strtolower(trim($domain));

        // ═══════════════════════════════════════════════════════════════
        // 1. KNOWLEDGE BASE: Buyer keyword matching
        //    These are 100+ keywords Gemini identified as appearing in
        //    snippets/titles of REAL EMS buyer companies
        // ═══════════════════════════════════════════════════════════════
        $buyerHits = 0;
        foreach ($this->kb['_buyer_kw_lower'] as $kw) {
            if (str_contains($text, $kw)) {
                $buyerHits++;
            }
        }
        if ($buyerHits >= 3) {
            $score += 25;
            $reasons[] = "Strong buyer signals ({$buyerHits} keyword matches)";
        } elseif ($buyerHits >= 1) {
            $score += 10;
            $reasons[] = "Some buyer signals ({$buyerHits} keyword matches)";
        }

        // ═══════════════════════════════════════════════════════════════
        // 2. KNOWLEDGE BASE: Reject keyword matching
        //    100+ keywords that indicate NON-BUYERS
        // ═══════════════════════════════════════════════════════════════
        $rejectHits = 0;
        $rejectMatches = [];
        foreach ($this->kb['_reject_kw_lower'] as $kw) {
            if (str_contains($text, $kw)) {
                $rejectHits++;
                if (count($rejectMatches) < 3) {
                    $rejectMatches[] = $kw;
                }
            }
        }
        if ($rejectHits >= 3) {
            $score -= 40;
            $reasons[] = "Strong reject signals ({$rejectHits} matches: " . implode(', ', $rejectMatches) . ")";
        } elseif ($rejectHits >= 1) {
            $score -= 15;
            $reasons[] = "Some reject signals ({$rejectHits} matches: " . implode(', ', $rejectMatches) . ")";
        }

        // ═══════════════════════════════════════════════════════════════
        // 3. KNOWLEDGE BASE: Name pattern matching (regex)
        //    Gemini-generated patterns for names that are always/never buyers
        // ═══════════════════════════════════════════════════════════════
        foreach ($this->kb['name_patterns_reject'] ?? [] as $pattern) {
            try {
                if (preg_match('/' . $pattern . '/i', $name)) {
                    $score -= 25;
                    $reasons[] = "Name reject pattern: {$pattern}";
                    break; // One match is enough
                }
            } catch (\Throwable $e) {
                // Invalid regex pattern — skip silently
            }
        }

        $namePatternBuyer = false;
        foreach ($this->kb['name_patterns_buyer'] ?? [] as $pattern) {
            try {
                if (preg_match('/' . $pattern . '/i', $name)) {
                    $score += 10;
                    $reasons[] = "Name buyer pattern: {$pattern}";
                    $namePatternBuyer = true;
                    break;
                }
            } catch (\Throwable $e) {
                // Invalid regex
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // 4. KNOWLEDGE BASE: Giant OEM detection
        //    85 mega-corporations too large to be realistic EMS outsourcers
        // ═══════════════════════════════════════════════════════════════
        foreach ($this->kb['_giant_names_lower'] as $giant) {
            // Match exact or as a standalone word in the name
            if ($nameLower === $giant || preg_match('/\b' . preg_quote($giant, '/') . '\b/i', $nameLower)) {
                $score -= 35;
                $reasons[] = "Giant OEM: {$giant}";
                break;
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // 5. KNOWLEDGE BASE: Distributor indicator matching
        //    30+ phrases that mark distributors vs OEMs
        // ═══════════════════════════════════════════════════════════════
        foreach ($this->kb['_distributor_lower'] as $indicator) {
            if (str_contains($text, $indicator)) {
                $score -= 25;
                $reasons[] = "Distributor indicator: {$indicator}";
                break;
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // 6. KNOWLEDGE BASE: Domain pattern rejection
        //    20 domain substrings that indicate non-buyer websites
        // ═══════════════════════════════════════════════════════════════
        foreach ($this->kb['domain_reject_patterns'] ?? [] as $domainPattern) {
            if (!empty($domainLower) && str_contains($domainLower, strtolower($domainPattern))) {
                $score -= 20;
                $reasons[] = "Domain reject pattern: {$domainPattern}";
                break;
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // 7. KNOWLEDGE BASE: Business type scoring (weighted)
        //    Buyer and reject business types with 1-10 weights
        // ═══════════════════════════════════════════════════════════════
        $bestBuyerWeight = 0;
        $bestBuyerType = '';
        foreach ($this->kb['buyer_business_types'] ?? [] as $bt) {
            $typeLower = strtolower($bt['type'] ?? '');
            if (!empty($typeLower) && str_contains($text, $typeLower)) {
                $w = (int)($bt['weight'] ?? 5);
                if ($w > $bestBuyerWeight) {
                    $bestBuyerWeight = $w;
                    $bestBuyerType = $bt['type'];
                }
            }
        }
        if ($bestBuyerWeight > 0) {
            $bonus = (int)($bestBuyerWeight * 3); // scale 1-10 → 3-30 points
            $score += $bonus;
            $reasons[] = "Business type match: {$bestBuyerType} (weight {$bestBuyerWeight}, +{$bonus})";
        }

        $bestRejectWeight = 0;
        $bestRejectType = '';
        foreach ($this->kb['reject_business_types'] ?? [] as $bt) {
            $typeLower = strtolower($bt['type'] ?? '');
            if (!empty($typeLower) && str_contains($text, $typeLower)) {
                $w = (int)($bt['weight'] ?? 5);
                if ($w > $bestRejectWeight) {
                    $bestRejectWeight = $w;
                    $bestRejectType = $bt['type'];
                }
            }
        }
        if ($bestRejectWeight > 0) {
            $penalty = (int)($bestRejectWeight * 3);
            $score -= $penalty;
            $reasons[] = "Reject business type: {$bestRejectType} (weight {$bestRejectWeight}, -{$penalty})";
        }

        // ═══════════════════════════════════════════════════════════════
        // 8. LOCAL HEURISTICS (not from knowledge base)
        //    These are structural features the local model checks directly
        // ═══════════════════════════════════════════════════════════════

        // ── Name quality: single word, too short, too long ──
        $wordCount = str_word_count($name);
        if ($wordCount <= 1 && strlen($name) < 10) {
            $score -= 10;
            $reasons[] = 'Very short/single-word name';
        }
        if ($wordCount > 8) {
            $score -= 10;
            $reasons[] = 'Suspiciously long name (probably a sentence)';
        }

        // ── Name looks like a phrase, not a company ──
        if (preg_match('/^(how|what|why|where|when|who|top|best|list|guide|review|comparison|vs|versus|alternative|the\s+\d+)/i', $name)) {
            $score -= 30;
            $reasons[] = 'Name looks like a search phrase, not a company';
        }

        // ── Domain: .com/.net = normal, exotic TLDs = suspicious ──
        if (!empty($domainLower)) {
            if (preg_match('/\.(com|co|net|org|io)$/i', $domainLower)) {
                $score += 3;
            }
            if (preg_match('/\.(ae|eg|ma|de|fr|nl|cz|pl|ro|us|uk|ca|au)$/i', $domainLower)) {
                $score += 3;
            }
            // Free hosting / blog platforms
            if (preg_match('/(wordpress|blogspot|wix|squarespace|medium|github\.io|gitlab\.io)/i', $domainLower)) {
                $score -= 15;
                $reasons[] = 'Free hosting/blog platform domain';
            }
        }

        // ── Snippet mentions EMS-specific certifications ──
        if (preg_match('/\b(iso\s*9001|iatf\s*16949|as9100|iso\s*13485|nadcap|iso\s*14001|ce\s+mark|ul\s+listed|mil[\s-]?std)\b/i', $text)) {
            $score += 12;
            $reasons[] = 'Industry certifications mentioned';
        }

        // ── Snippet mentions product design/development ──
        if (preg_match('/\b(we\s+design|we\s+develop|we\s+engineer|we\s+manufacture|our\s+products?|product\s+(line|range|portfolio)|r&d\b|research\s+and\s+development)\b/i', $text)) {
            $score += 15;
            $reasons[] = 'Product design/development language';
        }

        // ── Snippet mentions outsourcing / supply chain ──
        if (preg_match('/\b(outsourc|contract\s+manufactur|supply\s+chain|procurement|vendor\s+management|oem\s+partner|tier[\s-]?[12]\s+supplier)\b/i', $text)) {
            $score += 10;
            $reasons[] = 'Supply chain / outsourcing language';
        }

        // ── Company legal suffix boosts confidence ──
        if (preg_match('/\b(ltd|llc|inc|corp|gmbh|sa|sas|bv|nv|ag|plc|co\.|pty|srl|spa|fze|fzc|group|holding)\b/i', $name)) {
            $score += 8;
            $reasons[] = 'Proper company legal suffix';
        }

        // ═══════════════════════════════════════════════════════════════
        // 9. DECISION
        // ═══════════════════════════════════════════════════════════════
        $verdict = 'UNCERTAIN';
        if ($score >= 15) {
            $verdict = 'BUYER';
        } elseif ($score <= -20) {
            $verdict = 'REJECT';
        }

        return [
            'verdict' => $verdict,
            'score' => $score,
            'reasons' => $reasons,
        ];
    }

    /**
     * Validate whether a name looks like a real person's name (not a company name).
     *
     * Used by CompanyDiscoveryService to reject garbage contact names
     * like "RFC Technologies" or "Sales Manager" before saving them as contacts.
     *
     * Returns true if the name looks like a real person.
     * Returns false if the name looks like a company, department, job title, or generic term.
     */
    public function isLikelyPersonName(string $firstName, string $lastName, string $companyName = ''): bool
    {
        $full = trim($firstName . ' ' . $lastName);
        $firstLower = strtolower(trim($firstName));
        $lastLower = strtolower(trim($lastName));
        $fullLower = strtolower($full);
        $companyLower = strtolower(trim($companyName));

        // ─── 1. Must have both first and last name with reasonable length ──
        if (strlen($firstName) < 2 || strlen($lastName) < 2) {
            return false;
        }

        // ─── 2. Reject if full name matches or contains the company name ──
        if (!empty($companyLower)) {
            // "RFC Technologies" as a contact for company "RFC Technologies Group"
            $similarity = 0;
            similar_text($fullLower, $companyLower, $similarity);
            if ($similarity > 60) {
                return false;
            }
            // Check if full name is a subset of company name or vice versa
            if (str_contains($companyLower, $fullLower) || str_contains($fullLower, $companyLower)) {
                return false;
            }
        }

        // ─── 3. Reject company suffixes anywhere in the name ──
        $companySuffixes = [
            'llc', 'ltd', 'inc', 'corp', 'corporation', 'company', 'co',
            'gmbh', 'ag', 'sa', 'sas', 'bv', 'nv', 'plc', 'pty',
            'srl', 'spa', 'fze', 'fzc', 'fzco', 'group', 'holding',
            'industries', 'enterprises', 'international', 'global',
            'technologies', 'technology', 'tech', 'electronics',
            'systems', 'solutions', 'services', 'consulting',
            'manufacturing', 'engineering', 'automation', 'electric',
            'electrical', 'power', 'energy', 'controls', 'instruments',
            'partners', 'associates', 'ventures', 'capital', 'investments',
            'foundation', 'institute', 'labs', 'laboratory', 'network',
        ];
        // Check each WORD in the full name (handles multi-word last names like "Computer Systems LLC")
        $allWords = preg_split('/[\s,]+/', $fullLower);
        foreach ($companySuffixes as $suffix) {
            foreach ($allWords as $word) {
                if ($word === $suffix) {
                    return false;
                }
            }
        }

        // ─── 4. Reject job titles used as names ──
        $jobTitles = [
            'manager', 'director', 'president', 'chairman', 'chairwoman',
            'ceo', 'cto', 'cfo', 'coo', 'cmo', 'vp', 'svp', 'evp',
            'chief', 'officer', 'executive', 'supervisor', 'coordinator',
            'administrator', 'secretary', 'assistant', 'analyst',
            'specialist', 'consultant', 'engineer', 'architect',
            'editorial', 'editor', 'correspondent', 'reporter',
            'staff', 'team', 'department', 'division', 'unit', 'bureau',
            'sales', 'marketing', 'support', 'customer', 'service',
            'operations', 'logistics', 'procurement', 'hr', 'human',
            'general', 'regional', 'national', 'senior', 'junior',
            'lead', 'head', 'principal', 'managing', 'deputy', 'associate',
            'expo', 'exhibition', 'event', 'conference',
        ];
        // Both parts being job-title words is bad
        $firstIsTitle = in_array($firstLower, $jobTitles);
        $lastIsTitle = in_array($lastLower, $jobTitles);
        if ($firstIsTitle && $lastIsTitle) {
            return false; // "Sales Manager", "General Director"
        }
        // Single part being certain keywords is bad on its own
        $definitelyNotName = [
            'editorial', 'staff', 'team', 'department', 'division',
            'expo', 'exhibition', 'event', 'conference', 'bureau',
            'customer', 'support', 'operations', 'procurement',
            'content', 'maker', 'admin', 'webmaster', 'contributor',
            'writer', 'blogger', 'editor', 'moderator', 'reviewer',
            // iter13 Tunisia: corporate function / compliance pages scraped as contacts
            'whistleblowing', 'whistleblower', 'compliance', 'ethics',
            'governance', 'integrity', 'ombudsman', 'hotline',
            'grievance', 'transparency', 'sustainability', 'csr',
            'careers', 'recruitment', 'hiring', 'jobs',
            'privacy', 'legal', 'cookie', 'cookies', 'disclaimer',
            'newsroom', 'pressroom', 'mediaroom', 'press',

            // ── EU-expansion: English common/function words (cookie banners, nav, marketing) ──
            'by', 'as', 'at', 'to', 'in', 'on', 'or', 'if', 'so', 'up', 'do',
            'an', 'be', 'am', 'is', 'no', 'go', 'he',
            'we', 'our', 'this', 'the', 'that', 'these', 'those', 'your', 'their',
            'its', 'his', 'her', 'my', 'any', 'all', 'some', 'each', 'every',
            'use', 'using', 'used', 'manage', 'accept', 'reject', 'select',
            'adjust', 'consent', 'notice', 'result', 'experience', 'more',
            'only', 'also', 'not', 'out', 'off', 'about', 'here', 'there',
            'how', 'what', 'which', 'where', 'when', 'who', 'why',
            'can', 'may', 'will', 'shall', 'would', 'could', 'should',
            'are', 'were', 'was', 'been', 'being', 'have', 'has', 'had',
            'does', 'did', 'doing', 'done', 'make', 'made', 'take', 'taken',
            'read', 'set', 'get', 'got', 'let', 'put', 'keep', 'kept',
            'connect', 'click', 'view', 'show', 'hide', 'open', 'close',
            'find', 'search', 'browse', 'visit', 'learn', 'know', 'see',
            'personal', 'essential', 'necessary', 'functional', 'optional',
            'third', 'certain', 'respective', 'external', 'preferred',
            'competitive', 'supplier', 'explanations', 'regarding',
            'corporate', 'headquarters', 'registration', 'portal',
            'deal', 'flight', 'remote', 'maintenance', 'design', 'quality',
            'project', 'planning', 'scope', 'years', 'form', 'name',
            'infrastructure', 'secured', 'leadership', 'ownership',
            'inquiries', 'matters', 'data', 'protection', 'settings',
            'policy', 'policies', 'website', 'sites', 'online',
            'parties', 'performance', 'based', 'information', 'display',
            'enable', 'improve', 'process', 'processing', 'store',
            'stored', 'custom', 'customized', 'benutzerdefinierten',

            // ── German common words (articles, prepositions, verbs, nouns — all capitalized in German) ──
            'die', 'der', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'einem', 'einen',
            'wir', 'sie', 'ich', 'ihr', 'uns', 'ihm', 'ihn', 'mir', 'mich',
            'und', 'oder', 'aber', 'auch', 'nur', 'wie', 'mit', 'für', 'von',
            'zur', 'zum', 'bei', 'über', 'auf', 'aus', 'bis', 'nach', 'vor',
            'durch', 'ohne', 'unter', 'zwischen', 'gegen', 'seit', 'während',
            'ist', 'sind', 'hat', 'haben', 'wird', 'werden', 'kann', 'können',
            'muss', 'müssen', 'soll', 'sollen', 'darf', 'dürfen', 'mag', 'mögen',
            'diese', 'dieser', 'dieses', 'jede', 'jeder', 'jedes', 'alle',
            'nicht', 'kein', 'keine', 'keiner', 'noch', 'schon', 'sehr',
            'hier', 'dort', 'dann', 'wenn', 'weil', 'dass', 'damit',
            'seine', 'seiner', 'seinen', 'ihren', 'ihrem', 'ihrer',
            'unsere', 'unserer', 'unseren', 'unserem', 'unserer',
            'rechte', 'recht', 'setzen', 'verwenden', 'verwendet',
            'akzeptieren', 'essentielle', 'bestimmte', 'bitte',
            'aktivieren', 'anzeigen', 'informationen', 'individuelle',
            'datenschutzeinstellungen', 'datenschutz',
            'geschäftsleitung', 'führungsteam', 'geschäftsführer',
            'kontakt', 'technologien', 'webseite', 'erfolgen',
            'verbieten', 'bereits', 'gesetzte', 'speichern',
            'einwilligung', 'einstellungen', 'auswählen',
            'ausblenden', 'ähnliche',

            // ── French common words ──
            'le', 'la', 'les', 'un', 'une', 'des', 'du', 'au', 'aux',
            'nous', 'vous', 'ils', 'elles', 'lui', 'leur', 'leurs',
            'ce', 'cet', 'cette', 'ces', 'mon', 'ton', 'son', 'nos', 'vos',
            'et', 'ou', 'mais', 'donc', 'car', 'ni', 'puis',
            'de', 'en', 'par', 'pour', 'sur', 'sous', 'avec', 'sans', 'dans',
            'est', 'sont', 'ont', 'être', 'avoir', 'fait', 'faire',
            'pas', 'plus', 'très', 'bien', 'tout', 'tous', 'toute', 'toutes',
            'qui', 'que', 'quoi', 'dont', 'où',
            'accepter', 'gérer', 'mes', 'consentement',
            'politique', 'confidentialité', 'données', 'personnelles',
            'utilisons', 'telles',

            // ── Dutch common words ──
            'het', 'een', 'zij', 'wij', 'hun', 'haar', 'zijn',
            'niet', 'ook', 'nog', 'wel', 'dan', 'als', 'maar',
            'met', 'voor', 'van', 'naar', 'uit', 'door', 'bij',
            'deze', 'dit', 'dat', 'die',
            'worden', 'kunnen', 'moeten', 'willen', 'zullen',

            // ── Polish common words ──
            'nie', 'tak', 'jest', 'aby', 'lub', 'czy', 'jak',
            'oraz', 'bez', 'przy', 'nad', 'pod', 'przed',
            'nasza', 'nasz', 'nasze', 'które', 'który', 'która',
            'pliki', 'plik', 'ciasteczka', 'strona', 'witryna',
            'zgoda', 'polityka', 'prywatności', 'dane', 'osobowe',
            'wszystkie', 'tylko', 'niezbędne', 'ustawienia',

            // ── Italian common words ──
            'il', 'lo', 'gli', 'una', 'uno', 'dei', 'del', 'della', 'delle', 'dello',
            'nel', 'nella', 'nei', 'negli', 'nelle', 'sul', 'sulla', 'sui',
            'noi', 'voi', 'loro', 'suo', 'sua', 'suoi', 'sue', 'nostro', 'nostra',
            'che', 'chi', 'cosa', 'come', 'dove', 'quando', 'perché',
            'con', 'tra', 'fra', 'senza', 'verso', 'dopo', 'prima',
            'sono', 'siamo', 'hanno', 'essere', 'avere',
            'non', 'più', 'molto', 'anche', 'così', 'già', 'ancora',
            'questo', 'questa', 'questi', 'queste', 'quello', 'quella',
            'utilizziamo', 'accetta', 'accettare', 'rifiuta', 'gestisci',
            'informativa', 'consenso', 'preferenze',

            // ── Spanish common words ──
            'el', 'los', 'las', 'unos', 'unas',
            'del', 'al',
            'nosotros', 'vosotros', 'ellos', 'ellas', 'usted', 'ustedes',
            'su', 'sus', 'nuestro', 'nuestra', 'nuestros', 'nuestras',
            'qué', 'quién', 'cómo', 'dónde', 'cuándo', 'por',
            'con', 'sin', 'sobre', 'entre', 'hasta', 'desde', 'hacia',
            'somos', 'tenemos', 'pueden', 'puede',
            'no', 'más', 'muy', 'también', 'ya', 'aún', 'todavía',
            'este', 'esta', 'estos', 'estas', 'ese', 'esa', 'aquel', 'aquella',
            'aceptar', 'rechazar', 'gestionar', 'configurar',
            'privacidad', 'aviso',

            // ── Portuguese common words ──
            'os', 'as', 'um', 'uma', 'uns', 'umas',
            'do', 'da', 'dos', 'das', 'no', 'na', 'nos', 'nas', 'ao', 'aos',
            'nós', 'eles', 'elas', 'você', 'vocês',
            'seu', 'sua', 'seus', 'suas', 'nosso', 'nossa',
            'como', 'onde', 'porque',
            'com', 'sem', 'sobre', 'entre', 'até', 'desde', 'para',
            'são', 'tem', 'têm', 'pode', 'podem',
            'não', 'mais', 'muito', 'também', 'ainda',
            'este', 'esta', 'estes', 'estas', 'esse', 'essa',
            'aceitar', 'rejeitar', 'gerir',

            // ── Swedish common words ──
            'och', 'att', 'det', 'som', 'med', 'till', 'från',
            'har', 'kan', 'ska', 'var', 'vår', 'våra', 'era',
            'inte', 'eller', 'när', 'här', 'där', 'sedan',
            'denna', 'detta', 'dessa', 'vilka', 'vilken', 'vilket',
            'acceptera', 'avvisa', 'hantera', 'inställningar',
            'webbplats', 'kakor', 'sekretess', 'integritet',

            // ── Danish common words ──
            'og', 'er', 'til', 'med', 'fra', 'har', 'kan',
            'skal', 'vil', 'var', 'vor', 'vores',
            'ikke', 'eller', 'når', 'hvor', 'hvad', 'hvem',
            'denne', 'dette', 'disse',
            'acceptér', 'afvis', 'indstillinger', 'samtykke',

            // ── Finnish common words ──
            'ja', 'on', 'ei', 'se', 'tämä', 'nämä',
            'tai', 'mutta', 'kun', 'jos', 'niin',
            'ovat', 'oli', 'olla', 'voida',
            'meidän', 'teidän', 'heidän',
            'hyväksy', 'hyväksyä', 'hylkää', 'asetukset',
            'evästeet', 'eväste', 'tietosuoja', 'yksityisyys',

            // ── Norwegian common words ──
            'og', 'er', 'til', 'med', 'fra', 'har', 'kan',
            'skal', 'vil', 'var', 'vår', 'våre',
            'ikke', 'eller', 'når', 'hvor', 'hva', 'hvem',
            'denne', 'dette', 'disse',
            'godta', 'avvis', 'innstillinger', 'samtykke',
            'informasjonskapsler', 'personvern',

            // ── Czech common words ──
            'jsou', 'jsme', 'není', 'mají', 'může', 'musí',
            'tento', 'tato', 'toto', 'tyto', 'naše', 'vaše', 'jejich',
            'nebo', 'ale', 'když', 'kde', 'jak', 'kdo', 'co',
            'přijmout', 'odmítnout', 'nastavení', 'souhlas',
            'soubory', 'ochrana', 'osobních', 'údajů',

            // ── Romanian common words ──
            'sunt', 'este', 'avem', 'poate', 'trebuie',
            'acest', 'această', 'aceste', 'acești',
            'sau', 'dar', 'când', 'unde', 'cum', 'cine',
            'nostru', 'noastră', 'lor',
            'acceptă', 'refuză', 'setări', 'consimțământ',
            'cookie-uri', 'confidențialitate',

            // ── Hungarian common words ──
            'egy', 'nem', 'igen', 'van', 'volt', 'lesz',
            'vagy', 'mint', 'már', 'még', 'itt',
            'ezt', 'azt', 'ezek', 'azok',
            'elfogad', 'elutasít', 'beállítások', 'hozzájárulás',
            'sütik', 'adatvédelem',

            // ── Country / geography names parsed as person names ──
            'united', 'kingdom', 'states', 'america', 'africa', 'kong',
            'puerto', 'rico', 'south', 'north', 'east', 'west',
            'france', 'germany', 'poland', 'netherlands', 'holland',
            'england', 'scotland', 'ireland', 'wales',
            'italy', 'spain', 'portugal', 'belgium', 'austria',
            'sweden', 'denmark', 'finland', 'norway', 'switzerland',
            'czech', 'romania', 'hungary', 'croatia', 'slovenia',
            'europe', 'european', 'worldwide', 'global',
            'elastic', 'metal', 'moduloo',

            // ── Business / generic nouns that get parsed as names ──
            'industry', 'industries', 'collaborations', 'collaboration',
            'innovation', 'innovations', 'initiative', 'initiatives',
            'integration', 'implementation', 'development', 'developments',
            'management', 'communications', 'communication',
            'organization', 'association', 'application', 'applications',
            'environment', 'performance', 'efficiency', 'sustainability',
            'solutions', 'products', 'overview', 'categories', 'practices',
            'microsoft', 'google', 'facebook', 'linkedin', 'twitter',

            // ── German nouns/words commonly Title-Cased (not names) ──
            'rolle', 'weitere', 'andere', 'neuen', 'neuer', 'neue',
            'impressum', 'unternehmen', 'standort', 'standorte',
            'karriere', 'stellenangebote', 'produkte', 'leistungen',
            'geschäftsführung', 'vorstand', 'aufsichtsrat',

            // ── German product description adjectives / technical nouns ──
            'kompakt', 'kompakter', 'kompakte', 'kompaktes', 'kompakten',
            'zahlreich', 'zahlreiche', 'zahlreicher', 'zahlreiches',
            'leistungsstark', 'leistungsstarker', 'leistungsstarke',
            'mittlere', 'mittlerer', 'mittleres',
            'reife', 'multitag', 'schnittstelle', 'schnittstellen',
            'robust', 'robuste', 'robuster', 'vielseitig', 'vielseitige',
            'zertifiziert', 'zertifizierte', 'integriert', 'integrierte',
            'modular', 'modulare', 'hochwertig', 'hochwertige',
            'baugruppe', 'baugruppen', 'platine', 'platinen',
            'bauteil', 'bauteile', 'gehäuse', 'stecker',
            'temperatur', 'spannung', 'frequenz',

            // ── French common words / product / technical terms ──
            'colorant', 'colorants', 'rouge', 'bleu', 'vert', 'noir', 'blanc',
            'ingénieur', 'ingénieurs', 'technicien', 'techniciens',
            'peuvent', 'devrait', 'pourrait',
            'vidéo', 'vidéos', 'industriel', 'industrielle',
            'équipement', 'équipements', 'composant', 'composants',
            'fabrication', 'assemblage', 'montage',
            'capteur', 'capteurs', 'puissance', 'tension',

            // ── Chemical / scientific terms ──
            'benzoate', 'denatonium', 'sulfate', 'phosphate', 'carbonate',
            'nitrate', 'acetate', 'chloride', 'oxide', 'hydroxide',

            // ── City / geography names (FR/DE/IT/ES) ──
            'paris', 'lyon', 'marseille', 'toulouse', 'bordeaux', 'lille',
            'strasbourg', 'nantes', 'montpellier', 'grenoble',
            'wien', 'zürich', 'zurich', 'bern', 'genf', 'geneva', 'basel',
            'milano', 'roma', 'torino', 'firenze', 'napoli',
            'madrid', 'barcelona', 'valencia', 'sevilla',
            'amsterdam', 'rotterdam', 'bruxelles', 'antwerp',

            // ── Generic UI / location / department terms ──
            'locations', 'location', 'office', 'offices',
            'commercial', 'export', 'import', 'vente',
            'département', 'filiale', 'succursale',

            // ── Quote attribution verbs leaked into names ──
            'says', 'said', 'explains', 'explained', 'adds', 'added',
            'notes', 'noted', 'announces', 'announced',
            'comments', 'commented', 'reports', 'reported',
            'sagt', 'sagte', 'erklärt', 'erklärte', 'betont',
            'dit', 'déclare', 'explique', 'ajoute', 'précise',
            'selon', 'poursuit', 'confirme', 'indique', 'souligne',
            // ── Polish product / technical / junk words ──
            'dostępny', 'dostepny', 'wydajny', 'wydajna', 'niezawodny',
            'precyzyjny', 'wytrzymały', 'wytrzymala', 'trwały', 'trwala',
            'nowoczesny', 'nowoczesna', 'innowacyjny', 'innowacyjna',
            'automatyczny', 'automatyczna', 'spiralnych', 'spiralna',
            'maszyna', 'maszyny', 'urządzenie', 'urzadzenie',
            'narzędzie', 'narzedzie', 'produkt', 'produkty',
            'technologia', 'technologie', 'rozwiązanie', 'rozwiazanie',
            'startup', 'attempts', 'attempt', 'resident', 'county',
            'connecting', 'locker', 'frontair', 'extremely', 'efficient',
            // ── German industrial nouns ──
            'sonstiges', 'sonstige', 'drehmaschinen', 'drehmaschine',
            'dornenlose', 'dornenlos', 'rohr', 'rohre',
            'fräsmaschine', 'frasmaschine', 'bohrmaschine', 'schleifmaschine',
            'werkzeugmaschine', 'bandsäge', 'bandsage',
            // ── Italian product / technical / navigation words ──
            'sgrigliatore', 'automatico', 'automatica',
            'scambiatore', 'scambiatori', 'raffreddamento', 'riscaldamento',
            'aria', 'acqua', 'olio', 'vapore',
            'sede', 'amministrativa', 'amministrativo', 'amministrazione',
            'isola', 'isole', 'territorio', 'provincia', 'regione', 'comune',
            'macchina', 'macchine', 'impianto', 'impianti',
            'componente', 'componenti', 'accessorio', 'accessori',
            'lavorazione', 'lavorazioni', 'trattamento', 'trattamenti',
            'stampaggio', 'fusione', 'fresatura', 'tornitura', 'rettifica',
            'qualità', 'sicurezza', 'affidabilità', 'efficienza',
            'resistenza', 'potenza', 'pressione', 'portata', 'capacità',
            'misura', 'misure', 'controllo', 'controlli', 'sensore', 'sensori',
            'motore', 'motori', 'valvola', 'valvole',
            'cilindro', 'cilindri', 'riduttore', 'riduttori',
            'compressore', 'compressori', 'generatore', 'generatori',
            'certificazione', 'certificazioni', 'normativa', 'normative',
            'azienda', 'aziende', 'impresa', 'imprese', 'stabilimento',
            'contatti', 'contattaci', 'richiesta', 'preventivo',
            'carriera', 'carriere', 'notizie', 'novità',
            // ── Political / head-of-state ──
            'republic', 'repubblica', 'republik', 'république',
            'chancellor', 'senator', 'ambassador', 'consul', 'governor',
            // ── Generic English junk words ──
            'standard', 'advanced', 'basic', 'enhanced',
            'autonomous', 'exchangers', 'exchanger',
            'cocos', 'cook',

            // ── Product specification / technical measurement terms ──
            'coaxial', 'antenna', 'antennas', 'thermal', 'density',
            'complexity', 'impedance', 'attenuation', 'bandwidth',
            'wavelength', 'amplitude', 'conductivity', 'resistivity',
            'dielectric', 'inductance', 'capacitance', 'reactance',
            'connector', 'connectors', 'cable', 'cables', 'wire', 'wires',
            'harness', 'receptacle', 'socket', 'sockets', 'terminal', 'terminals',
            'sensor', 'sensors', 'detector', 'detectors', 'actuator', 'actuators',
            'module', 'modules', 'panel', 'panels', 'relay', 'relays',
            'switch', 'switches', 'fuse', 'fuses', 'plug', 'plugs',
            'pump', 'pumps', 'valve', 'valves', 'turbine', 'turbines',
            'compressor', 'compressors', 'generator', 'generators',
            'inverter', 'inverters', 'motor', 'motors',
            'hvac', 'cooling', 'heating', 'evaporative', 'condenser', 'condensers',
            'gasket', 'gaskets', 'shrink', 'tube', 'tubes',
            'voltage', 'current', 'resistance', 'frequency', 'tolerance',
            'dimension', 'dimensions', 'rating', 'ratings', 'range',
            'stability', 'output', 'input', 'capacity',
            'rail', 'railway', 'railroad', 'transit', 'transport',
            'aerospace', 'defense', 'defence', 'marine', 'naval',
            'replacement', 'upgrade', 'upgrades', 'configuration',
            'specification', 'specifications', 'description',
            'alternative', 'extraordinary', 'operating',
            'vat', 'id', 'pid', 'sku', 'ref', 'qty',
        ];
        if (in_array($firstLower, $definitelyNotName) || in_array($lastLower, $definitelyNotName)) {
            return false;
        }
        // Also check individual words within multi-word first/last names
        // e.g. first="Ingénieurs Peuvent" last="Être" → check each word
        $allNameWords = preg_split('/\s+/', $fullLower);
        foreach ($allNameWords as $nw) {
            if (in_array($nw, $definitelyNotName, true)) {
                return false;
            }
        }

        // ─── 4b. Reject if full name matches cookie/privacy/consent patterns ──
        if (preg_match('/\b(cookie|cookies|datenschutz|gdpr|rgpd|eprivacy|consent|privacy|tracking|analytics)\b/i', $fullLower)) {
            return false;
        }
        // Reject "X As Vice/President/..." — "as" leaking into parsed names
        if (preg_match('/\bas\s+(vice|president|director|manager|ceo|cto|cfo)/i', $fullLower)) {
            return false;
        }
        // Reject names ending with trailing preposition ("Bacher as", "Reipert as", "Schmidt als")
        if (preg_match('/\s+(as|als|von|und|oder|by|at|in|on|to|for)$/i', $fullLower)) {
            return false;
        }
        // Reject "XWord And YWord" — conjunction gluing two non-name words
        if (preg_match('/\b(and|und|et|of|von|du|des)\b/i', $fullLower)) {
            return false;
        }

        // ─── 5. Reject if name contains numbers ──
        if (preg_match('/\d/', $full)) {
            return false;
        }

        // ─── 6. Normalize ALL-CAPS names to Title Case before checking ──
        //    Arabic/French names often arrive in ALL-CAPS from LinkedIn.
        //    "ISMAILI ALAOUI" → "Ismaili Alaoui" (valid person name)
        //    Only reject if AFTER normalization the name re-matches company suffixes.
        if (strlen($firstName) > 3 && $firstName === strtoupper($firstName)) {
            $firstName = mb_convert_case($firstName, MB_CASE_TITLE, 'UTF-8');
            $firstLower = strtolower($firstName);
        }
        if (strlen($lastName) > 3 && $lastName === strtoupper($lastName)) {
            $lastName = mb_convert_case($lastName, MB_CASE_TITLE, 'UTF-8');
            $lastLower = strtolower($lastName);
        }
        // Re-check company suffixes after normalization
        $allWords = preg_split('/[\s,]+/', strtolower(trim($firstName . ' ' . $lastName)));
        foreach ($companySuffixes as $suffix) {
            foreach ($allWords as $word) {
                if ($word === $suffix) {
                    return false;
                }
            }
        }

        // ─── 7. Reject if name looks like it has too many words ──
        //    Real person name: "John Smith", "Mohammed Al-Rashid"
        //    Fake: "Pacific Power Source Corporation"
        $full = trim($firstName . ' ' . $lastName);
        $totalWords = str_word_count($full);
        if ($totalWords > 5) {
            return false;
        }

        // ─── 8. Reject if first or last name is too long ──
        //    Allow up to 30 chars for multi-part names ("Ezzahra Ismaili Alaoui")
        if (strlen($firstName) > 25 || strlen($lastName) > 30) {
            return false;
        }

        // ─── 9. Reject generic/placeholder patterns ──
        $genericPatterns = [
            '/^(info|admin|contact|support|sales|marketing|hello|no[\s-]?reply|webmaster)$/i',
            '/^(mr|mrs|ms|dr|prof|sir|lady|lord)\s*$/i',
            '/^(unknown|unnamed|anonymous|n\/?a|none|test|dummy|sample)$/i',
        ];
        foreach ($genericPatterns as $pattern) {
            if (preg_match($pattern, $firstLower) || preg_match($pattern, $lastLower)) {
                return false;
            }
        }

        // ─── 10. Check knowledge base for company name patterns in the name ──
        if ($this->loaded) {
            foreach ($this->kb['name_patterns_reject'] ?? [] as $pattern) {
                try {
                    if (preg_match('/' . $pattern . '/i', $full)) {
                        return false;
                    }
                } catch (\Throwable $e) {
                    // Invalid regex
                }
            }
        }

        // ══════════════════════════════════════════════════════════════════
        // SMART PATTERN-BASED JUNK DETECTION (iter16b)
        // Structural pattern detection to catch junk that doesn't need
        // to be explicitly listed in blacklists
        // ══════════════════════════════════════════════════════════════════

        // ─── 11. Gerund as first name (>5 chars ending in -ing) → likely junk ──
        // "Strengthening Communities", "Expanding Broadband", "Ensuring Long"
        $gerundExceptions = ['sterling', 'starling', 'king', 'ming', 'ling', 'ning', 'ping', 'ying'];
        if (preg_match('/ing$/i', $firstName) && strlen($firstName) > 5 && !in_array($firstLower, $gerundExceptions, true)) {
            return false;
        }

        // ─── 12. Past participle as first name (>4 chars ending in -ed) → likely junk ──
        // "Earned Revenue", "Reduced Cost", "Integrated Solutions"
        if (preg_match('/ed$/i', $firstName) && strlen($firstName) > 4) {
            return false;
        }

        // ─── 13. Plural noun as last name (>5 chars ending in -s) requiring care ──
        // Many real surnames end in -s (Jones, Williams), but "Systems", "Solutions", etc. don't
        // We specifically target -ies, -ors, -ers, -ons, -ics, -als  patterns more aggressively
        $pluralPatterns = [
            '/ies$/i',   // Communities, Industries, Technologies
            '/ors$/i',   // Attenuators, Connectors, Sensors  
            '/ems$/i',   // Systems, Items, Problems
            '/als$/i',   // Terminals, Materials, Signals
            '/ics$/i',   // Electronics, Logistics, Analytics
            '/ons$/i',   // Solutions, Operations, Connections
        ];
        foreach ($pluralPatterns as $pattern) {
            if (preg_match($pattern, $lastName) && strlen($lastName) > 6) {
                return false;
            }
        }

        // ─── 14. Abstract noun suffixes in last name ──
        // Words ending in -ment, -tion, -ness, -ity, -ance, -ence are almost never surnames
        if (preg_match('/(?:ment|tion|sion|ness|ity|ance|ence)$/i', $lastName) && strlen($lastName) > 6) {
            return false;
        }

        // If nothing triggered a rejection, it's likely a person name
        return true;
    }

    /**
     * Check if knowledge base is loaded and functional.
     */
    public function isReady(): bool
    {
        return $this->loaded;
    }

    /**
     * Get knowledge base stats for diagnostics.
     */
    public function getStats(): array
    {
        if (!$this->loaded) {
            return ['status' => 'not_loaded'];
        }
        return [
            'status' => 'loaded',
            'buyer_keywords' => count($this->kb['buyer_keywords'] ?? []),
            'reject_keywords' => count($this->kb['reject_keywords'] ?? []),
            'buyer_business_types' => count($this->kb['buyer_business_types'] ?? []),
            'reject_business_types' => count($this->kb['reject_business_types'] ?? []),
            'name_patterns_reject' => count($this->kb['name_patterns_reject'] ?? []),
            'name_patterns_buyer' => count($this->kb['name_patterns_buyer'] ?? []),
            'giant_oems' => count($this->kb['giant_oem_names'] ?? []),
            'distributor_indicators' => count($this->kb['distributor_indicators'] ?? []),
            'domain_reject_patterns' => count($this->kb['domain_reject_patterns'] ?? []),
            'generated_at' => $this->kb['_meta']['generated_at'] ?? 'unknown',
        ];
    }
}
