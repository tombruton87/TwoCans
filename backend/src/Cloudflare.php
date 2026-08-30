<?php
declare(strict_types=1);

/**
 * Minimal Cloudflare API v4 client — just enough to keep one DNS record pointed
 * at this house.
 *
 * Uses core stream functions like the rest of the codebase (no Composer
 * dependency), authenticating with a bearer token against api.cloudflare.com.
 * Every response comes back in the same envelope — `{success, errors[], result}`
 * — so decoding it, and turning `errors[0].message` into something a parent can
 * read, happens in request() and nowhere else.
 *
 * The token this is given should be scoped to the one zone, with Zone → Zone →
 * Read and Zone → DNS → Edit. Nothing here needs any more than that.
 */
final class Cloudflare
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    /** Cloudflare's floor for an explicit TTL; 1 would mean "automatic". */
    public const MIN_TTL = 60;

    public function __construct(private string $token)
    {
    }

    /**
     * The zone id for a domain, or null when the token cannot see it.
     *
     * This is also the token check: a token that cannot list the zone is either
     * wrong or missing Zone → Zone → Read, and either way there is no point
     * continuing. (Cloudflare's /user/tokens/verify endpoint is deliberately not
     * used — it rejects some otherwise-fine scoped tokens with "Invalid API
     * Token", while the real zone call below accepts them.)
     *
     * @return array{ok:bool,error:?string,id:?string}
     */
    public function zoneId(string $zone): array
    {
        $res = $this->request('GET', '/zones?name=' . rawurlencode($zone));

        if (!$this->succeeded($res)) {
            return ['ok' => false, 'error' => $this->reason($res, 'Cloudflare could not look that domain up.'), 'id' => null];
        }

        $zones = $res['data']['result'] ?? [];
        if (!is_array($zones) || $zones === []) {
            return [
                'ok' => false,
                'id' => null,
                'error' => $zone . ' is not on this Cloudflare account, or the token cannot see it — '
                    . 'it needs Zone → Zone → Read for that domain.',
            ];
        }

        return ['ok' => true, 'error' => null, 'id' => (string) ($zones[0]['id'] ?? '')];
    }

    /**
     * Find an existing record for a name.
     *
     * Looked up rather than assumed, so a record somebody already created by
     * hand is adopted instead of duplicated — two A records for one name would
     * send half the traffic to a stale address.
     *
     * @return array{ok:bool,error:?string,id:?string,content:?string,proxied:bool}
     */
    public function findRecord(string $zoneId, string $type, string $name): array
    {
        $res = $this->request(
            'GET',
            '/zones/' . rawurlencode($zoneId) . '/dns_records'
            . '?type=' . rawurlencode($type) . '&name=' . rawurlencode($name)
        );

        if (!$this->succeeded($res)) {
            return [
                'ok' => false,
                'error' => $this->reason($res, 'Cloudflare could not list the records for that domain.'),
                'id' => null, 'content' => null, 'proxied' => false,
            ];
        }

        $records = $res['data']['result'] ?? [];
        if (!is_array($records) || $records === []) {
            return ['ok' => true, 'error' => null, 'id' => null, 'content' => null, 'proxied' => false];
        }

        return [
            'ok' => true,
            'error' => null,
            'id' => (string) ($records[0]['id'] ?? ''),
            'content' => (string) ($records[0]['content'] ?? ''),
            'proxied' => (bool) ($records[0]['proxied'] ?? false),
        ];
    }

    /**
     * Create the record.
     *
     * @param  array{type:string,name:string,content:string,ttl:int,proxied:bool} $record
     * @return array{ok:bool,error:?string,id:?string}
     */
    public function createRecord(string $zoneId, array $record): array
    {
        $res = $this->request('POST', '/zones/' . rawurlencode($zoneId) . '/dns_records', $this->body($record));

        if (!$this->succeeded($res)) {
            return ['ok' => false, 'error' => $this->reason($res, 'Cloudflare would not create that record.'), 'id' => null];
        }

        return ['ok' => true, 'error' => null, 'id' => (string) ($res['data']['result']['id'] ?? '')];
    }

    /**
     * Point an existing record somewhere else.
     *
     * PUT with the whole record rather than PATCH with only the address: the
     * record is entirely ours, and sending all of it re-asserts the TTL and the
     * proxy setting every time rather than leaving them as whatever they were.
     *
     * @param  array{type:string,name:string,content:string,ttl:int,proxied:bool} $record
     * @return array{ok:bool,error:?string}
     */
    public function updateRecord(string $zoneId, string $recordId, array $record): array
    {
        $res = $this->request(
            'PUT',
            '/zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($recordId),
            $this->body($record)
        );

        if (!$this->succeeded($res)) {
            return ['ok' => false, 'error' => $this->reason($res, 'Cloudflare would not update that record.')];
        }

        return ['ok' => true, 'error' => null];
    }

    /** The record as Cloudflare wants it, with a comment saying who wrote it. */
    private function body(array $record): array
    {
        return [
            'type' => (string) $record['type'],
            'name' => (string) $record['name'],
            'content' => (string) $record['content'],
            'ttl' => max(self::MIN_TTL, (int) $record['ttl']),
            'proxied' => (bool) $record['proxied'],
            'comment' => 'twocans dynamic DNS',
        ];
    }

    /** HTTP 2xx *and* Cloudflare's own flag — it can answer 200 and still refuse. */
    private function succeeded(array $res): bool
    {
        return $res['status'] >= 200 && $res['status'] < 300
            && ($res['data']['success'] ?? false) === true;
    }

    /** The most specific reason available for a failure. */
    private function reason(array $res, string $fallback): string
    {
        if ($res['status'] === 0) {
            return 'Could not reach Cloudflare — check the network and try again.';
        }
        if ($res['error'] !== '') {
            return 'Cloudflare said: ' . $res['error'];
        }

        return $fallback . ' (HTTP ' . $res['status'] . ')';
    }

    /**
     * Perform an authenticated request and decode the envelope.
     *
     * @return array{status:int,data:array,error:string}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $header = 'Authorization: Bearer ' . $this->token . "\r\n"
                . "Accept: application/json\r\n";

        $options = [
            'method' => $method,
            'timeout' => 10,
            // Read the body of a 4xx: that is where Cloudflare says what is wrong.
            'ignore_errors' => true,
        ];

        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);
            $header .= "Content-Type: application/json\r\n";
            $options['content'] = $encoded === false ? '{}' : $encoded;
        }

        $options['header'] = $header;

        $http_response_header = null;
        $raw = @file_get_contents(self::BASE . $path, false, stream_context_create(['http' => $options]));

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        $data = [];
        $error = '';
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
                $error = trim((string) ($decoded['errors'][0]['message'] ?? ''));
            }
        }

        return ['status' => $status, 'data' => $data, 'error' => $error];
    }
}
