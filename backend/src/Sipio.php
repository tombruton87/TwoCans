<?php
declare(strict_types=1);

/**
 * Minimal SIP.IO REST client for the trunk wizard — verifies an API key via
 * /v1/whoami and checks whether a number belongs to the account.
 *
 * SIP.IO authenticates with an `x-api-key: sk_…` header against api.sip.io.
 * Uses core stream functions, matching the rest of the codebase.
 */
final class Sipio
{
    private const BASE = 'https://api.sip.io/v1';

    public function __construct(private string $apiKey)
    {
    }

    /**
     * Verify the API key by asking the API who it belongs to.
     *
     * @return array{ok:bool,error:?string,account_id:?string}
     */
    public function verify(): array
    {
        $res = $this->get('/whoami');

        if ($res['status'] === 0) {
            return ['ok' => false, 'error' => 'Could not reach SIP.IO — check the network and try again.', 'account_id' => null];
        }
        if ($res['status'] === 401) {
            return ['ok' => false, 'error' => 'That API key was rejected by SIP.IO.', 'account_id' => null];
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'error' => $res['error'] !== '' ? $res['error'] : ('SIP.IO returned HTTP ' . $res['status']), 'account_id' => null];
        }

        return [
            'ok' => true,
            'error' => null,
            'account_id' => (string) ($res['data']['accountId'] ?? ''),
        ];
    }

    /**
     * Whether the given E.164 number is one of the account's DIDs.
     *
     * @return 'found'|'not_found'|'unavailable'  'unavailable' means the number
     *         list could not be read (no scope, network, etc.) — the caller
     *         should not treat that as a failure.
     */
    public function numberExists(string $e164): string
    {
        $res = $this->get('/numbers');

        if ($res['status'] < 200 || $res['status'] >= 300) {
            return 'unavailable';
        }

        $digits = preg_replace('/\D/', '', $e164) ?? '';

        return $digits !== '' && $this->containsDigits($res['data'], $digits) ? 'found' : 'not_found';
    }

    /** Recursively look for a scalar whose digits equal $digits (matches `e164`). */
    private function containsDigits(mixed $data, string $digits): bool
    {
        if (is_string($data)) {
            return preg_replace('/\D/', '', $data) === $digits;
        }
        if (is_array($data)) {
            foreach ($data as $value) {
                if ($this->containsDigits($value, $digits)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{status:int,data:array,error:string} */
    private function get(string $path): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "x-api-key: {$this->apiKey}\r\nAccept: application/json\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);

        $http_response_header = null;
        $body = @file_get_contents(self::BASE . $path, false, $context);

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        $data = [];
        $error = '';
        if ($body !== false && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $data = $decoded;
                $error = (string) ($decoded['error'] ?? '');
            }
        }

        return ['status' => $status, 'data' => $data, 'error' => $error];
    }
}
