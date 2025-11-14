<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add password reset fields to users table
 */
final class Version20251113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reset_token and reset_token_expires_at fields to users table for password reset functionality';
    }

    public function up(Schema $schema): void
    {
        // Add reset token fields
        $this->addSql('ALTER TABLE users ADD reset_token VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD reset_token_expires_at DATETIME DEFAULT NULL');
        
        // Add index for faster token lookups
        $this->addSql('CREATE INDEX IDX_reset_token ON users (reset_token)');
    }

    public function down(Schema $schema): void
    {
        // Drop index
        $this->addSql('DROP INDEX IDX_reset_token ON users');
        
        // Remove reset token fields
        $this->addSql('ALTER TABLE users DROP reset_token');
        $this->addSql('ALTER TABLE users DROP reset_token_expires_at');
    }
}
