<?php
declare(strict_types=1);

/**
 * A phone's diagnostics: one text file someone can download from its page
 * and send to whoever's helping them — on a GitHub issue, say — when it
 * won't come online or a setting won't take. What twocans knows about it,
 * how it's registered, what it's asked twocans for lately, what it's chosen,
 * and the exact settings file it's handed.
 *
 * It's made to leave the house, so before anything's written out it's
 * masked (Masker): passwords, names, phone numbers, email addresses, MAC
 * and IP addresses — each name, number and address a consistent stand-in
 * ("phone-1", "lan-ip-2"), so the file still makes sense.
 */
final class Diagnostics
{
    /** How much of the end of the access log to look through, and how many of its lines to keep. */
    private const LOG_TAIL_BYTES = 6 * 1024 * 1024;
    private const LOG_LINES = 60;

    public function __construct(
        private DeviceRepository $devices = new DeviceRepository(),
        private string $accessLog = '/var/log/nginx/access.log',
    ) {
    }

    /** The file's name: the phone's model and today, nothing personal. */
    public static function fileName(array $device, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable();

        return 'twocans-diagnostics-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $device['model'])) . '-' . $now->format('Y-m-d-Hi') . '.txt';
    }

    /**
     * The diagnostics for one phone, masked.
     *
     * @param array $row a devices row
     */
    public function forDevice(array $row): string
    {
        $d = DeviceRepository::toView($row);
        $masker = Masker::forHousehold($d);
        $version = trim((string) @file_get_contents(__DIR__ . '/../VERSION')) ?: 'unknown';
        $type = DeviceRepository::TYPES[$d['type']] ?? [];
        $siblings = $d['mac'] !== '' ? $this->devices->findByMac($d['mac']) : [$row];

        $out = "twocans diagnostics — " . ($d['model'] ?? 'a phone') . "\n"
            . 'Made ' . date('Y-m-d H:i T') . " by twocans {$version}.\n"
            . "Passwords, names, phone numbers, email, MAC and IP addresses are masked:\n"
            . "each has a stand-in, the same wherever it appears.\n";

        $out .= self::section('The phone');
        $out .= self::row('Kind', ($type['label'] ?? $d['type']) . ' — ' . ($type['sub'] ?? ''));
        $out .= self::row('twocans type', $d['type'] . ' (' . $d['brand'] . ', ' . $d['family'] . ')' . ($d['untested'] ? ', untested' : ''));
        if (DeviceRepository::ports($d['type']) > 1) {
            $out .= self::row(DeviceRepository::isDect($d['type']) ? 'Handset' : 'Socket', $d['port'] . ' of ' . DeviceRepository::ports($d['type'])
                . ' — ' . count($siblings) . ' on this box in twocans');
        }
        $out .= self::row('MAC', $d['mac'] !== '' ? $d['mac'] : '(none given)');
        $out .= self::row('SIP user', $d['sipUsername'] . ' on ' . PjsipConfig::domain() . ':' . PjsipConfig::port('udp') . ' (' . $d['transport'] . ')');
        $out .= self::row('Signed in', $d['online'] ? 'yes' : 'no');
        $out .= self::row('Settings fetched', $d['settingsFetched'] ?? 'never');
        $out .= self::row('Changes waiting', $d['settingsPending'] ? 'yes — it was offline when they changed' : 'no');
        $out .= self::row('Answers announcements', ($type['autoAnswer'] ?? false) ? 'by itself' : 'rings');
        $out .= self::row('Adult mode', $d['adult'] ? 'on' : 'off');
        if ($d['pausedUntil'] !== null) {
            $out .= self::row('Paused until', date('Y-m-d H:i', (int) $d['pausedUntil']));
        }

        $out .= self::section('Its registration, as Asterisk sees it');
        $out .= $this->registration($d['sipUsername']);

        $out .= self::section('What it\'s asked twocans for lately (oldest first)');
        $out .= $this->requests($d['mac'], $this->contactIp($d['sipUsername']));

        $out .= self::section('Its Phone settings (what\'s been changed from the default is marked *)');
        $catalog = PhoneSettings::catalog($d['type']);
        if ($catalog === []) {
            $out .= "(none for this kind of phone)\n";
        }
        foreach (PhoneSettings::for($d) as $key => $value) {
            $default = $catalog[$key]['default'];
            $shown = is_bool($value) ? ($value ? 'on' : 'off') : (string) ($catalog[$key]['choices'][$value] ?? $value);
            $out .= ($value !== $default ? '* ' : '  ') . $key . ' = ' . $shown . "\n";
        }
        if ($d['hotline'] !== '') {
            $out .= '  hotline = ' . $d['hotline'] . ' after ' . $d['hotlineDelay'] . "s\n";
        }

        $out .= self::section('The settings file twocans hands it');
        $out .= $this->settingsFile($row, $siblings);

        $found = Database::pdo()->prepare('SELECT ip, model, firmware, source, seen_at FROM found_phones WHERE mac = ?');
        $found->execute([$d['mac']]);
        $seen = $found->fetch();
        if ($seen) {
            $out .= self::section('Last seen on the network');
            $out .= self::row('From', $seen['source'] . ' at ' . $seen['ip'] . ', ' . $seen['seen_at']);
            $out .= self::row('Said it was', trim($seen['model'] . ' ' . $seen['firmware']) ?: '(didn\'t say)');
        }

        return $masker->mask($out);
    }

    private static function section(string $title): string
    {
        return "\n== {$title}\n";
    }

    private static function row(string $label, string $value): string
    {
        return str_pad($label . ':', 24) . $value . "\n";
    }

    /** Asterisk's view of its sign-in: where from, and as what. */
    private function registration(string $user): string
    {
        if ($user === '') {
            return "(it has no account)\n";
        }
        try {
            $ami = new Ami();
            $ami->connect();
            // The full list: an AOR's own table cuts long addresses short.
            $lines = $ami->command('pjsip show contacts like ' . $user);
            $contacts = [];
            foreach ($lines as $line) {
                if (preg_match('/^\s*Contact:\s+(\S+\/sip:\S+)/', $line, $m)) {
                    $contacts[] = $m[1];
                }
            }
            $detail = [];
            foreach ($contacts as $contact) {
                foreach ($ami->command('pjsip show contact ' . $contact) as $line) {
                    if (preg_match('/^\s*(user_agent|via_addr|status|expiration_time|reg_server|endpoint)\s*:/', $line)) {
                        $detail[] = trim($line);
                    }
                }
            }
            $ami->disconnect();
        } catch (Throwable $e) {
            return '(couldn\'t ask Asterisk: ' . $e->getMessage() . ")\n";
        }
        if ($contacts === []) {
            return "Not signed in: Asterisk has no contact for it.\n";
        }

        return 'Signed in from: ' . implode(', ', array_map(static fn(string $c): string => preg_replace('#^[^/]+/#', '', $c) ?? $c, $contacts)) . "\n"
            . ($detail !== [] ? implode("\n", $detail) . "\n" : '');
    }

    /** The address it last signed in from, to find its requests by. */
    private function contactIp(string $user): ?string
    {
        if ($user === '') {
            return null;
        }
        try {
            $ami = new Ami();
            $ami->connect();
            $lines = $ami->command('pjsip show contacts like ' . $user);
            $ami->disconnect();
        } catch (Throwable) {
            return null;
        }
        foreach ($lines as $line) {
            if (preg_match('/@(\d{1,3}(?:\.\d{1,3}){3}):/', $line, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Its recent requests from the web server's log: any naming its MAC
     * (its file, or its User-Agent), and any from the address it signed in from.
     */
    private function requests(string $mac, ?string $ip): string
    {
        $file = $this->accessLog;
        if (!is_readable($file)) {
            return "(the web server's log isn't readable here)\n";
        }
        $size = (int) filesize($file);
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return "(the web server's log couldn't be opened)\n";
        }
        fseek($fh, max(0, $size - self::LOG_TAIL_BYTES));
        $tail = (string) stream_get_contents($fh);
        fclose($fh);

        $needles = [];
        if ($mac !== '') {
            $lower = strtolower($mac);
            $needles = [$lower, implode(':', str_split($lower, 2)), $mac];
        }
        $kept = [];
        foreach (explode("\n", $tail) as $line) {
            if ($line === '') {
                continue;
            }
            $match = false;
            foreach ($needles as $n) {
                if (str_contains($line, $n)) {
                    $match = true;
                    break;
                }
            }
            if (!$match && $ip !== null && str_starts_with($line, $ip . ' ')) {
                $match = true;
            }
            if ($match) {
                $kept[] = $line;
            }
        }
        if ($kept === []) {
            return "None in the log's recent part" . ($mac === '' ? ' — and it has no MAC to find them by' : '')
                . ". If it's set up, it hasn't asked twocans for anything yet.\n";
        }

        return implode("\n", array_slice($kept, -self::LOG_LINES)) . "\n";
    }

    /** The settings file it's handed — as its kind of phone asks for it — or why there isn't one. */
    private function settingsFile(array $row, array $siblings): string
    {
        $d = DeviceRepository::toView($row);
        $host = PjsipConfig::domain() . ':' . (int) (getenv('HTTP_PORT') ?: 8083);
        $hotkeyRepo = new DeviceHotkeyRepository();
        $views = array_map([DeviceRepository::class, 'toView'], $siblings);
        $byPort = [];
        foreach ($views as $v) {
            $byPort[$v['port']] ??= $v;
        }

        return match (true) {
            $d['type'] === 'linphone' => "(an app: it's set up from its QR code, not a settings file)\n",
            $d['brand'] === 'grandstream' && $d['ata'] => (new GrandstreamProvisioning())->ataXml($d['type'], $byPort),
            $d['brand'] === 'grandstream' => (new GrandstreamProvisioning())->xml($d, $hotkeyRepo->forDevice($d['id']), $hotkeyRepo->labels(), $host),
            $d['brand'] === 'yealink' && YealinkProvisioning::isDesk($d['type'])
                => (new YealinkProvisioning())->desk($d, $hotkeyRepo->forDevice($d['id']), $hotkeyRepo->labels(), $host),
            $d['brand'] === 'yealink' => YealinkProvisioning::boot($d['mac'] !== '' ? strtolower($d['mac']) : null) . "\n"
                . (new YealinkProvisioning())->cfg($byPort, $host),
            $d['brand'] === 'cisco' => (new CiscoProvisioning())->xml($byPort, $host),
            $d['brand'] === 'poly' => PolyProvisioning::master() . "\n" . (new PolyProvisioning())->config($d)
                . "\n(and its speed dials, " . count(array_filter($hotkeyRepo->forDevice($d['id']))) . ", in its directory file)\n",
            $d['brand'] === 'fanvil' => (new FanvilProvisioning())->xml($d),
            default => "(no settings file for this kind of phone)\n",
        };
    }
}
