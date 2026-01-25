<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add confidence scoring and manual override fields to bom_line table
 */
final class Version20260125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add confidence scoring and manual override fields to bom_line for Quote CoPilot improvements';
    }

    public function up(Schema $schema): void
    {
        // Add confidence scoring fields
        $this->addSql('ALTER TABLE bom_line ADD original_mpn VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD matched_mpn VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD bom_description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD confidence_score SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD confidence_level VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD confidence_reasons JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD confidence_warnings JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD requires_review TINYINT(1) DEFAULT 0');
        
        // Add manual override fields
        $this->addSql('ALTER TABLE bom_line ADD manually_verified TINYINT(1) DEFAULT 0');
        $this->addSql('ALTER TABLE bom_line ADD manual_unit_price DECIMAL(10, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD manual_notes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD verified_by VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE bom_line ADD verified_at DATETIME DEFAULT NULL');
        
        // Add index for review status queries
        $this->addSql('CREATE INDEX idx_bom_review ON bom_line (requires_review)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_bom_review ON bom_line');
        $this->addSql('ALTER TABLE bom_line DROP original_mpn');
        $this->addSql('ALTER TABLE bom_line DROP matched_mpn');
        $this->addSql('ALTER TABLE bom_line DROP bom_description');
        $this->addSql('ALTER TABLE bom_line DROP confidence_score');
        $this->addSql('ALTER TABLE bom_line DROP confidence_level');
        $this->addSql('ALTER TABLE bom_line DROP confidence_reasons');
        $this->addSql('ALTER TABLE bom_line DROP confidence_warnings');
        $this->addSql('ALTER TABLE bom_line DROP requires_review');
        $this->addSql('ALTER TABLE bom_line DROP manually_verified');
        $this->addSql('ALTER TABLE bom_line DROP manual_unit_price');
        $this->addSql('ALTER TABLE bom_line DROP manual_notes');
        $this->addSql('ALTER TABLE bom_line DROP verified_by');
        $this->addSql('ALTER TABLE bom_line DROP verified_at');
    }
}
