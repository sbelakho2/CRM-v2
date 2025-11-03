<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251028214333 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE companies ADD COLUMN physical_site VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE companies ADD COLUMN linkedin_company_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE companies ADD COLUMN source_notes CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE companies ADD COLUMN legal_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier_portals ADD COLUMN portal_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier_portals ADD COLUMN portal_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier_portals ADD COLUMN submitted_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier_portals ADD COLUMN approval_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier_portals ADD COLUMN buyer_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier_portals ADD COLUMN buyer_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier_portals ADD COLUMN buyer_contacted BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__companies AS SELECT id, name, sector, account_tier, region, pipeline_stage, website, address, city, country, linked_in_url, notes, created_at, updated_at FROM companies');
        $this->addSql('DROP TABLE companies');
        $this->addSql('CREATE TABLE companies (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, sector VARCHAR(100) NOT NULL, account_tier VARCHAR(10) NOT NULL, region VARCHAR(100) DEFAULT NULL, pipeline_stage VARCHAR(50) NOT NULL, website VARCHAR(255) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, country VARCHAR(50) DEFAULT NULL, linked_in_url VARCHAR(255) DEFAULT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO companies (id, name, sector, account_tier, region, pipeline_stage, website, address, city, country, linked_in_url, notes, created_at, updated_at) SELECT id, name, sector, account_tier, region, pipeline_stage, website, address, city, country, linked_in_url, notes, created_at, updated_at FROM __temp__companies');
        $this->addSql('DROP TABLE __temp__companies');
        $this->addSql('CREATE TEMPORARY TABLE __temp__supplier_portals AS SELECT id, company_id, registered, registration_date, portal_username, profile_completed, notes FROM supplier_portals');
        $this->addSql('DROP TABLE supplier_portals');
        $this->addSql('CREATE TABLE supplier_portals (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, company_id INTEGER NOT NULL, registered BOOLEAN DEFAULT 0 NOT NULL, registration_date DATE DEFAULT NULL, portal_username VARCHAR(255) DEFAULT NULL, profile_completed BOOLEAN DEFAULT 0 NOT NULL, notes CLOB DEFAULT NULL, CONSTRAINT FK_27DDE79B979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO supplier_portals (id, company_id, registered, registration_date, portal_username, profile_completed, notes) SELECT id, company_id, registered, registration_date, portal_username, profile_completed, notes FROM __temp__supplier_portals');
        $this->addSql('DROP TABLE __temp__supplier_portals');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_27DDE79B979B1AD6 ON supplier_portals (company_id)');
    }
}
