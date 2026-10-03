<?php
declare(strict_types=1);

/**
 * A printable sheet of who a child can call — photo, name and what to dial —
 * for the fridge, or beside a phone with no screen. A4 or A5, in a theme with
 * a picture across the top (the artwork is in assets/sheets/).
 *
 * Everyone a phone may ring: people with a number and groups with a speed
 * dial (see DeviceHotkeyRepository::contactTargets), then the twocans lines
 * a child can dial — whichever of them a grown-up ticks (LINES) — and the
 * emergency number when there's a line to reach it.
 * For one phone, it also says which of its hotkeys rings each person.
 */
final class ContactSheet
{
    /** The looks: a picture across the top, and the colours around it. */
    public const THEMES = [
        'dino' => ['label' => 'Dinosaurs', 'art' => 'assets/sheets/dino.png', 'title' => 'Who I can call'],
        'princess' => ['label' => 'Princesses', 'art' => 'assets/sheets/princess.png', 'title' => 'Who I can call'],
        'racing' => ['label' => 'Racing cars', 'art' => 'assets/sheets/racing.png', 'title' => 'Who I can call'],
        'plain' => ['label' => 'Plain', 'art' => null, 'title' => 'Our phone numbers'],
    ];

    public const PAPERS = ['a4' => 'A4', 'a5' => 'A5'];

    /**
     * Everyone on the sheet, speed dials first (the easiest to dial), then by name.
     *
     * @param int|null $deviceId a phone, to show which of its keys rings whom
     * @return array<int,array{name:string,rel:string,photo:string,initial:string,color:string,dial:string,isCode:bool,key:?int,group:bool}>
     */
    public static function people(?int $deviceId = null): array
    {
        $keys = [];
        if ($deviceId !== null) {
            foreach ((new DeviceHotkeyRepository())->forDevice($deviceId) as $index => $target) {
                $keys[$target] ??= (int) $index;
            }
        }

        $out = [];
        foreach ((new DeviceHotkeyRepository())->contactTargets() as $target => $row) {
            $c = ContactRepository::toView($row);
            if (!$c['allowOut']) {
                continue;
            }
            $code = $c['code'];
            $out[] = [
                'name' => $c['name'] !== '' ? $c['name'] : (string) $target,
                'rel' => $c['rel'],
                'photo' => $c['photo'],
                'initial' => initial($c['name'] !== '' ? $c['name'] : '?'),
                'color' => $c['color'],
                // What to dial: the speed dial when there is one — short and
                // easy — else the number itself.
                'dial' => $code !== '' ? $code : self::friendly((string) $c['number']),
                'isCode' => $code !== '',
                'key' => $keys[(string) $target] ?? null,
                'group' => $c['isGroup'],
            ];
        }
        usort($out, static fn(array $a, array $b): int => [$a['isCode'] ? 0 : 1, $a['isCode'] ? (int) $a['dial'] : 0, $a['name']]
            <=> [$b['isCode'] ? 0 : 1, $b['isCode'] ? (int) $b['dial'] : 0, $b['name']]);

        return $out;
    }

    /**
     * Every twocans line a sheet can show, in the order it shows them: what
     * it's called and its icon. 'keys': it needs keys pressed once the call's
     * started — answers, minutes, a menu — which a rotary phone's dial can't
     * do (an adapter only hears its clicks while dialling).
     */
    public const LINES = [
        'messages' => ['label' => 'My messages', 'icon' => 'fa-solid fa-voicemail', 'keys' => true],
        'house' => ['label' => "The house's messages", 'icon' => 'fa-solid fa-inbox', 'keys' => true],
        'jokes' => ['label' => 'The joke line', 'icon' => 'fa-solid fa-face-laugh-squint'],
        'games' => ['label' => 'Games', 'icon' => 'fa-solid fa-dice', 'keys' => true],
        'times' => ['label' => 'Times tables', 'icon' => 'fa-solid fa-xmark', 'keys' => true],
        'radio' => ['label' => 'The radio', 'icon' => 'fa-solid fa-radio'],
        'clock' => ['label' => 'What time is it?', 'icon' => 'fa-solid fa-clock'],
        'timer' => ['label' => 'Kitchen timer', 'icon' => 'fa-solid fa-hourglass-half', 'keys' => true],
        'silly' => ['label' => 'Silly voices', 'icon' => 'fa-solid fa-face-grin-squint-tears'],
        'walkie' => ['label' => 'Walkie-talkie', 'icon' => 'fa-solid fa-walkie-talkie'],
        'christmas' => ['label' => 'Sleeps till Christmas', 'icon' => 'fa-solid fa-tree'],
        'sos' => ['label' => 'Emergency', 'icon' => 'fa-solid fa-truck-medical'],
    ];

    /**
     * The lines this sheet could show — each one there is to dial: the house's
     * messages for a phone allowed them, the radio with songs on it, the
     * walkie-talkie for a phone that's paired (or, for every phone, when any
     * is), the emergency number with a line to reach it. Printed for a rotary
     * phone, none that need keys pressed during the call (see LINES).
     *
     * @return array<string,array{label:string,dial:string,icon:string,sos:bool,ticked:bool}>
     */
    public static function available(?int $deviceId = null): array
    {
        $settings = new SettingsRepository();
        $devices = new DeviceRepository();
        $phone = $deviceId === null ? null : $devices->find($deviceId);
        $phoneView = $phone === null ? null : DeviceRepository::toView($phone);

        $dial = [
            'messages' => $settings->voicemailSpeedDial() ?: PjsipConfig::VOICEMAIL_NUMBER,
            'house' => $phoneView !== null && $phoneView['houseMessages'] ? PjsipConfig::HOUSE_MESSAGES_NUMBER : null,
            'jokes' => $settings->jokeNumber(),
            'games' => $settings->gamesNumber(),
            'times' => $settings->quizNumber(),
            'radio' => (new Radio())->all(true) !== [] ? $settings->radioNumber() : null,
            'clock' => $settings->clockNumber(),
            'timer' => $settings->timerNumber(),
            'silly' => $settings->sillyNumber(),
            'walkie' => null,
            'christmas' => $settings->sleepsNumber(),
            'sos' => (new TrunkRepository())->get()['connected'] ? self::emergencyNumber() : null,
        ];
        foreach ($devices->all() as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['walkieTo'] !== null && ($deviceId === null || $d['id'] === $deviceId)) {
                $dial['walkie'] = $settings->walkieNumber();
            }
        }

        // The Christmas countdown is ticked in the run-up to it; the rest always.
        $season = in_array(date('n'), ['11', '12'], true) && Christmas::sleeps(new DateTimeImmutable()) > 0;
        $rotary = $phoneView !== null && self::rotary($phoneView);
        $out = [];
        foreach (self::LINES as $key => $line) {
            if ($dial[$key] === null || ($rotary && ($line['keys'] ?? false))) {
                continue;
            }
            unset($line['keys']);
            $out[$key] = $line + ['dial' => (string) $dial[$key], 'sos' => $key === 'sos', 'ticked' => $key !== 'christmas' || $season];
        }

        return $out;
    }

    /** Whether a phone is a rotary one: see GrandstreamProvisioning::ATA_SETTINGS. */
    public static function rotary(array $phoneView): bool
    {
        return (bool) (PhoneSettings::for($phoneView)['rotary'] ?? false);
    }

    /** The lines a rotary phone can't use, by name: they need keys pressed during the call. */
    public static function keyLines(): array
    {
        return array_values(array_map(static fn(array $l): string => $l['label'],
            array_filter(self::LINES, static fn(array $l): bool => $l['keys'] ?? false)));
    }

    /**
     * The lines on the sheet: the available ones that are ticked — $shown,
     * when somebody's chosen (keys of LINES), or each one's default.
     *
     * @param array<int,string>|null $shown
     * @return array<int,array{label:string,dial:string,icon:string,sos:bool}>
     */
    public static function services(?int $deviceId = null, ?array $shown = null): array
    {
        $out = [];
        foreach (self::available($deviceId) as $key => $line) {
            if ($shown === null ? $line['ticked'] : in_array($key, $shown, true)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** The emergency number to print, for where the household is. */
    public static function emergencyNumber(): string
    {
        return match (ContactRepository::countryCode()) {
            '44' => '999',
            '1' => '911',
            '61' => '000',
            default => '112',
        };
    }

    /** A number as it's written at home: +447700900123 is 07700 900123. */
    public static function friendly(string $e164): string
    {
        $cc = ContactRepository::countryCode();
        if ($cc !== '' && str_starts_with($e164, '+' . $cc)) {
            $national = '0' . substr($e164, strlen($cc) + 1);

            return strlen($national) === 11 ? substr($national, 0, 5) . ' ' . substr($national, 5) : $national;
        }

        return $e164;
    }
}
