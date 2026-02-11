<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deprecated migration (superseded by Version20260212130000).
 */
final class Version20260211133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deprecated: superseded by Version20260212130000';
    }

    public function up(Schema $schema): void
    {
        // No-op
    }

    public function down(Schema $schema): void
    {
        // No-op
    }
}
