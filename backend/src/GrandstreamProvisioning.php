<?php
declare(strict_types=1);

/**
 * Grandstream provisioning: hand a GHP61x/62x desk phone, or an HT801/HT802
 * adapter, its SIP account(s) as a Grandstream config file (cfg{MAC}.xml).
 *
 * Grandstream phones fetch this file from a "Config Server Path" (set in the
 * phone's web UI, or via DHCP option 66) on boot and on reprovision. Unlike
 * Linphone's expiring QR token, the URL is stable — so it is served with HTTP
 * basic auth (see index.php) rather than a one-time token.
 *
 * The file contains the SIP password in clear, because that is exactly what the
 * phone needs to be told.
 *
 * SIP account P-codes below (P271/P3/P47/P35/P36/P34) are the standard account-1
 * codes shared across Grandstream models. The hotkeys are the GHP62x's six
 * Multi-Purpose Keys, from Grandstream's own template (ghp6xx_config_1.0.1.101).
 *
 * The adapters' codes follow Grandstream's HT80x template: socket 1 uses the
 * account-1 codes, socket 2 of an HT802 its own set (P401, P747, ...).
 */
final class GrandstreamProvisioning
{
    /**
     * A GHP62x's six Multi-Purpose Keys (a GHP61x has the first three), key
     * index => its P-codes: the mode
     * (-1 none, 0 speed dial), which account dials (0 = account 1), the label
     * and the number.
     */
    public const HOTKEY_PCODES = [
        1 => ['mode' => 'P365', 'account' => 'P366', 'label' => 'P367', 'value' => 'P368'],
        2 => ['mode' => 'P369', 'account' => 'P370', 'label' => 'P371', 'value' => 'P372'],
        3 => ['mode' => 'P373', 'account' => 'P374', 'label' => 'P375', 'value' => 'P376'],
        4 => ['mode' => 'P377', 'account' => 'P378', 'label' => 'P379', 'value' => 'P380'],
        5 => ['mode' => 'P381', 'account' => 'P382', 'label' => 'P383', 'value' => 'P384'],
        6 => ['mode' => 'P385', 'account' => 'P386', 'label' => 'P387', 'value' => 'P388'],
    ];

    public static function keyCount(): int
    {
        return count(self::HOTKEY_PCODES);
    }

    /**
     * Tell a Grandstream to fetch its settings file now — or to restart,
     * which fetches it on the way back up — with a SIP NOTIFY (check-sync)
     * through Asterisk. See docker/asterisk/etc/pjsip_notify.conf.
     *
     * @param array $device DeviceRepository::toView() shape
     * @return array{ok:bool,error:?string,pending?:bool}
     */
    public static function notify(array $device, bool $reboot = false): array
    {
        if (($device['family'] ?? 'app') === 'app' || ($device['sipUsername'] ?? '') === '') {
            return ['ok' => false, 'error' => 'Only a Grandstream can be sent its settings.'];
        }
        if (!($device['online'] ?? false)) {
            // Waiting for it: sent the moment it's back — see sendPending().
            if (!$reboot) {
                (new DeviceRepository())->setSettingsPending((int) $device['id'], true);
            }

            return ['ok' => false, 'pending' => true, 'error' => $device['name'] . " is offline — it'll be sent them the moment it's back."];
        }
        try {
            $ami = new Ami();
            $ami->connect();
            $send = static fn(): array => $ami->send('PJSIPNotify', [
                'Endpoint' => (string) $device['sipUsername'],
                'Option' => $reboot ? 'twocans-reboot' : 'twocans-resync',
            ]);
            $reply = $send();
            // Updated without Asterisk restarting, the module that sends these
            // (which needs pjsip_notify.conf, new then) isn't running yet.
            if (str_contains((string) ($reply['message'] ?? ''), 'unknown command')) {
                $ami->send('Command', ['Command' => 'module load res_pjsip_notify.so']);
                $reply = $send();
            }
            $ami->disconnect();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach the phone system: ' . $e->getMessage()];
        }

        return ($reply['response'] ?? '') === 'Success'
            ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => (string) ($reply['message'] ?? 'The phone system could not send it.')];
    }

    /**
     * Send settings to every phone that was offline when they changed and is
     * back now. Run each minute (bin/minute.php), after the registrations are
     * brought up to date. A phone that fetches them clears its own flag.
     *
     * @return array<int,string> the phones sent to, by name
     */
    public static function sendPending(): array
    {
        $sent = [];
        $devices = new DeviceRepository();
        foreach ($devices->all() as $row) {
            $d = DeviceRepository::toView($row);
            if (!$d['settingsPending'] || !$d['online']) {
                continue;
            }
            if (self::notify($d)['ok']) {
                $devices->setSettingsPending($d['id'], false);
                $sent[] = $d['name'];
            }
        }

        return $sent;
    }

    /** Normalise a pasted MAC to 12 uppercase hex chars, or '' when it cannot be one. */
    public static function normalizeMac(string $input): string
    {
        $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $input) ?? '');

        return preg_match('/^[0-9A-F]{12}$/', $mac) ? $mac : '';
    }

    /**
     * Socket => the P-codes for that socket's account on an HT80x.
     */
    public const ATA_PCODES = [
        1 => [
            'active' => 'P271', 'name' => 'P3', 'server' => 'P47', 'proxy' => 'P48',
            'user' => 'P35', 'auth' => 'P36', 'secret' => 'P34', 'transport' => 'P130',
            'localPort' => 'P40', 'expiry' => 'P32',
        ],
        2 => [
            'active' => 'P401', 'name' => 'P703', 'server' => 'P747', 'proxy' => 'P748',
            'user' => 'P735', 'auth' => 'P736', 'secret' => 'P734', 'transport' => 'P830',
            'localPort' => 'P740', 'expiry' => 'P732',
        ],
    ];

    /**
     * Where the phones register: host and the UDP port Asterisk listens on.
     * Grandstream assumes 5060 when the port is left off, which is wrong
     * whenever twocans shares the box with another SIP server.
     */
    public static function server(): string
    {
        return PjsipConfig::domain() . ':' . PjsipConfig::port('udp');
    }

    private static function p(string $code, string $value): string
    {
        return '    <' . $code . '>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $code . ">\n";
    }

    /**
     * The file's head. <config version="1"> is the P-value format this file is
     * written in; version 2 means named settings (<item name="...">), and a
     * phone given P-values under it fetches the file and ignores all of it.
     * The MAC is optional, but Grandstream's own files carry it.
     */
    private static function open(string $mac = ''): string
    {
        $mac = GrandstreamProvisioning::normalizeMac($mac);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . "<gs_provision version=\"1\">\n"
            . ($mac !== '' ? '  <mac>' . strtolower($mac) . "</mac>\n" : '')
            . "  <config version=\"1\">\n";
    }

    private static function close(): string
    {
        return "  </config>\n</gs_provision>\n";
    }

    /**
     * An HT801 or HT802: each socket's account. A socket with no twocans
     * phone is switched off, so an old account left on the box can't
     * register behind twocans' back.
     *
     * @param array<int,array> $bySocket socket (1|2) => DeviceRepository::toView() shape
     */
    public function ataXml(string $type, array $bySocket): string
    {
        $sockets = $type === 'ht802' ? [1, 2] : [1];
        $first = $bySocket[1] ?? $bySocket[2] ?? [];
        $xml = self::open((string) ($first['mac'] ?? ''));

        foreach ($sockets as $socket) {
            $codes = self::ATA_PCODES[$socket];
            $device = $bySocket[$socket] ?? null;
            if ($device === null) {
                $xml .= self::p($codes['active'], '0');
                continue;
            }
            $xml .= self::p($codes['active'], '1');
            $xml .= self::p($codes['name'], $device['name']);
            $xml .= self::p($codes['server'], self::server());
            $xml .= self::p($codes['proxy'], '');
            $xml .= self::p($codes['user'], $device['sipUsername']);
            $xml .= self::p($codes['auth'], $device['sipUsername']);
            $xml .= self::p($codes['secret'], $device['sipSecret']);
            $xml .= self::p($codes['transport'], '0');                // UDP
            $xml .= self::p($codes['localPort'], $socket === 1 ? '5060' : '5062');
            $xml .= self::p($codes['expiry'], '60');                  // minutes
        }

        // How dialling feels on a corded phone: # sends the number straight
        // away, otherwise it goes after 4 seconds without a key press.
        $xml .= self::p('P72', '1');
        $xml .= self::p('P85', '4');

        return $xml . self::close();
    }

    /**
     * A GHP610, 611, 620 or 621 desk phone: one settings file for them all,
     * the GHP61x's three hotkeys being the first three of the GHP62x's six.
     *
     * @param array            $device  DeviceRepository::toView() shape
     * @param array<int,string> $hotkeys key index => number to dial
     * @param array<string,string> $labels number => who it is, for the key's label
     */
    public function xml(array $device, array $hotkeys, array $labels = []): string
    {
        $p = static fn(string $code, string $value): string => trim(self::p($code, $value));

        $xml = self::open((string) ($device['mac'] ?? ''));

        // SIP account 1.
        $xml .= '    ' . $p('P271', '1') . "\n";                          // account active
        $xml .= '    ' . $p('P3', $device['name']) . "\n";                // display name
        $xml .= '    ' . $p('P47', self::server()) . "\n";               // SIP server, with its port
        $xml .= '    ' . $p('P35', $device['sipUsername']) . "\n";        // SIP user ID
        $xml .= '    ' . $p('P36', $device['sipUsername']) . "\n";        // authenticate ID
        $xml .= '    ' . $p('P34', $device['sipSecret']) . "\n";          // authenticate password

        // Announcements: answer by itself when the call asks to, through the
        // Call-Info/Alert-Info headers twocans sends (PjsipConfig's
        // twocans-page context). On by default on the GHP6xx; set anyway so a
        // factory reset or a hand edit can't quietly turn announcements back
        // into ringing. Only that header does it — ordinary calls still ring.
        $xml .= '    ' . $p('P298', '1') . "\n";                         // allow auto answer by Call-Info

        // Pages: listen for twocans' multicast pages (see Pager), and play them
        // through the speaker as they come. A page doesn't cut into a call.
        $xml .= '    ' . $p('P1567', '1') . "\n";                        // paging priority active
        $xml .= '    ' . $p('P1566', '0') . "\n";                        // paging barge: never into a call
        $xml .= '    ' . $p('P8454', '0') . "\n";                        // plain RTP, not Polycom's format
        $xml .= '    ' . $p('P1569', Pager::address((int) $device['id'])) . "\n"; // priority 1 listening address
        $xml .= '    ' . $p('P1570', 'twocans') . "\n";                  // its label

        // Hotkeys: one speed dial per physical key — three on a GHP61x, six on
        // a GHP62x. Every one of its keys is written, so a key cleared here is
        // cleared on the phone too.
        $keys = DeviceRepository::keys((string) ($device['type'] ?? 'ghp621')) ?: count(self::HOTKEY_PCODES);
        foreach (array_slice(self::HOTKEY_PCODES, 0, $keys, true) as $index => $code) {
            $number = (string) ($hotkeys[$index] ?? '');
            $xml .= '    ' . $p($code['mode'], $number === '' ? '-1' : '0') . "\n";
            $xml .= '    ' . $p($code['account'], '0') . "\n";
            $xml .= '    ' . $p($code['label'], $number === '' ? '' : ($labels[$number] ?? $number)) . "\n";
            $xml .= '    ' . $p($code['value'], $number) . "\n";
        }

        return $xml . self::close();
    }
}
