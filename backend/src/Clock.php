<?php
declare(strict_types=1);

/**
 * "What time is it?" — a number a child dials (8463, T-I-M-E, by default):
 * the time the way children learn it, to the nearest five minutes ("ten past
 * six", "quarter to seven"), and how long till bedtime when it's within two
 * hours. The dialplan works it out when the call comes in
 * (PjsipConfig::renderClock); say() is the same sum, for the web page.
 */
final class Clock
{
    /** Bedtime is mentioned when it's at most this many minutes away. */
    public const BEDTIME_WINDOW = 120;

    /**
     * When bedtime starts on each day, ISO weekday (1 Monday … 7 Sunday) =>
     * "HH:MM" — from the quiet hours' rules; a day with none isn't there.
     *
     * @return array<int,string>
     */
    public static function bedtimes(SettingsRepository $settings): array
    {
        $iso = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
        $out = [];
        foreach ($settings->quietRules() as $rule) {
            foreach ((array) ($rule['days'] ?? []) as $day) {
                if (isset($iso[$day]) && !isset($out[$iso[$day]])) {
                    $out[$iso[$day]] = (string) $rule['from'];
                }
            }
        }
        ksort($out);

        return $out;
    }

    /** The time in words, to the nearest five minutes: "ten past six", "quarter to seven". */
    public static function words(int $hour, int $minute): string
    {
        $names = [1 => 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
            20 => 'twenty', 25 => 'twenty-five'];
        $rounded = (int) (round($minute / 5) * 5);
        if ($rounded === 60) {
            $rounded = 0;
            $hour++;
        }
        $h = ($hour % 12) ?: 12;
        $next = (($hour + 1) % 12) ?: 12;

        return match (true) {
            $rounded === 0 => $names[$h] . " o'clock",
            $rounded === 15 => 'quarter past ' . $names[$h],
            $rounded === 30 => 'half past ' . $names[$h],
            $rounded === 45 => 'quarter to ' . $names[$next],
            $rounded < 30 => $names[$rounded] . ' past ' . $names[$h],
            default => $names[60 - $rounded] . ' to ' . $names[$next],
        };
    }

    /** What the line says at $now, in words, for the web page. */
    public static function say(DateTimeImmutable $now, SettingsRepository $settings): string
    {
        $text = "It's " . self::words((int) $now->format('G'), (int) $now->format('i')) . '.';
        if (!$settings->quietHours()) {
            return $text;
        }
        if (Schedule::isOn($settings->quietRules(), $now)) {
            return "It's bedtime! Night night, sleep tight!";
        }
        $at = self::bedtimes($settings)[(int) $now->format('N')] ?? null;
        if ($at === null) {
            return $text;
        }
        [$h, $m] = array_map('intval', explode(':', $at));
        $left = $h * 60 + $m - ((int) $now->format('G') * 60 + (int) $now->format('i'));
        if ($left <= 0 || $left > self::BEDTIME_WINDOW) {
            return $text;
        }

        return $text . ' Bedtime is in ' . match (true) {
            $left === 60 => 'an hour.',
            $left > 60 => 'an hour and ' . ($left - 60) . ' minutes!',
            default => $left . ' minutes!',
        };
    }
}
