<?php
declare(strict_types=1);

/**
 * Passkey sign-in, end to end, with a pretend phone.
 *
 * The phone is played by OpenSSL: a P-256 key made here, and the exact bytes a
 * real authenticator sends — CBOR attestation, authenticator data, a signature
 * over it. Registration and sign-in both go through WebAuthn as they would from
 * a browser, and each check it has to make is tried the wrong way round too.
 */

/** Just enough CBOR to write what a phone sends. */
$cbor = static function (mixed $v) use (&$cbor): string {
    $head = static function (int $major, int $n): string {
        if ($n < 24) { return chr(($major << 5) | $n); }
        if ($n < 256) { return chr(($major << 5) | 24) . chr($n); }
        if ($n < 65536) { return chr(($major << 5) | 25) . pack('n', $n); }
        return chr(($major << 5) | 26) . pack('N', $n);
    };
    if (is_int($v)) { return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v); }
    if (is_array($v) && array_key_exists('__bytes', $v)) { return $head(2, strlen($v['__bytes'])) . $v['__bytes']; }
    if (is_string($v)) { return $head(3, strlen($v)) . $v; }
    if (is_array($v)) {
        $out = $head(5, count($v));
        foreach ($v as $k => $item) { $out .= $cbor($k) . $cbor($item); }
        return $out;
    }
    throw new RuntimeException('unsupported');
};
$bytes = static fn(string $b): array => ['__bytes' => $b];

/** A phone: its key, and the COSE form of the public half. */
$phone = static function () use ($cbor, $bytes): array {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $d = openssl_pkey_get_details($key)['ec'];
    $cose = $cbor([1 => 2, 3 => -7, -1 => 1, -2 => $bytes(str_pad($d['x'], 32, "\0", STR_PAD_LEFT)), -3 => $bytes(str_pad($d['y'], 32, "\0", STR_PAD_LEFT))]);
    return ['key' => $key, 'cose' => $cose, 'id' => random_bytes(16)];
};

$setServer = static function (): void {
    $_SERVER['HTTP_HOST'] = 'phone.example.com';
    $_SERVER['HTTPS'] = 'on';
};

$register = static function (array $p, string $challenge, array $over = []) use ($cbor, $bytes): array {
    $client = json_encode(['type' => $over['type'] ?? 'webauthn.create', 'challenge' => $challenge,
        'origin' => $over['origin'] ?? 'https://phone.example.com']);
    $auth = hash('sha256', $over['rp'] ?? 'phone.example.com', true) . chr($over['flags'] ?? 0x45) . pack('N', 0)
        . str_repeat("\0", 16) . pack('n', strlen($p['id'])) . $p['id'] . $p['cose'];
    return [
        'clientDataJSON' => WebAuthn::b64url($client),
        'attestationObject' => WebAuthn::b64url($cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => $bytes($auth)])),
    ];
};

$login = static function (array $p, string $challenge, int $count, array $over = []): array {
    $client = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => 'https://phone.example.com']);
    $auth = hash('sha256', 'phone.example.com', true) . chr($over['flags'] ?? 0x05) . pack('N', $count);
    openssl_sign($auth . hash('sha256', $client, true), $sig, $over['signer'] ?? $p['key'], OPENSSL_ALGO_SHA256);
    return [
        'clientDataJSON' => WebAuthn::b64url($client),
        'authenticatorData' => WebAuthn::b64url($auth),
        'signature' => WebAuthn::b64url($sig),
        'userHandle' => WebAuthn::b64url(WebAuthn::userHandle($over['guardian'] ?? 7)),
    ];
};

$expectFail = static function (callable $fn, string $needle): void {
    try {
        $fn();
    } catch (RuntimeException $e) {
        assertContains($needle, $e->getMessage());
        return;
    }
    throw new RuntimeException('expected it to be refused');
};

$registered = static function () use ($phone, $register, $setServer): array {
    $setServer();
    $p = $phone();
    $options = WebAuthn::registerOptions(['id' => 7, 'email' => 'a@b.c', 'name' => 'A'], []);
    $key = WebAuthn::verifyRegistration($register($p, $options['challenge']));
    return [$p, ['guardian_id' => 7, 'public_key' => $key['publicKey'], 'sign_count' => $key['signCount']], $key];
};

return [
    test('a phone registers a passkey and signs in with it', function () use ($registered, $login) {
        [$p, $stored, $key] = $registered();
        assertSame(WebAuthn::b64url($p['id']), $key['id']);
        $options = WebAuthn::loginOptions();
        assertSame(5, WebAuthn::verifyLogin($login($p, $options['challenge'], 5), $stored));
    }),
    test('a synced passkey that always counts 0 still signs in', function () use ($registered, $login) {
        [$p, $stored] = $registered();
        $options = WebAuthn::loginOptions();
        assertSame(0, WebAuthn::verifyLogin($login($p, $options['challenge'], 0), $stored));
    }),
    test('a challenge only works once', function () use ($registered, $login, $expectFail) {
        [$p, $stored] = $registered();
        $options = WebAuthn::loginOptions();
        WebAuthn::verifyLogin($login($p, $options['challenge'], 1), $stored);
        $expectFail(fn() => WebAuthn::verifyLogin($login($p, $options['challenge'], 2), $stored), 'too long');
    }),
    test('a reply to someone else\'s challenge is refused', function () use ($registered, $login, $expectFail) {
        [$p, $stored] = $registered();
        WebAuthn::loginOptions();
        $expectFail(fn() => WebAuthn::verifyLogin($login($p, 'not-the-challenge', 1), $stored), 'different request');
    }),
    test('a signature from a different key is refused', function () use ($registered, $login, $phone, $expectFail) {
        [$p, $stored] = $registered();
        $options = WebAuthn::loginOptions();
        $other = $phone();
        $expectFail(fn() => WebAuthn::verifyLogin($login($p, $options['challenge'], 1, ['signer' => $other['key']]), $stored), 'signature');
    }),
    test('without Face ID or a fingerprint it is refused', function () use ($registered, $login, $expectFail) {
        [$p, $stored] = $registered();
        $options = WebAuthn::loginOptions();
        $expectFail(fn() => WebAuthn::verifyLogin($login($p, $options['challenge'], 1, ['flags' => 0x01]), $stored), 'Face ID');
    }),
    test('a copied key whose counter goes backwards is refused', function () use ($registered, $login, $expectFail) {
        [$p, $stored] = $registered();
        $stored['sign_count'] = 9;
        $options = WebAuthn::loginOptions();
        $expectFail(fn() => WebAuthn::verifyLogin($login($p, $options['challenge'], 4), $stored), 'copy');
    }),
    test('a passkey for another guardian is refused', function () use ($registered, $login, $expectFail) {
        [$p, $stored] = $registered();
        $options = WebAuthn::loginOptions();
        $expectFail(fn() => WebAuthn::verifyLogin($login($p, $options['challenge'], 1, ['guardian' => 8]), $stored), "someone else");
    }),
    test('a passkey made for another site is refused', function () use ($phone, $register, $setServer, $expectFail) {
        $setServer();
        $options = WebAuthn::registerOptions(['id' => 7, 'email' => 'a@b.c', 'name' => 'A'], []);
        $expectFail(fn() => WebAuthn::verifyRegistration($register($phone(), $options['challenge'], ['rp' => 'evil.example'])), 'different site');
    }),
    test('a reply from another address is refused', function () use ($phone, $register, $setServer, $expectFail) {
        $setServer();
        $options = WebAuthn::registerOptions(['id' => 7, 'email' => 'a@b.c', 'name' => 'A'], []);
        $expectFail(fn() => WebAuthn::verifyRegistration($register($phone(), $options['challenge'], ['origin' => 'https://evil.example'])), 'different address');
    }),
    test('passkeys need a name, not a bare IP address', function () {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = '192.168.50.23:443';
        assertSame(false, WebAuthn::available());
        $_SERVER['HTTP_HOST'] = 'phone.example.com';
        assertSame(true, WebAuthn::available());
        $_SERVER['HTTPS'] = '';
        assertSame(false, WebAuthn::available());
    }),
];
