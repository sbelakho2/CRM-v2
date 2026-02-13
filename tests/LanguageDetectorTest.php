<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\WebCrawler\Text\LanguageDetector;
use PHPUnit\Framework\TestCase;

class LanguageDetectorTest extends TestCase
{
    private LanguageDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new LanguageDetector();
    }

    // ──────────────────────────────────────────────────────────
    // English detection
    // ──────────────────────────────────────────────────────────

    public function testEnglishDetected(): void
    {
        $r = $this->detector->detect(
            'The company designs and manufactures advanced electronic systems for the automotive industry. With over 200 employees, they are a global leader.'
        );
        $this->assertSame('en', $r['language']);
        $this->assertGreaterThan(0.1, $r['confidence']);
    }

    // ──────────────────────────────────────────────────────────
    // French detection
    // ──────────────────────────────────────────────────────────

    public function testFrenchDetected(): void
    {
        $r = $this->detector->detect(
            'L\'entreprise conçoit et fabrique des systèmes électroniques avancés pour l\'industrie automobile. Avec plus de 200 employés, elle est un leader mondial dans le domaine des capteurs et contrôleurs.'
        );
        $this->assertSame('fr', $r['language']);
    }

    // ──────────────────────────────────────────────────────────
    // German detection
    // ──────────────────────────────────────────────────────────

    public function testGermanDetected(): void
    {
        $r = $this->detector->detect(
            'Das Unternehmen entwickelt und fertigt fortschrittliche elektronische Systeme für die Automobilindustrie. Mit über 200 Mitarbeitern ist es ein weltweit führender Anbieter von Sensoren und Steuerungssystemen.'
        );
        $this->assertSame('de', $r['language']);
    }

    // ──────────────────────────────────────────────────────────
    // Italian detection
    // ──────────────────────────────────────────────────────────

    public function testItalianDetected(): void
    {
        $r = $this->detector->detect(
            'L\'azienda progetta e produce sistemi elettronici avanzati per l\'industria automobilistica. Con oltre 200 dipendenti, è un leader globale nel settore dei sensori e dei controllori.'
        );
        $this->assertSame('it', $r['language']);
    }

    // ──────────────────────────────────────────────────────────
    // Spanish detection
    // ──────────────────────────────────────────────────────────

    public function testSpanishDetected(): void
    {
        $r = $this->detector->detect(
            'La empresa diseña y fabrica sistemas electrónicos avanzados para la industria automotriz. Con más de 200 empleados, es un líder mundial en sensores y controladores.'
        );
        $this->assertSame('es', $r['language']);
    }

    // ──────────────────────────────────────────────────────────
    // Arabic detection (script-based)
    // ──────────────────────────────────────────────────────────

    public function testArabicDetected(): void
    {
        $r = $this->detector->detect(
            'تقوم الشركة بتصميم وتصنيع الأنظمة الإلكترونية المتقدمة لصناعة السيارات. مع أكثر من 200 موظف، فهي رائدة عالمية في مجال أجهزة الاستشعار والتحكم.'
        );
        $this->assertSame('ar', $r['language']);
        $this->assertSame('script', $r['method']);
    }

    // ──────────────────────────────────────────────────────────
    // Short text → indeterminate
    // ──────────────────────────────────────────────────────────

    public function testShortTextUndetermined(): void
    {
        $r = $this->detector->detect('Hello world');
        $this->assertSame('und', $r['language']);
        $this->assertSame('too_short', $r['method']);
    }

    // ──────────────────────────────────────────────────────────
    // Region relevance
    // ──────────────────────────────────────────────────────────

    public function testGermanTextRelevantForDE(): void
    {
        $r = $this->detector->detectWithRegionRelevance(
            'Das Unternehmen entwickelt und fertigt elektronische Systeme für die Automobilindustrie mit Sitz in München.',
            'DE',
        );
        $this->assertTrue($r['region_relevant']);
        $this->assertSame(1.0, $r['relevance_score']);
    }

    public function testEnglishTextRelevantForDE(): void
    {
        $r = $this->detector->detectWithRegionRelevance(
            'The company designs and manufactures advanced electronic systems for the automotive industry based in Munich Germany.',
            'DE',
        );
        $this->assertTrue($r['region_relevant']);
        $this->assertGreaterThanOrEqual(0.8, $r['relevance_score']);
    }

    public function testFrenchTextRelevantForFR(): void
    {
        $r = $this->detector->detectWithRegionRelevance(
            'L\'entreprise conçoit et fabrique des systèmes électroniques avancés pour l\'industrie automobile basée à Paris.',
            'FR',
        );
        $this->assertTrue($r['region_relevant']);
    }

    public function testFrenchTextRelevantForMA(): void
    {
        // French is expected in Morocco
        $r = $this->detector->detectWithRegionRelevance(
            'L\'entreprise marocaine fabrique des composants électroniques dans la zone franche de Tanger.',
            'MA',
        );
        $this->assertTrue($r['region_relevant']);
    }

    public function testArabicRelevantForEG(): void
    {
        $r = $this->detector->detectWithRegionRelevance(
            'تقوم الشركة المصرية بتصنيع المكونات الإلكترونية في المنطقة الصناعية بالعاشر من رمضان.',
            'EG',
        );
        $this->assertTrue($r['region_relevant']);
    }

    // ──────────────────────────────────────────────────────────
    // Dutch detection
    // ──────────────────────────────────────────────────────────

    public function testDutchDetected(): void
    {
        $r = $this->detector->detect(
            'Het bedrijf ontwerpt en produceert geavanceerde elektronische systemen voor de auto-industrie. Met meer dan 200 werknemers is het een wereldleider op het gebied van sensoren en besturingssystemen.'
        );
        $this->assertSame('nl', $r['language']);
    }

    // ──────────────────────────────────────────────────────────
    // Result structure
    // ──────────────────────────────────────────────────────────

    public function testResultStructure(): void
    {
        $r = $this->detector->detect(
            'The company manufactures electronic systems for industrial applications worldwide with strong R&D capabilities.'
        );
        $this->assertArrayHasKey('language', $r);
        $this->assertArrayHasKey('confidence', $r);
        $this->assertArrayHasKey('method', $r);
    }

    public function testRegionRelevanceResultStructure(): void
    {
        $r = $this->detector->detectWithRegionRelevance(
            'The company manufactures electronic systems for industrial applications worldwide.',
            'GB',
        );
        $this->assertArrayHasKey('language', $r);
        $this->assertArrayHasKey('confidence', $r);
        $this->assertArrayHasKey('method', $r);
        $this->assertArrayHasKey('region_relevant', $r);
        $this->assertArrayHasKey('relevance_score', $r);
    }
}
