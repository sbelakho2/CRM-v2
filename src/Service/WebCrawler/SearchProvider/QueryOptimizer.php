<?php

namespace App\Service\WebCrawler\SearchProvider;

/**
 * Query optimizer for web scraping search engines.
 *
 * Problem: The GoogleDorkService generates queries with 50+ exclusions like:
 *   -site:linkedin.com -site:wikipedia.org -textile -apparel -garment...
 *
 * This causes:
 *   - HTTP 413 (Payload Too Large) from engines with URL length limits
 *   - Bot detection triggers from unusual query patterns
 *   - Empty results when engines truncate long queries
 *
 * Solution: Strip long exclusion lists from queries and handle filtering
 * in post-processing instead. Each engine has different URL length limits:
 *   - Brave: ~8000 chars (generous)
 *   - Bing: ~2048 chars
 *   - DuckDuckGo: ~2048 chars
 *   - Yahoo: ~2048 chars
 *   - Startpage: ~1500 chars (strict)
 *   - Most others: ~2000 chars
 *
 * This optimizer:
 *   1. Strips verbose exclusion patterns
 *   2. Keeps core search terms
 *   3. Optionally keeps a few high-value site exclusions
 *   4. Provides post-result domain filtering
 */
final class QueryOptimizer
{
    /** Domains to always filter from results (post-processing, not in query) */
    private const BLOCKED_DOMAINS = [
        // ─── Social media & user-generated content ────────────────────
        'linkedin.com', 'facebook.com', 'twitter.com', 'x.com',
        'instagram.com', 'tiktok.com', 'pinterest.com', 'reddit.com',
        'quora.com', 'tumblr.com', 'threads.net', 'vk.com',
        'xing.com', 'viadeo.com',
        // ─── Video / streaming ────────────────────────────────────────
        'youtube.com', 'vimeo.com', 'dailymotion.com', 'twitch.tv',
        // ─── Reference / knowledge ────────────────────────────────────
        'wikipedia.org', 'wikidata.org', 'wikimedia.org',
        'fandom.com', 'wikia.com',
        'stackoverflow.com', 'stackexchange.com',
        // ─── Global marketplaces ──────────────────────────────────────
        'amazon.com', 'amazon.de', 'amazon.fr', 'amazon.it',
        'amazon.es', 'amazon.nl', 'amazon.co.uk', 'amazon.pl',
        'amazon.com.au', 'amazon.ca', 'amazon.co.jp',
        'ebay.com', 'ebay.de', 'ebay.co.uk', 'ebay.fr', 'ebay.it',
        'ebay.es', 'ebay.nl', 'ebay.pl', 'ebay.com.au',
        'alibaba.com', 'aliexpress.com', 'made-in-china.com',
        'indiamart.com', 'tradeindia.com', 'tradewheel.com',
        'temu.com', 'shein.com', 'wish.com', 'banggood.com',
        // ─── Country-specific marketplaces / classifieds ──────────────
        'bol.com', 'allegro.pl', 'cdiscount.fr', 'otto.de',
        'zalando.de', 'zalando.fr', 'zalando.it', 'zalando.es',
        'zalando.nl', 'zalando.pl', 'zalando.co.uk',
        'ricardo.ch', 'willhaben.at', '2dehands.be',
        'leboncoin.fr', 'gumtree.co.uk', 'subito.it',
        'milanuncios.com', 'marktplaats.nl', 'olx.pl',
        'meinestadt.de', 'markt.de', 'kalaydo.de', 'quoka.de',
        // ─── B2B directories (not actual companies) ───────────────────
        'thomasnet.com', 'globalspec.com', 'europages.com',
        'kompass.com', 'wlw.de', 'industrystock.com',
        'directindustry.com', 'mfg.com',
        'dnb.com', 'zoominfo.com', 'crunchbase.com',
        'opencorporates.com', 'northdata.de',
        'societe.com', 'verif.com', 'manageo.fr', 'infogreffe.fr',
        'firmenwissen.de', 'bundesanzeiger.de', 'gelbeseiten.de',
        'paginegialle.it', 'registroimprese.it', 'atoka.io',
        'einforma.com', 'empresia.es', 'axesor.es',
        'kvk.nl', 'detelefoongids.nl', 'openkvk.nl',
        'panoramafirm.pl', 'pkt.pl',
        'firmy.cz', 'yell.com', 'checkatrade.com', '192.com',
        // ─── Job boards ───────────────────────────────────────────────
        'glassdoor.com', 'glassdoor.de', 'glassdoor.fr',
        'indeed.com', 'indeed.de', 'indeed.fr', 'indeed.co.uk',
        'monster.com', 'monster.de', 'monster.fr',
        'stepstone.de', 'stepstone.nl', 'stepstone.be',
        'pole-emploi.fr', 'arbeitsagentur.de',
        'infojobs.it', 'infojobs.es', 'infojobs.net',
        'pracuj.pl', 'jobs.cz', 'werk.nl',
        'randstad.com', 'randstad.de', 'randstad.fr',
        'hays.com', 'hays.de', 'hays.fr',
        'michaelpage.com', 'michaelpage.de', 'michaelpage.fr',
        // ─── Price comparison / reviews ───────────────────────────────
        'trustpilot.com', 'idealo.de', 'billiger.de',
        'ledenicheur.com', 'kelkoo.com', 'tweakers.net',
        'testberichte.de', 'geizhals.de', 'geizhals.at',
        // ─── Patent / IP databases ────────────────────────────────────
        'espacenet.com', 'patents.google.com', 'tmdn.org', 'dpma.de',
        // ─── Wire services / press release ────────────────────────────
        'globenewswire.com', 'prnewswire.com', 'businesswire.com',        // ── Major news sites (compound-word domains __ misses) ────────
        'dailymail.co.uk', 'mailonline.com', 'mirror.co.uk',
        'thesun.co.uk', 'huffpost.com', 'foxnews.com',
        'nbcnews.com', 'cbsnews.com', 'usatoday.com', 'cnbc.com',
        'thedrive.com', 'autoweek.com', 'motortrend.com',
        'caranddriver.com', 'autoblog.com', 'jalopnik.com',
        'autocar.co.uk', 'topgear.com', 'insideevs.com',
        'electrive.com', 'electrive.net', 'cleantechnica.com',
        'spiegel.de', 'faz.net', 'handelsblatt.com',
        'lemonde.fr', 'lefigaro.fr', 'lesechos.fr',
        'corriere.it', 'repubblica.it', 'elpais.com',
        // ── Standards bodies / certification orgs ─────────────────────
        'iso.org', 'iec.ch', 'din.de', 'ansi.org', 'bsigroup.com',
        'ul.com', 'tuv.com', 'dekra.com', 'intertek.com', 'sgs.com',
        'bureauveritas.com', 'dnv.com', 'afnor.org',
        'technickenormy.cz', 'normservis.cz', 'beuth.de',
        'sae.org', 'astm.org',    ];

    /** Keywords indicating non-target results (for post-filtering) — ALL LANGUAGES */
    private const BLOCKED_KEYWORDS = [
        // ─── EN: Jobs / recruitment ───────────────────────────────────
        'job vacancy', 'job opening', 'careers', 'recruitment',
        'job board', 'job portal', 'apply now', 'work with us',
        'we are hiring', 'join our team',
        // ─── DE: Jobs / recruitment ───────────────────────────────────
        'stellenangebot', 'stellenanzeige', 'karriere bei',
        'wir suchen', 'jetzt bewerben', 'offene stellen',
        // ─── FR: Jobs / recruitment ───────────────────────────────────
        'offre d\'emploi', 'nous recrutons', 'rejoignez-nous',
        'postuler maintenant', 'offres d\'emploi',
        // ─── IT: Jobs / recruitment ───────────────────────────────────
        'offerta di lavoro', 'lavora con noi', 'candidatura',
        // ─── ES: Jobs / recruitment ───────────────────────────────────
        'oferta de empleo', 'trabaja con nosotros', 'vacante',
        // ─── NL: Jobs / recruitment ───────────────────────────────────
        'vacature', 'werken bij', 'solliciteer',
        // ─── PL / CZ: Jobs ────────────────────────────────────────────
        'oferta pracy', 'praca w', 'nabidka prace',
        // ─── EN: News / media ─────────────────────────────────────────
        'news agency', 'newspaper', 'magazine', 'press release',
        // ─── EN: Legal ────────────────────────────────────────────────
        'law firm', 'attorney', 'solicitor', 'legal counsel',
        // ─── DE: Legal ────────────────────────────────────────────────
        'rechtsanwalt', 'anwaltskanzlei', 'kanzlei',
        // ─── FR: Legal ────────────────────────────────────────────────
        'cabinet d\'avocat', 'avocat',
        // ─── IT / ES: Legal ───────────────────────────────────────────
        'studio legale', 'despacho de abogados', 'bufete',
        // ─── NL / PL / CZ: Legal ─────────────────────────────────────
        'advocatenkantoor', 'kancelaria prawna', 'advokatni kancelar',
        // ─── Trade / chamber ──────────────────────────────────────────
        'chamber of commerce', 'handelskammer', 'chambre de commerce',
        'camera di commercio', 'camara de comercio',
        'trade association', 'branchevereniging',
        // ─── Web artifacts / scraping junk ────────────────────────────
        'we use cookies', 'wir verwenden cookies',
        'nous utilisons des cookies', 'accept all cookies',
        'cookie-einstellungen', 'cookie preferences',
        'verify you are human', 'i am not a robot',
        'access denied', 'page not found', '404 not found',
        'seite nicht gefunden', 'page introuvable',
        'pagina non trovata', 'pagina no encontrada',
        'please enable javascript', 'enable cookies',
        'cloudflare', 'just a moment',
        // ─── E-commerce snippet signals ───────────────────────────────
        'add to cart', 'in den warenkorb', 'ajouter au panier',
        'aggiungi al carrello', 'buy now', 'jetzt kaufen',
        'acheter maintenant', 'free shipping', 'kostenloser versand',
        'livraison gratuite', 'prime eligible',
        'sold by', 'in stock', 'out of stock',
        // ─── Automotive junk (not OEM manufacturers) ──────────────────
        'car dealership', 'autohaus', 'concessionnaire auto',
        'concessionario auto', 'concesionario',
        'car rental', 'autovermietung', 'location de voiture',
        'autonoleggio', 'alquiler de coches',
        'tire shop', 'reifenhandel', 'pneumaticien',
        'pneuservis', 'opony',
        'auto insurance', 'autoversicherung',
        'assurance auto', 'assicurazione auto',
        'driving school', 'fahrschule', 'auto-ecole', 'autoescuela',
        'autobazar', 'used cars', 'gebrauchtwagen', 'occasion auto',
        'auto parts shop', 'autoteile', 'pieces auto', 'ricambi auto',
        'recambios auto', 'car wash', 'autowasche',
    ];

    /** Maximum query length for safest compatibility */
    private const DEFAULT_MAX_LENGTH = 600;

    /** Engine-specific max lengths — generous to preserve query quality */
    private const ENGINE_MAX_LENGTHS = [
        'brave_search' => 1000,
        'mojeek' => 800,
        'bing' => 800,
        'duckduckgo_html' => 700,
        'yahoo' => 600,
        'startpage' => 500,
        'qwant' => 600,
        'ecosia' => 600,
        'swisscows' => 600,
        'metager' => 600,
        'you' => 700,
        'yandex' => 700,
        'seznam' => 600,
        'exalead' => 600,
        'gigablast' => 600,
        'searxng' => 900,
        'marginalia' => 500,
        'dogpile' => 600,
        'yep' => 600,
        'alexandria' => 500,
        'rightdao' => 500,
        'aol' => 600,
        'presearch' => 600,
        'naver' => 700,
        'ask' => 600,
        'baidu' => 700,
        'info' => 600,
        'lycos' => 600,
    ];

    /**
     * Optimize a query for web scraping.
     *
     * Strips ALL negative exclusions (-site:, -keyword, -"phrase") because:
     *   1. filterResults() handles ALL blocking in post-processing
     *   2. 40+ negatives bloat queries to ~920 chars → triggers bot detection
     *   3. Most scraping engines choke on queries > 200 chars
     *   4. Excessive negatives are a strong bot fingerprint
     *
     * Preserves positive query syntax: quotes, OR, AND operators.
     *
     * @param string $query Original query (may include many negative exclusions)
     * @param string|null $engine Engine name for length limits
     * @return string Optimized query
     */
    public function optimizeQuery(string $query, ?string $engine = null): string
    {
        // Step 1: Remove ALL -site: exclusions (handled by post-result filtering)
        $query = preg_replace('/-site:\S+\s*/i', '', $query);

        // Step 2: Remove ALL -"quoted phrase" exclusions (handled by filterResults)
        // These add ~600 chars of bloat and trigger bot detection
        $query = preg_replace('/-"[^"]*"\s*/i', '', $query);

        // Step 3: Remove ALL -keyword exclusions (single unquoted negative terms)
        // Match -word but NOT things like "powertrain" (inside quotes) or OR/AND
        $query = preg_replace('/(?<!["\w])-(?!site:)[a-zA-ZÀ-ÿ]\S*\s*/i', '', $query);

        // Step 4: Collapse multiple spaces (from removed exclusions)
        $query = preg_replace('/\s+/', ' ', $query);
        $query = trim($query);

        // Step 5: Enforce engine-specific length limit
        $maxLength = self::ENGINE_MAX_LENGTHS[$engine ?? ''] ?? self::DEFAULT_MAX_LENGTH;
        if (strlen($query) > $maxLength) {
            // Try to truncate at a natural boundary (end of a quoted phrase or word)
            $truncated = substr($query, 0, $maxLength);

            // Ensure we don't break a quoted phrase
            $quoteCount = substr_count($truncated, '"');
            if ($quoteCount % 2 !== 0) {
                // Find the last opening quote and truncate before it
                $lastQuote = strrpos($truncated, '"');
                $truncated = rtrim(substr($truncated, 0, $lastQuote));
            }

            // Truncate at last complete word
            $lastSpace = strrpos($truncated, ' ');
            if ($lastSpace !== false && $lastSpace > $maxLength * 0.7) {
                $truncated = substr($truncated, 0, $lastSpace);
            }

            $query = trim($truncated);
        }

        return $query;
    }

    /** Regex patterns for e-commerce / marketplace product listings (multi-language) */
    private const ECOMMERCE_PATTERNS = [
        '/[€$£]\s*\d+[\.,]\d{2}/',                             // Price: €29.99, $15.00, £42.50
        '/\d+[\.,]\d{2}\s*[€$£]/',                             // Price: 29,99 €
        '/\bab\s+\d+[\.,]\d{2}\s*€/i',                         // DE: ab 19,99 €
        '/★{2,}|⭐{2,}|☆{2,}/',                                // Star ratings
        '/\d[\.,]\d\s*out of 5|\d[\.,]\d\/5|\d[\.,]\d\s*von\s*5/', // Rating: 4.5 out of 5
        '/\b(UPC|EAN|ASIN|SKU)\s*[:# ]\s*[A-Z0-9]{5,}/i',     // Product codes
        '/\bMPN\s*[:# ]\s*\S+/i',                              // MPN codes
    ];

    /** Regex patterns for web scraping artifacts & broken pages (multi-language) */
    private const ARTIFACT_PATTERNS = [
        '/\bcaptcha\b/i',
        '/\brecaptcha\b/i',
        '/\bcloudflare[\s\-]ray/i',
        '/\bddos[\s\-]protection/i',
        '/\bwaiting\s+for\s+.*\.com/i',
        '/\bperformance\s+&?\s*security\s+by/i',
        '/\bplease\s+(turn|enable|allow)\s+(on\s+)?javascript/i',
        '/\bbitte\s+(aktivieren|erlauben)\s+sie\s+javascript/i',
        '/\bveuillez\s+activer\s+javascript/i',
    ];

    /** Domains blocked dynamically by pattern (not exact match) */
    private const BLOCKED_DOMAIN_PATTERNS = [
        // Government
        '/\.gov(\.[a-z]{2,3})?$/i',
        // Education
        '/\.edu(\.[a-z]{2,3})?$/i',
        '/\.ac\.[a-z]{2,3}$/i',
        // Non-profit / charity – only block known non-manufacturer .org domains
        // (blanket .org block would filter legitimate IPC.org, SMTA.org, etc.)
        '/^(wikipedia|wikimedia|wiktionary|archive|creativecommons|mozilla|fsf|eff|aclu|redcross|unesco|amnesty|oxfam|greenpeace|peta)\./i',
        // Military
        '/\.mil(\.[a-z]{2,3})?$/i',
        // Shopping subdomains
        '/^(shop|store|eshop|boutique|tienda|negozio|sklep|obchod)\./i',
        // Blog/news subdomains
        '/^(blog|news|press|media|newsroom|magazine|redaktion|actualites|notizie)\./i',
        // Job subdomains
        '/^(jobs|careers|karriere|carriere|empleo|lavoro|praca)\./i',
        // Investor/IR subdomains
        '/^(investors?|ir|sharehol)\./i',
    ];

    /**
     * Filter results to remove blocked domains, spam, e-commerce listings,
     * web artifacts, and non-target content in ALL languages.
     *
     * Call this AFTER scraping to remove results that would have been
     * excluded by -site: operators. This is the first defense layer —
     * GoogleDorkService applies deeper filtering downstream.
     *
     * @param array[] $results Raw search results
     * @return array[] Filtered results
     */
    public function filterResults(array $results): array
    {
        return array_values(array_filter($results, function (array $result) {
            $url = strtolower($result['link'] ?? '');
            $title = strtolower($result['title'] ?? '');
            $snippet = strtolower($result['snippet'] ?? '');
            $displayLink = strtolower($result['displayLink'] ?? '');
            $combined = $title . ' ' . $snippet;

            // ── 1. Exact blocked domains ──────────────────────────────
            foreach (self::BLOCKED_DOMAINS as $domain) {
                if (str_contains($url, $domain) || str_contains($displayLink, $domain)) {
                    return false;
                }
            }

            // ── 2. Domain pattern matching (gov, edu, org, shop subdomains) ──
            $host = parse_url($result['link'] ?? '', PHP_URL_HOST);
            if ($host) {
                $host = strtolower(preg_replace('/^www\./', '', $host));
                foreach (self::BLOCKED_DOMAIN_PATTERNS as $pattern) {
                    if (preg_match($pattern, $host)) {
                        return false;
                    }
                }
            }

            // ── 3. Blocked keywords (multi-language) ──────────────────
            foreach (self::BLOCKED_KEYWORDS as $keyword) {
                if (str_contains($title, $keyword) || str_contains($snippet, $keyword)) {
                    return false;
                }
            }

            // ── 4. E-commerce product listing detection ───────────────
            $ecommerceHits = 0;
            foreach (self::ECOMMERCE_PATTERNS as $pattern) {
                if (preg_match($pattern, $combined)) {
                    $ecommerceHits++;
                }
            }
            // Single price mention could be a legitimate company page;
            // 2+ e-commerce signals = almost certainly a product listing
            if ($ecommerceHits >= 2) {
                return false;
            }

            // ── 5. Web artifact / broken page detection ───────────────
            foreach (self::ARTIFACT_PATTERNS as $pattern) {
                if (preg_match($pattern, $combined)) {
                    return false;
                }
            }

            // ── 6. Market research report title patterns ──────────────
            if (preg_match('/\b(market\s+size|cagr|forecast\s+\d{4}|market\s+share|market\s+report|market\s+analysis|billion\s+by\s+\d{4}|million\s+by\s+\d{4})\b/i', $combined)) {
                return false;
            }

            // ── 7. Directory listing / "top N companies" patterns ─────
            if (preg_match('/\b(top\s+\d+\s+(companies|firms|manufacturers|suppliers)|list\s+of\s+(companies|manufacturers|suppliers)|best\s+\d+\s+(companies|firms)|company\s+directory|business\s+directory|firmenverzeichnis|annuaire\s+(entreprises|professionnel)|elenco\s+aziende|directorio\s+empresas)\b/i', $combined)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Get maximum query length for an engine.
     */
    public function getMaxLength(string $engine): int
    {
        return self::ENGINE_MAX_LENGTHS[$engine] ?? self::DEFAULT_MAX_LENGTH;
    }

    /**
     * Check if a query needs optimization (is too long).
     */
    public function needsOptimization(string $query, ?string $engine = null): bool
    {
        $maxLength = self::ENGINE_MAX_LENGTHS[$engine ?? ''] ?? self::DEFAULT_MAX_LENGTH;
        return strlen($query) > $maxLength || preg_match('/-site:\S+/', $query);
    }
}
