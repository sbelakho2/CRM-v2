<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add contact form detection and scraping metadata fields to leads table.
 * Part of Lead Discovery improvements for enhanced scraping with headless browser.
 */
final class Version20260128160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add has_contact_form, scraping_method, pages_scraped, and last_scraped_at fields to leads table';
    }

    public function up(Schema $schema): void
    {
        // Add contact form detection flag
        $this->addSql('ALTER TABLE leads ADD has_contact_form TINYINT(1) DEFAULT 0 NOT NULL');
        
        // Add scraping metadata fields
        $this->addSql('ALTER TABLE leads ADD scraping_method VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD pages_scraped INT DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD last_scraped_at DATETIME DEFAULT NULL');
        
        // Add index for contact form filtering
        $this->addSql('CREATE INDEX IDX_leads_has_contact_form ON leads (has_contact_form)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_leads_has_contact_form ON leads');
        $this->addSql('ALTER TABLE leads DROP has_contact_form');
        $this->addSql('ALTER TABLE leads DROP scraping_method');
        $this->addSql('ALTER TABLE leads DROP pages_scraped');
        $this->addSql('ALTER TABLE leads DROP last_scraped_at');
    }
}
