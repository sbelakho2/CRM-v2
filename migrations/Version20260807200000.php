<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260807200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make playbooks.priority nullable to match the entity contract';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playbooks CHANGE priority priority INT DEFAULT NULL');
        $this->addSql('ALTER TABLE onboarding_packs DROP FOREIGN KEY FK_780B2E3D1C5F1E1B');
        $this->addSql('ALTER TABLE onboarding_packs CHANGE portal_candidate_id portal_candidate_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE onboarding_packs CHANGE portal_candidate_id portal_candidate_id INT NOT NULL');
        $this->addSql('ALTER TABLE playbooks CHANGE priority priority INT NOT NULL');
    }
}
