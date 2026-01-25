<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Ensure the test database schema exists before running tests.
// We cannot rely on SQLite here because the runtime environment may not have pdo_sqlite/sqlite3.
if (($_SERVER['APP_ENV'] ?? null) === 'test') {
    \App\Tests\Bootstrap\DoctrineSchemaBootstrap::ensureSchema();
}