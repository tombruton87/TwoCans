<?php
declare(strict_types=1);

/**
 * The household's SIP trunk — the single row (id = 1) in `trunk`.
 *
 * Provider secrets (the Twilio auth token, the SIP.IO API key) are written
 * plaintext; everything else is a normal column. `Store` delegates its trunk
 * methods here so the views keep working unchanged while the data becomes real.
 */
final class TrunkRepository
{
    private const ID = 1;

    private const PROVIDERS = ['Twilio', 'SIP.IO'];

    /** Read the trunk as the array shape the views already expect. */
    public function get(): array
    {
        $row = $this->row();
        $provider = self::normalizeProvider((string) ($row['provider'] ?? 'Twilio'));

        return [
            'connected' => (bool) ($row['connected'] ?? false),
            'provider' => $provider,
            'number' => (string) ($row['number_e164'] ?? ''),
            'balance' => (float) ($row['balance'] ?? 0.0),
            'currency' => (string) ($row['currency'] ?? '$'),
            'lowThreshold' => (float) ($row['low_threshold'] ?? 5.0),
            'minutesThisMonth' => (int) ($row['minutes_this_month'] ?? 0),
            'rate' => (string) ($row['rate'] ?? '') ?: '—',
            'autoTopUp' => (bool) ($row['auto_topup'] ?? false),
            'terminationUri' => (string) ($row['termination_uri'] ?? ''),
            'sipProxy' => (string) ($row['sip_proxy'] ?? ''),
            // Where Asterisk sends outbound calls, whichever provider is live.
            'sipHost' => $provider === 'SIP.IO'
                ? (string) ($row['sip_proxy'] ?? '')
                : (string) ($row['termination_uri'] ?? ''),
            'lastVerifiedAt' => $row['last_verified_at'] ?? null,
        ];
    }

    /** Only prepaid providers warn about running out of credit. */
    public function isLowCredit(): bool
    {
        $trunk = $this->get();

        return $trunk['connected'] && $trunk['provider'] === 'Twilio'
            && $trunk['balance'] < $trunk['lowThreshold'];
    }

    /** Decrypt the stored auth token (for future API calls). */
    public function authToken(): ?string
    {
        $encrypted = $this->row()['auth_token_enc'] ?? null;

        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        return Crypto::decrypt($encrypted);
    }

    /**
     * Verify credentials for the chosen provider, encrypt and persist them, and
     * mark the line connected. Asterisk config is left to PjsipConfig::apply().
     *
     * @return array{ok:bool,error:?string}
     */
    public function connect(array $input): array
    {
        $provider = self::normalizeProvider((string) ($input['provider'] ?? 'Twilio'));

        return $provider === 'SIP.IO' ? $this->connectSipio($input) : $this->connectTwilio($input);
    }

    /** @return array{ok:bool,error:?string} */
    private function connectTwilio(array $input): array
    {
        $sid = strtoupper(trim((string) ($input['sid'] ?? '')));
        $token = trim((string) ($input['token'] ?? ''));
        $number = self::normalizeNumber((string) ($input['number'] ?? ''));
        $termination = self::normalizeTermination((string) ($input['termination'] ?? ''));

        if (!preg_match('/^AC[0-9a-f]{32}$/i', $sid)) {
            return ['ok' => false, 'error' => "That Account SID doesn't look right — it starts with AC and is 34 characters long."];
        }
        if ($token === '') {
            return ['ok' => false, 'error' => 'Enter the auth token from the Twilio console.'];
        }
        if ($number === '') {
            return ['ok' => false, 'error' => "That phone number doesn't look right."];
        }
        if ($termination === '') {
            return ['ok' => false, 'error' => 'Enter the SIP trunk termination URI — find it in Twilio under Elastic SIP Trunking → Termination.'];
        }

        $twilio = new Twilio($sid, $token);

        $verified = $twilio->verify();
        if (!$verified['ok']) {
            return ['ok' => false, 'error' => $verified['error']];
        }

        $numberCheck = $twilio->number($number);
        if (!$numberCheck['ok']) {
            return ['ok' => false, 'error' => $numberCheck['error']];
        }

        $balance = $twilio->balance();

        Database::pdo()->prepare(
            'INSERT INTO trunk
                (id, provider, connected, number_e164, account_sid, auth_token_enc,
                 termination_uri, balance, currency, last_verified_at)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                provider = VALUES(provider),
                connected = VALUES(connected),
                number_e164 = VALUES(number_e164),
                account_sid = VALUES(account_sid),
                auth_token_enc = VALUES(auth_token_enc),
                termination_uri = VALUES(termination_uri),
                balance = VALUES(balance),
                currency = VALUES(currency),
                api_key_enc = NULL,
                sip_proxy = NULL,
                last_verified_at = VALUES(last_verified_at)'
        )->execute([
            self::ID,
            'Twilio',
            $number,
            $sid,
            Crypto::encrypt($token),
            $termination,
            sprintf('%.2F', $balance['balance']),
            $balance['currency'],
        ]);

        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok:bool,error:?string} */
    private function connectSipio(array $input): array
    {
        $apiKey = trim((string) ($input['apiKey'] ?? ''));
        $number = self::normalizeNumber((string) ($input['number'] ?? ''));
        $proxy = self::normalizeProxy((string) ($input['proxy'] ?? ''));

        if (!preg_match('/^sk_[A-Za-z0-9_-]{12,}$/', $apiKey)) {
            return ['ok' => false, 'error' => "That API key doesn't look right — it should start with sk_."];
        }
        if ($number === '') {
            return ['ok' => false, 'error' => "That phone number doesn't look right."];
        }
        if ($proxy === '') {
            return ['ok' => false, 'error' => 'Enter the SIP edge host (proxy) — find it in your SIP.IO console trunk settings.'];
        }

        $sipio = new Sipio($apiKey);

        $verified = $sipio->verify();
        if (!$verified['ok']) {
            return ['ok' => false, 'error' => $verified['error']];
        }

        if ($sipio->numberExists($number) === 'not_found') {
            return ['ok' => false, 'error' => 'That number was not found on this SIP.IO account.'];
        }

        Database::pdo()->prepare(
            'INSERT INTO trunk
                (id, provider, connected, number_e164, api_key_enc, sip_proxy, last_verified_at)
             VALUES (?, ?, 1, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                provider = VALUES(provider),
                connected = VALUES(connected),
                number_e164 = VALUES(number_e164),
                api_key_enc = VALUES(api_key_enc),
                sip_proxy = VALUES(sip_proxy),
                account_sid = NULL,
                auth_token_enc = NULL,
                termination_uri = NULL,
                last_verified_at = VALUES(last_verified_at)'
        )->execute([
            self::ID,
            'SIP.IO',
            $number,
            Crypto::encrypt($apiKey),
            $proxy,
        ]);

        return ['ok' => true, 'error' => null];
    }

    /** Normalise a pasted number to E.164. Ten-digit numbers are treated as North America. */
    public static function normalizeNumber(string $input): string
    {
        $digits = preg_replace('/\D/', '', $input) ?? '';

        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }

        return '+' . $digits;
    }

    /** Accepts a bare hostname or a full sip: URI; returns a bare hostname. */
    public static function normalizeTermination(string $input): string
    {
        $host = trim($input);
        $host = preg_replace('#^sips?:#i', '', $host) ?? $host;
        $host = rtrim($host, '/');

        if (str_contains($host, '@')) {
            $host = (string) substr($host, strpos($host, '@') + 1);
        }
        $host = trim($host, '.');

        // Hostname (possibly dotted), or an IPv4 literal.
        if ($host !== '' && !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host)) {
            return '';
        }

        return $host;
    }

    /** Accepts a bare host[:port] or a full sip: URI (the SIP.IO edge proxy). */
    public static function normalizeProxy(string $input): string
    {
        $host = trim($input);
        $host = preg_replace('#^sips?:#i', '', $host) ?? $host;
        $host = rtrim($host, '/');

        if (str_contains($host, '@')) {
            $host = (string) substr($host, strpos($host, '@') + 1);
        }
        $host = trim($host, '.');

        // Hostname or IPv4 literal, with an optional :port.
        if ($host !== '' && !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?$/i', $host)) {
            return '';
        }

        return $host;
    }

    /** Normalise a provider name from the wizard into a canonical value. */
    private static function normalizeProvider(string $provider): string
    {
        $provider = strtoupper(trim($provider));
        if ($provider === 'SIPIO') {
            return 'SIP.IO';
        }

        return in_array($provider, self::PROVIDERS, true) ? $provider : 'Twilio';
    }

    private function row(): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM trunk WHERE id = ?');
        $st->execute([self::ID]);

        return $st->fetch() ?: [];
    }
}
