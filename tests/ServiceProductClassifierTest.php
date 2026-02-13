<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\WebCrawler\Classifier\ServiceProductClassifier;
use App\Service\WebCrawler\Classifier\ServiceProductVerdict;
use PHPUnit\Framework\TestCase;

class ServiceProductClassifierTest extends TestCase
{
    private ServiceProductClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ServiceProductClassifier();
    }

    // ──────────────────────────────────────────────────────────
    // SERVICE_PROVIDER detections
    // ──────────────────────────────────────────────────────────

    public function testEmsCompetitorDetectedAsService(): void
    {
        $v = $this->classifier->classify(
            'FlexTech EMS',
            'FlexTech is a leading contract electronics manufacturing services provider. We offer turnkey EMS solutions including PCB assembly, box-build assembly and cable harness manufacturing services.',
            'FlexTech EMS - Contract Manufacturing Services',
            'flextech-ems.com',
        );
        $this->assertTrue($v->isRejected(), 'EMS competitor should be SERVICE_PROVIDER');
        $this->assertSame(ServiceProductVerdict::TYPE_SERVICE, $v->type);
        $this->assertGreaterThan($v->productScore, $v->serviceScore);
    }

    public function testConsultingFirmDetectedAsService(): void
    {
        $v = $this->classifier->classify(
            'Acme Consulting Group',
            'Acme is a leading management consulting firm providing strategy consulting, digital transformation advisory and technology consulting services to clients worldwide.',
            'Acme Consulting Group - Strategy & Advisory',
            'acme-consulting.com',
        );
        $this->assertTrue($v->isRejected(), 'Consulting firm should be SERVICE_PROVIDER');
        $this->assertSame(ServiceProductVerdict::TYPE_SERVICE, $v->type);
    }

    public function testITServicesCompanyDetectedAsService(): void
    {
        $v = $this->classifier->classify(
            'CodeForge Technologies',
            'CodeForge is a custom software development company and IT services provider. We offer offshore software development, web design, app development and managed IT services.',
            'CodeForge - Custom Software Development & IT Services',
            'codeforge-services.com',
        );
        $this->assertTrue($v->isRejected(), 'IT services should be SERVICE_PROVIDER');
    }

    public function testStaffingAgencyDetectedAsService(): void
    {
        $v = $this->classifier->classify(
            'TechTalent Recruitment',
            'TechTalent is a staffing agency specializing in talent acquisition for engineering and manufacturing. Our executive search firm places top workforce solutions.',
            'TechTalent - Engineering Recruitment Agency',
            'techtalent-recruit.com',
        );
        $this->assertTrue($v->isRejected(), 'Staffing agency should be SERVICE_PROVIDER');
    }

    public function testLogisticsProviderDetectedAsService(): void
    {
        $v = $this->classifier->classify(
            'FastFreight Logistics',
            'FastFreight is a global logistics provider and freight forwarding company. Our supply chain management services include warehousing and distribution, customs brokerage, and 3PL solutions.',
            'FastFreight - Global Logistics & Freight',
            'fastfreight-logistics.com',
        );
        $this->assertTrue($v->isRejected(), 'Logistics provider should be SERVICE_PROVIDER');
    }

    // ──────────────────────────────────────────────────────────
    // PRODUCT_COMPANY detections
    // ──────────────────────────────────────────────────────────

    public function testElectronicsOemDetectedAsProduct(): void
    {
        $v = $this->classifier->classify(
            'SensorTech GmbH',
            'SensorTech designs and develops innovative sensor systems and IoT devices. Our product portfolio includes radar modules, temperature controllers, and battery management systems. Our R&D center has 200 engineers.',
            'SensorTech - Industrial Sensor Systems',
            'sensortech.de',
        );
        $this->assertFalse($v->isRejected(), 'Electronics OEM should be PRODUCT_COMPANY');
        $this->assertTrue($v->isProduct());
        $this->assertSame(ServiceProductVerdict::TYPE_PRODUCT, $v->type);
    }

    public function testDefenseElectronicsDetectedAsProduct(): void
    {
        $v = $this->classifier->classify(
            'Thales Defence Electronics',
            'We design and engineer advanced defence electronics systems including radar, avionics, and communication equipment. Our products are deployed in over 50 countries. Headquarters in Paris, founded in 1968.',
            'Thales - Defence & Security Systems',
            'thalesgroup.com',
        );
        $this->assertFalse($v->isRejected(), 'Defence electronics should be PRODUCT_COMPANY');
        $this->assertTrue($v->isProduct());
    }

    public function testMedicalDeviceMakerDetectedAsProduct(): void
    {
        $v = $this->classifier->classify(
            'MedTronic Instruments',
            'MedTronic is a leading manufacturer of medical devices and diagnostic equipment. Our product range includes imaging systems, patient monitoring instruments, and surgical robots. ISO 13485 certified. R&D investment of 15% of revenue.',
            'MedTronic - Medical Device Innovation',
            'medtronic-instruments.com',
        );
        $this->assertFalse($v->isRejected(), 'Medical device maker should be PRODUCT_COMPANY');
        $this->assertTrue($v->isProduct());
    }

    public function testAutomotiveElectronicsDetectedAsProduct(): void
    {
        $v = $this->classifier->classify(
            'DriveLogic Systems',
            'DriveLogic develops ECU, motor drive, and telematics modules for the automotive industry. Our manufacturing plant produces 2M units/year. We outsource PCB assembly to EMS partners. IATF 16949 certified.',
            'DriveLogic - Automotive Electronics Systems',
            'drivelogic-systems.com',
        );
        $this->assertFalse($v->isRejected());
        $this->assertTrue($v->isProduct());
    }

    // ──────────────────────────────────────────────────────────
    // INDETERMINATE / edge cases
    // ──────────────────────────────────────────────────────────

    public function testMinimalSnippetIsIndeterminate(): void
    {
        $v = $this->classifier->classify(
            'Acme Corp',
            'Welcome to Acme Corp website.',
            'Acme Corp',
            'acmecorp.com',
        );
        $this->assertFalse($v->isRejected(), 'Minimal snippet → INDETERMINATE → pass through');
        $this->assertSame(ServiceProductVerdict::TYPE_INDETERMINATE, $v->type);
    }

    public function testMixedSignalsIsIndeterminate(): void
    {
        // A company that both designs products AND offers engineering services
        $v = $this->classifier->classify(
            'HybridTech Solutions',
            'HybridTech designs and develops innovative sensor systems. We also provide engineering services and consulting firm-level advisory to help our clients. Our products include controllers and power supply units.',
            'HybridTech - Products & Services',
            'hybridtech.com',
        );
        // Either indeterminate or product (the product signals should still dominate)
        $this->assertFalse($v->isRejected(), 'Mixed signals should not reject');
    }

    // ──────────────────────────────────────────────────────────
    // Value object
    // ──────────────────────────────────────────────────────────

    public function testVerdictToArray(): void
    {
        $v = $this->classifier->classify(
            'SensorTech GmbH',
            'SensorTech designs sensor systems. Our product portfolio includes radar modules and IoT devices for industrial automation.',
            'SensorTech - Sensor Systems',
            'sensortech.de',
        );
        $arr = $v->toArray();
        $this->assertArrayHasKey('type', $arr);
        $this->assertArrayHasKey('product_score', $arr);
        $this->assertArrayHasKey('service_score', $arr);
        $this->assertArrayHasKey('reason', $arr);
        $this->assertArrayHasKey('signal_breakdown', $arr);
        $this->assertIsArray($arr['signal_breakdown']);
    }

    public function testDomainHintBoostsServiceScore(): void
    {
        // A domain with "consulting" in it should add service points
        $v = $this->classifier->classify(
            'AlphaConsult',
            'AlphaConsult helps businesses grow. We provide management consulting and strategy consulting services.',
            'AlphaConsult',
            'alphaconsulting.com',
        );
        $this->assertTrue($v->isRejected());
        // Check the breakdown includes domain hint
        $this->assertArrayHasKey('domain_service_hint', $v->signalBreakdown);
    }

    public function testDomainHintBoostsProductScore(): void
    {
        // A domain with "electronics" boosts product score
        $v = $this->classifier->classify(
            'Peak Electronics',
            'Peak Electronics designs and develops power electronics and sensor systems. Our product line includes inverters, converters, and motor drives.',
            'Peak Electronics - Power Systems',
            'peak-electronics.com',
        );
        $this->assertTrue($v->isProduct());
        $this->assertArrayHasKey('domain_product_hint', $v->signalBreakdown);
    }
}
