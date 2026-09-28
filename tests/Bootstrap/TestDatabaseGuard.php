<?php

namespace App\Tests\Bootstrap;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SqlitePlatform;

/**
 * Fail-closed guard against destructive test operations on a non-test database.
 *
 * The test bootstrap drops/recreates the Doctrine schema and truncates tables.
 * Historically phpunit.xml.dist pointed at the real `starz_crm` database, which
 * meant a plain `vendor/bin/phpunit` would wipe it. Every destructive code path
 * must call assertSafeTestDatabase() first: it refuses to proceed unless the
 * process is running with APP_ENV=test AND the connected database is
 * unambiguously test-only (name ending in `_test`, or an in-memory/file sqlite
 * database reserved for tests).
 */
final class TestDatabaseGuard
{
    public static function assertSafeTestDatabase(Connection $connection): void
    {
        if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) !== 'test') {
            throw new \RuntimeException(
                'Refusing destructive test operation: APP_ENV is not "test".'
            );
        }

        $params = $connection->getParams();
        $driver = $params['driver'] ?? null;

        if ($driver === 'pdo_sqlite') {
            $path = $params['path'] ?? null;
            // In-memory sqlite is inherently disposable; file-backed sqlite
            // must live in a *_test file so a dev/prod database can never match.
            if ($path === null || $path === ':memory:') {
                return;
            }
            if (!str_contains(strtolower(basename($path)), '_test')) {
                throw new \RuntimeException(sprintf(
                    'Refusing destructive test operation against non-test sqlite database "%s".',
                    $path
                ));
            }

            return;
        }

        $database = self::currentDatabase($connection);

        if ($database === '' || $database === null) {
            throw new \RuntimeException(
                'Refusing destructive test operation: unable to determine the connected database name.'
            );
        }

        $knownProductionNames = ['starz_crm', 'crm', 'starzcrm'];
        if (in_array(strtolower($database), $knownProductionNames, true)
            || !str_ends_with(strtolower($database), '_test')) {
            throw new \RuntimeException(sprintf(
                'Refusing destructive test operation against non-test database "%s". '.
                'Tests must use a dedicated database whose name ends with "_test" (e.g. starz_crm_test).',
                $database
            ));
        }
    }

    private static function currentDatabase(Connection $connection): ?string
    {
        if ($connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $path = $connection->getParams()['path'] ?? ':memory:';

            return $path === ':memory:' ? null : basename((string) $path);
        }

        // getDatabase() may issue a connection; SELECT DATABASE() is the
        // authoritative answer on MySQL/MariaDB.
        try {
            $value = $connection->fetchOne('SELECT DATABASE()');

            return is_string($value) ? $value : null;
        } catch (\Throwable) {
            $params = $connection->getParams();
            $name = $params['dbname'] ?? null;

            return is_string($name) ? $name : null;
        }
    }
}
