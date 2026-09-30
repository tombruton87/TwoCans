<?php
declare(strict_types=1);

/**
 * The household's hold music — see HoldMusic. In a folder of its own inside
 * the refusals volume, which Asterisk already reads, as
 * /var/lib/twocans/refusals/moh: the directory of the "twocans" music class.
 */
final class HoldMusicStore extends AudioStore
{
    /** A song, or a few: long enough for anyone kept waiting. */
    private const MAX_SECONDS = 600;

    /** `storage/refusals/moh`. HOLD_MUSIC_PATH moves it. */
    public function path(): string
    {
        $override = trim((string) (getenv('HOLD_MUSIC_PATH') ?: ''));
        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim(getenv('REFUSALS_PATH') ?: '/var/lib/twocans/refusals', '/') . '/moh';
    }

    protected function maxSeconds(): int
    {
        return self::MAX_SECONDS;
    }

    protected function noun(): string
    {
        return 'hold track';
    }
}
