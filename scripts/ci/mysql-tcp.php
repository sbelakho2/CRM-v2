<?php

/**
 * CI MySQL helper: talks to the throwaway test database over TCP
 * (127.0.0.1:3309) instead of `docker exec`.
 *
 * Why: the CI agent's docker CLI context does not necessarily match the
 * context the crm-ci-mysql container actually runs in — `docker exec` can
 * hit a stale namesake container while the tests talk to the real one over
 * the published port (pipeline 54/55/56 populated-upgrade failures:
 * "Unknown database 'crm_upgrade_test'").
 *
 * Usage:
 *   php scripts/ci/mysql-tcp.php <database> < statements.sql   # ;-separated
 *   php scripts/ci/mysql-tcp.php <database> -e "SELECT ..."     # one query, raw rows
 *
 * Credentials: crm_test / crm_test_pw (CI throwaway only).
 */

$host = getenv('CI_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('CI_MYSQL_PORT') ?: '3309';
$user = getenv('CI_MYSQL_USER') ?: 'crm_test';
$pass = getenv('CI_MYSQL_PASSWORD') ?: 'crm_test_pw';

if ($argc < 2) {
    fwrite(STDERR, "usage: mysql-tcp.php <database> [-e <query>]\n");
    fwrite(STDERR, "       mysql-tcp.php --root-grant <database>\n");
    exit(2);
}

if ($argv[1] === '--root-grant') {
    // GRANT ALL ON <db>.* TO the CI app user — as root, over TCP.
    $rootPw = getenv('CI_MYSQL_ROOT_PASSWORD') ?: 'ci_root_pw';
    $grantDb = $argv[2] ?? '';
    if ($grantDb === '' || !preg_match('/^[a-z0-9_]+$/i', $grantDb)) {
        fwrite(STDERR, "--root-grant: invalid database name\n");
        exit(2);
    }
    $root = new PDO(
        sprintf('mysql:host=%s;port=%s', $host, $port),
        'root',
        $rootPw,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $root->exec(sprintf('GRANT ALL ON `%s`.* TO %s', $grantDb, "'crm_test'@'%'"));
    $root->exec('FLUSH PRIVILEGES');
    exit(0);
}

$db = $argv[1];

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $db);
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'DB connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($argc >= 4 && $argv[2] === '-e') {
    foreach ($pdo->query($argv[3]) as $row) {
        echo implode("\t", array_map(fn ($v) => $v === null ? 'NULL' : (string) $v, $row)), "\n";
    }
    exit(0);
}

// Batch mode: split stdin on statement-terminating semicolons. The CI SQL
// payloads are generated (no literal ';' inside values by construction).
// Statements run via prepare/execute with rowset draining: PREPARE/EXECUTE
// payloads return result sets that a plain exec() leaves pending (PDO 2014).
$sql = stream_get_contents(STDIN);
foreach (preg_split('/;\s*(\n|$)/', $sql) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') {
        continue;
    }
    $st = $pdo->prepare($stmt);
    $st->execute();
    do {
        $st->fetchAll();
    } while ($st->nextRowset());
}
