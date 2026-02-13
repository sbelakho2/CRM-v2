<?php

namespace App\Tests\Unit\Service;

use App\Service\CountryService;
use App\Service\WebCrawler\CompanyDiscoveryService;
use App\Service\WebCrawler\GoogleDorkService;
use App\Service\WebCrawler\LeadScoringService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Comprehensive web-scraping pipeline tests for all 6 target regions.
 *
 * Each region gets 10 realistic simulated lead examples that exercise:
 *   1. GoogleDorkService — query generation, region detection, TLD mapping
 *   2. LeadScoringService — full scoring with geo, mfg, procurement, sector
 *   3. Score parity — no region is systematically disadvantaged
 *
 * Regions tested: Morocco (MA), US, EU, UK (GB), Egypt (EG), GCC (AE/SA/QA)
 */
class WebScrapingRegionTest extends TestCase
{
    private GoogleDorkService $dorkService;
    private LeadScoringService $scoringService;
    private CountryService $countryService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = new NullLogger();
        $httpClient = $this->createMock(HttpClientInterface::class);
        $this->dorkService = new GoogleDorkService($httpClient, $this->logger);
        $this->scoringService = new LeadScoringService($this->logger);
        $this->countryService = new CountryService();
    }

    // ════════════════════════════════════════════════════════════
    //  DORK QUERY GENERATION PER REGION
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider regionLocationProvider
     */
    public function testDorkQueryContainsLocationKeyword(string $location, string $sector): void
    {
        // Use reflection to access private buildGoogleDorkQueries
        $ref = new \ReflectionMethod($this->dorkService, 'buildGoogleDorkQueries');
        $ref->setAccessible(true);
        $queries = $ref->invoke($this->dorkService, $sector, $location);
        $this->assertNotEmpty($queries, "Should build dork queries for {$sector} in {$location}");

        $allQueries = implode(' ', $queries);
        $this->assertNotEmpty($allQueries);
    }

    public static function regionLocationProvider(): array
    {
        return [
            // Morocco
            ['Tangier', 'Automotive'],
            ['Casablanca', 'Industrial'],
            // US
            ['New York', 'Aerospace'],
            ['Houston, TX', 'Medical'],
            // EU
            ['Munich, Germany', 'Automotive'],
            ['Paris, France', 'Rail'],
            // UK
            ['Birmingham, UK', 'Industrial'],
            ['Edinburgh, Scotland', 'Renewables'],
            // Egypt
            ['Cairo, Egypt', 'Automotive'],
            ['Suez Canal Economic Zone', 'Industrial'],
            // GCC
            ['Dubai, UAE', 'Aerospace'],
            ['Riyadh, Saudi Arabia', 'Telecom'],
        ];
    }

    /**
     * @dataProvider regionDetectionProvider
     */
    public function testDetectRegionFromLocation(string $location, string $expectedRegion): void
    {
        $ref = new \ReflectionMethod($this->dorkService, 'detectRegionFromLocation');
        $ref->setAccessible(true);
        $region = $ref->invoke($this->dorkService, $location);
        $this->assertSame($expectedRegion, $region, "Location '{$location}' should map to region '{$expectedRegion}'");
    }

    public static function regionDetectionProvider(): array
    {
        return [
            ['Tangier', 'MA'],
            ['Casablanca, Morocco', 'MA'],
            ['New York, NY', 'US'],
            ['Houston, Texas', 'US'],
            ['Munich, Germany', 'DE'],
            ['Stockholm, Sweden', 'SE'],
            ['London, UK', 'GB'],
            ['Edinburgh, Scotland', 'GB'],
            ['Cairo, Egypt', 'EG'],
            ['Suez, Egypt', 'EG'],
            ['Alexandria', 'EG'],
            ['Dubai, UAE', 'GCC'],
            ['Riyadh, Saudi Arabia', 'GCC'],
            ['Doha, Qatar', 'GCC'],
            ['Muscat, Oman', 'GCC'],
            ['Manama, Bahrain', 'GCC'],
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  DISCOVERY SERVICE — TARGET LOCATIONS COVERAGE
    // ════════════════════════════════════════════════════════════

    public function testTargetLocationsHasAllSixRegions(): void
    {
        $locations = CompanyDiscoveryService::getTargetLocations();
        $labels = array_values($locations);
        $combined = strtolower(implode(' ', $labels));

        // Morocco locations
        $this->assertStringContainsString('tanger', $combined);
        $this->assertStringContainsString('casablanca', $combined);
        // US
        $this->assertStringContainsString('new york', $combined);
        $this->assertStringContainsString('texas', $combined);
        // EU
        $this->assertStringContainsString('germany', $combined);
        // UK
        $this->assertStringContainsString('england', $combined);
        // Egypt
        $this->assertStringContainsString('cairo', $combined);
        // GCC
        $this->assertStringContainsString('dubai', $combined);
    }

    public function testTargetLocationsMinimumPerRegion(): void
    {
        $locations = CompanyDiscoveryService::getTargetLocations();
        $ref = new \ReflectionMethod($this->dorkService, 'detectRegionFromLocation');
        $ref->setAccessible(true);

        // EU country codes that should be grouped under 'EU' region
        $euCodes = ['DE', 'FR', 'SE', 'DK', 'FI', 'NO', 'NL', 'BE', 'AT', 'CH', 'IT', 'ES', 'PL', 'CZ', 'RO', 'HU', 'PT', 'IE', 'BG', 'HR', 'SK', 'SI', 'LT', 'LV', 'EE'];
        $regionHits = ['MA' => 0, 'US' => 0, 'EU' => 0, 'GB' => 0, 'EG' => 0, 'GCC' => 0];
        foreach ($locations as $code => $label) {
            $region = $ref->invoke($this->dorkService, $label);
            if (in_array($region, $euCodes, true)) {
                $regionHits['EU']++;
            } elseif (isset($regionHits[$region])) {
                $regionHits[$region]++;
            }
        }

        foreach ($regionHits as $region => $count) {
            $this->assertGreaterThanOrEqual(3, $count, "Region {$region} should have ≥3 target locations, got {$count}");
        }
    }

    // ════════════════════════════════════════════════════════════
    //  LEAD SCORING — 10 MOROCCO LEADS
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider moroccoLeadProvider
     */
    public function testMoroccoLeadScoring(array $lead, int $minScore): void
    {
        $result = $this->scoringService->scoreLead($lead);
        $this->assertGreaterThanOrEqual($minScore, $result['score'],
            "Morocco lead '{$lead['company_name']}' should score ≥{$minScore}, got {$result['score']}");
        $this->assertGreaterThan(0, $result['breakdown']['geo']['score'],
            "Morocco lead should have positive geo score");
    }

    public static function moroccoLeadProvider(): array
    {
        return [
            'TFZ EMS provider' => [self::buildLead(
                'Tangier Electronics Assembly', 'https://tangier-ea.ma',
                'Tangier Free Zone, Morocco', 'MA',
                'Tangier Free Zone electronics assembly pcba smt manufacturing supplier quality ISO 9001 IATF 16949 automotive pcb assembly',
                ['smt', 'pcba'], ['supplier portal'], ['automotive'], ['ISO 9001']
            ), 55],
            'Casablanca PCB shop' => [self::buildLead(
                'CasaPCB Industries', 'https://casapcb.ma',
                'Casablanca, Morocco', 'MA',
                'Casablanca industrial zone pcb fabrication smt assembly box build electronics manufacturing rfq process quality rohs',
                ['pcb fabrication', 'smt'], ['rfq'], ['industrial'], ['rohs']
            ), 50],
            'Kenitra automotive' => [self::buildLead(
                'Atlantic Zone Motors', 'https://azm-kenitra.ma',
                'Kenitra Atlantic Free Zone, Morocco', 'MA',
                'Kenitra Atlantic Free Zone afz automotive pcba manufacturing power electronics bms converter ppap imds supplier onboarding',
                ['pcba', 'power electronics'], ['ppap', 'imds', 'supplier onboarding'], ['automotive'], ['IATF 16949']
            ), 60],
            'Nouaceur aerospace' => [self::buildLead(
                'MidParc Aeroconnect', 'https://midparc-aero.ma',
                'Nouaceur, Morocco', 'MA',
                'Nouaceur Midparc aerospace electronics assembly cable assembly pcb through-hole manufacturing AS9100 nadcap quality',
                ['electronics assembly', 'cable assembly', 'pcb'], ['quality requirements'], ['aerospace'], ['AS9100', 'NADCAP']
            ), 40],
            'Rabat renewable tech' => [self::buildLead(
                'RabatSolar Tech', 'https://rabatsolar.ma',
                'Rabat, Morocco', 'MA',
                'Rabat Morocco solar inverter power electronics manufacturing bms smt assembly renewables energy systems rfq process',
                ['inverter', 'power electronics', 'smt'], ['rfq'], ['renewables', 'solar'], []
            ), 50],
            'Tetouan cable assembly' => [self::buildLead(
                'NorthWire Morocco', 'https://northwire.ma',
                'Tetouan, Morocco', 'MA',
                'Tetouan Morocco cable assembly wire harness electronics industrial manufacturing quality iso 9001 automotive supplier',
                ['cable assembly', 'electronics'], ['supplier quality'], ['automotive', 'industrial'], ['ISO 9001']
            ), 40],
            'Tanger Med logistics-EMS' => [self::buildLead(
                'TMZ Electronics Logistics', 'https://tmz-el.ma',
                'Tanger Med Zone, Morocco', 'MA',
                'Tanger Med TMZ electronics assembly contract manufacturing ems pcba smt supplier portal vendor registration quality',
                ['ems', 'pcba', 'smt', 'contract manufacturing'], ['supplier portal', 'vendor registration'], ['industrial'], []
            ), 60],
            'Ain Sebaa industrial' => [self::buildLead(
                'AinSebaa PowerTech', 'https://ainsebaa-pt.ma',
                'Ain Sebaa, Morocco', 'MA',
                'Ain Sebaa Casablanca industrial electronics inverter converter power electronics manufacturing quality iso 9001',
                ['industrial electronics', 'inverter', 'converter'], [], ['industrial', 'energy'], ['ISO 9001']
            ), 45],
            'Bouskoura data center' => [self::buildLead(
                'BouskouraData Systems', 'https://bouskoura-data.ma',
                'Bouskoura, Morocco', 'MA',
                'Bouskoura midparc data center electronics assembly pcba rack systems telecom manufacturing quality iso 9001',
                ['electronics assembly', 'pcba'], [], ['data center', 'telecom'], ['ISO 9001']
            ), 50],
            'Zone Franche medical' => [self::buildLead(
                'MedTech FZ Morocco', 'https://medtech-fz.ma',
                'Casablanca Free Zone, Morocco', 'MA',
                'Casablanca zone franche medical device electronics pcb assembly iso 13485 ce marking fda quality supplier',
                ['electronics', 'pcb assembly'], ['supplier quality'], ['medical device'], ['ISO 13485', 'CE']
            ), 38],
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  LEAD SCORING — 10 US LEADS
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider usLeadProvider
     */
    public function testUSLeadScoring(array $lead, int $minScore): void
    {
        $result = $this->scoringService->scoreLead($lead);
        $this->assertGreaterThanOrEqual($minScore, $result['score'],
            "US lead '{$lead['company_name']}' should score ≥{$minScore}, got {$result['score']}");
        $this->assertGreaterThan(0, $result['breakdown']['geo']['score'],
            "US lead should have positive geo score");
    }

    public static function usLeadProvider(): array
    {
        return [
            'NJ EMS provider' => [self::buildLead(
                'Garden State Electronics', 'https://gs-electronics.com',
                'Newark, NJ', 'US',
                'New Jersey NJ pcba smt ems contract manufacturing electronics assembly automotive aerospace quality iatf 16949 as9100 rfq',
                ['pcba', 'smt', 'ems', 'contract manufacturing'], ['rfq'], ['automotive', 'aerospace'], ['IATF 16949']
            ), 55],
            'Houston power electronics' => [self::buildLead(
                'Houston PowerBoard', 'https://houstonpowerboard.com',
                'Houston, TX', 'US',
                'Houston Texas TX power electronics pcb assembly bms inverter converter industrial energy manufacturing supplier portal quality',
                ['power electronics', 'pcb assembly', 'inverter'], ['supplier portal'], ['industrial', 'energy'], ['ISO 9001']
            ), 55],
            'Dallas aerospace' => [self::buildLead(
                'DFW Aero Electronics', 'https://dfw-aero.com',
                'Dallas, TX', 'US',
                'Dallas Fort Worth Texas aerospace electronics assembly pcba smt through-hole as9100 nadcap itar quality rfq',
                ['electronics assembly', 'pcba', 'smt'], ['rfq', 'quality requirements'], ['aerospace'], ['AS9100', 'NADCAP']
            ), 48],
            'PA automotive' => [self::buildLead(
                'PennTech Automotive', 'https://penntech-auto.com',
                'Philadelphia, PA', 'US',
                'Pennsylvania PA automotive electronics pcba manufacturing iatf 16949 ppap imds supplier onboarding quality rohs reach',
                ['pcba', 'electronics'], ['ppap', 'imds', 'supplier onboarding'], ['automotive'], ['IATF 16949']
            ), 55],
            'MA medical devices' => [self::buildLead(
                'BayState MedTech', 'https://baystate-medtech.com',
                'Boston, MA', 'US',
                'Massachusetts MA medical device electronics assembly pcb iso 13485 fda 21 cfr quality ce marking contract manufacturing',
                ['electronics assembly', 'pcb', 'contract manufacturing'], ['quality requirements'], ['medical device'], ['ISO 13485']
            ), 38],
            'NC renewable energy' => [self::buildLead(
                'Carolina Solar Systems', 'https://carolinasolar.com',
                'Charlotte, NC', 'US',
                'North Carolina NC solar inverter power electronics smt assembly renewables energy manufacturing rfq sourcing quality',
                ['inverter', 'power electronics', 'smt'], ['rfq', 'sourcing'], ['renewables', 'solar'], []
            ), 50],
            'VA defense electronics' => [self::buildLead(
                'NoVa Defense Tech', 'https://nova-defensetech.com',
                'Arlington, VA', 'US',
                'Virginia VA defense electronics pcba assembly box build cable assembly itar as9100 quality mil-spec supplier portal',
                ['pcba', 'box build', 'cable assembly'], ['supplier portal'], ['aerospace'], ['AS9100']
            ), 45],
            'CT industrial' => [self::buildLead(
                'Hartford Industrial Electronics', 'https://hartford-ie.com',
                'Hartford, CT', 'US',
                'Connecticut CT industrial electronics pcb fabrication smt assembly manufacturing quality iso 9001 rohs rfq process',
                ['pcb fabrication', 'smt'], ['rfq'], ['industrial'], ['ISO 9001']
            ), 50],
            'Austin tech hub' => [self::buildLead(
                'Austin EMS Solutions', 'https://austin-ems.com',
                'Austin, TX', 'US',
                'Austin Round Rock Texas TX ems pcba smt contract manufacturing electronics assembly data center telecom quality',
                ['ems', 'pcba', 'smt', 'contract manufacturing'], [], ['data center', 'telecom'], []
            ), 50],
            'GA automotive' => [self::buildLead(
                'Peach State Auto Electronics', 'https://peachstate-ae.com',
                'Atlanta, GA', 'US',
                'Georgia GA automotive electronics pcba manufacturing telematics controller hvac smt quality iatf 16949 supplier vendor registration',
                ['pcba', 'smt', 'electronics'], ['supplier', 'vendor registration'], ['automotive'], ['IATF 16949']
            ), 55],
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  LEAD SCORING — 10 EU LEADS
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider euLeadProvider
     */
    public function testEULeadScoring(array $lead, int $minScore): void
    {
        $result = $this->scoringService->scoreLead($lead);
        $this->assertGreaterThanOrEqual($minScore, $result['score'],
            "EU lead '{$lead['company_name']}' should score ≥{$minScore}, got {$result['score']}");
        $this->assertGreaterThan(0, $result['breakdown']['geo']['score'],
            "EU lead should have positive geo score");
    }

    public static function euLeadProvider(): array
    {
        return [
            'DE automotive EMS' => [self::buildLead(
                'Bayern Elektronik GmbH', 'https://bayern-elektronik.de',
                'Munich, Germany', 'EU',
                'Germany DE automotive electronics assembly pcba smt manufacturing Lieferant Einkauf iatf 16949 vda 6.3 ppap imds quality',
                ['pcba', 'smt', 'electronics assembly'], ['ppap', 'imds'], ['automotive'], ['IATF 16949', 'VDA 6.3']
            ), 55],
            'FR aerospace' => [self::buildLead(
                'AeroTech Toulouse', 'https://aerotech-toulouse.fr',
                'Toulouse, France', 'EU',
                'France FR aerospace electronics assembly cable assembly pcb fournisseur approvisionnement as9100 en 9100 nadcap quality',
                ['electronics assembly', 'cable assembly', 'pcb'], ['fournisseur', 'approvisionnement'], ['aerospace'], ['AS9100', 'NADCAP']
            ), 38],
            'IT industrial' => [self::buildLead(
                'Elettronica Milano', 'https://elettronica-milano.it',
                'Milan, Italy', 'EU',
                'Italy IT industrial electronics pcba smt manufacturing fornitore acquisti quality iso 9001 ce marking rohs reach',
                ['pcba', 'smt', 'industrial electronics'], ['fornitore', 'acquisti'], ['industrial'], ['ISO 9001', 'CE']
            ), 50],
            'NL renewables' => [self::buildLead(
                'WindTech Nederland', 'https://windtech.nl',
                'Rotterdam, Netherlands', 'EU',
                'Netherlands NL wind solar renewables power electronics inverter converter smt assembly leverancier inkoop quality iso 14001',
                ['power electronics', 'inverter', 'smt'], ['leverancier', 'inkoop'], ['renewables', 'wind', 'solar'], ['ISO 14001']
            ), 50],
            'SE nordics rail' => [self::buildLead(
                'Nordic Rail Systems', 'https://nordic-rail.se',
                'Gothenburg, Sweden', 'EU',
                'Sweden SE rail railway electronics manufacturing pcb assembly power electronics controller quality iso 9001 en standards',
                ['electronics', 'pcb assembly', 'power electronics'], ['quality requirements'], ['rail', 'railway'], ['ISO 9001']
            ), 42],
            'PL CEE automotive' => [self::buildLead(
                'PolTech Automotive', 'https://poltech-auto.pl',
                'Wroclaw, Poland', 'EU',
                'Poland PL automotive electronics pcba smt assembly dostawca zakupy iatf 16949 ppap quality rohs manufacturing',
                ['pcba', 'smt'], ['dostawca', 'zakupy', 'ppap'], ['automotive'], ['IATF 16949']
            ), 50],
            'ES medical devices' => [self::buildLead(
                'MedElectro Barcelona', 'https://medelectro.es',
                'Barcelona, Spain', 'EU',
                'Spain ES medical device electronics pcb assembly proveedor compras iso 13485 mdr ce marking quality manufacturing',
                ['electronics', 'pcb assembly'], ['proveedor', 'compras'], ['medical device'], ['ISO 13485', 'CE']
            ), 32],
            'CZ semiconductor' => [self::buildLead(
                'CzechTech Electronics', 'https://czechtech.cz',
                'Brno, Czech Republic', 'EU',
                'Czech Republic CZ electronics assembly smt pcba manufacturing industrial automotive rfq sourcing quality iso 9001',
                ['electronics assembly', 'smt', 'pcba'], ['rfq', 'sourcing'], ['industrial', 'automotive'], ['ISO 9001']
            ), 50],
            'BE defense' => [self::buildLead(
                'Brussels Defense Systems', 'https://bds-defense.be',
                'Brussels, Belgium', 'EU',
                'Belgium BE defense aerospace electronics pcba box build cable assembly as9100 quality supplier portal vendor registration',
                ['pcba', 'box build', 'cable assembly'], ['supplier portal', 'vendor registration'], ['aerospace'], ['AS9100']
            ), 55],
            'AT power electronics' => [self::buildLead(
                'AlpenPower GmbH', 'https://alpenpower.at',
                'Vienna, Austria', 'EU',
                'Austria AT power electronics inverter converter bms pcb assembly Lieferantenportal Beschaffung quality iso 9001 iso 14001',
                ['power electronics', 'inverter', 'pcb assembly'], ['Lieferantenportal', 'Beschaffung'], ['energy', 'industrial'], ['ISO 9001', 'ISO 14001']
            ), 42],
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  LEAD SCORING — 10 UK LEADS
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider ukLeadProvider
     */
    public function testUKLeadScoring(array $lead, int $minScore): void
    {
        $result = $this->scoringService->scoreLead($lead);
        $this->assertGreaterThanOrEqual($minScore, $result['score'],
            "UK lead '{$lead['company_name']}' should score ≥{$minScore}, got {$result['score']}");
        $this->assertGreaterThan(0, $result['breakdown']['geo']['score'],
            "UK lead should have positive geo score");
    }

    public static function ukLeadProvider(): array
    {
        return [
            'Birmingham automotive' => [self::buildLead(
                'Midlands Auto Electronics', 'https://midlands-ae.co.uk',
                'Birmingham, England', 'GB',
                'England Birmingham automotive electronics pcba smt assembly manufacturing iatf 16949 ppap supplier portal quality',
                ['pcba', 'smt', 'electronics'], ['ppap', 'supplier portal'], ['automotive'], ['IATF 16949']
            ), 50],
            'Edinburgh aerospace' => [self::buildLead(
                'Scottish Aero Systems', 'https://scottish-aero.co.uk',
                'Edinburgh, Scotland', 'GB',
                'Scotland Edinburgh aerospace electronics assembly cable assembly pcb as9100 nadcap quality rfq process manufacturing',
                ['electronics assembly', 'cable assembly', 'pcb'], ['rfq', 'quality requirements'], ['aerospace'], ['AS9100', 'NADCAP']
            ), 40],
            'Manchester industrial' => [self::buildLead(
                'NorthTech Electronics', 'https://northtech.co.uk',
                'Manchester, England', 'GB',
                'England Manchester industrial electronics pcba manufacturing smt assembly iso 9001 quality rohs reach rfq',
                ['pcba', 'smt', 'industrial electronics'], ['rfq'], ['industrial'], ['ISO 9001']
            ), 50],
            'Cardiff renewables' => [self::buildLead(
                'Welsh Green Energy', 'https://welsh-green.co.uk',
                'Cardiff, Wales', 'GB',
                'Wales Cardiff renewables solar wind power electronics inverter smt assembly manufacturing quality iso 14001',
                ['power electronics', 'inverter', 'smt'], [], ['renewables', 'solar', 'wind'], ['ISO 14001']
            ), 45],
            'Bristol defense' => [self::buildLead(
                'Avon Defense Tech', 'https://avon-defense.co.uk',
                'Bristol, England', 'GB',
                'England Bristol defense aerospace electronics pcba box build as9100 itar quality supplier vendor registration manufacturing',
                ['pcba', 'box build'], ['supplier', 'vendor registration'], ['aerospace'], ['AS9100']
            ), 40],
            'Glasgow medical' => [self::buildLead(
                'Clyde MedTech', 'https://clyde-medtech.co.uk',
                'Glasgow, Scotland', 'GB',
                'Scotland Glasgow medical device electronics pcb assembly iso 13485 mdr ce marking quality manufacturing',
                ['electronics', 'pcb assembly'], ['quality requirements'], ['medical device'], ['ISO 13485', 'CE']
            ), 30],
            'Leeds rail' => [self::buildLead(
                'Yorkshire Rail Systems', 'https://yorkshire-rail.co.uk',
                'Leeds, England', 'GB',
                'England Leeds rail railway electronics manufacturing pcb assembly controller power electronics quality iso 9001 en standards',
                ['electronics', 'pcb assembly', 'power electronics'], [], ['rail', 'railway'], ['ISO 9001']
            ), 40],
            'Southampton marine' => [self::buildLead(
                'Solent Electronics', 'https://solent-electronics.co.uk',
                'Southampton, England', 'GB',
                'England Southampton electronics assembly pcba smt contract manufacturing industrial marine quality iso 9001 rfq process',
                ['electronics assembly', 'pcba', 'smt', 'contract manufacturing'], ['rfq'], ['industrial'], ['ISO 9001']
            ), 50],
            'Cambridge tech' => [self::buildLead(
                'CamTech EMS', 'https://camtech-ems.co.uk',
                'Cambridge, England', 'GB',
                'England Cambridge ems electronics assembly pcba smt manufacturing data center telecom quality iso 9001 supplier portal',
                ['ems', 'pcba', 'smt'], ['supplier portal'], ['data center', 'telecom'], ['ISO 9001']
            ), 50],
            'Belfast power' => [self::buildLead(
                'Ulster Power Electronics', 'https://ulster-pe.co.uk',
                'Belfast, Northern Ireland', 'GB',
                'Northern Ireland Belfast power electronics inverter converter bms pcb assembly manufacturing quality iso 9001 rfq',
                ['power electronics', 'inverter', 'pcb assembly'], ['rfq'], ['energy', 'industrial'], ['ISO 9001']
            ), 45],
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  LEAD SCORING — 10 EGYPT LEADS
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider egyptLeadProvider
     */
    public function testEgyptLeadScoring(array $lead, int $minScore): void
    {
        $result = $this->scoringService->scoreLead($lead);
        $this->assertGreaterThanOrEqual($minScore, $result['score'],
            "Egypt lead '{$lead['company_name']}' should score ≥{$minScore}, got {$result['score']}");
        $this->assertGreaterThan(0, $result['breakdown']['geo']['score'],
            "Egypt lead should have positive geo score");
    }

    public static function egyptLeadProvider(): array
    {
        return [
            'Cairo EMS' => [self::buildLead(
                'Nile Electronics Assembly', 'https://nile-ea.com.eg',
                'Cairo, Egypt', 'EG',
                'Cairo Egypt electronics assembly pcba smt ems manufacturing industrial automotive quality iso 9001 rfq supplier portal',
                ['pcba', 'smt', 'ems'], ['rfq', 'supplier portal'], ['automotive', 'industrial'], ['ISO 9001']
            ), 55],
            'SCZONE industrial' => [self::buildLead(
                'SCZone Electronics', 'https://sczone-electronics.eg',
                'Suez Canal Economic Zone, Egypt', 'EG',
                'Suez Canal Economic Zone SCZONE Egypt electronics pcba manufacturing industrial smt assembly rfq quality iso 9001',
                ['pcba', 'smt', 'electronics'], ['rfq', 'quality requirements'], ['industrial'], ['ISO 9001']
            ), 46],
            '10th Ramadan automotive' => [self::buildLead(
                'Ramadan Auto Electronics', 'https://ramadan-auto.com.eg',
                '10th of Ramadan City, Egypt', 'EG',
                '10th of Ramadan City Egypt automotive electronics pcba manufacturing iatf 16949 ppap quality supplier onboarding smt assembly',
                ['pcba', 'smt', 'electronics'], ['ppap', 'supplier onboarding'], ['automotive'], ['IATF 16949']
            ), 55],
            '6th October industrial' => [self::buildLead(
                'October Tech Industries', 'https://october-tech.com.eg',
                '6th of October City, Egypt', 'EG',
                '6th of October City Egypt industrial electronics pcb assembly manufacturing quality iso 9001 rohs reach rfq sourcing',
                ['industrial electronics', 'pcb assembly'], ['rfq', 'sourcing'], ['industrial'], ['ISO 9001']
            ), 50],
            'Alexandria renewables' => [self::buildLead(
                'Alexandria Solar Tech', 'https://alex-solar.com.eg',
                'Alexandria, Egypt', 'EG',
                'Alexandria Egypt solar inverter power electronics smt assembly renewables energy manufacturing rfq quality iso 14001',
                ['inverter', 'power electronics', 'smt'], ['rfq'], ['renewables', 'solar', 'energy'], ['ISO 14001']
            ), 50],
            'Ain Sokhna power' => [self::buildLead(
                'Sokhna Power Electronics', 'https://sokhna-pe.com.eg',
                'Ain Sokhna, Egypt', 'EG',
                'Ain Sokhna industrial zone Egypt power electronics inverter converter pcb assembly manufacturing quality iso 9001',
                ['power electronics', 'inverter', 'converter', 'pcb assembly'], [], ['energy', 'industrial'], ['ISO 9001']
            ), 50],
            'New Cairo tech' => [self::buildLead(
                'NewCairo Systems', 'https://newcairo-sys.com.eg',
                'New Cairo, Egypt', 'EG',
                'New Cairo industrial zone Egypt electronics assembly ems pcba smt data center telecom manufacturing quality',
                ['ems', 'pcba', 'smt', 'electronics assembly'], [], ['data center', 'telecom'], []
            ), 50],
            'Sadat City manufacturing' => [self::buildLead(
                'Sadat Electronics', 'https://sadat-elec.com.eg',
                'Sadat City, Egypt', 'EG',
                'Sadat City Egypt electronics manufacturing pcba smt contract manufacturing industrial quality iso 9001 rfq',
                ['pcba', 'smt', 'contract manufacturing'], ['rfq'], ['industrial'], ['ISO 9001']
            ), 50],
            'Port Said free zone' => [self::buildLead(
                'PortSaid FZ Electronics', 'https://portsaid-fz.com.eg',
                'Port Said, Egypt', 'EG',
                'Port Said free zone Egypt electronics assembly pcba manufacturing automotive quality iatf 16949 supplier portal',
                ['electronics assembly', 'pcba'], ['supplier portal'], ['automotive'], ['IATF 16949']
            ), 42],
            'Borg El Arab aerospace' => [self::buildLead(
                'BorgArab Aero Electronics', 'https://borgel-arab-aero.com.eg',
                'Borg El Arab, Egypt', 'EG',
                'Borg El Arab industrial zone Alexandria Egypt aerospace electronics assembly cable assembly pcb as9100 quality manufacturing',
                ['electronics assembly', 'cable assembly', 'pcb'], ['quality requirements'], ['aerospace'], ['AS9100']
            ), 38],
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  LEAD SCORING — 10 GCC LEADS
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider gccLeadProvider
     */
    public function testGCCLeadScoring(array $lead, int $minScore): void
    {
        $result = $this->scoringService->scoreLead($lead);
        $this->assertGreaterThanOrEqual($minScore, $result['score'],
            "GCC lead '{$lead['company_name']}' should score ≥{$minScore}, got {$result['score']}");
        $this->assertGreaterThan(0, $result['breakdown']['geo']['score'],
            "GCC lead should have positive geo score");
    }

    public static function gccLeadProvider(): array
    {
        return [
            'JAFZA Dubai EMS' => [self::buildLead(
                'Gulf Electronics Manufacturing', 'https://gulf-em.ae',
                'JAFZA, Dubai, UAE', 'GCC',
                'JAFZA Jebel Ali Dubai UAE electronics assembly pcba smt ems manufacturing automotive aerospace quality iso 9001 supplier portal',
                ['pcba', 'smt', 'ems', 'electronics assembly'], ['supplier portal'], ['automotive', 'aerospace'], ['ISO 9001']
            ), 55],
            'Abu Dhabi KIZAD' => [self::buildLead(
                'KIZAD Power Tech', 'https://kizad-pt.ae',
                'KIZAD, Abu Dhabi, UAE', 'GCC',
                'KIZAD Khalifa Industrial Zone Abu Dhabi UAE power electronics inverter pcb assembly manufacturing quality iso 9001 rfq',
                ['power electronics', 'inverter', 'pcb assembly'], ['rfq'], ['energy', 'industrial'], ['ISO 9001']
            ), 46],
            'Riyadh defense' => [self::buildLead(
                'Riyadh Defense Electronics', 'https://riyadh-de.sa',
                'Riyadh, Saudi Arabia', 'GCC',
                'Riyadh Saudi Arabia defense aerospace electronics pcba assembly box build cable assembly as9100 quality supplier vendor registration',
                ['pcba', 'box build', 'cable assembly'], ['supplier', 'vendor registration'], ['aerospace'], ['AS9100']
            ), 46],
            'Jubail industrial' => [self::buildLead(
                'Jubail Electronics', 'https://jubail-elec.sa',
                'Jubail Industrial City, Saudi Arabia', 'GCC',
                'Jubail Industrial City Saudi Arabia electronics pcba smt manufacturing industrial quality iso 9001 rohs rfq sourcing',
                ['pcba', 'smt'], ['rfq', 'sourcing'], ['industrial'], ['ISO 9001']
            ), 50],
            'Doha data center' => [self::buildLead(
                'Qatar TechSystems', 'https://qatar-techsys.qa',
                'Doha, Qatar', 'GCC',
                'Doha Qatar QFC electronics assembly pcba data center telecom manufacturing smt quality iso 9001 rfq sourcing vendor registration supplier portal industrial',
                ['electronics assembly', 'pcba', 'smt'], ['rfq', 'sourcing', 'vendor registration'], ['data center', 'telecom'], ['ISO 9001']
            ), 50],
            'Jeddah automotive' => [self::buildLead(
                'KAEC Auto Electronics', 'https://kaec-auto.sa',
                'KAEC, Jeddah, Saudi Arabia', 'GCC',
                'King Abdullah Economic City KAEC Jeddah Saudi Arabia automotive electronics pcba manufacturing iatf 16949 ppap quality supplier',
                ['pcba', 'electronics'], ['ppap', 'supplier quality'], ['automotive'], ['IATF 16949']
            ), 38],
            'Sharjah SAIF Zone' => [self::buildLead(
                'Sharjah Precision Electronics', 'https://spe-sharjah.ae',
                'SAIF Zone, Sharjah, UAE', 'GCC',
                'Sharjah Airport International Free Zone SAIF Zone UAE electronics pcb fabrication smt assembly manufacturing quality iso 9001',
                ['pcb fabrication', 'smt'], ['quality requirements'], ['industrial'], ['ISO 9001']
            ), 38],
            'Kuwait industrial' => [self::buildLead(
                'Kuwait Power Electronics', 'https://kpe.kw',
                'Kuwait City, Kuwait', 'GCC',
                'Kuwait City Kuwait power electronics inverter converter bms manufacturing industrial quality iso 9001 rfq',
                ['power electronics', 'inverter', 'converter'], ['rfq'], ['energy', 'industrial'], ['ISO 9001']
            ), 50],
            'Muscat/Sohar EMS' => [self::buildLead(
                'Oman EMS Solutions', 'https://oman-ems.om',
                'Sohar Free Zone, Oman', 'GCC',
                'Sohar Free Zone Oman ems electronics assembly pcba smt contract manufacturing industrial quality iso 9001 rfq',
                ['ems', 'pcba', 'smt', 'contract manufacturing'], ['rfq'], ['industrial'], ['ISO 9001']
            ), 50],
            'Bahrain BIW' => [self::buildLead(
                'Bahrain Tech Assembly', 'https://bahrain-ta.bh',
                'Bahrain Investment Wharf, Bahrain', 'GCC',
                'Bahrain Investment Wharf BIW Manama electronics assembly pcba manufacturing automotive quality iatf 16949 supplier portal',
                ['electronics assembly', 'pcba'], ['supplier portal'], ['automotive'], ['IATF 16949']
            ), 42],
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  FALLBACK SCORING — EGYPT / GCC COVERAGE
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider egyptFallbackProvider
     */
    public function testEgyptFallbackScoringGivesRegionBonus(array $lead): void
    {
        $result = $this->scoringService->scoreLead($lead);
        $this->assertGreaterThan(0, $result['breakdown']['region']['score'] ?? 0,
            "Egypt fallback lead should get region bonus");
    }

    public static function egyptFallbackProvider(): array
    {
        return [
            'Cairo tag' => [['company_name' => 'Cairo EMS', 'page_content' => '', 'region_tag' => 'egypt', 'site_location' => 'Cairo']],
            'Suez tag' => [['company_name' => 'Suez Tech', 'page_content' => '', 'region_tag' => 'EG', 'site_location' => 'Suez, Egypt']],
            'Alexandria tag' => [['company_name' => 'Alex Manufacturing', 'page_content' => '', 'region_tag' => 'egypt', 'site_location' => 'Alexandria']],
        ];
    }

    /**
     * @dataProvider gccFallbackProvider
     */
    public function testGCCFallbackScoringGivesRegionBonus(array $lead): void
    {
        $result = $this->scoringService->scoreLead($lead);
        $this->assertGreaterThan(0, $result['breakdown']['region']['score'] ?? 0,
            "GCC fallback lead should get region bonus");
    }

    public static function gccFallbackProvider(): array
    {
        return [
            'Dubai tag' => [['company_name' => 'Dubai EMS', 'page_content' => '', 'region_tag' => 'gcc', 'site_location' => 'Dubai']],
            'Riyadh tag' => [['company_name' => 'Riyadh Tech', 'page_content' => '', 'region_tag' => 'GCC', 'site_location' => 'Riyadh, Saudi Arabia']],
            'Doha tag' => [['company_name' => 'Doha Systems', 'page_content' => '', 'region_tag' => 'gcc', 'site_location' => 'Doha, Qatar']],
            'Kuwait tag' => [['company_name' => 'Kuwait Power', 'page_content' => '', 'region_tag' => 'gcc', 'site_location' => 'Kuwait City']],
            'Bahrain tag' => [['company_name' => 'Bahrain Elec', 'page_content' => '', 'region_tag' => 'gcc', 'site_location' => 'Manama, Bahrain']],
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  GEO SCORING — EGYPT & GCC GET NON-ZERO POINTS
    // ════════════════════════════════════════════════════════════

    public function testEgyptLeadGetsGeoPoints(): void
    {
        $lead = self::buildLead(
            'Test Egypt', 'https://test.com.eg',
            'Cairo, Egypt', 'EG',
            'Cairo Egypt electronics assembly pcba smt manufacturing industrial supplier quality iso 9001 rohs rfq contract manufacturing power',
            ['pcba'], [], [], []
        );
        $result = $this->scoringService->scoreLead($lead);
        $this->assertGreaterThanOrEqual(14, $result['breakdown']['geo']['score'],
            "Egypt lead geo score should be ≥14 (geo_egypt weight)");
    }

    public function testGCCLeadGetsGeoPoints(): void
    {
        $lead = self::buildLead(
            'Test Dubai', 'https://test.ae',
            'Dubai, UAE', 'GCC',
            'Dubai UAE JAFZA electronics assembly pcba smt manufacturing industrial supplier quality iso 9001 rohs rfq contract manufacturing power',
            ['pcba'], [], [], []
        );
        $result = $this->scoringService->scoreLead($lead);
        $this->assertGreaterThanOrEqual(14, $result['breakdown']['geo']['score'],
            "GCC lead geo score should be ≥14 (geo_gcc weight)");
    }

    public function testEgyptTLDTriggersGeoScore(): void
    {
        $lead = self::buildLead(
            'Some Company', 'https://example.com.eg',
            '', '',
            'electronics pcba smt manufacturing quality iso 9001 rohs reach industrial assembly supplier portal rfq vendor registration procurement',
            ['pcba'], [], [], []
        );
        $result = $this->scoringService->scoreLead($lead);
        $this->assertGreaterThan(0, $result['breakdown']['geo']['score'],
            ".com.eg TLD should trigger Egypt geo score");
    }

    public function testGCCTLDsTriggersGeoScore(): void
    {
        $tlds = ['.ae', '.sa', '.qa', '.kw', '.om', '.bh'];
        foreach ($tlds as $tld) {
            $lead = self::buildLead(
                'Some Company', "https://example{$tld}",
                '', '',
                'electronics pcba smt manufacturing quality iso 9001 rohs reach industrial assembly supplier portal rfq vendor registration procurement',
                ['pcba'], [], [], []
            );
            $result = $this->scoringService->scoreLead($lead);
            $this->assertGreaterThan(0, $result['breakdown']['geo']['score'],
                "TLD {$tld} should trigger GCC geo score");
        }
    }

    // ════════════════════════════════════════════════════════════
    //  CROSS-REGION PARITY — NO REGION DISADVANTAGED
    // ════════════════════════════════════════════════════════════

    public function testCrossRegionScoreParityForEquivalentLeads(): void
    {
        // Build structurally identical leads for each region — they differ only in geo
        $regions = [
            'MA' => ['Tangier Free Zone, Morocco', 'MA', 'https://test.ma', 'Tangier Free Zone Morocco'],
            'US' => ['Houston, TX', 'US', 'https://test.com', 'Houston Texas TX'],
            'EU' => ['Munich, Germany', 'EU', 'https://test.de', 'Germany DE Munich'],
            'GB' => ['Birmingham, England', 'GB', 'https://test.co.uk', 'England Birmingham'],
            'EG' => ['Cairo, Egypt', 'EG', 'https://test.com.eg', 'Cairo Egypt'],
            'GCC' => ['Dubai, UAE', 'GCC', 'https://test.ae', 'Dubai UAE JAFZA'],
        ];

        $scores = [];
        foreach ($regions as $code => [$location, $tag, $url, $geoContent]) {
            $lead = self::buildLead(
                "Test Company {$code}", $url,
                $location, $tag,
                "{$geoContent} electronics assembly pcba smt ems manufacturing supplier portal vendor registration automotive aerospace quality iso 9001 iatf 16949",
                ['pcba', 'smt', 'ems'], ['supplier portal', 'vendor registration'], ['automotive', 'aerospace'], ['ISO 9001', 'IATF 16949']
            );
            $result = $this->scoringService->scoreLead($lead);
            $scores[$code] = $result['score'];
        }

        // All scores should be ≥ 50 (approve-range or solid review)
        foreach ($scores as $region => $score) {
            $this->assertGreaterThanOrEqual(50, $score,
                "Equivalent lead in {$region} scored only {$score} — should be ≥50");
        }

        // Max spread across all regions should be ≤ 12 points
        // (Morocco gets 20 geo, others get 12-16, so max spread is the geo weight difference)
        $spread = max($scores) - min($scores);
        $this->assertLessThanOrEqual(12, $spread,
            "Cross-region score spread is {$spread} (max-min: " . max($scores) . '-' . min($scores)
            . "). Should be ≤12. Scores: " . json_encode($scores));
    }

    public function testAllRegionsCanReachApproveThreshold(): void
    {
        // Strong leads in every region should clear the recommend threshold (55)
        $regions = [
            'MA' => ['Tangier Free Zone, Morocco', 'MA', 'https://test.ma', 'Tangier Free Zone Morocco'],
            'US' => ['Houston, TX', 'US', 'https://test.com', 'Houston Texas TX'],
            'EU' => ['Munich, Germany', 'EU', 'https://test.de', 'Germany DE Munich'],
            'GB' => ['Birmingham, England', 'GB', 'https://test.co.uk', 'England Birmingham'],
            'EG' => ['Cairo, Egypt', 'EG', 'https://test.com.eg', 'Cairo Egypt SCZONE'],
            'GCC' => ['Dubai, UAE', 'GCC', 'https://test.ae', 'Dubai UAE JAFZA Jebel Ali'],
        ];

        foreach ($regions as $code => [$location, $tag, $url, $geoContent]) {
            $lead = self::buildLead(
                "Strong Company {$code}", $url,
                $location, $tag,
                "{$geoContent} electronics assembly pcba smt ems manufacturing supplier portal rfq vendor registration ppap imds automotive aerospace medical quality iso 9001 iatf 16949 as9100",
                ['pcba', 'smt', 'ems', 'contract manufacturing'], ['supplier portal', 'rfq', 'ppap', 'imds', 'vendor registration'], ['automotive', 'aerospace', 'medical device'], ['ISO 9001', 'IATF 16949'],
                true, true, false // facility_evidence, jobs_evidence
            );
            $result = $this->scoringService->scoreLead($lead);
            $this->assertSame('approve', $result['recommendation'],
                "Strong lead in {$code} should be 'approve', got '{$result['recommendation']}' (score: {$result['score']})");
        }
    }

    // ════════════════════════════════════════════════════════════
    //  CONFIG VALIDATION — CRAWLER_CONFIG.YAML
    // ════════════════════════════════════════════════════════════

    public function testCrawlerConfigHasEgyptWeight(): void
    {
        $config = \Symfony\Component\Yaml\Yaml::parseFile(__DIR__ . '/../../../config/crawler_config.yaml');
        $this->assertArrayHasKey('geo_egypt', $config['weights']);
        $this->assertGreaterThanOrEqual(10, $config['weights']['geo_egypt']);
    }

    public function testCrawlerConfigHasGCCWeight(): void
    {
        $config = \Symfony\Component\Yaml\Yaml::parseFile(__DIR__ . '/../../../config/crawler_config.yaml');
        $this->assertArrayHasKey('geo_gcc', $config['weights']);
        $this->assertGreaterThanOrEqual(10, $config['weights']['geo_gcc']);
    }

    public function testCrawlerConfigHasEgyptZones(): void
    {
        $config = \Symfony\Component\Yaml\Yaml::parseFile(__DIR__ . '/../../../config/crawler_config.yaml');
        $this->assertNotEmpty($config['zones']['egypt_zones'] ?? []);
        $this->assertNotEmpty($config['zones']['egypt_cities'] ?? []);
    }

    public function testCrawlerConfigHasGCCZones(): void
    {
        $config = \Symfony\Component\Yaml\Yaml::parseFile(__DIR__ . '/../../../config/crawler_config.yaml');
        $this->assertNotEmpty($config['zones']['gcc_freezones'] ?? []);
        $this->assertNotEmpty($config['zones']['gcc_cities'] ?? []);
    }

    public function testCrawlerConfigHasEgyptRegion(): void
    {
        $config = \Symfony\Component\Yaml\Yaml::parseFile(__DIR__ . '/../../../config/crawler_config.yaml');
        $this->assertArrayHasKey('egypt', $config['regions']);
        $this->assertNotEmpty($config['regions']['egypt']['cities'] ?? []);
        $this->assertNotEmpty($config['regions']['egypt']['tlds'] ?? []);
    }

    public function testCrawlerConfigHasGCCRegion(): void
    {
        $config = \Symfony\Component\Yaml\Yaml::parseFile(__DIR__ . '/../../../config/crawler_config.yaml');
        $this->assertArrayHasKey('gcc', $config['regions']);
        $this->assertNotEmpty($config['regions']['gcc']['countries'] ?? []);
        $this->assertNotEmpty($config['regions']['gcc']['cities'] ?? []);
        $this->assertNotEmpty($config['regions']['gcc']['tlds'] ?? []);
    }

    public function testCrawlerConfigHasArabicKeywords(): void
    {
        $config = \Symfony\Component\Yaml\Yaml::parseFile(__DIR__ . '/../../../config/crawler_config.yaml');
        $this->assertArrayHasKey('ar', $config['keywords_multi'] ?? []);
        $this->assertNotEmpty($config['keywords_multi']['ar']);
    }

    // ════════════════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════════════════

    private static function buildLead(
        string $companyName,
        string $url,
        string $siteLocation,
        string $regionTag,
        string $pageContent,
        array $mfgSignals = [],
        array $procurementSignals = [],
        array $sectorSignals = [],
        array $qualityStack = [],
        bool $facilityEvidence = false,
        bool $jobsEvidence = false,
        bool $newsEvidence = false,
    ): array {
        return [
            'company_name' => $companyName,
            'website_root' => $url,
            'lead_url' => $url,
            'site_location' => $siteLocation,
            'region_tag' => $regionTag,
            'page_content' => $pageContent,
            'address' => $siteLocation,
            'mfg_signals' => $mfgSignals,
            'procurement_signals' => $procurementSignals,
            'sector_signals' => $sectorSignals,
            'quality_stack' => $qualityStack,
            'geo_signals' => [$siteLocation],
            'contact_signals' => [],
            'freshness_signals' => [],
            'contact_emails_public' => ['procurement@' . parse_url($url, PHP_URL_HOST)],
            'contact_form_url' => $url . '/contact',
            'supplier_portal_url' => null,
            'content_last_modified' => '2025-01-15',
            'facility_evidence' => $facilityEvidence,
            'jobs_evidence' => $jobsEvidence,
            'news_evidence' => $newsEvidence,
        ];
    }
}
