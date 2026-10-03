<?php
declare(strict_types=1);

/**
 * Santa's Christmas Day message, when the household records its own — a
 * grown-up doing the voice, with the children's names in it. Without one,
 * the line plays the one twocans ships (storage/defaults/christmas/).
 *
 * In a folder of its own inside the refusals volume, like announcements, so
 * an existing install needs no new mount.
 */
final class SantaStore extends AudioStore
{
    /** Long enough for Santa to say hello to everyone in the house. */
    private const MAX_SECONDS = 180;

    /** `storage/refusals/santa`. SANTA_PATH moves it. */
    public function path(): string
    {
        $override = trim((string) (getenv('SANTA_PATH') ?: ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim(getenv('REFUSALS_PATH') ?: '/var/lib/twocans/refusals', '/') . '/santa';
    }

    protected function maxSeconds(): int
    {
        return self::MAX_SECONDS;
    }

    protected function noun(): string
    {
        return "Santa's message";
    }
}
