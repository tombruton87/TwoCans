<?php
declare(strict_types=1);

/**
 * Settings for Yealink phones, served as each asks for them: /yealink/<mac>.cfg,
 * behind the same username and password as the Grandstreams' (see index.php).
 *
 * A cordless base — W60B, W70B or W52P — and its handsets (cfg()): each
 * handset is a twocans phone of its own, sharing the base's MAC, its port
 * being its handset number (the order it was registered to the base; see
 * HANDSETS). Handset N gets account N, and rings and dials out on it only.
 *
 * A desk phone — T31G, T33G, T42U/S, T43U, T44U, T46U, T48U/S, T53W, T54W,
 * T57W, T58W (desk()): its line on line key 1,
 * twocans' hotkeys as speed dials on the keys after it, named on its screen.
 * A slot with no twocans phone has its account switched off, so an old one
 * left on the base can't register behind twocans' back.
 *
 * Parameter names are from Yealink's administrator guides — W60 (V81), W70B
 * (V85), W52P (V73) and the T-series (V86). On a base, most of
 * the handset's own settings (custom.handset.*) apply to every handset on the
 * base at once; those are 'shared' in PHONE_SETTINGS, and the first handset's
 * choice is the one sent.
 */
final class YealinkProvisioning
{
    /** Handsets one base holds, each on its own account — by twocans type (see DeviceRepository::TYPES). */
    public const HANDSETS = ['w56h' => 8, 'w70b' => 10, 'w52p' => 5];

    /** The base each cordless type is set up on, for its file's notes. */
    public const BASES = ['w56h' => 'W60B', 'w70b' => 'W70B', 'w52p' => 'W52P'];

    /** The file every W60B fetches first, before its own (<mac>.cfg). */
    public const COMMON_FILE = 'y000000000077.cfg';

    /**
     * What a W56H can have changed, on its Phone settings tab — see
     * PhoneSettings for the shape. Defaults are what's best for a child's
     * handset; each says where Yealink's own default differs.
     */
    public const PHONE_SETTINGS = [
        // Its ring: one of the handset's own, chosen per call by twocans
        // (Alert-Info "ringtone-N" — see PjsipConfig::renderRingtoneContext),
        // so each handset can have its own; and a fixed volume for them all.
        'ringtone' => ['group' => 'Ringing', 'label' => 'Its ringtone',
            'hint' => 'One of the tunes built into the handset — each handset can have its own.', 'default' => 'handset',
            'choices' => ['handset' => 'As chosen on the handset', 1 => 'Ring 1', 2 => 'Ring 2', 3 => 'Ring 3', 4 => 'Ring 4',
                5 => 'Ring 5', 6 => 'Ring 6', 7 => 'Ring 7', 8 => 'Ring 8']],
        'ring_volume' => ['group' => 'Ringing', 'label' => 'How loud it rings',
            'hint' => 'Fixed, so it can\'t be turned down to nothing on the handset — or left for the handset to change.',
            'default' => 'handset', 'shared' => true,
            'choices' => ['handset' => 'Changed on the handset', 1 => '1 — very quiet', 2 => '2 — quiet', 3 => '3 — normal',
                4 => '4 — loud', 5 => '5 — loudest']],
        'announcements' => ['group' => 'Calls', 'label' => 'An announcement',
            'hint' => 'From twocans — or the walkie-talkie. It plays through the handset, so it\'s heard best off the charger.',
            'default' => 2,
            'choices' => [2 => 'Answers by itself, with a beep first', 1 => 'Answers by itself, silently', 0 => 'Rings, to be answered']],
        'lift_to_answer' => ['group' => 'Calls', 'label' => 'Answer by lifting it off the charger',
            'hint' => 'No button to find: picked up while it rings, it answers.', 'default' => true, 'shared' => true],
        'charger_hangs_up' => ['group' => 'Calls', 'label' => 'Hang up by putting it back on the charger',
            'hint' => 'So a call ends when it\'s put away, like an ordinary phone.', 'default' => true, 'shared' => true],
        'call_waiting' => ['group' => 'Calls', 'label' => 'Call waiting',
            'hint' => 'Off: a second caller goes to voicemail instead of beeping in a child\'s ear.', 'default' => false, 'shared' => true],
        'intercom_answer' => ['group' => 'Calls', 'label' => 'An intercom from another handset',
            'hint' => 'When another handset on this base calls it with its Intercom button.', 'default' => 0, 'shared' => true,
            'choices' => [0 => 'Rings, to be answered', 2 => 'Answers by itself, with a beep', 1 => 'Answers by itself, silently']],
        'call_history' => ['group' => 'Calls', 'label' => 'Keep a list of its calls',
            'hint' => 'Missed, made and answered — on the handset, for calling back.', 'default' => true, 'shared' => true],
        'phonebook' => ['group' => 'Calls', 'label' => 'Its phonebook: everyone it can call',
            'hint' => 'From twocans\' call list, kept up to date — pick a name instead of dialling.', 'default' => true, 'shared' => true],

        'keypad_tone' => ['group' => 'Lights & sounds', 'label' => 'A tone when a key is pressed',
            'hint' => 'Helps little fingers know a press worked.', 'default' => true, 'shared' => true],
        'confirmation_tone' => ['group' => 'Lights & sounds', 'label' => 'A tone when it goes on the charger',
            'hint' => 'And when a setting is saved.', 'default' => true, 'shared' => true],
        'low_battery_tone' => ['group' => 'Lights & sounds', 'label' => 'A tone when its battery is low',
            'hint' => 'A reminder to put it back on the charger.', 'default' => true, 'shared' => true],
        'keypad_light' => ['group' => 'Lights & sounds', 'label' => 'Keys light up when pressed',
            'hint' => 'Easier to dial in the dark.', 'default' => true, 'shared' => true],
        'message_light' => ['group' => 'Lights & sounds', 'label' => 'Light for a new message',
            'hint' => 'Its message key flashes.', 'default' => true, 'shared' => true],
        'missed_light' => ['group' => 'Lights & sounds', 'label' => 'Light for a missed call',
            'hint' => 'Its message key flashes red.', 'default' => true, 'shared' => true],
        'ring_light' => ['group' => 'Lights & sounds', 'label' => 'Light flashes when it rings',
            'hint' => 'Its red light, as well as the ring.', 'default' => true, 'shared' => true],
        'idle_light' => ['group' => 'Lights & sounds', 'label' => 'Red light on when idle',
            'hint' => 'Off, so a handset in a bedroom isn\'t a night-light.', 'default' => false, 'shared' => true],

        'lit_on_charger' => ['group' => 'Screen', 'label' => 'Screen stays lit on the charger',
            'hint' => 'Off, so it goes dark at night. (Yealink has it on.)', 'default' => false, 'shared' => true],
        'lit_off_charger' => ['group' => 'Screen', 'label' => 'Screen stays lit off the charger',
            'hint' => 'On runs the battery down much faster.', 'default' => false, 'shared' => true],
        'clock' => ['group' => 'Screen', 'label' => 'A big clock when it\'s idle',
            'hint' => 'Its screen saver: an analogue clock face — good for learning to tell the time.', 'default' => true, 'shared' => true],
        'time_format' => ['group' => 'Screen', 'label' => 'The time on its screen',
            'hint' => 'How it writes the time.', 'default' => 24, 'shared' => true,
            'choices' => [24 => '24-hour — 18:30', 12 => '12-hour — 6:30 PM']],
        'wallpaper' => ['group' => 'Screen', 'label' => 'Wallpaper',
            'hint' => 'One of the five pictures built into the handset.', 'default' => 1, 'shared' => true,
            'choices' => [1 => 'Picture 1', 2 => 'Picture 2', 3 => 'Picture 3', 4 => 'Picture 4', 5 => 'Picture 5']],
        'colours' => ['group' => 'Screen', 'label' => 'Colours',
            'hint' => 'The handset\'s two colour schemes.', 'default' => 2, 'shared' => true,
            'choices' => [1 => 'Scheme 1', 2 => 'Scheme 2']],

        'direct_ip' => ['group' => 'Safety', 'label' => 'Calls straight to its IP address',
            'hint' => 'Off: every call goes through twocans and its rules. (Yealink has it on.)', 'default' => false, 'shared' => true],

        'unregister_on_reboot' => ['group' => 'Looking after it', 'label' => 'Drop its old registration when the base restarts',
            'hint' => 'So calls aren\'t sent to where it used to be.', 'default' => true],
    ];

    /**
     * A base's settings: the W60B's, less what that base hasn't. A W70B has
     * no message or missed-call light setting, nor colour schemes; a W52P no
     * hang-up-on-the-charger, power light or wallpaper settings.
     */
    public static function settingsFor(string $type): array
    {
        $without = match ($type) {
            'w70b' => ['message_light', 'missed_light', 'colours'],
            // Its older firmware (V73) documents neither a fixed ring volume
            // nor choosing a ring by Alert-Info.
            'w52p' => ['charger_hangs_up', 'ring_light', 'idle_light', 'wallpaper', 'ringtone', 'ring_volume', 'announcements'],
            default => [],
        };

        return array_diff_key(self::PHONE_SETTINGS, array_flip($without));
    }

    /** Line keys a desk phone has, its own line's first: by twocans type. */
    public const LINE_KEYS = ['t31g' => 2, 't33g' => 4, 't42u' => 6, 't43u' => 8, 't44u' => 8, 't46u' => 10, 't48u' => 10,
        't53w' => 8, 't54w' => 10, 't57w' => 10, 't58w' => 9];

    /**
     * What a desk phone hasn't, of what desk() would set: the T58W (its own
     * guide, the VP59/T58/CP96x one) has no idle brightness, see-through key
     * labels or screen saver clock; the T46U and T44U no see-through labels.
     */
    private const LACKS = [
        't58w' => ['inactive_backlight_level', 'transparency', 'screensaver_clock'],
        't46u' => ['transparency'],
        't44u' => ['transparency'],
    ];

    /**
     * The desk phones that can show a wallpaper of the household's own: its
     * screen, in pixels — an upload is cropped to fill it.
     */
    public const WALLPAPER = ['t33g' => [320, 240], 't44u' => [320, 240], 't46u' => [480, 272], 't48u' => [800, 480],
        't54w' => [480, 272], 't57w' => [800, 480], 't58w' => [1024, 600]];

    /** The desk phones with a colour screen: a screen saver to choose. */
    private const COLOUR = ['t33g', 't44u', 't46u', 't48u', 't54w', 't57w', 't58w'];

    /**
     * What a Yealink desk phone can have changed — see PhoneSettings. Its
     * ring is set to a fixed volume, like the Grandstreams', so it can't be
     * turned down to nothing; or left for the phone, if chosen.
     */
    public const DESK_SETTINGS = [
        'ring_volume' => ['group' => 'Ringing', 'label' => 'How loud it rings',
            'hint' => 'Fixed, so it can\'t be turned down to nothing on the phone — or left for the phone to change.', 'default' => 3,
            'choices' => [1 => '1 — very quiet', 2 => '2 — quiet', 3 => '3 — normal', 4 => '4 — loud', 5 => '5 — loudest',
                'phone' => 'Changed on the phone']],
        'call_waiting' => ['group' => 'Calls', 'label' => 'Call waiting',
            'hint' => 'Off: a second caller goes to voicemail instead of beeping in a child\'s ear.', 'default' => false],
        'dnd' => ['group' => 'Calls', 'label' => 'Do Not Disturb on the phone',
            'hint' => 'Off, so a child can\'t silence it without anyone knowing. twocans\' own quiet hours still work.', 'default' => false],
        'forward' => ['group' => 'Calls', 'label' => 'Call forwarding on the phone',
            'hint' => 'Off, so its calls can\'t be sent somewhere else from its menu.', 'default' => false],
        'phonebook' => ['group' => 'Calls', 'label' => 'Its phonebook: everyone it can call',
            'hint' => 'From twocans\' call list, kept up to date — pick a name instead of dialling.', 'default' => true],
        'key_tone' => ['group' => 'Lights & sounds', 'label' => 'A tone when a key is pressed',
            'hint' => 'Helps little fingers know a press worked.', 'default' => true],
        'idle_light' => ['group' => 'Lights & sounds', 'label' => 'Screen lit when idle',
            'hint' => 'Off, so a phone in a bedroom isn\'t a night-light.', 'default' => false],
        'screen_saver' => ['group' => 'Lights & sounds', 'label' => 'A clock screen saver',
            'hint' => 'The time, big, after ten minutes idle.', 'default' => false, 'colour' => true],
        'direct_ip' => ['group' => 'Safety', 'label' => 'Calls straight to its IP address',
            'hint' => 'Off: every call goes through twocans and its rules. (Yealink has it on.)', 'default' => false],
        'unregister_on_reboot' => ['group' => 'Looking after it', 'label' => 'Drop its old registration when it restarts',
            'hint' => 'So calls aren\'t sent to where it used to be.', 'default' => true],
    ];

    /** A desk phone's settings: a mono screen hasn't a screen saver. */
    public static function deskSettingsFor(string $type): array
    {
        return in_array($type, self::COLOUR, true)
            ? self::DESK_SETTINGS
            : array_filter(self::DESK_SETTINGS, static fn(array $s): bool => !($s['colour'] ?? false));
    }

    /** Whether this twocans type is a Yealink desk phone. */
    public static function isDesk(string $type): bool
    {
        return isset(self::LINE_KEYS[$type]);
    }

    /**
     * The household's time zone (TZ) as the base knows it: an offset and one
     * of its own names, so it changes the clocks itself. Yealink's default is
     * China's; a zone not here gets its offset only.
     */
    private const TIME_ZONES = [
        'Europe/London' => ['0', 'United Kingdom(London)'], 'Europe/Dublin' => ['0', 'Ireland(Dublin)'],
        'Europe/Lisbon' => ['0', 'Portugal(Lisboa,Porto,Funchal)'], 'Atlantic/Canary' => ['0', 'Spain-Canary Islands(Las Palmas)'],
        'Europe/Paris' => ['+1', 'France(Paris)'], 'Europe/Berlin' => ['+1', 'Germany(Berlin)'],
        'Europe/Madrid' => ['+1', 'Spain(Madrid)'], 'Europe/Rome' => ['+1', 'Italy(Rome)'],
        'Europe/Amsterdam' => ['+1', 'Netherlands(Amsterdam)'], 'Europe/Brussels' => ['+1', 'Belgium(Brussels)'],
        'Europe/Vienna' => ['+1', 'Austria(Vienna)'], 'Europe/Prague' => ['+1', 'Czech Republic(Prague)'],
        'Europe/Copenhagen' => ['+1', 'Denmark(Kopenhagen)'], 'Europe/Budapest' => ['+1', 'Hungary(Budapest)'],
        'Europe/Zagreb' => ['+1', 'Croatia(Zagreb)'], 'Europe/Luxembourg' => ['+1', 'Luxembourg(Luxembourg)'],
        'Europe/Helsinki' => ['+2', 'Finland(Helsinki)'], 'Europe/Athens' => ['+2', 'Greece(Athens)'],
        'Europe/Riga' => ['+2', 'Latvia(Riga)'], 'Europe/Tallinn' => ['+2', 'Estonia(Tallinn)'],
        'Europe/Bucharest' => ['+2', 'Romania(Bucharest)'], 'Europe/Kyiv' => ['+2', 'Ukraine(Kyiv, Odessa)'],
        'Europe/Kiev' => ['+2', 'Ukraine(Kyiv, Odessa)'], 'Europe/Moscow' => ['+3', 'Russia(Moscow)'],
        'Asia/Kolkata' => ['+5:30', 'India(Calcutta)'], 'Asia/Shanghai' => ['+8', 'China(Beijing)'],
        'Asia/Singapore' => ['+8', 'Singapore(Singapore)'], 'Asia/Tokyo' => ['+9', 'Japan(Tokyo)'],
        'Asia/Seoul' => ['+9', 'Korea(Seoul)'], 'Australia/Perth' => ['+8', 'Australia(Perth)'],
        'Australia/Adelaide' => ['+9:30', 'Australia(Adelaide)'], 'Australia/Darwin' => ['+9:30', 'Australia(Darwin)'],
        'Australia/Brisbane' => ['+10', 'Australia(Brisbane)'], 'Australia/Hobart' => ['+10', 'Australia(Hobart)'],
        'Australia/Sydney' => ['+10', 'Australia(Sydney,Melboume,Canberra)'], 'Australia/Melbourne' => ['+10', 'Australia(Sydney,Melboume,Canberra)'],
        'Pacific/Auckland' => ['+12', 'New Zealand(Wellington,Auckland)'],
        'America/New_York' => ['-5', 'United States-Eastern Time'], 'America/Chicago' => ['-6', 'United States-Central Time'],
        'America/Denver' => ['-7', 'United States-Mountain Time'], 'America/Phoenix' => ['-7', 'United States-MST no DST'],
        'America/Los_Angeles' => ['-8', 'United States-Pacific Time'], 'America/Anchorage' => ['-9', 'United States-Alaska Time'],
        'Pacific/Honolulu' => ['-10', 'United States-Hawaii-Aleutian'], 'America/Toronto' => ['-5', 'Canada(Montreal,Ottawa,Quebec)'],
        'America/Vancouver' => ['-8', 'Canada(Vancouver,Whitehorse)'], 'America/Edmonton' => ['-7', 'Canada(Edmonton,Calgary)'],
        'America/Winnipeg' => ['-6', 'Canada-Manitoba(Winnipeg)'], 'America/Halifax' => ['-4', 'Canada(Halifax,Saint John)'],
        'America/St_Johns' => ['-3:30', 'Canada-New Foundland(St.Johns)'], 'America/Mexico_City' => ['-6', 'Mexico(Mexico City,Acapulco)'],
        'America/Argentina/Buenos_Aires' => ['-3', 'Argentina(Buenos Aires)'],
    ];

    /** The base's time zone: [offset, name or null] — see TIME_ZONES. */
    public static function timeZone(string $tz): array
    {
        if (isset(self::TIME_ZONES[$tz])) {
            return self::TIME_ZONES[$tz];
        }
        // Not one it has a name for: its standard (winter) offset.
        try {
            $zone = new DateTimeZone($tz);
        } catch (Throwable) {
            return ['0', null];
        }
        $year = (int) date('Y');
        $minutes = intdiv(min(
            $zone->getOffset(new DateTimeImmutable("$year-01-01", $zone)),
            $zone->getOffset(new DateTimeImmutable("$year-07-01", $zone))
        ), 60);
        $sign = $minutes < 0 ? '-' : '+';
        $minutes = abs($minutes);

        return [($minutes === 0 ? '' : $sign) . intdiv($minutes, 60) . ($minutes % 60 ? ':' . ($minutes % 60) : ''), null];
    }

    /**
     * The boot file newer firmware asks for first (<mac>.boot, or the common
     * y000000000000.boot): the settings file to load — its own <mac>.cfg. Not
     * overwriting: what twocans doesn't set is left as it is, not reset to
     * the factory's.
     */
    public static function boot(?string $mac): string
    {
        return "#!version:1.0.0.1\n"
            . "## twocans: this phone's settings are all in its own file.\n"
            . 'include:config "' . ($mac !== null ? $mac : '$mac') . ".cfg\"\n"
            . "overwrite_mode = 0\n";
    }

    /** The file every base fetches first: nothing in it — each base's own file has it all. */
    public static function common(): string
    {
        return "#!version:1.0.0.1\n## twocans: everything is in each base's own <mac>.cfg.\n";
    }

    private static function line(string $name, string|int $value): string
    {
        // One setting a line: nothing in a value may start another.
        return $name . ' = ' . str_replace(["\r", "\n"], ' ', (string) $value) . "\n";
    }

    /**
     * A base's settings file: its handsets' accounts, and how they behave.
     *
     * @param array<int,array> $byHandset handset number (1–8) => DeviceRepository::toView() shape
     * @param ?string $host how the base reached twocans (Host:), for the phonebook's address
     */
    public function cfg(array $byHandset, ?string $host = null, bool $https = false): string
    {
        ksort($byHandset);
        $first = $byHandset !== [] ? reset($byHandset) : [];
        // The base's own settings come from its first handset: see 'shared'.
        $type = isset(self::HANDSETS[$first['type'] ?? '']) ? (string) $first['type'] : 'w56h';
        $o = PhoneSettings::for(['type' => $type] + $first);
        $yes = static fn(bool $on): string => $on ? '1' : '0';
        $mac = GrandstreamProvisioning::normalizeMac((string) ($first['mac'] ?? ''));
        // A line for a setting this base has: one it hasn't (see settingsFor) is left out.
        $set = static fn(string $key, string $name, string|int $value): string => array_key_exists($key, $o) ? self::line($name, $value) : '';

        $cfg = "#!version:1.0.0.1\n"
            . '## twocans: the Yealink ' . self::BASES[$type] . ' base ' . ($mac !== '' ? strtolower($mac) . ' ' : '') . "and its handsets.\n"
            . "## Made by twocans and fetched again on every restart: change things on twocans' Phone settings tab, not here.\n\n";

        // Each handset: its own account, on its own line.
        foreach (range(1, self::HANDSETS[$type]) as $n) {
            $d = $byHandset[$n] ?? null;
            if ($d === null) {
                $cfg .= self::line("account.$n.enable", 0) . "\n";
                continue;
            }
            $mine = PhoneSettings::for(['type' => $type] + $d);
            $cfg .= "## Handset $n: " . str_replace(["\r", "\n"], ' ', (string) $d['name']) . "\n";
            $cfg .= self::line("account.$n.enable", 1);
            $cfg .= self::line("account.$n.label", $d['name']);
            $cfg .= self::line("account.$n.display_name", $d['name']);
            $cfg .= self::line("account.$n.auth_name", $d['sipUsername']);
            $cfg .= self::line("account.$n.user_name", $d['sipUsername']);
            $cfg .= self::line("account.$n.password", $d['sipSecret']);
            $cfg .= self::line("account.$n.sip_server.1.address", PjsipConfig::domain());
            $cfg .= self::line("account.$n.sip_server.1.port", PjsipConfig::port('udp'));
            $cfg .= self::line("account.$n.sip_server.1.transport_type", 0);             // UDP
            $cfg .= self::line("account.$n.sip_server.1.expires", 3600);                 // seconds
            $cfg .= self::line("account.$n.outbound_proxy_enable", 0);
            $cfg .= self::line("account.$n.unregister_on_reboot", $yes((bool) $mine['unregister_on_reboot']));
            // An announcement (Alert-Info alert-autoanswer): answered by itself,
            // with a beep or without — never barging into a call.
            if (array_key_exists('announcements', $mine)) {
                $cfg .= self::line("account.$n.auto_external_intercom", (int) $mine['announcements']);
                $cfg .= self::line("account.$n.external_intercom.barge.enable", 0);
            }
            // Its message key dials 700, its own messages; the light comes on
            // by itself (Asterisk tells it — mailboxes= on its endpoint).
            $cfg .= self::line("account.$n.subscribe_mwi_to_vm", 1);
            $cfg .= self::line("voice_mail.number.$n", PjsipConfig::VOICEMAIL_NUMBER);
            // Its name on its screen, and only its own line in and out.
            $cfg .= self::line("handset.$n.name", rtrim(rtrim(mb_substr((string) $d['name'], 0, 12))));
            $cfg .= self::line("handset.$n.incoming_lines", $n);
            $cfg .= self::line("handset.$n.dial_out_lines", $n);
            $cfg .= self::line("handset.$n.dial_out_default_line", $n);
            $cfg .= "\n";
        }

        // Told to fetch these again (a check-sync NOTIFY — see
        // GrandstreamProvisioning::notify): do, restarting only when asked to.
        $cfg .= "## The base\n";
        $cfg .= self::line('sip.notify_reboot_enable', 0);
        // Its handsets take their settings from here, not just the base.
        $cfg .= self::line('auto_provision.handset_configured.enable', 1);
        if ($type === 'w70b') {
            $cfg .= self::line('static.auto_provision.handset_configured.enable', 1);   // the W70B's name for it
        }

        // The household's time, not China's.
        [$offset, $name] = self::timeZone(PjsipConfig::timezone());
        $cfg .= self::line('local_time.time_zone', $offset);
        if ($name !== null) {
            $cfg .= self::line('local_time.time_zone_name', $name);
        }
        $cfg .= self::line('local_time.summer_time', 2);                                  // automatic
        $cfg .= self::line('local_time.ntp_server1', 'pool.ntp.org');
        $cfg .= self::line('custom.handset.time_format', $o['time_format'] === 12 ? 0 : 1);

        // Its phonebook: everyone it can call, from twocans.
        $phonebook = $o['phonebook'] && $host !== null && $host !== '';
        $cfg .= self::line('features.remote_phonebook.enable', $yes($phonebook));
        $cfg .= self::line('remote_phonebook.data.1.name', $phonebook ? 'twocans' : '');
        $cfg .= self::line('remote_phonebook.data.1.url', $phonebook
            ? ($https ? 'https' : 'http') . '://twocans:' . rawurlencode((new SettingsRepository())->provisionPass()) . '@' . $host . '/phonebook/yealink.xml'
            : '');

        $cfg .= "\n## Its settings (twocans' Phone settings tab)\n";
        // A fixed ring volume for every handset, or blank for each to change.
        $cfg .= $set('ring_volume', 'force.voice.ring_vol', ($o['ring_volume'] ?? 'handset') === 'handset' ? '' : (int) $o['ring_volume']);
        $cfg .= self::line('custom.handset.auto_answer.enable', $yes((bool) $o['lift_to_answer']));
        $cfg .= $set('charger_hangs_up', 'phone_setting.end_call_on_hook.enable', array_key_exists('charger_hangs_up', $o) ? $yes((bool) $o['charger_hangs_up']) : 0);
        $cfg .= self::line('call_waiting.enable', $yes((bool) $o['call_waiting']));
        $cfg .= self::line('custom.handset.auto_intercom', (int) $o['intercom_answer']);
        $cfg .= self::line('features.save_call_history', $yes((bool) $o['call_history']));
        $cfg .= self::line('custom.handset.keypad_tone.enable', $yes((bool) $o['keypad_tone']));
        $cfg .= self::line('custom.handset.confirmation_tone.enable', $yes((bool) $o['confirmation_tone']));
        $cfg .= self::line('custom.handset.low_battery_tone.enable', $yes((bool) $o['low_battery_tone']));
        $cfg .= self::line('custom.handset.keypad_light.enable', $yes((bool) $o['keypad_light']));
        $cfg .= $set('message_light', 'custom.handset.voice_mail_notify_light.enable', array_key_exists('message_light', $o) ? $yes((bool) $o['message_light']) : 0);
        $cfg .= $set('missed_light', 'custom.handset.missed_call_notify_light.enable', array_key_exists('missed_light', $o) ? $yes((bool) $o['missed_light']) : 0);
        $cfg .= $set('ring_light', 'phone_setting.ring_power_led_flash_enable', array_key_exists('ring_light', $o) ? $yes((bool) $o['ring_light']) : 0);
        $cfg .= $set('idle_light', 'phone_setting.common_power_led_enable', array_key_exists('idle_light', $o) ? $yes((bool) $o['idle_light']) : 0);
        $cfg .= self::line('custom.handset.backlight_in_charger.enable', $yes((bool) $o['lit_on_charger']));
        $cfg .= self::line('custom.handset.backlight_out_of_charger.enable', $yes((bool) $o['lit_off_charger']));
        $cfg .= self::line('custom.handset.screen_saver.enable', $yes((bool) $o['clock']));
        $cfg .= $set('wallpaper', 'custom.handset.wallpaper', array_key_exists('wallpaper', $o) ? (int) $o['wallpaper'] : 0);
        $cfg .= $set('colours', 'custom.handset.color_scheme', array_key_exists('colours', $o) ? (int) $o['colours'] - 1 : 0);
        $cfg .= self::line('features.direct_ip_call_enable', $yes((bool) $o['direct_ip']));

        return $cfg;
    }

    /**
     * A desk phone's settings file: its line, its speed-dial keys, how it
     * answers announcements, and its Phone settings.
     *
     * @param array                $device  DeviceRepository::toView() shape
     * @param array<int,string>    $hotkeys key index => number (key 1 is line key 2)
     * @param array<string,string> $labels  number => who it is
     * @param ?string $host how it reached twocans (Host:), for the phonebook's address
     */
    public function desk(array $device, array $hotkeys = [], array $labels = [], ?string $host = null, bool $https = false): string
    {
        $type = (string) $device['type'];
        $o = PhoneSettings::for($device);
        $yes = static fn(bool $on): string => $on ? '1' : '0';
        $mac = GrandstreamProvisioning::normalizeMac((string) ($device['mac'] ?? ''));

        $cfg = "#!version:1.0.0.1\n"
            . '## twocans: the Yealink ' . strtoupper($type) . ' ' . ($mac !== '' ? strtolower($mac) . ' ' : '')
            . '— ' . str_replace(["\r", "\n"], ' ', (string) $device['name']) . ".\n"
            . "## Made by twocans and fetched again on every restart: change things on twocans' Phone settings tab, not here.\n\n";

        // Its line.
        $cfg .= self::line('account.1.enable', 1);
        $cfg .= self::line('account.1.label', $device['name']);
        $cfg .= self::line('account.1.display_name', $device['name']);
        $cfg .= self::line('account.1.auth_name', $device['sipUsername']);
        $cfg .= self::line('account.1.user_name', $device['sipUsername']);
        $cfg .= self::line('account.1.password', $device['sipSecret']);
        $cfg .= self::line('account.1.sip_server.1.address', PjsipConfig::domain());
        $cfg .= self::line('account.1.sip_server.1.port', PjsipConfig::port('udp'));
        $cfg .= self::line('account.1.sip_server.1.transport_type', 0);                 // UDP
        $cfg .= self::line('account.1.sip_server.1.expires', 3600);
        $cfg .= self::line('account.1.outbound_proxy_enable', 0);
        $cfg .= self::line('account.1.unregister_on_reboot', $yes((bool) $o['unregister_on_reboot']));
        // Its message key dials 700, its own messages; the light comes by itself.
        $cfg .= self::line('account.1.subscribe_mwi_to_vm', 1);
        $cfg .= self::line('voice_mail.number.1', PjsipConfig::VOICEMAIL_NUMBER);
        // Told to fetch these again (check-sync): do, restarting only when asked.
        $cfg .= self::line('sip.notify_reboot_enable', 0);

        // Announcements: answered by itself, from the Alert-Info twocans sends
        // with them (info=alert-autoanswer) — never barging into a call.
        $cfg .= "\n## Announcements\n";
        $cfg .= self::line('features.intercom.allow', 1);
        $cfg .= self::line('features.intercom.barge', 0);

        // Picked up and nothing dialled: after a few seconds, it rings the
        // grown-up chosen for it (its hotline). Blank is none.
        $hotline = preg_replace('/[^0-9*#+]/', '', (string) ($device['hotline'] ?? '')) ?? '';
        $cfg .= self::line('features.hotline_number', $hotline);
        $cfg .= self::line('features.hotline_delay', $hotline !== '' ? max(1, min(10, (int) ($device['hotlineDelay'] ?? 4))) : 0);

        // Line key 1 is its line; the rest are twocans' hotkeys, as speed dials
        // named on its screen. One not set is nothing at all.
        $cfg .= "\n## Its keys\n";
        $cfg .= self::line('linekey.1.type', 15);
        $cfg .= self::line('linekey.1.line', 1);
        foreach (range(1, self::LINE_KEYS[$type] - 1) as $index) {
            $key = $index + 1;
            $number = (string) ($hotkeys[$index] ?? '');
            $cfg .= self::line("linekey.$key.type", $number !== '' ? 13 : 0);
            $cfg .= self::line("linekey.$key.line", 1);
            $cfg .= self::line("linekey.$key.value", $number);
            $cfg .= self::line("linekey.$key.label", $number !== '' ? mb_substr($labels[$number] ?? $number, 0, 20) : '');
        }

        // The household's time, and its phonebook.
        $cfg .= "\n## Time and phonebook\n";
        [$offset, $name] = self::timeZone(PjsipConfig::timezone());
        $cfg .= self::line('local_time.time_zone', $offset);
        if ($name !== null) {
            $cfg .= self::line('local_time.time_zone_name', $name);
        }
        $cfg .= self::line('local_time.summer_time', 2);
        $cfg .= self::line('local_time.ntp_server1', 'pool.ntp.org');
        $phonebook = $o['phonebook'] && $host !== null && $host !== '';
        $cfg .= self::line('features.remote_phonebook.enable', $yes($phonebook));
        $cfg .= self::line('remote_phonebook.data.1.name', $phonebook ? 'twocans' : '');
        $cfg .= self::line('remote_phonebook.data.1.url', $phonebook
            ? ($https ? 'https' : 'http') . '://twocans:' . rawurlencode((new SettingsRepository())->provisionPass()) . '@' . $host . '/phonebook/yealink.xml'
            : '');

        $cfg .= "\n## Its settings (twocans' Phone settings tab)\n";
        // A fixed ring volume, or blank for the phone to change.
        $cfg .= self::line('force.voice.ring_vol', $o['ring_volume'] === 'phone' ? '' : (int) $o['ring_volume']);
        $cfg .= self::line('call_waiting.enable', $yes((bool) $o['call_waiting']));
        $cfg .= self::line('features.dnd.allow', $yes((bool) $o['dnd']));
        $cfg .= self::line('features.fwd.allow', $yes((bool) $o['forward']));
        $cfg .= self::line('features.key_tone', $yes((bool) $o['key_tone']));
        // Lit when idle, or dimmed after 30 seconds. (0 is "always on"; 1 would
        // be "always off" on a black-and-white screen.)
        $cfg .= self::line('phone_setting.backlight_time', $o['idle_light'] ? 0 : 30);
        $lacks = self::LACKS[$type] ?? [];
        if (!in_array('inactive_backlight_level', $lacks, true)) {
            $cfg .= self::line('phone_setting.inactive_backlight_level', $o['idle_light'] ? 1 : 0);
        }
        // Its own wallpaper, fetched from twocans (behind the same password);
        // or its built-in one. Its key labels go part see-through over a photo.
        if (isset(self::WALLPAPER[$type])) {
            $wallpaper = (string) ($device['wallpaper'] ?? '');
            $own = $wallpaper !== '' && $host !== null && $host !== '';
            $cfg .= self::line('wallpaper_upload.url', $own
                ? ($https ? 'https' : 'http') . '://twocans:' . rawurlencode((new SettingsRepository())->provisionPass()) . '@' . $host . '/yealink/wallpaper/' . $wallpaper
                : '');
            $cfg .= self::line('phone_setting.backgrounds', $own ? $wallpaper : ($type === 't46u' ? 'Default.png' : 'Default.jpg'));
            if (!in_array('transparency', $lacks, true)) {
                $cfg .= self::line('phone_setting.idle_dsskey_and_title.transparency', $own ? '40%' : '100%');
            }
        }
        if (array_key_exists('screen_saver', $o)) {
            // Ten minutes, or "off": its longest wait, twelve hours (it has no 0).
            $cfg .= self::line('screensaver.wait_time', $o['screen_saver'] ? 600 : 43200);
            if (!in_array('screensaver_clock', $lacks, true)) {
                $cfg .= self::line('screensaver.display_clock.enable', 1);
            }
        }
        $cfg .= self::line('features.direct_ip_call_enable', $yes((bool) $o['direct_ip']));

        return $cfg;
    }

    /** The URL to type into the base: where its settings are (it adds <mac>.cfg itself). */
    public static function serverUrl(): string
    {
        return 'http://' . PjsipConfig::domain() . ':' . (int) (getenv('HTTP_PORT') ?: 8083) . '/yealink/';
    }
}
