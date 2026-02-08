#!/usr/bin/env php
<?php
/**
 * Run EMS-buyer company discovery via Google Custom Search API.
 *
 * Directly instantiates the services (no Symfony container needed) and
 * persists results through Doctrine.
 *
 * Usage:  php scripts/run_discovery.php [region]
 * Example: php scripts/run_discovery.php MA     — Morocco only
 *          php scripts/run_discovery.php         — all regions
 */

use App\Kernel;
use App\Service\GoogleSearchService;
use App\Service\WebCrawler\GoogleDorkService;
use App\Service\WebCrawler\CompanyDiscoveryService;
use App\Service\CountryService;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

// Boot Symfony kernel to get Doctrine
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool)($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();
$container = $kernel->getContainer();

$em = $container->get('doctrine')->getManager();
$companyRepo = $em->getRepository(\App\Entity\Company::class);

// Build logger
$logger = new Logger('discovery');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Build services
$httpClient = HttpClient::create();
$apiKey = $_SERVER['GOOGLE_API_KEY'] ?? $_ENV['GOOGLE_API_KEY'] ?? '';
$engineId = $_SERVER['GOOGLE_SEARCH_ENGINE_ID'] ?? $_ENV['GOOGLE_SEARCH_ENGINE_ID'] ?? '';

$searchService = new GoogleSearchService($httpClient, $logger, $apiKey, $engineId);
$dorkService = new GoogleDorkService($httpClient, $logger, $searchService);
$countryService = new CountryService();

$discovery = new CompanyDiscoveryService(
    $em,
    $companyRepo,
    $dorkService,
    $logger,
    $countryService
);

$regionFilter = $argv[1] ?? null;

// ── Strategic region/sector combos ──────────────────────────────────
// Paid tier = 2000 queries/day.
// Each discoverCompanies() call fires ~12 queries (4 cross-sector + ~6 sector + ~2 location).
// So 12 combos ≈ 144 queries + LinkedIn/homepage verification.
// We pick 2 sectors per top region for broader coverage.

$plan = [
    // ── Morocco ──────────────────────────────────────────────────────
    'MA-1'  => ['sector' => 'Automotive',   'location' => 'Tanger Free Zone, Morocco'],
    'MA-2'  => ['sector' => 'Aerospace',    'location' => 'Casablanca, Morocco'],
    // ── United States ────────────────────────────────────────────────
    'US-1'  => ['sector' => 'Aerospace',    'location' => 'Texas'],
    'US-2'  => ['sector' => 'Medical',      'location' => 'Massachusetts'],
    'US-3'  => ['sector' => 'Defense',      'location' => 'Virginia'],
    'US-4'  => ['sector' => 'Data Center',  'location' => 'California'],
    'US-5'  => ['sector' => 'Consumer Electronics', 'location' => 'California'],
    'US-6'  => ['sector' => 'Telecom',      'location' => 'USA'],
    // ── Europe ───────────────────────────────────────────────────────
    'EU-1'  => ['sector' => 'Automotive',   'location' => 'Germany'],
    'EU-2'  => ['sector' => 'Industrial',   'location' => 'Netherlands'],
    'EU-3'  => ['sector' => 'Marine',       'location' => 'Norway'],
    'EU-4'  => ['sector' => 'Power Electronics', 'location' => 'Germany'],
    'EU-5'  => ['sector' => 'Rail',         'location' => 'Germany'],
    'EU-6'  => ['sector' => 'HVAC',         'location' => 'Germany'],
    'EU-7'  => ['sector' => 'Telecom',      'location' => 'Sweden'],
    // ── United Kingdom ───────────────────────────────────────────────
    'GB-1'  => ['sector' => 'Aerospace',    'location' => 'England'],
    'GB-2'  => ['sector' => 'Renewables',   'location' => 'Scotland'],
    'GB-3'  => ['sector' => 'Defense',      'location' => 'England'],
    'GB-4'  => ['sector' => 'Rail',         'location' => 'England'],
    // ── Egypt ────────────────────────────────────────────────────────
    'EG-1'  => ['sector' => 'Industrial',   'location' => 'Cairo, Egypt'],
    'EG-2'  => ['sector' => 'Automotive',   'location' => '6th of October City, Egypt'],
    'EG-3'  => ['sector' => 'HVAC',         'location' => 'Cairo, Egypt'],
    // ── GCC / Gulf ───────────────────────────────────────────────────
    'GCC-1' => ['sector' => 'Aerospace',    'location' => 'Dubai, UAE'],
    'GCC-2' => ['sector' => 'Renewables',   'location' => 'Riyadh, Saudi Arabia'],
    'GCC-3' => ['sector' => 'Data Center',  'location' => 'Dubai, UAE'],
    'GCC-4' => ['sector' => 'Telecom',      'location' => 'Riyadh, Saudi Arabia'],
];

if ($regionFilter) {
    $regionFilter = strtoupper($regionFilter);
    // Allow filtering by region prefix (e.g. "MA" matches "MA-1" and "MA-2")
    $filtered = [];
    foreach ($plan as $key => $spec) {
        if (str_starts_with($key, $regionFilter)) {
            $filtered[$key] = $spec;
        }
    }
    if (empty($filtered)) {
        fwrite(STDERR, "Unknown region: {$regionFilter}. Valid prefixes: MA, US, EU, GB, EG, GCC\n");
        exit(1);
    }
    $plan = $filtered;
}

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  EMS Buyer Discovery — Google Custom Search API                ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$totalFound = 0;

foreach ($plan as $region => $spec) {
    $sector = $spec['sector'];
    $location = $spec['location'];
    echo "──── {$region}: {$sector} in {$location} ────\n";
    
    try {
        $companies = $discovery->discoverCompanies($sector, $location);
        $count = count($companies);
        $totalFound += $count;

        echo "  ✅ Found {$count} new companies\n";
        foreach ($companies as $i => $company) {
            $name = $company->getName();
            $web  = $company->getWebsite() ?? '(no website)';
            $sec  = $company->getSector() ?? '?';
            $li   = $company->getLinkedinCompanyUrl() ? '🔗 LI' : '';
            $addr = $company->getAddress() ? '📍' : '';
            $notes = $company->getNotes() ? '📝' : '';
            echo "     " . ($i + 1) . ". {$name}  —  {$web}  [{$sec}] {$li} {$addr} {$notes}\n";
        }
    } catch (\Throwable $e) {
        echo "  ❌ Error: {$e->getMessage()}\n";
    }

    echo "\n";
    // Rate-limit between regions
    if (count($plan) > 1) {
        sleep(2);
    }
}

echo "════════════════════════════════════════════════════════════════════\n";
echo "Total new companies saved: {$totalFound}\n";
echo "════════════════════════════════════════════════════════════════════\n";

$kernel->shutdown();
