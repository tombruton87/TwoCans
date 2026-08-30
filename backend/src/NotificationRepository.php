<?php
declare(strict_types=1);

/**
 * The household's notification setup — the single row (id = 1) in
 * `notifications`, plus the state the notifier needs to report each event once.
 *
 * Shaped like TrunkRepository / DynamicDnsRepository: one row, a view-shaped
 * get(), a save() that validates, and the Mailgun API key encrypted at rest
 * with APP_KEY (a key that can send email as the household is worth as much to
 * an attacker as the trunk's auth token).
 */
final class NotificationRepository
{
    private const ID = 1;

    /** Read the setup as the array shape the views expect. Never the key itself. */
    public function get(): array
    {
        $row = $this->row();
        $hasKey = ($row['mailgun_api_key_enc'] ?? null) !== null && ($row['mailgun_api_key_enc'] ?? '') !== '';

        return [
            'enabled' => (bool) ($row['enabled'] ?? false),
            'region' => (string) ($row['mailgun_region'] ?? 'us'),
            'domain' => (string) ($row['mailgun_domain'] ?? ''),
            'from' => (string) ($row['mailgun_from'] ?? ''),
            'to' => (string) ($row['mailgun_to'] ?? ''),
            'kumaUrl' => (string) ($row['uptime_kuma_url'] ?? ''),
            'notifyAsks' => (bool) ($row['notify_asks'] ?? true),
            'notifyOffline' => (bool) ($row['notify_offline'] ?? true),
            'notifyLowCredit' => (bool) ($row['notify_low_credit'] ?? true),
            'mailgunConfigured' => $hasKey
                && ($row['mailgun_domain'] ?? '') !== ''
                && ($row['mailgun_from'] ?? '') !== ''
                && ($row['mailgun_to'] ?? '') !== '',
            'hasKey' => $hasKey,
            'lastError' => ($row['last_error'] ?? '') === '' ? null : (string) $row['last_error'],
            'lastRunAt' => $row['last_run_at'] ?? null,
        ];
    }

    /** @return array{ok:bool,error?:string} */
    public function save(array $input): array
    {
        $enabled = !empty($input['enabled']);
        $region = ($input['mailgun_region'] ?? 'us') === 'eu' ? 'eu' : 'us';
        $notifyAsks = !empty($input['notify_asks']);
        $notifyOffline = !empty($input['notify_offline']);
        $notifyLowCredit = !empty($input['notify_low_credit']);

        $domainInput = trim((string) ($input['mailgun_domain'] ?? ''));
        $fromInput = trim((string) ($input['mailgun_from'] ?? ''));
        $toInput = trim((string) ($input['mailgun_to'] ?? ''));
        $kumaInput = trim((string) ($input['uptime_kuma_url'] ?? ''));

        $domain = self::normalizeDomain($domainInput);
        $from = self::emailAddress($fromInput);
        $to = self::normalizeEmails($toInput);
        $kumaUrl = self::normalizeKumaUrl($kumaInput);

        if ($domainInput !== '' && $domain === '') {
            return ['ok' => false, 'error' => 'That Mailgun domain does not look right.'];
        }
        if ($fromInput !== '' && $from === null) {
            return ['ok' => false, 'error' => 'That "from" address does not look right.'];
        }
        if ($toInput !== '' && $to === '') {
            return ['ok' => false, 'error' => 'Enter at least one valid "to" email address.'];
        }
        if ($kumaInput !== '' && $kumaUrl === '') {
            return ['ok' => false, 'error' => 'That Uptime Kuma URL does not look right — it should start with http(s)://.'];
        }

        $from = $from ?? '';

        // A blank key keeps the stored one — the form never shows it back.
        $key = trim((string) ($input['mailgun_api_key'] ?? ''));
        $keyEnc = ($this->row()['mailgun_api_key_enc'] ?? null);
        if ($key !== '') {
            $keyEnc = Crypto::encrypt($key);
        }

        Database::pdo()->prepare(
            'INSERT INTO notifications
                (id, enabled, mailgun_api_key_enc, mailgun_region, mailgun_domain, mailgun_from, mailgun_to,
                 uptime_kuma_url, notify_asks, notify_offline, notify_low_credit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                mailgun_api_key_enc = VALUES(mailgun_api_key_enc),
                mailgun_region = VALUES(mailgun_region),
                mailgun_domain = VALUES(mailgun_domain),
                mailgun_from = VALUES(mailgun_from),
                mailgun_to = VALUES(mailgun_to),
                uptime_kuma_url = VALUES(uptime_kuma_url),
                notify_asks = VALUES(notify_asks),
                notify_offline = VALUES(notify_offline),
                notify_low_credit = VALUES(notify_low_credit)'
        )->execute([
            self::ID, $enabled ? 1 : 0, $keyEnc, $region, $domain, $from, $to, $kumaUrl,
            $notifyAsks ? 1 : 0, $notifyOffline ? 1 : 0, $notifyLowCredit ? 1 : 0,
        ]);

        return ['ok' => true];
    }

    public function setEnabled(bool $on): void
    {
        Database::pdo()->prepare('UPDATE notifications SET enabled = ? WHERE id = ?')
            ->execute([$on ? 1 : 0, self::ID]);
    }

    /** @throws RuntimeException when APP_KEY changed since the key was saved. */
    public function apiKey(): ?string
    {
        $enc = $this->row()['mailgun_api_key_enc'] ?? null;
        if ($enc === null || $enc === '') {
            return null;
        }

        return Crypto::decrypt($enc);
    }

    // -------------------------------------------------------------- state

    public function lastAskId(): int
    {
        return (int) ($this->row()['last_ask_id'] ?? 0);
    }

    public function setLastAskId(int $id): void
    {
        Database::pdo()->prepare('UPDATE notifications SET last_ask_id = ? WHERE id = ?')
            ->execute([$id, self::ID]);
    }

    public function lowCreditAlerted(): bool
    {
        return (bool) ($this->row()['last_low_credit_alerted'] ?? false);
    }

    public function setLowCreditAlerted(bool $alerted): void
    {
        Database::pdo()->prepare('UPDATE notifications SET last_low_credit_alerted = ? WHERE id = ?')
            ->execute([$alerted ? 1 : 0, self::ID]);
    }

    /** @return array<int,int> device_id => 1 when it was online last run */
    public function lastOnline(): array
    {
        $decoded = json_decode((string) ($this->row()['last_online_json'] ?? ''), true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /** @param array<int,int> $map device_id => 1/0 */
    public function setLastOnline(array $map): void
    {
        Database::pdo()->prepare('UPDATE notifications SET last_online_json = ? WHERE id = ?')
            ->execute([json_encode($map), self::ID]);
    }

    public function recordRun(?string $error): void
    {
        Database::pdo()->prepare('UPDATE notifications SET last_error = ?, last_run_at = NOW() WHERE id = ?')
            ->execute([$error ?? '', self::ID]);
    }

    // ------------------------------------------------------------ helpers

    public static function normalizeDomain(string $input): string
    {
        $domain = mb_strtolower(trim($input));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0];
        $domain = trim($domain, '.');

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)
            ? $domain
            : '';
    }

    public static function normalizeEmails(string $input): string
    {
        $emails = [];
        foreach (explode(',', $input) as $part) {
            $email = self::emailAddress($part);
            if ($email !== null) {
                $emails[] = $email;
            }
        }

        return implode(', ', array_unique($emails));
    }

    private static function emailAddress(string $input): ?string
    {
        $email = mb_strtolower(trim($input));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function normalizeKumaUrl(string $input): string
    {
        return preg_match('#^https?://#i', trim($input)) ? trim($input) : '';
    }

    private function row(): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM notifications WHERE id = ?');
        $st->execute([self::ID]);

        return $st->fetch() ?: [];
    }
}
