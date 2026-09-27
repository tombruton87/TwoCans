<?php
declare(strict_types=1);

/**
 * The recordings announcements play — see AnnouncementRepository.
 *
 * In a folder of its own inside the refusals volume, like the other clips
 * Asterisk plays, so an existing install needs no new mount.
 */
final class AnnouncementStore extends AudioStore
{
    /** Long enough for "dinner's on the table, come down and wash your hands". */
    private const MAX_SECONDS = 60;

    /** `storage/refusals/announcements`. ANNOUNCEMENTS_PATH moves it. */
    public function path(): string
    {
        $override = trim((string) (getenv('ANNOUNCEMENTS_PATH') ?: ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim(getenv('REFUSALS_PATH') ?: '/var/lib/twocans/refusals', '/') . '/announcements';
    }

    protected function maxSeconds(): int
    {
        return self::MAX_SECONDS;
    }

    protected function noun(): string
    {
        return 'announcement';
    }

    protected function nowhereToSave(): string
    {
        return "Couldn't save that announcement — nothing is writable at " . $this->path() . '. '
             . "It lives inside the refusals folder, mounted from './storage/refusals' "
             . '(see the compose file) and read read-only by Asterisk.';
    }
}
