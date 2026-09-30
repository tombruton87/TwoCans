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

/*
 * Every recording route below serves the same small WAV files, and a browser
 * fetches media by byte range: without a real 206 reply the <audio> element
 * can start but cannot scrub, and Safari will not play it at all. One helper
 * keeps the six routes honest, rather than six header blocks that each promise
 * 'Accept-Ranges: bytes' and then always read out the whole file.
 */
$play = static function (string $file, string $filename): never {
    $size = (int) filesize($file);
    $start = 0;
    $end = $size - 1;
    $ranged = false;

    // A player asks for one range at a time: "bytes=0-", "bytes=1000-2000", or
    // the suffix form "bytes=-500" for the tail of the recording.
    if (preg_match('/^\s*bytes=(\d*)-(\d*)\s*$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m)
        && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min($end, (int) $m[2]);
            }
        }

        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }

        $ranged = true;
    }

    $length = $end - $start + 1;

    header('Accept-Ranges: bytes');
    header('Content-Type: audio/wav');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . $length);
    if ($ranged) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    // Anything buffered from earlier in the request would be prepended to the
    // audio and stop it being a playable WAV, so drop it before streaming.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $in = fopen($file, 'rb');
    if ($in === false) {
        exit;
    }
    fseek($in, $start);
    for ($left = $length; $left > 0 && !feof($in);) {
        $chunk = fread($in, min(65536, $left));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
    }
    fclose($in);
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
        $file = $row === null ? null : $repo->playableFile($row);

        if ($file === null) {
            http_response_code(404);
            exit('No recording for that call');
        }

        $play($file, 'call-' . (int) $row['id'] . '.wav');

    case 'voicemail_audio':
        $repo = new VoicemailRepository();
        $row = $repo->find(isset($_GET['id']) ? (int) $_GET['id'] : null);
        $file = $row === null ? '' : (string) $row['audio_path'];

        // Only ever serve something inside the spool, whatever the row says.
        if ($row === null || !str_starts_with($file, $repo->spoolPath() . '/') || !is_readable($file)) {
            http_response_code(404);
            exit('No audio for that message');
        }

        $play($file, 'voicemail-' . (int) $row['id'] . '.wav');

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

        $play($file, 'ask-' . (int) $row['id'] . '.wav');

    case 'joke_audio':
        $row = (new JokeRepository())->find(isset($_GET['id']) ? (int) $_GET['id'] : null);
        // file() validates the name and re-derives the path, so a tampered row
        // still can't point at anything outside the jokes folder.
        $file = $row === null ? null : (new JokeStore())->file((string) $row['audio_file']);

        if ($file === null) {
            http_response_code(404);
            exit('No audio for that joke');
        }

        $play($file, 'joke-' . (int) $row['id'] . '.wav');

    case 'refusal_audio':
        $row = (new DeviceRepository())->find(isset($_GET['id']) ? (int) $_GET['id'] : null);
        // file() validates the name and re-derives the path, so a tampered row
        // still can't point at anything outside the messages folder.
        $file = $row === null ? null : (new RefusalStore())->file((string) ($row['refusal_audio'] ?? ''));

        if ($file === null) {
            http_response_code(404);
            exit('No message for that phone');
        }

        $play($file, 'refusal-' . (int) $row['id'] . '.wav');

    case 'quiet_message':
        // The household's bedtime recording. The row is the only thing that
        // names it, so the browser can play back what a caller actually hears
        // without any path being reachable from the query string.
        $file = (new QuietMessageStore())->file((new SettingsRepository())->quietMessage());

        if ($file === null) {
            http_response_code(404);
            exit('No bedtime message');
        }

        $play($file, 'bedtime-message.wav');

    case 'group_prompt':
        // With an id, that group's own greeting; without, the house's. Only the
        // stored name is ever used, so the query string can't reach a path.
        $prompts = new GroupPromptStore();
        if (isset($_GET['id'])) {
            $row = (new ContactRepository())->find((int) $_GET['id']);
            $file = $row === null ? null : $prompts->file((string) ($row['group_prompt'] ?? ''));
        } else {
            $file = $prompts->file((new SettingsRepository())->groupPrompt());
        }

        if ($file === null) {
            http_response_code(404);
            exit('No greeting');
        }

        $play($file, 'group-greeting.wav');

    case 'caller_name':
        // A contact's name spoken, by their row only.
        $row = (new ContactRepository())->find(isset($_GET['id']) ? (int) $_GET['id'] : 0);
        $file = $row === null ? null : (new CallerNameStore())->file((string) ($row['announce_clip'] ?? ''));
        if ($file === null) {
            http_response_code(404);
            exit('No name clip');
        }

        $play($file, 'caller-name.wav');

    case 'hold_music':
        // One of the household's hold tracks, by its row.
        $track = (new HoldMusic())->find(isset($_GET['id']) ? (int) $_GET['id'] : 0);
        $file = $track === null ? null : (new HoldMusicStore())->file($track['file']);
        if ($file === null) {
            http_response_code(404);
            exit('No recording');
        }
        $play($file, 'hold-music-' . $track['id'] . '.wav');

    case 'announcement':
        // An announcement's recording, named by its row only.
        $row = (new AnnouncementRepository())->find(isset($_GET['id']) ? (int) $_GET['id'] : 0);
        $file = $row === null ? null : (new AnnouncementStore())->file($row['audio']);

        if ($file === null) {
            http_response_code(404);
            exit('No recording');
        }

        $play($file, 'announcement-' . $row['id'] . '.wav');

    case 'greeting':
        // One of the re-recorded stock prompts, named by its slot only.
        $slot = (string) ($_GET['slot'] ?? '');
        $file = Greetings::exists($slot)
            ? (new GreetingStore())->file((new Greetings())->file($slot))
            : null;

        if ($file === null) {
            http_response_code(404);
            exit('No greeting');
        }

        $play($file, 'greeting-' . $slot . '.wav');

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
