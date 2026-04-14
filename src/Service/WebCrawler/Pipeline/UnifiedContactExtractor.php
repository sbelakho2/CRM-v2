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
    /** Team card CSS class keywords. */
    private const TEAM_CLASS_KEYWORDS = [
        'team-member', 'team-card', 'staff-member', 'member-card',
        'leadership', 'person-card', 'profile-card', 'executive',
        'bio-card', 'team_member', 'team_card', 'staff_card',
    ];

    private const GENERIC_EMAIL_PREFIXES = [
        'info', 'sales', 'contact', 'support', 'admin', 'hr', 'marketing',
        'webmaster', 'noreply', 'no-reply', 'office', 'careers', 'jobs',
        'press', 'media', 'general', 'enquiries', 'hello', 'service',
        'help', 'billing', 'accounts', 'orders', 'team', 'news',
        'feedback', 'privacy', 'legal', 'compliance', 'reception',
    ];

    private const MIN_QUALITY_SCORE = 30;
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
        'procurement' => ['procurement', 'purchasing', 'sourcing', 'buyer', 'commodity', 'supply chain', 'materials'],
        'executive' => ['ceo', 'coo', 'cto', 'cfo', 'president', 'founder', 'owner', 'managing director', 'general manager', 'vice president', 'vp', 'director', 'head', 'chief'],
        'engineering' => ['engineering', 'engineer', 'technical', 'r&d', 'operations', 'manufacturing', 'quality', 'product development'],
    ];

    /** @var array<string, int> */
    private const DECISION_MAKER_SCORES = [
        'procurement' => 15,
        'executive' => 12,
        'engineering' => 10,
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

        foreach (self::TEAM_CLASS_KEYWORDS as $keyword) {
            $pattern = '/<(?:div|li|article)[^>]*class=["\'][^"\']*'
                . preg_quote($keyword, '/')
                . '[^"\']*["\'][^>]*>(.*?)<\/(?:div|li|article)>/si';

            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $cardHtml) {
                    $contact = $this->parseTeamCard($cardHtml);
                    if ($contact) {
                        $contacts[] = $contact;
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

        if (preg_match_all(
            '/href=["\']([^"\']*linkedin\.com\/in\/[^"\']*)["\'][^>]*>([^<]*)/si',
            $html,
            $matches,
        )) {
            foreach ($matches[1] as $i => $url) {
                $linkText = trim(strip_tags($matches[2][$i]));
                $contact = [
                    'first_name' => null,
                    'last_name' => null,
                    'job_title' => null,
                    'email' => null,
                    'phone' => null,
                    'linkedin_url' => html_entity_decode($url, ENT_QUOTES, 'UTF-8'),
                ];
                if ($linkText !== '' && !preg_match('/linkedin|profile|view/i', $linkText)) {
                    [$contact['first_name'], $contact['last_name']] = $this->splitName($linkText);
                }
                $contacts[] = $contact;
            }
        }

        return $contacts;
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

    // ──────────────────────────────────────────────────────────────────
    //  Team card parser
    // ──────────────────────────────────────────────────────────────────

    private function parseTeamCard(string $cardHtml): ?array
    {
        // Extract name from heading
        $name = null;
        if (preg_match('/<h[2-6][^>]*>\s*([^<]{2,60})\s*<\/h[2-6]>/si', $cardHtml, $m)) {
            $name = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
        }
        if (!$name && preg_match('/<strong[^>]*>\s*([^<]{2,60})\s*<\/strong>/si', $cardHtml, $m)) {
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

        // Title from class="title/position/role" or first <p>
        if (preg_match(
            '/<[^>]*class=["\'][^"\']*(?:title|position|role|job)[^"\']*["\'][^>]*>([^<]{2,100})<\//si',
            $cardHtml,
            $tm,
        )) {
            $contact['job_title'] = html_entity_decode(trim(strip_tags($tm[1])), ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/<p[^>]*>\s*([^<]{3,100})\s*<\/p>/si', $cardHtml, $pm)) {
            $title = html_entity_decode(trim(strip_tags($pm[1])), ENT_QUOTES, 'UTF-8');
            if ($title !== $name && mb_strlen($title) < 80) {
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
