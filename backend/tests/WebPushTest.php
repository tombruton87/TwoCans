<?php
declare(strict_types=1);

/**
 * Web Push: the encryption, byte for byte against RFC 8291's own worked
 * example (Appendix A), the VAPID signature, and the subscriptions.
 */

// An EC private key from its raw scalar and public point (SEC1 DER as PEM).
$privateKey = static function (string $d, string $public): OpenSSLAsymmetricKey {
    $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $public;
    $key = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n");
    assertTrue($key !== false, 'the example key loads');

    return $key;
};

return [
    test('encryption matches RFC 8291\'s worked example exactly', function () use ($privateKey) {
        $u = [WebPush::class, 'unb64'];
        $as = $privateKey($u('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
            $u('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'));
        $body = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            $u('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            $u('BTBZMqHH6r4Tts7J_aSIgg'),
            $as,
            $u('DGv6ra1nlYgDCS1FRnbzlw')
        );
        assertSame('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            WebPush::b64($body));
    }),
    test('a VAPID token is ES256-signed by the box\'s key, for the push service it\'s sent to', function () {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $jwt = WebPush::vapidJwt('https://push.example.com', $key);
        [$head, $claims, $sig] = explode('.', $jwt);
        assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::unb64($head), true));
        $c = json_decode(WebPush::unb64($claims), true);
        assertSame('https://push.example.com', $c['aud']);
        assertTrue($c['exp'] > time() && $c['exp'] <= time() + 24 * 3600, 'expires within a day, as the RFC asks');
        // Raw r||s back to DER, and checked with the public key.
        $raw = WebPush::unb64($sig);
        assertSame(64, strlen($raw));
        $int = static function (string $n): string {
            $n = ltrim($n, "\0");
            if ($n === '' || ord($n[0]) & 0x80) {
                $n = "\0" . $n;
            }

            return "\x02" . chr(strlen($n)) . $n;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        $der = "\x30" . chr(strlen($seq)) . $seq;
        $public = openssl_pkey_get_public(WebPush::pem(WebPush::rawPublic($key)));
        assertSame(1, openssl_verify($head . '.' . $claims, $der, $public, OPENSSL_ALGO_SHA256));
    }),
    test('a browser\'s subscription is kept once, and only its grown-up can remove it', function () {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $push = new Push();
            $ua = WebPush::b64(WebPush::rawPublic(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC])));
            $auth = WebPush::b64(random_bytes(16));
            $endpoint = 'https://push.example.com/test-' . bin2hex(random_bytes(4));
            assertTrue($push->subscribe(1, $endpoint, $ua, $auth, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1'));
            assertTrue($push->subscribe(1, $endpoint, $ua, $auth, 'again'));
            $mine = array_values(array_filter($push->all(1), static fn(array $r): bool => $r['endpoint'] === $endpoint));
            assertSame(1, count($mine));
            assertFalse($push->subscribe(1, 'http://not-https.example.com', $ua, $auth, ''), 'only https');
            assertFalse($push->subscribe(1, $endpoint . 'x', 'short', $auth, ''), 'a real key');
            $push->remove((int) $mine[0]['id'], 999);
            assertSame(1, count(array_filter($push->all(1), static fn(array $r): bool => $r['endpoint'] === $endpoint)), "someone else's isn't removed");
            $push->remove((int) $mine[0]['id'], 1);
            assertSame(0, count(array_filter($push->all(1), static fn(array $r): bool => $r['endpoint'] === $endpoint)));
        } finally {
            $pdo->rollBack();
        }
    }),
    test('devices are named the way a grown-up tells them apart', function () {
        assertSame('iPhone · Safari', Push::label('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1'));
        assertSame('Android · Chrome', Push::label('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/128.0 Mobile Safari/537.36'));
        assertSame('Windows · Edge', Push::label('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0 Safari/537.36 Edg/128.0'));
    }),
];
