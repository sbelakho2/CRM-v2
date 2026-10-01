<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Extracts contacts from all pages of a crawled domain using multiple
 * signal sources: JSON-LD, mailto/tel links, HTML team cards, LinkedIn URLs,
 * and visible email/phone patterns.
 *
 * Deduplicates by email, scores each contact for quality, and returns
 * contacts sorted by quality descending.
 */
final class UnifiedContactExtractor
{
    /** Team card CSS class keywords — comprehensive set for modern web frameworks. */
    private const TEAM_CLASS_KEYWORDS = [
        // Original patterns
        'team-member', 'team-card', 'staff-member', 'member-card',
        'leadership', 'person-card', 'profile-card', 'executive',
        'bio-card', 'team_member', 'team_card', 'staff_card',
        // Grid / list layouts
        'team-grid', 'staff-grid', 'people-grid', 'team-list', 'staff-list',
        'people-list', 'member-grid', 'member-list', 'employee-grid',
        // Management & board
        'management-team', 'executive-team', 'board-members', 'board-of-directors',
        'directors', 'management', 'executives', 'leadership-team',
        // Item / card patterns (Bootstrap, Tailwind, custom)
        'team-item', 'person-item', 'member-item', 'employee-card',
        'people-item', 'staff-item', 'profile-item',
        'col-team', 'card-team', 'team-col', 'staff-col',
        // Section wrappers
        'team-wrapper', 'our-team', 'meet-team', 'team-section',
        'staff-section', 'people-section', 'team-content',
        // Individual detail
        'single-team', 'single-staff', 'team-detail', 'staff-detail',
        'team-member-single', 'staff-member-single',
        // Generic common patterns
        'member', 'team__member', 'staff__member', 'profile', 'employee',
        'team-member-info', 'staff-member-info', 'person-info',
        // Job-specific containers
        'job-title', 'position-title', 'person-role', 'person-position',
        'person-name', 'person-job', 'employee-name', 'employee-title',
        // data- attribute based team cards
        'team', 'staff', 'people', 'person', 'member', 'employee',
    ];

    private const GENERIC_EMAIL_PREFIXES = [
        'info', 'sales', 'contact', 'support', 'admin', 'hr', 'marketing',
        'webmaster', 'noreply', 'no-reply', 'office', 'careers', 'jobs',
        'press', 'media', 'general', 'enquiries', 'hello', 'service',
        'help', 'billing', 'accounts', 'orders', 'team', 'news',
        'feedback', 'privacy', 'legal', 'compliance', 'reception',
        'purchasing', 'procurement', 'sourcing', 'supplychain', 'vendor',
        'supplier', 'buying', 'enquiry', 'inquiry', 'info-request',
    ];

    private const MIN_QUALITY_SCORE = 25;
    private const SCORE_NAME = 25;
    private const SCORE_EMAIL = 20;
    private const SCORE_DOMAIN_MATCH = 10;
    private const SCORE_TITLE = 15;
    private const SCORE_PHONE = 10;
    private const SCORE_LINKEDIN = 10;

    /**
     * @var array<string, array<int, string>>
     */
    private const DECISION_MAKER_KEYWORDS = [
        'procurement' => ['procurement', 'purchasing', 'sourcing', 'buyer', 'commodity', 'supply chain', 'materials', 'supplychain'],
        'executive' => ['ceo', 'coo', 'cto', 'cfo', 'president', 'founder', 'owner', 'managing director', 'general manager', 'vice president', 'vp', 'director', 'head', 'chief', 'chairman', 'chairperson', 'board member', 'partner'],
        'engineering' => ['engineering', 'engineer', 'technical', 'r&d', 'operations', 'manufacturing', 'quality', 'product development', 'production manager', 'plant manager', 'factory manager', 'process engineer', 'design engineer'],
        'commercial' => ['sales manager', 'business development', 'account manager', 'commercial director', 'sales director', 'regional sales', 'key account', 'customer relationship', 'bdm'],
    ];

    /** @var array<string, int> */
    private const DECISION_MAKER_SCORES = [
        'procurement' => 15,
        'executive' => 12,
        'engineering' => 10,
        'commercial' => 8,
        'other' => 0,
    ];

    /**
     * @return ExtractedContact[]
     */
    public function extract(CrawledDomain $domain): array
    {
        $rawContacts = [];
        $domainName = $domain->getDomain();

        foreach ($domain->getSuccessfulPages() as $page) {
            $html = $page->getHtml();
            $sd = $page->getStructuredData();

            // 1. JSON-LD Person / ContactPoint
            $rawContacts = array_merge($rawContacts, $this->extractFromJsonLd($sd));

            // 2. Team card HTML patterns
            $rawContacts = array_merge($rawContacts, $this->extractFromTeamCards($html));

            // 3. mailto links
            $rawContacts = array_merge($rawContacts, $this->extractFromMailto($html));

            // 4. tel links
            $rawContacts = array_merge($rawContacts, $this->extractFromTel($html));

            // 5. LinkedIn URLs
            $rawContacts = array_merge($rawContacts, $this->extractFromLinkedIn($html));

            // 6. Visible email patterns
            $rawContacts = array_merge($rawContacts, $this->extractFromVisibleEmails($html));

            // 7. Obfuscated email patterns (name [at] domain [dot] com, etc.)
            $rawContacts = array_merge($rawContacts, $this->extractFromObfuscatedEmails($html));
        }

        $preparedContacts = [];
        foreach ($rawContacts as $rawContact) {
            $prepared = $this->prepareContact($rawContact, $domainName);
            if ($prepared !== null) {
                $preparedContacts[] = $prepared;
            }
        }

        // Deduplicate
        $deduped = $this->deduplicate($preparedContacts);

        // Score and build ExtractedContact objects
        $scored = [];
        foreach ($deduped as $raw) {
            $score = $this->scoreContact($raw, $domainName);
            if ($score < self::MIN_QUALITY_SCORE) {
                continue;
            }

            $scored[] = new ExtractedContact(
                $raw['first_name'] ?? null,
                $raw['last_name'] ?? null,
                $raw['job_title'] ?? null,
                $raw['email'] ?? null,
                $raw['phone'] ?? null,
                $raw['linkedin_url'] ?? null,
                $score,
            );
        }

        // Sort by quality descending
        usort($scored, fn(ExtractedContact $a, ExtractedContact $b) => $b->getQualityScore() <=> $a->getQualityScore());

        return $scored;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Extraction sources
    // ──────────────────────────────────────────────────────────────────

    private function extractFromJsonLd(array $structuredData): array
    {
        $contacts = [];

        foreach ($structuredData as $item) {
            $type = $item['@type'] ?? '';

            // Person type
            if ($type === 'Person') {
                $c = [
                    'first_name' => $item['givenName'] ?? null,
                    'last_name' => $item['familyName'] ?? null,
                    'job_title' => $item['jobTitle'] ?? null,
                    'email' => isset($item['email']) ? $this->normalizeEmail($item['email']) : null,
                    'phone' => $item['telephone'] ?? null,
                    'linkedin_url' => null,
                ];
                // Try name if givenName/familyName not set
                if (!$c['first_name'] && !$c['last_name'] && isset($item['name'])) {
                    [$c['first_name'], $c['last_name']] = $this->splitName($item['name']);
                }
                if ($c['first_name'] || $c['email']) {
                    $contacts[] = $c;
                }
            }

            // ContactPoint in Organization
            if (isset($item['contactPoint'])) {
                $cp = $item['contactPoint'];
                if (!\is_array($cp)) {
                    continue;
                }
                // Handle single vs array of contact points
                $points = isset($cp['@type']) ? [$cp] : $cp;
                foreach ($points as $point) {
                    if (!\is_array($point)) {
                        continue;
                    }
                    $email = isset($point['email']) ? $this->normalizeEmail($point['email']) : null;
                    $phone = $point['telephone'] ?? null;
                    if ($email || $phone) {
                        $contacts[] = [
                            'first_name' => null,
                            'last_name' => null,
                            'job_title' => $point['contactType'] ?? null,
                            'email' => $email,
                            'phone' => $phone,
                            'linkedin_url' => null,
                        ];
                    }
                }
            }
        }

        return $contacts;
    }

    private function extractFromTeamCards(string $html): array
    {
        $contacts = [];

        // Strategy 1: Class-based card matching (div, li, article, section, figure, span)
        foreach (self::TEAM_CLASS_KEYWORDS as $keyword) {
            $pattern = '/<(?:div|li|article|section|figure|span)[^>]*class=["\'][^"\']*'
                . preg_quote($keyword, '/')
                . '[^"\']*["\'][^>]*>(.*?)<\/(?:div|li|article|section|figure|span)>/si';

            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $cardHtml) {
                    $contact = $this->parseTeamCard($cardHtml);
                    if ($contact) {
                        $contacts[] = $contact;
                    }
                }
            }
        }

        // Strategy 2: data-* attribute based team members
        // e.g., <div data-team-member="..." data-name="John" data-title="CEO">
        if (preg_match_all(
            '/<(?:div|li|article|section)[^>]*data-(?:team|staff|person|member|employee)(?:-member|-card)?\s*=\s*["\'][^"\']*["\'][^>]*>(.*?)<\/(?:div|li|article|section)>/si',
            $html,
            $matches,
        )) {
            foreach ($matches[1] as $cardHtml) {
                $contact = $this->parseTeamCard($cardHtml);
                if ($contact) {
                    $contacts[] = $contact;
                }
            }
        }

        // Strategy 3: Section with id="team" / id="leadership" etc.
        if (preg_match_all(
            '/<section[^>]*(?:id|data-section)\s*=\s*["\'](?:team|leadership|management|staff|people|our-team|our-people|meet-the-team|executives)["\'][^>]*>(.*?)<\/section>/si',
            $html,
            $matches,
        )) {
            foreach ($matches[1] as $sectionHtml) {
                // Within team sections, look for individual person containers
                if (preg_match_all(
                    '/<(?:div|li|article|figure)[^>]*>(.*?)<\/(?:div|li|article|figure)>/si',
                    $sectionHtml,
                    $innerMatches,
                )) {
                    foreach ($innerMatches[1] as $cardHtml) {
                        // Only parse if it looks like a person card (has name + role)
                        if (preg_match('/<h[1-6][^>]*>/i', $cardHtml) || preg_match('/class=["\'][^"\']*(?:name|title|position|role)[^"\']*["\']/i', $cardHtml)) {
                            $contact = $this->parseTeamCard($cardHtml);
                            if ($contact) {
                                $contacts[] = $contact;
                            }
                        }
                    }
                }
            }
        }

        return $contacts;
    }

    private function extractFromMailto(string $html): array
    {
        $contacts = [];

        if (preg_match_all('/href=["\']mailto:([^"\'?]+)/i', $html, $matches)) {
            foreach ($matches[1] as $email) {
                $email = $this->normalizeEmail($email);
                if ($email !== null) {
                    $contacts[] = [
                        'first_name' => null,
                        'last_name' => null,
                        'job_title' => null,
                        'email' => $email,
                        'phone' => null,
                        'linkedin_url' => null,
                    ];
                }
            }
        }

        return $contacts;
    }

    private function extractFromTel(string $html): array
    {
        $contacts = [];

        if (preg_match_all('/href=["\']tel:([^"\']+)/i', $html, $matches)) {
            foreach ($matches[1] as $phone) {
                $phone = trim($phone);
                if ($phone !== '') {
                    $contacts[] = [
                        'first_name' => null,
                        'last_name' => null,
                        'job_title' => null,
                        'email' => null,
                        'phone' => $phone,
                        'linkedin_url' => null,
                    ];
                }
            }
        }

        return $contacts;
    }

    private function extractFromLinkedIn(string $html): array
    {
        $contacts = [];
        $seenUrls = [];

        // Strategy 1: Traditional href attributes
        if (preg_match_all(
            '/href=["\']([^"\']*linkedin\.com\/in\/[^"\']*)["\'][^>]*>([^<]*)/si',
            $html,
            $matches,
        )) {
            foreach ($matches[1] as $i => $url) {
                $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
                $key = md5($url);
                if (isset($seenUrls[$key])) {
                    continue;
                }
                $seenUrls[$key] = true;

                $linkText = trim(strip_tags($matches[2][$i]));
                $contact = [
                    'first_name' => null,
                    'last_name' => null,
                    'job_title' => null,
                    'email' => null,
                    'phone' => null,
                    'linkedin_url' => $url,
                ];
                if ($linkText !== '' && !preg_match('/linkedin|profile|view|connect|follow|icon/i', $linkText)) {
                    [$contact['first_name'], $contact['last_name']] = $this->splitName($linkText);
                }
                // If no name from link text, try extracting from URL slug
                if ($contact['first_name'] === null) {
                    [$contact['first_name'], $contact['last_name']] = $this->extractNameFromLinkedInUrl($url);
                }
                $contacts[] = $contact;
            }
        }

        // Strategy 2: data-href, data-url, data-profile attributes
        if (preg_match_all(
            '/data-(?:href|url|profile|linkedin)\s*=\s*["\']([^"\']*linkedin\.com\/in\/[^"\']*)["\']/si',
            $html,
            $matches,
        )) {
            foreach ($matches[1] as $url) {
                $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
                $key = md5($url);
                if (isset($seenUrls[$key])) {
                    continue;
                }
                $seenUrls[$key] = true;

                $contact = [
                    'first_name' => null,
                    'last_name' => null,
                    'job_title' => null,
                    'email' => null,
                    'phone' => null,
                    'linkedin_url' => $url,
                ];
                [$contact['first_name'], $contact['last_name']] = $this->extractNameFromLinkedInUrl($url);
                $contacts[] = $contact;
            }
        }

        // Strategy 3: Meta tags and JSON-like content with LinkedIn URLs
        if (preg_match_all(
            '/(?:content|value|data-value)=["\']([^"\']*linkedin\.com\/in\/[^"\']*)["\']/si',
            $html,
            $matches,
        )) {
            foreach ($matches[1] as $url) {
                $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
                $key = md5($url);
                if (isset($seenUrls[$key])) {
                    continue;
                }
                $seenUrls[$key] = true;

                $contact = [
                    'first_name' => null,
                    'last_name' => null,
                    'job_title' => null,
                    'email' => null,
                    'phone' => null,
                    'linkedin_url' => $url,
                ];
                [$contact['first_name'], $contact['last_name']] = $this->extractNameFromLinkedInUrl($url);
                $contacts[] = $contact;
            }
        }

        return $contacts;
    }

    /**
     * Extract first/last name from a LinkedIn profile URL slug.
     * E.g., linkedin.com/in/john-doe → John, Doe
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function extractNameFromLinkedInUrl(string $url): array
    {
        // Extract the slug portion: /in/john-doe-123abc/
        if (preg_match('~linkedin\.com/in/([^/?#]+)~i', $url, $m)) {
            $slug = $m[1];
            // Remove trailing identifiers (e.g., -123abc, -a123b4)
            $slug = preg_replace('/-[a-z0-9]{5,}$/i', '', $slug);
            $slug = preg_replace('/-\d+$/', '', $slug);
            // Split on hyphens and underscores
            $parts = preg_split('/[-_]+/', $slug);
            if (\count($parts) >= 2) {
                $firstName = $this->normalizeNamePart($parts[0]);
                $lastName = $this->normalizeNamePart(implode('-', \array_slice($parts, 1)));
                if ($firstName !== null && $lastName !== null) {
                    return [$firstName, $lastName];
                }
            }
        }
        return [null, null];
    }

    private function extractFromVisibleEmails(string $html): array
    {
        $contacts = [];
        $text = strip_tags($html);

        if (preg_match_all(
            '/\b[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}\b/',
            $text,
            $matches,
        )) {
            foreach ($matches[0] as $email) {
                $email = $this->normalizeEmail($email);
                if ($email !== null) {
                    $contacts[] = [
                        'first_name' => null,
                        'last_name' => null,
                        'job_title' => null,
                        'email' => $email,
                        'phone' => null,
                        'linkedin_url' => null,
                    ];
                }
            }
        }

        return $contacts;
    }

    /**
     * Extract obfuscated emails using anti-spam patterns.
     *
     * Handles common obfuscation techniques:
     *   name [at] domain [dot] com
     *   name(at)domain(dot)com
     *   name {at} domain {dot} com
     *   name AT domain DOT com
     *   name ät domain döt com
     *   name [@] domain [.] com
     *   name (at) domain (dot) com
     */
    private function extractFromObfuscatedEmails(string $html): array
    {
        $contacts = [];
        $text = strip_tags($html);

        // Pattern: localpart [at] domain [dot] tld (with various brackets/words)
        if (preg_match_all(
            '/([a-zA-Z0-9._%+\-]+)\s*(?:\[at\]|\(at\)|\{at\}|\(@\)|\[@\]|\bat\b|ät|\[@\])\s*([a-zA-Z0-9.\-]+)\s*(?:\[dot\]|\(dot\)|\{dot\}|\(\.\)|\[\.\]|\bdot\b|döt|\[\.\])\s*([a-zA-Z]{2,})/i',
            $text,
            $matches,
        )) {
            foreach ($matches[0] as $i => $match) {
                $localPart = trim($matches[1][$i]);
                $domain = trim($matches[2][$i]);
                $tld = trim($matches[3][$i]);
                $email = $this->normalizeEmail($localPart . '@' . $domain . '.' . $tld);
                if ($email !== null) {
                    $contacts[] = [
                        'first_name' => null,
                        'last_name' => null,
                        'job_title' => null,
                        'email' => $email,
                        'phone' => null,
                        'linkedin_url' => null,
                    ];
                }
            }
        }

        // Pattern: localpart [@] domain (dot separated TLD)
        if (preg_match_all(
            '/([a-zA-Z0-9._%+\-]+)\s*(?:\[@\]|\(@\)|\{@\})\s*([a-zA-Z0-9.\-]+)\s*(?:\[dot\]|\(dot\)|\{dot\}|\(\.\)|\[\.\]|\bdot\b)\s*([a-zA-Z]{2,})/i',
            $text,
            $matches,
        )) {
            foreach ($matches[0] as $i => $match) {
                $localPart = trim($matches[1][$i]);
                $domain = trim($matches[2][$i]);
                $tld = trim($matches[3][$i]);
                $email = $this->normalizeEmail($localPart . '@' . $domain . '.' . $tld);
                if ($email !== null) {
                    $contacts[] = [
                        'first_name' => null,
                        'last_name' => null,
                        'job_title' => null,
                        'email' => $email,
                        'phone' => null,
                        'linkedin_url' => null,
                    ];
                }
            }
        }

        // Pattern: image-based fallback — look for alt text containing email patterns
        if (preg_match_all(
            '/<img[^>]*alt=["\']([^"\']+@[^"\']+\.[a-zA-Z]{2,})["\'][^>]*>/si',
            $html,
            $matches,
        )) {
            foreach ($matches[1] as $altText) {
                $email = $this->normalizeEmail(trim($altText));
                if ($email !== null) {
                    $contacts[] = [
                        'first_name' => null,
                        'last_name' => null,
                        'job_title' => null,
                        'email' => $email,
                        'phone' => null,
                        'linkedin_url' => null,
                    ];
                }
            }
        }

        return $contacts;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Team card parser
    // ──────────────────────────────────────────────────────────────────

    private function parseTeamCard(string $cardHtml): ?array
    {
        // Extract name from heading
        $name = null;
        if (preg_match('/<h[1-6][^>]*>\s*([^<]{2,60})\s*<\/h[1-6]>/si', $cardHtml, $m)) {
            $name = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
        }
        if (!$name && preg_match('/<strong[^>]*>\s*([^<]{2,60})\s*<\/strong>/si', $cardHtml, $m)) {
            $name = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
        }
        // Try <span> with name-related class
        if (!$name && preg_match('/<span[^>]*class=["\'][^"\']*(?:name|person-name|member-name|employee-name)[^"\']*["\'][^>]*>\s*([^<]{2,60})\s*<\/span>/si', $cardHtml, $m)) {
            $name = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
        }
        // Try <div> with name-related class
        if (!$name && preg_match('/<div[^>]*class=["\'][^"\']*(?:name|person-name|member-name|employee-name)[^"\']*["\'][^>]*>\s*([^<]{2,60})\s*<\/div>/si', $cardHtml, $m)) {
            $name = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
        }
        if (!$name) {
            return null;
        }

        $nameParts = preg_split('/\s+/', $name);
        if (\count($nameParts) < 2 || \count($nameParts) > 5) {
            return null;
        }

        $contact = [
            'first_name' => $nameParts[0],
            'last_name' => implode(' ', \array_slice($nameParts, 1)),
            'job_title' => null,
            'email' => null,
            'phone' => null,
            'linkedin_url' => null,
        ];

        // Title from class="title/position/role/job-title/subtitle/designation" or data-title/data-position
        $titlePatterns = [
            // Class-based
            '/<[^>]*class=["\'][^"\']*(?:title|position|role|job-title|job_title|subtitle|designation|function|department|role-title|person-title|person-role)[^"\']*["\'][^>]*>([^<]{2,100})<\//si',
            // data-attribute based (modern JS frameworks)
            '/<[^>]*data-(?:title|position|role|job|designation)\s*=\s*["\']([^"\']{2,100})["\'][^>]*>/si',
            // aria-label based (accessibility)
            '/<[^>]*aria-label=["\']([^"\']*(?:ceo|manager|director|engineer|president|sales|procurement|purchasing)[^"\']*)["\'][^>]*>/si',
        ];
        foreach ($titlePatterns as $pattern) {
            if (preg_match($pattern, $cardHtml, $tm)) {
                $candidate = html_entity_decode(trim(strip_tags($tm[1])), ENT_QUOTES, 'UTF-8');
                if ($candidate !== '' && $candidate !== $name && mb_strlen($candidate) < 100) {
                    $contact['job_title'] = $candidate;
                    break;
                }
            }
        }

        // Fallback: first <p> that looks like a job title (doesn't contain generic text)
        if ($contact['job_title'] === null && preg_match('/<p[^>]*>\s*([^<]{3,100})\s*<\/p>/si', $cardHtml, $pm)) {
            $title = html_entity_decode(trim(strip_tags($pm[1])), ENT_QUOTES, 'UTF-8');
            if ($title !== $name && mb_strlen($title) < 80 && !preg_match('/^(tel|phone|email|mobile|fax|call|contact|follow|connect|share|view)/i', $title)) {
                $contact['job_title'] = $title;
            }
        }

        // Last fallback: <small> tag (often used for job titles in Bootstrap/tailwind)
        if ($contact['job_title'] === null && preg_match('/<small[^>]*>\s*([^<]{3,80})\s*<\/small>/si', $cardHtml, $sm)) {
            $title = html_entity_decode(trim(strip_tags($sm[1])), ENT_QUOTES, 'UTF-8');
            if ($title !== $name && !preg_match('/^(tel|phone|email|mobile|fax)/i', $title)) {
                $contact['job_title'] = $title;
            }
        }

        // Email
        if (preg_match('/href=["\']mailto:([^"\'?]+)/i', $cardHtml, $em)) {
            $contact['email'] = $this->normalizeEmail($em[1]);
        }

        // Phone
        if (preg_match('/href=["\']tel:([^"\']+)/i', $cardHtml, $pm)) {
            $contact['phone'] = trim($pm[1]);
        }

        // LinkedIn
        if (preg_match('/href=["\']([^"\']*linkedin\.com\/in\/[^"\']*)["\']/', $cardHtml, $lm)) {
            $contact['linkedin_url'] = html_entity_decode($lm[1], ENT_QUOTES, 'UTF-8');
        }

        return $contact;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Deduplication
    // ──────────────────────────────────────────────────────────────────

    private function deduplicate(array $rawContacts): array
    {
        $seen = [];
        $deduped = [];

        foreach ($rawContacts as $c) {
            $email = $c['email'] ?? null;
            $name = mb_strtolower(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));

            // Skip if same email already seen
            if ($email !== null && isset($seen['email:' . $email])) {
                // Merge additional data into existing contact
                $idx = $seen['email:' . $email];
                $deduped[$idx] = $this->mergeContact($deduped[$idx], $c);
                continue;
            }

            // Skip if same name already seen (and name is non-empty)
            if ($name !== '' && $name !== ' ' && isset($seen['name:' . $name])) {
                $idx = $seen['name:' . $name];
                $deduped[$idx] = $this->mergeContact($deduped[$idx], $c);
                continue;
            }

            $idx = \count($deduped);
            $deduped[] = $c;

            if ($email !== null) {
                $seen['email:' . $email] = $idx;
            }
            if ($name !== '' && $name !== ' ') {
                $seen['name:' . $name] = $idx;
            }
        }

        return array_values($deduped);
    }

    /**
     * Merge additional data from $b into $a, preferring non-null values.
     */
    private function mergeContact(array $a, array $b): array
    {
        foreach (['first_name', 'last_name', 'job_title', 'email', 'phone', 'linkedin_url'] as $field) {
            if (($a[$field] ?? null) === null && ($b[$field] ?? null) !== null) {
                $a[$field] = $b[$field];
            }
        }
        return $a;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Quality scoring
    // ──────────────────────────────────────────────────────────────────

    private function scoreContact(array $raw, string $domainName): int
    {
        $score = 0;

        if (($raw['first_name'] ?? null) && ($raw['last_name'] ?? null)) {
            $score += self::SCORE_NAME;
        }
        if ($raw['email'] ?? null) {
            $score += self::SCORE_EMAIL;
            // Bonus if email matches domain
            if (str_ends_with($raw['email'], '@' . $domainName)) {
                $score += self::SCORE_DOMAIN_MATCH;
            }
        }
        if ($raw['job_title'] ?? null) {
            $score += self::SCORE_TITLE;
            $score += self::DECISION_MAKER_SCORES[$this->classifyDecisionMakerRole($raw['job_title'])] ?? 0;
        }
        if ($raw['phone'] ?? null) {
            $score += self::SCORE_PHONE;
        }
        if ($raw['linkedin_url'] ?? null) {
            $score += self::SCORE_LINKEDIN;
        }

        return $score;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

    private function prepareContact(array $raw, string $domainName): ?array
    {
        $raw['email'] = isset($raw['email']) ? $this->normalizeEmail($raw['email']) : null;
        $raw['job_title'] = isset($raw['job_title']) ? trim((string) $raw['job_title']) : null;
        $raw['phone'] = isset($raw['phone']) ? trim((string) $raw['phone']) : null;
        $raw['linkedin_url'] = isset($raw['linkedin_url']) ? trim((string) $raw['linkedin_url']) : null;

        if ($raw['email'] !== null && $this->isGenericEmail($raw['email'])) {
            $raw['email'] = null;
        }

        $firstName = $raw['first_name'] ?? null;
        $lastName = $raw['last_name'] ?? null;

        if (($firstName === null || $lastName === null) && $raw['email'] !== null) {
            [$derivedFirstName, $derivedLastName] = $this->deriveNameFromEmail($raw['email']);
            $raw['first_name'] ??= $derivedFirstName;
            $raw['last_name'] ??= $derivedLastName;
        }

        if (($raw['first_name'] ?? null) !== null) {
            $raw['first_name'] = $this->normalizeNamePart((string) $raw['first_name']);
        }
        if (($raw['last_name'] ?? null) !== null) {
            $raw['last_name'] = $this->normalizeNamePart((string) $raw['last_name']);
        }

        $hasIdentity = ($raw['first_name'] ?? null) !== null && ($raw['last_name'] ?? null) !== null;
        $hasReachableChannel = ($raw['email'] ?? null) !== null
            || ($raw['phone'] ?? null) !== null
            || ($raw['linkedin_url'] ?? null) !== null;

        if (!$hasIdentity && !$hasReachableChannel) {
            return null;
        }

        if (($raw['email'] ?? null) !== null && !str_ends_with($raw['email'], '@' . $domainName) && !$hasIdentity) {
            return null;
        }

        return $raw;
    }

    private function normalizeEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $email;
    }

    private function isGenericEmail(string $email): bool
    {
        $localPart = explode('@', $email, 2)[0] ?? '';
        return in_array($localPart, self::GENERIC_EMAIL_PREFIXES, true);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function deriveNameFromEmail(string $email): array
    {
        $localPart = explode('@', $email, 2)[0] ?? '';
        $localPart = explode('+', $localPart, 2)[0];
        $tokens = preg_split('/[._-]+/', $localPart) ?: [];
        $tokens = array_values(array_filter($tokens, function (string $token): bool {
            return preg_match('/^[a-z]{2,20}$/i', $token) === 1
                && !in_array(strtolower($token), self::GENERIC_EMAIL_PREFIXES, true);
        }));

        if (count($tokens) < 2) {
            return [null, null];
        }

        $firstName = $this->normalizeNamePart($tokens[0]);
        $lastName = $this->normalizeNamePart(implode(' ', array_slice($tokens, 1)));

        return [$firstName, $lastName];
    }

    private function normalizeNamePart(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/\d/', $value) === 1) {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', $value);
        if ($value === null || $value === '') {
            return null;
        }

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    private function classifyDecisionMakerRole(?string $jobTitle): string
    {
        if ($jobTitle === null || trim($jobTitle) === '') {
            return 'other';
        }

        $jobTitleLower = mb_strtolower($jobTitle);

        foreach (self::DECISION_MAKER_KEYWORDS as $role => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($jobTitleLower, $keyword)) {
                    return $role;
                }
            }
        }

        return 'other';
    }

    /**
     * @return array{0: ?string, 1: ?string} [firstName, lastName]
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName));
        if (\count($parts) < 2) {
            return [$fullName, null];
        }
        return [$parts[0], implode(' ', \array_slice($parts, 1))];
    }
}
