<?php
declare(strict_types=1);

/**
 * Audio for the joke line.
 *
 * A joke is a sound file on disk, named by the hash of its own contents: the
 * dialplan plays storage/jokes/<hash>, and the same clip re-added under a
 * different filename is recognised as one we already have.
 *
 * The conversion — anything in, 8kHz mono WAV out — lives in AudioStore, along
 * with the checks that stop an upload becoming a file Asterisk can't play. See
 * that class for why the format is what it is.
 */
final class JokeStore extends AudioStore
{
    /**
     * A joke is a few seconds. The cap is generous enough for a shaggy-dog
     * story and mean enough that nobody parks an audiobook on the line.
     */
    private const MAX_SECONDS = 90;

    public function path(): string
    {
        return rtrim(getenv('JOKES_PATH') ?: '/var/lib/twocans/jokes', '/');
    }

    protected function maxSeconds(): int
    {
        return self::MAX_SECONDS;
    }

    protected function noun(): string
    {
        return 'joke';
    }
}
