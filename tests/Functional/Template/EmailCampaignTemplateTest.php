<?php

namespace App\Tests\Functional\Template;

use PHPUnit\Framework\TestCase;

/**
 * Email campaign template functional tests
 * 
 * NOTE: These tests require a full database schema to run successfully.
 * 
 * TODO: Complete TestDatabaseSchema.php with all required tables or use WebTestCase with mocks
 */
class EmailCampaignTemplateTest extends TestCase
{
    public function testPlaceholder(): void
    {
        $this->markTestSkipped(
            'Functional template tests require complete database schema. ' .
            'TestDatabaseSchema.php needs to be extended with all application tables.'
        );
    }
}
