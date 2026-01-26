<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260126153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user locale/timezone preferences and remove display currency default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD preferred_locale VARCHAR(10) DEFAULT NULL");
        $this->addSql("ALTER TABLE users ADD preferred_timezone VARCHAR(64) DEFAULT NULL");
        $this->addSql("ALTER TABLE users MODIFY display_currency VARCHAR(10) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users DROP preferred_locale");
        $this->addSql("ALTER TABLE users DROP preferred_timezone");
        $this->addSql("ALTER TABLE users MODIFY display_currency VARCHAR(10) DEFAULT 'USD'");
    }
}
