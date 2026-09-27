<?php
declare(strict_types=1);

/**
 * The "press 1 to join" prompt a grown-up hears when a group call rings them.
 *
 * Group calls ask whoever answers to press 1 before they join, because a
 * mobile that isn't picked up is answered by its voicemail and the network
 * reports that as a real answer — see PjsipConfig::renderConfContext(). The
 * household can record that question in its own words, once for the house and
 * optionally per group: "Maeva is calling the grannies — press 1 to join,
 * or 2 if you can't."
 *
 * Kept in a folder of its own inside the refusals volume, for the same reasons
 * QuietMessageStore is: it is a clip played to somebody outside the house, and
 * the mounts Asterisk already reads carry it without a compose change.
 */
final class GroupPromptStore extends AudioStore
{
    /** Who is calling and which key to press — any longer and they hang up. */
    private const MAX_SECONDS = 20;

    /** `storage/refusals/groups`. GROUP_PROMPT_PATH moves it. */
    public function path(): string
    {
        $override = trim((string) (getenv('GROUP_PROMPT_PATH') ?: ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim(getenv('REFUSALS_PATH') ?: '/var/lib/twocans/refusals', '/') . '/groups';
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
