<?php
declare(strict_types=1);

/**
 * Re-read every stored call from Asterisk's CDR file.
 *
 * For after the way a CDR is read has changed: fixes who each call was with
 * and which way it went, and drops rows the import now ignores. Recordings,
 * transcripts and listen-in marks are left alone.
 *
 *   docker compose exec web php /var/www/html/bin/refresh-calls.php
 */

require __DIR__ . '/../src/init.php';

$repo = new CallRepository(new DeviceRepository());
$before = (int) Database::pdo()->query('SELECT COUNT(*) FROM calls')->fetchColumn();
$repo->import(true);
$after = (int) Database::pdo()->query('SELECT COUNT(*) FROM calls')->fetchColumn();

echo "Refreshed. Calls stored: {$before} → {$after}.\n";
