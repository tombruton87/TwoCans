<?php
declare(strict_types=1);

/**
 * Settings for a Poly (Polycom) VVX desk phone, served as it asks for them
 * from its provisioning server, /polycom/, behind the same username and
 * password as the rest (see index.php):
 *
 *  - <mac>.cfg, its master configuration file: no firmware to fetch, and one
 *    file of settings, twocans-<mac>.cfg;
 *  - twocans-<mac>.cfg: its account, its message key, the household's time,
 *    and its PHONE_SETTINGS;
 *  - <mac>-directory.xml: its contacts, each hotkey a speed dial — the VVX
 *    puts speed dials on its unused line keys, in order.
 *
 * Parameter names are from Poly's UC Software 6.4 Administrator Guide. A
 * check-sync NOTIFY (GrandstreamProvisioning::notify) has it fetch them again,
 * restarting only when it must. Its hotline (call.autoOffHook) dials the
 * moment the handset's lifted, so a child could never dial anyone else on it:
 * twocans doesn't offer one.
 */
final class PolyProvisioning
{
    /**
     * What a VVX can have changed, on its Phone settings tab — see
     * PhoneSettings for the shape.
     */
    public const PHONE_SETTINGS = [
        'call_waiting' => ['group' => 'Calls', 'label' => 'Call waiting',
            'hint' => 'Off: a second caller goes to voicemail instead of beeping in a child\'s ear.', 'default' => false],
        'speakerphone' => ['group' => 'Calls', 'label' => 'Speakerphone',
            'hint' => 'Off, so calls are on the handset — and a call can\'t be left running on the speaker.', 'default' => true],
        'dnd' => ['group' => 'Calls', 'label' => 'Do Not Disturb on the phone',
            'hint' => 'Off, so a child can\'t silence it without anyone knowing. twocans\' own quiet hours still work.', 'default' => false],
        'forward' => ['group' => 'Calls', 'label' => 'Call forwarding on the phone',
            'hint' => 'Off, so its calls can\'t be sent somewhere else from its menu.', 'default' => false],
        'keep_volume' => ['group' => 'Sound', 'label' => 'Keep the earpiece volume between calls',
            'hint' => 'Off: back to normal for every call, however loud or quiet the last one was turned.', 'default' => false],
        'idle_light' => ['group' => 'Screen', 'label' => 'Screen brightness when idle',
            'hint' => 'Low or off, so a phone in a bedroom isn\'t a night-light.', 'default' => 0,
            'choices' => [0 => 'Off', 1 => 'Low', 2 => 'Medium', 3 => 'High']],
        'screen_saver' => ['group' => 'Screen', 'label' => 'A screen saver when idle',
            'hint' => 'The clock and date, moving around the screen.', 'default' => false],
        'url_dialing' => ['group' => 'Safety', 'label' => 'Calls to an internet address',
            'hint' => 'Off: every call goes through twocans and its rules.', 'default' => false],
        'edit_contacts' => ['group' => 'Safety', 'label' => 'Change its contacts and speed dials on the phone',
            'hint' => 'Off: they\'re twocans\' — its call list and hotkeys — and only change from here.', 'default' => false],
    ];

    /**
     * The household's clocks change: the rule, as the VVX writes it. Europe
     * (last Sundays of March and October) or North America (second Sunday
     * of March, first of November); null for none.
     *
     * @return array{0:int,1:?array} [offset in seconds, the rule's settings or null]
     */
    public static function timeZone(string $tz): array
    {
        try {
            $zone = new DateTimeZone($tz);
        } catch (Throwable) {
            return [0, null];
        }
        $year = (int) date('Y');
        $winter = $zone->getOffset(new DateTimeImmutable("$year-01-15", $zone));
        $summer = $zone->getOffset(new DateTimeImmutable("$year-07-15", $zone));
        $offset = min($winter, $summer);
        if ($winter === $summer) {
            return [$offset, null];
        }
        if (str_starts_with($tz, 'Europe/') || str_starts_with($tz, 'Atlantic/')) {
            // 01:00 UTC both ways: 1am in the UK, 2am in Paris.
            $at = intdiv($offset, 3600) + 1;

            return [$offset, ['start.month' => 3, 'start.dayOfWeek' => 1, 'start.dayOfWeek.lastInMonth' => 1, 'start.time' => $at,
                'stop.month' => 10, 'stop.dayOfWeek' => 1, 'stop.dayOfWeek.lastInMonth' => 1, 'stop.time' => $at + 1]];
        }
        if (str_starts_with($tz, 'America/')) {
            return [$offset, ['start.month' => 3, 'start.date' => 8, 'start.dayOfWeek' => 1, 'start.dayOfWeek.lastInMonth' => 0, 'start.time' => 2,
                'stop.month' => 11, 'stop.date' => 1, 'stop.dayOfWeek' => 1, 'stop.dayOfWeek.lastInMonth' => 0, 'stop.time' => 2]];
        }

        return [$offset, null];
    }

    /** Where the phone's provisioning server is: typed into the phone. */
    public static function serverAddress(): string
    {
        return 'http://' . PjsipConfig::domain() . ':' . (int) (getenv('HTTP_PORT') ?: 8083) . '/polycom';
    }

    /** Its master configuration file: no firmware, one file of settings. */
    public static function master(): string
    {
        return '<?xml version="1.0" standalone="yes"?>' . "\n"
            . "<!-- twocans: the phone's own settings are in twocans-<mac>.cfg. -->\n"
            . '<APPLICATION APP_FILE_PATH="" CONFIG_FILES="twocans-[PHONE_MAC_ADDRESS].cfg" MISC_FILES="" '
            . 'LOG_FILE_DIRECTORY="" OVERRIDES_DIRECTORY="" CONTACTS_DIRECTORY="" LICENSE_DIRECTORY="" '
            . 'USER_PROFILES_DIRECTORY="" CALL_LISTS_DIRECTORY="" COREFILE_DIRECTORY="" />' . "\n";
    }

    private static function attrs(array $params): string
    {
        $out = '';
        foreach ($params as $name => $value) {
            $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            $out .= "\n    " . $name . '="' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"';
        }

        return $out;
    }

    /**
     * The phone's settings file.
     *
     * @param array $device DeviceRepository::toView() shape
     */
    public function config(array $device): string
    {
        $o = PhoneSettings::for($device);
        $account = [
            'reg.1.address' => $device['sipUsername'],
            'reg.1.label' => $device['name'],
            'reg.1.displayName' => $device['name'],
            'reg.1.auth.userId' => $device['sipUsername'],
            'reg.1.auth.password' => $device['sipSecret'],
            'reg.1.server.1.address' => PjsipConfig::domain(),
            'reg.1.server.1.port' => PjsipConfig::port('udp'),
            'reg.1.server.1.transport' => 'UDPOnly',
            'reg.1.server.1.expires' => 3600,
            'reg.1.lineKeys' => 1,
            // Its message key rings 700, its own messages, straight away;
            // the light comes on by itself (mailboxes= on its endpoint).
            'msg.mwi.1.callBackMode' => 'contact',
            'msg.mwi.1.callBack' => PjsipConfig::VOICEMAIL_NUMBER,
            'up.oneTouchVoiceMail' => 1,
            // Told to fetch these again (check-sync): only restart if it must.
            'voIpProt.SIP.specialEvent.checkSync.alwaysReboot' => 0,
            // Announcements: answered by itself, from the Alert-Info twocans
            // sends with them (PjsipConfig's intercom). Ordinary calls ring.
            'voIpProt.SIP.alertInfo.1.value' => 'http://twocans',
            'voIpProt.SIP.alertInfo.1.class' => 'autoAnswer',
            // Its speed dials are twocans' hotkeys, in order on its line keys.
            'dir.local.contacts.maxFavIx' => max(1, DeviceRepository::keys((string) $device['type'])),
        ];

        [$offset, $dst] = self::timeZone(PjsipConfig::timezone());
        $time = [
            'tcpIpApp.sntp.address' => 'pool.ntp.org',
            'tcpIpApp.sntp.gmtOffset' => $offset,
            'tcpIpApp.sntp.gmtOffset.overrideDHCP' => 1,
            'tcpIpApp.sntp.daylightSavings.enable' => $dst !== null ? 1 : 0,
        ];
        if ($dst !== null) {
            $time['tcpIpApp.sntp.daylightSavings.fixedDayEnable'] = 0;
            foreach ($dst as $key => $value) {
                $time['tcpIpApp.sntp.daylightSavings.' . $key] = $value;
            }
        }

        $settings = [
            'call.callWaiting.enable' => (bool) $o['call_waiting'],
            'up.handsfreeMode' => (bool) $o['speakerphone'],
            'feature.doNotDisturb.enable' => (bool) $o['dnd'],
            'feature.forward.enable' => (bool) $o['forward'],
            'voice.volume.persist.handset' => (bool) $o['keep_volume'],
            'up.backlight.idleIntensity' => (int) $o['idle_light'],
            'up.screenSaver.enabled' => (bool) $o['screen_saver'],
            'feature.urlDialing.enabled' => (bool) $o['url_dialing'],
            'dir.local.readonly' => !$o['edit_contacts'],
        ];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . "<!-- twocans: made by twocans and fetched again whenever it changes; change things on twocans' Phone settings tab. -->\n"
            . "<polycomConfig>\n"
            . '  <account' . self::attrs($account) . " />\n"
            . '  <time' . self::attrs($time) . " />\n"
            . '  <settings' . self::attrs($settings) . " />\n"
            . "</polycomConfig>\n";
    }

    /**
     * Its contacts: each hotkey a speed dial, in key order — the VVX shows
     * speed dials on its unused line keys — named as the phone shows it.
     *
     * @param array<int,string>    $hotkeys key index => number
     * @param array<string,string> $labels  number => who it is
     */
    public function directory(array $hotkeys, array $labels): string
    {
        ksort($hotkeys);
        $items = '';
        foreach ($hotkeys as $index => $number) {
            $number = (string) $number;
            if ($number === '') {
                continue;
            }
            $items .= '    <item><fn>' . htmlspecialchars(mb_substr($labels[$number] ?? $number, 0, 40), ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</fn><ct>' . htmlspecialchars($number, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</ct><sd>' . (int) $index . "</sd></item>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . "<directory>\n  <item_list>\n" . $items . "  </item_list>\n</directory>\n";
    }
}
