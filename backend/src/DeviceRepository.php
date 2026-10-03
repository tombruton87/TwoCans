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
            'brand' => 'app', 'family' => 'app', 'keys' => 0, 'body' => null, 'faceplate' => null],
        'ht801' => ['label' => 'HT801', 'sub' => 'Grandstream adapter · plug in a corded phone', 'available' => true, 'autoAnswer' => false,
            'brand' => 'grandstream', 'family' => 'adapter', 'keys' => 0, 'body' => null, 'faceplate' => null],
        'ht802' => ['label' => 'HT802', 'sub' => 'Grandstream adapter · two corded phones', 'available' => true, 'autoAnswer' => false,
            'brand' => 'grandstream', 'family' => 'adapter', 'keys' => 0, 'body' => null, 'faceplate' => null],
        'ghp610' => ['label' => 'GHP610', 'sub' => 'Grandstream hotel phone · white · 3 hotkeys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'grandstream', 'family' => 'desk', 'keys' => 3, 'body' => 'white', 'faceplate' => 'strip'],
        'ghp611' => ['label' => 'GHP611', 'sub' => 'Grandstream hotel phone · black · 3 hotkeys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'grandstream', 'family' => 'desk', 'keys' => 3, 'body' => 'black', 'faceplate' => 'strip'],
        'ghp620' => ['label' => 'GHP620', 'sub' => 'Grandstream hotel phone · white · 6 hotkeys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'grandstream', 'family' => 'desk', 'keys' => 6, 'body' => 'white', 'faceplate' => 'card'],
        'ghp621' => ['label' => 'GHP621', 'sub' => 'Grandstream hotel phone · black · 6 hotkeys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'grandstream', 'family' => 'desk', 'keys' => 6, 'body' => 'black', 'faceplate' => 'card'],
        // Poly (Polycom) VVX desk phones: keys are the line keys after its
        // own line's first, each a speed dial (see PolyProvisioning). Its
        // labels are on its screen, so there's no faceplate to print.
        'vvx150' => ['label' => 'VVX 150', 'sub' => 'Poly desk phone · 1 speed-dial key', 'available' => true, 'autoAnswer' => true,
            'brand' => 'poly', 'family' => 'desk', 'keys' => 1, 'body' => null, 'faceplate' => null, 'untested' => true],
        'vvx201' => ['label' => 'VVX 201', 'sub' => 'Poly desk phone · 1 speed-dial key', 'available' => true, 'autoAnswer' => true,
            'brand' => 'poly', 'family' => 'desk', 'keys' => 1, 'body' => null, 'faceplate' => null, 'untested' => true],
        'vvx250' => ['label' => 'VVX 250', 'sub' => 'Poly desk phone · 3 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'poly', 'family' => 'desk', 'keys' => 3, 'body' => null, 'faceplate' => null, 'untested' => true],
        'vvx300' => ['label' => 'VVX 300 / 310', 'sub' => 'Poly desk phone (and 301, 311) · 5 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'poly', 'family' => 'desk', 'keys' => 5, 'body' => null, 'faceplate' => null, 'untested' => true],
        'vvx350' => ['label' => 'VVX 350', 'sub' => 'Poly desk phone · 5 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'poly', 'family' => 'desk', 'keys' => 5, 'body' => null, 'faceplate' => null, 'untested' => true],
        'vvx400' => ['label' => 'VVX 400 / 410', 'sub' => 'Poly desk phone (and 401, 411) · 11 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'poly', 'family' => 'desk', 'keys' => 11, 'body' => null, 'faceplate' => null, 'untested' => true],
        'vvx450' => ['label' => 'VVX 450', 'sub' => 'Poly desk phone · 11 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'poly', 'family' => 'desk', 'keys' => 11, 'body' => null, 'faceplate' => null, 'untested' => true],
        'vvx500' => ['label' => 'VVX 500 / 501', 'sub' => 'Poly touchscreen desk phone · 11 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'poly', 'family' => 'desk', 'keys' => 11, 'body' => null, 'faceplate' => null, 'untested' => true],
        'vvx600' => ['label' => 'VVX 600 / 601', 'sub' => 'Poly touchscreen desk phone · 15 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'poly', 'family' => 'desk', 'keys' => 15, 'body' => null, 'faceplate' => null, 'untested' => true],
        // A Cisco adapter: two corded phones, each a phone of its own here.
        // It can't hear a rotary dial.
        'spa112' => ['label' => 'SPA112', 'sub' => 'Cisco adapter · two corded phones', 'available' => true, 'autoAnswer' => false,
            'brand' => 'cisco', 'family' => 'adapter', 'keys' => 0, 'body' => null, 'faceplate' => null, 'untested' => true],
        // The same, with a router built in: set up the same way.
        'spa122' => ['label' => 'SPA122', 'sub' => 'Cisco adapter · two corded phones, with a router', 'available' => true, 'autoAnswer' => false,
            'brand' => 'cisco', 'family' => 'adapter', 'keys' => 0, 'body' => null, 'faceplate' => null, 'untested' => true],
        // Their successors, on Multiplatform firmware (the "-3PW" models; the
        // Enterprise ones only talk to Cisco's own call manager).
        'ata191' => ['label' => 'ATA 191', 'sub' => 'Cisco adapter · two corded phones · Multiplatform firmware', 'available' => true, 'autoAnswer' => false,
            'brand' => 'cisco', 'family' => 'adapter', 'keys' => 0, 'body' => null, 'faceplate' => null, 'untested' => true],
        'ata192' => ['label' => 'ATA 192', 'sub' => 'Cisco adapter · two corded phones, with a router · Multiplatform firmware', 'available' => true, 'autoAnswer' => false,
            'brand' => 'cisco', 'family' => 'adapter', 'keys' => 0, 'body' => null, 'faceplate' => null, 'untested' => true],
        // A Fanvil adapter: one corded phone. Untested — set up from Fanvil's
        // documentation for its newer phones (see FanvilProvisioning).
        'ga10' => ['label' => 'GA10', 'sub' => 'Fanvil adapter · one corded phone', 'available' => true, 'autoAnswer' => false,
            'brand' => 'fanvil', 'family' => 'adapter', 'keys' => 0, 'body' => null, 'faceplate' => null, 'untested' => true],
        // Yealink cordless phones: a type for each base, each handset on it a
        // phone of its own here, sharing the base's MAC (port: its handset
        // number). On a W60B or W70B (firmware V85) each handset can answer an
        // announcement by itself — account.N.auto_external_intercom; the W52P's
        // older firmware can't, so its handsets are rung like calls instead.
        // (w56h is the W60B's, from when that was the only one.) The W60B has
        // been tried on a real one — firmware 77.85 — so it isn't "untested".
        'w56h' => ['label' => 'W60B', 'sub' => 'Yealink cordless base · W56H handsets, up to 8', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'dect', 'keys' => 0, 'body' => null, 'faceplate' => null],
        'w70b' => ['label' => 'W70B', 'sub' => 'Yealink cordless base · W73H, W56H, W59R… handsets, up to 10', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'dect', 'keys' => 0, 'body' => null, 'faceplate' => null, 'untested' => true],
        'w52p' => ['label' => 'W52P', 'sub' => 'Yealink cordless base · W52H handsets, up to 5', 'available' => true, 'autoAnswer' => false,
            'brand' => 'yealink', 'family' => 'dect', 'keys' => 0, 'body' => null, 'faceplate' => null, 'untested' => true],
        // Yealink desk phones: keys are the line keys after its own line's
        // first, each a speed dial named on its screen (see YealinkProvisioning).
        't31g' => ['label' => 'T31G', 'sub' => 'Yealink desk phone · 1 speed-dial key', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'desk', 'keys' => 1, 'body' => null, 'faceplate' => null, 'untested' => true],
        't33g' => ['label' => 'T33G', 'sub' => 'Yealink desk phone · colour screen · 3 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'desk', 'keys' => 3, 'body' => null, 'faceplate' => null, 'untested' => true],
        't42u' => ['label' => 'T42U / T42S', 'sub' => 'Yealink desk phone · 5 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'desk', 'keys' => 5, 'body' => null, 'faceplate' => null, 'untested' => true],
        't43u' => ['label' => 'T43U', 'sub' => 'Yealink desk phone · 7 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'desk', 'keys' => 7, 'body' => null, 'faceplate' => null, 'untested' => true],
        't44u' => ['label' => 'T44U / T44W', 'sub' => 'Yealink desk phone · colour screen · 7 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'desk', 'keys' => 7, 'body' => null, 'faceplate' => null, 'untested' => true],
        't46u' => ['label' => 'T46U', 'sub' => 'Yealink desk phone · colour screen · 9 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'desk', 'keys' => 9, 'body' => null, 'faceplate' => null, 'untested' => true],
        't48u' => ['label' => 'T48U / T48S', 'sub' => 'Yealink touchscreen desk phone · colour · 9 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'desk', 'keys' => 9, 'body' => null, 'faceplate' => null, 'untested' => true],
        't53w' => ['label' => 'T53W', 'sub' => 'Yealink desk phone · Wi-Fi · 7 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'desk', 'keys' => 7, 'body' => null, 'faceplate' => null, 'untested' => true],
        't54w' => ['label' => 'T54W', 'sub' => 'Yealink desk phone · colour screen, Wi-Fi · 9 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'desk', 'keys' => 9, 'body' => null, 'faceplate' => null, 'untested' => true],
        't57w' => ['label' => 'T57W', 'sub' => 'Yealink touchscreen desk phone · colour, Wi-Fi · 9 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'desk', 'keys' => 9, 'body' => null, 'faceplate' => null, 'untested' => true],
        't58w' => ['label' => 'T58W', 'sub' => 'Yealink Android touchscreen desk phone · colour, Wi-Fi · 8 speed-dial keys', 'available' => true, 'autoAnswer' => true,
            'brand' => 'yealink', 'family' => 'desk', 'keys' => 8, 'body' => null, 'faceplate' => null, 'untested' => true],
    ];

    /**
     * How many phones one box (one MAC) holds, each on its own port: the
     * HT802's two sockets, the W60B's eight handsets; one for the rest.
     */
    public const PORTS = ['ht802' => 2, 'spa112' => 2, 'spa122' => 2, 'ata191' => 2, 'ata192' => 2, 'w56h' => 8, 'w70b' => 10, 'w52p' => 5];

    /**
     * One ring, in seconds: the desk phones' own ring (two seconds on, four
     * off), so "four rings" is what a child in the room would count.
     */
    public const RING_SECONDS = 6;

    /** How many rings a phone may be set to before voicemail — the choices offered. */
    public const RING_CHOICES = [2, 3, 4, 5, 6, 8, 10];

    /** Rings before voicemail when a phone hasn't been set: the old 30 seconds. */
    public const DEFAULT_RINGS = 5;

    /**
     * Who makes it, in the order they're offered when adding a phone: pick
     * one, then its model. An app is only ever Linphone, so it has no models
     * to pick from.
     */
    public const BRANDS = [
        'app' => ['label' => 'An app on a phone or tablet', 'sub' => 'Linphone — free, on any phone or tablet you already have', 'icon' => 'fa-mobile-screen'],
        'grandstream' => ['label' => 'Grandstream', 'sub' => 'GHP desk phones with hotkeys, and HT adapters for any corded phone', 'icon' => 'fa-phone-flip'],
        'poly' => ['label' => 'Poly (Polycom)', 'sub' => 'VVX desk phones — speed-dial keys, announcements through the speaker', 'icon' => 'fa-phone'],
        'cisco' => ['label' => 'Cisco', 'sub' => 'SPA112, SPA122, ATA 191 and ATA 192 adapters for two corded phones — touch-tone only', 'icon' => 'fa-plug'],
        'fanvil' => ['label' => 'Fanvil', 'sub' => 'GA10 adapter for a corded phone', 'icon' => 'fa-plug'],
        'yealink' => ['label' => 'Yealink', 'sub' => 'T-series desk phones, and cordless phones on a W60B, W70B or W52P base', 'icon' => 'fa-mobile-retro'],
    ];

    /** The kinds of phone, with what each is for — each brand's models are grouped by these. */
    public const FAMILIES = [
        'app' => ['label' => 'An app on a phone or tablet', 'sub' => 'Linphone — free, on any phone or tablet you already have', 'icon' => 'fa-mobile-screen'],
        'desk' => ['label' => 'A desk phone', 'sub' => 'Hotkeys or speed-dial keys, and announcements through the speaker', 'icon' => 'fa-phone-flip'],
        'adapter' => ['label' => 'An adapter for a corded phone', 'sub' => 'HT801 or HT802 — plug in any ordinary phone', 'icon' => 'fa-plug'],
        'dect' => ['label' => 'A cordless phone', 'sub' => 'Handsets on a base — each a phone of its own', 'icon' => 'fa-mobile-retro'],
    ];

    /** How many phones of this type one box holds (see PORTS). */
    public static function ports(string $type): int
    {
        return self::PORTS[$type] ?? 1;
    }

    /**
     * Whether twocans hasn't been tried with a real one yet: set up from its
     * maker's documentation (and others' working templates) alone, so it's
     * marked as such wherever it's offered. Take it off once one's confirmed.
     */
    public static function untested(string $type): bool
    {
        return (bool) (self::TYPES[$type]['untested'] ?? false);
    }

    /** Whether this type is a cordless handset, on a base with others. */
    public static function isDect(string $type): bool
    {
        return (self::TYPES[$type]['family'] ?? '') === 'dect';
    }

    /** Whether this type is an adapter for corded phones. */
    public static function isAdapter(string $type): bool
    {
        return (self::TYPES[$type]['family'] ?? '') === 'adapter';
    }

    /** Whether this type is one of the Grandstream desk phones: GHP hotkeys, faceplates, multicast pages. */
    public static function isGrandstreamDesk(string $type): bool
    {
        return self::isDesk($type) && (self::TYPES[$type]['brand'] ?? '') === 'grandstream';
    }

    /** Whether this type is a desk phone, of any make. */
    public static function isDesk(string $type): bool
    {
        return (self::TYPES[$type]['family'] ?? '') === 'desk';
    }

    /** How many hotkeys this type has: 0 for anything without. */
    public static function keys(string $type): int
    {
        return (int) (self::TYPES[$type]['keys'] ?? 0);
    }

    /** The types a brand makes, in order. */
    public static function typesBy(string $brand): array
    {
        return array_filter(self::TYPES, static fn(array $t): bool => $t['brand'] === $brand);
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
     * Give a phone its MAC. For a box with more than one phone on it (an
     * HT802, a W60B base) the others move with it — they are the same box.
     * Returns false when another phone already has that MAC, other than one
     * of the same box on a different port.
     */
    public function setMac(int $id, string $mac): bool
    {
        $mac = GrandstreamProvisioning::normalizeMac($mac);
        $device = $this->find($id);
        if ($mac === '' || $device === null) {
            return false;
        }
        $old = (string) ($device['mac'] ?? '');
        $shared = self::ports((string) $device['type']) > 1;
        $moving = $old !== '' && $shared
            ? array_column($this->findByMac($old), 'id')
            : [$id];

        foreach ($this->findByMac($mac) as $other) {
            // Another port of the same kind of box is the one allowed neighbour.
            $sibling = $shared && $other['type'] === $device['type']
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
            ->execute([max(1, min(self::ports((string) ($this->find($id)['type'] ?? '')), $port)), $id]);
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
            // Only what the old column allows; the label comes from TYPES.
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

    /**
     * Change one of a phone's settings (PhoneSettings); false if it can't be
     * that. A setting the whole box shares — every handset on a W60B base —
     * is changed on each phone on it.
     */
    public function setPhoneSetting(int $id, string $key, mixed $value): bool
    {
        $row = $this->find($id);
        $type = (string) ($row['type'] ?? '');
        if ($row === null || !PhoneSettings::valid($type, $key, $value)) {
            return false;
        }
        $setting = PhoneSettings::catalog($type)[$key];
        $rows = ($setting['shared'] ?? false) && (string) $row['mac'] !== '' ? $this->findByMac((string) $row['mac']) : [$row];
        foreach ($rows as $each) {
            $chosen = self::toView($each)['phoneSettings'];
            // Only what differs from the default is kept, so a new default reaches it.
            if ($value === $setting['default']) {
                unset($chosen[$key]);
            } else {
                $chosen[$key] = $value;
            }
            Database::pdo()->prepare('UPDATE devices SET phone_settings = ? WHERE id = ?')
                ->execute([$chosen === [] ? null : json_encode($chosen), (int) $each['id']]);
        }

        return true;
    }

    /**
     * Pause a phone until $until — or let it go, with null. While paused it
     * doesn't ring and can't call out, and the fun lines are off; emergency
     * numbers and its own messages still work. See PjsipConfig.
     */
    public function pause(int $id, ?int $until): void
    {
        Database::pdo()->prepare('UPDATE devices SET paused_until = ? WHERE id = ?')
            ->execute([$until === null ? null : date('Y-m-d H:i:s', $until), $id]);
    }

    /** Let go of every pause whose time has come. Returns how many. */
    public function endPauses(): int
    {
        return Database::pdo()->exec('UPDATE devices SET paused_until = NULL WHERE paused_until IS NOT NULL AND paused_until <= NOW()') ?: 0;
    }

    /** A desk phone's ring volume, 1 (quiet) to 8 (loud). */
    public function setRingVolume(int $id, int $volume): void
    {
        Database::pdo()->prepare('UPDATE devices SET ring_volume = ? WHERE id = ?')
            ->execute([max(1, min(8, $volume)), $id]);
    }

    /** What a desk phone rings when its handset's picked up and nothing's pressed; '' for nothing. */
    public function setHotline(int $id, string $number, int $delay): void
    {
        Database::pdo()->prepare('UPDATE devices SET hotline_number = ?, hotline_delay = ? WHERE id = ?')
            ->execute([$number === '' ? null : mb_substr($number, 0, 40), max(2, min(10, $delay)), $id]);
    }

    /** Something a desk phone has told twocans: it's started up, or its handset's off or back on the hook. */
    public function phoneEvent(int $id, string $event): void
    {
        $sql = match ($event) {
            'started' => 'UPDATE devices SET started_at = NOW(), offhook_since = NULL, offhook_notified = 0 WHERE id = ?',
            'offhook' => 'UPDATE devices SET offhook_since = COALESCE(offhook_since, NOW()) WHERE id = ?',
            'onhook' => 'UPDATE devices SET offhook_since = NULL, offhook_notified = 0 WHERE id = ?',
            default => null,
        };
        if ($sql !== null) {
            Database::pdo()->prepare($sql)->execute([$id]);
        }
    }

    /** The phone this one walkie-talkies to, or null for none. */
    public function setWalkie(int $id, ?int $to): void
    {
        Database::pdo()->prepare('UPDATE devices SET walkie_device_id = ? WHERE id = ?')
            ->execute([$to === $id ? null : $to, $id]);
    }

    /** A phone's favourite radio station, or null for the menu. */
    public function setRadioStation(int $id, ?int $stationId): void
    {
        Database::pdo()->prepare('UPDATE devices SET radio_station_id = ? WHERE id = ?')->execute([$stationId, $id]);
    }

    public function toggle(int $id, string $field): void
    {
        $columns = ['allowIn' => 'allow_in', 'allowOut' => 'allow_out', 'announceCaller' => 'announce_caller',
            'houseMessages' => 'house_messages', 'roomListen' => 'room_listen', 'canRoomListen' => 'can_room_listen'];
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

        // Nothing walkie-talkies to a phone that's gone.
        Database::pdo()->prepare('UPDATE devices SET walkie_device_id = NULL WHERE walkie_device_id = ?')->execute([$id]);
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

    /** Its wallpaper, or none: the last one's picture goes. */
    public function setWallpaper(int $id, ?string $file): void
    {
        $row = $this->find($id);
        if ($row !== null) {
            (new PhotoStore())->delete((string) ($row['wallpaper_path'] ?? ''));
        }
        Database::pdo()->prepare('UPDATE devices SET wallpaper_path = ? WHERE id = ?')->execute([$file, $id]);
    }

    /** Whether $file is some phone's wallpaper — the only pictures a phone may fetch. */
    public function isWallpaper(string $file): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM devices WHERE wallpaper_path = ?');
        $st->execute([$file]);

        return (bool) $st->fetchColumn();
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
            // An adapter for corded phones: a Grandstream HT80x or a Cisco SPA112.
            'ata' => (self::TYPES[$type]['family'] ?? '') === 'adapter',
            'family' => self::TYPES[$type]['family'] ?? 'app',
            'desk' => self::isDesk($type),
            'dect' => (self::TYPES[$type]['family'] ?? '') === 'dect',
            'brand' => self::TYPES[$type]['brand'] ?? 'app',
            'untested' => self::untested($type),
            'keys' => self::keys($type),
            'body' => self::TYPES[$type]['body'] ?? null,
            'faceplate' => self::TYPES[$type]['faceplate'] ?? null,
            'extension' => (string) ($row['extension'] ?? ''),
            'photo' => (string) ($row['photo_path'] ?? ''),
            'wallpaper' => (string) ($row['wallpaper_path'] ?? ''),
            'sipUsername' => (string) ($row['sip_username'] ?? ''),
            'sipSecret' => (string) ($row['sip_secret'] ?? ''),
            'online' => (bool) $row['online'],
            'registered' => (bool) $row['registered'],
            'available' => self::TYPES[$type]['available'] ?? false,
            // Fetched its settings from home, so it hears multicast pages — see Pager.
            'pagingMulticast' => (bool) ($row['paging_multicast'] ?? false),
            'settingsPending' => (bool) ($row['settings_pending'] ?? false),
            // May hear the house's mailbox, on 701 — see PjsipConfig::HOUSE_MESSAGES_NUMBER.
            'houseMessages' => (bool) ($row['house_messages'] ?? false),
            // Listening to a room: this phone's room may be listened to (it has
            // to answer by itself), and this phone may listen — see migration 058.
            'roomListen' => (bool) ($row['room_listen'] ?? false) && (self::TYPES[$type]['autoAnswer'] ?? false),
            'canRoomListen' => (bool) ($row['can_room_listen'] ?? false),
            // A desk phone's ring volume (1 to 8), and its hotline: the number it
            // rings when its handset's picked up and nothing's pressed — see
            // GrandstreamProvisioning. And what it has told twocans itself.
            'ringVolume' => max(1, min(8, (int) ($row['ring_volume'] ?? 4))),
            // Paused for a while: no calls in or out, no fun lines — see pause().
            'pausedUntil' => ($row['paused_until'] ?? null) !== null && strtotime((string) $row['paused_until']) > time()
                ? (int) strtotime((string) $row['paused_until']) : null,
            // What's been changed on its Phone settings tab — see GrandstreamProvisioning::settingsFor().
            'phoneSettings' => (array) (json_decode((string) ($row['phone_settings'] ?? ''), true) ?: []),
            'hotline' => (string) ($row['hotline_number'] ?? ''),
            'hotlineDelay' => max(2, min(10, (int) ($row['hotline_delay'] ?? 4))),
            'startedAt' => ($row['started_at'] ?? null) === null ? null : self::humanTime((string) $row['started_at']),
            'offhookSince' => ($row['offhook_since'] ?? null) === null ? null : (int) strtotime((string) $row['offhook_since']),
            // The phone it walkie-talkies to — see PjsipConfig::renderWalkie().
            'walkieTo' => ($row['walkie_device_id'] ?? null) === null ? null : (int) $row['walkie_device_id'],
            // Its favourite radio station, played straight away — see Radio.
            'radioStation' => ($row['radio_station_id'] ?? null) === null ? null : (int) $row['radio_station_id'],
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
