<?php
declare(strict_types=1);

/**
 * Recordings that stand in for Asterisk's stock prompts — see Greetings.
 *
 * One folder for all of them inside the refusals volume, like the bedtime
 * message and the group greetings: every one is a clip Asterisk plays on a
 * call, and the mounts it already reads carry them without a compose change.
 */
final class GreetingStore extends AudioStore
{
    private const MAX_SECONDS = 30;

    /** `storage/refusals/greetings`. GREETINGS_PATH moves it. */
    public function path(): string
    {
        $override = trim((string) (getenv('GREETINGS_PATH') ?: ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim(getenv('REFUSALS_PATH') ?: '/var/lib/twocans/refusals', '/') . '/greetings';
    }

    protected function maxSeconds(): int
    {
        return self::MAX_SECONDS;
    }

    protected function noun(): string
    {
        return 'greeting';
    }

    protected function nowhereToSave(): string
    {
        return "Couldn't save that greeting — nothing is writable at " . $this->path() . '. '
             . "It lives inside the refusals folder, mounted from './storage/refusals' "
             . '(see the compose file) and read read-only by Asterisk.';
    }
}
