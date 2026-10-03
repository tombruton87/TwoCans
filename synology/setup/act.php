<?php
// No declare(strict_types=1): it has to be a file's first statement, and run.sh
// puts the request in front of this one.

/**
 * Small things twocans' window in DSM asks of the app, done inside the web
 * container: run.sh puts $req in front of this, as for reset-owner.php.
 *
 *   ['do' => 'hangup', 'channel' => a channel from live.php] — end that call
 *   ['do' => 'retry'] — send failed transcriptions back to speech-to-text
 *   ['do' => 'ring', 'phone' => id] — ring a phone; answered, it says hello
 *   ['do' => 'pause', 'phone' => id or 0 for every one, 'for' => minutes or
 *     'morning'] and ['do' => 'resume', 'phone' => id or 0] — as the app's
 *     Phones screen does (0.1.7 on)
 *
 * Prints JSON: whether it worked, and how many.
 *
 * @var array{do:string,channel?:string} $req
 */

require '/var/www/html/src/bootstrap_cli.php';

$say = static function (array $answer): never {
    echo json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(isset($answer['error']) ? 1 : 0);
};

switch ($req['do'] ?? '') {
    case 'hangup':
        $live = new LiveCalls();
        $channel = (string) ($req['channel'] ?? '');
        // Only a call that's going on now, involving one of the house's phones.
        if ($live->find($channel) === null) {
            $say(['error' => 'That call has already ended.']);
        }
        $live->hangup($channel) ? $say(['ok' => true]) : $say(['error' => 'The phone service didn\'t end the call.']);
        // no break: $say exits
    case 'retry':
        $pdo = Database::pdo();
        $calls = $pdo->exec("UPDATE calls SET transcript_status = 'pending', transcript_attempts = 0, transcript_error = NULL
                              WHERE transcript_status = 'failed' AND recording_path IS NOT NULL") ?: 0;
        $voicemails = $pdo->exec("UPDATE voicemails SET transcript_status = 'pending', transcript_attempts = 0, transcript_error = NULL
                                   WHERE transcript_status = 'failed'") ?: 0;
        $say(['ok' => true, 'count' => $calls + $voicemails]);
        // no break: $say exits
    case 'ring':
        $devices = new DeviceRepository();
        $phone = $devices->find((int) ($req['phone'] ?? 0));
        if ($phone === null) {
            $say(['error' => 'That phone isn\'t there any more.']);
        }
        // Answered, it plays Asterisk's own "hello world" — there in every
        // install — and hangs up.
        $ami = new Ami();
        $ami->connect();
        $reply = $ami->send('Originate', [
            'Channel' => 'PJSIP/' . $phone['sip_username'],
            'Application' => 'Playback',
            'Data' => 'beep&hello-world&beep',
            'CallerID' => '"twocans test" <600>',
            'Timeout' => 30000,
            'Async' => 'true',
        ]);
        $ami->disconnect();
        ($reply['response'] ?? '') === 'Success' ? $say(['ok' => true]) : $say(['error' => 'The phone service didn\'t ring it.']);
        // no break: $say exits
    case 'pause':
    case 'resume':
        $devices = new DeviceRepository();
        if (!method_exists($devices, 'pause')) {
            $say(['error' => 'Pausing phones needs twocans 0.1.7 or later — run setup again once newer images are published.']);
        }
        $id = (int) ($req['phone'] ?? 0);
        $ids = $id > 0 ? [$id] : array_map(static fn(array $d): int => (int) $d['id'], $devices->all());
        $until = null;
        if ($req['do'] === 'pause') {
            $for = (string) ($req['for'] ?? '60');
            if ($for === 'morning') {
                // When bedtime ends next — tomorrow's, if today's has gone — as the Phones screen does.
                [$h, $m] = array_map('intval', explode(':', (new SettingsRepository())->quietTo()));
                $morning = (new DateTimeImmutable('now', new DateTimeZone(PjsipConfig::timezone())))->setTime($h, $m);
                $until = ($morning->getTimestamp() <= time() ? $morning->modify('+1 day') : $morning)->getTimestamp();
            } else {
                $until = time() + 60 * max(1, min(1440, (int) $for));
            }
        }
        foreach ($ids as $one) {
            if ($devices->find($one) !== null) {
                $devices->pause($one, $until);
            }
        }
        (new PjsipConfig($devices))->apply();
        $say(['ok' => true, 'count' => count($ids), 'until' => $until]);
        // no break: $say exits
    default:
        $say(['error' => 'Unknown request.']);
}
