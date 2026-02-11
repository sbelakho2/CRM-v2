<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User UI preferences: theme + accent color.
 */
final class Version20260212130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add preferred_theme and accent_color to users';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns('users');

        if (!isset($columns['preferred_theme'])) {
            $this->addSql('ALTER TABLE users ADD preferred_theme VARCHAR(20) DEFAULT NULL');
        }

        if (!isset($columns['accent_color'])) {
            $this->addSql('ALTER TABLE users ADD accent_color VARCHAR(7) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP preferred_theme');
        $this->addSql('ALTER TABLE users DROP accent_color');
    }
}
