<?php
declare(strict_types=1);

/**
 * Devices — anything that registers to the line. Softphones (Linphone) work
 * today; the Grandstream ATAs are not wired yet.
 *
 * Lives in the database because Asterisk config is generated from it.
 */
final class DeviceRepository
{
    /**
     * Extensions start here and count up.
     *
     * Deliberately 201, not 101: 101 is the UK police non-emergency number and
     * 111 is NHS urgent care, so an internal extension in the 1xx range would
     * shadow a number a child might genuinely need. 5xx/6xx are the twocans
     * test numbers.
     */
    private const FIRST_EXTENSION = 201;

    /**
     * Secret alphabet with look-alike characters removed. These get typed on a
     * phone keypad by a parent, so 0/O and 1/l/I are more trouble than the few
     * bits of entropy they add. 16 chars of 30 ≈ 78 bits.
     */
    private const SECRET_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const SECRET_LENGTH = 16;

    public const TYPES = [
        // autoAnswer: whether the phone picks an announcement up by itself
        // when asked (Call-Info/Alert-Info). Linphone has no such option — only
        // one that answers every call, which a child's phone must not do.
        //
        // family: how it's added and set up — an app (a QR code), an adapter
        // for a corded phone, or a desk phone. keys: its hotkeys. body: the
        // colour of the phone, which its faceplate preview follows. faceplate:
        // what can be printed for it — see Faceplate.
        'linphone' => ['label' => 'Linphone', 'sub' => 'Phone or tablet app', 'available' => true, 'autoAnswer' => false,
            'family' => 'app', 'keys' => 0, 'body' => null, 'faceplate' => null],
        'ht801' => ['label' => 'HT801', 'sub' => 'Grandstream adapter · plug in a corded phone', 'available' => true, 'autoAnswer' => false,
            'family' => 'adapter', 'keys' => 0, 'body' => null, 'faceplate' => null],
        'ht802' => ['label' => 'HT802', 'sub' => 'Grandstream adapter · two corded phones', 'available' => true, 'autoAnswer' => false,
            'family' => 'adapter', 'keys' => 0, 'body' => null, 'faceplate' => null],
        'ghp610' => ['label' => 'GHP610', 'sub' => 'Grandstream hotel phone · white · 3 hotkeys', 'available' => true, 'autoAnswer' => true,
            'family' => 'desk', 'keys' => 3, 'body' => 'white', 'faceplate' => 'strip'],
        'ghp611' => ['label' => 'GHP611', 'sub' => 'Grandstream hotel phone · black · 3 hotkeys', 'available' => true, 'autoAnswer' => true,
            'family' => 'desk', 'keys' => 3, 'body' => 'black', 'faceplate' => 'strip'],
        'ghp620' => ['label' => 'GHP620', 'sub' => 'Grandstream hotel phone · white · 6 hotkeys', 'available' => true, 'autoAnswer' => true,
            'family' => 'desk', 'keys' => 6, 'body' => 'white', 'faceplate' => 'card'],
        'ghp621' => ['label' => 'GHP621', 'sub' => 'Grandstream hotel phone · black · 6 hotkeys', 'available' => true, 'autoAnswer' => true,
            'family' => 'desk', 'keys' => 6, 'body' => 'black', 'faceplate' => 'card'],
    ];

    /**
     * One ring, in seconds: the desk phones' own ring (two seconds on, four
     * off), so "four rings" is what a child in the room would count.
     */
    public const RING_SECONDS = 6;

    /** How many rings a phone may be set to before voicemail — the choices offered. */
    public const RING_CHOICES = [2, 3, 4, 5, 6, 8, 10];

    /** Rings before voicemail when a phone hasn't been set: the old 30 seconds. */
    public const DEFAULT_RINGS = 5;

    /** The kinds of phone, in the order they're offered, with what each is for. */
    public const FAMILIES = [
        'app' => ['label' => 'An app on a phone or tablet', 'sub' => 'Linphone — free, on any phone or tablet you already have', 'icon' => 'fa-mobile-screen'],
        'desk' => ['label' => 'A Grandstream desk phone', 'sub' => 'GHP610, 611, 620 or 621 — hotkeys, a printable faceplate, pages through the speaker', 'icon' => 'fa-phone-flip'],
        'adapter' => ['label' => 'An adapter for a corded phone', 'sub' => 'HT801 or HT802 — plug in any ordinary phone', 'icon' => 'fa-plug'],
    ];

    /** Whether this type is one of the Grandstream desk phones. */
    public static function isDesk(string $type): bool
    {
        return (self::TYPES[$type]['family'] ?? '') === 'desk';
    }

    /** How many hotkeys this type has: 0 for anything without. */
    public static function keys(string $type): int
    {
        return (int) (self::TYPES[$type]['keys'] ?? 0);
    }

    /** The types in one family, in order. */
    public static function typesIn(string $family): array
    {
        return array_filter(self::TYPES, static fn(array $t): bool => $t['family'] === $family);
    }

    public const TRANSPORTS = [
        'udp' => ['label' => 'UDP', 'sub' => 'Simplest — start here', 'available' => true],
        'tcp' => ['label' => 'TCP', 'sub' => 'Steadier on flaky wifi', 'available' => true],
        'tls' => ['label' => 'TLS', 'sub' => 'Encrypted — needs a certificate', 'available' => false],
    ];

    public function all(): array
    {
        return Database::pdo()->query('SELECT * FROM devices ORDER BY extension, id')->fetchAll();
    }

    public function find(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM devices WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    /**
     * Every phone behind one MAC, socket 1 first. One for a desk phone or an
     * HT801; up to two for an HT802, whose sockets share the box's MAC.
     *
     * @return array<int,array>
     */
    public function findByMac(string $mac): array
    {
        $mac = GrandstreamProvisioning::normalizeMac($mac);
        if ($mac === '') {
            return [];
        }
        $st = Database::pdo()->prepare('SELECT * FROM devices WHERE mac = ? ORDER BY port, id');
        $st->execute([$mac]);

        return $st->fetchAll();
    }

    /**
     * Give a phone its MAC. For an HT802 socket the other socket moves with
     * it — they are the same box. Returns false when another phone already
     * has that MAC.
     */
    public function setMac(int $id, string $mac): bool
    {
        $mac = GrandstreamProvisioning::normalizeMac($mac);
        $device = $this->find($id);
        if ($mac === '' || $device === null) {
            return false;
        }
        $old = (string) ($device['mac'] ?? '');
        $moving = $old !== '' && $device['type'] === 'ht802'
            ? array_column($this->findByMac($old), 'id')
            : [$id];

        foreach ($this->findByMac($mac) as $other) {
            // The other socket of the same HT802 is the one allowed neighbour.
            $sibling = $device['type'] === 'ht802' && $other['type'] === 'ht802'
                && (int) $other['port'] !== (int) $device['port'];
            if (!in_array($other['id'], $moving, false) && !$sibling) {
                return false;
            }
        }

        $st = Database::pdo()->prepare('UPDATE devices SET mac = ? WHERE id = ?');
        foreach ($moving as $each) {
            $st->execute([$mac, (int) $each]);
        }

        return true;
    }

    /** Which socket of its adapter this phone is (HT802 only has a 2). */
    /** How many rings before voicemail; null for the default. */
    public function setRings(int $id, ?int $rings): void
    {
        $seconds = $rings !== null && in_array($rings, self::RING_CHOICES, true) ? $rings * self::RING_SECONDS : null;
        Database::pdo()->prepare('UPDATE devices SET ring_seconds = ? WHERE id = ?')->execute([$seconds, $id]);
    }

    /** Settings changed while it was offline, or not: see GrandstreamProvisioning::sendPending. */
    public function setSettingsPending(int $id, bool $pending): void
    {
        Database::pdo()->prepare('UPDATE devices SET settings_pending = ? WHERE id = ?')->execute([$pending ? 1 : 0, $id]);
    }

    /** It has just fetched its settings file — so nothing is waiting for it any more. */
    public function touchSettingsFetched(int $id): void
    {
        // PHP's clock, as humanTime() reads it with, not the database's.
        Database::pdo()->prepare('UPDATE devices SET settings_fetched_at = ?, settings_pending = 0 WHERE id = ?')->execute([date('Y-m-d H:i:s'), $id]);
    }

    /** Whether a desk phone can hear multicast pages — see Pager. */
    public function setPagingMulticast(int $id, bool $on): void
    {
        Database::pdo()->prepare('UPDATE devices SET paging_multicast = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    }

    public function setPort(int $id, int $port): void
    {
        Database::pdo()->prepare('UPDATE devices SET port = ? WHERE id = ?')
            ->execute([max(1, min(2, $port)), $id]);
    }

    /**
     * Put a recording on this phone as the message a refused caller hears.
     *
     * Marked pending so the transcription worker picks it up: the audio is
     * already on disk by the time this is called — RefusalStore put it there —
     * so this is only the bookkeeping. Attempts start again from zero, because
     * a fresh clip deserves its full share of them.
     */
    public function setRefusalAudio(int $id, string $file, int $seconds): void
    {
        Database::pdo()
            ->prepare(
                "UPDATE devices
                    SET refusal_audio = ?, refusal_seconds = ?,
                        refusal_status = 'pending', refusal_attempts = 0,
                        refusal_error = NULL
                  WHERE id = ?"
            )
            ->execute([$file, $seconds, $id]);
    }

    /**
     * Take the recording off the line. The transcript stays: it is the wording
     * the household settled on, and it is what they will read out next time.
     */
    public function clearRefusalAudio(int $id): void
    {
        Database::pdo()
            ->prepare(
                "UPDATE devices
                    SET refusal_audio = NULL, refusal_seconds = 0,
                        refusal_status = 'skipped', refusal_attempts = 0,
                        refusal_error = NULL
                  WHERE id = ?"
            )
            ->execute([$id]);
    }

    public function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM devices')->fetchColumn();
    }

    /**
     * Create a device and its SIP credentials.
     *
     * The secret is stored recoverable, not hashed: Asterisk has to present it
     * when answering a digest challenge, and the parent needs to read it back
     * to type into the app. It is a per-device credential for a LAN service,
     * not a user password.
     */
    public function create(string $name, string $type, string $transport): array
    {
        $type = isset(self::TYPES[$type]) ? $type : 'linphone';
        $transport = isset(self::TRANSPORTS[$transport]) ? $transport : 'udp';
        $name = trim($name) !== '' ? trim($name) : 'New phone';

        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT INTO devices
                (name, type, transport, extension, display_name, model,
                 sip_username, sip_secret, online, registered,
                 allow_in, allow_out, announce_caller, time_from, time_to)
             VALUES
                (:name, :type, :transport, :ext, :display, :model,
                 :user, :secret, 0, 0,
                 1, 1, :announce, \'15:00:00\', \'19:30:00\')'
        );

        $extension = $this->nextExtension();
        $username = $this->uniqueUsername($name);

        $st->execute([
            'name' => $name,
            'type' => $type,
            'transport' => $transport,
            'ext' => $extension,
            'display' => $name,
            'model' => in_array($type, ['ht801', 'ht802'], true) ? strtoupper($type) : null,
            'user' => $username,
            'secret' => $this->generateSecret(),
            // A phone with no screen says who's calling; the app shows them.
            'announce' => $type === 'linphone' ? 0 : 1,
        ]);

        return $this->find((int) $pdo->lastInsertId());
    }

    public function updateField(int $id, string $field, string $value): void
    {
        $columns = [
            'name' => 'name',
            'timeFrom' => 'time_from',
            'timeTo' => 'time_to',
            'refusalTranscript' => 'refusal_transcript',
        ];
        if (!isset($columns[$field])) {
            return;
        }

        Database::pdo()
            ->prepare('UPDATE devices SET ' . $columns[$field] . ' = ? WHERE id = ?')
            ->execute([$value, $id]);
    }

    /** Adult mode on or off — see migration 041. */
    public function setAdult(int $id, bool $on): void
    {
        Database::pdo()->prepare('UPDATE devices SET adult_mode = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    }

    /** Save a phone's hours as a schedule — see Schedule. */
    public function setHours(int $id, array $rules): void
    {
        Database::pdo()
            ->prepare('UPDATE devices SET hours_schedule = ? WHERE id = ?')
            ->execute([Schedule::toJson($rules), $id]);
    }

    /** Save a phone's call limits, in minutes; null is no limit. */
    public function setLimits(int $id, ?int $maxCall, ?int $daily): void
    {
        Database::pdo()
            ->prepare('UPDATE devices SET max_call_minutes = ?, daily_minutes = ? WHERE id = ?')
            ->execute([$maxCall, $daily, $id]);
    }

    /**
     * Minutes this phone has spent talking today, from the call log — what
     * the phone's page shows against its daily limit. Asterisk keeps its own
     * running count to enforce the limit mid-call; this is the same calls, so
     * the two agree once the log has caught up.
     */
    public function minutesToday(int $id): int
    {
        $st = Database::pdo()->prepare(
            "SELECT COALESCE(SUM(billsec), 0) FROM calls
              WHERE device_id = ? AND status = 'done' AND started_at >= CURDATE()"
        );
        $st->execute([$id]);

        return intdiv((int) $st->fetchColumn() + 59, 60);
    }

    /** Set a phone's incoming or outgoing switch to on or off (Home Assistant). */
    public function setAllow(int $id, string $field, bool $on): void
    {
        $columns = ['allowIn' => 'allow_in', 'allowOut' => 'allow_out', 'announceCaller' => 'announce_caller'];
        if (!isset($columns[$field])) {
            return;
        }
        Database::pdo()
            ->prepare("UPDATE devices SET {$columns[$field]} = ? WHERE id = ?")
            ->execute([$on ? 1 : 0, $id]);
    }

    public function toggle(int $id, string $field): void
    {
        $columns = ['allowIn' => 'allow_in', 'allowOut' => 'allow_out', 'announceCaller' => 'announce_caller'];
        if (!isset($columns[$field])) {
            return;
        }

        $column = $columns[$field];
        Database::pdo()
            ->prepare("UPDATE devices SET {$column} = NOT {$column} WHERE id = ?")
            ->execute([$id]);
    }

    public function remove(int $id): void
    {
        $row = $this->find($id);
        if ($row !== null) {
            (new PhotoStore())->delete((string) ($row['photo_path'] ?? ''));
        }

        Database::pdo()->prepare('DELETE FROM devices WHERE id = ?')->execute([$id]);
    }

    /** Replace this phone's picture, discarding whatever it had. */
    public function setPhoto(int $id, ?string $file): void
    {
        $row = $this->find($id);
        if ($row !== null) {
            (new PhotoStore())->delete((string) ($row['photo_path'] ?? ''));
        }

        Database::pdo()->prepare('UPDATE devices SET photo_path = ? WHERE id = ?')
            ->execute([$file, $id]);
    }

    /** Record what Asterisk reports, so the UI shows real registration state. */
    public function syncRegistration(array $onlineByUsername): void
    {
        $pdo = Database::pdo();

        /*
         * `online` is current reachability and flips freely. `registered` is
         * sticky — it records that this device has signed in at least once, so
         * the UI can tell "never set up" apart from "set up but offline right
         * now". Clearing it on every disconnect would lose that distinction.
         */
        $st = $pdo->prepare(
            'UPDATE devices
                SET online = :online,
                    registered = CASE WHEN :seen = 1 THEN 1 ELSE registered END,
                    last_seen_at = CASE WHEN :seen2 = 1 THEN NOW() ELSE last_seen_at END
              WHERE sip_username = :user'
        );

        $cameBack = [];
        foreach ($this->all() as $device) {
            $username = (string) $device['sip_username'];
            $isOnline = $onlineByUsername[$username] ?? false;
            $hasContact = array_key_exists($username, $onlineByUsername);

            $st->execute([
                'online' => $isOnline ? 1 : 0,
                'seen' => $hasContact ? 1 : 0,
                'seen2' => $isOnline ? 1 : 0,
                'user' => $username,
            ]);

            if ($isOnline && !(bool) $device['online'] && (bool) ($device['settings_pending'] ?? false)) {
                $cameBack[] = (int) $device['id'];
            }
        }

        // Back online with settings waiting for it: send them now, rather than
        // at the next minute's check.
        if ($cameBack !== []) {
            GrandstreamProvisioning::sendPending();
        }
    }

    /** Map a database row to the shape the views expect. */
    public static function toView(array $row): array
    {
        $type = (string) $row['type'];

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'type' => $type,
            'model' => self::TYPES[$type]['label'] ?? (string) ($row['model'] ?? 'Phone'),
            'transport' => (string) $row['transport'],
            'mac' => (string) ($row['mac'] ?? ''),
            'port' => (int) ($row['port'] ?? 1),
            'ata' => in_array($type, ['ht801', 'ht802'], true),
            'family' => self::TYPES[$type]['family'] ?? 'app',
            'desk' => self::isDesk($type),
            'keys' => self::keys($type),
            'body' => self::TYPES[$type]['body'] ?? null,
            'faceplate' => self::TYPES[$type]['faceplate'] ?? null,
            'extension' => (string) ($row['extension'] ?? ''),
            'photo' => (string) ($row['photo_path'] ?? ''),
            'sipUsername' => (string) ($row['sip_username'] ?? ''),
            'sipSecret' => (string) ($row['sip_secret'] ?? ''),
            'online' => (bool) $row['online'],
            'registered' => (bool) $row['registered'],
            'available' => self::TYPES[$type]['available'] ?? false,
            // Fetched its settings from home, so it hears multicast pages — see Pager.
            'pagingMulticast' => (bool) ($row['paging_multicast'] ?? false),
            'settingsPending' => (bool) ($row['settings_pending'] ?? false),
            // How long it rings before voicemail: seconds, and the rings that is.
            'ringSeconds' => (int) ($row['ring_seconds'] ?? 0) ?: self::DEFAULT_RINGS * self::RING_SECONDS,
            'rings' => intdiv((int) ($row['ring_seconds'] ?? 0) ?: self::DEFAULT_RINGS * self::RING_SECONDS, self::RING_SECONDS),
            // Its own choice of the line's numbers to call out from; '' is automatic.
            'outgoingNumber' => (string) ($row['outgoing_number'] ?? ''),
            'settingsFetched' => ($row['settings_fetched_at'] ?? null) === null
                ? null
                : self::humanTime((string) $row['settings_fetched_at']),
            'autoAnswer' => self::TYPES[$type]['autoAnswer'] ?? false,
            'lastSeen' => $row['last_seen_at'] === null
                ? 'never'
                : self::humanTime((string) $row['last_seen_at']),
            'allowIn' => (bool) $row['allow_in'],
            'allowOut' => (bool) $row['allow_out'],
            'announceCaller' => (bool) ($row['announce_caller'] ?? false),
            // No restrictions at all — see migration 041.
            'adult' => (bool) ($row['adult_mode'] ?? false),
            'timeFrom' => substr((string) $row['time_from'], 0, 5),
            'timeTo' => substr((string) $row['time_to'], 0, 5),
            // The phone's hours by day — see Schedule and migration 035. With
            // no schedule saved, the old pair above every day.
            'hours' => Schedule::fromJson(
                $row['hours_schedule'] ?? null,
                substr((string) $row['time_from'], 0, 5),
                substr((string) $row['time_to'], 0, 5)
            ),
            // Call limits in minutes; null is no limit.
            'maxCallMinutes' => isset($row['max_call_minutes']) ? (int) $row['max_call_minutes'] : null,
            'dailyMinutes' => isset($row['daily_minutes']) ? (int) $row['daily_minutes'] : null,
            'refusalAudio' => (string) ($row['refusal_audio'] ?? ''),
            'refusalTranscript' => (string) ($row['refusal_transcript'] ?? ''),
            'refusalStatus' => (string) ($row['refusal_status'] ?? 'skipped'),
            'refusalSeconds' => (int) ($row['refusal_seconds'] ?? 0),
        ];
    }

    private static function humanTime(string $timestamp): string
    {
        $seconds = time() - strtotime($timestamp);
        if ($seconds < 90) {
            return 'just now';
        }

        $ago = static fn(int $n, string $unit): string
            => $n . ' ' . $unit . ($n === 1 ? '' : 's') . ' ago';

        if ($seconds < 3600) {
            return $ago(intdiv($seconds, 60), 'minute');
        }
        if ($seconds < 86400) {
            return $ago(intdiv($seconds, 3600), 'hour');
        }

        return $ago(intdiv($seconds, 86400), 'day');
    }

    private function nextExtension(): string
    {
        $used = Database::pdo()
            ->query('SELECT extension FROM devices WHERE extension IS NOT NULL')
            ->fetchAll(PDO::FETCH_COLUMN);

        // Fetched once: the joke line's number is a setting, so this is a
        // query rather than a constant now.
        $service = PjsipConfig::testNumbers();

        $candidate = self::FIRST_EXTENSION;
        while (in_array((string) $candidate, $used, true)
               || isset($service[(string) $candidate])
               || in_array((string) $candidate, ContactRepository::RESERVED_NUMBERS, true)) {
            $candidate++;
        }

        return (string) $candidate;
    }

    /** A readable SIP username derived from the device name, plus a suffix. */
    private function uniqueUsername(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'phone');
        $slug = trim($slug, '-');
        $slug = $slug === '' ? 'phone' : substr($slug, 0, 24);

        do {
            $candidate = $slug . '-' . bin2hex(random_bytes(2));
            $st = Database::pdo()->prepare('SELECT 1 FROM devices WHERE sip_username = ?');
            $st->execute([$candidate]);
        } while ($st->fetchColumn() !== false);

        return $candidate;
    }

    private function generateSecret(): string
    {
        $alphabet = self::SECRET_ALPHABET;
        $max = strlen($alphabet) - 1;
        $secret = '';
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= $alphabet[random_int(0, $max)];
        }

        return $secret;
    }
}
