<?php
declare(strict_types=1);

/**
 * Passkeys — signing in with Face ID, Touch ID or a fingerprint (WebAuthn).
 *
 * Just what twocans needs, on PHP's own OpenSSL, rather than a library:
 *
 *   - registration asks for no attestation ("none"), so there is no
 *     manufacturer certificate chain to check — the key is trusted because a
 *     signed-in grown-up just made it on their own phone;
 *   - the passkey must be discoverable (stored on the phone with the account
 *     in it), so signing in needs no email address, just the face or finger;
 *   - user verification is required every time: a passkey that only proves
 *     the phone was nearby is not a sign-in.
 *
 * Every check the spec asks a server to make is made here: the challenge is
 * the one this session was given and is used once; the origin and the relying
 * party are this site; the phone says the user was present and verified; the
 * signature is over exactly what was signed; a signature counter that goes
 * backwards (a cloned key) is refused. Keys are ES256 (P-256) or RS256.
 *
 * WebAuthn only works on a secure origin with a domain name — HTTPS on
 * phone.example.com, or localhost — never on a bare IP address.
 */
final class WebAuthn
{
    /** How long a challenge stays good. */
    private const CHALLENGE_SECONDS = 180;

    private const FLAG_UP = 0x01;   // user present
    private const FLAG_UV = 0x04;   // user verified — Face ID, fingerprint, PIN
    private const FLAG_AT = 0x40;   // attested credential data included

    /** The site's own address, as the browser saw it. */
    public static function origin(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        return ($https ? 'https' : 'http') . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /** The relying party: this site's host name, without a port. */
    public static function rpId(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        return (string) preg_replace('/:\d+$/', '', $host);
    }

    /**
     * Whether passkeys can work at the address the page was opened on — HTTPS
     * with a domain name, or localhost. A bare IP address never can.
     */
    public static function available(): bool
    {
        $host = self::rpId();
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return str_starts_with(self::origin(), 'https://');
    }

    // ------------------------------------------------------------- register

    /**
     * Options for navigator.credentials.create(), for a signed-in guardian.
     *
     * @param array<int,string> $existing credential ids they already have, so
     *                                    the same phone isn't registered twice
     */
    public static function registerOptions(array $guardian, array $existing): array
    {
        return [
            'challenge' => self::newChallenge('create'),
            'rp' => ['id' => self::rpId(), 'name' => 'twocans'],
            'user' => [
                'id' => self::b64url(self::userHandle((int) $guardian['id'])),
                'name' => (string) $guardian['email'],
                'displayName' => (string) $guardian['name'],
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],     // ES256
                ['type' => 'public-key', 'alg' => -257],   // RS256
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'residentKey' => 'required',
                'requireResidentKey' => true,
                'userVerification' => 'required',
            ],
            'excludeCredentials' => array_map(
                static fn(string $id): array => ['type' => 'public-key', 'id' => $id],
                $existing
            ),
        ];
    }

    /**
     * Check what navigator.credentials.create() returned.
     *
     * @return array{id:string,publicKey:string,signCount:int}
     * @throws RuntimeException with a message fit to show
     */
    public static function verifyRegistration(array $response): array
    {
        $clientJson = self::unb64url((string) ($response['clientDataJSON'] ?? ''));
        self::checkClientData($clientJson, 'webauthn.create', 'create');

        $attestation = self::cborDecode(self::unb64url((string) ($response['attestationObject'] ?? '')));
        if (!is_array($attestation) || !isset($attestation['authData']) || !is_string($attestation['authData'])) {
            throw new RuntimeException("The phone's reply wasn't understood.");
        }

        $auth = self::parseAuthData($attestation['authData'], true);
        if ($auth['credentialId'] === '' || $auth['publicKey'] === null) {
            throw new RuntimeException('The phone sent no key.');
        }

        return [
            'id' => self::b64url($auth['credentialId']),
            'publicKey' => $auth['publicKey'],
            'signCount' => $auth['signCount'],
        ];
    }

    // ---------------------------------------------------------------- login

    /** Options for navigator.credentials.get(): any passkey for this site. */
    public static function loginOptions(): array
    {
        return [
            'challenge' => self::newChallenge('get'),
            'rpId' => self::rpId(),
            'timeout' => 60000,
            'userVerification' => 'required',
            'allowCredentials' => [],
        ];
    }

    /**
     * Check what navigator.credentials.get() returned, against the stored key.
     *
     * @param  array $stored a guardian_passkeys row
     * @return int   the new signature counter
     * @throws RuntimeException
     */
    public static function verifyLogin(array $response, array $stored): int
    {
        $clientJson = self::unb64url((string) ($response['clientDataJSON'] ?? ''));
        self::checkClientData($clientJson, 'webauthn.get', 'get');

        $authData = self::unb64url((string) ($response['authenticatorData'] ?? ''));
        $auth = self::parseAuthData($authData, false);

        // Returned by a discoverable passkey: it must be this guardian's.
        $handle = (string) ($response['userHandle'] ?? '');
        if ($handle !== '' && !hash_equals(self::userHandle((int) $stored['guardian_id']), self::unb64url($handle))) {
            throw new RuntimeException("That passkey belongs to someone else's account.");
        }

        $signature = self::unb64url((string) ($response['signature'] ?? ''));
        $signed = $authData . hash('sha256', $clientJson, true);
        if (openssl_verify($signed, $signature, (string) $stored['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException("That passkey's signature didn't check out.");
        }

        // A counter that doesn't move forward means a copied key. Passkeys
        // synced between devices (iCloud, Google) always send 0, which is fine.
        $old = (int) $stored['sign_count'];
        if (($old > 0 || $auth['signCount'] > 0) && $auth['signCount'] <= $old) {
            throw new RuntimeException('That passkey looks like a copy — remove it and set it up again.');
        }

        return $auth['signCount'];
    }

    // --------------------------------------------------------------- shared

    /** A stable, opaque id for a guardian inside their passkeys. */
    public static function userHandle(int $guardianId): string
    {
        return 'twocans-guardian-' . $guardianId;
    }

    private static function newChallenge(string $kind): string
    {
        $challenge = self::b64url(random_bytes(32));
        $_SESSION['webauthn'] = ['challenge' => $challenge, 'kind' => $kind, 'at' => time()];

        return $challenge;
    }

    /** The browser's own account of the ceremony: right kind, right site, our challenge. */
    private static function checkClientData(string $json, string $type, string $kind): void
    {
        $pending = $_SESSION['webauthn'] ?? null;
        unset($_SESSION['webauthn']);   // one use, whatever happens next

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException("The phone's reply wasn't understood.");
        }
        if (!is_array($pending) || ($pending['kind'] ?? '') !== $kind
            || time() - (int) ($pending['at'] ?? 0) > self::CHALLENGE_SECONDS) {
            throw new RuntimeException('That took too long — try again.');
        }
        if (($data['type'] ?? '') !== $type) {
            throw new RuntimeException("The phone's reply wasn't the right kind.");
        }
        if (!hash_equals((string) $pending['challenge'], (string) ($data['challenge'] ?? ''))) {
            throw new RuntimeException('That reply was for a different request — try again.');
        }
        if (($data['origin'] ?? '') !== self::origin()) {
            throw new RuntimeException('That passkey was made for a different address.');
        }
    }

    /**
     * @return array{signCount:int,credentialId:string,publicKey:?string}
     */
    private static function parseAuthData(string $data, bool $expectKey): array
    {
        if (strlen($data) < 37) {
            throw new RuntimeException("The phone's reply was too short.");
        }
        if (!hash_equals(hash('sha256', self::rpId(), true), substr($data, 0, 32))) {
            throw new RuntimeException('That passkey belongs to a different site.');
        }
        $flags = ord($data[32]);
        if (!($flags & self::FLAG_UP) || !($flags & self::FLAG_UV)) {
            throw new RuntimeException('Face ID or a fingerprint is needed to use a passkey here.');
        }
        $signCount = unpack('N', substr($data, 33, 4))[1];

        $credentialId = '';
        $publicKey = null;
        if ($expectKey) {
            if (!($flags & self::FLAG_AT) || strlen($data) < 55) {
                throw new RuntimeException('The phone sent no key.');
            }
            $length = unpack('n', substr($data, 53, 2))[1];
            $credentialId = substr($data, 55, $length);
            $offset = 55 + $length;
            $cose = self::cborDecode($data, $offset);
            $publicKey = self::coseToPem(is_array($cose) ? $cose : []);
        }

        return ['signCount' => (int) $signCount, 'credentialId' => $credentialId, 'publicKey' => $publicKey];
    }

    /** A COSE public key (ES256 or RS256) as PEM, for openssl_verify(). */
    private static function coseToPem(array $key): string
    {
        $kty = $key[1] ?? null;
        $alg = $key[3] ?? null;

        if ($kty === 2 && $alg === -7 && ($key[-1] ?? null) === 1) {
            $x = (string) ($key[-2] ?? '');
            $y = (string) ($key[-3] ?? '');
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new RuntimeException('That key is the wrong shape.');
            }
            // SubjectPublicKeyInfo for an uncompressed P-256 point.
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        } elseif ($kty === 3 && $alg === -257) {
            $n = ltrim((string) ($key[-1] ?? ''), "\0");
            $e = ltrim((string) ($key[-2] ?? ''), "\0");
            if ($n === '' || $e === '') {
                throw new RuntimeException('That key is the wrong shape.');
            }
            $int = static fn(string $v): string => "\x02" . self::derLength(strlen((ord($v[0]) & 0x80 ? "\0" : '') . $v))
                . (ord($v[0]) & 0x80 ? "\0" : '') . $v;
            $rsa = "\x30" . self::derLength(strlen($int($n) . $int($e))) . $int($n) . $int($e);
            $bits = "\x03" . self::derLength(strlen($rsa) + 1) . "\0" . $rsa;
            $algId = hex2bin('300d06092a864886f70d0101010500');
            $der = "\x30" . self::derLength(strlen($algId . $bits)) . $algId . $bits;
        } else {
            throw new RuntimeException("That phone's key type isn't supported.");
        }

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\0");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Decode one CBOR item — the subset WebAuthn uses: integers, byte and
     * text strings, arrays, maps, tags, booleans, null and floats. Definite
     * lengths only, as WebAuthn requires.
     */
    public static function cborDecode(string $data, int &$offset = 0): mixed
    {
        $take = static function (int $n) use ($data, &$offset): string {
            if ($offset + $n > strlen($data)) {
                throw new RuntimeException("The phone's reply was cut short.");
            }
            $out = substr($data, $offset, $n);
            $offset += $n;

            return $out;
        };

        $initial = ord($take(1));
        $major = $initial >> 5;
        $info = $initial & 0x1f;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                25 => self::halfFloat($take(2)),
                26 => unpack('G', $take(4))[1],
                27 => unpack('E', $take(8))[1],
                default => throw new RuntimeException("The phone's reply wasn't understood."),
            };
        }

        $value = match (true) {
            $info < 24 => $info,
            $info === 24 => ord($take(1)),
            $info === 25 => unpack('n', $take(2))[1],
            $info === 26 => unpack('N', $take(4))[1],
            $info === 27 => unpack('J', $take(8))[1],
            default => throw new RuntimeException("The phone's reply wasn't understood."),
        };

        switch ($major) {
            case 0:
                return $value;
            case 1:
                return -1 - $value;
            case 2:
            case 3:
                return $take($value);
            case 4:
                $list = [];
                for ($i = 0; $i < $value; $i++) {
                    $list[] = self::cborDecode($data, $offset);
                }

                return $list;
            case 5:
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    $k = self::cborDecode($data, $offset);
                    $map[is_int($k) || is_string($k) ? $k : (string) json_encode($k)] = self::cborDecode($data, $offset);
                }

                return $map;
            case 6:
                return self::cborDecode($data, $offset);   // a tag: the item it wraps
        }

        throw new RuntimeException("The phone's reply wasn't understood.");
    }

    private static function halfFloat(string $bytes): float
    {
        $h = unpack('n', $bytes)[1];
        $exp = ($h >> 10) & 0x1f;
        $mant = $h & 0x3ff;
        $val = $exp === 0 ? $mant * 2 ** -24 : ($exp === 31 ? ($mant ? NAN : INF) : ($mant + 1024) * 2 ** ($exp - 25));

        return ($h & 0x8000) ? -$val : $val;
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64url(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }
}
