<?php
declare(strict_types=1);

/**
 * Web Push, with nothing but OpenSSL: a notification on a grown-up's phone or
 * computer, even with twocans closed — the browser's own push service carries
 * it (Apple's, Google's, Mozilla's), and the service worker (sw.js) shows it.
 *
 * Two standards, both small:
 *  - RFC 8291: the message is encrypted for that one browser (aes128gcm), so
 *    the push service carries it without being able to read it.
 *  - RFC 8292 (VAPID): each request is signed with this box's own key pair,
 *    made once and kept in settings, so only this box can push to the
 *    browsers that said yes to it.
 */
final class WebPush
{
    /** A P-256 public key as SubjectPublicKeyInfo DER, all but its last 65 bytes. */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function __construct(private SettingsRepository $settings = new SettingsRepository())
    {
    }

    /** This box's public key, as the browser wants it (base64url, 65 bytes uncompressed). */
    public function publicKey(): string
    {
        return self::b64(self::rawPublic($this->privateKey()));
    }

    /**
     * Send $message — {title, body, url, tag} — to one browser.
     *
     * @param array{endpoint:string,p256dh:string,auth:string} $to
     * @return array{ok:bool,gone:bool,status:int,error:?string} gone: the browser has unsubscribed
     */
    public function send(array $to, array $message, bool $urgent = false): array
    {
        $endpoint = $to['endpoint'];
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || !is_string($host) || $host === '') {
            return ['ok' => false, 'gone' => true, 'status' => 0, 'error' => 'not a push address'];
        }
        try {
            $body = self::encrypt((string) json_encode($message), self::unb64($to['p256dh']), self::unb64($to['auth']));
        } catch (Throwable $e) {
            return ['ok' => false, 'gone' => true, 'status' => 0, 'error' => 'its keys are no good: ' . $e->getMessage()];
        }

        $key = $this->privateKey();
        $jwt = self::vapidJwt('https://' . $host, $key);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: ' . ($urgent ? 3600 : 86400),
                'Urgency: ' . ($urgent ? 'high' : 'normal'),
                'Authorization: vapid t=' . $jwt . ', k=' . self::b64(self::rawPublic($key)),
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $response === false ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'ok' => $status >= 200 && $status < 300,
            // Unsubscribed, or the address is no more: forget it.
            'gone' => $status === 404 || $status === 410,
            'status' => $status,
            'error' => $status >= 200 && $status < 300 ? null : ($error ?? 'the push service said ' . $status),
        ];
    }

    /**
     * RFC 8291: $plain encrypted for the browser with public key $uaPublic
     * (65 bytes) and auth secret $auth (16 bytes), as one aes128gcm record.
     */
    public static function encrypt(string $plain, string $uaPublic, string $auth, ?OpenSSLAsymmetricKey $ephemeral = null, ?string $salt = null): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($auth) < 16) {
            throw new InvalidArgumentException('not a P-256 public key and auth secret');
        }
        $ephemeral ??= self::newKey();
        $salt ??= random_bytes(16);
        $asPublic = self::rawPublic($ephemeral);

        $peer = openssl_pkey_get_public(self::pem($uaPublic));
        $shared = $peer === false ? false : openssl_pkey_derive($peer, $ephemeral, 32);
        if ($shared === false || strlen($shared) !== 32) {
            throw new RuntimeException("couldn't agree a key with the browser");
        }

        $prkKey = hash_hmac('sha256', $shared, $auth, true);
        $ikm = substr(hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", $prkKey, true), 0, 32);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        // One record: the message, then the delimiter 0x02 (the last record).
        $tag = '';
        $cipher = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException("couldn't encrypt");
        }

        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** RFC 8292: a JWT for $audience (the push service's origin), signed ES256 with this box's key. */
    public static function vapidJwt(string $audience, OpenSSLAsymmetricKey $key, string $subject = 'https://github.com/tombruton87/TwoCans'): string
    {
        $head = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64((string) json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
        openssl_sign($head . '.' . $claims, $der, $key, OPENSSL_ALGO_SHA256);

        return $head . '.' . $claims . '.' . self::b64(self::derToRaw((string) $der));
    }

    /** This box's VAPID key pair: made the first time, then kept in settings. */
    private function privateKey(): OpenSSLAsymmetricKey
    {
        $pem = (string) ($this->settings->all()['vapid_private'] ?? '');
        $key = $pem !== '' ? openssl_pkey_get_private($pem) : false;
        if ($key === false) {
            $key = self::newKey();
            openssl_pkey_export($key, $pem);
            $this->settings->set('vapid_private', $pem);
        }

        return $key;
    }

    private static function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException("couldn't make a key");
        }

        return $key;
    }

    /** A key's public half, uncompressed: 0x04, then x and y, 32 bytes each. */
    public static function rawPublic(OpenSSLAsymmetricKey $key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];

        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** A raw uncompressed P-256 public key as PEM, for OpenSSL. */
    public static function pem(string $raw): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode((string) hex2bin(self::P256_SPKI_PREFIX) . $raw), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /** An ECDSA signature from OpenSSL's DER to the 64 bytes (r, then s) a JWT wants. */
    private static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $parts[] = str_pad(ltrim(substr($der, $offset + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $parts[0] . $parts[1];
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }
}
