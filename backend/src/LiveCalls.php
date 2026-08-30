<?php
declare(strict_types=1);

/**
 * Calls happening right now, and listening in on them.
 *
 * A parent cannot listen from the browser: ChanSpy is an Asterisk application
 * that runs *on a channel*, so somebody has to be on a phone to hear anything.
 * Listening therefore rings a handset in the house and, when it is answered,
 * attaches it to the call in progress. Doing it in the browser instead would
 * mean WebRTC — a WSS transport, SRTP and a JS SIP stack — which is a much
 * larger piece of work than the feature is worth today.
 */
final class LiveCalls
{
    /**
     * Field order of `core show channels concise`. Documented by Asterisk and
     * stable across versions; parsed rather than the verbose form because this
     * one is machine-readable by design.
     */
    private const FIELDS = [
        'channel', 'context', 'exten', 'priority', 'state', 'application',
        'data', 'callerid', 'accountcode', 'peeraccount', 'amaflags',
        'duration', 'bridgeid', 'uniqueid',
    ];

    /** ChanSpy options per listening mode. */
    private const MODES = [
        // q: no announcement beep. The child should not hear a click.
        'listen' => 'q',
        // w: whisper — only the phone being spied on hears the parent.
        'whisper' => 'qw',
        // B: barge — everyone on the call hears the parent.
        'join' => 'qB',
    ];

    public function __construct(private DeviceRepository $devices = new DeviceRepository())
    {
    }

    /**
     * Calls in progress that involve one of our phones.
     *
     * @return array<int,array>
     */
    public function active(): array
    {
        try {
            $ami = new Ami();
            $ami->connect();
            $lines = $ami->command('core show channels concise');
            $ami->disconnect();
        } catch (Throwable) {
            return [];
        }

        $byUsername = [];
        foreach ($this->devices->all() as $row) {
            $byUsername[(string) $row['sip_username']] = $row;
        }

        $channels = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '!')) {
                continue;
            }

            $parts = explode('!', $line);
            if (count($parts) < count(self::FIELDS)) {
                continue;
            }

            $c = array_combine(self::FIELDS, array_slice($parts, 0, count(self::FIELDS)));
            $c['device'] = $this->deviceForChannel($c['channel'], $byUsername);
            $channels[] = $c;
        }

        return $this->pair($channels);
    }

    /**
     * Turn a flat channel list into calls.
     *
     * A conversation is two channels sharing a bridge. A call that is still
     * ringing, or one being handled by Asterisk itself (voicemail, the echo
     * test), has no bridge partner and stands alone.
     */
    private function pair(array $channels): array
    {
        $calls = [];
        $claimed = [];

        foreach ($channels as $i => $c) {
            if (isset($claimed[$i]) || $c['device'] === null) {
                continue;                       // only calls involving a phone
            }

            $partner = null;
            $bridge = trim((string) $c['bridgeid']);

            if ($bridge !== '') {
                foreach ($channels as $j => $other) {
                    if ($j === $i || isset($claimed[$j])) {
                        continue;
                    }
                    if (trim((string) $other['bridgeid']) === $bridge) {
                        $partner = $other;
                        $claimed[$j] = true;
                        break;
                    }
                }
            }

            $claimed[$i] = true;
            $calls[] = $this->describe($c, $partner);
        }

        return $calls;
    }

    private function describe(array $channel, ?array $partner): array
    {
        $device = $channel['device'];
        $deviceView = DeviceRepository::toView($device);

        // Whoever is on the other end: the bridged channel's caller ID if there
        // is one, otherwise this channel's own.
        $peerNumber = trim((string) ($partner['callerid'] ?? $channel['callerid']));
        $contact = $peerNumber !== '' ? (new ContactRepository())->findByNumber($peerNumber) : null;

        $peerName = $contact !== null
            ? (string) $contact['name']
            : ($peerNumber !== '' ? $peerNumber : 'Unknown');

        // An originated call makes the rung device the caller, so direction is
        // read the same way the call log reads it.
        $outbound = $peerNumber !== '' && $peerNumber === $deviceView['extension'];

        return [
            'channel' => (string) $channel['channel'],
            'uniqueid' => (string) $channel['uniqueid'],
            'deviceId' => $deviceView['id'],
            'deviceName' => $deviceView['name'],
            'devicePhoto' => $deviceView['photo'],
            'peerName' => $peerName,
            'peerNumber' => $peerNumber,
            'peerPhoto' => $contact === null ? '' : (string) ($contact['photo_path'] ?? ''),
            'peerColor' => $contact === null ? '#C9B79E' : (string) $contact['color'],
            'dir' => $outbound ? 'out' : 'in',
            'seconds' => (int) $channel['duration'],
            'startTs' => time() - (int) $channel['duration'],
            'connected' => $partner !== null,
            'application' => (string) $channel['application'],
        ];
    }

    private function deviceForChannel(string $channel, array $byUsername): ?array
    {
        if (!preg_match('#^PJSIP/(.+)-[0-9a-f]{8}$#i', trim($channel), $m)) {
            return null;
        }

        return $byUsername[$m[1]] ?? null;
    }

    public function find(string $channel): ?array
    {
        foreach ($this->active() as $call) {
            if ($call['channel'] === $channel) {
                return $call;
            }
        }

        return null;
    }

    // ------------------------------------------------------------ actions

    /**
     * Ring a handset and attach it to a call in progress.
     *
     * @return array{ok:bool,error:?string}
     */
    public function listen(string $targetChannel, array $listenOn, string $mode): array
    {
        $options = self::MODES[$mode] ?? self::MODES['listen'];
        $device = DeviceRepository::toView($listenOn);

        if (!$device['online']) {
            return ['ok' => false, 'error' => $device['name'] . " isn't online, so it can't be used to listen"];
        }

        try {
            $ami = new Ami();
            $ami->connect();
            $reply = $ami->send('Originate', [
                'Channel' => 'PJSIP/' . $device['sipUsername'],
                'Application' => 'ChanSpy',
                // Spy this exact channel, not a prefix.
                'Data' => $targetChannel . ',' . $options,
                'CallerID' => '"Listening in" <' . PjsipConfig::TEST_CALLER_NUMBER . '>',
                'Timeout' => 30000,
                'Async' => 'true',
            ]);
            $ami->disconnect();

            if (($reply['response'] ?? '') !== 'Success') {
                return ['ok' => false, 'error' => $reply['message'] ?? 'Asterisk refused'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'error' => null];
    }

    /** Hang up a call in progress. */
    public function hangup(string $channel): bool
    {
        try {
            $ami = new Ami();
            $ami->connect();
            $reply = $ami->send('Hangup', ['Channel' => $channel]);
            $ami->disconnect();

            return ($reply['response'] ?? '') === 'Success';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Note that somebody listened.
     *
     * The call record does not exist yet — Asterisk writes its CDR at hangup —
     * so this is parked against the channel's uniqueid and merged in when the
     * log is imported. The UI promises listening is recorded, so it has to
     * survive the gap.
     */
    public function recordListen(string $uniqueid, int $guardianId, string $mode): void
    {
        Database::pdo()->prepare(
            'INSERT INTO listen_events (uniqueid, guardian_id, mode)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), listened_at = NOW()'
        )->execute([$uniqueid, $guardianId, $mode]);
    }

    public static function modeDescription(string $mode): string
    {
        return Presenter::listenModeDescription($mode);
    }
}
