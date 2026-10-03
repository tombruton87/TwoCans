<?php
declare(strict_types=1);

/**
 * How many sleeps until Christmas — a number a child dials (1225 by default)
 * to hear Santa count down: "Ho ho ho! … There are 57 sleeps until
 * Christmas!" On Christmas Eve, one more sleep; on Christmas Day, Santa's
 * message — the household's own recording, or the one twocans ships.
 *
 * And, if a grown-up switches it on, Santa rings the children's phones on
 * Christmas morning at the time they choose (ringIfDue(), from bin/minute.php).
 *
 * The countdown itself is worked out in the dialplan (PjsipConfig::
 * renderChristmas), so it's right every day without anything being
 * rewritten; sleeps() is the same sum, for the web page.
 */
final class Christmas
{
    /** The caller ID Santa rings with. */
    public const CALLER = '"Santa" <santa>';

    /** Where Santa's own voice lives, as Asterisk sees it — see storage/defaults/README.md. */
    public const SOUNDS = PjsipConfig::DEFAULTS_DIR . '/christmas';

    /** Sleeps from $today until the next Christmas Day: 0 on the day itself. */
    public static function sleeps(DateTimeImmutable $today): int
    {
        $day = $today->setTime(12, 0);
        $christmas = $day->setDate((int) $day->format('Y'), 12, 25);
        if ($christmas < $day) {
            $christmas = $christmas->modify('+1 year');
        }

        return (int) round(($christmas->getTimestamp() - $day->getTimestamp()) / 86400);
    }

    /** What Santa says today, in words, for the web page. */
    public static function saying(DateTimeImmutable $today): string
    {
        return match ($n = self::sleeps($today)) {
            0 => "It's Christmas Day — Santa's message plays.",
            1 => '“It\'s Christmas Eve! Just one more sleep until Christmas!”',
            default => '“Ho ho ho! Hello there, it\'s Santa! There are ' . $n . ' sleeps until Christmas!”',
        };
    }

    /**
     * The phones Santa rings: the children's — not one in adult mode, not
     * one with incoming calls switched off, and only one that can be rung.
     *
     * @return array<int,array> device views
     */
    public static function childPhones(): array
    {
        return array_values(array_filter(
            array_map([DeviceRepository::class, 'toView'], (new DeviceRepository())->all()),
            static fn(array $d): bool => $d['available'] && $d['sipUsername'] !== '' && !$d['adult'] && $d['allowIn']
        ));
    }

    /**
     * On Christmas morning, once the chosen time comes, ring the children's
     * phones with Santa's message — once a year.
     *
     * @return array<int,string> the phones rung
     */
    public static function ringIfDue(?DateTimeImmutable $now = null, ?SettingsRepository $settings = null): array
    {
        $settings ??= new SettingsRepository();
        $now ??= new DateTimeImmutable('now', new DateTimeZone(PjsipConfig::timezone()));

        if (!$settings->santaRings() || $now->format('m-d') !== '12-25'
            || $now->format('H:i') < $settings->santaRingTime()
            || $settings->santaRangYear() === $now->format('Y')) {
            return [];
        }
        // Noted first: a failure ringing shouldn't mean ringing again every minute.
        $settings->setSantaRangYear($now->format('Y'));

        $rung = [];
        try {
            $ami = new Ami();
            $ami->connect();
            foreach (self::childPhones() as $d) {
                $ami->originate('PJSIP/' . $d['sipUsername'], PjsipConfig::CHRISTMAS_CONTEXT, 'santa', self::CALLER, 45);
                $rung[] = $d['name'];
            }
            $ami->disconnect();
        } catch (Throwable) {
            // Asterisk not there: nothing more to do this year.
        }

        return $rung;
    }
}
