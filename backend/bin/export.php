<?php
declare(strict_types=1);

/**
 * Everything the household has in twocans, in one zip they can keep: the call
 * log, voicemails and contacts as spreadsheets (CSV), and the recordings and
 * voicemails as audio — for a copy of their own, or for moving away.
 *
 *   ./twocans export                 (from the twocans folder)
 *   ./twocans export --no-audio      spreadsheets only
 *   docker compose exec web php /var/www/html/bin/export.php
 *
 * The zip is written to storage/exports/ on the host. Built with PHP's own
 * Phar, which needs no zip extension. Recordings already removed by the
 * retention setting are simply not there to export.
 *
 * Prints "kind<TAB>text" lines for ./twocans, the last being "file<TAB>name".
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$withAudio = !in_array('--no-audio', $argv, true);
$say = static function (string $kind, string $text): void {
    echo $kind, "\t", $text, "\n";
};

$dir = rtrim(getenv('EXPORTS_PATH') ?: '/var/lib/twocans/exports', '/');
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    $say('bad', "Couldn't make the exports folder ({$dir}).");
    exit(1);
}
$name = 'twocans-export-' . date('Y-m-d-His') . '.zip';
$path = $dir . '/' . $name;

/** A name safe for a file: letters, digits and dashes. */
$slug = static function (string $text): string {
    $text = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-');

    return $text !== '' ? substr($text, 0, 40) : 'unknown';
};
/** Rows as CSV text, with a header row. */
$csv = static function (array $header, array $rows): string {
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $header, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ',', '"', '');
    }
    rewind($fh);

    return (string) stream_get_contents($fh);
};
/** An audio file that's really in the recordings or voicemail folders. */
$readable = static function (?string $file): bool {
    $file = (string) $file;

    return $file !== ''
        && (str_starts_with($file, '/var/spool/asterisk/monitor/') || str_starts_with($file, '/var/spool/asterisk/voicemail/'))
        && !str_contains($file, '..')
        && is_file($file) && is_readable($file);
};

$pdo = Database::pdo();
try {
    $zip = new PharData($path, 0, null, Phar::ZIP);
} catch (Throwable $e) {
    $say('bad', "Couldn't start the zip: " . $e->getMessage());
    exit(1);
}

// -------------------------------------------------------------------- calls
$rows = [];
$audio = 0;
foreach ($pdo->query('SELECT * FROM calls ORDER BY started_at, id') as $c) {
    $file = '';
    if ($withAudio && $readable($c['recording_path'])) {
        $file = 'recordings/' . date('Y-m-d_Hi', (int) strtotime((string) $c['started_at']))
            . '_' . $slug((string) $c['peer_name']) . '_' . $c['id'] . '.wav';
        $zip->addFile((string) $c['recording_path'], $file);
        $audio++;
    }
    $rows[] = [
        $c['started_at'], $c['direction'] === 'in' ? 'incoming' : 'outgoing',
        $c['peer_name'], $c['peer_number'], $c['dialled'], $c['status'], $c['disposition'],
        (int) $c['billsec'], $file, (string) $c['transcript'],
    ];
}
$zip->addFromString('calls.csv', $csv(
    ['when', 'direction', 'who', 'number', 'dialled', 'status', 'detail', 'talk seconds', 'recording', 'transcript'],
    $rows
));
$say('ok', count($rows) . ' calls' . ($withAudio ? ", {$audio} with a recording" : ''));

// --------------------------------------------------------------- voicemails
$rows = [];
$audio = 0;
foreach ($pdo->query('SELECT * FROM voicemails ORDER BY left_at, id') as $v) {
    $file = '';
    if ($withAudio && $readable($v['audio_path'])) {
        $file = 'voicemails/' . date('Y-m-d_Hi', (int) strtotime((string) $v['left_at']))
            . '_' . $slug((string) $v['peer_name']) . '_' . $v['id'] . '.' . (pathinfo((string) $v['audio_path'], PATHINFO_EXTENSION) ?: 'wav');
        $zip->addFile((string) $v['audio_path'], $file);
        $audio++;
    }
    $rows[] = [
        $v['left_at'], $v['peer_name'], $v['peer_number'], (int) $v['duration_secs'],
        (int) $v['heard'] ? 'yes' : 'no', $file, (string) $v['transcript'],
    ];
}
$zip->addFromString('voicemails.csv', $csv(
    ['when', 'from', 'number', 'seconds', 'listened to', 'audio', 'transcript'],
    $rows
));
$say('ok', count($rows) . ' voicemails' . ($withAudio ? ", {$audio} with audio" : ''));

// ----------------------------------------------------------------- contacts
$rows = [];
foreach ($pdo->query('SELECT * FROM contacts ORDER BY name') as $c) {
    $rows[] = [
        $c['name'], $c['relationship'], (int) $c['is_group'] ? 'group' : 'person',
        $c['number_e164'], $c['speed_dial'],
        (int) $c['allow_in'] ? 'yes' : 'no', (int) $c['allow_out'] ? 'yes' : 'no', (int) $c['sos'] ? 'yes' : 'no',
    ];
}
$zip->addFromString('contacts.csv', $csv(
    ['name', 'relationship', 'type', 'number', 'speed dial', 'can call in', 'can be called', 'SOS'],
    $rows
));
$say('ok', count($rows) . ' contacts');

$zip->addFromString('README.txt', "twocans export — " . date('j F Y, H:i') . "\n\n"
    . "calls.csv       every call in the log, with its transcript\n"
    . "voicemails.csv  every voicemail, with its transcript\n"
    . "contacts.csv    the call list\n"
    . ($withAudio ? "recordings/     call recordings, named by date and who\nvoicemails/     voicemail audio\n" : '')
    . "\nThe .csv files open in Excel, Numbers, LibreOffice or Google Sheets.\n"
    . "Recordings removed by your retention setting aren't included.\n");
unset($zip);

$bytes = (int) filesize($path);
$say('ok', 'zip is ' . ($bytes < 1048576 ? max(1, round($bytes / 1024)) . 'KB' : round($bytes / 1048576, 1) . 'MB'));
$say('file', $name);
