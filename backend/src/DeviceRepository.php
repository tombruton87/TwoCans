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
        'linphone' => ['label' => 'Linphone', 'sub' => 'Phone or tablet app', 'available' => true],
        'ht801' => ['label' => 'HT801', 'sub' => '1 phone port', 'available' => false],
        'ht802' => ['label' => 'HT802', 'sub' => '2 phone ports', 'available' => false],
        'ghp621' => ['label' => 'GHP621', 'sub' => 'Grandstream hotel phone', 'available' => true],
    ];

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

    public function findByMac(string $mac): ?array
    {
        $mac = GrandstreamProvisioning::normalizeMac($mac);
        if ($mac === '') {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM devices WHERE mac = ?');
        $st->execute([$mac]);

        return $st->fetch() ?: null;
    }

    public function setMac(int $id, string $mac): void
    {
        $mac = GrandstreamProvisioning::normalizeMac($mac);
        if ($mac === '') {
            return;
        }
        Database::pdo()->prepare('UPDATE devices SET mac = ? WHERE id = ?')->execute([$mac, $id]);
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
                 allow_in, allow_out, time_from, time_to, blocked_msg)
             VALUES
                (:name, :type, :transport, :ext, :display, :model,
                 :user, :secret, 0, 0,
                 1, 1, \'15:00:00\', \'19:30:00\', :blocked)'
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
            'blocked' => "Sorry! That number isn't on the call list yet. Ask a grown-up to add it.",
        ]);

        return $this->find((int) $pdo->lastInsertId());
    }

    public function updateField(int $id, string $field, string $value): void
    {
        $columns = [
            'name' => 'name',
            'timeFrom' => 'time_from',
            'timeTo' => 'time_to',
            'blockedMsg' => 'blocked_msg',
        ];
        if (!isset($columns[$field])) {
            return;
        }

        Database::pdo()
            ->prepare('UPDATE devices SET ' . $columns[$field] . ' = ? WHERE id = ?')
            ->execute([$value, $id]);
    }

    public function toggle(int $id, string $field): void
    {
        $columns = ['allowIn' => 'allow_in', 'allowOut' => 'allow_out'];
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
            'extension' => (string) ($row['extension'] ?? ''),
            'photo' => (string) ($row['photo_path'] ?? ''),
            'sipUsername' => (string) ($row['sip_username'] ?? ''),
            'sipSecret' => (string) ($row['sip_secret'] ?? ''),
            'online' => (bool) $row['online'],
            'registered' => (bool) $row['registered'],
            'available' => self::TYPES[$type]['available'] ?? false,
            'lastSeen' => $row['last_seen_at'] === null
                ? 'never'
                : self::humanTime((string) $row['last_seen_at']),
            'allowIn' => (bool) $row['allow_in'],
            'allowOut' => (bool) $row['allow_out'],
            'timeFrom' => substr((string) $row['time_from'], 0, 5),
            'timeTo' => substr((string) $row['time_to'], 0, 5),
            'blockedMsg' => (string) ($row['blocked_msg'] ?? ''),
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
