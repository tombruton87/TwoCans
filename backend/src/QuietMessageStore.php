<?php
declare(strict_types=1);

/**
 * The message a caller hears when the line has gone to sleep.
 *
 * Bedtime mode stops the phones ringing, and a caller used to be handed to
 * voicemail without being told why — Asterisk's own greeting, in a voice that
 * belongs to nobody in the house. This is the household's own recording
 * instead: "it's late, we can't take your call just now — press 5 for a joke."
 *
 * One recording for the whole line, unlike RefusalStore's one per phone. At
 * bedtime every handset is asleep, so there is no phone whose voice it would
 * be, and the whole house has the same thing to say.
 *
 * The recording lives in a folder of its own inside the refusals volume rather
 * than in a volume of its own. Both are a clip played to somebody outside the
 * house, files are named at random so they cannot collide, and a household
 * upgrading should not have to recreate its containers to record one. The
 * folder is made on the first upload — see AudioStore::convert.
 */
final class QuietMessageStore extends AudioStore
{
    /**
     * Long enough to say why, apologise and name the joke line, short enough
     * that nobody stands in a hallway listening to a house explain itself.
     */
    private const MAX_SECONDS = 30;

    /**
     * `storage/refusals/quiet`, so the mounts that already carry the phones'
     * refusal messages carry this one too. QUIET_MESSAGE_PATH moves it.
     */
    public function path(): string
    {
        $override = trim((string) (getenv('QUIET_MESSAGE_PATH') ?: ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim(getenv('REFUSALS_PATH') ?: '/var/lib/twocans/refusals', '/') . '/quiet';
    }

    protected function maxSeconds(): int
    {
        return self::MAX_SECONDS;
    }

    protected function noun(): string
    {
        return 'message';
    }

    /**
     * Named after the folder rather than the mount, because that folder is what
     * is missing when a deployment has gone wrong — and the fix is the mounts
     * the phones' refusal messages already use.
     */
    protected function nowhereToSave(): string
    {
        return "Couldn't save that message — nothing is writable at " . $this->path() . '. '
             . "It lives inside the refusals folder, mounted from './storage/refusals' "
             . '(see the compose file) and read read-only by Asterisk.';
    }
}
