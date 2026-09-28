<?php
declare(strict_types=1);

/**
 * Create or list twocans backups.
 *
 *   ./twocans backup / ./twocans backups     (from the twocans folder)
 *   docker compose exec web php /var/www/html/bin/backup.php          # create
 *   docker compose exec web php /var/www/html/bin/backup.php --list
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$backup = new Backup();

// One backup per line, "name<TAB>bytes<TAB>when" — for ./twocans.
if (in_array('--tsv', $argv, true)) {
    foreach ($backup->list() as $b) {
        echo $b['name'], "\t", $b['size'], "\t", $b['when'], "\n";
    }
    exit(0);
}

if (in_array('--list', $argv, true)) {
    echo "Backups\n", str_repeat('=', 52), "\n";
    $rows = $backup->list();
    if ($rows === []) {
        echo "  (none yet)\n";
        exit(0);
    }
    foreach ($rows as $b) {
        printf("  %-40s %9s  %s\n", $b['name'], round($b['size'] / 1048576, 1) . ' MB', $b['when']);
    }
    exit(0);
}

$result = $backup->create();
if (!$result['ok']) {
    fwrite(STDERR, "Backup failed: " . ($result['error'] ?? 'unknown reason') . "\n");
    exit(1);
}

echo "Backup created: " . $result['name'] . "\n";
