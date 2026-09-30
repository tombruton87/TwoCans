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
            'region' => Twilio::normalizeRegion((string) ($row['region'] ?? 'us1')),
            // The main number: the first the line was connected with.
            'number' => (string) ($row['number_e164'] ?? ''),
            // What calls go out from, unless a phone has its own — see
            // outgoingNumberFor(). The main number unless another is chosen.
            'outgoing' => self::onLine((string) ($row['outgoing_number'] ?? ''), $row) ?: (string) ($row['number_e164'] ?? ''),
            // Every number that rings the house, main one first — see
            // migration 033.
            'numbers' => array_values(array_filter(array_merge(
                [(string) ($row['number_e164'] ?? '')],
                preg_split('/\s+/', trim((string) ($row['extra_numbers'] ?? ''))) ?: []
            ))),
            'accountSid' => (string) ($row['account_sid'] ?? ''),
            // number => the one phone it rings. A number missing from this
            // rings every phone that may receive right now — see migration 034.
            'rings' => $this->rings(),
            // Null means Twilio would not tell us, which is not the same as nil credit.
            'balance' => isset($row['balance']) ? (float) $row['balance'] : null,
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'lowThreshold' => (float) ($row['low_threshold'] ?? 5.0),
            'minutesThisMonth' => (int) ($row['minutes_this_month'] ?? 0),
            'rate' => (string) ($row['rate'] ?? '') ?: '—',
            'autoTopUp' => (bool) ($row['auto_topup'] ?? false),
            'terminationUri' => (string) ($row['termination_uri'] ?? ''),
            // Blank means the provider authenticates us by source IP instead.
            'terminationUsername' => (string) ($row['termination_username'] ?? ''),
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

        // An unreadable balance is not a low one — never cry wolf on a figure
        // we do not have.
        return $trunk['connected'] && $trunk['provider'] === 'Twilio'
            && $trunk['balance'] !== null
            && $trunk['balance'] < $trunk['lowThreshold'];
    }

    /**
     * Which of the line's numbers a call came in on, from what Asterisk
     * recorded as dialled — or null for anything that isn't one of them (a
     * call between two of the house's phones, a test call). Last nine digits,
     * as everywhere else numbers are matched.
     *
     * @param array<int,string> $numbers the line's numbers
     */
    public static function lineNumberFor(string $dialled, array $numbers): ?string
    {
        $tail = substr(preg_replace('/\D/', '', $dialled) ?? '', -9);
        if (strlen($tail) < 9) {
            return null;
        }
        foreach ($numbers as $number) {
            if (substr(preg_replace('/\D/', '', $number) ?? '', -9) === $tail) {
                return $number;
            }
        }

        return null;
    }

    /**
     * The number a phone calls out from. Its own choice if it has one; else,
     * automatically, the first of the line's numbers pointed at it — so a
     * number that belongs to one child is also what the people they ring see.
     * Null means the line's (see get()['outgoing']).
     */
    public function outgoingNumberFor(int $deviceId): ?string
    {
        $trunk = $this->get();
        $st = Database::pdo()->prepare('SELECT outgoing_number FROM devices WHERE id = ?');
        $st->execute([$deviceId]);
        $chosen = (string) ($st->fetchColumn() ?: '');
        if ($chosen !== '' && in_array($chosen, $trunk['numbers'], true)) {
            return $chosen;
        }

        return $this->ownNumber($deviceId, $trunk);
    }

    /** The first of the line's numbers pointed at this phone, or null. */
    public function ownNumber(int $deviceId, ?array $trunk = null): ?string
    {
        $trunk ??= $this->get();
        foreach ($trunk['numbers'] as $number) {
            if (($trunk['rings'][$number] ?? null) === $deviceId) {
                return $number;
            }
        }

        return null;
    }

    /** What the line calls out from; '' for its main number. Not one of its numbers: ignored. */
    public function setOutgoing(string $number): void
    {
        $number = in_array($number, $this->get()['numbers'], true) ? $number : '';
        Database::pdo()->prepare('UPDATE trunk SET outgoing_number = ? WHERE id = ?')
            ->execute([$number !== '' ? $number : null, self::ID]);
    }

    /** What one phone calls out from; '' for automatic. Not one of the line's numbers: ignored. */
    public function setDeviceOutgoing(int $deviceId, string $number): void
    {
        $number = in_array($number, $this->get()['numbers'], true) ? $number : '';
        Database::pdo()->prepare('UPDATE devices SET outgoing_number = ? WHERE id = ?')
            ->execute([$number !== '' ? $number : null, $deviceId]);
    }

    /**
     * Where messages left on one of the line's numbers go: the house mailbox,
     * or a phone's (its extension). Automatic unless chosen: the phone the
     * number rings, if it rings just one, else the house.
     *
     * @return array{mailbox:string,deviceId:?int,auto:bool}
     */
    public function mailboxFor(string $number, ?array $trunk = null): array
    {
        $trunk ??= $this->get();
        $st = Database::pdo()->prepare('SELECT target FROM trunk_number_mailboxes WHERE number_e164 = ?');
        $st->execute([$number]);
        $target = (string) ($st->fetchColumn() ?: '');

        $house = ['mailbox' => PjsipConfig::HOUSE_MAILBOX, 'deviceId' => null];
        $phone = static function (int $id): ?array {
            $row = (new DeviceRepository())->find($id);

            return $row === null || (string) $row['extension'] === '' ? null : ['mailbox' => (string) $row['extension'], 'deviceId' => $id];
        };

        if ($target === 'house') {
            return $house + ['auto' => false];
        }
        if ($target !== '' && ($chosen = $phone((int) $target)) !== null) {
            return $chosen + ['auto' => false];
        }
        $rings = $trunk['rings'][$number] ?? null;

        return (($rings !== null ? $phone($rings) : null) ?? $house) + ['auto' => true];
    }

    /** Where one number's messages go: '' automatic, 'house', or a phone's id. */
    public function setMailbox(string $number, string $target): void
    {
        $pdo = Database::pdo();
        if ($target === '' || !in_array($number, $this->get()['numbers'], true)) {
            $pdo->prepare('DELETE FROM trunk_number_mailboxes WHERE number_e164 = ?')->execute([$number]);

            return;
        }
        $pdo->prepare('INSERT INTO trunk_number_mailboxes (number_e164, target) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE target = VALUES(target)')
            ->execute([$number, $target === 'house' ? 'house' : (string) (int) $target]);
    }

    /** $number if it is one of the line's (in this trunk row), else ''. */
    private static function onLine(string $number, array $row): string
    {
        if ($number === '') {
            return '';
        }
        $numbers = array_merge([(string) ($row['number_e164'] ?? '')], preg_split('/\s+/', trim((string) ($row['extra_numbers'] ?? ''))) ?: []);

        return in_array($number, $numbers, true) ? $number : '';
    }

    /** @return array<string,int> number => the one phone it rings */
    private function rings(): array
    {
        $rings = [];
        foreach (Database::pdo()->query('SELECT number_e164, device_id FROM trunk_number_rings') as $row) {
            $rings[(string) $row['number_e164']] = (int) $row['device_id'];
        }

        return $rings;
    }

    /**
     * Point one of the line's numbers at one phone, or back at all of them.
     *
     * The device is not checked here: the foreign key rejects an id that is not
     * a real phone, and a phone deleted later takes its rows with it.
     */
    public function setRingDevice(string $number, ?int $deviceId): void
    {
        $pdo = Database::pdo();
        if ($deviceId === null) {
            $pdo->prepare('DELETE FROM trunk_number_rings WHERE number_e164 = ?')->execute([$number]);

            return;
        }

        $pdo->prepare(
            'INSERT INTO trunk_number_rings (number_e164, device_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE device_id = VALUES(device_id)'
        )->execute([$number, $deviceId]);
    }

    /**
     * Decrypt the termination password, or null when the trunk authenticates
     * by IP. Only PjsipConfig needs this — Asterisk requires it in the clear.
     */
    public function terminationPassword(): ?string
    {
        $encrypted = $this->row()['termination_password_enc'] ?? null;

        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        return Crypto::decrypt($encrypted);
    }

    /** Decrypt the stored auth token (for future API calls). */
    /** Decrypt the stored SIP.IO API key, or null if this line is not on SIP.IO. */
    public function apiKey(): ?string
    {
        $encrypted = $this->row()['api_key_enc'] ?? null;

        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        return Crypto::decrypt($encrypted);
    }

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

        /*
         * Secrets are write-only: the wizard never renders one back, so every
         * reopening of it arrives with the field blank. Treat that as "keep
         * what is saved" rather than "clear it", or editing the number would
         * silently take the whole line down.
         */
        if ($token === '') {
            $token = (string) ($this->authToken() ?? '');
        }
        [$numbers, $badNumber] = self::parseNumbers((string) ($input['number'] ?? ''));
        $number = $numbers[0] ?? '';
        $termination = self::normalizeTermination((string) ($input['termination'] ?? ''));
        $region = Twilio::normalizeRegion((string) ($input['region'] ?? 'us1'));
        // Optional: a trunk may instead allowlist this house's IP address.
        $termUser = trim((string) ($input['terminationUsername'] ?? ''));
        $termPass = (string) ($input['terminationPassword'] ?? '');

        /*
         * A blank password means "keep the one already saved".
         *
         * The wizard never echoes a password back into the form, so reopening
         * it to change anything else would otherwise wipe the credentials and
         * take outbound calling down with them. Clearing the username is still
         * how you switch a trunk back to IP authentication — that drops both.
         */
        if ($termUser !== '' && $termPass === '') {
            $termPass = (string) ($this->terminationPassword() ?? '');
        }

        /*
         * Half a credential is worse than none: it stores cleanly, then
         * PjsipConfig declines to write an auth section because it has no
         * password, and outbound calls fail with "no auth ids available"
         * somewhere far away from this screen.
         */
        if ($termUser !== '' && $termPass === '') {
            return ['ok' => false, 'error' => 'That termination username needs its password. Find the pair in Twilio under Elastic SIP Trunking → Credential Lists, or set a new password there.'];
        }
        if ($termUser === '' && $termPass !== '') {
            return ['ok' => false, 'error' => 'Enter the termination username that goes with that password.'];
        }

        if (!preg_match('/^AC[0-9a-f]{32}$/i', $sid)) {
            return ['ok' => false, 'error' => "That Account SID doesn't look right — it starts with AC and is 34 characters long."];
        }
        if ($token === '') {
            return ['ok' => false, 'error' => 'Enter the auth token from the Twilio console.'];
        }
        if ($badNumber !== null) {
            return ['ok' => false, 'error' => self::badNumberMessage($badNumber)];
        }
        if ($number === '') {
            return ['ok' => false, 'error' => "That phone number doesn't look right."];
        }
        if ($termination === '') {
            return ['ok' => false, 'error' => 'Enter the SIP trunk termination URI — find it in Twilio under Elastic SIP Trunking → Termination.'];
        }

        // Each region issues its own auth token, so the region is part of the
        // credentials, not a detail of where the trunk happens to sit.
        $twilio = new Twilio($sid, $token, $region);

        $verified = $twilio->verify();
        if (!$verified['ok']) {
            return ['ok' => false, 'error' => $verified['error']];
        }

        // Every number has to be on this account and attached to the trunk, or
        // calls to it keep their old routing and never reach the house.
        foreach ($numbers as $each) {
            $numberCheck = $twilio->number($each);
            if (!$numberCheck['ok']) {
                return ['ok' => false, 'error' => self::aboutNumber($each, $numbers, (string) $numberCheck['error'])];
            }

            // The termination host is only a string until Twilio confirms there
            // is a trunk behind it that can carry calls both ways.
            $trunkCheck = $twilio->trunk($termination, $each);
            if (!$trunkCheck['ok']) {
                return ['ok' => false, 'error' => $trunkCheck['error']];
            }
        }

        $balance = $twilio->balance();

        Database::pdo()->prepare(
            'INSERT INTO trunk
                (id, provider, region, connected, number_e164, extra_numbers, account_sid, auth_token_enc,
                 termination_uri, termination_username, termination_password_enc,
                 balance, currency, last_verified_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                provider = VALUES(provider),
                region = VALUES(region),
                connected = VALUES(connected),
                number_e164 = VALUES(number_e164),
                extra_numbers = VALUES(extra_numbers),
                account_sid = VALUES(account_sid),
                auth_token_enc = VALUES(auth_token_enc),
                termination_uri = VALUES(termination_uri),
                termination_username = VALUES(termination_username),
                termination_password_enc = VALUES(termination_password_enc),
                balance = VALUES(balance),
                currency = VALUES(currency),
                api_key_enc = NULL,
                sip_proxy = NULL,
                last_verified_at = VALUES(last_verified_at)'
        )->execute([
            self::ID,
            'Twilio',
            $region,
            $number,
            self::extras($numbers),
            $sid,
            Crypto::encrypt($token),
            $termination,
            $termUser === '' ? null : $termUser,
            $termPass === '' ? null : Crypto::encrypt($termPass),
            $balance['balance'] === null ? null : sprintf('%.2F', $balance['balance']),
            $balance['currency'],
        ]);

        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok:bool,error:?string} */
    private function connectSipio(array $input): array
    {
        $apiKey = trim((string) ($input['apiKey'] ?? ''));

        // Blank means keep the saved key — see connectTwilio() for why.
        if ($apiKey === '') {
            $apiKey = (string) ($this->apiKey() ?? '');
        }
        [$numbers, $badNumber] = self::parseNumbers((string) ($input['number'] ?? ''));
        $number = $numbers[0] ?? '';
        $proxy = self::normalizeProxy((string) ($input['proxy'] ?? ''));

        if (!preg_match('/^sk_[A-Za-z0-9_-]{12,}$/', $apiKey)) {
            return ['ok' => false, 'error' => "That API key doesn't look right — it should start with sk_."];
        }
        if ($badNumber !== null) {
            return ['ok' => false, 'error' => self::badNumberMessage($badNumber)];
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

        foreach ($numbers as $each) {
            if ($sipio->numberExists($each) === 'not_found') {
                return ['ok' => false, 'error' => self::aboutNumber($each, $numbers, 'That number was not found on this SIP.IO account.')];
            }
        }

        Database::pdo()->prepare(
            'INSERT INTO trunk
                (id, provider, connected, number_e164, extra_numbers, api_key_enc, sip_proxy, last_verified_at)
             VALUES (?, ?, 1, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                provider = VALUES(provider),
                connected = VALUES(connected),
                number_e164 = VALUES(number_e164),
                extra_numbers = VALUES(extra_numbers),
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
            self::extras($numbers),
            Crypto::encrypt($apiKey),
            $proxy,
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Read the number box, which takes one number or several.
     *
     * Several are separated by spaces (or commas). A number is often written
     * with spaces in it too — "+44 1522 474753" — so a space only starts a new
     * number where the next one begins with +, or where every piece is a whole
     * number on its own. Commas always separate. Duplicates are dropped; the
     * first number stays first, because it is the line's caller ID.
     *
     * @return array{0:array<int,string>,1:?string} the numbers, and the first
     *         piece that isn't a number (or null)
     */
    public static function parseNumbers(string $input): array
    {
        $pieces = [];
        foreach (preg_split('/[,;\n]+/', $input) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (str_contains($chunk, '+')) {
                $parts = preg_split('/\s+(?=\+)/', $chunk) ?: [];
            } else {
                $words = preg_split('/\s+/', $chunk) ?: [];
                $whole = array_filter($words, static fn(string $w): bool => strlen(preg_replace('/\D/', '', $w) ?? '') >= 7);
                $parts = count($words) > 1 && count($whole) === count($words) ? $words : [$chunk];
            }
            foreach ($parts as $part) {
                $pieces[] = trim($part);
            }
        }

        $numbers = [];
        foreach ($pieces as $piece) {
            // The same reading contacts get, so a national number written the
            // way a household writes it (07700…) takes the house's country code.
            $number = ContactRepository::toE164($piece);
            // Letters, or fewer than 7 digits, is a typo rather than a number.
            if ($number === '' || strlen($number) < 8 || preg_match('/[a-z]/i', $piece)) {
                return [$numbers, $piece];
            }
            if (!in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }

        return [$numbers, null];
    }

    /** The numbers after the first, as stored in trunk.extra_numbers. */
    private static function extras(array $numbers): ?string
    {
        $rest = array_slice($numbers, 1);

        return $rest === [] ? null : implode(' ', $rest);
    }

    private static function badNumberMessage(string $piece): string
    {
        return '“' . $piece . "” doesn't look like a phone number. Separate several numbers "
             . 'with spaces, and start each with + and its country code.';
    }

    /** A provider's complaint, naming the number when there is more than one. */
    private static function aboutNumber(string $number, array $numbers, string $error): string
    {
        return count($numbers) > 1 ? $number . ': ' . $error : $error;
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
