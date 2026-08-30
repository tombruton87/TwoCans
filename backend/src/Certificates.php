<?php
declare(strict_types=1);

/**
 * The HTTPS certificate twocans is serving, and the request path to replace the
 * default self-signed one with a Let's Encrypt certificate.
 *
 * nginx owns the certificate and the certbot run; this class only reads the
 * current cert's details, writes a request file the nginx entrypoint loop picks
 * up, and reads back the result. The two containers meet in the shared
 * /certs and /acme directories (see compose.yaml and docker/nginx/entrypoint.sh).
 */
final class Certificates
{
    private const CERTS = '/certs';
    private const ACME = '/acme';

    /**
     * @return array{exists:bool,selfSigned:bool,issuer:string,validTo:string,validToTs:int,daysLeft:int}
     */
    public function status(): array
    {
        $pem = self::CERTS . '/fullchain.pem';

        if (!is_file($pem)) {
            return ['exists' => false, 'selfSigned' => true, 'issuer' => '', 'validTo' => '', 'validToTs' => 0, 'daysLeft' => 0];
        }

        $cert = @openssl_x509_parse((string) file_get_contents($pem));
        if ($cert === false) {
            return ['exists' => true, 'selfSigned' => true, 'issuer' => 'unreadable', 'validTo' => '', 'validToTs' => 0, 'daysLeft' => 0];
        }

        $issuer = (string) ($cert['issuer']['CN'] ?? '');
        $subject = (string) ($cert['subject']['CN'] ?? '');
        $validToTs = (int) ($cert['validTo_time_t'] ?? 0);
        $daysLeft = $validToTs > 0 ? (int) floor(($validToTs - time()) / 86400) : 0;

        return [
            'exists' => true,
            'selfSigned' => $issuer === $subject,
            'issuer' => $issuer !== '' ? $issuer : $subject,
            'validTo' => $validToTs > 0 ? date('j M Y', $validToTs) : '',
            'validToTs' => $validToTs,
            'daysLeft' => $daysLeft,
        ];
    }

    /** The domain the certificate is for — the household's external address. */
    public function domain(): ?string
    {
        $name = (string) (new DynamicDnsRepository())->get()['hostname'];

        return $name === '' ? null : $name;
    }

    /** The Cloudflare API token for DNS-01, or null when unavailable. */
    private function cloudflareToken(): ?string
    {
        try {
            return (new DynamicDnsRepository())->apiToken();
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Ask nginx to obtain a Let's Encrypt certificate for the external address.
     *
     * @return array{ok:bool,error:?string}
     */
    public function request(string $email): array
    {
        $domain = $this->domain();
        if ($domain === null) {
            return ['ok' => false, 'error' => 'Set the external address first — that is the domain the certificate is for.'];
        }

        // DNS-01 proves ownership via a Cloudflare TXT record, so certbot needs
        // the token in a credentials file (kept 0600 because certbot refuses a
        // world-readable one).
        $token = $this->cloudflareToken();
        if ($token === null) {
            return ['ok' => false, 'error' => 'No Cloudflare API token is saved — add one in the Cloudflare section above first.'];
        }

        $dir = self::ACME . '/requests';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // Clear any previous result so the screen does not show a stale "issued".
        @unlink(self::ACME . '/results/' . $domain . '.result');

        if (@file_put_contents(self::ACME . '/cloudflare.ini', "dns_cloudflare_api_token = " . $token . "\n") === false) {
            return ['ok' => false, 'error' => 'Could not write the Cloudflare credentials for certbot.'];
        }
        @chmod(self::ACME . '/cloudflare.ini', 0600);

        if (@file_put_contents($dir . '/' . $domain . '.request', trim($email)) === false) {
            return ['ok' => false, 'error' => 'Could not write the certificate request.'];
        }

        return ['ok' => true, 'error' => null];
    }

    /** Whether a request is still waiting for nginx to run certbot. */
    public function pending(): bool
    {
        $domain = $this->domain();

        return $domain !== null && is_file(self::ACME . '/requests/' . $domain . '.request');
    }

    /** The result of the last certbot run for this domain, if any. */
    public function result(): ?string
    {
        $domain = $this->domain();
        if ($domain === null) {
            return null;
        }

        $path = self::ACME . '/results/' . $domain . '.result';
        if (!is_file($path)) {
            return null;
        }

        $content = trim((string) file_get_contents($path));

        return $content === '' ? null : $content;
    }
}
