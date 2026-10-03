<?php
declare(strict_types=1);

/**
 * The songs on the radio — see Radio. Converted like every other clip the
 * phones play (AudioStore): a phone call is narrowband, so a song sounds like
 * a song down a phone.
 *
 * In a folder of its own inside the refusals volume, like announcements, so
 * an existing install needs no new mount.
 */
final class RadioStore extends AudioStore
{
    /** Long enough for any song; short enough that nobody parks an album on it. */
    private const MAX_SECONDS = 900;

    /** `storage/refusals/radio`. RADIO_PATH moves it. */
    public function path(): string
    {
        $override = trim((string) (getenv('RADIO_PATH') ?: ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim(getenv('REFUSALS_PATH') ?: '/var/lib/twocans/refusals', '/') . '/radio';
    }

    protected function maxSeconds(): int
    {
        return self::MAX_SECONDS;
    }

    protected function noun(): string
    {
        return 'song';
    }
}
