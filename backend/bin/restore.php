<?php
declare(strict_types=1);

/**
 * Restore a twocans backup. Destructive — kept out of the web UI on purpose.
 *
 *   docker compose exec php php /var/www/html/bin/restore.php --dry-run twocans-….tgz
 *   docker compose exec php php /var/www/html/bin/restore.php twocans-….tgz
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$dryRun = in_array('--dry-run', $argv, true);
$name = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '-')) {
        continue;
    }
    $name = $arg;
}

if ($name === null) {
    fwrite(STDERR, "Usage: php bin/restore.php [--dry-run] <backup.tgz>\n");
    exit(1);
}

if (!$dryRun) {
    echo "This will replace the current database and files with {$name}.\n";
    echo "A safety dump is written first. Type RESTORE to continue: ";
    $confirm = trim((string) fgets(STDIN));
    if ($confirm !== 'RESTORE') {
        echo "Aborted.\n";
        exit(1);
    }
}

$result = (new Backup())->restore($name, $dryRun);

if (!$result['ok']) {
    fwrite(STDERR, "Restore failed: " . ($result['error'] ?? 'unknown reason') . "\n");
    exit(1);
}

if ($dryRun) {
    echo "Dry run — would restore:\n";
    echo "  - database ({$name}/db.sql)\n";
    foreach ($result['files'] ?? [] as $file) {
        echo "  - {$file}\n";
    }
    exit(0);
}

echo "Restored the database and " . count($result['files'] ?? []) . " folder(s).\n";
echo "Now regenerate Asterisk config and restart it:\n";
echo "  docker compose exec php php /var/www/html/bin/apply-config.php\n";
echo "  docker compose restart asterisk\n";
