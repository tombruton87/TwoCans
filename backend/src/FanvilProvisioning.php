<?php
declare(strict_types=1);

/**
 * Settings for a Fanvil GA10 adapter — one corded phone — served as it asks
 * for them from its static provisioning server: /fanvil/<mac>.cfg, behind the
 * same username and password as the rest (see index.php).
 *
 * UNTESTED, and a best guess: Fanvil hasn't published the GA10's own
 * template. This is Fanvil's newer XML format (<sysConf>, which the GA10's
 * manual says it reads), with the keys Fanvil's current phones use for the
 * settings the GA10's manual describes on its web pages — checked against a
 * working X210 profile (FusionPBX's). A Fanvil file need only carry what it
 * changes, so a key the GA10 doesn't know is skipped by it, not an error.
 * Its caller ID style and line impedance have no published key at all, so
 * they're left as the adapter has them; and so is its clock's summer time.
 */
final class FanvilProvisioning
{
    private const VOLUMES = ['factory' => 'As the adapter comes', 9 => 'Loudest (9)', 8 => '8', 7 => '7', 6 => '6',
        5 => 'Middle (5)', 4 => '4', 3 => '3', 2 => '2', 1 => 'Quietest (1)'];

    /** What a GA10 can have changed — see PhoneSettings for the shape. */
    public const PHONE_SETTINGS = [
        'call_waiting' => ['group' => 'Calls', 'label' => 'Call waiting',
            'hint' => 'Off: a second caller goes to voicemail instead of beeping in a child\'s ear.', 'default' => false],
        'hook_flash' => ['group' => 'Calls', 'label' => 'Transfer and three-way calls with the hook',
            'hint' => 'Off, so a quick tap of the cradle can\'t pass a call on or start a three-way call.', 'default' => false],
        'dnd' => ['group' => 'Calls', 'label' => 'Do Not Disturb on the adapter',
            'hint' => 'Off, so it can\'t be silenced from its own menu. twocans\' own quiet hours still work.', 'default' => false],
        'dial_wait' => ['group' => 'Calls', 'label' => 'How long it waits after the last number',
            'hint' => 'Then it rings — or press # to ring straight away.', 'default' => 4,
            'choices' => [3 => '3 seconds', 4 => '4 seconds', 5 => '5 seconds', 7 => '7 seconds', 10 => '10 seconds']],
        'earpiece' => ['group' => 'Sound', 'label' => 'How loud callers sound',
            'hint' => 'In the corded phone\'s earpiece — for little ears, or a phone that\'s quiet.', 'default' => 'factory',
            'choices' => self::VOLUMES],
        'mouthpiece' => ['group' => 'Sound', 'label' => 'How loud they sound to callers',
            'hint' => 'Turn up for a phone with a quiet microphone.', 'default' => 'factory', 'choices' => self::VOLUMES],
        'direct_ip' => ['group' => 'Safety', 'label' => 'Calls straight to an IP address',
            'hint' => 'Off: every call goes through twocans and its rules.', 'default' => false],
        'telnet' => ['group' => 'Safety', 'label' => 'Telnet',
            'hint' => 'Nothing in twocans needs it.', 'default' => false],
        'firmware_checks' => ['group' => 'Looking after it', 'label' => 'Upgrade its firmware by itself',
            'hint' => 'Off, so it stays on the version that\'s known to work.', 'default' => false],
    ];

    /** Where to point its Static Provisioning Server. */
    public static function serverAddress(): string
    {
        return 'http://' . PjsipConfig::domain() . ':' . (int) (getenv('HTTP_PORT') ?: 8083) . '/fanvil';
    }

    private static function tag(string $name, string|int $value, int $depth): string
    {
        return str_repeat('    ', $depth) . '<' . $name . '>' . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $name . ">\n";
    }

    /**
     * The adapter's settings file.
     *
     * @param array $device DeviceRepository::toView() shape
     */
    public function xml(array $device): string
    {
        $o = PhoneSettings::for($device);
        $yes = static fn(bool $on): int => $on ? 1 : 0;
        $hotline = preg_replace('/[^0-9*#+]/', '', (string) ($device['hotline'] ?? '')) ?? '';

        $line = self::tag('PhoneNumber', $device['sipUsername'], 3)
            . self::tag('DisplayName', $device['name'], 3)
            . self::tag('RegisterAddr', PjsipConfig::domain(), 3)
            . self::tag('RegisterPort', PjsipConfig::port('udp'), 3)
            . self::tag('RegisterUser', $device['sipUsername'], 3)
            . self::tag('RegisterPswd', $device['sipSecret'], 3)
            . self::tag('RegisterTTL', 3600, 3)
            . self::tag('EnableReg', 1, 3)
            . self::tag('Transport', 0, 3)                                   // UDP
            // Its message key: 700, its own messages.
            . self::tag('MWINum', PjsipConfig::VOICEMAIL_NUMBER, 3)
            // Picked up and nothing dialled: after a few seconds it rings the
            // grown-up chosen for it (a "warm line").
            . self::tag('EnableHotline', $yes($hotline !== ''), 3)
            . self::tag('HotlineNum', $hotline, 3)
            . self::tag('WarmLineTime', $hotline !== '' ? max(1, (int) ($device['hotlineDelay'] ?? 4)) : 0, 3)
            . self::tag('EnableDND', 0, 3);

        $port = self::tag('CallWaiting', $yes((bool) $o['call_waiting']), 3)
            . self::tag('CallTransfer', $yes((bool) $o['hook_flash']), 3)
            . self::tag('CallSemiXfer', $yes((bool) $o['hook_flash']), 3)
            . self::tag('CallConference', $yes((bool) $o['hook_flash']), 3)
            . self::tag('EnableDND', $yes((bool) $o['dnd']), 3)
            . self::tag('AllowIPCall', $yes((bool) $o['direct_ip']), 3);

        $volume = '';
        foreach (['earpiece' => 'HandsetVol', 'mouthpiece' => 'HandsetMicVol'] as $key => $tag) {
            if ($o[$key] !== 'factory') {
                $volume .= self::tag($tag, (int) $o[$key], 3);
            }
        }

        // The household's clock: Fanvil names zones as Yealink does.
        [$offset, $zoneName] = YealinkProvisioning::timeZone(PjsipConfig::timezone());
        $date = self::tag('EnableSNTP', 1, 3)
            . self::tag('SNTPServer', 'pool.ntp.org', 3);
        if (preg_match('/^[+-]?\d+$/', $offset) === 1) {
            $date .= self::tag('TimeZone', (int) $offset, 3);
        }
        if ($zoneName !== null) {
            $date .= self::tag('TimeZoneName', $zoneName, 3);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . "<!-- twocans: made by twocans; change things on twocans' Phone settings tab. Untested on a real GA10. -->\n"
            . "<sysConf>\n"
            . self::tag('Version', '2.0000000000', 1)
            . "    <sip>\n        <line index=\"1\">\n" . $line . "        </line>\n    </sip>\n"
            . "    <call>\n        <port index=\"1\">\n" . $port . "        </port>\n"
            . "        <basic>\n"
            . self::tag('DialbyPound', 1, 3)
            . self::tag('DialbyTimeout', 1, 3)
            . self::tag('DialTimeoutvalue', (int) $o['dial_wait'], 3)
            . "        </basic>\n    </call>\n"
            . "    <phone>\n"
            . ($volume !== '' ? "        <volume>\n" . $volume . "        </volume>\n" : '')
            . "        <date>\n" . $date . "        </date>\n"
            . "    </phone>\n"
            . "    <web>\n" . self::tag('EnableTelnet', $yes((bool) $o['telnet']), 2) . "    </web>\n"
            . "    <fwCheck>\n" . self::tag('EnableAutoUpgrade', $yes((bool) $o['firmware_checks']), 2) . "    </fwCheck>\n"
            . "</sysConf>\n";
    }
}
