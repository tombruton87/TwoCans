<?php
declare(strict_types=1);

/**
 * How the household's line is doing, for `./twocans status`.
 *
 * Prints one line per fact as "kind<TAB>text", kind being section, ok, warn,
 * bad or note; ./twocans turns those into the coloured lines the installer
 * uses. Run on its own it's still readable.
 *
 *   docker compose exec web php /var/www/html/bin/status.php
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$say = static function (string $kind, string $text): void {
    echo $kind, "\t", $text, "\n";
};
$ago = static function (int $seconds): string {
    return match (true) {
        $seconds < 90 => 'just now',
        $seconds < 5400 => round($seconds / 60) . ' minutes ago',
        $seconds < 129600 => round($seconds / 3600) . ' hours ago',
        default => round($seconds / 86400) . ' days ago',
    };
};

$say('section', 'twocans ' . app_version());

// ------------------------------------------------------------------- health
// The same checks as the System screen; only what's wrong is spelt out.
$checks = SystemHealth::checks();
$failed = array_filter($checks, static fn(array $c): bool => !$c['ok']);
if ($failed === []) {
    $say('ok', 'all ' . count($checks) . ' system checks pass');
} else {
    foreach ($failed as $c) {
        $say('bad', $c['label'] . ' — ' . $c['detail']);
    }
}

// ------------------------------------------------------------------- phones
$devices = new DeviceRepository();
try {
    (new PjsipConfig($devices))->syncRegistrations();
} catch (Throwable) {
    // Asterisk not answering is already reported by the checks above.
}
$phones = array_map([DeviceRepository::class, 'toView'], $devices->all());
$say('section', 'Phones');
if ($phones === []) {
    $say('warn', 'no phones yet — add one on the Phones screen');
}
foreach ($phones as $d) {
    $label = $d['name'] . ' (' . $d['model'] . ', extension ' . $d['extension'] . ')'
        . ($d['adult'] ? ' — adult mode' : '');
    if ($d['online']) {
        $say('ok', $label . ' — online');
    } elseif ($d['registered']) {
        $say('warn', $label . ' — offline, last seen ' . $d['lastSeen']);
    } else {
        $say('note', $label . ' — not set up yet');
    }
}

// --------------------------------------------------------------- phone line
$trunks = new TrunkRepository();
$trunk = $trunks->get();
$say('section', 'Phone line');
if (!$trunk['connected']) {
    $say('note', 'not connected — calls stay inside the house (Phone line screen to connect one)');
} else {
    $say('ok', $trunk['provider'] . ' — ' . ($trunk['numbers'] !== [] ? implode(', ', $trunk['numbers']) : 'no number yet'));
    if ($trunk['balance'] !== null) {
        $credit = Presenter::money($trunk);
        $trunks->isLowCredit()
            ? $say('warn', 'credit ' . $credit . ' — running low')
            : $say('ok', 'credit ' . $credit);
    }
}

// --------------------------------------------------------------- the day so far
$pdo = Database::pdo();
$today = (int) $pdo->query("SELECT COUNT(*) FROM calls WHERE started_at >= CURDATE()")->fetchColumn();
$unheard = (new VoicemailRepository())->unheardCount();
$queue = (int) $pdo->query("SELECT COUNT(*) FROM calls WHERE transcript_status IN ('pending','running') AND recording_path IS NOT NULL")->fetchColumn();
$say('section', 'Today');
$say('note', $today . ' call' . ($today === 1 ? '' : 's') . ' so far, '
    . $unheard . ' voicemail' . ($unheard === 1 ? '' : 's') . ' not listened to');
if ($queue > 0) {
    $say('note', $queue . ' recording' . ($queue === 1 ? '' : 's') . ' waiting to be transcribed');
}

// ------------------------------------------------------------------ backups
$backups = (new Backup())->list();
$say('section', 'Backups and storage');
if ($backups === []) {
    $say('warn', 'no backups yet — make one with: ./twocans backup');
} else {
    $newest = (int) @filemtime((new Backup())->path() . '/' . $backups[0]['name']);
    $age = time() - $newest;
    $line = 'last one ' . $ago($age) . ' (' . $backups[0]['when'] . '), ' . count($backups) . ' kept';
    $age > 7 * 86400 ? $say('warn', $line . ' — worth making a fresh one') : $say('ok', $line);
}

// ------------------------------------------------------------------ storage
$free = @disk_free_space('/var/lib/twocans');
$total = @disk_total_space('/var/lib/twocans');
if ($free !== false && $total) {
    $gb = round($free / 1073741824, 1);
    $line = $gb . 'GB free for recordings and backups (' . round(100 * $free / $total) . '%)';
    $free < 2 * 1073741824 ? $say('warn', $line . ' — getting full') : $say('ok', $line);
}
