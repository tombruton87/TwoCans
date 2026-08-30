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
    private const BASE = 'https://api.twilio.com/2010-04-01';

    public function __construct(
        private string $sid,
        private string $token,
    ) {
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

    /** @return array{balance:float,currency:string} */
    public function balance(): array
    {
        $res = $this->get('/Accounts/' . rawurlencode($this->sid) . '/Balance.json');

        if ($res['status'] >= 200 && $res['status'] < 300) {
            return [
                'balance' => (float) ($res['data']['balance'] ?? 0.0),
                'currency' => (string) ($res['data']['currency'] ?? '$'),
            ];
        }

        return ['balance' => 0.0, 'currency' => '$'];
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
     * Perform an authenticated GET and decode the JSON response.
     *
     * @return array{status:int,data:array,message:string}
     */
    private function get(string $path): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => 'Authorization: Basic ' . base64_encode($this->sid . ':' . $this->token) . "\r\n"
                      . "Accept: application/json\r\n",
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
