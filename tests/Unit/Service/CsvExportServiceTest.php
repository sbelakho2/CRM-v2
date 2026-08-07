<?php

namespace App\Tests\Unit\Service;

use App\Service\CsvExportService;
use PHPUnit\Framework\TestCase;

/**
 * CSV formula-injection guard: cells beginning with =, +, -, @ must be
 * neutralised with a tab prefix (OWASP), safe cells must pass through
 * untouched, and already-prefixed cells must not be double-prefixed.
 */
class CsvExportServiceTest extends TestCase
{
    public static function formulaProvider(): array
    {
        return [
            'equals formula' => ['=HYPERLINK("http://evil.example")', "\t=HYPERLINK(\"http://evil.example\")"],
            'plus formula' => ['+1+1', "\t+1+1"],
            'minus formula' => ['-2+3', "\t-2+3"],
            'at formula' => ['@SUM(A1)', "\t@SUM(A1)"],
            'spreadsheet bomb' => ['=cmd|"/c calc"!A0', "\t=cmd|\"/c calc\"!A0"],
            'plain text' => ['Acme Corp', 'Acme Corp'],
            'negative number' => ['-5', "\t-5"],
            'decimal number' => ['12.50', '12.50'],
            'email' => ['a@b.com', 'a@b.com'],
            'already prefixed' => ["\t=SAFE", "\t=SAFE"],
            'empty string' => ['', ''],
            'leading space safe' => [' =notformula', ' =notformula'],
        ];
    }

    /**
     * @dataProvider formulaProvider
     */
    public function testSanitizeCsvCell(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, CsvExportService::sanitizeCsvCell($input));
    }

    public function testSanitizeCsvCellLeavesNonStringsUntouched(): void
    {
        $this->assertSame(42, CsvExportService::sanitizeCsvCell(42));
        $this->assertSame(3.14, CsvExportService::sanitizeCsvCell(3.14));
        $this->assertNull(CsvExportService::sanitizeCsvCell(null));
        $this->assertTrue(CsvExportService::sanitizeCsvCell(true));
    }
}
