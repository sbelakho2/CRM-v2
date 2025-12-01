<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251201143626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_logs (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, entity_type VARCHAR(100) NOT NULL, entity_id INT NOT NULL, action VARCHAR(50) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, old_values JSON DEFAULT NULL, new_values JSON DEFAULT NULL, changed_fields JSON DEFAULT NULL, created_at DATETIME NOT NULL, user_agent VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, INDEX idx_entity (entity_type, entity_id), INDEX idx_user (user_id), INDEX idx_action (action), INDEX idx_created_at (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_D62F2858A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_logs DROP FOREIGN KEY FK_D62F2858A76ED395');
        $this->addSql('DROP TABLE audit_logs');
    }
}
