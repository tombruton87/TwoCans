<?php
declare(strict_types=1);

/**
 * Self-service links: a link a parent sends to one person — a grandparent,
 * usually — so they can add their own photo and say their own name for the
 * kids' phones, without an account.
 *
 * The token in the link is the whole credential, so it is random, one per
 * person, and short-lived; all it can do is set that one person's photo and
 * name clip. See migration 045, and index.php for the page itself.
 */
final class ContactLinkRepository
{
    /**
     * How long a link works for: a week to get round to it. Checked against
     * when it was made as well as its own expiry, so a link made while this
     * was longer is held to it too.
     */
    public const DAYS = 7;

    private const LIVE = 'l.expires_at > NOW() AND l.created_at > NOW() - INTERVAL ' . self::DAYS . ' DAY';

    /** Make (or remake) the link for one person. Returns the token. */
    public function create(int $contactId): string
    {
        $token = bin2hex(random_bytes(16));
        Database::pdo()->prepare(
            'REPLACE INTO contact_links (contact_id, token, expires_at, created_at, used_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . self::DAYS . ' DAY), NOW(), NULL)'
        )->execute([$contactId, $token]);

        return $token;
    }

    public function revoke(int $contactId): void
    {
        Database::pdo()->prepare('DELETE FROM contact_links WHERE contact_id = ?')->execute([$contactId]);
    }

    /**
     * The person's link while it still works, or null.
     *
     * @return array{token:string,expiresAt:string,usedAt:?string}|null
     */
    public function forContact(int $contactId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT token, LEAST(expires_at, created_at + INTERVAL ' . self::DAYS . ' DAY) AS expires_at, used_at
             FROM contact_links l WHERE contact_id = ? AND ' . self::LIVE
        );
        $st->execute([$contactId]);
        $row = $st->fetch();

        return $row ? [
            'token' => (string) $row['token'],
            'expiresAt' => (string) $row['expires_at'],
            'usedAt' => $row['used_at'] === null ? null : (string) $row['used_at'],
        ] : null;
    }

    /** The person a working token belongs to — never a group — or null. */
    public function contactFor(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT c.* FROM contact_links l JOIN contacts c ON c.id = l.contact_id
             WHERE l.token = ? AND ' . self::LIVE . ' AND c.is_group = 0'
        );
        $st->execute([$token]);

        return $st->fetch() ?: null;
    }

    /** Note that they've used it, for the parent to see. */
    public function markUsed(int $contactId): void
    {
        Database::pdo()->prepare('UPDATE contact_links SET used_at = NOW() WHERE contact_id = ?')->execute([$contactId]);
    }

    /**
     * Where a link must point: the house's outside address when it has a
     * proper certificate — a grandparent opens it from their own home, and
     * recording in a browser needs HTTPS — else the address this page was
     * reached on.
     */
    public static function base(): string
    {
        $certs = new Certificates();
        $domain = $certs->domain();
        $status = $certs->status();
        if ($domain !== null && $status['exists'] && !$status['selfSigned'] && $status['daysLeft'] >= 0) {
            $port = (int) (getenv('HTTPS_PORT') ?: 443);

            return 'https://' . $domain . ($port === 443 ? '' : ':' . $port);
        }
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /** Whether base() is reachable from outside the house. */
    public static function isPublic(): bool
    {
        return str_starts_with(self::base(), 'https://') && (new Certificates())->domain() !== null
            && str_contains(self::base(), (string) (new Certificates())->domain());
    }

    public static function url(string $token): string
    {
        return self::base() . '/hello/' . $token;
    }
}
