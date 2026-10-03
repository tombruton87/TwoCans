<?php
declare(strict_types=1);

/**
 * The numbers for twocans' window in DSM, as JSON: the phones, the calls,
 * voicemail, the phone line, the last backup.
 *
 * The Synology package runs this inside the web container, from its own copy
 * (run.sh: docker exec -i twocans-web php < stats.php), so it works with
 * whichever app image is there — 0.1.6 onwards. Each part stands alone: one
 * an older app can't answer is left out, and the rest still come.
 */

require '/var/www/html/src/bootstrap_cli.php';

$out = [];
$try = static function (string $key, callable $answer) use (&$out): void {
    try {
        $out[$key] = $answer();
    } catch (Throwable) {
        // Left out; the window shows what it has.
    }
};

$try('phones', static function (): array {
    $devices = new DeviceRepository();
    try {
        (new PjsipConfig($devices))->syncRegistrations();
    } catch (Throwable) {
        // Asterisk not answering: the last known state will do.
    }

    // Each phone as the window shows it — only these fields, never the rest
    // of its record (its SIP password is in there).
    return array_map(static function (array $row): array {
        $d = DeviceRepository::toView($row);

        return [
            'id' => (int) $d['id'],
            'name' => (string) $d['name'],
            'model' => (string) ($d['model'] ?? ''),
            'extension' => (string) ($d['extension'] ?? ''),
            'online' => (bool) ($d['online'] ?? false),
            'registered' => (bool) ($d['registered'] ?? false),
            // When it was last seen, as a time: the window says how long ago, in its language.
            'lastSeenAt' => ($row['last_seen_at'] ?? null) !== null ? (int) strtotime((string) $row['last_seen_at']) : null,
            // Paused (0.1.7 on): when it comes back on, if it's paused now.
            'pausedUntil' => $d['pausedUntil'] ?? null,
        ];
    }, $devices->all());
});

// Whether this app can pause phones — 0.1.7 onwards.
$out['can_pause'] = method_exists(DeviceRepository::class, 'pause');

$pdo = Database::pdo();
$number = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();
$shown = CallRepository::shownSql();

$try('calls', static function () use ($pdo, $number, $shown): array {
    $since = static fn(string $from): int => $number("SELECT COUNT(*) FROM calls WHERE {$shown} AND started_at >= {$from}");

    return [
        'today' => $since('CURDATE()'),
        'week' => $since('CURDATE() - INTERVAL 6 DAY'),
        'month' => $since('CURDATE() - INTERVAL 29 DAY'),
        'total' => $number("SELECT COUNT(*) FROM calls WHERE {$shown}"),
        'answered' => $number("SELECT COUNT(*) FROM calls WHERE {$shown} AND status = 'done'"),
        'missed' => $number("SELECT COUNT(*) FROM calls WHERE {$shown} AND status = 'missed'"),
        'blocked' => $number("SELECT COUNT(*) FROM calls WHERE {$shown} AND status = 'blocked'"),
        'incoming' => $number("SELECT COUNT(*) FROM calls WHERE {$shown} AND direction = 'in'"),
        'outgoing' => $number("SELECT COUNT(*) FROM calls WHERE {$shown} AND direction = 'out'"),
        'minutes' => (int) round($number("SELECT COALESCE(SUM(billsec), 0) FROM calls WHERE {$shown}") / 60),
        'last' => $pdo->query("SELECT MAX(started_at) FROM calls WHERE {$shown}")->fetchColumn() ?: null,
        'transcribing' => $number("SELECT COUNT(*) FROM calls WHERE transcript_status IN ('pending','running') AND recording_path IS NOT NULL"),
    ];
});

// Calls a day, the last 30 days — for the window's chart.
$try('per_day', static function () use ($pdo, $shown): array {
    $st = $pdo->query("SELECT DATE(started_at) AS day, COUNT(*) AS calls,
                              SUM(status = 'missed') AS missed
                         FROM calls WHERE {$shown} AND started_at >= CURDATE() - INTERVAL 29 DAY
                        GROUP BY DATE(started_at)");
    $days = [];
    foreach ($st->fetchAll() as $r) {
        $days[(string) $r['day']] = ['calls' => (int) $r['calls'], 'missed' => (int) $r['missed']];
    }
    $out = [];
    for ($i = 29; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $out[] = ['day' => $day] + ($days[$day] ?? ['calls' => 0, 'missed' => 0]);
    }

    return $out;
});

// The last 20 calls, each with its recording's file name when it has one.
$try('recent', static function () use ($pdo, $shown): array {
    $calls = new CallRepository();
    $rows = $pdo->query("SELECT c.*, d.name AS device_name, ct.name AS contact_name
                           FROM calls c
                           LEFT JOIN devices d ON d.id = c.device_id
                           LEFT JOIN contacts ct ON ct.id = c.contact_id
                          WHERE " . CallRepository::shownSql('c') . "
                          ORDER BY c.started_at DESC, c.id DESC LIMIT 20")->fetchAll();

    return array_map(static function (array $r) use ($calls): array {
        $file = $calls->playableFile($r);

        return [
            'at' => (string) $r['started_at'],
            'phone' => (string) ($r['device_name'] ?? ''),
            'with' => (string) ($r['contact_name'] ?: ($r['peer_name'] ?: $r['peer_number'])),
            'number' => (string) $r['peer_number'],
            'dir' => (string) $r['direction'],
            'status' => (string) $r['status'],
            'seconds' => (int) ($r['billsec'] ?? 0),
            'recording' => $file !== null ? basename($file) : null,
            'transcript' => $r['transcript'] !== null ? mb_substr((string) $r['transcript'], 0, 280) : null,
        ];
    }, $rows);
});

// How far along a new household is — the window's getting-started list.
$try('getting_started', static fn(): array => [
    'account' => $number('SELECT COUNT(*) FROM guardians WHERE password_hash IS NOT NULL') > 0,
    'phone' => $number('SELECT COUNT(*) FROM devices') > 0,
    'online' => $number('SELECT COUNT(*) FROM devices WHERE online = 1') > 0,
    'people' => $number('SELECT COUNT(*) FROM contacts') > 0,
    'line' => (bool) (new TrunkRepository())->get()['connected'],
    'https' => !((new Certificates())->status()['selfSigned'] ?? true),
    'backup' => (new Backup())->list() !== [],
]);

// Recordings speech-to-text gave up on, which the window can send back to it.
$try('transcripts_failed', static fn(): int => $number("SELECT COUNT(*) FROM calls WHERE transcript_status = 'failed' AND recording_path IS NOT NULL")
    + $number("SELECT COUNT(*) FROM voicemails WHERE transcript_status = 'failed'"));

// The same checks as twocans' System screen.
$try('checks', static fn(): array => array_map(static fn(array $c): array => [
    'label' => (string) $c['label'],
    'ok' => (bool) $c['ok'],
    'detail' => (string) ($c['detail'] ?? ''),
], SystemHealth::checks()));

$try('voicemail', static fn(): array => [
    'unheard' => (new VoicemailRepository())->unheardCount(),
    'total' => $number('SELECT COUNT(*) FROM voicemails'),
]);

$try('contacts', static fn(): int => $number('SELECT COUNT(*) FROM contacts'));

$try('line', static function (): array {
    $trunk = (new TrunkRepository())->get();

    return [
        'connected' => (bool) $trunk['connected'],
        'provider' => (string) ($trunk['provider'] ?? ''),
        'numbers' => array_values(array_map('strval', (array) ($trunk['numbers'] ?? []))),
        'credit' => $trunk['connected'] && ($trunk['balance'] ?? null) !== null ? Presenter::money($trunk) : null,
    ];
});

// The certificate twocans serves HTTPS with now.
$try('https', static fn(): array => (new Certificates())->status());

$try('backup', static function (): ?array {
    $backup = new Backup();
    $list = $backup->list();
    if ($list === []) {
        return null;
    }

    return [
        'when' => (string) $list[0]['when'],
        'at' => (int) @filemtime($backup->path() . '/' . $list[0]['name']),
        'kept' => count($list),
    ];
});

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
