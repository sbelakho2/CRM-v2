<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add display currency preference and RFQ currency';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD display_currency VARCHAR(10) DEFAULT 'USD'");
        $this->addSql("ALTER TABLE rfqs ADD currency VARCHAR(10) DEFAULT 'EUR'");
        $this->addSql("UPDATE users SET display_currency = 'USD' WHERE display_currency IS NULL");
        $this->addSql("UPDATE rfqs SET currency = 'EUR' WHERE currency IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP display_currency');
        $this->addSql('ALTER TABLE rfqs DROP currency');
    }
}
