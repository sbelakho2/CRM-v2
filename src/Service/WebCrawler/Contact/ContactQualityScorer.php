<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Contact;

/**
 * Scores and deduplicates contacts from multiple sources.
 *
 * Scoring factors:
 *  - Role relevance (procurement > engineering > C-suite > generic)
 *  - Data completeness (email, phone, LinkedIn URL, job title)
 *  - Source reliability (website > LinkedIn > snippet-derived)
 *  - Region match (contact's location vs. target region)
 */
final class ContactQualityScorer
{
    // ──────────────────────────────────────────────────
    // Constants
    // ──────────────────────────────────────────────────

    /**
     * Source reliability weights.
     */
    private const SOURCE_WEIGHTS = [
        'schema_org'        => 30,  // Structured data from website
        'vcard'             => 28,  // Microformat data
        'team_page'         => 25,  // Dedicated team/leadership page
        'mailto_link'       => 22,  // mailto: link near person name
        'linkedin_title'    => 20,  // LinkedIn profile title parse
        'linkedin_slug'     => 12,  // LinkedIn URL slug parse
        'email_derived'     => 10,  // Name derived from email pattern
        'web_search'        => 8,   // From general web search
        'snippet_text'      => 5,   // From Google snippet text patterns
    ];

    /**
     * Completeness bonuses.
     */
    private const FIELD_BONUSES = [
        'email'        => 20,
        'phone'        => 15,
        'linkedin_url' => 10,
        'job_title'    => 10,
        'first_name'   => 5,
        'last_name'    => 5,
    ];

    /**
     * Minimum score to accept a contact.
     */
    public const MIN_QUALITY_SCORE = 25;

    // ──────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────

    /**
     * Score a single contact.
     *
     * @return int Quality score (higher = better)
      * @param array<string|int, mixed> $contact
     */
    public function score(array $contact): int
    {
        $score = 0;

        // 1. Role relevance (0–100 from LinkedInProfileParser or custom)
        $score += (int) ($contact['role_score'] ?? 0);

        // 2. Source reliability
        $source = $contact['source'] ?? 'snippet_text';
        $score += self::SOURCE_WEIGHTS[$source] ?? 5;

        // 3. Data completeness
        foreach (self::FIELD_BONUSES as $field => $bonus) {
            if (!empty($contact[$field])) {
                $score += $bonus;
            }
        }

        // 4. Email domain match (bonus if email matches company domain)
        if (!empty($contact['email']) && !empty($contact['company_domain'])) {
            $emailDomain = strtolower(substr($contact['email'], strrpos($contact['email'], '@') + 1));
            $companyDomain = strtolower($contact['company_domain']);
            if (str_contains($emailDomain, $companyDomain) || str_contains($companyDomain, $emailDomain)) {
                $score += 15; // Strong signal: email belongs to the company
            }
        }

        return $score;
    }

    /**
     * Score and sort a list of contacts. Adds 'quality_score' to each.
     *
     * @param list<array> $contacts
     * @return list<array> Sorted descending by quality_score
     */
    public function scoreAndSort(array $contacts): array
    {
        foreach ($contacts as &$c) {
            $c['quality_score'] = $this->score($c);
        }
        unset($c);

        usort($contacts, fn(array $a, array $b) => $b['quality_score'] <=> $a['quality_score']);

        return $contacts;
    }

    /**
     * Deduplicate contacts by matching first+last name or email.
     * When duplicates are found, merge the richer record.
     *
     * @param list<array> $contacts Already scored
     * @return list<array> Deduplicated
     */
    public function deduplicate(array $contacts): array
    {
        /** @var array<string, array> $seen key=normalized identity */
        $seen = [];

        foreach ($contacts as $c) {
            $keys = $this->getDedupeKeys($c);

            $merged = false;
            foreach ($keys as $key) {
                if (isset($seen[$key])) {
                    // Merge: keep the richer record
                    $seen[$key] = $this->mergeContacts($seen[$key], $c);
                    $merged = true;
                    break;
                }
            }

            if (!$merged) {
                // Register all keys pointing to this contact
                $primary = $keys[0] ?? spl_object_hash((object) $c);
                $seen[$primary] = $c;
                foreach (array_slice($keys, 1) as $altKey) {
                    $seen[$altKey] = &$seen[$primary];
                }
            }
        }

        // Deduplicate by reference (multiple keys may point to same record)
        $unique = [];
        $seenIds = [];
        foreach ($seen as $c) {
            $id = ($c['first_name'] ?? '') . '|' . ($c['last_name'] ?? '') . '|' . ($c['email'] ?? '');
            if (!isset($seenIds[$id])) {
                $seenIds[$id] = true;
                $unique[] = $c;
            }
        }

        return $unique;
    }

    /**
     * Full pipeline: score → deduplicate → filter → sort → limit.
     *
     * @param list<array> $contacts
     * @param int $maxContacts Maximum contacts to return
     * @param int $minScore Minimum quality score
     * @return list<array>
     */
    public function pipeline(array $contacts, int $maxContacts = 5, int $minScore = self::MIN_QUALITY_SCORE): array
    {
        $scored = $this->scoreAndSort($contacts);
        $deduped = $this->deduplicate($scored);

        // Filter by minimum score
        $filtered = array_filter($deduped, fn(array $c) => ($c['quality_score'] ?? 0) >= $minScore);
        $filtered = array_values($filtered);

        // Re-sort (dedup may have changed scores)
        usort($filtered, fn(array $a, array $b) => ($b['quality_score'] ?? 0) <=> ($a['quality_score'] ?? 0));

        return array_slice($filtered, 0, $maxContacts);
    }

    // ──────────────────────────────────────────────────
    // Internal
    // ──────────────────────────────────────────────────

    /**
     * Get deduplication keys for a contact.
     *
     * @return list<string>
      * @param array<string|int, mixed> $c
     */
    private function getDedupeKeys(array $c): array
    {
        $keys = [];

        // Name-based key
        $fn = mb_strtolower(trim($c['first_name'] ?? ''));
        $ln = mb_strtolower(trim($c['last_name'] ?? ''));
        if ($fn !== '' && $ln !== '') {
            $keys[] = "name:$fn|$ln";
        }

        // Email key
        $email = strtolower(trim($c['email'] ?? ''));
        if ($email !== '') {
            $keys[] = "email:$email";
        }

        // LinkedIn URL key
        $li = strtolower(trim($c['linkedin_url'] ?? ''));
        if ($li !== '') {
            // Normalize: strip trailing hash
            $li = preg_replace('/[?#].*$/', '', $li);
            $keys[] = "li:$li";
        }

        return $keys;
    }

    /**
     * Merge two contact records, keeping the richer data from each.
      * @param array<string|int, mixed> $existing
 * @param array<string|int, mixed> $new
     */
    private function mergeContacts(array $existing, array $new): array
    {
        $mergeFields = ['email', 'phone', 'linkedin_url', 'job_title', 'location', 'company_mentioned'];

        foreach ($mergeFields as $field) {
            if (empty($existing[$field]) && !empty($new[$field])) {
                $existing[$field] = $new[$field];
            }
        }

        // Keep the higher quality score
        $existing['quality_score'] = max(
            $existing['quality_score'] ?? 0,
            $new['quality_score'] ?? 0,
        );

        // Keep the higher role score
        $existing['role_score'] = max(
            $existing['role_score'] ?? 0,
            $new['role_score'] ?? 0,
        );

        return $existing;
    }
}
