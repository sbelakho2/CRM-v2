<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251029090852 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE estimates (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, company_id INTEGER NOT NULL, rfq_id INTEGER DEFAULT NULL, estimate_number VARCHAR(50) NOT NULL, origin_country VARCHAR(100) NOT NULL, destination_country VARCHAR(100) NOT NULL, origin_port VARCHAR(100) DEFAULT NULL, destination_port VARCHAR(100) DEFAULT NULL, material_cost NUMERIC(15, 2) NOT NULL, labor_cost NUMERIC(15, 2) NOT NULL, freight_cost NUMERIC(15, 2) NOT NULL, duty_cost NUMERIC(15, 2) NOT NULL, other_costs NUMERIC(15, 2) DEFAULT NULL, total_landed_cost NUMERIC(15, 2) NOT NULL, currency VARCHAR(10) NOT NULL, duty_rate NUMERIC(5, 2) DEFAULT NULL, fta_agreement VARCHAR(100) DEFAULT NULL, fta_qualified BOOLEAN NOT NULL, bom_data CLOB DEFAULT NULL, sha256_hash VARCHAR(64) DEFAULT NULL, version_id VARCHAR(100) DEFAULT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, CONSTRAINT FK_85B8B0EE979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_85B8B0EEABD9545F FOREIGN KEY (rfq_id) REFERENCES rfqs (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_85B8B0EE9D3C8144 ON estimates (estimate_number)');
        $this->addSql('CREATE INDEX IDX_85B8B0EEABD9545F ON estimates (rfq_id)');
        $this->addSql('CREATE INDEX idx_company ON estimates (company_id)');
        $this->addSql('CREATE INDEX idx_estimate_number ON estimates (estimate_number)');
        $this->addSql('CREATE TABLE freight_tables (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, origin_port VARCHAR(100) NOT NULL, destination_port VARCHAR(100) NOT NULL, transport_mode VARCHAR(50) NOT NULL, container_type VARCHAR(20) NOT NULL, cost_per_unit NUMERIC(10, 2) NOT NULL, currency VARCHAR(10) NOT NULL, transit_days INTEGER DEFAULT NULL, effective_date DATE NOT NULL, expiry_date DATE DEFAULT NULL, carrier VARCHAR(255) DEFAULT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_route ON freight_tables (origin_port, destination_port)');
        $this->addSql('CREATE INDEX idx_effective_date ON freight_tables (effective_date)');
        $this->addSql('CREATE TABLE fta_rules (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, hs_code VARCHAR(20) NOT NULL, fta_agreement VARCHAR(100) NOT NULL, roo_requirement CLOB NOT NULL, roo_language CLOB DEFAULT NULL, minimum_value_content NUMERIC(5, 2) DEFAULT NULL, requires_certificate BOOLEAN NOT NULL, certificate_type VARCHAR(50) DEFAULT NULL, required_documents CLOB DEFAULT NULL, notes CLOB DEFAULT NULL, effective_date DATE NOT NULL, expiry_date DATE DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_hs_code ON fta_rules (hs_code)');
        $this->addSql('CREATE TABLE fx_rates (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, from_currency VARCHAR(10) NOT NULL, to_currency VARCHAR(10) NOT NULL, rate NUMERIC(12, 6) NOT NULL, asof DATETIME NOT NULL, version_id VARCHAR(36) NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_currencies ON fx_rates (from_currency, to_currency)');
        $this->addSql('CREATE INDEX idx_asof ON fx_rates (asof)');
        $this->addSql('CREATE TABLE packaging_factors (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, category VARCHAR(100) NOT NULL, kg_per_unit NUMERIC(10, 4) NOT NULL, dm3_per_unit NUMERIC(10, 4) NOT NULL, palletization_rule_json CLOB DEFAULT NULL --(DC2Type:json)
        , asof DATETIME NOT NULL, version_id VARCHAR(36) NOT NULL, is_active BOOLEAN NOT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_category ON packaging_factors (category)');
        $this->addSql('CREATE INDEX idx_asof ON packaging_factors (asof)');
        $this->addSql('CREATE TABLE quote_part_breakdowns (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quote_id INTEGER NOT NULL, mpn VARCHAR(255) NOT NULL, manufacturer VARCHAR(255) DEFAULT NULL, quantity INTEGER NOT NULL, unit_price NUMERIC(15, 4) NOT NULL, extended_price NUMERIC(15, 2) NOT NULL, data_source VARCHAR(100) DEFAULT NULL, lead_time_days INTEGER DEFAULT NULL, is_imputed BOOLEAN NOT NULL, category VARCHAR(100) DEFAULT NULL, CONSTRAINT FK_88D90569DB805178 FOREIGN KEY (quote_id) REFERENCES quotes (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_88D90569DB805178 ON quote_part_breakdowns (quote_id)');
        $this->addSql('CREATE TABLE quotes (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, company_id INTEGER NOT NULL, quote_number VARCHAR(50) NOT NULL, status VARCHAR(50) NOT NULL, total_cost NUMERIC(15, 2) NOT NULL, currency VARCHAR(10) NOT NULL, coverage_percent NUMERIC(5, 2) DEFAULT NULL, alibaba_percent NUMERIC(5, 2) DEFAULT NULL, critical_dfm_count INTEGER DEFAULT NULL, max_imputed_lead_time_days INTEGER DEFAULT NULL, auto_published BOOLEAN NOT NULL, dataset_version_id VARCHAR(36) DEFAULT NULL, api_versions CLOB DEFAULT NULL --(DC2Type:json)
        , bom_data_json CLOB DEFAULT NULL, ship_to_country VARCHAR(100) DEFAULT NULL, incoterms VARCHAR(50) DEFAULT NULL, quantity INTEGER DEFAULT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, CONSTRAINT FK_A1B588C5979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A1B588C5AC28B117 ON quotes (quote_number)');
        $this->addSql('CREATE INDEX idx_company ON quotes (company_id)');
        $this->addSql('CREATE INDEX idx_quote_number ON quotes (quote_number)');
        $this->addSql('CREATE INDEX idx_status ON quotes (status)');
        $this->addSql('CREATE TABLE report_audits (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, report_type VARCHAR(100) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id INTEGER NOT NULL, sha256_hash VARCHAR(64) NOT NULL, version_id VARCHAR(100) DEFAULT NULL, dataset_versions CLOB DEFAULT NULL --(DC2Type:json)
        , api_versions CLOB DEFAULT NULL --(DC2Type:json)
        , metadata CLOB DEFAULT NULL --(DC2Type:json)
        , file_name VARCHAR(255) DEFAULT NULL, file_size INTEGER DEFAULT NULL, generated_by VARCHAR(100) DEFAULT NULL, generated_at DATETIME NOT NULL, notes CLOB DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_report_type ON report_audits (report_type)');
        $this->addSql('CREATE INDEX idx_entity_type_id ON report_audits (entity_type, entity_id)');
        $this->addSql('CREATE TABLE tariff_rates (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, hs_code VARCHAR(20) NOT NULL, origin_country VARCHAR(100) NOT NULL, destination_country VARCHAR(100) NOT NULL, duty_rate NUMERIC(5, 2) NOT NULL, mfn_rate NUMERIC(5, 2) DEFAULT NULL, fta_rate NUMERIC(5, 2) DEFAULT NULL, effective_date DATE NOT NULL, expiry_date DATE DEFAULT NULL, notes CLOB DEFAULT NULL, fta_agreement VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_hs_code ON tariff_rates (hs_code)');
        $this->addSql('CREATE INDEX idx_effective_date ON tariff_rates (effective_date)');
        $this->addSql('ALTER TABLE compliance_documents ADD COLUMN document_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_documents ADD COLUMN sha256_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_documents ADD COLUMN version_id VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE estimates');
        $this->addSql('DROP TABLE freight_tables');
        $this->addSql('DROP TABLE fta_rules');
        $this->addSql('DROP TABLE fx_rates');
        $this->addSql('DROP TABLE packaging_factors');
        $this->addSql('DROP TABLE quote_part_breakdowns');
        $this->addSql('DROP TABLE quotes');
        $this->addSql('DROP TABLE report_audits');
        $this->addSql('DROP TABLE tariff_rates');
        $this->addSql('CREATE TEMPORARY TABLE __temp__compliance_documents AS SELECT id, company_id, name, required, provided, status, file_name, file_size, expiry_date, uploaded_at, updated_at FROM compliance_documents');
        $this->addSql('DROP TABLE compliance_documents');
        $this->addSql('CREATE TABLE compliance_documents (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, company_id INTEGER NOT NULL, name VARCHAR(255) NOT NULL, required BOOLEAN NOT NULL, provided BOOLEAN NOT NULL, status VARCHAR(100) DEFAULT NULL, file_name VARCHAR(255) DEFAULT NULL, file_size INTEGER DEFAULT NULL, expiry_date DATE DEFAULT NULL, uploaded_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, CONSTRAINT FK_EABE6873979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO compliance_documents (id, company_id, name, required, provided, status, file_name, file_size, expiry_date, uploaded_at, updated_at) SELECT id, company_id, name, required, provided, status, file_name, file_size, expiry_date, uploaded_at, updated_at FROM __temp__compliance_documents');
        $this->addSql('DROP TABLE __temp__compliance_documents');
        $this->addSql('CREATE INDEX IDX_EABE6873979B1AD6 ON compliance_documents (company_id)');
    }
}
