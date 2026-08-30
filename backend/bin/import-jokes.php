<?php
declare(strict_types=1);

/**
 * Bring a folder of audio files in as jokes.
 *
 *   docker compose exec php php /var/www/html/bin/import-jokes.php /path/to/folder
 *   docker compose exec php php /var/www/html/bin/import-jokes.php /path --dry-run
 *
 * Each file is converted to the format the dialplan plays and queued for
 * transcription. Nothing is deleted from the source folder — check the jokes
 * page first, then remove it yourself.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => !str_starts_with($a, '--')));
$dryRun = in_array('--dry-run', $argv, true);

$folder = rtrim($args[0] ?? '', '/');
if ($folder === '' || !is_dir($folder)) {
    fwrite(STDERR, "usage: import-jokes.php <folder> [--dry-run]\n");
    exit(1);
}

$store = new JokeStore();
if (!$store->isAvailable()) {
    fwrite(STDERR, "ffmpeg is not installed in this container — rebuild the php image.\n");
    exit(1);
}

// Anything ffmpeg might decode. The conversion itself is the real check.
$files = array_values(array_filter(
    glob($folder . '/*') ?: [],
    static fn(string $f): bool => is_file($f)
        && !str_starts_with(basename($f), '.')
        && preg_match('/\.(mp3|m4a|aac|wav|wave|ogg|oga|opus|flac|webm|amr|3gp|3gpp|aiff|aif|wma|mp4|mov)$/i', $f) === 1
));

if ($files === []) {
    fwrite(STDERR, "no audio files in {$folder}\n");
    exit(1);
}

sort($files);
echo count($files) . " file(s) to import from {$folder}\n\n";

$jokes = new JokeRepository();
$imported = 0;
$failed = 0;
$skipped = 0;

foreach ($files as $file) {
    $name = basename($file);
    printf("%-58s ", mb_strimwidth($name, 0, 56, '…'));

    if ($dryRun) {
        echo "(dry run)\n";
        continue;
    }

    $result = $store->convert($file, $name);

    if ($result['file'] === null) {
        echo "FAILED — {$result['error']}\n";
        $failed++;
        continue;
    }

    /*
     * A folder that gets re-added, or the same clip exported twice, would
     * otherwise put the same joke on the line more than once — and make it
     * that much likelier to come up. The converted audio is thrown away
     * again rather than left orphaned on disk.
     */
    $existing = $jokes->findByHash($result['sha256']);
    if ($existing !== null) {
        $store->delete($result['file']);
        echo "already have it (joke {$existing['id']})\n";
        $skipped++;
        continue;
    }

    $jokes->create($result['file'], $result['seconds'], $name, null, $result['sha256']);
    echo "ok — {$result['seconds']}s\n";
    $imported++;
}

echo "\n{$imported} imported";
if ($skipped > 0) {
    echo ", {$skipped} already had";
}
if ($failed > 0) {
    echo ", {$failed} failed";
}
echo ".\n";

if ($imported > 0) {
    // The dialplan lists the jokes by name, so it has to be rewritten before
    // any of these can be dialled.
    $result = (new PjsipConfig())->apply();
    echo $result['reloaded']
        ? "Dialplan reloaded — dial " . PjsipConfig::jokeNumber() . " to hear one.\n"
        : "Wrote the dialplan but could not reload Asterisk: " . ($result['error'] ?? '?') . "\n";

    echo "Transcripts will appear as the worker picks them up.\n";
}
