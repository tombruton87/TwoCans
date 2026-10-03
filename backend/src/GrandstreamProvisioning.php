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

    /**
     * The settings a desk phone can have changed, on its Phone settings tab:
     * key => what it's called, a line about it, its group, and its default —
     * what every phone gets until somebody changes it. A choice's choices are
     * value => label. See xml() for the P-codes each one sets.
     */
    public const PHONE_SETTINGS = [
        'ring_lock' => ['group' => 'Ringing', 'label' => "Lock how loud it rings",
            'hint' => "So it can't be turned down to nothing on the phone.", 'default' => true],
        'idle_light' => ['group' => 'Lights & sounds', 'label' => 'Light on when idle',
            'hint' => 'Off, so a phone in a bedroom isn\'t a night-light.', 'default' => false],
        'message_light' => ['group' => 'Lights & sounds', 'label' => 'Light for a new message',
            'hint' => 'Steady is calmer than blinking at night.', 'default' => 'steady',
            'choices' => ['steady' => 'Steady', 'blink' => 'Blinking', 'off' => 'Off']],
        'boot_beep' => ['group' => 'Lights & sounds', 'label' => 'Beep when it starts up',
            'hint' => 'Off, so a power cut at night doesn\'t wake anyone.', 'default' => false],
        'call_waiting' => ['group' => 'Calls', 'label' => 'Call waiting',
            'hint' => 'Off: a second caller goes to voicemail instead of beeping in a child\'s ear.', 'default' => false],
        'mute_dnd' => ['group' => 'Calls', 'label' => 'Mute button can turn on Do Not Disturb',
            'hint' => 'Off, so a child can\'t silence the phone without anyone knowing.', 'default' => false],
        'offhook_timeout' => ['group' => 'Calls', 'label' => 'Left off the hook',
            'hint' => 'How long before it plays its loud “put me back” tone.', 'default' => 30,
            'choices' => [10 => 'After 10 seconds', 20 => 'After 20 seconds', 30 => 'After 30 seconds',
                45 => 'After 45 seconds', 60 => 'After a minute']],
        'check_user_id' => ['group' => 'Safety', 'label' => 'Refuse calls not meant for it',
            'hint' => 'Stops internet "SIP scanners" ringing it with ghost calls.', 'default' => true],
        'direct_ip' => ['group' => 'Safety', 'label' => 'Calls straight to its IP address',
            'hint' => 'Off: every call goes through twocans and its rules.', 'default' => false],
        'ssh' => ['group' => 'Safety', 'label' => 'SSH',
            'hint' => 'Nothing in twocans needs it.', 'default' => false],
        'keypad_menu' => ['group' => 'Safety', 'label' => 'Settings from the keypad\'s voice menu',
            'hint' => 'Off, so key-mashing can\'t change its settings. It still reads out its IP address.', 'default' => false],
        'only_twocans' => ['group' => 'Looking after it', 'label' => 'Settings only from twocans',
            'hint' => 'A router or another phone system can\'t take it over.', 'default' => true],
        'firmware_checks' => ['group' => 'Looking after it', 'label' => 'Check Grandstream for new firmware',
            'hint' => 'Off, so it stays on the version that\'s known to work.', 'default' => false],
        'unregister_on_reboot' => ['group' => 'Looking after it', 'label' => 'Drop its old registration when it restarts',
            'hint' => 'So calls aren\'t sent to where it used to be.', 'default' => true],
        'report_status' => ['group' => 'Looking after it', 'label' => 'Tell twocans how it is',
            'hint' => 'When it starts up, and when its handset is left off the hook — shown at the top of this page.', 'default' => true],
    ];

    /** A desk phone's settings: see PhoneSettings::for(). */
    public static function settingsFor(array $device): array
    {
        return PhoneSettings::for(['type' => 'ghp621'] + $device);
    }

    /** Whether $value is one a desk phone's setting can have. */
    public static function validSetting(string $key, mixed $value): bool
    {
        return PhoneSettings::valid('ghp621', $key, $value);
    }

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
            return ['ok' => false, 'error' => 'Only a phone twocans sets up can be sent its settings.'];
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
            // A Cisco wants "resync" or "reboot"; the rest, "check-sync".
            $cisco = ($device['brand'] ?? '') === 'cisco';
            $send = static fn(): array => $ami->send('PJSIPNotify', [
                'Endpoint' => (string) $device['sipUsername'],
                'Option' => ($cisco ? 'twocans-cisco-' : 'twocans-') . ($reboot ? 'reboot' : 'resync'),
            ]);
            $reply = $send();
            // Updated without Asterisk restarting, the module that sends these
            // (which needs pjsip_notify.conf, new then) isn't running yet — or
            // is, without a kind of NOTIFY added since.
            $message = strtolower((string) ($reply['message'] ?? ''));
            if (str_contains($message, 'unknown command')) {
                $ami->send('Command', ['Command' => 'module load res_pjsip_notify.so']);
                $reply = $send();
            } elseif (str_contains($message, 'unable to find notify type')) {
                $ami->send('Command', ['Command' => 'module reload res_pjsip_notify.so']);
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
            // How the socket behaves: see ATA_SETTINGS and ataXml().
            'callWaiting' => 'P91', 'rxGain' => 'P249', 'txGain' => 'P247', 'callFeatures' => 'P191',
            'hookFlash' => 'P4424', 'offhookTone' => 'P4793', 'visualMwi' => 'P855', 'callerId' => 'P853',
            'slic' => 'P854', 'ringFrequency' => 'P4429', 'hotline' => 'P71', 'hotlineDelay' => 'P4045',
            'unregister' => 'P81', 'checkUserId' => 'P258',
            'hashSends' => 'P72', 'dialWait' => 'P85', 'pulse' => 'P20521', 'pulseStandard' => 'P28165',
        ],
        2 => [
            'active' => 'P401', 'name' => 'P703', 'server' => 'P747', 'proxy' => 'P748',
            'user' => 'P735', 'auth' => 'P736', 'secret' => 'P734', 'transport' => 'P830',
            'localPort' => 'P740', 'expiry' => 'P732',
            'callWaiting' => 'P791', 'rxGain' => 'P283', 'txGain' => 'P248', 'callFeatures' => 'P751',
            'hookFlash' => 'P4425', 'offhookTone' => 'P4794', 'visualMwi' => 'P869', 'callerId' => 'P863',
            'slic' => 'P864', 'ringFrequency' => 'P4430', 'hotline' => 'P771', 'hotlineDelay' => 'P4046',
            'unregister' => 'P752', 'checkUserId' => 'P449',
            'hashSends' => 'P772', 'dialWait' => 'P292', 'pulse' => 'P20522', 'pulseStandard' => 'P28166',
        ],
    ];

    /** An HT80x's gain choices: its own codes, louder to quieter (the same on both firmwares). */
    private const GAINS = ['factory' => 'As the adapter comes', 1 => 'Louder (+6 dB)', 2 => '+4 dB', 3 => 'A little louder (+2 dB)',
        0 => 'Normal (0 dB)', 4 => 'A little quieter (−2 dB)', 5 => '−4 dB', 6 => 'Quieter (−6 dB)'];

    /**
     * What an HT801 or HT802 can have changed, for the corded phone in each
     * socket — see PhoneSettings for the shape. 'shared': the adapter's own,
     * the same for both of an HT802's sockets. P-codes are from Grandstream's
     * HT80x templates (1.0.65 and the V2's 1.0.15), which agree on them: where
     * one says "Disable X, 1 is Yes" the other says "Enable X, 1 is No".
     */
    public const ATA_SETTINGS = [
        'rotary' => ['group' => 'Dialling', 'label' => 'It\'s a rotary phone',
            'hint' => 'Hears the dial\'s clicks as numbers (touch-tone still works too). A dial can\'t press keys once a call has '
                . 'started, so the games, the quiz and choosing a radio station won\'t hear it — the joke line, the time and '
                . '"Pick up to ring a grown-up" are perfect for one.', 'default' => false],
        'dial_wait' => ['group' => 'Dialling', 'label' => 'How long it waits after the last number',
            'hint' => 'Then it rings. A dial has no # to say "that\'s all", so a rotary phone waits longer, for small fingers.',
            'default' => 'auto',
            'choices' => ['auto' => '4 seconds, or 7 on a rotary phone', 3 => '3 seconds', 4 => '4 seconds', 5 => '5 seconds',
                7 => '7 seconds', 10 => '10 seconds', 15 => '15 seconds']],
        'earpiece' => ['group' => 'Sound', 'label' => 'How loud callers sound',
            'hint' => 'In the corded phone\'s earpiece — for little ears, or a phone that\'s quiet.', 'default' => 'factory',
            'choices' => self::GAINS],
        'mouthpiece' => ['group' => 'Sound', 'label' => 'How loud they sound to callers',
            'hint' => 'Turn up for a phone with a quiet microphone.', 'default' => 'factory', 'choices' => self::GAINS],
        'caller_id' => ['group' => 'Sound', 'label' => 'Caller ID on its screen',
            'hint' => 'For a corded phone with a display: the style it understands. Your country\'s, unless it shows nothing.', 'default' => 'auto',
            'choices' => ['auto' => 'Your country\'s', 9 => 'UK — BT (SIN 227)', 0 => 'North America (Bellcore)',
                1 => 'Europe — during ringing (ETSI-FSK)', 2 => 'Europe — before ringing (ETSI-FSK)',
                3 => 'Europe — before ringing, line reversal (ETSI-FSK)', 6 => 'Before ringing, by tones (ETSI-DTMF)', 10 => 'Japan (NTT)']],
        'message_light' => ['group' => 'Sound', 'label' => 'Light the phone\'s message lamp',
            'hint' => 'For a corded phone with a "message waiting" light.', 'default' => true],
        'offhook_tone' => ['group' => 'Calls', 'label' => 'A loud tone when it\'s left off the hook',
            'hint' => 'So a receiver left on the table gets noticed.', 'default' => true],
        'call_waiting' => ['group' => 'Calls', 'label' => 'Call waiting',
            'hint' => 'Off: a second caller goes to voicemail instead of beeping in a child\'s ear.', 'default' => false],
        'hook_flash' => ['group' => 'Calls', 'label' => 'Tapping the hook puts a call on hold',
            'hint' => 'Off, so a quick tap of the cradle can\'t leave someone waiting on hold.', 'default' => false],
        'star_codes' => ['group' => 'Safety', 'label' => 'The adapter\'s own star codes',
            'hint' => 'Off: *78 can\'t quietly turn on Do Not Disturb, nor *77 change what it rings when picked up.', 'default' => false],
        'check_user_id' => ['group' => 'Safety', 'label' => 'Refuse calls not meant for it',
            'hint' => 'Stops internet "SIP scanners" ringing it with ghost calls.', 'default' => true],
        'direct_ip' => ['group' => 'Safety', 'label' => 'Calls straight to its IP address',
            'hint' => 'Off: every call goes through twocans and its rules.', 'default' => false, 'shared' => true],
        'ssh' => ['group' => 'Safety', 'label' => 'SSH',
            'hint' => 'Nothing in twocans needs it.', 'default' => false, 'shared' => true],
        'keypad_menu' => ['group' => 'Safety', 'label' => 'Settings from the keypad\'s voice menu',
            'hint' => 'Off, so key-mashing (***) can\'t change its settings. It still reads out its IP address.', 'default' => false, 'shared' => true],
        'only_twocans' => ['group' => 'Looking after it', 'label' => 'Settings only from twocans',
            'hint' => 'A router or another phone system can\'t take it over.', 'default' => true, 'shared' => true],
        'firmware_checks' => ['group' => 'Looking after it', 'label' => 'Check Grandstream for new firmware',
            'hint' => 'Off, so it stays on the version that\'s known to work.', 'default' => false, 'shared' => true],
        'unregister_on_reboot' => ['group' => 'Looking after it', 'label' => 'Drop its old registration when it restarts',
            'hint' => 'So calls aren\'t sent to where it used to be.', 'default' => true],
    ];

    /**
     * The line itself, from the household's country (its dialling code): the
     * impedance its corded phones expect (SLIC), the caller ID style they
     * read, and the ring frequency. A country not here keeps the adapter's.
     */
    private const REGIONS = [
        '44' => ['slic' => 10, 'callerId' => 9, 'ring' => 25],
        '1' => ['slic' => 0, 'callerId' => 0, 'ring' => 20],
        '61' => ['slic' => 11], '64' => ['slic' => 8], '91' => ['slic' => 8], '49' => ['slic' => 9],
        '33' => ['slic' => 4], '34' => ['slic' => 4], '39' => ['slic' => 4], '31' => ['slic' => 4], '32' => ['slic' => 4],
        '43' => ['slic' => 4], '45' => ['slic' => 4], '46' => ['slic' => 4], '47' => ['slic' => 4], '351' => ['slic' => 4],
        '353' => ['slic' => 4], '358' => ['slic' => 4], '41' => ['slic' => 4], '48' => ['slic' => 4],
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
        $region = self::REGIONS[ContactRepository::countryCode()] ?? [];

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

            // The corded phone in it: what's been chosen on its Phone
            // settings tab, or the defaults. Grandstream's "Disable X" codes
            // are 1 for off.
            $o = PhoneSettings::for(['type' => $type] + $device);
            $off = static fn(bool $on): string => $on ? '0' : '1';
            foreach (['earpiece' => 'rxGain', 'mouthpiece' => 'txGain'] as $key => $code) {
                if ($o[$key] !== 'factory') {
                    $xml .= self::p($codes[$code], (string) $o[$key]);
                }
            }
            $callerId = $o['caller_id'] === 'auto' ? ($region['callerId'] ?? null) : (int) $o['caller_id'];
            if ($callerId !== null) {
                $xml .= self::p($codes['callerId'], (string) $callerId);
            }
            if (isset($region['slic'])) {
                $xml .= self::p($codes['slic'], (string) $region['slic']);       // line impedance
            }
            if (isset($region['ring'])) {
                $xml .= self::p($codes['ringFrequency'], (string) $region['ring']); // Hz
            }
            $xml .= self::p($codes['visualMwi'], $off((bool) $o['message_light']));
            $xml .= self::p($codes['offhookTone'], $off((bool) $o['offhook_tone']));
            $xml .= self::p($codes['callWaiting'], $off((bool) $o['call_waiting']));
            $xml .= self::p($codes['hookFlash'], $o['hook_flash'] ? '1' : '0');
            $xml .= self::p($codes['callFeatures'], $o['star_codes'] ? '1' : '0');
            $xml .= self::p($codes['checkUserId'], $o['check_user_id'] ? '1' : '0');
            $xml .= self::p($codes['unregister'], $o['unregister_on_reboot'] ? '1' : '0');
            // Picked up and nothing dialled: after a few seconds it rings the
            // grown-up chosen for it (its hotline). Empty is nothing.
            $xml .= self::p($codes['hotline'], (string) ($device['hotline'] ?? ''));
            $xml .= self::p($codes['hotlineDelay'], (string) ($device['hotlineDelay'] ?? 4));

            // How dialling feels: # sends the number straight away, otherwise
            // it goes after a few seconds without another number. A rotary
            // phone's clicks are heard as numbers, by its country's standard
            // (Sweden's and New Zealand's dials count differently).
            $xml .= self::p($codes['hashSends'], '1');
            $xml .= self::p($codes['dialWait'], (string) ($o['dial_wait'] === 'auto' ? ($o['rotary'] ? 7 : 4) : (int) $o['dial_wait']));
            $xml .= self::p($codes['pulse'], $o['rotary'] ? '1' : '0');
            $xml .= self::p($codes['pulseStandard'], ['46' => '1', '64' => '2'][ContactRepository::countryCode()] ?? '0');
        }

        // The adapter's own settings, from its first socket's phone.
        $box = PhoneSettings::for(['type' => $type] + $first);
        $xml .= self::p('P277', $box['direct_ip'] ? '0' : '1');           // direct IP calls
        $xml .= self::p('P276', $box['ssh'] ? '0' : '1');                 // SSH
        $xml .= self::p('P88', $box['keypad_menu'] ? '0' : '1');          // settings from the *** menu
        $xml .= self::p('P145', $box['only_twocans'] ? '0' : '1');        // DHCP can change the settings server
        $xml .= self::p('P1414', $box['only_twocans'] ? '0' : '1');       // 3CX auto provisioning
        $xml .= self::p('P238', $box['firmware_checks'] ? '0' : '2');     // check for new firmware
        $xml .= self::p('P22296', '0');                                   // no scheduled upgrades

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
    /**
     * The key on a phone's event addresses (phoneEventUrl), so nobody else
     * can say its handset's off the hook. From its SIP secret: a new one is a
     * new key.
     */
    public static function eventKey(array $device): string
    {
        return substr(hash_hmac('sha256', 'phone-event:' . (int) $device['id'], (string) $device['sipSecret']), 0, 20);
    }

    /** The address the phone calls when $event happens: started, offhook, onhook. */
    public static function phoneEventUrl(string $host, array $device, string $event, bool $https = false): string
    {
        return ($https ? 'https://' : 'http://') . $host . '/grandstream/event?d=' . (int) $device['id'] . '&e=' . $event . '&k=' . self::eventKey($device);
    }

    /**
     * @param string|null $host how the phone reached twocans for this file (its
     *        Host header) — so it keeps using exactly that, and the event
     *        addresses are ones it can reach. Null when there's no request.
     * @param bool $https whether it came over HTTPS — so it carries on that way
     */
    public function xml(array $device, array $hotkeys, array $labels = [], ?string $host = null, bool $https = false): string
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
        // The phone's voicemail button dials this: 700, its own messages.
        // Without it the button does nothing. (The light comes on by itself:
        // Asterisk tells the phone about new messages — mailboxes= on its
        // endpoint, see PjsipConfig.)
        $xml .= '    ' . $p('P33', PjsipConfig::VOICEMAIL_NUMBER) . "\n";  // voicemail access number

        // Announcements: answer by itself when the call asks to, through the
        // Call-Info/Alert-Info headers twocans sends (PjsipConfig's
        // twocans-page context). On by default on the GHP6xx; set anyway so a
        // factory reset or a hand edit can't quietly turn announcements back
        // into ringing. Only that header does it — ordinary calls still ring.
        $xml .= '    ' . $p('P298', '1') . "\n";                         // allow auto answer by Call-Info

        // A child's phone, often in a bedroom: everything below is one of its
        // PHONE_SETTINGS — the defaults, or what's been chosen for it on its
        // Phone settings tab. Its ring at the volume chosen for it.
        $o = self::settingsFor($device);
        $yes = static fn(bool $on): string => $on ? '1' : '0';
        $no = static fn(bool $on): string => $on ? '0' : '1';   // Grandstream's "0 is Yes"
        $xml .= '    ' . $p('P8352', (string) ($device['ringVolume'] ?? 4)) . "\n"; // ring volume, 0 - 8
        $xml .= '    ' . $p('P8392', $o['ring_lock'] ? '1' : '0') . "\n";  // lock the ring volume
        $xml .= '    ' . $p('P22482', $o['idle_light'] ? '10' : '0') . "\n"; // LED brightness when idle
        $xml .= '    ' . $p('P8371', ['blink' => '0', 'off' => '1', 'steady' => '2'][$o['message_light']] ?? '2') . "\n"; // new message light
        $xml .= '    ' . $p('P22486', $yes($o['boot_beep'])) . "\n";    // start-up tone
        $xml .= '    ' . $p('P91', $no($o['call_waiting'])) . "\n";     // call waiting
        $xml .= '    ' . $p('P1565', $o['mute_dnd'] ? '0' : '2') . "\n"; // Mute while idle: Do Not Disturb, or nothing
        $xml .= '    ' . $p('P1485', (string) $o['offhook_timeout']) . "\n"; // left off the hook: seconds till its tone

        // Picked up and nothing pressed: after a few seconds, it rings the
        // grown-up chosen for it (its hotline). Empty is nothing.
        $xml .= '    ' . $p('P71', (string) ($device['hotline'] ?? '')) . "\n"; // off-hook auto dial
        $xml .= '    ' . $p('P8388', (string) ($device['hotlineDelay'] ?? 4)) . "\n"; // ...after this many seconds

        // Nothing anyone else can reach: an incoming call has to be for this
        // phone (not a scanner trying any number); calls straight to its IP
        // address; SSH; settings from the keypad's voice menu (which still
        // reads out the IP address).
        $xml .= '    ' . $p('P258', $yes($o['check_user_id'])) . "\n";  // check the SIP user ID of a call
        $xml .= '    ' . $p('P1310', $no($o['direct_ip'])) . "\n";      // direct IP calls
        $xml .= '    ' . $p('P276', $no($o['ssh'])) . "\n";             // SSH
        $xml .= '    ' . $p('P22513', $yes($o['keypad_menu'])) . "\n";  // basic settings in the voice menu

        // Settings only ever from twocans — at exactly the address it used
        // for this file, over HTTP or HTTPS as it did — not a router's DHCP
        // option or a 3CX server; firmware only if asked for; its old
        // registration dropped when it reboots.
        if ($o['only_twocans'] && $host !== null && $host !== '') {
            $xml .= '    ' . $p('P212', $https ? '2' : '1') . "\n";      // settings over HTTP(S), as now
            $xml .= '    ' . $p('P237', $host . '/grandstream') . "\n";  // ...from where this came from
        }
        $xml .= '    ' . $p('P145', $o['only_twocans'] ? '0' : '1') . "\n"; // DHCP can change the settings server
        $xml .= '    ' . $p('P1414', $o['only_twocans'] ? '0' : '1') . "\n"; // 3CX auto provisioning
        $xml .= '    ' . $p('P238', $o['firmware_checks'] ? '0' : '2') . "\n"; // check for new firmware
        $xml .= '    ' . $p('P81', $yes($o['unregister_on_reboot'])) . "\n"; // unregister on reboot

        // It tells twocans when it's started up, and when its handset goes
        // off and back on the hook — for "left off the hook" on its page.
        $report = $o['report_status'] && $host !== null && $host !== '' && isset($device['id']);
        foreach (['P8304' => 'started', 'P8308' => 'offhook', 'P8309' => 'onhook'] as $code => $event) {
            $xml .= '    ' . $p($code, $report ? self::phoneEventUrl($host, $device, $event, $https) : '') . "\n";
        }

        // Pages: listen for twocans' multicast pages (see Pager), and play them
        // through the speaker as they come. A page doesn't cut into a call.
        $xml .= '    ' . $p('P1567', '1') . "\n";                        // paging priority active
        $xml .= '    ' . $p('P1566', '0') . "\n";                        // paging barge: never into a call
        $xml .= '    ' . $p('P8454', '0') . "\n";                        // plain RTP, not Polycom's format
        $xml .= '    ' . $p('P1569', Pager::address((int) ($device['id'] ?? 0))) . "\n"; // priority 1 listening address
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
