<?php
declare(strict_types=1);

/**
 * Fill in audio_sha256 for jokes imported before duplicate detection existed.
 *
 *   docker compose exec php php /var/www/html/bin/backfill-joke-hashes.php
 *
 * Safe to run repeatedly — rows that already have a hash are left alone.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$store = new JokeStore();
$repo = new JokeRepository();
$done = 0;
$missing = 0;

foreach ($repo->all() as $row) {
    if (($row['audio_sha256'] ?? null) !== null) {
        continue;
    }

    $file = $store->file((string) $row['audio_file']);
    if ($file === null) {
        echo "joke {$row['id']}: audio is missing, skipped\n";
        $missing++;
        continue;
    }

    $repo->setHash((int) $row['id'], (string) hash_file('sha256', $file));
    $done++;
}

echo "{$done} hashed";
if ($missing > 0) {
    echo ", {$missing} with no audio";
}
echo ".\n";
