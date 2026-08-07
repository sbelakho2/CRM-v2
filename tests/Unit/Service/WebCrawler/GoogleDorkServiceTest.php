<?php

namespace App\Tests\Unit\Service\WebCrawler;

use App\Service\WebCrawler\GoogleDorkService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleDorkServiceTest extends TestCase
{
    private GoogleDorkService $service;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new GoogleDorkService($this->httpClient, $this->logger);
    }

    public function testSearchCompaniesLogsCorrectly(): void
    {
        $sector = 'Automotive';
        $location = 'Tanger Free Zone';

        $captured = [];
        $this->logger->method('info')->willReturnCallback(function ($message) use (&$captured) {
            $captured[] = $message;
        });

        $result = $this->service->searchCompanies($sector, $location);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertNotEmpty($captured);
    }

    public function testSearchCompaniesWithoutLocation(): void
    {
        $sector = 'Aerospace';

        $result = $this->service->searchCompanies($sector);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testFindCompanyWebsiteLogsSearch(): void
    {
        $companyName = 'Test Company';

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Website search',
                $this->callback(function($context) use ($companyName) {
                    return $context['company'] === $companyName
                        && isset($context['url'])
                        && str_contains($context['url'], urlencode($companyName . ' official website'));
                })
            );

        $result = $this->service->findCompanyWebsite($companyName);

        // Currently returns null without API integration
        $this->assertNull($result);
    }

    public function testFindSupplierPortalGeneratesMultipleQueries(): void
    {
        $companyName = 'Test Company';

        // Expect multiple debug log calls for different dork queries
        $this->logger->expects($this->atLeast(4))
            ->method('debug')
            ->with('Portal search', $this->isType('array'));

        $result = $this->service->findSupplierPortal($companyName);

        // Currently returns null without API integration
        $this->assertNull($result);
    }

    public function testFindContactEmailsWithDomain(): void
    {
        $companyName = 'Test Company';
        $domain = 'testcompany.com';

        // Should generate queries with the provided domain
        $this->logger->expects($this->atLeast(3))
            ->method('debug')
            ->with('Email search', $this->isType('array'));

        $result = $this->service->findContactEmails($companyName, $domain);

        $this->assertIsArray($result);
    }

    public function testFindContactEmailsWithoutDomain(): void
    {
        $companyName = 'Test Company';

        // Should try to guess domain
        $result = $this->service->findContactEmails($companyName);

        $this->assertIsArray($result);
    }

    public function testFindCertificationsGeneratesQueries(): void
    {
        $companyName = 'Test Company';

        // Should generate queries for different certifications
        $this->logger->expects($this->atLeast(4))
            ->method('debug')
            ->with('Certification search', $this->isType('array'));

        $result = $this->service->findCertifications($companyName);

        $this->assertIsArray($result);
    }

    public function testSearchCompaniesGeneratesSectorSpecificQueries(): void
    {
        $automotiveSector = 'Automotive';
        $aerospaceSector = 'Aerospace';

        $auto = $this->service->searchCompanies($automotiveSector, 'Morocco');
        $aero = $this->service->searchCompanies($aerospaceSector, 'Morocco');

        $this->assertNotEmpty($auto);
        $this->assertNotEmpty($aero);
        $this->assertNotEquals($auto, $aero);
    }

    public function testSearchCompaniesHandlesAllTargetSectors(): void
    {
        $sectors = ['Automotive', 'Aerospace', 'Industrial', 'Rail', 'Renewables', 'Medical', 'Telecom', 'HVAC'];

        foreach ($sectors as $sector) {
            $this->logger->expects($this->atLeastOnce())
                ->method('info');

            $result = $this->service->searchCompanies($sector, 'Morocco');
            
            $this->assertIsArray($result);
        }
    }

    public function testFindSupplierPortalGeneratesCorrectDorkPatterns(): void
    {
        $companyName = 'TestCompany';

        $capturedQueries = [];
        $this->logger->method('debug')
            ->willReturnCallback(function($message, $context) use (&$capturedQueries) {
                if ($message === 'Portal search' && isset($context['query'])) {
                    $capturedQueries[] = $context['query'];
                }
            });

        $this->service->findSupplierPortal($companyName);

        // Verify some expected patterns are present
        $this->assertNotEmpty($capturedQueries);
        $hasSupplierQuery = false;
        foreach ($capturedQueries as $query) {
            if (str_contains($query, 'supplier')) {
                $hasSupplierQuery = true;
                break;
            }
        }
        $this->assertTrue($hasSupplierQuery, 'Should generate supplier-related queries');
    }

    // ─── Helper to call private methods via reflection ────────────────
    private function callPrivate(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(GoogleDorkService::class, $method);
        return $ref->invoke($this->service, ...$args);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  extractCompanyName() tests
    // ═══════════════════════════════════════════════════════════════════

    /** @dataProvider companyNameExtractionProvider */
    public function testExtractCompanyName(string $title, string $domain, string $expected): void
    {
        $result = $this->callPrivate('extractCompanyName', [$title, $domain]);
        $this->assertSame($expected, $result, "extractCompanyName('$title', '$domain')");
    }

    public static function companyNameExtractionProvider(): array
    {
        return [
            'simple company name' => ['Safran', 'safran.com', 'Safran'],
            'with separator' => ['BorgWarner | Global Automotive Supplier', 'borgwarner.com', 'BorgWarner'],
            'with dash page title' => ['About Us - Stellantis', 'stellantis.com', 'Stellantis'],
            'with colon home' => ['Delfingen: Home', 'delfingen.com', 'Delfingen'],
            'with homepage suffix' => ['ZF Group: Homepage', 'zf.com', 'ZF Group'],
            'with chevron nav' => ['Solutions » EVO Automotive', 'evoautomotive.com', 'Solutions'],
            'generic about us falls to domain' => ['About Us', 'ficosa.com', 'Ficosa'],
            'generic home falls to domain' => ['Home', 'magna.com', 'Magna'],
            'very long title falls to domain' => [
                'This is a very long title that describes a page about automotive wire harness manufacturing services and capabilities for OEM customers worldwide',
                'acmecorp.com',
                'Acmecorp',
            ],
            'domain extraction with hyphen' => ['Products', 'op-mobility.com', 'Op Mobility'],
            'domain extraction with subdomain' => ['Services', 'www.thalesaleniaspace.com', 'Thalesaleniaspace'],
            'strips trademark symbols' => ['Delfingen® Industries', 'delfingen.com', 'Delfingen Industries'],
            'strips trailing dots' => ['Archer Aviation...', 'archer.com', 'Archer Aviation'],
            'welcome to prefix' => ['Welcome to Firefly Aerospace', 'fireflyaerospace.com', 'Firefly Aerospace'],
            'products suffix after colon' => ['Eberspächer: Products', 'eberspaecher.com', 'Eberspächer'],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  isJunkCompanyName() tests
    // ═══════════════════════════════════════════════════════════════════

    /** @dataProvider junkCompanyNameProvider */
    public function testIsJunkCompanyName(string $name, bool $expectedJunk): void
    {
        $result = $this->callPrivate('isJunkCompanyName', [$name]);
        $this->assertSame($expectedJunk, $result, "isJunkCompanyName('$name')");
    }

    public static function junkCompanyNameProvider(): array
    {
        return [
            // ─── Should be JUNK (true) ────────────────────────────────
            'empty string' => ['', true],
            'single char' => ['A', true],
            'generic word cairo' => ['cairo', true],
            'product description' => ['Wire Harness for Toyota Camry 2024', true],
            'oem part listing' => ['Genuine Toyota Part PT12345', true],
            'too many lowercase words' => ['how to find the best cable harness supplier in germany', true],
            'sentence starter how' => ['How to outsource your PCB assembly', true],
            'sentence starter find' => ['Find contract electronics manufacturing', true],
            'page title list of' => ['List of all the Valeo sites around the world', true],
            'page title history' => ['History of EJ Darby & Son Ltd', true],
            'home page suffix' => ['MacDermid Alpha: Home Page', true],
            'company overview suffix' => ['Acme Corp Company Overview', true],
            'carrier heat pump' => ['Carrier Heat Pump Wire', true],
            'contract manufacturing phrase' => ['Contract Manufacturing and Supplier Services', true],
            'market report' => ['Wire Harness Market Growth Report 2025', true],
            'certification ref' => ['IATF 16949 Certification Guide', true],
            'service description' => ['wire harness assembly services', true],
            'verb structure we offer' => ['We offer PCB assembly and test', true],
            'verb structure you can' => ['You can find our products here', true],
            'eight words mixed case' => ['leading provider of electronic manufacturing services in the region', true],
            'panel building' => ['control panel wiring and building services', true],
            'MRO services' => ['MRO services for aircraft components', true],
            'test equipment' => ['test and measurement solutions provider', true],
            'brochure title' => ['Product Brochure for Industrial Solutions', true],
            'job posting' => ['Job Opening for Manufacturing Engineer', true],

            // ─── Should NOT be junk (false) ───────────────────────────
            'real company Safran' => ['Safran', false],
            'real company BorgWarner' => ['BorgWarner', false],
            'real company Stellantis' => ['Stellantis', false],
            'real company ZF Group' => ['ZF Group', false],
            'real company Magna Steyr' => ['Magna Steyr', false],
            'real company Thales Alenia Space' => ['Thales Alenia Space', false],
            'real company Archer Aviation' => ['Archer Aviation', false],
            'real company Elsewedy Electric' => ['Elsewedy Electric', false],
            'real company Delfingen' => ['Delfingen', false],
            'real company Ficosa' => ['Ficosa', false],
            'real company OPmobility' => ['OPmobility', false],
            'real company EMUS BMS' => ['EMUS BMS', false],
            'real company Varroc' => ['Varroc', false],
            'real company Gulfstream' => ['Gulfstream', false],
            'real company CPI Aerostructures' => ['CPI Aerostructures', false],
            'real company MTA Morocco' => ['MTA Morocco', false],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  isCompetitorOrWrongType() tests (snippet-based semantic filter)
    // ═══════════════════════════════════════════════════════════════════

    /** @dataProvider competitorSnippetProvider */
    public function testIsCompetitorOrWrongType(string $snippet, string $title, string $domain, bool $expectedReject): void
    {
        $result = $this->callPrivate('isCompetitorOrWrongType', [$snippet, $title, $domain]);
        $this->assertSame($expectedReject, $result, "isCompetitorOrWrongType for '$domain'");
    }

    public static function competitorSnippetProvider(): array
    {
        return [
            // ─── Should REJECT (competitors / wrong type) ─────────────
            'EMS provider snippet' => [
                'We manufacture PCB assemblies and cable harnesses for automotive OEMs. Full-service EMS provider.',
                'Acme Electronics - Contract Manufacturer',
                'acmeems.com',
                true,
            ],
            'PCB assembly service' => [
                'Leading PCB assembly service provider with SMT and through-hole assembly capabilities.',
                'FastPCB Assembly Services',
                'fastpcb.com',
                true,
            ],
            'cable harness manufacturer' => [
                'Specialist in bespoke cable harness design and manufacture for automotive and aerospace.',
                'WireWorks - Cable Harness Manufacturer',
                'wireworks.com',
                true,
            ],
            'contract electronics manufacturer' => [
                'Contract electronics manufacturer company providing prototype to production services.',
                'ProtoEMS',
                'protoems.com',
                true,
            ],
            'turnkey EMS' => [
                'Turnkey electronics manufacturing services from design to delivery.',
                'TotalEMS Solutions',
                'totalems.com',
                true,
            ],
            'wire harness supplier' => [
                'Custom wire harness manufacturer and supplier for industrial applications.',
                'HarnessPlus',
                'harnessplus.com',
                true,
            ],
            'distributor' => [
                'Authorized distributor of electronic components. We stock semiconductors and passives.',
                'ChipDist Electronics',
                'chipdist.com',
                true,
            ],
            'equipment supplier' => [
                'Wire processing machine manufacturer. Our crimping and stripping equipment serves harness makers.',
                'WireMach Equipment',
                'wiremach.com',
                true,
            ],
            'connector manufacturer' => [
                'Connector manufacturer and supplier for automotive and industrial applications.',
                'PinConnect',
                'pinconnect.com',
                true,
            ],
            'control panel builder' => [
                'Control panel manufacturer and builder. Bespoke control panel wiring and assembly.',
                'PanelTech Controls',
                'paneltech.com',
                true,
            ],
            'system integrator' => [
                'System integrator specializing in PLC programming and bespoke automation solutions.',
                'AutoIntegrate',
                'autointegrate.com',
                true,
            ],
            'MRO company' => [
                'Aircraft maintenance, repair and overhaul services. MRO provider for commercial fleets.',
                'AeroMRO Services',
                'aeromro.com',
                true,
            ],
            'SMT assembly line' => [
                'Our SMT assembly line features high-speed pick-and-place with AOI inspection.',
                'SMTLine Corp',
                'smtline.com',
                true,
            ],
            'box build assembly' => [
                'We provide box-build assembly and complete system integration for OEM customers.',
                'BoxBuild Pro',
                'boxbuildpro.com',
                true,
            ],
            'staffing agency' => [
                'Leading staffing agency specializing in manufacturing and engineering placements.',
                'TechStaff Solutions',
                'techstaff.com',
                true,
            ],
            'recruitment firm' => [
                'We recruit engineers and technical staff for the automotive and aerospace sectors.',
                'EngiRecruit Ltd',
                'engirecruit.com',
                true,
            ],
            'consulting firm' => [
                'Management consulting firm advising manufacturers on supply chain optimization.',
                'StratConsult Partners',
                'stratconsult.com',
                true,
            ],
            'training provider' => [
                'Accredited training provider offering IPC certification courses for electronics manufacturing.',
                'CertTrain Academy',
                'certtrain.com',
                true,
            ],

            // ─── Should KEEP (genuine OEM buyers / prospects) ─────────
            'automotive OEM' => [
                'Stellantis is a global automotive OEM with 14 brands including Peugeot, Fiat, and Jeep.',
                'Stellantis',
                'stellantis.com',
                false,
            ],
            'aerospace OEM' => [
                'Safran is a leading international high-technology group in aerospace and defense.',
                'Safran Group',
                'safran-group.com',
                false,
            ],
            'tier 1 supplier' => [
                'BorgWarner delivers innovative powertrain solutions for combustion, hybrid and electric vehicles.',
                'BorgWarner',
                'borgwarner.com',
                false,
            ],
            'industrial OEM' => [
                'Elsewedy Electric is a global energy solutions provider serving utilities and industrial clients.',
                'Elsewedy Electric',
                'elsewedy.com',
                false,
            ],
            'automotive parts OEM' => [
                'ZF is a global technology company supplying systems for passenger cars and commercial vehicles.',
                'ZF Group',
                'zf.com',
                false,
            ],
            'space company' => [
                'Thales Alenia Space designs satellites and space systems for telecommunications and Earth observation.',
                'Thales Alenia Space',
                'thalesaleniaspace.com',
                false,
            ],
            'EV startup' => [
                'Archer Aviation is building electric vertical takeoff aircraft for urban air mobility.',
                'Archer Aviation',
                'archer.com',
                false,
            ],
            'automotive lighting OEM' => [
                'Varroc Engineering designs and manufactures automotive exterior lighting and electronics.',
                'Varroc Engineering',
                'varroc.com',
                false,
            ],
            'exhaust systems OEM' => [
                'Eberspächer is one of the world\'s leading suppliers of exhaust technology and vehicle heaters.',
                'Eberspächer Group',
                'eberspaecher.com',
                false,
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  isBlockedDomain() tests
    // ═══════════════════════════════════════════════════════════════════

    /** @dataProvider blockedDomainProvider */
    public function testIsBlockedDomain(string $domain, bool $expectedBlocked): void
    {
        $result = $this->callPrivate('isBlockedDomain', [$domain]);
        $this->assertSame($expectedBlocked, $result, "isBlockedDomain('$domain')");
    }

    public static function blockedDomainProvider(): array
    {
        return [
            // Should be blocked
            'news site' => ['reuters.com', true],
            'gov TLD' => ['example.gov', true],
            'edu TLD' => ['mit.edu', true],
            'EMS competitor jabil' => ['jabil.com', true],
            'EMS competitor flex' => ['flex.com', true],
            'harness competitor yazaki' => ['yazaki-europe.com', true],
            'harness competitor leoni' => ['leoni.com', true],
            'directory thomasnet' => ['thomasnet.com', true],
            'e-commerce amazon' => ['amazon.com', true],
            'social media linkedin' => ['linkedin.com', true],
            'market research statista' => ['statista.com', true],
            'new blocklist roscan' => ['roscan.co.uk', true],
            'new blocklist lumileds' => ['lumileds.com', true],
            'new blocklist meyertool' => ['meyertool.com', true],
            'new blocklist ucs-uk' => ['ucs-uk.com', true],
            'new blocklist aarcorp' => ['aarcorp.com', true],
            'subdomain of blocked' => ['www.jabil.com', true],
            'subdomain oracle' => ['cloud.oracle.com', true],

            // Should NOT be blocked (real prospects)
            'safran' => ['safran-group.com', false],
            'stellantis' => ['stellantis.com', false],
            'borgwarner' => ['borgwarner.com', false],
            'zf' => ['zf.com', false],
            'magna' => ['magna.com', true],
            'delfingen now blocked' => ['delfingen.com', true],
            'elsewedy' => ['elsewedy.com', false],
            'ficosa' => ['ficosa.com', false],
            'archer aviation' => ['archer.com', false],
            'astronics' => ['astronics.com', false],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Structural heuristics in isJunkCompanyName()
    // ═══════════════════════════════════════════════════════════════════

    public function testStructuralHeuristicsRejectLongPhrases(): void
    {
        // 8+ words always rejected
        $this->assertTrue(
            $this->callPrivate('isJunkCompanyName', ['the best automotive wire harness manufacturer in all of europe today']),
            'Should reject 8+ word phrases'
        );
    }

    public function testStructuralHeuristicsAcceptShortProperNames(): void
    {
        // Short capitalised names always accepted
        $shortNames = ['Safran', 'ZF', 'ABB', 'CPI', 'Ficosa', 'Magna Steyr'];
        foreach ($shortNames as $name) {
            $this->assertFalse(
                $this->callPrivate('isJunkCompanyName', [$name]),
                "Should accept short proper name: $name"
            );
        }
    }

    public function testStructuralHeuristicsMajorityLowercaseRejected(): void
    {
        // 5+ words with majority lowercase → phrase
        $this->assertTrue(
            $this->callPrivate('isJunkCompanyName', ['custom wire harness assembly for automotive']),
            'Should reject 5+ word phrase with majority lowercase'
        );
    }

    // ─── New junk pattern tests from Round 3 ───────────────────────

    /**
     * @dataProvider round3JunkProvider
     */
    public function testRound3JunkPatternsRejected(string $name): void
    {
        $this->assertTrue(
            $this->callPrivate('isJunkCompanyName', [$name]),
            "Should reject junk name: $name"
        );
    }

    public function round3JunkProvider(): array
    {
        return [
            'part number TBW1122' => ['TBW1122'],
            'military spec' => ['MIL-DTL-38999 Series III Connectors'],
            'NF product' => ['NF-2405 AIR'],
            'tagline with periods' => ['Forward. For all.'],
            'chapter number' => ['chapter 7'],
            'press release' => ['Press Release'],
            'new oem' => ['NEW OEM'],
            'wire cable harness' => ['Wire Cable Harness'],
            'autosar software' => ['AUTOSAR Embedded Software Implementation'],
            'product listing with comma' => ['Tape, OEM Wire Harness'],
            'suppliers near me' => ['Electrical Suppliers Near Me'],
            'vfd control' => ['VFD Control Panel'],
            'pure number' => ['2035'],
            'single word author' => ['Author'],
            'single word certifications' => ['Certifications'],
            'single word avionics' => ['Avionics'],
            'single word amazon' => ['Amazon'],
            'air store' => ['AIR Store'],
            'wiring article' => ['Wiring for the Final Frontier'],
            'cable harness boot camp' => ['Cable Harness Wiring Boot Camp'],
            'all lowercase two word' => ['chapter seven'],
            'CJK characters' => ['機會、課題、策略與預測'],
            'refrigerator product' => ['KitchenAid Refrigerator Wire Harness Replacement'],
            'news headline' => ['Fire outbreak at Tangier plant'],
            'generic performance' => ['Performance'],
            'generic english' => ['English'],
            'generic analog' => ['Analog'],
            'featured services' => ['Featured services'],
            'cover sheet' => ['COVER SHEET'],
            'country name' => ['United States'],
            'long single word forum' => ['Diysolarforum'],
            'avionics manufacturing systems' => ['Avionics Manufacturing Systems'],
            // Hosting error / placeholder pages
            'account suspended' => ['Account Suspended'],
            'coming soon' => ['Coming Soon'],
            'under construction' => ['Under Construction'],
            'parked domain' => ['Parked Domain'],
            'domain for sale' => ['Domain For Sale'],
            // Country names as single words
            'country togo' => ['Togo'],
            'country morocco' => ['Morocco'],
            'country tunisia' => ['Tunisia'],
            'country oman' => ['Oman'],
        ];
    }

    /**
     * @dataProvider round3KeepProvider
     */
    public function testRound3RealCompaniesAccepted(string $name): void
    {
        $this->assertFalse(
            $this->callPrivate('isJunkCompanyName', [$name]),
            "Should accept real company: $name"
        );
    }

    public function round3KeepProvider(): array
    {
        return [
            'Eaton' => ['Eaton'],
            'STMicroelectronics' => ['STMicroelectronics'],
            'Safran' => ['Safran'],
            'Meggitt' => ['Meggitt'],
            'Carlex Glass' => ['Carlex Glass'],
            'GKN Aerospace' => ['GKN Aerospace'],
            'Heico' => ['Heico'],
            'Barnes Aerospace' => ['Barnes Aerospace'],
            'Marshall Group' => ['Marshall Group'],
            'Henkel Adhesives' => ['Henkel Adhesives'],
            'Air Products' => ['Air Products'],
            'TI Fluid Systems' => ['TI Fluid Systems'],
            'Antolin' => ['Antolin'],
            'SFC Solutions' => ['SFC Solutions'],
            'TRIGO Group' => ['TRIGO Group'],
            'Joubert Group' => ['Joubert Group'],
            'Dedienne Aerospace' => ['Dedienne Aerospace'],
            'Belcan' => ['Belcan'],
        ];
    }

    // ─── Subdomain prefix blocking tests ──────────────────────────

    public function testSubdomainPrefixBlocking(): void
    {
        $blockedSubdomains = [
            'blog.example.com',
            'press.siemens.com',
            'jobs.boeing.com',
            'shop.gerenewableenergy.com',
            'investors.modine.com',
            'resources.altium.com',
            'static.garmin.com',
            'info.maricopacorporate.com',
            'download.sew-eurodrive.com',
        ];

        foreach ($blockedSubdomains as $domain) {
            $this->assertTrue(
                $this->callPrivate('isBlockedDomain', [$domain]),
                "Should block subdomain: $domain"
            );
        }
    }

    // ─── LinkedIn/Homepage verification unit tests ────────────────

    public function testVerifyCompaniesReturnsUnchangedWhenNoSearchService(): void
    {
        // Default test service has null googleSearchService
        // so verifyCompanies should return candidates unchanged
        $candidates = [
            'example.com' => [
                'name' => 'Example Corp',
                'website' => 'https://example.com',
            ],
        ];

        $result = $this->callPrivate('verifyCompanies', [$candidates]);
        $this->assertSame($candidates, $result);
    }

    public function testVerifyCompaniesReturnsEmptyForEmptyCandidates(): void
    {
        $result = $this->callPrivate('verifyCompanies', [[]]);
        $this->assertSame([], $result);
    }

    // ─── Multi-TLD shopping site blocking ─────────────────────────

    public function testUbuyMultiTldBlocking(): void
    {
        $blocked = ['ubuy.tg', 'ubuy.sa', 'ubuy.eg', 'ubuy.ng', 'ubuy.com'];
        foreach ($blocked as $domain) {
            $this->assertTrue(
                $this->callPrivate('isBlockedDomain', [$domain]),
                "Should block multi-TLD shopping site: $domain"
            );
        }
    }

    // ─── Enrichment pipeline unit tests ───────────────────────────

    public function testExtractContactInfoFromSchemaOrg(): void
    {
        $html = '<html><head><script type="application/ld+json">{
            "@type": "Organization",
            "telephone": "+1-555-123-4567",
            "email": "sales@example.com",
            "address": {
                "@type": "PostalAddress",
                "streetAddress": "123 Main St",
                "addressLocality": "Detroit",
                "addressRegion": "MI",
                "postalCode": "48201",
                "addressCountry": "US"
            }
        }</script></head><body></body></html>';

        $result = $this->callPrivate('extractContactInfoFromHtml', [$html]);

        $this->assertNotNull($result['phone'], 'Should extract phone from schema.org');
        $this->assertStringContainsString('555', $result['phone']);
        $this->assertEquals('sales@example.com', $result['email']);
        $this->assertStringContainsString('Detroit', $result['address']);
        $this->assertStringContainsString('MI', $result['address']);
    }

    public function testExtractContactInfoFromTelLinks(): void
    {
        $html = '<html><body>
            <a href="tel:+442071234567">Call us</a>
            <a href="mailto:info@company.com">Email</a>
        </body></html>';

        $result = $this->callPrivate('extractContactInfoFromHtml', [$html]);

        $this->assertNotNull($result['phone'], 'Should extract phone from tel: link');
        $this->assertStringContainsString('4420712', $result['phone']);
        $this->assertEquals('info@company.com', $result['email']);
    }

    public function testExtractContactInfoSkipsFaxNumbers(): void
    {
        $html = '<html><body>
            <a href="tel:+15551111111">Fax</a>
            <a href="tel:+15552222222">Sales Line</a>
        </body></html>';

        $result = $this->callPrivate('extractContactInfoFromHtml', [$html]);

        // Should skip fax and take the sales line
        $this->assertNotNull($result['phone']);
        $this->assertStringContainsString('5552222222', $result['phone']);
    }

    public function testExtractContactInfoSkipsNoReplyEmails(): void
    {
        $html = '<html><body>
            <a href="mailto:noreply@company.com">No Reply</a>
            <a href="mailto:contact@company.com">Contact</a>
        </body></html>';

        $result = $this->callPrivate('extractContactInfoFromHtml', [$html]);

        $this->assertEquals('contact@company.com', $result['email']);
    }

    public function testExtractContactInfoFromGraphWrapper(): void
    {
        $html = '<html><head><script type="application/ld+json">{
            "@graph": [
                {"@type": "WebSite", "name": "Test"},
                {
                    "@type": "Corporation",
                    "telephone": "+33-1-23-45-67-89",
                    "email": "mailto:info@corp.fr"
                }
            ]
        }</script></head></html>';

        $result = $this->callPrivate('extractContactInfoFromHtml', [$html]);

        $this->assertNotNull($result['phone']);
        $this->assertEquals('info@corp.fr', $result['email']);
    }

    public function testCleanPhoneNumber(): void
    {
        $this->assertEquals('+15551234567', $this->callPrivate('cleanPhoneNumber', ['+1 (555) 123-4567']));
        $this->assertEquals('+442071234567', $this->callPrivate('cleanPhoneNumber', ['+44 207 123 4567']));
        $this->assertNull($this->callPrivate('cleanPhoneNumber', ['123'])); // Too short
    }

    public function testExtractLinkedInDescription(): void
    {
        $snippet = 'Acme Corp | 5,432 followers on LinkedIn. Leading provider of industrial automation solutions for the automotive sector.';
        $result = $this->callPrivate('extractLinkedInDescription', [$snippet]);
        $this->assertNotNull($result);
        $this->assertStringContainsString('industrial automation', $result);
        $this->assertStringNotContainsString('followers', $result);
    }

    public function testExtractLinkedInDescriptionEmpty(): void
    {
        $this->assertNull($this->callPrivate('extractLinkedInDescription', ['']));
        $this->assertNull($this->callPrivate('extractLinkedInDescription', ['short']));
    }

    public function testMergeEnrichmentSetsNewKeysOnly(): void
    {
        $data = ['name' => 'Original', 'website' => 'https://example.com'];
        $enrichment = [
            'name' => 'Better Name',
            'phone' => '+15551234567',
            'description' => 'A great company',
            'linkedin_url' => 'https://linkedin.com/company/example',
        ];

        $result = $this->callPrivate('mergeEnrichment', [$data, $enrichment]);

        $this->assertEquals('Better Name', $result['name']);
        $this->assertEquals('+15551234567', $result['phone']);
        $this->assertEquals('A great company', $result['description']);
        $this->assertEquals('https://linkedin.com/company/example', $result['linkedin_url']);
        // Original website preserved
        $this->assertEquals('https://example.com', $result['website']);
    }

    public function testMergeEnrichmentDoesNotOverwriteExisting(): void
    {
        $data = ['name' => 'Original', 'phone' => '+11111111111'];
        $enrichment = ['phone' => '+22222222222'];

        $result = $this->callPrivate('mergeEnrichment', [$data, $enrichment]);

        // Should keep original phone
        $this->assertEquals('+11111111111', $result['phone']);
    }

    public function testMergeEnrichmentRejectsJunkNames(): void
    {
        $data = ['name' => 'Good Company'];
        $enrichment = ['name' => 'Account Suspended'];

        $result = $this->callPrivate('mergeEnrichment', [$data, $enrichment]);

        // Should keep original name since enriched one is junk
        $this->assertEquals('Good Company', $result['name']);
    }

    // ─── TLD Suffix Stripping ─────────────────────────────────────

    public function testMergeEnrichmentStripsDotComFromName(): void
    {
        $data = ['name' => 'Original'];
        $enrichment = ['name' => 'Phinia.com'];

        $result = $this->callPrivate('mergeEnrichment', [$data, $enrichment]);

        $this->assertEquals('Phinia', $result['name']);
    }

    public function testMergeEnrichmentStripsDotNetFromName(): void
    {
        $data = ['name' => 'Original'];
        $enrichment = ['name' => 'Stellantis.net'];

        $result = $this->callPrivate('mergeEnrichment', [$data, $enrichment]);

        $this->assertEquals('Stellantis', $result['name']);
    }

    public function testMergeEnrichmentPreservesLegitDotInName(): void
    {
        $data = ['name' => 'Original'];
        $enrichment = ['name' => 'ABB Ltd.'];

        // "ABB Ltd." should get the legal suffix stripped but remain "ABB"
        // Actually, "ABB" is 3 chars and valid
        $result = $this->callPrivate('mergeEnrichment', [$data, $enrichment]);

        $this->assertNotEquals('Original', $result['name']); // name changed
    }

    // ─── Redirect/Placeholder Junk Detection ─────────────────────

    public function testRedirectingWithEllipsisIsJunk(): void
    {
        $this->assertTrue($this->callPrivate('isJunkCompanyName', ['Redirecting…']));
    }

    public function testRedirectingWithDotsIsJunk(): void
    {
        $this->assertTrue($this->callPrivate('isJunkCompanyName', ['Redirecting...']));
    }

    public function testGlobalEntryPageIsJunk(): void
    {
        $this->assertTrue($this->callPrivate('isJunkCompanyName', ['Global Entry Page']));
    }

    public function testLoadingIsJunk(): void
    {
        $this->assertTrue($this->callPrivate('isJunkCompanyName', ['Loading…']));
    }

    public function testPleaseWaitIsJunk(): void
    {
        $this->assertTrue($this->callPrivate('isJunkCompanyName', ['Please Wait']));
    }

    public function testJustAMomentIsJunk(): void
    {
        $this->assertTrue($this->callPrivate('isJunkCompanyName', ['Just a moment']));
    }

    public function testCheckingYourBrowserIsJunk(): void
    {
        $this->assertTrue($this->callPrivate('isJunkCompanyName', ['Checking your browser']));
    }

    public function testAttentionRequiredIsJunk(): void
    {
        $this->assertTrue($this->callPrivate('isJunkCompanyName', ['Attention Required']));
    }

    public function testYouAreBeingRedirectedIsJunk(): void
    {
        $this->assertTrue($this->callPrivate('isJunkCompanyName', ['You are being redirected']));
    }

    // ─── Person Name Splitting ────────────────────────────────────

    public function testSplitPersonNameBasic(): void
    {
        $result = $this->callPrivate('splitPersonName', ['John Smith']);
        $this->assertEquals('John', $result['first_name']);
        $this->assertEquals('Smith', $result['last_name']);
    }

    public function testSplitPersonNameWithPrefix(): void
    {
        $result = $this->callPrivate('splitPersonName', ['Dr. Jane Doe']);
        $this->assertEquals('Jane', $result['first_name']);
        $this->assertEquals('Doe', $result['last_name']);
    }

    public function testSplitPersonNameThreeParts(): void
    {
        $result = $this->callPrivate('splitPersonName', ['José María García']);
        $this->assertEquals('José', $result['first_name']);
        $this->assertEquals('María García', $result['last_name']);
    }

    public function testSplitPersonNameRejectsGenericLabel(): void
    {
        $result = $this->callPrivate('splitPersonName', ['Customer Service Department']);
        $this->assertNull($result);
    }

    public function testSplitPersonNameRejectsSingleWord(): void
    {
        $result = $this->callPrivate('splitPersonName', ['Admin']);
        $this->assertNull($result);
    }

    public function testSplitPersonNameRejectsLowercase(): void
    {
        $result = $this->callPrivate('splitPersonName', ['john smith']);
        $this->assertNull($result);
    }

    // ─── Schema.org Person Extraction ─────────────────────────────

    public function testExtractPersonFromSchemaOrg(): void
    {
        $entity = [
            '@type' => 'Person',
            'name' => 'Alice Johnson',
            'jobTitle' => 'VP Engineering',
            'email' => 'mailto:alice@example.com',
            'telephone' => '+1-555-123-4567',
        ];

        $result = $this->callPrivate('extractPersonFromSchemaOrg', [$entity]);

        $this->assertNotNull($result);
        $this->assertEquals('Alice', $result['first_name']);
        $this->assertEquals('Johnson', $result['last_name']);
        $this->assertEquals('VP Engineering', $result['job_title']);
        $this->assertEquals('alice@example.com', $result['email']);
        $this->assertEquals('+15551234567', $result['phone']);
    }

    public function testExtractPersonFromSchemaOrgWithGivenFamilyName(): void
    {
        $entity = [
            '@type' => 'Person',
            'givenName' => 'Bob',
            'familyName' => 'Williams',
            'sameAs' => ['https://linkedin.com/in/bob-williams'],
        ];

        $result = $this->callPrivate('extractPersonFromSchemaOrg', [$entity]);

        $this->assertNotNull($result);
        $this->assertEquals('Bob', $result['first_name']);
        $this->assertEquals('Williams', $result['last_name']);
        $this->assertEquals('https://linkedin.com/in/bob-williams', $result['linkedin_url']);
    }

    // ─── Contact Info Extraction with Contacts ────────────────────

    public function testExtractContactInfoIncludesPersonFromSchemaOrg(): void
    {
        $html = '<html><head></head><body>
            <script type="application/ld+json">
            {
                "@type": "Organization",
                "name": "Acme Corp",
                "telephone": "+1-555-000-1234",
                "employee": {
                    "@type": "Person",
                    "name": "Sarah Connor",
                    "jobTitle": "Procurement Manager"
                }
            }
            </script>
        </body></html>';

        $result = $this->callPrivate('extractContactInfoFromHtml', [$html]);

        $this->assertEquals('+15550001234', $result['phone']);
        $this->assertNotEmpty($result['contacts']);
        $this->assertEquals('Sarah', $result['contacts'][0]['first_name']);
        $this->assertEquals('Connor', $result['contacts'][0]['last_name']);
        $this->assertEquals('Procurement Manager', $result['contacts'][0]['job_title']);
    }

    public function testExtractContactInfoFromContactPoint(): void
    {
        $html = '<html><head></head><body>
            <script type="application/ld+json">
            {
                "@type": "Organization",
                "name": "Acme Corp",
                "contactPoint": {
                    "@type": "ContactPoint",
                    "name": "Michael Brown",
                    "contactType": "Sales Manager",
                    "telephone": "+44-20-7946-0958"
                }
            }
            </script>
        </body></html>';

        $result = $this->callPrivate('extractContactInfoFromHtml', [$html]);

        $this->assertNotEmpty($result['contacts']);
        $this->assertEquals('Michael', $result['contacts'][0]['first_name']);
        $this->assertEquals('Brown', $result['contacts'][0]['last_name']);
        $this->assertEquals('Sales Manager', $result['contacts'][0]['job_title']);
    }

    // ─── Email Priority (sales > info > generic) ─────────────────

    public function testEmailExtractionPrefersSales(): void
    {
        $html = '<html><body>
            <a href="mailto:webmaster@example.com">Webmaster</a>
            <a href="mailto:sales@example.com">Sales</a>
            <a href="mailto:hr@example.com">HR</a>
        </body></html>';

        $result = $this->callPrivate('extractContactInfoFromHtml', [$html]);

        $this->assertEquals('sales@example.com', $result['email']);
    }

    public function testEmailExtractionPrefersInfoOverGeneric(): void
    {
        $html = '<html><body>
            <a href="mailto:marketing@example.com">Marketing</a>
            <a href="mailto:info@example.com">Info</a>
        </body></html>';

        $result = $this->callPrivate('extractContactInfoFromHtml', [$html]);

        $this->assertEquals('info@example.com', $result['email']);
    }

    // ─── LinkedIn Description Cleanup ─────────────────────────────

    public function testLinkedInDescriptionStripsRawUrls(): void
    {
        $snippet = 'CompanyX | 500 followers on LinkedIn. http://www.company.com/. External link for CompanyX';

        $result = $this->callPrivate('extractLinkedInDescription', [$snippet]);

        // Should be null since after stripping URL and "External link...", not enough content
        $this->assertNull($result);
    }

    public function testLinkedInDescriptionFixesEncoding(): void
    {
        $snippet = 'CompanyX | 500 followers on LinkedIn. We�re a leading manufacturer of electronic components for the automotive industry';

        $result = $this->callPrivate('extractLinkedInDescription', [$snippet]);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('�', $result);
    }

    // ─── MergeEnrichment Contacts Deduplication ───────────────────

    public function testMergeEnrichmentDeduplicatesContacts(): void
    {
        $data = [
            'name' => 'Test Corp',
            'contacts' => [
                ['first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@test.com'],
            ],
        ];
        $enrichment = [
            'contacts' => [
                ['first_name' => 'John', 'last_name' => 'Smith', 'phone' => '+15551234567'],
                ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@test.com'],
            ],
        ];

        $result = $this->callPrivate('mergeEnrichment', [$data, $enrichment]);

        // John Smith should appear only once, Jane Doe added
        $this->assertCount(2, $result['contacts']);
        $names = array_map(fn($c) => $c['first_name'], $result['contacts']);
        $this->assertContains('John', $names);
        $this->assertContains('Jane', $names);
    }

    public function testMergeEnrichmentLimitsContactsToFive(): void
    {
        $data = ['name' => 'Test Corp', 'contacts' => []];
        $contacts = [];
        for ($i = 0; $i < 8; $i++) {
            $contacts[] = ['first_name' => "Person$i", 'last_name' => "Last$i"];
        }
        $enrichment = ['contacts' => $contacts];

        $result = $this->callPrivate('mergeEnrichment', [$data, $enrichment]);

        $this->assertCount(5, $result['contacts']);
    }

    // ─── ProcessHomepageHtml (refactored method) ──────────────────

    public function testProcessHomepageHtmlVerifiesViaOgSiteName(): void
    {
        $html = '<html><head>
            <meta property="og:site_name" content="Acme Corp" />
            <meta property="og:description" content="We are a global leader in electronic manufacturing services for aerospace and defense" />
        </head><body></body></html>';

        $result = $this->callPrivate('processHomepageHtml', [$html, 'Acme']);

        $this->assertNotNull($result);
        $this->assertEquals('Acme Corp', $result['name']);
        $this->assertStringContainsString('electronic manufacturing', $result['description']);
    }

    public function testProcessHomepageHtmlStripsTldFromSiteName(): void
    {
        $html = '<html><head>
            <meta property="og:site_name" content="Phinia.com" />
        </head><body></body></html>';

        $result = $this->callPrivate('processHomepageHtml', [$html, 'Phinia']);

        $this->assertNotNull($result);
        $this->assertEquals('Phinia', $result['name']);
    }

    public function testProcessHomepageHtmlRejectsRedirectTitle(): void
    {
        $html = '<html><head>
            <title>Redirecting…</title>
        </head><body></body></html>';

        $result = $this->callPrivate('processHomepageHtml', [$html, 'SomeCompany']);

        $this->assertNull($result);
    }

    public function testProcessHomepageHtmlExtractsLinkedInUrl(): void
    {
        $html = '<html><head>
            <meta property="og:site_name" content="TestCorp" />
        </head><body>
            <a href="https://www.linkedin.com/company/testcorp">LinkedIn</a>
        </body></html>';

        $result = $this->callPrivate('processHomepageHtml', [$html, 'TestCorp']);

        $this->assertNotNull($result);
        $this->assertEquals('https://www.linkedin.com/company/testcorp', $result['linkedin_url']);
    }

    public function testProcessHomepageHtmlExtractsContacts(): void
    {
        $html = '<html><head>
            <meta property="og:site_name" content="TestCorp" />
            <script type="application/ld+json">
            {"@type":"Organization","name":"TestCorp",
             "employee":{"@type":"Person","name":"Emma Watson","jobTitle":"Sales Director"}}
            </script>
        </head><body></body></html>';

        $result = $this->callPrivate('processHomepageHtml', [$html, 'TestCorp']);

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['contacts']);
        $this->assertEquals('Emma', $result['contacts'][0]['first_name']);
        $this->assertEquals('Watson', $result['contacts'][0]['last_name']);
    }
}
