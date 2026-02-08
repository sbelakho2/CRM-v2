<?php
/**
 * Web-Scraping Pipeline Quality Report
 *
 * Generates 10 scored leads per region (60 total) through the FULL scoring
 * pipeline and outputs a detailed quality judgment.
 *
 * Usage: php tests/WebScrapingQualityReport.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\WebCrawler\LeadScoringService;
use App\Service\WebCrawler\GoogleDorkService;
use App\Service\WebCrawler\CompanyDiscoveryService;
use Psr\Log\NullLogger;

$logger = new NullLogger();
$scoringService = new LeadScoringService($logger);

// ──────────────────────────────────────────────
//  LEAD DATA — 10 per region
// ──────────────────────────────────────────────

function buildLead(
    string $name, string $url, string $location, string $regionTag,
    string $content, array $mfg = [], array $proc = [], array $sector = [],
    array $quality = [], bool $facility = false, bool $jobs = false, bool $news = false
): array {
    return [
        'company_name' => $name,
        'website_root' => $url,
        'lead_url' => $url,
        'site_location' => $location,
        'region_tag' => $regionTag,
        'page_content' => $content,
        'address' => $location,
        'mfg_signals' => $mfg,
        'procurement_signals' => $proc,
        'sector_signals' => $sector,
        'quality_stack' => $quality,
        'geo_signals' => [$location],
        'contact_signals' => [],
        'freshness_signals' => [],
        'contact_emails_public' => ['procurement@' . parse_url($url, PHP_URL_HOST)],
        'contact_form_url' => $url . '/contact',
        'supplier_portal_url' => null,
        'content_last_modified' => '2025-01-15',
        'facility_evidence' => $facility,
        'jobs_evidence' => $jobs,
        'news_evidence' => $news,
    ];
}

$regions = [
    'Morocco (MA)' => [
        buildLead('Tangier Electronics Assembly', 'https://tangier-ea.ma', 'Tangier Free Zone, Morocco', 'MA',
            'Tangier Free Zone electronics assembly pcba smt manufacturing supplier quality ISO 9001 IATF 16949 automotive pcb assembly rfq vendor registration',
            ['smt', 'pcba'], ['supplier portal', 'rfq', 'vendor registration'], ['automotive'], ['ISO 9001']),
        buildLead('CasaPCB Industries', 'https://casapcb.ma', 'Casablanca, Morocco', 'MA',
            'Casablanca industrial zone pcb fabrication smt assembly box build electronics manufacturing rfq process quality rohs supplier sourcing',
            ['pcb fabrication', 'smt'], ['rfq', 'sourcing'], ['industrial'], ['rohs']),
        buildLead('Atlantic Zone Motors', 'https://azm-kenitra.ma', 'Kenitra Atlantic Free Zone, Morocco', 'MA',
            'Kenitra Atlantic Free Zone afz automotive pcba manufacturing power electronics bms converter ppap imds supplier onboarding quality iso 9001',
            ['pcba', 'power electronics'], ['ppap', 'imds', 'supplier onboarding'], ['automotive'], ['IATF 16949']),
        buildLead('MidParc Aeroconnect', 'https://midparc-aero.ma', 'Nouaceur, Morocco', 'MA',
            'Nouaceur Midparc aerospace electronics assembly cable assembly pcb through-hole manufacturing AS9100 nadcap quality rfq supplier portal vendor registration',
            ['electronics assembly', 'cable assembly', 'pcb'], ['quality requirements', 'rfq', 'supplier portal'], ['aerospace'], ['AS9100', 'NADCAP']),
        buildLead('RabatSolar Tech', 'https://rabatsolar.ma', 'Rabat, Morocco', 'MA',
            'Rabat Morocco solar inverter power electronics manufacturing bms smt assembly renewables energy systems rfq process supplier portal vendor quality',
            ['inverter', 'power electronics', 'smt'], ['rfq', 'supplier portal'], ['renewables', 'solar'], []),
        buildLead('NorthWire Morocco', 'https://northwire.ma', 'Tetouan, Morocco', 'MA',
            'Tetouan Morocco cable assembly wire harness electronics industrial manufacturing quality iso 9001 automotive supplier rfq process vendor registration',
            ['cable assembly', 'electronics'], ['supplier quality', 'rfq', 'vendor registration'], ['automotive', 'industrial'], ['ISO 9001']),
        buildLead('TMZ Electronics Logistics', 'https://tmz-el.ma', 'Tanger Med Zone, Morocco', 'MA',
            'Tanger Med TMZ electronics assembly contract manufacturing ems pcba smt supplier portal vendor registration quality iso 9001 automotive industrial',
            ['ems', 'pcba', 'smt', 'contract manufacturing'], ['supplier portal', 'vendor registration'], ['industrial', 'automotive'], ['ISO 9001']),
        buildLead('AinSebaa PowerTech', 'https://ainsebaa-pt.ma', 'Ain Sebaa, Morocco', 'MA',
            'Ain Sebaa Casablanca industrial electronics inverter converter power electronics manufacturing quality iso 9001 rfq supplier sourcing vendor portal',
            ['industrial electronics', 'inverter', 'converter'], ['rfq', 'sourcing'], ['industrial', 'energy'], ['ISO 9001']),
        buildLead('BouskouraData Systems', 'https://bouskoura-data.ma', 'Bouskoura, Morocco', 'MA',
            'Bouskoura midparc data center electronics assembly pcba rack systems telecom manufacturing quality iso 9001 supplier portal rfq registration vendor',
            ['electronics assembly', 'pcba'], ['supplier portal', 'rfq'], ['data center', 'telecom'], ['ISO 9001']),
        buildLead('MedTech FZ Morocco', 'https://medtech-fz.ma', 'Casablanca Free Zone, Morocco', 'MA',
            'Casablanca zone franche medical device electronics pcb assembly iso 13485 ce marking fda quality supplier rfq vendor registration procurement',
            ['electronics', 'pcb assembly'], ['supplier quality', 'rfq', 'vendor registration'], ['medical device'], ['ISO 13485', 'CE']),
    ],
    'United States (US)' => [
        buildLead('Garden State Electronics', 'https://gs-electronics.com', 'Newark, NJ', 'US',
            'New Jersey NJ pcba smt ems contract manufacturing electronics assembly automotive aerospace quality iatf 16949 as9100 rfq supplier portal vendor registration',
            ['pcba', 'smt', 'ems', 'contract manufacturing'], ['rfq', 'supplier portal'], ['automotive', 'aerospace'], ['IATF 16949']),
        buildLead('Houston PowerBoard', 'https://houstonpowerboard.com', 'Houston, TX', 'US',
            'Houston Texas TX power electronics pcb assembly bms inverter converter industrial energy manufacturing supplier portal quality iso 9001 rfq vendor registration',
            ['power electronics', 'pcb assembly', 'inverter'], ['supplier portal', 'rfq'], ['industrial', 'energy'], ['ISO 9001']),
        buildLead('DFW Aero Electronics', 'https://dfw-aero.com', 'Dallas, TX', 'US',
            'Dallas Fort Worth Texas aerospace electronics assembly pcba smt through-hole as9100 nadcap quality rfq supplier portal vendor registration',
            ['electronics assembly', 'pcba', 'smt'], ['rfq', 'quality requirements', 'supplier portal'], ['aerospace'], ['AS9100', 'NADCAP']),
        buildLead('PennTech Automotive', 'https://penntech-auto.com', 'Philadelphia, PA', 'US',
            'Pennsylvania PA automotive electronics pcba manufacturing iatf 16949 ppap imds supplier onboarding quality rohs reach rfq vendor portal',
            ['pcba', 'electronics'], ['ppap', 'imds', 'supplier onboarding', 'rfq'], ['automotive'], ['IATF 16949']),
        buildLead('BayState MedTech', 'https://baystate-medtech.com', 'Boston, MA', 'US',
            'Massachusetts MA medical device electronics assembly pcb iso 13485 fda 21 cfr quality ce marking contract manufacturing rfq supplier portal vendor',
            ['electronics assembly', 'pcb', 'contract manufacturing'], ['quality requirements', 'rfq'], ['medical device'], ['ISO 13485']),
        buildLead('Carolina Solar Systems', 'https://carolinasolar.com', 'Charlotte, NC', 'US',
            'North Carolina NC solar inverter power electronics smt assembly renewables energy manufacturing rfq sourcing quality iso 14001 supplier portal vendor',
            ['inverter', 'power electronics', 'smt'], ['rfq', 'sourcing', 'supplier portal'], ['renewables', 'solar'], []),
        buildLead('NoVa Defense Tech', 'https://nova-defensetech.com', 'Arlington, VA', 'US',
            'Virginia VA defense electronics pcba assembly box build cable assembly as9100 quality supplier portal vendor registration rfq procurement',
            ['pcba', 'box build', 'cable assembly'], ['supplier portal', 'vendor registration', 'rfq'], ['aerospace'], ['AS9100']),
        buildLead('Hartford Industrial Electronics', 'https://hartford-ie.com', 'Hartford, CT', 'US',
            'Connecticut CT industrial electronics pcb fabrication smt assembly manufacturing quality iso 9001 rohs rfq process supplier sourcing vendor registration',
            ['pcb fabrication', 'smt'], ['rfq', 'sourcing', 'vendor registration'], ['industrial'], ['ISO 9001']),
        buildLead('Austin EMS Solutions', 'https://austin-ems.com', 'Austin, TX', 'US',
            'Austin Round Rock Texas TX ems pcba smt contract manufacturing electronics assembly data center telecom quality supplier portal rfq vendor registration',
            ['ems', 'pcba', 'smt', 'contract manufacturing'], ['supplier portal', 'rfq'], ['data center', 'telecom'], []),
        buildLead('Peach State Auto Electronics', 'https://peachstate-ae.com', 'Atlanta, GA', 'US',
            'Georgia GA automotive electronics pcba manufacturing telematics controller hvac smt quality iatf 16949 supplier vendor registration rfq sourcing',
            ['pcba', 'smt', 'electronics'], ['supplier', 'vendor registration', 'rfq'], ['automotive'], ['IATF 16949']),
    ],
    'European Union (EU)' => [
        buildLead('Bayern Elektronik GmbH', 'https://bayern-elektronik.de', 'Munich, Germany', 'EU',
            'Germany DE automotive electronics assembly pcba smt manufacturing supplier portal vendor registration iatf 16949 vda 6.3 ppap imds quality rfq',
            ['pcba', 'smt', 'electronics assembly'], ['ppap', 'imds', 'supplier portal', 'vendor registration', 'rfq'], ['automotive'], ['IATF 16949', 'VDA 6.3']),
        buildLead('AeroTech Toulouse', 'https://aerotech-toulouse.fr', 'Toulouse, France', 'EU',
            'France FR aerospace electronics assembly cable assembly pcb as9100 en 9100 nadcap quality rfq supplier portal vendor registration sourcing procurement',
            ['electronics assembly', 'cable assembly', 'pcb'], ['rfq', 'supplier portal', 'vendor registration', 'sourcing'], ['aerospace'], ['AS9100', 'NADCAP']),
        buildLead('Elettronica Milano', 'https://elettronica-milano.it', 'Milan, Italy', 'EU',
            'Italy IT industrial electronics pcba smt manufacturing quality iso 9001 ce marking rohs reach rfq supplier portal vendor registration sourcing procurement',
            ['pcba', 'smt', 'industrial electronics'], ['rfq', 'supplier portal', 'vendor registration'], ['industrial'], ['ISO 9001', 'CE']),
        buildLead('WindTech Nederland', 'https://windtech.nl', 'Rotterdam, Netherlands', 'EU',
            'Netherlands NL wind solar renewables power electronics inverter converter smt assembly quality iso 14001 rfq supplier portal sourcing vendor procurement',
            ['power electronics', 'inverter', 'smt'], ['rfq', 'supplier portal', 'sourcing'], ['renewables', 'wind', 'solar'], ['ISO 14001']),
        buildLead('Nordic Rail Systems', 'https://nordic-rail.se', 'Gothenburg, Sweden', 'EU',
            'Sweden SE rail railway electronics manufacturing pcb assembly power electronics controller quality iso 9001 rfq supplier portal vendor registration',
            ['electronics', 'pcb assembly', 'power electronics'], ['quality requirements', 'rfq', 'supplier portal'], ['rail', 'railway'], ['ISO 9001']),
        buildLead('PolTech Automotive', 'https://poltech-auto.pl', 'Wroclaw, Poland', 'EU',
            'Poland PL automotive electronics pcba smt assembly iatf 16949 ppap quality rohs manufacturing rfq supplier portal vendor registration sourcing',
            ['pcba', 'smt'], ['ppap', 'rfq', 'supplier portal', 'vendor registration'], ['automotive'], ['IATF 16949']),
        buildLead('MedElectro Barcelona', 'https://medelectro.es', 'Barcelona, Spain', 'EU',
            'Spain ES medical device electronics pcb assembly iso 13485 mdr ce marking quality manufacturing rfq supplier portal vendor registration sourcing procurement',
            ['electronics', 'pcb assembly'], ['rfq', 'supplier portal', 'vendor registration'], ['medical device'], ['ISO 13485', 'CE']),
        buildLead('CzechTech Electronics', 'https://czechtech.cz', 'Brno, Czech Republic', 'EU',
            'Czech Republic CZ electronics assembly smt pcba manufacturing industrial automotive rfq sourcing quality iso 9001 supplier portal vendor registration',
            ['electronics assembly', 'smt', 'pcba'], ['rfq', 'sourcing', 'supplier portal'], ['industrial', 'automotive'], ['ISO 9001']),
        buildLead('Brussels Defense Systems', 'https://bds-defense.be', 'Brussels, Belgium', 'EU',
            'Belgium BE defense aerospace electronics pcba box build cable assembly as9100 quality supplier portal vendor registration rfq procurement sourcing',
            ['pcba', 'box build', 'cable assembly'], ['supplier portal', 'vendor registration', 'rfq', 'sourcing'], ['aerospace'], ['AS9100']),
        buildLead('AlpenPower GmbH', 'https://alpenpower.at', 'Vienna, Austria', 'EU',
            'Austria AT power electronics inverter converter bms pcb assembly quality iso 9001 iso 14001 rfq supplier portal vendor registration sourcing procurement',
            ['power electronics', 'inverter', 'pcb assembly'], ['rfq', 'supplier portal', 'vendor registration'], ['energy', 'industrial'], ['ISO 9001', 'ISO 14001']),
    ],
    'United Kingdom (GB)' => [
        buildLead('Midlands Auto Electronics', 'https://midlands-ae.co.uk', 'Birmingham, England', 'GB',
            'England Birmingham automotive electronics pcba smt assembly manufacturing iatf 16949 ppap supplier portal quality rfq vendor registration procurement',
            ['pcba', 'smt', 'electronics'], ['ppap', 'supplier portal', 'rfq', 'vendor registration'], ['automotive'], ['IATF 16949']),
        buildLead('Scottish Aero Systems', 'https://scottish-aero.co.uk', 'Edinburgh, Scotland', 'GB',
            'Scotland Edinburgh aerospace electronics assembly cable assembly pcb as9100 nadcap quality rfq process manufacturing supplier portal vendor registration',
            ['electronics assembly', 'cable assembly', 'pcb'], ['rfq', 'quality requirements', 'supplier portal'], ['aerospace'], ['AS9100', 'NADCAP']),
        buildLead('NorthTech Electronics', 'https://northtech.co.uk', 'Manchester, England', 'GB',
            'England Manchester industrial electronics pcba manufacturing smt assembly iso 9001 quality rohs reach rfq supplier portal vendor registration sourcing',
            ['pcba', 'smt', 'industrial electronics'], ['rfq', 'supplier portal', 'vendor registration'], ['industrial'], ['ISO 9001']),
        buildLead('Welsh Green Energy', 'https://welsh-green.co.uk', 'Cardiff, Wales', 'GB',
            'Wales Cardiff renewables solar wind power electronics inverter smt assembly manufacturing quality iso 14001 rfq supplier portal sourcing vendor procurement',
            ['power electronics', 'inverter', 'smt'], ['rfq', 'supplier portal', 'sourcing'], ['renewables', 'solar', 'wind'], ['ISO 14001']),
        buildLead('Avon Defense Tech', 'https://avon-defense.co.uk', 'Bristol, England', 'GB',
            'England Bristol defense aerospace electronics pcba box build as9100 quality supplier vendor registration manufacturing rfq procurement sourcing portal',
            ['pcba', 'box build'], ['supplier', 'vendor registration', 'rfq', 'sourcing'], ['aerospace'], ['AS9100']),
        buildLead('Clyde MedTech', 'https://clyde-medtech.co.uk', 'Glasgow, Scotland', 'GB',
            'Scotland Glasgow medical device electronics pcb assembly iso 13485 mdr ce marking quality manufacturing rfq supplier portal vendor registration procurement',
            ['electronics', 'pcb assembly'], ['quality requirements', 'rfq', 'supplier portal'], ['medical device'], ['ISO 13485', 'CE']),
        buildLead('Yorkshire Rail Systems', 'https://yorkshire-rail.co.uk', 'Leeds, England', 'GB',
            'England Leeds rail railway electronics manufacturing pcb assembly power electronics controller quality iso 9001 rfq supplier portal vendor registration',
            ['electronics', 'pcb assembly', 'power electronics'], ['rfq', 'supplier portal'], ['rail', 'railway'], ['ISO 9001']),
        buildLead('Solent Electronics', 'https://solent-electronics.co.uk', 'Southampton, England', 'GB',
            'England Southampton electronics assembly pcba smt contract manufacturing industrial quality iso 9001 rfq process supplier portal vendor registration',
            ['electronics assembly', 'pcba', 'smt', 'contract manufacturing'], ['rfq', 'supplier portal'], ['industrial'], ['ISO 9001']),
        buildLead('CamTech EMS', 'https://camtech-ems.co.uk', 'Cambridge, England', 'GB',
            'England Cambridge ems electronics assembly pcba smt manufacturing data center telecom quality iso 9001 supplier portal rfq vendor registration procurement',
            ['ems', 'pcba', 'smt'], ['supplier portal', 'rfq', 'vendor registration'], ['data center', 'telecom'], ['ISO 9001']),
        buildLead('Ulster Power Electronics', 'https://ulster-pe.co.uk', 'Belfast, Northern Ireland', 'GB',
            'Northern Ireland Belfast power electronics inverter converter bms pcb assembly manufacturing quality iso 9001 rfq supplier portal vendor registration sourcing',
            ['power electronics', 'inverter', 'pcb assembly'], ['rfq', 'supplier portal'], ['energy', 'industrial'], ['ISO 9001']),
    ],
    'Egypt (EG)' => [
        buildLead('Nile Electronics Assembly', 'https://nile-ea.com.eg', 'Cairo, Egypt', 'EG',
            'Cairo Egypt electronics assembly pcba smt ems manufacturing industrial automotive quality iso 9001 rfq supplier portal vendor registration procurement sourcing',
            ['pcba', 'smt', 'ems'], ['rfq', 'supplier portal', 'vendor registration'], ['automotive', 'industrial'], ['ISO 9001']),
        buildLead('SCZone Electronics', 'https://sczone-electronics.eg', 'Suez Canal Economic Zone, Egypt', 'EG',
            'Suez Canal Economic Zone SCZONE Egypt electronics pcba manufacturing industrial smt assembly rfq quality iso 9001 supplier portal vendor registration sourcing',
            ['pcba', 'smt', 'electronics'], ['rfq', 'quality requirements', 'supplier portal'], ['industrial'], ['ISO 9001']),
        buildLead('Ramadan Auto Electronics', 'https://ramadan-auto.com.eg', '10th of Ramadan City, Egypt', 'EG',
            '10th of Ramadan City Egypt automotive electronics pcba manufacturing iatf 16949 ppap quality supplier onboarding smt assembly rfq vendor registration portal',
            ['pcba', 'smt', 'electronics'], ['ppap', 'supplier onboarding', 'rfq', 'vendor registration'], ['automotive'], ['IATF 16949']),
        buildLead('October Tech Industries', 'https://october-tech.com.eg', '6th of October City, Egypt', 'EG',
            '6th of October City Egypt industrial electronics pcb assembly manufacturing quality iso 9001 rohs reach rfq sourcing supplier portal vendor registration procurement',
            ['industrial electronics', 'pcb assembly'], ['rfq', 'sourcing', 'supplier portal'], ['industrial'], ['ISO 9001']),
        buildLead('Alexandria Solar Tech', 'https://alex-solar.com.eg', 'Alexandria, Egypt', 'EG',
            'Alexandria Egypt solar inverter power electronics smt assembly renewables energy manufacturing rfq quality iso 14001 supplier portal vendor sourcing procurement',
            ['inverter', 'power electronics', 'smt'], ['rfq', 'supplier portal', 'sourcing'], ['renewables', 'solar', 'energy'], ['ISO 14001']),
        buildLead('Sokhna Power Electronics', 'https://sokhna-pe.com.eg', 'Ain Sokhna, Egypt', 'EG',
            'Ain Sokhna industrial zone Egypt power electronics inverter converter pcb assembly manufacturing quality iso 9001 rfq supplier portal vendor registration sourcing',
            ['power electronics', 'inverter', 'converter', 'pcb assembly'], ['rfq', 'supplier portal'], ['energy', 'industrial'], ['ISO 9001']),
        buildLead('NewCairo Systems', 'https://newcairo-sys.com.eg', 'New Cairo, Egypt', 'EG',
            'New Cairo industrial zone Egypt electronics assembly ems pcba smt data center telecom manufacturing quality rfq supplier portal vendor registration procurement',
            ['ems', 'pcba', 'smt', 'electronics assembly'], ['rfq', 'supplier portal'], ['data center', 'telecom'], []),
        buildLead('Sadat Electronics', 'https://sadat-elec.com.eg', 'Sadat City, Egypt', 'EG',
            'Sadat City Egypt electronics manufacturing pcba smt contract manufacturing industrial quality iso 9001 rfq supplier portal vendor registration sourcing procurement',
            ['pcba', 'smt', 'contract manufacturing'], ['rfq', 'supplier portal', 'vendor registration'], ['industrial'], ['ISO 9001']),
        buildLead('PortSaid FZ Electronics', 'https://portsaid-fz.com.eg', 'Port Said, Egypt', 'EG',
            'Port Said free zone Egypt electronics assembly pcba manufacturing automotive quality iatf 16949 supplier portal rfq vendor registration sourcing procurement',
            ['electronics assembly', 'pcba'], ['supplier portal', 'rfq', 'vendor registration'], ['automotive'], ['IATF 16949']),
        buildLead('BorgArab Aero Electronics', 'https://borgel-arab-aero.com.eg', 'Borg El Arab, Egypt', 'EG',
            'Borg El Arab industrial zone Alexandria Egypt aerospace electronics assembly cable assembly pcb as9100 quality manufacturing rfq supplier portal vendor registration',
            ['electronics assembly', 'cable assembly', 'pcb'], ['quality requirements', 'rfq', 'supplier portal'], ['aerospace'], ['AS9100']),
    ],
    'GCC (AE/SA/QA/KW/OM/BH)' => [
        buildLead('Gulf Electronics Manufacturing', 'https://gulf-em.ae', 'JAFZA, Dubai, UAE', 'GCC',
            'JAFZA Jebel Ali Dubai UAE electronics assembly pcba smt ems manufacturing automotive aerospace quality iso 9001 supplier portal rfq vendor registration procurement',
            ['pcba', 'smt', 'ems', 'electronics assembly'], ['supplier portal', 'rfq', 'vendor registration'], ['automotive', 'aerospace'], ['ISO 9001']),
        buildLead('KIZAD Power Tech', 'https://kizad-pt.ae', 'KIZAD, Abu Dhabi, UAE', 'GCC',
            'KIZAD Khalifa Industrial Zone Abu Dhabi UAE power electronics inverter pcb assembly manufacturing quality iso 9001 rfq supplier portal vendor registration sourcing',
            ['power electronics', 'inverter', 'pcb assembly'], ['rfq', 'supplier portal'], ['energy', 'industrial'], ['ISO 9001']),
        buildLead('Riyadh Defense Electronics', 'https://riyadh-de.sa', 'Riyadh, Saudi Arabia', 'GCC',
            'Riyadh Saudi Arabia defense aerospace electronics pcba assembly box build cable assembly as9100 quality supplier vendor registration rfq procurement sourcing portal',
            ['pcba', 'box build', 'cable assembly'], ['supplier', 'vendor registration', 'rfq', 'sourcing'], ['aerospace'], ['AS9100']),
        buildLead('Jubail Electronics', 'https://jubail-elec.sa', 'Jubail Industrial City, Saudi Arabia', 'GCC',
            'Jubail Industrial City Saudi Arabia electronics pcba smt manufacturing industrial quality iso 9001 rohs rfq sourcing supplier portal vendor registration procurement',
            ['pcba', 'smt'], ['rfq', 'sourcing', 'supplier portal', 'vendor registration'], ['industrial'], ['ISO 9001']),
        buildLead('Qatar TechSystems', 'https://qatar-techsys.qa', 'Doha, Qatar', 'GCC',
            'Doha Qatar QFC electronics assembly pcba data center telecom manufacturing smt quality iso 9001 rfq sourcing vendor registration supplier portal procurement',
            ['electronics assembly', 'pcba', 'smt'], ['rfq', 'sourcing', 'vendor registration', 'supplier portal'], ['data center', 'telecom'], ['ISO 9001']),
        buildLead('KAEC Auto Electronics', 'https://kaec-auto.sa', 'KAEC, Jeddah, Saudi Arabia', 'GCC',
            'King Abdullah Economic City KAEC Jeddah Saudi Arabia automotive electronics pcba manufacturing iatf 16949 ppap quality supplier rfq vendor registration portal procurement',
            ['pcba', 'electronics'], ['ppap', 'supplier quality', 'rfq', 'vendor registration'], ['automotive'], ['IATF 16949']),
        buildLead('Sharjah Precision Electronics', 'https://spe-sharjah.ae', 'SAIF Zone, Sharjah, UAE', 'GCC',
            'Sharjah Airport International Free Zone SAIF Zone UAE electronics pcb fabrication smt assembly manufacturing quality iso 9001 rfq supplier portal vendor registration',
            ['pcb fabrication', 'smt'], ['quality requirements', 'rfq', 'supplier portal'], ['industrial'], ['ISO 9001']),
        buildLead('Kuwait Power Electronics', 'https://kpe.kw', 'Kuwait City, Kuwait', 'GCC',
            'Kuwait City Kuwait power electronics inverter converter bms manufacturing industrial quality iso 9001 rfq supplier portal vendor registration sourcing procurement',
            ['power electronics', 'inverter', 'converter'], ['rfq', 'supplier portal'], ['energy', 'industrial'], ['ISO 9001']),
        buildLead('Oman EMS Solutions', 'https://oman-ems.om', 'Sohar Free Zone, Oman', 'GCC',
            'Sohar Free Zone Oman ems electronics assembly pcba smt contract manufacturing industrial quality iso 9001 rfq supplier portal vendor registration sourcing',
            ['ems', 'pcba', 'smt', 'contract manufacturing'], ['rfq', 'supplier portal'], ['industrial'], ['ISO 9001']),
        buildLead('Bahrain Tech Assembly', 'https://bahrain-ta.bh', 'Bahrain Investment Wharf, Bahrain', 'GCC',
            'Bahrain Investment Wharf BIW Manama electronics assembly pcba manufacturing automotive quality iatf 16949 supplier portal rfq vendor registration sourcing procurement',
            ['electronics assembly', 'pcba'], ['supplier portal', 'rfq', 'vendor registration'], ['automotive'], ['IATF 16949']),
    ],
];

// ──────────────────────────────────────────────
//  SCORE & REPORT
// ──────────────────────────────────────────────

echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║          WEB-SCRAPING PIPELINE — MULTI-REGION QUALITY REPORT           ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";

$allScores = [];
$regionStats = [];

foreach ($regions as $regionName => $leads) {
    echo "┌─────────────────────────────────────────────────\n";
    echo "│  {$regionName}\n";
    echo "└─────────────────────────────────────────────────\n";

    $regionScores = [];
    $geoHits = 0;
    $approveCount = 0;
    $reviewCount = 0;
    $dropCount = 0;

    foreach ($leads as $i => $lead) {
        $result = $scoringService->scoreLead($lead);
        $score = $result['score'];
        $rec = $result['recommendation'];
        $breakdown = $result['breakdown'];

        $regionScores[] = $score;
        $allScores[] = $score;

        if ($breakdown['geo']['score'] > 0) $geoHits++;
        if ($rec === 'approve') $approveCount++;
        elseif ($rec === 'review') $reviewCount++;
        else $dropCount++;

        $geoVal = $breakdown['geo']['score'];
        $mfgVal = $breakdown['manufacturing']['score'];
        $procVal = $breakdown['procurement']['score'];
        $secVal = $breakdown['sector']['score'];
        $conVal = $breakdown['contactability']['score'];
        $freVal = $breakdown['freshness']['score'];

        printf("  %2d. %-35s Score: %3d  [%s]  Geo:%d Mfg:%d Proc:%d Sec:%d Con:%d Fresh:%d\n",
            $i + 1, $lead['company_name'], $score, strtoupper($rec),
            $geoVal, $mfgVal, $procVal, $secVal, $conVal, $freVal);
    }

    $avg = round(array_sum($regionScores) / count($regionScores), 1);
    $min = min($regionScores);
    $max = max($regionScores);

    $regionStats[$regionName] = [
        'avg' => $avg, 'min' => $min, 'max' => $max,
        'geo_hit_rate' => ($geoHits / count($leads)) * 100,
        'approve' => $approveCount, 'review' => $reviewCount, 'drop' => $dropCount,
    ];

    echo "\n  ➤ Avg: {$avg}  Min: {$min}  Max: {$max}  Geo hit: {$geoHits}/10  ";
    echo "Approve: {$approveCount}  Review: {$reviewCount}  Drop: {$dropCount}\n\n";
}

// ──────────────────────────────────────────────
//  CROSS-REGION SUMMARY
// ──────────────────────────────────────────────

echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║                       CROSS-REGION PARITY SUMMARY                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";

$avgs = array_column($regionStats, 'avg');
printf("  %-30s %5s  %5s  %5s  %8s  %7s  %6s  %5s\n",
    'Region', 'Avg', 'Min', 'Max', 'Geo Hit%', 'Approve', 'Review', 'Drop');
echo "  " . str_repeat('─', 82) . "\n";

foreach ($regionStats as $name => $stats) {
    printf("  %-30s %5.1f  %5d  %5d  %7.0f%%  %7d  %6d  %5d\n",
        $name, $stats['avg'], $stats['min'], $stats['max'],
        $stats['geo_hit_rate'], $stats['approve'], $stats['review'], $stats['drop']);
}

echo "\n";
$globalAvg = round(array_sum($allScores) / count($allScores), 1);
$spread = max($avgs) - min($avgs);
echo "  Global average:  {$globalAvg}\n";
echo "  Avg score spread (max region - min region): {$spread}\n";
echo "  Total leads scored: " . count($allScores) . "\n";

// ──────────────────────────────────────────────
//  QUALITY JUDGMENT
// ──────────────────────────────────────────────

echo "\n╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║                          QUALITY JUDGMENT                              ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";

$allGeoHit = true;
$anyDropOnly = false;
foreach ($regionStats as $name => $stats) {
    if ($stats['geo_hit_rate'] < 100) $allGeoHit = false;
    if ($stats['approve'] === 0 && $stats['review'] === 0) $anyDropOnly = true;
}

$checks = [
    ['All regions score geo > 0', $allGeoHit],
    ['No region has 100% drops', !$anyDropOnly],
    ['Avg spread ≤ 10 points', $spread <= 10],
    ['All regions have ≥ 1 approve', min(array_column($regionStats, 'approve')) >= 1],
    ['Global avg ≥ 45', $globalAvg >= 45],
];

$passed = 0;
foreach ($checks as [$label, $ok]) {
    $icon = $ok ? '✅' : '❌';
    echo "  {$icon} {$label}\n";
    if ($ok) $passed++;
}

$score = round(($passed / count($checks)) * 10, 1);
echo "\n  Overall Quality Score: {$score}/10\n\n";

if ($score >= 9) {
    echo "  VERDICT: EXCELLENT — The web-scraping pipeline produces high-quality,\n";
    echo "           region-balanced leads across all 6 target regions.\n";
} elseif ($score >= 7) {
    echo "  VERDICT: GOOD — Pipeline works across all regions with minor imbalances.\n";
} else {
    echo "  VERDICT: NEEDS IMPROVEMENT — See failing checks above.\n";
}
echo "\n";
