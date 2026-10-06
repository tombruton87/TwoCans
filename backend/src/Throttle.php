<?php
declare(strict_types=1);

/**
 * A small limiter for the routes that sit outside the login.
 *
 * The trigger URL and a "say hello" link are credentials in themselves, and a
 * leaked one should cost a stranger something more than nothing: without this
 * they could ring every phone in the house on a loop, or fill the disk with
 * uploads. Counting is per token rather than per address, because the token is
 * what was leaked and an address is trivially changed.
 *
 * Deliberately a database table and not an in-memory counter: the web
 * container is restarted often enough that anything in memory would forget.
 */
final class Throttle
{
    /**
     * Record a hit and say whether it is over the limit.
     *
     * Sweeps its own bucket as it goes, so the table cannot grow without
     * bound and no scheduled job is needed.
     */
    public static function tooMany(string $bucket, int $limit, int $seconds): bool
    {
        $pdo = Database::pdo();

        // The window is an int from the caller, never user text — bound
        // intervals are awkward across drivers, so it is interpolated.
        $seconds = max(1, $seconds);
        $pdo->prepare("DELETE FROM rate_hits WHERE bucket = ? AND at < NOW() - INTERVAL {$seconds} SECOND")
            ->execute([$bucket]);

        $st = $pdo->prepare('SELECT COUNT(*) FROM rate_hits WHERE bucket = ?');
        $st->execute([$bucket]);
        $used = (int) $st->fetchColumn();

        if ($used >= $limit) {
            return true;
        }

        $pdo->prepare('INSERT INTO rate_hits (bucket) VALUES (?)')->execute([$bucket]);

        return false;
    }
}
