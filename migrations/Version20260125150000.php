<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add external_crm_url field to leads table for deep linking to external CRMs
 */
final class Version20260125150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add external_crm_url field to leads table for deep linking to external CRM records';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leads ADD external_crm_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leads DROP external_crm_url');
    }
}
