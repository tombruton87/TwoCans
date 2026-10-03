<?php
declare(strict_types=1);

/**
 * The browsers grown-ups have asked to be notified on, and sending to them
 * all — see WebPush for how, and Notifier for what's worth telling.
 */
final class Push
{
    public function __construct(private WebPush $push = new WebPush())
    {
    }

    /** Remember a browser — or bring it up to date, if it's asked before. */
    public function subscribe(int $guardianId, string $endpoint, string $p256dh, string $auth, string $userAgent): bool
    {
        if (!str_starts_with($endpoint, 'https://') || strlen($endpoint) > 700
            || strlen(WebPush::unb64($p256dh)) !== 65 || strlen(WebPush::unb64($auth)) < 16) {
            return false;
        }
        Database::pdo()->prepare(
            'INSERT INTO push_subscriptions (guardian_id, endpoint, p256dh, auth, label) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE guardian_id = VALUES(guardian_id), p256dh = VALUES(p256dh), auth = VALUES(auth), label = VALUES(label)'
        )->execute([$guardianId, $endpoint, $p256dh, $auth, self::label($userAgent)]);

        return true;
    }

    /** @return array<int,array> every browser, or one grown-up's */
    public function all(?int $guardianId = null): array
    {
        if ($guardianId === null) {
            return Database::pdo()->query('SELECT * FROM push_subscriptions ORDER BY id')->fetchAll();
        }
        $st = Database::pdo()->prepare('SELECT * FROM push_subscriptions WHERE guardian_id = ? ORDER BY id');
        $st->execute([$guardianId]);

        return $st->fetchAll();
    }

    /** Stop notifying a browser — only one of $guardianId's own. */
    public function remove(int $id, int $guardianId): void
    {
        Database::pdo()->prepare('DELETE FROM push_subscriptions WHERE id = ? AND guardian_id = ?')->execute([$id, $guardianId]);
    }

    public function forget(string $endpoint): void
    {
        Database::pdo()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$endpoint]);
    }

    /**
     * Tell every browser — or $only's — something. A browser that's gone is
     * forgotten. Returns how many it reached.
     *
     * @param array<int,array>|null $only rows from all()
     */
    public function send(string $title, string $body, string $url = '/', bool $urgent = false, ?array $only = null, string $tag = 'twocans'): int
    {
        $sent = 0;
        foreach ($only ?? $this->all() as $row) {
            $res = $this->push->send(
                ['endpoint' => (string) $row['endpoint'], 'p256dh' => (string) $row['p256dh'], 'auth' => (string) $row['auth']],
                ['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag, 'urgent' => $urgent],
                $urgent
            );
            if ($res['ok']) {
                $sent++;
                Database::pdo()->prepare('UPDATE push_subscriptions SET last_sent_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
            } elseif ($res['gone']) {
                $this->forget((string) $row['endpoint']);
            }
        }

        return $sent;
    }

    /** "iPhone", "Android", "Mac · Chrome" — enough to tell a grown-up's devices apart. */
    public static function label(string $ua): string
    {
        $device = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'A device',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => '',
        };

        return $browser === '' ? $device : $device . ' · ' . $browser;
    }
}
