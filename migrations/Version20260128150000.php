<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add lifecycle tracking, source URL tracking, and alternative parts fields to BomLine.
 * Part of Quote CoPilot improvements for multi-distributor waterfall and transparency.
 */
final class Version20260128150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lifecycle_status, lifecycle_warning, price_source_url, distributor_search_url, and alternative_parts fields to bom_line table';
    }

    public function up(Schema $schema): void
    {
        // Add lifecycle tracking columns
        $this->addSql('ALTER TABLE bom_line ADD lifecycle_status VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD lifecycle_warning VARCHAR(20) DEFAULT NULL');
        
        // Add source URL tracking for transparency
        $this->addSql('ALTER TABLE bom_line ADD price_source_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD distributor_search_url VARCHAR(500) DEFAULT NULL');
        
        // Add alternative parts JSON storage
        $this->addSql('ALTER TABLE bom_line ADD alternative_parts JSON DEFAULT NULL');
        
        // Add index for lifecycle filtering
        $this->addSql('CREATE INDEX IDX_bom_line_lifecycle ON bom_line (lifecycle_warning)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_bom_line_lifecycle ON bom_line');
        $this->addSql('ALTER TABLE bom_line DROP lifecycle_status');
        $this->addSql('ALTER TABLE bom_line DROP lifecycle_warning');
        $this->addSql('ALTER TABLE bom_line DROP price_source_url');
        $this->addSql('ALTER TABLE bom_line DROP distributor_search_url');
        $this->addSql('ALTER TABLE bom_line DROP alternative_parts');
    }
}
