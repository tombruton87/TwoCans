<?php
declare(strict_types=1);

/**
 * Applies pending SQL migrations from backend/migrations, in filename order.
 *
 *   docker exec twocans-php php /var/www/html/bin/migrate.php
 *   docker exec twocans-php php /var/www/html/bin/migrate.php --status
 *
 * MariaDB has no transactional DDL, so a migration that fails part-way is not
 * rolled back — keep each file small and idempotent (IF NOT EXISTS etc.).
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$statusOnly = in_array('--status', $argv, true);

$pdo = Database::pdo();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(120) NOT NULL,
        applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (version)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
sort($files);

echo "twocans — migrations\n", str_repeat('=', 52), "\n";

$pending = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    $done = in_array($version, $applied, true);

    if ($statusOnly) {
        printf("  [%s] %s\n", $done ? 'applied' : 'PENDING', $version);
        continue;
    }
    if ($done) {
        printf("  [skip]    %s\n", $version);
        continue;
    }

    $pending++;
    printf("  [apply]   %s ... ", $version);

    foreach (sql_statements((string) file_get_contents($file)) as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            echo "FAILED\n\n", $e->getMessage(), "\n\n", $stmt, "\n";
            exit(1);
        }
    }

    $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
    echo "ok\n";
}

echo str_repeat('=', 52), "\n";
echo $statusOnly ? "status only, nothing applied\n" : ($pending === 0 ? "already up to date\n" : "{$pending} migration(s) applied\n");

/**
 * Split a migration file into statements.
 *
 * Strips comments first — including trailing ones, which matters because a
 * semicolon inside a comment would otherwise split a statement in half — then
 * splits on the remaining semicolons.
 *
 * Follows MySQL's rule that `--` only begins a comment when followed by
 * whitespace. Still deliberately simple: it would mis-handle a semicolon inside
 * a string literal, so keep migrations free of those. These are our own files.
 */
function sql_statements(string $sql): array
{
    $sql = preg_replace('/(^|\s)--\s[^\n]*/m', '$1', $sql) ?? $sql;   // -- comments
    $sql = preg_replace('!/\*.*?\*/!s', '', $sql) ?? $sql;            // /* block */

    return array_values(array_filter(
        array_map('trim', explode(';', $sql)),
        static fn(string $s): bool => $s !== ''
    ));
}
