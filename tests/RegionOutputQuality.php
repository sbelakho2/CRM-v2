<?php
/**
 * Region Output Quality — generates 10+ sample leads per region
 * through LeadSalesAnalystService::analyzeLead() and prints results.
 *
 * Run:  php tests/RegionOutputQuality.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Entity\Lead;
use App\Service\LeadSalesAnalystService;
use Psr\Log\NullLogger;

$analyst = new LeadSalesAnalystService(new NullLogger());

// ───── Region Lead definitions ─────
$regions = [
    // ——— Morocco (MA) ———
    'Morocco' => [
        ['name' => 'Tanger Automotive Parts', 'region' => 'MA', 'location' => 'Tanger Free Zone, Morocco', 'morocco' => true, 'sectors' => ['automotive'], 'fit' => ['smt' => true, 'through_hole' => true, 'pcba' => true], 'certs' => ['IATF 16949', 'ISO 9001'], 'score' => 85, 'notes' => 'Hiring QA engineers, ISO 14001 audit upcoming', 'defense' => false],
        ['name' => 'Casablanca Aero Systems', 'region' => 'MA', 'location' => 'Casablanca, Morocco', 'morocco' => true, 'sectors' => ['aerospace', 'defense'], 'fit' => ['pcba' => true, 'box_build' => true, 'conformal_coating' => true], 'certs' => ['AS9100', 'ISO 9001', 'ITAR'], 'score' => 92, 'notes' => 'Expanding production line, mentioned supply chain diversification', 'defense' => true],
        ['name' => 'Rabat Medical Devices', 'region' => 'MA', 'location' => 'Rabat, Morocco', 'morocco' => true, 'sectors' => ['medical'], 'fit' => ['smt' => true, 'functional_test' => true, 'x_ray_inspection' => true], 'certs' => ['ISO 13485', 'ISO 9001', 'CE'], 'score' => 78, 'notes' => 'New product launch in Q2, fast prototyping needs', 'defense' => false],
        ['name' => 'Kenitra Industrial Electronics', 'region' => 'MA', 'location' => 'Kenitra, Morocco', 'morocco' => true, 'sectors' => ['industrial'], 'fit' => ['pcba' => true, 'cable_assembly' => true], 'certs' => ['ISO 9001'], 'score' => 65, 'notes' => 'Cost reduction initiative underway', 'defense' => false],
        ['name' => 'Fez Renewable Energy Corp', 'region' => 'MA', 'location' => 'Fez, Morocco', 'morocco' => true, 'sectors' => ['renewables', 'power_electronics'], 'fit' => ['smt' => true, 'pcba' => true, 'testing' => true], 'certs' => ['ISO 9001', 'IPC-A-610', 'CE'], 'score' => 74, 'notes' => 'Rapid scaling, overtime issues reported', 'defense' => false],
        ['name' => 'Marrakech Telecom Solutions', 'region' => 'MA', 'location' => 'Marrakech, Morocco', 'morocco' => true, 'sectors' => ['telecommunications'], 'fit' => ['smt' => true, 'aoi' => true, 'prototyping' => true], 'certs' => ['ISO 9001', 'RoHS'], 'score' => 71, 'notes' => 'Looking for NPI partner', 'defense' => false],
        ['name' => 'Agadir Marine Electronics', 'region' => 'MA', 'location' => 'Agadir, Morocco', 'morocco' => true, 'sectors' => ['industrial'], 'fit' => ['pcba' => true, 'conformal_coating' => true], 'certs' => ['ISO 9001', 'IPC-A-610'], 'score' => 60, 'notes' => 'Small batch production, looking for reliable EMS', 'defense' => false],
        ['name' => 'Tanger Med Logistics EMS', 'region' => 'MA', 'location' => 'Tanger Med, Morocco', 'morocco' => true, 'sectors' => ['automotive', 'industrial'], 'fit' => ['pcba' => true, 'smt' => true, 'bom_sourcing' => true, 'dfa_review' => true], 'certs' => ['IATF 16949', 'ISO 9001', 'ISO 14001'], 'score' => 88, 'notes' => 'Major Tier-1 supplier, procurement hiring surge', 'defense' => false],
        ['name' => 'Oujda Defense Systems', 'region' => 'MA', 'location' => 'Oujda, Morocco', 'morocco' => true, 'sectors' => ['defense', 'aerospace'], 'fit' => ['box_build' => true, 'functional_test' => true, 'conformal_coating' => true], 'certs' => ['AS9100', 'ISO 9001'], 'score' => 82, 'notes' => 'Government contracts, compliance complexity mentioned', 'defense' => true],
        ['name' => 'Meknes Agricultural Tech', 'region' => 'MA', 'location' => 'Meknes, Morocco', 'morocco' => true, 'sectors' => ['industrial'], 'fit' => ['pcba' => true, 'testing' => true], 'certs' => ['ISO 9001', 'CE'], 'score' => 55, 'notes' => 'IoT sensors for agriculture, startup pace', 'defense' => false],
    ],

    // ——— EU ———
    'EU' => [
        ['name' => 'München Avionics GmbH', 'region' => 'EU', 'location' => 'Munich, Germany', 'morocco' => false, 'sectors' => ['aerospace'], 'fit' => ['smt' => true, 'pcba' => true, 'conformal_coating' => true, 'x_ray_inspection' => true], 'certs' => ['AS9100', 'ISO 9001', 'ISO 14001'], 'score' => 90, 'notes' => 'Audit mentions shortage concerns, diversification interest', 'defense' => false],
        ['name' => 'Paris Semiconductor SA', 'region' => 'EU', 'location' => 'Paris, France', 'morocco' => false, 'sectors' => ['telecommunications', 'industrial'], 'fit' => ['smt' => true, 'aoi' => true, 'npi' => true], 'certs' => ['ISO 9001', 'RoHS', 'CE'], 'score' => 76, 'notes' => 'Product launch imminent, fast prototyping needed', 'defense' => false],
        ['name' => 'Amsterdam Power BV', 'region' => 'EU', 'location' => 'Amsterdam, Netherlands', 'morocco' => false, 'sectors' => ['power_electronics', 'renewables'], 'fit' => ['pcba' => true, 'testing' => true, 'through_hole' => true], 'certs' => ['ISO 9001', 'IPC-A-610', 'UL'], 'score' => 72, 'notes' => 'Margin pressure, procurement restructuring', 'defense' => false],
        ['name' => 'Bruxelles Medical NV', 'region' => 'EU', 'location' => 'Brussels, Belgium', 'morocco' => false, 'sectors' => ['medical'], 'fit' => ['smt' => true, 'functional_test' => true, 'x_ray_inspection' => true], 'certs' => ['ISO 13485', 'CE', 'ISO 9001'], 'score' => 83, 'notes' => 'Regulatory changes upcoming, compliance hiring underway', 'defense' => false],
        ['name' => 'Berlin Robotik AG', 'region' => 'EU', 'location' => 'Berlin, Germany', 'morocco' => false, 'sectors' => ['industrial', 'automotive'], 'fit' => ['pcba' => true, 'cable_assembly' => true, 'box_build' => true], 'certs' => ['IATF 16949', 'ISO 9001'], 'score' => 79, 'notes' => 'Production hiring, demand spike in robotics arm controllers', 'defense' => false],
        ['name' => 'Vienna Defence Tech GmbH', 'region' => 'EU', 'location' => 'Vienna, Austria', 'morocco' => false, 'sectors' => ['defense', 'aerospace'], 'fit' => ['box_build' => true, 'conformal_coating' => true, 'functional_test' => true], 'certs' => ['AS9100', 'ISO 9001'], 'score' => 86, 'notes' => 'ITAR clearance discussions, long-term contract potential', 'defense' => true],
        ['name' => 'Stockholm Green Energy AB', 'region' => 'EU', 'location' => 'Stockholm, Sweden', 'morocco' => false, 'sectors' => ['renewables'], 'fit' => ['smt' => true, 'pcba' => true, 'prototyping' => true], 'certs' => ['ISO 9001', 'ISO 14001', 'CE'], 'score' => 68, 'notes' => 'Startup mentality, rapid iterations', 'defense' => false],
        ['name' => 'Milano Automotive Srl', 'region' => 'EU', 'location' => 'Milan, Italy', 'morocco' => false, 'sectors' => ['automotive'], 'fit' => ['smt' => true, 'pcba' => true, 'bom_sourcing' => true], 'certs' => ['IATF 16949', 'ISO 9001', 'RoHS'], 'score' => 81, 'notes' => 'Supply chain mentions, QA job openings visible', 'defense' => false],
        ['name' => 'Prague Instrumentation sro', 'region' => 'EU', 'location' => 'Prague, Czech Republic', 'morocco' => false, 'sectors' => ['industrial'], 'fit' => ['pcba' => true, 'testing' => true, 'aoi' => true], 'certs' => ['ISO 9001', 'IPC-A-610'], 'score' => 63, 'notes' => 'Cost-conscious, looking for Eastern-quality at lower price', 'defense' => false],
        ['name' => 'Madrid Telecom SA', 'region' => 'EU', 'location' => 'Madrid, Spain', 'morocco' => false, 'sectors' => ['telecommunications'], 'fit' => ['smt' => true, 'cable_assembly' => true], 'certs' => ['ISO 9001', 'CE'], 'score' => 59, 'notes' => 'Exploring Morocco-based suppliers for proximity', 'defense' => false],
    ],

    // ——— US ———
    'US' => [
        ['name' => 'Texas Instruments Assembly Inc', 'region' => 'US', 'location' => 'Dallas, Texas', 'morocco' => false, 'sectors' => ['industrial', 'automotive'], 'fit' => ['smt' => true, 'pcba' => true, 'bom_sourcing' => true, 'dfa_review' => true], 'certs' => ['IATF 16949', 'ISO 9001', 'IPC-A-610'], 'score' => 88, 'notes' => 'Major reshoring initiative, seeking nearshore alternatives to Asia', 'defense' => false, 'usState' => 'TX', 'usCity' => 'Dallas'],
        ['name' => 'Boston MedTech Corp', 'region' => 'US', 'location' => 'Boston, Massachusetts', 'morocco' => false, 'sectors' => ['medical'], 'fit' => ['smt' => true, 'functional_test' => true, 'x_ray_inspection' => true], 'certs' => ['ISO 13485', 'ISO 9001', 'FDA'], 'score' => 91, 'notes' => 'FDA audit pending, QA engineer openings posted', 'defense' => false, 'usState' => 'MA', 'usCity' => 'Boston'],
        ['name' => 'Silicon Valley Startups LLC', 'region' => 'US', 'location' => 'San Jose, California', 'morocco' => false, 'sectors' => ['telecommunications', 'industrial'], 'fit' => ['prototyping' => true, 'npi' => true, 'smt' => true], 'certs' => ['ISO 9001', 'RoHS'], 'score' => 70, 'notes' => 'Startup, fast prototyping needs, product launch soon', 'defense' => false, 'usState' => 'CA', 'usCity' => 'San Jose'],
        ['name' => 'Detroit Auto Electronics', 'region' => 'US', 'location' => 'Detroit, Michigan', 'morocco' => false, 'sectors' => ['automotive'], 'fit' => ['pcba' => true, 'smt' => true, 'through_hole' => true, 'cable_assembly' => true], 'certs' => ['IATF 16949', 'ISO 9001', 'J-STD-001'], 'score' => 85, 'notes' => 'Tier-2 supplier capacity constraints, overtime mentions', 'defense' => false, 'usState' => 'MI', 'usCity' => 'Detroit'],
        ['name' => 'Phoenix Aerospace Inc', 'region' => 'US', 'location' => 'Phoenix, Arizona', 'morocco' => false, 'sectors' => ['aerospace', 'defense'], 'fit' => ['box_build' => true, 'conformal_coating' => true, 'functional_test' => true], 'certs' => ['AS9100', 'ISO 9001', 'ITAR'], 'score' => 93, 'notes' => 'ITAR program, looking for diversified manufacturing base', 'defense' => true, 'usState' => 'AZ', 'usCity' => 'Phoenix'],
        ['name' => 'Denver Renewables Corp', 'region' => 'US', 'location' => 'Denver, Colorado', 'morocco' => false, 'sectors' => ['renewables', 'power_electronics'], 'fit' => ['pcba' => true, 'smt' => true, 'testing' => true], 'certs' => ['ISO 9001', 'UL', 'CE'], 'score' => 73, 'notes' => 'Government grant received, scaling production', 'defense' => false, 'usState' => 'CO', 'usCity' => 'Denver'],
        ['name' => 'Chicago Industrial Controls', 'region' => 'US', 'location' => 'Chicago, Illinois', 'morocco' => false, 'sectors' => ['industrial'], 'fit' => ['pcba' => true, 'cable_assembly' => true, 'box_build' => true], 'certs' => ['ISO 9001', 'IPC-A-610'], 'score' => 67, 'notes' => 'Cost reduction initiative, procurement hiring', 'defense' => false, 'usState' => 'IL', 'usCity' => 'Chicago'],
        ['name' => 'Seattle Avionics LLC', 'region' => 'US', 'location' => 'Seattle, Washington', 'morocco' => false, 'sectors' => ['aerospace'], 'fit' => ['smt' => true, 'pcba' => true, 'conformal_coating' => true, 'aoi' => true], 'certs' => ['AS9100', 'ISO 9001', 'J-STD-001'], 'score' => 87, 'notes' => 'Boeing supply chain tier, quality hiring visible', 'defense' => false, 'usState' => 'WA', 'usCity' => 'Seattle'],
        ['name' => 'Atlanta Telecom Inc', 'region' => 'US', 'location' => 'Atlanta, Georgia', 'morocco' => false, 'sectors' => ['telecommunications'], 'fit' => ['smt' => true, 'prototyping' => true], 'certs' => ['ISO 9001', 'RoHS', 'CE'], 'score' => 62, 'notes' => 'Exploring offshore options for cost savings', 'defense' => false, 'usState' => 'GA', 'usCity' => 'Atlanta'],
        ['name' => 'Austin Defense Systems', 'region' => 'US', 'location' => 'Austin, Texas', 'morocco' => false, 'sectors' => ['defense'], 'fit' => ['box_build' => true, 'functional_test' => true, 'x_ray_inspection' => true], 'certs' => ['AS9100', 'ISO 9001', 'ITAR'], 'score' => 90, 'notes' => 'Government contract won, compliance audit upcoming', 'defense' => true, 'usState' => 'TX', 'usCity' => 'Austin'],
    ],

    // ——— Egypt (EG) ———
    'Egypt' => [
        ['name' => 'Cairo Electronics Manufacturing', 'region' => 'EG', 'location' => 'Cairo, Egypt', 'morocco' => false, 'sectors' => ['industrial', 'telecommunications'], 'fit' => ['pcba' => true, 'smt' => true, 'cable_assembly' => true], 'certs' => ['ISO 9001'], 'score' => 72, 'notes' => 'Expanding to Suez Canal Economic Zone, hiring ramp-up', 'defense' => false],
        ['name' => 'Alexandria Automotive Corp', 'region' => 'EG', 'location' => 'Alexandria, Egypt', 'morocco' => false, 'sectors' => ['automotive'], 'fit' => ['smt' => true, 'pcba' => true, 'through_hole' => true], 'certs' => ['IATF 16949', 'ISO 9001'], 'score' => 80, 'notes' => 'Tier-2 supplier for European OEMs, quality audits', 'defense' => false],
        ['name' => 'Suez Canal Tech Solutions', 'region' => 'EG', 'location' => 'Suez, Egypt', 'morocco' => false, 'sectors' => ['industrial'], 'fit' => ['pcba' => true, 'testing' => true], 'certs' => ['ISO 9001', 'IPC-A-610'], 'score' => 65, 'notes' => 'Strategic location near canal, logistics advantage', 'defense' => false],
        ['name' => '6th October Aerospace Ltd', 'region' => 'EG', 'location' => '6th of October City, Egypt', 'morocco' => false, 'sectors' => ['aerospace'], 'fit' => ['box_build' => true, 'conformal_coating' => true, 'functional_test' => true], 'certs' => ['AS9100', 'ISO 9001'], 'score' => 84, 'notes' => 'Military contracts, export compliance needs', 'defense' => true],
        ['name' => 'New Cairo MedTech', 'region' => 'EG', 'location' => 'New Cairo, Egypt', 'morocco' => false, 'sectors' => ['medical'], 'fit' => ['smt' => true, 'functional_test' => true, 'x_ray_inspection' => true], 'certs' => ['ISO 13485', 'ISO 9001', 'CE'], 'score' => 77, 'notes' => 'Growing medical device market, looking for certified EMS', 'defense' => false],
        ['name' => 'Port Said Marine Electronics', 'region' => 'EG', 'location' => 'Port Said, Egypt', 'morocco' => false, 'sectors' => ['industrial'], 'fit' => ['pcba' => true, 'conformal_coating' => true], 'certs' => ['ISO 9001', 'IPC-A-610'], 'score' => 58, 'notes' => 'Marine electronics, harsh environment coatings needed', 'defense' => false],
        ['name' => 'Ain Sokhna Renewables', 'region' => 'EG', 'location' => 'Ain Sokhna, Egypt', 'morocco' => false, 'sectors' => ['renewables', 'power_electronics'], 'fit' => ['pcba' => true, 'smt' => true, 'testing' => true], 'certs' => ['ISO 9001', 'UL', 'CE'], 'score' => 70, 'notes' => 'Solar inverter manufacturing, rapid scaling', 'defense' => false],
        ['name' => '10th Ramadan Defense Electronics', 'region' => 'EG', 'location' => '10th of Ramadan City, Egypt', 'morocco' => false, 'sectors' => ['defense', 'aerospace'], 'fit' => ['box_build' => true, 'functional_test' => true, 'conformal_coating' => true], 'certs' => ['AS9100', 'ISO 9001'], 'score' => 86, 'notes' => 'Expanding defense electronics capability, compliance hiring', 'defense' => true],
        ['name' => 'Giza Telecom Systems', 'region' => 'EG', 'location' => 'Giza, Egypt', 'morocco' => false, 'sectors' => ['telecommunications'], 'fit' => ['smt' => true, 'pcba' => true, 'prototyping' => true], 'certs' => ['ISO 9001', 'RoHS'], 'score' => 63, 'notes' => '5G infrastructure push, quick-turn prototyping needed', 'defense' => false],
        ['name' => 'Helwan Heavy Electronics', 'region' => 'EG', 'location' => 'Helwan, Egypt', 'morocco' => false, 'sectors' => ['industrial', 'power_electronics'], 'fit' => ['pcba' => true, 'through_hole' => true, 'cable_assembly' => true], 'certs' => ['ISO 9001', 'IPC-A-610'], 'score' => 56, 'notes' => 'Heavy industry supplier, cost pressure from Chinese imports', 'defense' => false],
    ],

    // ——— GCC ———
    'GCC' => [
        ['name' => 'Dubai Silicon Oasis Tech', 'region' => 'AE', 'location' => 'Dubai, UAE', 'morocco' => false, 'sectors' => ['telecommunications', 'industrial'], 'fit' => ['smt' => true, 'pcba' => true, 'npi' => true], 'certs' => ['ISO 9001', 'RoHS', 'CE'], 'score' => 79, 'notes' => 'Smart city initiative, IoT manufacturing ramp-up', 'defense' => false],
        ['name' => 'JAFZA Aerospace Assembly', 'region' => 'AE', 'location' => 'Jebel Ali Free Zone, UAE', 'morocco' => false, 'sectors' => ['aerospace'], 'fit' => ['box_build' => true, 'conformal_coating' => true, 'smt' => true, 'pcba' => true], 'certs' => ['AS9100', 'ISO 9001', 'ISO 14001'], 'score' => 88, 'notes' => 'MRO hub expansion, looking for certified EMS partners', 'defense' => false],
        ['name' => 'Abu Dhabi Defense Electronics', 'region' => 'AE', 'location' => 'Abu Dhabi, UAE', 'morocco' => false, 'sectors' => ['defense', 'aerospace'], 'fit' => ['box_build' => true, 'functional_test' => true, 'x_ray_inspection' => true], 'certs' => ['AS9100', 'ISO 9001', 'ITAR'], 'score' => 92, 'notes' => 'EDGE Group supplier, defense electronics expansion', 'defense' => true],
        ['name' => 'Riyadh Vision 2030 Electronics', 'region' => 'SA', 'location' => 'Riyadh, Saudi Arabia', 'morocco' => false, 'sectors' => ['industrial', 'power_electronics'], 'fit' => ['pcba' => true, 'smt' => true, 'bom_sourcing' => true], 'certs' => ['ISO 9001', 'IPC-A-610'], 'score' => 75, 'notes' => 'Vision 2030 localization mandate, looking for manufacturing partners', 'defense' => false],
        ['name' => 'Jeddah Automotive Systems', 'region' => 'SA', 'location' => 'Jeddah, Saudi Arabia', 'morocco' => false, 'sectors' => ['automotive'], 'fit' => ['smt' => true, 'pcba' => true, 'through_hole' => true], 'certs' => ['IATF 16949', 'ISO 9001'], 'score' => 73, 'notes' => 'EV assembly plant in KAEC, supply chain building phase', 'defense' => false],
        ['name' => 'NEOM Smart Tech', 'region' => 'SA', 'location' => 'NEOM, Saudi Arabia', 'morocco' => false, 'sectors' => ['industrial', 'renewables'], 'fit' => ['pcba' => true, 'smt' => true, 'prototyping' => true, 'npi' => true], 'certs' => ['ISO 9001', 'CE', 'RoHS'], 'score' => 82, 'notes' => 'NEOM mega-project, green hydrogen electronics, startup pace', 'defense' => false],
        ['name' => 'Doha Medical Devices', 'region' => 'QA', 'location' => 'Doha, Qatar', 'morocco' => false, 'sectors' => ['medical'], 'fit' => ['smt' => true, 'functional_test' => true, 'x_ray_inspection' => true], 'certs' => ['ISO 13485', 'ISO 9001', 'CE'], 'score' => 78, 'notes' => 'Qatar Health Strategy 2030, medical device localization', 'defense' => false],
        ['name' => 'Kuwait Oil & Gas Electronics', 'region' => 'KW', 'location' => 'Kuwait City, Kuwait', 'morocco' => false, 'sectors' => ['industrial', 'power_electronics'], 'fit' => ['pcba' => true, 'through_hole' => true, 'cable_assembly' => true], 'certs' => ['ISO 9001', 'IPC-A-610', 'ATEX'], 'score' => 69, 'notes' => 'Oil & gas electronics, hazardous environment certifications needed', 'defense' => false],
        ['name' => 'Muscat Telecom Assembly', 'region' => 'OM', 'location' => 'Muscat, Oman', 'morocco' => false, 'sectors' => ['telecommunications'], 'fit' => ['smt' => true, 'pcba' => true], 'certs' => ['ISO 9001', 'RoHS'], 'score' => 60, 'notes' => 'Oman ICT push, 5G rollout electronics assembly', 'defense' => false],
        ['name' => 'Bahrain FinTech Hardware', 'region' => 'BH', 'location' => 'Manama, Bahrain', 'morocco' => false, 'sectors' => ['industrial', 'telecommunications'], 'fit' => ['smt' => true, 'pcba' => true, 'prototyping' => true], 'certs' => ['ISO 9001', 'CE'], 'score' => 64, 'notes' => 'FinTech hub, payment terminal and POS hardware', 'defense' => false],
    ],
];

// ───── Output formatting helpers ─────
function separator(): string { return str_repeat('═', 100); }
function thinSep(): string { return str_repeat('─', 80); }
function grade(float $score): string {
    if ($score >= 90) return '  A+';
    if ($score >= 80) return '  A ';
    if ($score >= 70) return '  B ';
    if ($score >= 60) return '  C ';
    if ($score >= 50) return '  D ';
    return '  F ';
}

// ───── Run all regions ─────
$regionStats = [];

foreach ($regions as $regionLabel => $leads) {
    echo "\n" . separator() . "\n";
    echo "  REGION: {$regionLabel}   ({$regionLabel} — " . count($leads) . " leads)\n";
    echo separator() . "\n";

    $scores = [];
    $grades = [];
    $priorities = [];
    $starterCounts = [];
    $positioningCounts = [];
    $painCounts = [];
    $geoStarterHits = 0;
    $regionPositioningHits = 0;

    foreach ($leads as $i => $def) {
        $lead = new Lead();
        $lead->setCompanyName($def['name']);
        $lead->setRegionTag($def['region']);
        $lead->setSiteLocation($def['location']);
        $lead->setMoroccoSignal($def['morocco']);
        $lead->setSectorTags($def['sectors']);
        $lead->setFitSignals($def['fit']);
        $lead->setQualityStack($def['certs']);
        $lead->setLeadScore($def['score']);
        $lead->setNotesAuto($def['notes']);
        $lead->setDefenseFlag($def['defense']);
        if (isset($def['usState'])) $lead->setUsState($def['usState']);
        if (isset($def['usCity'])) $lead->setUsCityMetro($def['usCity']);

        $result = $analyst->analyzeLead($lead);

        // Collect stats
        $fit = $result['overall_fit_score'];
        $scores[] = $fit;
        $grades[] = $result['fit_grade'];
        $priorities[] = $result['priority_score'];
        $nStarters = count($result['conversation_starters']);
        $starterCounts[] = $nStarters;
        $positioningCounts[] = count($result['competitive_positioning']);
        $painCounts[] = count($result['pain_points']);

        // Check for geographic conversation starter
        $hasGeoStarter = false;
        foreach ($result['conversation_starters'] as $s) {
            if (($s['type'] ?? '') === 'geographic') {
                $hasGeoStarter = true;
                break;
            }
        }
        if ($hasGeoStarter) $geoStarterHits++;

        // Check for region-specific positioning (not "Geographic Flexibility")
        $hasRegionPos = false;
        foreach ($result['competitive_positioning'] as $p) {
            if (($p['differentiator'] ?? '') !== 'Geographic Flexibility') {
                $hasRegionPos = true;
                break;
            }
        }
        if ($hasRegionPos) $regionPositioningHits++;

        // Print individual lead output
        $num = $i + 1;
        echo "\n  [{$num}] {$def['name']}  (score: {$def['score']}, region: {$def['region']})\n";
        echo thinSep() . "\n";
        echo "      Fit Score     : {$result['overall_fit_score']}  ({$result['fit_grade']})\n";
        echo "      Priority      : {$result['priority_score']}\n";
        echo "      Approach      : {$result['recommended_approach']}\n";
        echo "      Pain Points   : " . count($result['pain_points']) . "\n";
        foreach ($result['pain_points'] as $pp) {
            echo "        • [{$pp['type']}] {$pp['evidence']}  →  {$pp['pitch']}\n";
        }
        echo "      Conv. Starters: {$nStarters}\n";
        foreach ($result['conversation_starters'] as $cs) {
            $tag = ($cs['type'] ?? '?');
            $topic = ($cs['topic'] ?? '—');
            echo "        ▸ [{$tag}|{$topic}] {$cs['opener']}\n";
            echo "          Follow-up: {$cs['follow_up']}\n";
        }
        echo "      Competitive Positioning: " . count($result['competitive_positioning']) . "\n";
        foreach ($result['competitive_positioning'] as $cp) {
            echo "        ◆ {$cp['differentiator']}: {$cp['message']}\n";
            echo "          vs: {$cp['vs_competitors']}\n";
        }
        echo "      Email Opener  : " . substr($result['email_opener'], 0, 120) . (strlen($result['email_opener']) > 120 ? '...' : '') . "\n";
    }

    // Region Summary
    echo "\n" . thinSep() . "\n";
    echo "  REGION SUMMARY: {$regionLabel}\n";
    echo thinSep() . "\n";
    echo "    Leads analyzed         : " . count($leads) . "\n";
    echo "    Avg fit score          : " . round(array_sum($scores) / count($scores), 1) . "\n";
    echo "    Avg priority           : " . round(array_sum($priorities) / count($priorities), 1) . "\n";
    echo "    Avg conv. starters     : " . round(array_sum($starterCounts) / count($starterCounts), 1) . "\n";
    echo "    Avg pain points        : " . round(array_sum($painCounts) / count($painCounts), 1) . "\n";
    echo "    Geo starter hit rate   : {$geoStarterHits}/" . count($leads) . " (" . round($geoStarterHits / count($leads) * 100) . "%)\n";
    echo "    Region positioning rate: {$regionPositioningHits}/" . count($leads) . " (" . round($regionPositioningHits / count($leads) * 100) . "%)\n";

    $regionStats[$regionLabel] = [
        'count' => count($leads),
        'avg_fit' => round(array_sum($scores) / count($scores), 1),
        'avg_priority' => round(array_sum($priorities) / count($priorities), 1),
        'avg_starters' => round(array_sum($starterCounts) / count($starterCounts), 1),
        'avg_pains' => round(array_sum($painCounts) / count($painCounts), 1),
        'geo_rate' => round($geoStarterHits / count($leads) * 100),
        'pos_rate' => round($regionPositioningHits / count($leads) * 100),
    ];
}

// ───── Cross-Region Comparison ─────
echo "\n" . separator() . "\n";
echo "  CROSS-REGION COMPARISON\n";
echo separator() . "\n";
echo str_pad('Region', 12) . str_pad('Leads', 7) . str_pad('Avg Fit', 9) . str_pad('Avg Pri', 9) . str_pad('Starters', 10) . str_pad('Pains', 7) . str_pad('Geo%', 6) . str_pad('Pos%', 6) . "\n";
echo str_repeat('-', 66) . "\n";
foreach ($regionStats as $r => $s) {
    echo str_pad($r, 12) . str_pad($s['count'], 7) . str_pad($s['avg_fit'], 9) . str_pad($s['avg_priority'], 9) . str_pad($s['avg_starters'], 10) . str_pad($s['avg_pains'], 7) . str_pad($s['geo_rate'] . '%', 6) . str_pad($s['pos_rate'] . '%', 6) . "\n";
}

// Parity check
$fits = array_column($regionStats, 'avg_fit');
$maxDiff = max($fits) - min($fits);
echo "\n  Fit score spread: " . min($fits) . " – " . max($fits) . " (Δ={$maxDiff})\n";
$geos = array_column($regionStats, 'geo_rate');
echo "  Geo starter spread: " . min($geos) . "% – " . max($geos) . "% (target: all 100%)\n";
$poss = array_column($regionStats, 'pos_rate');
echo "  Positioning spread: " . min($poss) . "% – " . max($poss) . "% (target: all 100%)\n";
echo "\n";
