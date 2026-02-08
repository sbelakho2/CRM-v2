#!/usr/bin/env php
<?php
/**
 * Test script: Crawl 10 real company websites and evaluate contact quality.
 *
 * Usage: php scripts/test_webcrawler_contacts.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;

// ── Instantiate DeepScrapingService directly for testing ─────────────────
// We need the HeadlessBrowserService, but let's use a simple HTTP client fallback

class SimpleTestBrowser
{
    private $httpClient;
    
    public function __construct()
    {
        // Use file_get_contents with a proper User-Agent
    }
    
    public function fetchPage(string $url, bool $useHeadless = false): array
    {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n" .
                           "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
                           "Accept-Language: en-US,en;q=0.5\r\n",
                'timeout' => 15,
                'follow_location' => true,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        
        $html = @file_get_contents($url, false, $context);
        
        if ($html === false) {
            return ['success' => false, 'error' => 'Failed to fetch', 'html' => '', 'method' => 'static'];
        }
        
        return ['success' => true, 'html' => $html, 'method' => 'static', 'error' => null];
    }
}

// ── Test companies - MID-MARKET manufacturers more likely to list contacts ──
$testCompanies = [
    ['name' => 'MC Assembly', 'url' => 'https://www.mcassembly.com'],
    ['name' => 'InterConnect Wiring', 'url' => 'https://www.interconnect-wiring.com'],
    ['name' => 'Sanmina', 'url' => 'https://www.sanmina.com'],
    ['name' => 'Celestica', 'url' => 'https://www.celestica.com'],
    ['name' => 'Benchmark Electronics', 'url' => 'https://www.bench.com'],
    ['name' => 'IEC Electronics', 'url' => 'https://www.iec-electronics.com'],
    ['name' => 'SMTC Corporation', 'url' => 'https://www.smtc.com'],
    ['name' => 'Creation Technologies', 'url' => 'https://www.creationtech.com'],
    ['name' => 'Plexus Corp', 'url' => 'https://www.plexus.com'],
    ['name' => 'Fabrinet', 'url' => 'https://www.fabrinet.com'],
];

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║           WEBCRAWLER CONTACT EXTRACTION TEST                       ║\n";
echo "║           Testing " . count($testCompanies) . " companies for contact quality                   ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

// Include the DeepScrapingService
require_once __DIR__ . '/../src/Service/DeepScrapingService.php';

// We can't easily instantiate the full service without DI, so let's test
// the extraction logic directly by fetching pages and running the crawler

$browser = new SimpleTestBrowser();
$totalContacts = 0;
$totalEmails = 0;
$totalWithTitle = 0;
$totalDecisionMakers = 0;
$allResults = [];

foreach ($testCompanies as $idx => $company) {
    $num = $idx + 1;
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "  [{$num}/10] {$company['name']} — {$company['url']}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    // Fetch homepage
    $pageResult = $browser->fetchPage($company['url']);
    if (!$pageResult['success']) {
        echo "  ❌ Failed to fetch: {$pageResult['error']}\n";
        continue;
    }
    
    $html = $pageResult['html'];
    $crawler = new Crawler($html);
    
    // ── Extract JSON-LD ──
    $jsonLdContacts = [];
    try {
        $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$jsonLdContacts) {
            $json = json_decode($node->text(), true);
            if (!is_array($json)) return;
            
            $entities = [];
            if (isset($json['@graph'])) {
                $entities = $json['@graph'];
            } else {
                $entities = [$json];
            }
            
            foreach ($entities as $entity) {
                $type = $entity['@type'] ?? '';
                if (in_array($type, ['Person', 'schema:Person'])) {
                    $jsonLdContacts[] = $entity;
                }
                // Check Organization for employee/founder arrays
                if (in_array($type, ['Organization', 'Corporation', 'LocalBusiness'])) {
                    foreach (['employee', 'founder', 'member'] as $rel) {
                        if (!empty($entity[$rel])) {
                            $people = is_array($entity[$rel]) && isset($entity[$rel][0]) ? $entity[$rel] : [$entity[$rel]];
                            foreach ($people as $p) {
                                if (is_array($p) && !empty($p['name'])) {
                                    $jsonLdContacts[] = $p;
                                }
                            }
                        }
                    }
                }
            }
        });
    } catch (\Exception $e) {}
    
    echo "  📦 JSON-LD Person entities: " . count($jsonLdContacts) . "\n";
    foreach ($jsonLdContacts as $jc) {
        echo "     • " . ($jc['name'] ?? 'unnamed') . " — " . ($jc['jobTitle'] ?? 'no title') . "\n";
    }
    
    // ── Extract Schema.org microdata ──
    $microdataContacts = [];
    try {
        $crawler->filter('[itemtype*="schema.org/Person"]')->each(function (Crawler $node) use (&$microdataContacts) {
            $name = $node->filter('[itemprop="name"]')->count() > 0 ? trim($node->filter('[itemprop="name"]')->text()) : null;
            $title = $node->filter('[itemprop="jobTitle"]')->count() > 0 ? trim($node->filter('[itemprop="jobTitle"]')->text()) : null;
            if ($name) {
                $microdataContacts[] = ['name' => $name, 'title' => $title];
            }
        });
    } catch (\Exception $e) {}
    
    echo "  🏷️  Microdata Person entities: " . count($microdataContacts) . "\n";
    foreach ($microdataContacts as $mc) {
        echo "     • " . $mc['name'] . " — " . ($mc['title'] ?? 'no title') . "\n";
    }
    
    // ── Extract emails ──
    $emails = [];
    $crawler->filter('a[href^="mailto:"]')->each(function (Crawler $node) use (&$emails) {
        $email = str_replace('mailto:', '', explode('?', $node->attr('href'))[0]);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = strtolower($email);
        }
    });
    $emails = array_unique($emails);
    echo "  📧 Mailto emails: " . count($emails) . "\n";
    foreach (array_slice($emails, 0, 5) as $e) {
        echo "     • {$e}\n";
    }
    
    // ── Extract phones ──
    $phones = [];
    $crawler->filter('a[href^="tel:"]')->each(function (Crawler $node) use (&$phones) {
        $phones[] = str_replace('tel:', '', $node->attr('href'));
    });
    $phones = array_unique($phones);
    echo "  📞 Tel links: " . count($phones) . "\n";
    
    // ── Extract LinkedIn links ──
    $linkedinLinks = [];
    try {
        $crawler->filter('a[href*="linkedin.com"]')->each(function (Crawler $node) use (&$linkedinLinks) {
            $href = $node->attr('href') ?? '';
            if (str_contains($href, '/in/')) {
                $linkedinLinks[] = ['url' => $href, 'text' => trim($node->text())];
            }
        });
    } catch (\Exception $e) {}
    echo "  🔗 LinkedIn /in/ profile links: " . count($linkedinLinks) . "\n";
    foreach ($linkedinLinks as $li) {
        echo "     • " . ($li['text'] ?: '(no text)') . " → " . $li['url'] . "\n";
    }
    
    // ── Extract team sections ──
    $teamCards = 0;
    foreach (['.team-member', '.team-card', '.member', '.staff-member', '.leadership-card', '.person', '.bio', '.executive'] as $sel) {
        try {
            $teamCards += $crawler->filter($sel)->count();
        } catch (\Exception $e) {}
    }
    echo "  👥 Team card elements: {$teamCards}\n";
    
    // ── Look for contact/about/team page links ──
    $contactPages = [];
    $patterns = ['contact', 'about', 'team', 'leadership', 'management', 'people'];
    try {
        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$contactPages, $patterns) {
            $href = strtolower($node->attr('href') ?? '');
            $text = strtolower(trim($node->text()));
            foreach ($patterns as $p) {
                if (str_contains($href, $p) || str_contains($text, $p)) {
                    $contactPages[$href] = trim($node->text());
                    break;
                }
            }
        });
    } catch (\Exception $e) {}
    echo "  🔗 Contact/About/Team page links: " . count($contactPages) . "\n";
    foreach (array_slice($contactPages, 0, 5, true) as $href => $text) {
        echo "     • \"{$text}\" → {$href}\n";
    }
    
    // ── Now scrape the first contact/about/team page we find ──
    $contactPageUrl = null;
    $baseUrl = rtrim($company['url'], '/');
    
    // Prioritize team/leadership pages
    foreach ($contactPages as $href => $text) {
        if (str_contains($href, 'team') || str_contains($href, 'leadership') || str_contains($href, 'management') || str_contains($href, 'people')) {
            $contactPageUrl = str_starts_with($href, 'http') ? $href : $baseUrl . '/' . ltrim($href, '/');
            break;
        }
    }
    // Fallback to about/contact
    if (!$contactPageUrl) {
        foreach ($contactPages as $href => $text) {
            if (str_contains($href, 'about') || str_contains($href, 'contact')) {
                $contactPageUrl = str_starts_with($href, 'http') ? $href : $baseUrl . '/' . ltrim($href, '/');
                break;
            }
        }
    }
    
    $subpageContacts = [];
    if ($contactPageUrl) {
        echo "\n  📄 Scraping subpage: {$contactPageUrl}\n";
        usleep(800000); // Polite delay
        
        $subResult = $browser->fetchPage($contactPageUrl);
        if ($subResult['success']) {
            $subCrawler = new Crawler($subResult['html']);
            
            // JSON-LD on subpage
            try {
                $subCrawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$subpageContacts) {
                    $json = json_decode($node->text(), true);
                    if (!is_array($json)) return;
                    $entities = isset($json['@graph']) ? $json['@graph'] : [$json];
                    foreach ($entities as $entity) {
                        $type = $entity['@type'] ?? '';
                        if (in_array($type, ['Person', 'schema:Person'])) {
                            $subpageContacts[] = [
                                'name' => $entity['name'] ?? ($entity['givenName'] ?? '') . ' ' . ($entity['familyName'] ?? ''),
                                'title' => $entity['jobTitle'] ?? null,
                                'email' => isset($entity['email']) ? str_replace('mailto:', '', $entity['email']) : null,
                                'linkedin' => null,
                                'source' => 'JSON-LD (subpage)',
                            ];
                        }
                    }
                });
            } catch (\Exception $e) {}
            
            // Team cards on subpage
            foreach (['.team-member', '.team-card', '.card', '.member', '.bio', '.executive', '.person', '[class*="team"]', '[class*="leader"]'] as $sel) {
                try {
                    $subCrawler->filter($sel)->each(function (Crawler $card) use (&$subpageContacts) {
                        $name = null;
                        foreach (['h2', 'h3', 'h4', '.name', '[class*="name"]', 'strong'] as $ns) {
                            try {
                                if ($card->filter($ns)->count() > 0) {
                                    $candidate = trim($card->filter($ns)->first()->text());
                                    if (strlen($candidate) > 2 && strlen($candidate) < 60 && preg_match('/^[A-Z][a-z]+\s+[A-Z]/', $candidate)) {
                                        $name = $candidate;
                                        break;
                                    }
                                }
                            } catch (\Exception $e) {}
                        }
                        if (!$name) return;
                        
                        $title = null;
                        foreach (['.title', '.position', '.role', '[class*="title"]', '[class*="position"]', 'p'] as $ts) {
                            try {
                                if ($card->filter($ts)->count() > 0) {
                                    $candidate = trim($card->filter($ts)->first()->text());
                                    if ($candidate !== $name && strlen($candidate) > 2 && strlen($candidate) < 100) {
                                        $title = $candidate;
                                        break;
                                    }
                                }
                            } catch (\Exception $e) {}
                        }
                        
                        $email = null;
                        try {
                            if ($card->filter('a[href^="mailto:"]')->count() > 0) {
                                $email = strtolower(str_replace('mailto:', '', explode('?', $card->filter('a[href^="mailto:"]')->attr('href'))[0]));
                            }
                        } catch (\Exception $e) {}
                        
                        $linkedin = null;
                        try {
                            if ($card->filter('a[href*="linkedin.com/in/"]')->count() > 0) {
                                $linkedin = $card->filter('a[href*="linkedin.com/in/"]')->attr('href');
                            }
                        } catch (\Exception $e) {}
                        
                        $subpageContacts[] = [
                            'name' => $name,
                            'title' => $title,
                            'email' => $email,
                            'linkedin' => $linkedin,
                            'source' => 'Team card (subpage)',
                        ];
                    });
                } catch (\Exception $e) {}
            }
            
            // Emails on subpage
            $subCrawler->filter('a[href^="mailto:"]')->each(function (Crawler $node) use (&$emails) {
                $email = str_replace('mailto:', '', explode('?', $node->attr('href'))[0]);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = strtolower($email);
                }
            });
            $emails = array_unique($emails);
        }
    }
    
    echo "\n  📊 SUBPAGE CONTACTS: " . count($subpageContacts) . "\n";
    foreach ($subpageContacts as $sc) {
        $badge = '';
        $titleLower = strtolower($sc['title'] ?? '');
        $decisionKeywords = ['procurement', 'purchasing', 'sourcing', 'supply chain', 'buyer', 'director', 'vp', 'president', 'chief', 'ceo', 'cto', 'coo', 'head', 'engineering', 'manufacturing', 'operations'];
        foreach ($decisionKeywords as $kw) {
            if (str_contains($titleLower, $kw)) {
                $badge = ' ⭐ DECISION-MAKER';
                $totalDecisionMakers++;
                break;
            }
        }
        
        echo "     • " . $sc['name'];
        if ($sc['title']) echo " — " . $sc['title'];
        if ($sc['email']) echo " <" . $sc['email'] . ">";
        if ($sc['linkedin']) echo " [LinkedIn]";
        echo $badge . "\n";
        $totalContacts++;
        if (!empty($sc['title'])) $totalWithTitle++;
    }
    
    $allResults[] = [
        'company' => $company['name'],
        'homepage_jsonld' => count($jsonLdContacts),
        'homepage_microdata' => count($microdataContacts),
        'emails' => count($emails),
        'subpage_contacts' => count($subpageContacts),
        'contacts' => $subpageContacts,
    ];
    
    // Polite delay
    usleep(1500000); // 1.5s between companies
}

// ── Summary ──
echo "\n\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║                        RESULTS SUMMARY                            ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

echo "  Companies tested:       " . count($testCompanies) . "\n";
echo "  Total contacts found:   {$totalContacts}\n";
echo "  With job title:         {$totalWithTitle}\n";
echo "  Decision-makers:        {$totalDecisionMakers}\n";
echo "  Total emails:           {$totalEmails}\n\n";

foreach ($allResults as $r) {
    $indicator = $r['subpage_contacts'] > 0 ? '✅' : '❌';
    echo "  {$indicator} {$r['company']}: ";
    echo "{$r['subpage_contacts']} contacts, ";
    echo "{$r['emails']} emails, ";
    echo "{$r['homepage_jsonld']} JSON-LD, ";
    echo "{$r['homepage_microdata']} microdata\n";
}

echo "\n";
