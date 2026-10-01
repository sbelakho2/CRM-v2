<?php

namespace App\Tests\Functional\Regression;

use App\Command\PreserveComplianceLegacyCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Critical-surface coverage: the command that stands between the live
 * database and the destructive historical migration
 * (Version20260824120000 drops the legacy compliance columns). The full
 * populated-upgrade CI lane proves the whole chain; THIS test proves the
 * command's own contract exactly:
 *   1. archive every carrying row into compliance_legacy_preserved,
 *   2. CLEAR the live columns (so the preflight stops blocking),
 *   3. be idempotent AND refreshing — a re-run after values change must
 *      archive the NEW values, never the stale first-run ones,
 *   4. be era-safe: without pre-drop columns it is a no-op that can never
 *      re-archive restored data.
 *
 * The legacy columns are added and removed WITHIN the test — the throwaway
 * MySQL only; the live database is never touched by this suite.
 *
 * Each test runs in a SEPARATE PHP process: MySQL DDL implicitly commits,
 * which would break the per-test transaction wrapper (DAMA) for every
 * later test sharing the process — an order-dependent suite poisoning
 * that only shows under certain random seeds.
 */
class PreserveComplianceLegacyCommandTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private PreserveComplianceLegacyCommand $command;

    private const LEGACY_COLUMNS = [
        'document_type' => 'VARCHAR(255) DEFAULT NULL',
        'sha256_hash' => 'VARCHAR(64) DEFAULT NULL',
        'version_id' => 'VARCHAR(100) DEFAULT NULL',
    ];

    /** Columns THIS test actually added (the latest schema already has
     *  sha256_hash/version_id — tearDown must never drop those). */
    private array $addedColumns = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $this->command = new PreserveComplianceLegacyCommand($this->connection);

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['compliance_documents'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        // The archive table may not exist on a fresh chain (the command
        // creates it) — reset it here so every test starts clean.
        $this->connection->executeStatement('DROP TABLE IF EXISTS compliance_legacy_preserved');

        // Re-create the PRE-DROP era on the throwaway DB: add ONLY the
        // legacy columns the latest schema no longer has (document_type).
        // sha256_hash/version_id exist post-restore — they are REAL data
        // columns and must never be touched.
        foreach (self::LEGACY_COLUMNS as $column => $definition) {
            if (!$this->columnExists($column)) {
                $this->connection->executeStatement(
                    "ALTER TABLE compliance_documents ADD COLUMN {$column} {$definition}"
                );
                $this->addedColumns[] = $column;
            }
        }
        $this->connection->executeStatement(
            'CREATE TABLE compliance_legacy_preserved (
                document_id INT NOT NULL PRIMARY KEY,
                document_type VARCHAR(255) DEFAULT NULL,
                sha256_hash VARCHAR(64) DEFAULT NULL,
                version_id VARCHAR(100) DEFAULT NULL,
                preserved_at DATETIME NOT NULL
            ) ENGINE = InnoDB'
        );
    }

    protected function tearDown(): void
    {
        // Remove ONLY the columns this test added — never the real
        // (restored-era) schema. Dropping sha256_hash/version_id here
        // poisoned every later test in the suite (order-dependent).
        foreach ($this->addedColumns as $column) {
            if ($this->columnExists($column)) {
                $this->connection->executeStatement("ALTER TABLE compliance_documents DROP {$column}");
            }
        }
        $this->addedColumns = [];
        $this->connection->executeStatement('DROP TABLE IF EXISTS compliance_legacy_preserved');
        parent::tearDown();
    }

    private function columnExists(string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['compliance_documents', $column]
        );
    }

    private function seedDocument(string $name, string $doctype, string $hash, string $versionId): int
    {
        $company = $this->connection->fetchOne('SELECT id FROM companies LIMIT 1');
        if ($company === false) {
            $this->connection->executeStatement(
                "INSERT INTO companies (name, account_tier, pipeline_stage, company_status, created_at)
                 VALUES ('Preserve Test Co', 'C', 'Prospect', 'approved', NOW())"
            );
            $company = $this->connection->lastInsertId();
        }

        $this->connection->executeStatement(
            'INSERT INTO compliance_documents (company_id, name, required, provided, document_type, sha256_hash, version_id)
             VALUES (?, ?, 1, 1, ?, ?, ?)',
            [$company, $name, $doctype, $hash, $versionId]
        );

        return (int) $this->connection->lastInsertId();
    }

    private function runCommand(): void
    {
        $exit = $this->command->run(new ArrayInput([]), new NullOutput());
        $this->assertSame(0, $exit, 'the preserve command must succeed');
    }

    /** @runInSeparateProcess
     *  @preserveGlobalState disabled */
    public function testArchiveClearAndVerifyIsTheContract(): void
    {
        $docId = $this->seedDocument('Legacy ISO', 'SENTINEL_TYPE_A1', 'hash_aaa111', 'ver_111');

        $this->runCommand();

        // 1. Archived with the exact values.
        $archived = $this->connection->fetchAssociative(
            'SELECT * FROM compliance_legacy_preserved WHERE document_id = ?',
            [$docId]
        );
        $this->assertNotFalse($archived);
        $this->assertSame('SENTINEL_TYPE_A1', $archived['document_type']);
        $this->assertSame('hash_aaa111', $archived['sha256_hash']);
        $this->assertSame('ver_111', $archived['version_id']);

        // 2. Live columns CLEARED — the drop migration is now safe.
        $live = $this->connection->fetchAssociative(
            'SELECT document_type, sha256_hash, version_id FROM compliance_documents WHERE id = ?',
            [$docId]
        );
        $this->assertNull($live['document_type']);
        $this->assertNull($live['sha256_hash']);
        $this->assertNull($live['version_id']);
    }

    /** @runInSeparateProcess
     *  @preserveGlobalState disabled */
    public function testReRunRefreshesTheArchiveToFinalPreMigrationState(): void
    {
        $docId = $this->seedDocument('Refresh Doc', 'TYPE_FIRST', 'hash_first', 'ver_first');
        $this->runCommand();

        // Legacy fields change after the first run (operator backfill).
        $this->connection->executeStatement(
            'UPDATE compliance_documents SET document_type = ?, sha256_hash = ?, version_id = ? WHERE id = ?',
            ['TYPE_SECOND', 'hash_second', 'ver_second', $docId]
        );
        $this->runCommand();

        $archived = $this->connection->fetchAssociative(
            'SELECT * FROM compliance_legacy_preserved WHERE document_id = ?',
            [$docId]
        );
        $this->assertSame('TYPE_SECOND', $archived['document_type'], 're-run must archive the FINAL values, never the stale first-run ones');
        $this->assertSame('hash_second', $archived['sha256_hash']);
        $this->assertSame('ver_second', $archived['version_id']);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM compliance_legacy_preserved'), 'no duplicate archive rows');
    }

    /** @runInSeparateProcess
     *  @preserveGlobalState disabled */
    public function testWithoutLegacyColumnsTheCommandIsANoOp(): void
    {
        // Drop back to the latest-era schema INSIDE the test: the era
        // detector must see "not applicable" and never re-archive restored
        // data (the columns that exist post-restore are the REAL ones).
        foreach (array_keys(self::LEGACY_COLUMNS) as $column) {
            $this->connection->executeStatement("ALTER TABLE compliance_documents DROP {$column}");
        }
        $this->connection->executeStatement(
            'INSERT INTO compliance_legacy_preserved (document_id, document_type, sha256_hash, version_id, preserved_at)
             VALUES (424242, \'RESTORED_VALUE\', \'RESTORED_HASH\', \'RESTORED_VER\', NOW()), (424243, NULL, NULL, NULL, NOW())'
        );

        $this->runCommand();

        // The archive rows stand EXACTLY as restored — untouched.
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM compliance_legacy_preserved ORDER BY document_id');
        $this->assertCount(2, $rows);
        $this->assertSame('RESTORED_VALUE', $rows[0]['document_type']);
        $this->assertNull($rows[1]['document_type']);
    }
}
