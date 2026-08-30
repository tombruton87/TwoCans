<?php
declare(strict_types=1);

/**
 * Minimal Mailgun messages client — just enough to send one email.
 *
 * Uses core stream functions like the rest of the codebase (no Composer
 * dependency), authenticating with HTTP basic where the username is "api" and
 * the password is the Mailgun API key.
 */
final class Mailgun
{
    private const BASES = [
        'us' => 'https://api.mailgun.net',
        'eu' => 'https://api.eu.mailgun.net',
    ];

    public function __construct(
        private string $apiKey,
        private string $region = 'us',
        private string $domain = '',
    ) {
    }

    public static function baseUrl(string $region): string
    {
        return self::BASES[$region] ?? self::BASES['us'];
    }

    /** @return array{ok:bool,error?:string} */
    public function send(string $from, string $to, string $subject, string $text): array
    {
        if ($this->apiKey === '' || $this->domain === '') {
            return ['ok' => false, 'error' => 'Mailgun is not configured'];
        }

        $url = self::baseUrl($this->region) . '/v3/' . rawurlencode($this->domain) . '/messages';
        $body = http_build_query([
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'text' => $text,
        ]);

        $auth = base64_encode('api:' . $this->apiKey);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Basic {$auth}\r\n"
                      . "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);

        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($status >= 200 && $status < 300) {
            return ['ok' => true];
        }

        $message = '';
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            $message = trim((string) ($decoded['message'] ?? ''));
        }

        return ['ok' => false, 'error' => $message !== '' ? 'Mailgun said: ' . $message : 'Mailgun returned HTTP ' . $status];
    }
}
