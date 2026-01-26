<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260126155252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Activity status/subject/duration, Playbook cooldown hours, and RFQ win/loss competitive intelligence fields';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activities ADD subject VARCHAR(255) DEFAULT NULL, ADD outcome_detail VARCHAR(50) DEFAULT NULL, ADD status VARCHAR(50) DEFAULT NULL, ADD duration_minutes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE playbooks ADD cooldown_hours INT DEFAULT 24');
        $this->addSql('ALTER TABLE rfqs ADD loss_reason VARCHAR(50) DEFAULT NULL, ADD loss_reason_detail LONGTEXT DEFAULT NULL, ADD competitor_won VARCHAR(255) DEFAULT NULL, ADD winning_bid_amount NUMERIC(15, 2) DEFAULT NULL, ADD lessons_learned LONGTEXT DEFAULT NULL, ADD win_factors LONGTEXT DEFAULT NULL, ADD decision_date DATE DEFAULT NULL, ADD award_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activities DROP subject, DROP outcome_detail, DROP status, DROP duration_minutes');
        $this->addSql('ALTER TABLE playbooks DROP cooldown_hours');
        $this->addSql('ALTER TABLE rfqs DROP loss_reason, DROP loss_reason_detail, DROP competitor_won, DROP winning_bid_amount, DROP lessons_learned, DROP win_factors, DROP decision_date, DROP award_date');
    }
}
