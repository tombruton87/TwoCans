<?php
declare(strict_types=1);

/**
 * Transcript and call-log exports. The design does these client-side with a
 * Blob; server-side is simpler and keeps the transcript text out of the page.
 */

/** @var Store $store */
/** @var string $download */

// Every role may read call logs and voicemail, so authentication is enough here.
if (!Auth::check()) {
    redirect(url());
}

$slug = static fn(string $s): string => strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($s)) ?: 'unknown');

$send = static function (string $filename, string $body, string $type): never {
    header('Content-Type: ' . $type . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
};

switch ($download) {
    case 'call':
        $repo = new CallRepository();
        $row = $repo->find(isset($_GET['id']) ? (int) $_GET['id'] : null);
        $c = $row === null ? null : CallRepository::toView($row);
        if (!$c) {
            http_response_code(404);
            exit('Not found');
        }
        $txt = "twocans — call transcript\n"
             . "========================\n"
             . 'With:      ' . $c['name'] . ' (' . $c['number'] . ")\n"
             . 'Direction: ' . ($c['dir'] === 'in' ? 'Incoming' : 'Outgoing') . "\n"
             . 'When:      ' . $c['date'] . ' ' . $c['time'] . "\n"
             . 'Duration:  ' . $c['dur'] . "\n"
             . 'Status:    ' . $c['status'] . ($c['disposition'] !== '' ? ' (' . $c['disposition'] . ')' : '') . "\n\n"
             . ($c['transcript'] !== '' ? $c['transcript'] : 'No transcript — call recording is not switched on yet.') . "\n";
        $send('call-' . $slug($c['name']) . '-' . $c['id'] . '.txt', $txt, 'text/plain');

        // no break — $send exits

    case 'recording':
        $repo = new CallRepository();
        $row = $repo->find(isset($_GET['id']) ? (int) $_GET['id'] : null);
        $file = $row === null ? null : $repo->recordingFile((string) $row['uniqueid']);

        if ($file === null) {
            http_response_code(404);
            exit('No recording for that call');
        }

        // Range support so the browser's audio player can seek and scrub.
        header('Accept-Ranges: bytes');
        header('Content-Type: audio/wav');
        header('Content-Disposition: inline; filename="call-' . (int) $row['id'] . '.wav"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;

    case 'voicemail_audio':
        $repo = new VoicemailRepository();
        $row = $repo->find(isset($_GET['id']) ? (int) $_GET['id'] : null);
        $file = $row === null ? '' : (string) $row['audio_path'];

        // Only ever serve something inside the spool, whatever the row says.
        if ($row === null || !str_starts_with($file, $repo->spoolPath() . '/') || !is_readable($file)) {
            http_response_code(404);
            exit('No audio for that message');
        }

        header('Accept-Ranges: bytes');
        header('Content-Type: audio/wav');
        header('Content-Disposition: inline; filename="voicemail-' . (int) $row['id'] . '.wav"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;

    case 'ask_audio':
        $repo = new CallRequestRepository();
        $row = $repo->find(isset($_GET['id']) ? (int) $_GET['id'] : null);
        $file = $row === null ? '' : (string) ($row['recording_path'] ?? '');

        // Only ever something inside the ask spool, whatever the row says.
        if ($row === null || $file === '' || !str_starts_with($file, $repo->spoolPath() . '/')
            || !is_readable($file)) {
            http_response_code(404);
            exit('No audio for that ask');
        }

        header('Accept-Ranges: bytes');
        header('Content-Type: audio/wav');
        header('Content-Disposition: inline; filename="ask-' . (int) $row['id'] . '.wav"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;

    case 'joke_audio':
        $row = (new JokeRepository())->find(isset($_GET['id']) ? (int) $_GET['id'] : null);
        // file() validates the name and re-derives the path, so a tampered row
        // still can't point at anything outside the jokes folder.
        $file = $row === null ? null : (new JokeStore())->file((string) $row['audio_file']);

        if ($file === null) {
            http_response_code(404);
            exit('No audio for that joke');
        }

        header('Accept-Ranges: bytes');
        header('Content-Type: audio/wav');
        header('Content-Disposition: inline; filename="joke-' . (int) $row['id'] . '.wav"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;

    case 'voicemail':
        $repo = new VoicemailRepository();
        $found = $repo->find(isset($_GET['id']) ? (int) $_GET['id'] : null);
        $v = $found === null ? null : VoicemailRepository::toView($found);
        if (!$v) {
            http_response_code(404);
            exit('Not found');
        }
        $txt = "twocans — voicemail transcript\n"
             . "==============================\n"
             . 'From:     ' . $v['name'] . ' (' . $v['number'] . ")\n"
             . 'When:     ' . $v['date'] . ' ' . $v['time'] . "\n"
             . 'Length:   ' . $v['dur'] . "\n\n"
             . ($v['transcript'] !== '' ? $v['transcript'] : 'No transcript for this message.') . "\n";
        $send('voicemail-' . $slug($v['name']) . '-' . $v['id'] . '.txt', $txt, 'text/plain');

        // no break — $send exits

    case 'backup':
        if (!Auth::can('backups')) {
            http_response_code(403);
            exit('Not allowed');
        }
        $name = (string) ($_GET['file'] ?? '');
        if ($name === '' || basename($name) !== $name) {
            http_response_code(404);
            exit('Not found');
        }
        $file = (new Backup())->path() . '/' . $name;
        if (!is_file($file)) {
            http_response_code(404);
            exit('No such backup');
        }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;

    case 'calllog':
        $rows = ["Date,Time,Name,Number,Direction,Duration,Status,Transcript"];
        // Export what the screen is currently showing, not the whole log.
        $exportFilters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'contact' => (int) ($_GET['contact'] ?? 0),
            'status' => (string) ($_GET['status'] ?? ''),
        ];
        $matched = (new CallRepository())->search($exportFilters, 1, 5000);
        foreach (array_map([CallRepository::class, 'toView'], $matched) as $c) {
            $rows[] = implode(',', [
                $c['date'],
                $c['time'],
                $c['name'],
                $c['number'],
                $c['dir'] === 'in' ? 'Incoming' : 'Outgoing',
                $c['dur'],
                $c['status'],
                '"' . str_replace(['"', "\n"], ['""', ' '], $c['transcript']) . '"',
            ]);
        }
        $send('twocans-call-log.csv', implode("\n", $rows) . "\n", 'text/csv');

        // no break — $send exits

    default:
        http_response_code(404);
        exit('Not found');
}
