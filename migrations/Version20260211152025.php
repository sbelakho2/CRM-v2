<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260211152025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE outbound_messages RENAME INDEX idx_obm_rfq TO IDX_642BA0C1ABD9545F');
        $this->addSql('ALTER TABLE rfqs RENAME INDEX idx_rfqs_contact TO IDX_530068A8E7A1254A');
        $this->addSql('ALTER TABLE rfqs RENAME INDEX idx_rfqs_lead TO IDX_530068A855458D');
        $this->addSql('ALTER TABLE users ADD font_size VARCHAR(20) DEFAULT NULL, ADD density VARCHAR(20) DEFAULT NULL, ADD reduced_motion TINYINT(1) DEFAULT 0 NOT NULL, CHANGE accent_color accent_color VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE outbound_messages RENAME INDEX idx_642ba0c1abd9545f TO IDX_OBM_RFQ');
        $this->addSql('ALTER TABLE rfqs RENAME INDEX idx_530068a8e7a1254a TO IDX_RFQS_CONTACT');
        $this->addSql('ALTER TABLE rfqs RENAME INDEX idx_530068a855458d TO IDX_RFQS_LEAD');
        $this->addSql('ALTER TABLE users DROP font_size, DROP density, DROP reduced_motion, CHANGE accent_color accent_color VARCHAR(7) DEFAULT NULL');
    }
}
