<?php
declare(strict_types=1);

/**
 * Secret-key encryption for values that must be recoverable but never stored
 * plaintext — in practice the Twilio auth token in `trunk.auth_token_enc`.
 *
 * Uses libsodium's XSalsa20-Poly1305 secretbox (authenticated encryption). The
 * key comes from APP_KEY, a 64-hex-char (32-byte) secret set in the compose
 * file. There is deliberately no fallback key: a missing APP_KEY fails loudly
 * rather than silently encrypting with a known constant.
 */
final class Crypto
{
    /** @throws RuntimeException when APP_KEY is missing or malformed. */
    public static function key(): string
    {
        $hex = (string) getenv('APP_KEY');

        if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new RuntimeException(
                'APP_KEY is not set or is not 64 hex characters. Generate one with '
                . '`php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` and add it to the environment.'
            );
        }

        $key = hex2bin($hex);
        if ($key === false) {
            throw new RuntimeException('APP_KEY could not be decoded.');
        }

        return $key;
    }

    /** @return string nonce . ciphertext (binary) */
    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, self::key());

        return $nonce . $ciphertext;
    }

    /** @throws RuntimeException when the key has changed since encryption. */
    public static function decrypt(string $blob): string
    {
        $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, self::key());
        if ($plaintext === false) {
            throw new RuntimeException('Could not decrypt — APP_KEY changed since this value was stored.');
        }

        return $plaintext;
    }
}
