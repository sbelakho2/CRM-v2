<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sales Pipeline Rationalization — add missing FKs and nurturing stage column.
 *
 * Changes:
 *   rfqs          + contact_id  FK → contacts(id) ON DELETE SET NULL
 *   rfqs          + lead_id     FK → leads(id) ON DELETE SET NULL
 *   outbound_messages + rfq_id  FK → rfqs(id) ON DELETE SET NULL
 *   leads         + nurturing_stage VARCHAR(30) NULLABLE + INDEX
 */
final class Version20260212120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales Pipeline Rationalization: add RFQ.contact_id, RFQ.lead_id, OutboundMessage.rfq_id FKs, Lead.nurturing_stage column';
    }

    public function up(Schema $schema): void
    {
        // -- rfqs: add contact_id FK -----------------------------------------
        $this->addSql('ALTER TABLE rfqs ADD contact_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE rfqs ADD CONSTRAINT FK_BA4CF0F1E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_RFQS_CONTACT ON rfqs (contact_id)');

        // -- rfqs: add lead_id FK --------------------------------------------
        $this->addSql('ALTER TABLE rfqs ADD lead_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE rfqs ADD CONSTRAINT FK_BA4CF0F155458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_RFQS_LEAD ON rfqs (lead_id)');

        // -- outbound_messages: add rfq_id FK --------------------------------
        $this->addSql('ALTER TABLE outbound_messages ADD rfq_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE outbound_messages ADD CONSTRAINT FK_OBM_RFQ FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_OBM_RFQ ON outbound_messages (rfq_id)');

        // -- leads: add nurturing_stage column + index -----------------------
        $this->addSql("ALTER TABLE leads ADD nurturing_stage VARCHAR(30) DEFAULT NULL");
        $this->addSql('CREATE INDEX idx_leads_nurturing ON leads (nurturing_stage)');
    }

    public function down(Schema $schema): void
    {
        // -- leads: drop nurturing_stage
        $this->addSql('DROP INDEX idx_leads_nurturing ON leads');
        $this->addSql('ALTER TABLE leads DROP nurturing_stage');

        // -- outbound_messages: drop rfq_id FK
        $this->addSql('ALTER TABLE outbound_messages DROP FOREIGN KEY FK_OBM_RFQ');
        $this->addSql('DROP INDEX IDX_OBM_RFQ ON outbound_messages');
        $this->addSql('ALTER TABLE outbound_messages DROP rfq_id');

        // -- rfqs: drop lead_id FK
        $this->addSql('ALTER TABLE rfqs DROP FOREIGN KEY FK_BA4CF0F155458D');
        $this->addSql('DROP INDEX IDX_RFQS_LEAD ON rfqs');
        $this->addSql('ALTER TABLE rfqs DROP lead_id');

        // -- rfqs: drop contact_id FK
        $this->addSql('ALTER TABLE rfqs DROP FOREIGN KEY FK_BA4CF0F1E7A1254A');
        $this->addSql('DROP INDEX IDX_RFQS_CONTACT ON rfqs');
        $this->addSql('ALTER TABLE rfqs DROP contact_id');
    }
}
