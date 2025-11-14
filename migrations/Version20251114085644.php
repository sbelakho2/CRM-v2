<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251114085644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contact_email_campaigns (contact_id INT NOT NULL, email_campaign_id INT NOT NULL, INDEX IDX_776FD465E7A1254A (contact_id), INDEX IDX_776FD465E0F98BC3 (email_campaign_id), PRIMARY KEY(contact_id, email_campaign_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE contact_email_campaigns ADD CONSTRAINT FK_776FD465E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contact_email_campaigns ADD CONSTRAINT FK_776FD465E0F98BC3 FOREIGN KEY (email_campaign_id) REFERENCES email_campaigns (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email_campaigns ADD template_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE email_campaigns ADD CONSTRAINT FK_EC78EB5B5DA0FB8 FOREIGN KEY (template_id) REFERENCES email_template (id)');
        $this->addSql('CREATE INDEX IDX_EC78EB5B5DA0FB8 ON email_campaigns (template_id)');
        $this->addSql('DROP INDEX IDX_reset_token ON users');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contact_email_campaigns DROP FOREIGN KEY FK_776FD465E7A1254A');
        $this->addSql('ALTER TABLE contact_email_campaigns DROP FOREIGN KEY FK_776FD465E0F98BC3');
        $this->addSql('DROP TABLE contact_email_campaigns');
        $this->addSql('ALTER TABLE email_campaigns DROP FOREIGN KEY FK_EC78EB5B5DA0FB8');
        $this->addSql('DROP INDEX IDX_EC78EB5B5DA0FB8 ON email_campaigns');
        $this->addSql('ALTER TABLE email_campaigns DROP template_id');
        $this->addSql('CREATE INDEX IDX_reset_token ON users (reset_token)');
    }
}
