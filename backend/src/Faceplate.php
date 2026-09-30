<?php
declare(strict_types=1);

/**
 * A desk phone's printable faceplate: who each hotkey rings, with their photo,
 * over the key itself. A GHP62x's is a card behind its clear cover; a GHP61x's
 * a small label strip above its three keys (STRIP_*).
 *
 * The card slides in behind the phone's clear cover. Its size and the key
 * positions come from Grandstream's own drawing of the cover
 * (GHP62x_Faceplate_Drawing, 2023/02/10) and their faceplate tool's card size,
 * all in millimetres from the card's top-left corner. The top row of keys comes
 * through the card, so it is cut there; the bottom row sits just below it.
 */
final class Faceplate
{
    /** The card, as Grandstream's faceplate tool prints it. */
    public const WIDTH = 60.95;
    public const HEIGHT = 77.0;

    /** A GHP61x's label, above its three keys: Grandstream's 35 × 8.5 mm. */
    public const STRIP_WIDTH = 35.0;
    public const STRIP_HEIGHT = 8.5;

    /** Key centres across the card: the cover's centre line, and 20.5 mm either side. */
    public const KEY_X = [1 => 9.975, 2 => 30.475, 3 => 50.975];

    /** A key's hole in the cover. */
    public const KEY_WIDTH = 10.08;
    public const KEY_HEIGHT = 5.58;

    /**
     * Where the top row of keys comes through the card, top to bottom, with
     * 0.75 mm to spare all round: the cover's holes are 24.51–30.09 mm up from
     * its bottom edge, and the card's top sits 88.6 mm up.
     */
    public const CUT_TOP = 57.76;
    public const CUT_BOTTOM = 64.84;
    public const CUT_SPARE = 0.75;

    /** Service numbers' pictures: they have no photo of their own. */
    private const SERVICE_ICONS = [
        '700' => 'fa-solid fa-voicemail',
        '600' => 'fa-solid fa-microphone',
        '601' => 'fa-solid fa-comment-dots',
        '500' => 'fa-solid fa-microphone-lines',
    ];

    /**
     * Each key: what it rings, and how to show it. Keys 1–3 are the top row,
     * 4–6 the bottom, as on the phone.
     *
     * @param array<int,string> $hotkeys key index => number
     * @return array<int,array{number:string,name:string,photo:string,initial:string,color:string,icon:string}>
     */
    public static function keys(array $hotkeys, int $count = 6): array
    {
        $describe = self::describer();
        $keys = [];
        foreach (range(1, max(1, min(6, $count))) as $index) {
            $keys[$index] = $describe((string) ($hotkeys[$index] ?? ''));
        }

        return $keys;
    }

    /**
     * How the faceplate shows whatever a key could dial, keyed by the number:
     * for the phone's page to redraw its preview as keys are picked.
     *
     * @return array<string,array{number:string,name:string,photo:string,initial:string,color:string,icon:string}>
     */
    public static function everyChoice(): array
    {
        $describe = self::describer();
        $out = [];
        foreach (array_keys((new DeviceHotkeyRepository())->contactTargets()) as $target) {
            $out[(string) $target] = $describe((string) $target);
        }
        foreach (array_keys(PjsipConfig::testNumbers()) as $number) {
            $out[(string) $number] = $describe((string) $number);
        }

        return $out;
    }

    /** A function from a number to how its key looks, looking the people up once. */
    private static function describer(): Closure
    {
        $services = PjsipConfig::testNumbers();
        $contacts = [];
        foreach ((new DeviceHotkeyRepository())->contactTargets() as $target => $row) {
            $contacts[(string) $target] = ContactRepository::toView($row);
        }

        return static function (string $number) use ($services, $contacts): array {
            $key = ['number' => $number, 'name' => '', 'photo' => '', 'initial' => '', 'color' => '', 'icon' => ''];
            if (isset($contacts[$number])) {
                $c = $contacts[$number];
                $key['name'] = $c['name'] !== '' ? $c['name'] : $number;
                $key['photo'] = $c['photo'];
                $key['initial'] = initial($key['name']);
                // A group without a photo of its own: a crowd, not a letter.
                $key['icon'] = $c['isGroup'] && $c['photo'] === '' ? 'fa-solid fa-users' : '';
                $key['color'] = $c['color'];
            } elseif (isset($services[$number])) {
                $key['name'] = (string) $services[$number]['label'];
                $key['icon'] = self::SERVICE_ICONS[$number] ?? 'fa-solid fa-face-laugh-squint';
                $key['color'] = 'var(--tc-teal)';
            } elseif ($number !== '') {
                $key['name'] = $number;
                $key['icon'] = 'fa-solid fa-phone';
                $key['color'] = 'var(--tc-ink-4)';
            }

            return $key;
        };
    }

    /** A key's centre across the card, keys 4–6 under 1–3. */
    public static function keyX(int $index): float
    {
        return self::KEY_X[($index - 1) % 3 + 1];
    }
}
