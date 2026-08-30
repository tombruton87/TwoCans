<?php
declare(strict_types=1);

/**
 * Uptime Kuma "Push" monitor heartbeat.
 *
 * The box GETs the push URL every minute; when the heartbeats stop, Uptime Kuma
 * marks twocans down and alerts through its own notification channels. There is
 * no auth beyond the token baked into the push URL itself.
 */
final class UptimeKuma
{
    /** @return array{ok:bool,error?:string} */
    public static function heartbeat(string $pushUrl, string $msg = ''): array
    {
        if ($pushUrl === '') {
            return ['ok' => false, 'error' => 'No Uptime Kuma push URL set'];
        }

        $sep = str_contains($pushUrl, '?') ? '&' : '?';
        $url = $pushUrl . $sep . http_build_query([
            'status' => 'up',
            'msg' => $msg !== '' ? $msg : 'twocans heartbeat',
        ]);

        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true]]);
        @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }

        return $status >= 200 && $status < 300
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'Uptime Kuma returned HTTP ' . $status];
    }
}
