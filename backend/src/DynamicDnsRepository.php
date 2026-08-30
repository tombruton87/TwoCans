<?php
declare(strict_types=1);

/**
 * The household's dynamic DNS setup — the single row (id = 1) in `dynamic_dns`.
 *
 * Config and state only: what name to keep pointed here, and what the last check
 * saw. The doing lives in DynamicDns. The Cloudflare API token is encrypted at
 * rest with APP_KEY (see Crypto), because a token that can rewrite a whole
 * zone's DNS is worth as much to an attacker as the trunk's auth token.
 *
 * Shaped like TrunkRepository on purpose: one row, a view-shaped get(), and a
 * connect() that verifies before it saves, so nothing is ever stored that has
 * not been proven to work.
 */
final class DynamicDnsRepository
{
    private const ID = 1;

    /** Read the setup as the array shape the views expect. */
    public function get(): array
    {
        $row = $this->row();
        $error = trim((string) ($row['last_error'] ?? ''));

        return [
            'enabled' => (bool) ($row['enabled'] ?? false),
            // Enabled and actually usable are different things: a row can be on
            // with its token unreadable after an APP_KEY change.
            'configured' => ($row['hostname'] ?? '') !== '' && ($row['api_token_enc'] ?? null) !== null,
            'provider' => (string) ($row['provider'] ?? 'Cloudflare'),
            'zone' => (string) ($row['zone_name'] ?? ''),
            'zoneId' => (string) ($row['zone_id'] ?? ''),
            'hostname' => (string) ($row['hostname'] ?? ''),
            'recordId' => (string) ($row['record_id'] ?? ''),
            'recordType' => (string) ($row['record_type'] ?? 'A'),
            'ttl' => (int) ($row['ttl'] ?? Cloudflare::MIN_TTL),
            'proxied' => (bool) ($row['proxied'] ?? false),
            'ip' => (string) ($row['last_ip'] ?? ''),
            'checkedAt' => $row['last_checked_at'] ?? null,
            'updatedAt' => $row['last_updated_at'] ?? null,
            'error' => $error === '' ? null : $error,
        ];
    }

    public function isEnabled(): bool
    {
        $config = $this->get();

        return $config['enabled'] && $config['configured'];
    }

    /**
     * The stored API token.
     *
     * @throws RuntimeException when APP_KEY has changed since it was saved.
     */
    public function apiToken(): ?string
    {
        $encrypted = $this->row()['api_token_enc'] ?? null;

        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        return Crypto::decrypt($encrypted);
    }

    /**
     * Verify a token and domain, then save them and switch dynamic DNS on.
     *
     * Nothing is written until Cloudflare has confirmed both the token and that
     * it can see the zone — a saved setup that has never worked is worse than no
     * setup at all, because it looks finished. The record itself is left to the
     * first check, which knows this network's address.
     *
     * @param  array{token:string,zone:string,hostname:string,ttl?:int} $input
     * @return array{ok:bool,error:?string}
     */
    public function connect(array $input): array
    {
        $token = trim((string) ($input['token'] ?? ''));
        $zone = self::normalizeZone((string) ($input['zone'] ?? ''));
        $hostname = self::normalizeHostname((string) ($input['hostname'] ?? ''), $zone);
        $ttl = max(Cloudflare::MIN_TTL, (int) ($input['ttl'] ?? Cloudflare::MIN_TTL));

        // A blank token means "keep the one already saved". Editing a name should
        // not force the key to be pasted again; the first save has nothing to fall
        // back on, so it still has to be entered once.
        if ($token === '') {
            try {
                $token = $this->apiToken() ?? '';
            } catch (RuntimeException $e) {
                return ['ok' => false, 'error' => 'Could not read the saved token — ' . $e->getMessage()];
            }
        }
        if ($token === '') {
            return ['ok' => false, 'error' => 'Paste the Cloudflare API token.'];
        }
        if ($zone === '') {
            return ['ok' => false, 'error' => "That domain doesn't look right — use the bare domain, like example.com."];
        }
        if ($hostname === '') {
            return [
                'ok' => false,
                'error' => 'Set the external address above first — it must sit inside '
                    . $zone . ', like phone.' . $zone . '.',
            ];
        }

        $cloudflare = new Cloudflare($token);

        // zoneId() doubles as the token check: a wrong token, or one without
        // Zone → Zone → Read, cannot list the zone — so nothing is saved until
        // this succeeds. There is no separate "verify token" call.
        $zoneLookup = $cloudflare->zoneId($zone);
        if (!$zoneLookup['ok']) {
            return ['ok' => false, 'error' => $zoneLookup['error']];
        }

        Database::pdo()->prepare(
            "INSERT INTO dynamic_dns
                (id, enabled, provider, zone_name, zone_id, hostname, record_id,
                 record_type, ttl, proxied, api_token_enc,
                 last_ip, last_checked_at, last_updated_at, last_error)
             VALUES (?, 1, 'Cloudflare', ?, ?, ?, NULL, 'A', ?, 0, ?, NULL, NULL, NULL, NULL)
             ON DUPLICATE KEY UPDATE
                enabled = 1,
                provider = 'Cloudflare',
                zone_name = VALUES(zone_name),
                zone_id = VALUES(zone_id),
                hostname = VALUES(hostname),
                -- A different name means a different record: forget the old id
                -- rather than repointing somebody else's record at this house.
                record_id = NULL,
                record_type = 'A',
                ttl = VALUES(ttl),
                proxied = 0,
                api_token_enc = VALUES(api_token_enc),
                last_ip = NULL,
                last_checked_at = NULL,
                last_updated_at = NULL,
                last_error = NULL"
        )->execute([
            self::ID,
            $zone,
            (string) $zoneLookup['id'],
            $hostname,
            $ttl,
            Crypto::encrypt($token),
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Set the external address directly — the name that points at this house —
     * without requiring Cloudflare.
     *
     * Someone with a static IP, or another DDNS service, needs the name but not
     * the updater. The name is the thing provisioning and remote SIP actually
     * use; Cloudflare is just one way to keep its record current.
     *
     * @return array{ok:bool,error:?string,hostname:string}
     */
    public function setExternalHostname(string $input): array
    {
        $hostname = self::normalizeExternalHostname($input);

        if ($hostname === '') {
            return ['ok' => false, 'error' => 'Give a full name like phone.example.com.', 'hostname' => ''];
        }

        Database::pdo()->prepare(
            'UPDATE dynamic_dns SET hostname = ?, last_error = NULL WHERE id = ?'
        )->execute([$hostname, self::ID]);

        return ['ok' => true, 'error' => null, 'hostname' => $hostname];
    }

    /**
     * Stop updating — but remember the setup.
     *
     * The record is deliberately left in place: deleting a name a household has
     * given out — to a school, on a phone, in a bookmark — is not a side effect
     * anybody expects from a switch labelled "turn off". The token stays too, so
     * turning it back on (or editing the name) does not ask for it again; it is
     * still encrypted at rest either way.
     */
    public function disable(): void
    {
        Database::pdo()->prepare(
            'UPDATE dynamic_dns
                SET enabled = 0, last_error = NULL
              WHERE id = ?'
        )->execute([self::ID]);
    }

    /** Switch the Cloudflare updater back on after a pause. */
    public function enable(): void
    {
        Database::pdo()->prepare(
            'UPDATE dynamic_dns
                SET enabled = 1, last_error = NULL
              WHERE id = ?'
        )->execute([self::ID]);
    }

    /** A check happened and the address was already right. */
    public function recordCheck(string $ip): void
    {
        Database::pdo()->prepare(
            'UPDATE dynamic_dns
                SET last_ip = ?, last_checked_at = NOW(), last_error = NULL
              WHERE id = ?'
        )->execute([$ip, self::ID]);
    }

    /**
     * The record is now known to hold this address.
     *
     * Also stamped when a check finds the record already correct, because
     * `last_updated_at` is what the daily re-confirmation is measured against —
     * it means "last known good", not "last written".
     */
    public function recordConfirmed(string $ip, string $recordId): void
    {
        Database::pdo()->prepare(
            'UPDATE dynamic_dns
                SET last_ip = ?, record_id = ?, last_checked_at = NOW(),
                    last_updated_at = NOW(), last_error = NULL
              WHERE id = ?'
        )->execute([$ip, $recordId, self::ID]);
    }

    /**
     * A check ran and failed.
     *
     * The timestamp is stamped either way, so the interface can tell "not
     * working" from "not running" — a silent stop is the failure that matters.
     */
    public function recordError(string $message): void
    {
        Database::pdo()->prepare(
            'UPDATE dynamic_dns
                SET last_checked_at = NOW(), last_error = ?
              WHERE id = ?'
        )->execute([mb_substr($message, 0, 255), self::ID]);
    }

    /**
     * Note this network's address without pretending a Cloudflare update ran.
     *
     * Used while dynamic DNS is switched off, so the interface can still show
     * "this is the address the house is on" and prove the minute checks are
     * alive, before any setup exists.
     */
    public function recordAddress(string $ip): void
    {
        Database::pdo()->prepare(
            'UPDATE dynamic_dns SET last_ip = ?, last_checked_at = NOW() WHERE id = ?'
        )->execute([$ip, self::ID]);
    }

    /**
     * Remember a failed setup attempt for the interface to show, without
     * disturbing `last_checked_at` — this was a form submission, not a check.
     */
    public function noteError(string $message): void
    {
        Database::pdo()->prepare('UPDATE dynamic_dns SET last_error = ? WHERE id = ?')
            ->execute([mb_substr($message, 0, 255), self::ID]);
    }

    /** Remember the zone id we had to look up again. */
    public function rememberZoneId(string $zoneId): void
    {
        Database::pdo()->prepare('UPDATE dynamic_dns SET zone_id = ? WHERE id = ?')
            ->execute([$zoneId, self::ID]);
    }

    /**
     * Tidy a pasted domain into a bare zone name.
     *
     * Accepts what people actually paste — a URL, a trailing dot, capitals — and
     * returns example.com, or '' if it cannot.
     */
    public static function normalizeZone(string $input): string
    {
        $zone = mb_strtolower(trim($input));
        $zone = preg_replace('#^[a-z]+://#', '', $zone) ?? $zone;   // https://
        $zone = explode('/', $zone)[0];                             // any path
        $zone = trim($zone, '.');

        // Must be a dotted hostname: a zone always has at least one dot, and
        // anything else is a typo rather than a domain.
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $zone)) {
            return '';
        }

        return $zone;
    }

    /**
     * Tidy a pasted external address into a bare fully-qualified hostname.
     *
     * Same shape as a zone, but the name does not have to be a Cloudflare zone —
     * it can be any name that resolves here (another DDNS provider, a static IP
     * kept up to date elsewhere). Returns '' if it cannot be a hostname.
     */
    public static function normalizeExternalHostname(string $input): string
    {
        return self::normalizeZone($input);
    }

    /**
     * The full name to keep pointed here.
     *
     * Accepts a bare label ("phone") or the whole thing ("phone.example.com"),
     * because both are what somebody means, and `@` for the domain itself.
     * Anything outside the zone is refused: twocans should not be able to write
     * a record in a domain the household did not name.
     */
    public static function normalizeHostname(string $input, string $zone): string
    {
        $name = mb_strtolower(trim($input));
        $name = preg_replace('#^[a-z]+://#', '', $name) ?? $name;
        $name = explode('/', $name)[0];
        $name = trim($name, '.');

        if ($zone === '' || $name === '') {
            return '';
        }
        if ($name === '@' || $name === $zone) {
            return $zone;                          // the domain itself
        }
        if (str_ends_with($name, '.' . $zone)) {
            $label = substr($name, 0, -strlen('.' . $zone));
        } elseif (str_contains($name, '.')) {
            /*
             * A dotted name that is not inside this zone was meant for a
             * different domain. Refusing is kinder than quietly creating
             * phone.other.net.example.com and leaving somebody to wonder why
             * their name never resolves.
             */
            return '';
        } else {
            $label = $name;                        // phone → phone.example.com
        }
        if (str_contains($label, $zone)) {
            return '';                             // phone.example.com.example.com
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $label)) {
            return '';
        }

        return $label . '.' . $zone;
    }

    private function row(): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM dynamic_dns WHERE id = ?');
        $st->execute([self::ID]);

        return $st->fetch() ?: [];
    }
}
