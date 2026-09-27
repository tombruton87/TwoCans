<?php
declare(strict_types=1);

/**
 * Minimal Twilio REST client for the trunk wizard — verifies the Account SID +
 * auth token, confirms the number is voice-capable, and reads the balance.
 *
 * Uses core stream functions like the rest of the codebase (no Composer
 * dependency), with HTTP Basic auth against api.twilio.com.
 */
final class Twilio
{
    /**
     * The Twilio Regions a household might be in.
     *
     * Regions are isolated from one another: a trunk created in ie1 cannot be
     * seen from the default us1 API, and each region issues its own auth token.
     * The default region has no edge in its hostname; the others are addressed
     * as <product>.<edge>.<region>.twilio.com.
     */
    public const REGIONS = [
        'us1' => ['label' => 'United States (default)', 'edge' => null],
        'ie1' => ['label' => 'Ireland (IE1)', 'edge' => 'dublin'],
        'au1' => ['label' => 'Australia (AU1)', 'edge' => 'sydney'],
    ];

    public function __construct(
        private string $sid,
        private string $token,
        private string $region = 'us1',
    ) {
        $this->region = self::normalizeRegion($region);
    }

    /** Unknown values fall back to the default region rather than a bad host. */
    public static function normalizeRegion(string $region): string
    {
        $region = strtolower(trim($region));

        return isset(self::REGIONS[$region]) ? $region : 'us1';
    }

    /**
     * Base URL for one of Twilio's products in this line's region.
     *
     * The REST API and Elastic SIP Trunking are separate hosts with separate
     * version prefixes, and both move together when the region does.
     */
    private function base(string $product): string
    {
        $edge = self::REGIONS[$this->region]['edge'];
        $host = $edge === null
            ? $product . '.twilio.com'
            : $product . '.' . $edge . '.' . $this->region . '.twilio.com';

        return 'https://' . $host . ($product === 'api' ? '/2010-04-01' : '/v1');
    }

    /**
     * Verify credentials by fetching the account.
     *
     * @return array{ok:bool,error:?string,friendly_name:?string}
     */
    public function verify(): array
    {
        $res = $this->get('/Accounts/' . rawurlencode($this->sid) . '.json');

        if ($res['status'] === 0) {
            return ['ok' => false, 'error' => 'Could not reach Twilio — check the network and try again.', 'friendly_name' => null];
        }
        if ($res['status'] === 401 || $res['status'] === 403) {
            // The commonest cause once regions are in play: the right token
            // for the wrong region. Twilio's own message does not say that.
            return [
                'ok' => false,
                'friendly_name' => null,
                'error' => 'Twilio rejected those credentials for the '
                    . (string) self::REGIONS[$this->region]['label']
                    . ' region. Each region has its own auth token — check you copied the one for this region, not the default.',
            ];
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $message = $res['message'] !== '' ? $res['message'] : 'Those credentials were rejected by Twilio.';
            return ['ok' => false, 'error' => $message, 'friendly_name' => null];
        }

        return [
            'ok' => true,
            'error' => null,
            'friendly_name' => (string) ($res['data']['friendly_name'] ?? ''),
        ];
    }

    /**
     * Account credit, or null when Twilio will not tell us.
     *
     * Not every region serves this endpoint — ie1 answers 404 — and a failure
     * must not be reported as 0.00. A fabricated zero reads as an empty
     * account and trips the low-credit warning, which is worse than showing
     * nothing. The currency is an ISO 4217 code; Presenter::money() turns it
     * into a symbol.
     *
     * @return array{balance:?float,currency:string}
     */
    public function balance(): array
    {
        $res = $this->get('/Accounts/' . rawurlencode($this->sid) . '/Balance.json');

        if ($res['status'] >= 200 && $res['status'] < 300 && isset($res['data']['balance'])) {
            return [
                'balance' => (float) $res['data']['balance'],
                'currency' => self::currencyCode($res['data']['currency'] ?? null),
            ];
        }

        return ['balance' => null, 'currency' => 'USD'];
    }

    /**
     * Accept only a well-formed three-letter code. The column is CHAR(3), so
     * anything longer would be a write error rather than a display oddity.
     */
    private static function currencyCode(mixed $raw): string
    {
        $code = strtoupper(trim((string) $raw));

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : 'USD';
    }

    /**
     * Confirm a number belongs to the account and can carry voice calls.
     *
     * @return array{ok:bool,error:?string}
     */
    public function number(string $e164): array
    {
        $res = $this->get('/Accounts/' . rawurlencode($this->sid) . '/IncomingPhoneNumbers.json?PhoneNumber=' . rawurlencode($e164));

        if ($res['status'] === 0) {
            return ['ok' => false, 'error' => 'Could not reach Twilio while checking the number.'];
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'error' => $res['message'] !== '' ? $res['message'] : 'Twilio could not look that number up.'];
        }

        $numbers = $res['data']['incoming_phone_numbers'] ?? [];
        if (count($numbers) === 0) {
            return ['ok' => false, 'error' => 'That number was not found on this Twilio account.'];
        }

        if (!((bool) ($numbers[0]['capabilities']['voice'] ?? false))) {
            return ['ok' => false, 'error' => 'That number is not voice-capable — choose a number that can make and receive calls.'];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Check the termination host is a trunk that can actually carry this line.
     *
     * The wizard used to take the host on trust, so a typo — or a trunk the
     * household never got round to creating — was stored as a working line and
     * only showed up later as calls that went nowhere. Three things have to
     * hold: the trunk exists, inbound calls have somewhere to go, and the
     * number is attached to it.
     *
     * @return array{ok:bool,error:?string}
     */
    public function trunk(string $host, string $e164): array
    {
        $res = $this->fetch($this->base('trunking') . '/Trunks?PageSize=50');

        if ($res['status'] === 0) {
            return ['ok' => false, 'error' => 'Could not reach Twilio while checking the SIP trunk.'];
        }
        if ($res['status'] === 401 || $res['status'] === 403) {
            return ['ok' => false, 'error' => 'Twilio rejected those credentials for the '
                . (string) self::REGIONS[$this->region]['label']
                . ' region — each region has its own auth token.'];
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'error' => $res['message'] !== '' ? $res['message'] : 'Twilio could not list the SIP trunks on this account.'];
        }

        $trunks = $res['data']['trunks'] ?? [];
        $match = null;
        foreach ($trunks as $trunk) {
            if (strcasecmp((string) ($trunk['domain_name'] ?? ''), $host) === 0) {
                $match = $trunk;
                break;
            }
        }

        if ($match === null) {
            return ['ok' => false, 'error' => $this->noTrunkMessage($host, $trunks)];
        }

        $sid = (string) $match['sid'];

        // Origination is what points calls back at this house. Without it the
        // number rings inside Twilio and stops there.
        $origination = $this->fetch($this->base('trunking') . '/Trunks/' . rawurlencode($sid) . '/OriginationUrls');
        $enabled = array_filter(
            $origination['data']['origination_urls'] ?? [],
            static fn(array $u): bool => (bool) ($u['enabled'] ?? false)
        );
        if ($enabled === []) {
            return ['ok' => false, 'error' => 'That trunk has no enabled Origination URI, so Twilio has nowhere to send incoming calls. Add one in Twilio under Elastic SIP Trunking → your trunk → Origination.'];
        }

        // And the number has to be on the trunk, or it keeps its old routing.
        $numbers = $this->fetch($this->base('trunking') . '/Trunks/' . rawurlencode($sid) . '/PhoneNumbers?PageSize=50');
        foreach ($numbers['data']['phone_numbers'] ?? [] as $n) {
            if ((string) ($n['phone_number'] ?? '') === $e164) {
                return ['ok' => true, 'error' => null];
            }
        }

        return ['ok' => false, 'error' => $e164 . ' is not attached to that trunk. In Twilio, open Elastic SIP Trunking → your trunk → Numbers and add it.'];
    }

    /**
     * @param array<int,array> $trunks
     */
    private function noTrunkMessage(string $host, array $trunks): string
    {
        $names = array_filter(array_map(
            static fn(array $t): string => (string) ($t['domain_name'] ?? ''),
            $trunks
        ));

        $where = 'in the ' . (string) self::REGIONS[$this->region]['label'] . ' region';

        if ($names === []) {
            return 'This Twilio account has no SIP trunks ' . $where
                 . '. Either create one under Elastic SIP Trunking → Trunks, or pick the region your trunk is actually in — a trunk in one region is invisible from another.';
        }

        return 'No trunk ' . $where . ' has the address ' . $host . '. Trunks there: ' . implode(', ', $names) . '.';
    }

    /**
     * Perform an authenticated GET and decode the JSON response.
     *
     * @return array{status:int,data:array,message:string}
     */
    private function get(string $path): array
    {
        return $this->fetch($this->base('api') . $path);
    }

    /**
     * @return array{status:int,data:array,message:string}
     */
    private function fetch(string $url): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => 'Authorization: Basic ' . base64_encode($this->sid . ':' . $this->token) . "\r\n"
                      . "Accept: application/json\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);

        $http_response_header = null;
        $body = @file_get_contents($url, false, $context);

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        $data = [];
        $message = '';
        if ($body !== false && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $data = $decoded;
                $message = (string) ($decoded['message'] ?? '');
            }
        }

        return ['status' => $status, 'data' => $data, 'message' => $message];
    }
}
