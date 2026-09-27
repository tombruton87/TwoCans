<?php
declare(strict_types=1);

/**
 * A contact's name, spoken: what a child hears when they pick up a phone that
 * has no screen — "It's Nana!" — before the call connects.
 *
 * Recorded by a parent, made with Home Assistant's voice, or (later) by the
 * contact themselves. Kept in a folder of its own inside the refusals volume,
 * like QuietMessageStore and GroupPromptStore, because Asterisk already reads
 * that mount.
 */
final class CallerNameStore extends AudioStore
{
    /** A name, not a message: the caller is still waiting to be put through. */
    private const MAX_SECONDS = 8;

    /** `storage/refusals/callers`. CALLER_NAME_PATH moves it. */
    public function path(): string
    {
        $override = trim((string) (getenv('CALLER_NAME_PATH') ?: ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim(getenv('REFUSALS_PATH') ?: '/var/lib/twocans/refusals', '/') . '/callers';
    }

    protected function maxSeconds(): int
    {
        return self::MAX_SECONDS;
    }

    protected function noun(): string
    {
        return 'name clip';
    }

    protected function nowhereToSave(): string
    {
        return "Couldn't save that clip — nothing is writable at " . $this->path() . '. '
             . "It lives inside the refusals folder (see the compose file), which Asterisk reads.";
    }
}
