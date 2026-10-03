<?php
declare(strict_types=1);

/**
 * Settings for a Cisco adapter — two corded phones, PHONE 1 and PHONE 2: an
 * SPA112 or SPA122 (an SPA112 with a router), or an ATA 191 or ATA 192 (their
 * successors, on Multiplatform firmware) — served as it asks for them: /cisco/<mac>.xml, behind the same
 * username and password as the Grandstreams' (its Profile Rule carries them:
 * [--uid twocans --pwd …]). One file, a Cisco "flat profile": each setting is
 * a tag named after its field on the adapter's web page, a line's ending
 * _1_ or _2_.
 *
 * Tag names and values are from Cisco's SPA100 Series Administration Guide
 * and its ATA 191/192 Multiplatform Provisioning Guide, checked against
 * working profiles (FusionPBX's, sipXecs', Provisioner's). The ATA 19x keeps
 * the SPA's voice settings; its clock is set in a <router-configuration>
 * section, by Cisco's own time zone codes, its impedances differ a little,
 * and it has no "protect factory reset". Unlike an HT80x, none can hear a
 * rotary dial; caller ID, gains, impedance and dialling timers are the
 * adapter's, not each phone's.
 */
final class CiscoProvisioning
{
    /** The phones one SPA112 takes. */
    public const PORTS = 2;

    /** Its gain choices, in dB: what it comes with is −3. */
    private const GAINS = ['factory' => 'As the adapter comes (−3 dB)', 3 => 'Louder (+3 dB)', 0 => 'A little louder (0 dB)',
        -6 => 'A little quieter (−6 dB)', -9 => 'Quieter (−9 dB)'];

    /**
     * What an SPA112 can have changed — see PhoneSettings for the shape.
     * 'shared': the adapter's own, the same for both phones on it.
     */
    public const PHONE_SETTINGS = [
        'call_waiting' => ['group' => 'Calls', 'label' => 'Call waiting',
            'hint' => 'Off: a second caller goes to voicemail instead of beeping in a child\'s ear.', 'default' => false],
        'hook_flash' => ['group' => 'Calls', 'label' => 'Tapping the hook puts a call on hold',
            'hint' => 'Off, so a quick tap of the cradle can\'t leave someone waiting on hold, or start a three-way call.', 'default' => false],
        'dial_wait' => ['group' => 'Calls', 'label' => 'How long it waits after the last number',
            'hint' => 'Then it rings — or press # to ring straight away.',
            'default' => 4, 'shared' => true,
            'choices' => [3 => '3 seconds', 4 => '4 seconds', 5 => '5 seconds', 7 => '7 seconds', 10 => '10 seconds']],

        'earpiece' => ['group' => 'Sound', 'label' => 'How loud callers sound',
            'hint' => 'In the corded phone\'s earpiece — for little ears, or a phone that\'s quiet.', 'default' => 'factory', 'shared' => true,
            'choices' => self::GAINS],
        'mouthpiece' => ['group' => 'Sound', 'label' => 'How loud they sound to callers',
            'hint' => 'Turn up for a phone with a quiet microphone.', 'default' => 'factory', 'shared' => true,
            'choices' => self::GAINS],
        'caller_id' => ['group' => 'Sound', 'label' => 'Caller ID on its screen',
            'hint' => 'For a corded phone with a display: the style it understands. Your country\'s, unless it shows nothing.',
            'default' => 'auto', 'shared' => true,
            'choices' => ['auto' => 'Your country\'s', 'ETSI FSK With PR(UK)' => 'UK — BT', 'Bellcore(N.Amer,China)' => 'North America (Bellcore)',
                'ETSI FSK' => 'Europe — before ringing (ETSI-FSK)', 'ETSI DTMF' => 'Europe — by tones (ETSI-DTMF)',
                'DTMF(Finland,Sweden)' => 'Finland, Sweden', 'DTMF(Denmark)' => 'Denmark']],
        'message_light' => ['group' => 'Sound', 'label' => 'Light the phone\'s message lamp',
            'hint' => 'For a corded phone with a "message waiting" light.', 'default' => true],

        'star_codes' => ['group' => 'Safety', 'label' => 'The adapter\'s own star codes',
            'hint' => 'Off: *72 can\'t forward its calls somewhere else, nor *78 quietly turn on Do Not Disturb.', 'default' => false],
        'keypad_reset' => ['group' => 'Safety', 'label' => 'Factory reset from the keypad\'s voice menu',
            'hint' => 'Off, so key-mashing (****) can\'t wipe it. It still reads out its IP address.', 'default' => false, 'shared' => true],
        'firmware_checks' => ['group' => 'Looking after it', 'label' => 'Upgrade its firmware when told to',
            'hint' => 'Off, so it stays on the version that\'s known to work.', 'default' => false, 'shared' => true],
    ];

    /** An ATA 191 or 192's: the same, but it has no "protect factory reset". */
    public static function ataSettings(): array
    {
        $settings = self::PHONE_SETTINGS;
        unset($settings['keypad_reset']);

        return $settings;
    }

    /** Whether this is one of the ATA 19x, rather than an SPA. */
    public static function isAta19x(string $type): bool
    {
        return in_array($type, ['ata191', 'ata192'], true);
    }

    /**
     * The household's time zone as an ATA 19x knows it: Cisco's own code —
     * offset, then which daylight saving rule — from its provisioning guide.
     * A zone not here leaves the adapter's.
     */
    private const ATA_TIME_ZONES = [
        'Europe/London' => '+00 2 2', 'Europe/Dublin' => '+00 2 2', 'Europe/Lisbon' => '+00 2 2',
        'Europe/Paris' => '+01 2 2', 'Europe/Berlin' => '+01 2 2', 'Europe/Rome' => '+01 2 2', 'Europe/Madrid' => '+01 2 2',
        'Europe/Amsterdam' => '+01 2 2', 'Europe/Brussels' => '+01 2 2', 'Europe/Vienna' => '+01 2 2', 'Europe/Prague' => '+01 2 2',
        'Europe/Copenhagen' => '+01 2 2', 'Europe/Stockholm' => '+01 2 2', 'Europe/Oslo' => '+01 2 2', 'Europe/Zurich' => '+01 2 2',
        'Europe/Warsaw' => '+01 2 2', 'Europe/Budapest' => '+01 2 2', 'Europe/Luxembourg' => '+01 2 2',
        'Europe/Athens' => '+02 2 2', 'Europe/Bucharest' => '+02 2 2', 'Europe/Kyiv' => '+02 2 2', 'Europe/Kiev' => '+02 2 2',
        'Europe/Helsinki' => '+02 2 2', 'Europe/Riga' => '+02 2 2', 'Europe/Tallinn' => '+02 2 2', 'Africa/Johannesburg' => '+02 1 0',
        'Asia/Kolkata' => '+05.5 1 0', 'Asia/Shanghai' => '+08 3 0', 'Asia/Hong_Kong' => '+08 3 0', 'Asia/Singapore' => '+08 4 0',
        'Asia/Taipei' => '+08 4 0', 'Asia/Tokyo' => '+09 1 0', 'Asia/Seoul' => '+09 1 0', 'Australia/Perth' => '+08 1 4',
        'Australia/Adelaide' => '+09.5 1 10', 'Australia/Sydney' => '+10 2 4', 'Australia/Melbourne' => '+10 2 4',
        'Australia/Hobart' => '+10 2 4', 'Pacific/Auckland' => '+12 2 4',
        'America/St_Johns' => '-03.5 1 1', 'America/Halifax' => '-04 2 1', 'America/New_York' => '-05 2 1', 'America/Toronto' => '-05 2 1',
        'America/Detroit' => '-05 2 1', 'America/Chicago' => '-06 2 1', 'America/Winnipeg' => '-06 2 1', 'America/Mexico_City' => '-06 1 5',
        'America/Denver' => '-07 2 1', 'America/Edmonton' => '-07 2 1', 'America/Phoenix' => '-07 1 0', 'America/Los_Angeles' => '-08 1 1',
        'America/Vancouver' => '-08 1 1', 'America/Anchorage' => '-09 1 1', 'Pacific/Honolulu' => '-10 1 0',
    ];

    /** An ATA 19x's time zone code, or null to leave it. */
    public static function ataTimeZone(string $tz): ?string
    {
        return self::ATA_TIME_ZONES[$tz] ?? null;
    }

    /**
     * The star-code services a corded phone could turn on by itself: call
     * forwarding, Do Not Disturb, call return and the rest. Caller ID and
     * the message light aren't among them.
     */
    private const STAR_SERVICES = ['Block_CID', 'Block_ANC', 'Dist_Ring', 'Cfwd_All', 'Cfwd_Busy', 'Cfwd_No_Ans', 'Cfwd_Sel',
        'Cfwd_Last', 'Block_Last', 'Accept_Last', 'DND', 'Call_Return', 'Call_Redial', 'Call_Back', 'Speed_Dial',
        'Secure_Call', 'Referral', 'Feature_Dial'];

    /** A hook-flash's services: holding a call, a second line, three-way calling, transfer. */
    private const FLASH_SERVICES = ['Three_Way_Call', 'Three_Way_Conf', 'Attn_Transfer', 'Unattn_Transfer'];

    /**
     * The line itself, from the household's country: the impedance its
     * phones expect, the caller ID they read, and the ring. Europe's line
     * (TBR21) is 270+750||150nF; a country not here keeps the adapter's.
     */
    private const REGIONS = [
        '44' => ['impedance' => '270+750||150nF', 'callerId' => 'ETSI FSK With PR(UK)', 'ring' => 25],
        '1' => ['impedance' => '600', 'callerId' => 'Bellcore(N.Amer,China)', 'ring' => 20],
        '353' => ['impedance' => '270+750||150nF', 'callerId' => 'ETSI FSK'],
        '33' => ['impedance' => '270+750||150nF'], '49' => ['impedance' => '270+750||150nF'], '34' => ['impedance' => '270+750||150nF'],
        '39' => ['impedance' => '270+750||150nF'], '31' => ['impedance' => '270+750||150nF'], '32' => ['impedance' => '270+750||150nF'],
        '43' => ['impedance' => '270+750||150nF'], '41' => ['impedance' => '270+750||150nF'], '351' => ['impedance' => '270+750||150nF'],
        '46' => ['impedance' => '270+750||150nF', 'callerId' => 'DTMF(Finland,Sweden)'],
        '358' => ['impedance' => '270+750||150nF', 'callerId' => 'DTMF(Finland,Sweden)'],
        '45' => ['impedance' => '270+750||150nF', 'callerId' => 'DTMF(Denmark)'],
    ];

    private static function tag(string $name, string|int $value): string
    {
        return '  <' . $name . '>' . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $name . ">\n";
    }

    private static function yes(bool $on): string
    {
        return $on ? 'Yes' : 'No';
    }

    /** What to type into the adapter's Profile Rule: where its settings are, and the password for them. */
    public static function profileRule(?string $host = null, bool $https = false): string
    {
        $host ??= PjsipConfig::domain() . ':' . (int) (getenv('HTTP_PORT') ?: 8083);

        return '[--uid twocans --pwd ' . (new SettingsRepository())->provisionPass() . ']'
            . ($https ? 'https' : 'http') . '://' . $host . '/cisco/$MA.xml';
    }

    /**
     * The household's time zone, as the adapter writes it, with its summer
     * time rule — Europe's or North America's — or none.
     *
     * @return array{0:string,1:?string} [Time_Zone, Daylight_Saving_Time_Rule]
     */
    public static function timeZone(string $tz): array
    {
        try {
            $zone = new DateTimeZone($tz);
        } catch (Throwable) {
            return ['GMT', null];
        }
        $year = (int) date('Y');
        $winter = $zone->getOffset(new DateTimeImmutable("$year-01-15", $zone));
        $summer = $zone->getOffset(new DateTimeImmutable("$year-07-15", $zone));
        $minutes = intdiv(min($winter, $summer), 60);
        $name = $minutes === 0 ? 'GMT' : sprintf('GMT%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);
        if ($winter === $summer) {
            return [$name, null];
        }
        // The clocks change: Europe's last Sundays of March and October (at
        // 01:00 UTC, so local time differs by zone), or North America's
        // second Sunday of March and first of November, at 2am.
        if (str_starts_with($tz, 'Europe/') || str_starts_with($tz, 'Atlantic/')) {
            $at = intdiv($minutes, 60) + 1;

            return [$name, sprintf('start=3/-1/7/%d;end=10/-1/7/%d;save=1', $at, $at + 1)];
        }
        if (str_starts_with($tz, 'America/')) {
            return [$name, 'start=3/8/7/2;end=11/1/7/2;save=1'];
        }

        return [$name, null];
    }

    /**
     * An SPA112's settings file.
     *
     * @param array<int,array> $byPort PHONE port (1|2) => DeviceRepository::toView() shape
     * @param ?string $host how the adapter reached twocans (Host:), so it keeps coming back the same way
     */
    public function xml(array $byPort, ?string $host = null, bool $https = false): string
    {
        ksort($byPort);
        $first = $byPort !== [] ? reset($byPort) : [];
        $type = (string) ($first['type'] ?? 'spa112');
        $ata = self::isAta19x($type);
        $box = PhoneSettings::for(['type' => $type] + $first);
        $region = self::REGIONS[ContactRepository::countryCode()] ?? [];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . "<!-- twocans: made by twocans and fetched again on every restart; change things on twocans' Phone settings tab. -->\n"
            . "<flat-profile>\n";

        // Where it fetches these from: the same, every restart and daily, and
        // when twocans sends a NOTIFY (see GrandstreamProvisioning::notify).
        $xml .= self::tag('Provision_Enable', 'Yes');
        $xml .= self::tag('Resync_On_Reset', 'Yes');
        $xml .= self::tag('Resync_Periodic', 86400);
        $xml .= self::tag('Resync_From_SIP', 'Yes');
        if ($host !== null && $host !== '') {
            $xml .= self::tag('Profile_Rule', self::profileRule($host, $https));
        }
        $xml .= self::tag('Upgrade_Enable', self::yes((bool) $box['firmware_checks']));
        if (!$ata) {
            $xml .= self::tag('Protect_IVR_FactoryReset', self::yes(!$box['keypad_reset']));

            // The household's time, for the date and time caller ID sends.
            // (An ATA 19x's is in its router configuration, at the end.)
            [$zone, $dst] = self::timeZone(PjsipConfig::timezone());
            $xml .= self::tag('Time_Zone', $zone);
            $xml .= self::tag('Daylight_Saving_Time_Enable', self::yes($dst !== null));
            if ($dst !== null) {
                $xml .= self::tag('Daylight_Saving_Time_Rule', $dst);
            }
        }

        // The line: your country's, unless chosen.
        if (isset($region['impedance'])) {
            // The ATA 19x hasn't Europe's 270+750||150nF; its nearest is 220+820||115nF.
            $xml .= self::tag('FXS_Port_Impedance', $ata && $region['impedance'] === '270+750||150nF' ? '220+820||115nF' : $region['impedance']);
        }
        if (isset($region['ring'])) {
            $xml .= self::tag('Ring_Frequency', $region['ring']);
        }
        $callerId = $box['caller_id'] === 'auto' ? ($region['callerId'] ?? null) : (string) $box['caller_id'];
        if ($callerId !== null) {
            $xml .= self::tag('Caller_ID_Method', $callerId);
            // ETSI's FSK is V.23; North America's is Bell 202.
            $xml .= self::tag('Caller_ID_FSK_Standard', str_starts_with($callerId, 'ETSI FSK') ? 'v.23' : 'bell 202');
        }
        foreach (['earpiece' => 'FXS_Port_Output_Gain', 'mouthpiece' => 'FXS_Port_Input_Gain'] as $key => $tag) {
            $xml .= self::tag($tag, $box[$key] === 'factory' ? -3 : (int) $box[$key]);
        }
        // How long after the last number it rings: # rings straight away.
        $xml .= self::tag('Interdigit_Short_Timer', (int) $box['dial_wait']);
        $xml .= self::tag('Interdigit_Long_Timer', (int) $box['dial_wait']);

        foreach (range(1, self::PORTS) as $n) {
            $d = $byPort[$n] ?? null;
            if ($d === null) {
                // No twocans phone in it: switched off, so an old account can't register.
                $xml .= self::tag("Line_Enable_{$n}_", 'No');
                continue;
            }
            $o = PhoneSettings::for(['type' => $type] + $d);
            $xml .= self::tag("Line_Enable_{$n}_", 'Yes');
            $xml .= self::tag("Proxy_{$n}_", GrandstreamProvisioning::server());
            $xml .= self::tag("Use_Outbound_Proxy_{$n}_", 'No');
            $xml .= self::tag("Register_{$n}_", 'Yes');
            $xml .= self::tag("Register_Expires_{$n}_", 3600);
            $xml .= self::tag("SIP_Transport_{$n}_", 'UDP');
            $xml .= self::tag("SIP_Port_{$n}_", $n === 1 ? 5060 : 5061);
            $xml .= self::tag("Display_Name_{$n}_", $d['name']);
            $xml .= self::tag("User_ID_{$n}_", $d['sipUsername']);
            $xml .= self::tag("Password_{$n}_", $d['sipSecret']);
            $xml .= self::tag("Use_Auth_ID_{$n}_", 'No');

            // Picked up and nothing dialled: after a few seconds, it rings the
            // grown-up chosen for it (its hotline). Otherwise any number.
            $hotline = preg_replace('/[^0-9*#+]/', '', (string) ($d['hotline'] ?? '')) ?? '';
            $xml .= self::tag("Dial_Plan_{$n}_", $hotline !== ''
                ? '(P' . max(1, min(9, (int) ($d['hotlineDelay'] ?? 4))) . '<:' . $hotline . '>|x.)'
                : '(x.)');

            $xml .= self::tag("Call_Waiting_Serv_{$n}_", self::yes((bool) $o['call_waiting']));
            $xml .= self::tag("CW_Setting_{$n}_", self::yes((bool) $o['call_waiting']));
            $xml .= self::tag("CWCID_Serv_{$n}_", self::yes((bool) $o['call_waiting']));
            foreach (self::FLASH_SERVICES as $service) {
                $xml .= self::tag("{$service}_Serv_{$n}_", self::yes((bool) $o['hook_flash']));
            }
            foreach (self::STAR_SERVICES as $service) {
                $xml .= self::tag("{$service}_Serv_{$n}_", self::yes((bool) $o['star_codes']));
            }
            $xml .= self::tag("DND_Setting_{$n}_", 'No');
            $xml .= self::tag("CID_Serv_{$n}_", 'Yes');
            $xml .= self::tag("MWI_Serv_{$n}_", 'Yes');
            $xml .= self::tag("VMWI_Serv_{$n}_", self::yes((bool) $o['message_light']));
        }

        // An ATA 19x's clock: Cisco's time zone code, changing for summer by itself.
        $zone = $ata ? self::ataTimeZone(PjsipConfig::timezone()) : null;
        if ($zone !== null) {
            $xml .= "  <router-configuration>\n    <Time_Setup>\n"
                . '  ' . self::tag('Time_Zone', $zone)
                . '  ' . self::tag('Auto_Adjust_Clock', 1)
                . '  ' . self::tag('Time_Server_Mode', 'auto')
                . '  ' . self::tag('Auto_Recovery_System_Time', 1)
                . "    </Time_Setup>\n  </router-configuration>\n";
        }

        return $xml . "</flat-profile>\n";
    }
}
