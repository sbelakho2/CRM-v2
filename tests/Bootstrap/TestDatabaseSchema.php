<?php

namespace App\Tests\Bootstrap;

use Doctrine\DBAL\Connection;

class TestDatabaseSchema
{
    public static function createSchema(Connection $connection): void
    {
        TestDatabaseGuard::assertSafeTestDatabase($connection);

        $platform = $connection->getDatabasePlatform()->getName();

        // This helper is used by a small number of functional template/controller tests.
        // Keep it resilient across DB platforms.
        if ($platform === 'mysql') {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');

            // Ensure tables exist (SchemaTool bootstrap should already do this), then reset contents.
            // TRUNCATE is safer than DROP when other tables may reference these.
            $connection->executeStatement('CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(180) NOT NULL,
                roles LONGTEXT NOT NULL,
                password VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                role VARCHAR(50) NULL,
                territory VARCHAR(100) NULL,
                active TINYINT(1) NOT NULL DEFAULT 1
            )');
            $connection->executeStatement('CREATE TABLE IF NOT EXISTS email_campaigns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                language VARCHAR(10) NOT NULL DEFAULT \'EN\',
                description TEXT NULL,
                touch_count INT NOT NULL DEFAULT 5,
                touch_templates LONGTEXT NOT NULL,
                ab_test_variants LONGTEXT NULL,
                active TINYINT(1) NOT NULL DEFAULT 0,
                scheduled_at DATETIME NULL,
                template_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
            $connection->executeStatement('CREATE TABLE IF NOT EXISTS email_sends (
                id INT AUTO_INCREMENT PRIMARY KEY,
                campaign_id INT NOT NULL,
                contact_id INT NOT NULL,
                touch_number INT NOT NULL,
                sent_at DATETIME NULL,
                INDEX IDX_EMAIL_SENDS_CAMPAIGN (campaign_id)
            )');
            $connection->executeStatement('CREATE TABLE IF NOT EXISTS contacts_email_campaigns (
                contact_id INT NOT NULL,
                campaign_id INT NOT NULL,
                PRIMARY KEY (contact_id, campaign_id)
            )');

            // Reset data
            $connection->executeStatement('TRUNCATE TABLE contacts_email_campaigns');
            $connection->executeStatement('TRUNCATE TABLE email_sends');
            $connection->executeStatement('TRUNCATE TABLE email_campaigns');
            $connection->executeStatement('TRUNCATE TABLE users');

            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

            return;
        }

        // Default/fallback (SQLite-style)
        $schema = [
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(180) NOT NULL,
                roles CLOB NOT NULL,
                password VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                role VARCHAR(50) NULL,
                territory VARCHAR(100) NULL,
                active BOOLEAN NOT NULL DEFAULT 1
            )',
            'CREATE TABLE IF NOT EXISTS email_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                language VARCHAR(10) NOT NULL DEFAULT "EN",
                description TEXT NULL,
                touch_count INTEGER NOT NULL DEFAULT 5,
                touch_templates CLOB NOT NULL DEFAULT "[]",
                ab_test_variants CLOB NULL,
                active BOOLEAN NOT NULL DEFAULT 0,
                scheduled_at DATETIME NULL,
                template_id INTEGER NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE IF NOT EXISTS email_sends (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                contact_id INTEGER NOT NULL,
                touch_number INTEGER NOT NULL,
                sent_at DATETIME NULL,
                FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE
            )',
            'CREATE TABLE IF NOT EXISTS contacts_email_campaigns (
                contact_id INTEGER NOT NULL,
                campaign_id INTEGER NOT NULL,
                PRIMARY KEY (contact_id, campaign_id),
                FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE
            )'
        ];

        // Drop all tables first to ensure clean state
        $connection->executeStatement("DROP TABLE IF EXISTS contacts_email_campaigns");
        $connection->executeStatement("DROP TABLE IF EXISTS email_sends");
        $connection->executeStatement("DROP TABLE IF EXISTS email_campaigns");
        $connection->executeStatement("DROP TABLE IF EXISTS users");

        // Create tables
        foreach ($schema as $sql) {
            $connection->executeStatement($sql);
        }
    }
}