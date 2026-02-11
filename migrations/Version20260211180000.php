<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the estimates table — QuoteEstimator module removed (Option A rationalization).
 * 
 * The Estimate entity and its controller/templates/services have been fully removed.
 * All quoting functionality is consolidated in the QuoteCoPilot → QuoteReview → LiveQuote pipeline.
 */
final class Version20260211180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop estimates table — QuoteEstimator module removed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS estimates');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE estimates (
            id INT AUTO_INCREMENT NOT NULL,
            company_id INT NOT NULL,
            rfq_id INT DEFAULT NULL,
            estimate_number VARCHAR(50) NOT NULL,
            origin_country VARCHAR(100) NOT NULL,
            destination_country VARCHAR(100) NOT NULL,
            origin_port VARCHAR(100) DEFAULT NULL,
            destination_port VARCHAR(100) DEFAULT NULL,
            material_cost NUMERIC(15, 2) NOT NULL,
            labor_cost NUMERIC(15, 2) NOT NULL,
            freight_cost NUMERIC(15, 2) NOT NULL,
            duty_cost NUMERIC(15, 2) NOT NULL,
            other_costs NUMERIC(15, 2) DEFAULT NULL,
            total_landed_cost NUMERIC(15, 2) NOT NULL,
            currency VARCHAR(10) NOT NULL,
            duty_rate NUMERIC(5, 2) DEFAULT NULL,
            fta_agreement VARCHAR(100) DEFAULT NULL,
            fta_qualified TINYINT(1) NOT NULL DEFAULT 0,
            bom_data LONGTEXT DEFAULT NULL,
            sha256_hash VARCHAR(64) DEFAULT NULL,
            version_id VARCHAR(100) DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_A0E35E301E4A0F44 (estimate_number),
            INDEX idx_company (company_id),
            INDEX idx_estimate_number (estimate_number),
            PRIMARY KEY(id),
            CONSTRAINT FK_estimates_company FOREIGN KEY (company_id) REFERENCES companies (id),
            CONSTRAINT FK_estimates_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }
}
