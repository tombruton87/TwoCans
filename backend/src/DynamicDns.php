<?php
declare(strict_types=1);

/**
 * Keeping a name you own pointed at this house.
 *
 * Home broadband addresses move without warning, usually at three in the
 * morning, and nothing tells you. So this looks up the address every minute and
 * only writes to Cloudflare when it has actually changed — which is once every
 * few weeks, not once a minute.
 *
 * There is no separate container and no cron. The check runs from inside the
 * app's own container (see docker/php/entrypoint.sh), one short-lived process a
 * minute: a wedged run costs a minute, not a service, and there is nothing extra
 * to install, monitor or explain to somebody who just wants a phone for their
 * child.
 *
 * A minute of drift is the trade being made. The record's TTL is 60 seconds, so
 * in the worst case a changed address takes about two minutes to be right
 * everywhere — which for a house is nothing, and for a pipeline of DNS caches is
 * about as good as dynamic DNS gets.
 */
final class DynamicDns
{
    /** Leave at least this long between checks, whoever is asking. */
    public const INTERVAL_SECONDS = 60;

    /**
     * Re-confirm the record even when the address has not moved.
     *
     * Guards against the record being changed or deleted behind our back — in
     * the Cloudflare dashboard, by another tool, by a half-finished migration.
     * Without this, twocans would happily believe a record that no longer exists.
     */
    private const REFRESH_SECONDS = 86400;

    /** How stale a check has to be before the interface calls it a problem. */
    public const STALE_SECONDS = 300;

    /**
     * Who to ask for this network's public address, in order.
     *
     * Cloudflare first, because it is the same company as the DNS: if it cannot
     * be reached, the update was not going to work anyway. The other two are
     * there so a single provider having a bad day doesn't strand the record.
     */
    private const IP_SOURCES = [
        'https://cloudflare.com/cdn-cgi/trace',
        'https://api.ipify.org',
        'https://checkip.amazonaws.com',
    ];

    public function __construct(private DynamicDnsRepository $dns = new DynamicDnsRepository())
    {
    }

    /**
     * Look up this network's address and make the record agree with it.
     *
     * Safe to call as often as you like: everything past the interval guard is
     * idempotent, and two overlapping runs would write the same record twice
     * rather than fight.
     *
     * @param  bool $force ignore the once-a-minute guard (the CLI, and the
     *                     "Update now" button, where somebody is watching)
     * @return array{ran:bool,changed:bool,ip:?string,hostname:string,error:?string,message:string}
     */
    public function sync(bool $force = false): array
    {
        $config = $this->dns->get();

        $result = [
            'ran' => false,
            'changed' => false,
            'ip' => $config['ip'] === '' ? null : $config['ip'],
            'hostname' => $config['hostname'],
            'error' => null,
            'message' => 'Dynamic DNS is off.',
        ];

        if (!$force && !self::older($config['checkedAt'], self::INTERVAL_SECONDS)) {
            $result['message'] = 'Checked less than a minute ago.';

            return $result;
        }

        $ip = self::publicIp();
        if ($ip === null) {
            return $this->failed($result, "Could not work out this network's public address.");
        }

        $result['ran'] = true;
        $result['ip'] = $ip;

        /*
         * Not set up yet, or paused: there is no record to touch, but the
         * interface still wants to show this network's address and prove the
         * minute checks are happening. Record the address and stop before any
         * Cloudflare call is made.
         */
        if (!$config['enabled'] || !$config['configured']) {
            $this->dns->recordAddress($ip);
            $result['message'] = 'Dynamic DNS is off — this network is on ' . $ip . '.';

            return $result;
        }

        /*
         * The ordinary case, thousands of times between changes: the address is
         * the one we last saw and the record was confirmed recently. One small
         * database write, no API call, no Cloudflare rate limit to worry about.
         */
        if (!$force
            && $config['ip'] === $ip
            && $config['recordId'] !== ''
            && !self::older($config['updatedAt'], self::REFRESH_SECONDS)) {
            $this->dns->recordCheck($ip);
            $result['message'] = $config['hostname'] . ' still points at ' . $ip . '.';

            return $result;
        }

        try {
            $token = $this->dns->apiToken();
        } catch (RuntimeException $e) {
            return $this->failed($result, 'Could not read the saved API token — ' . $e->getMessage());
        }

        if ($token === null) {
            return $this->failed($result, 'There is no API token saved — set dynamic DNS up again.');
        }

        return $this->publish(new Cloudflare($token), $config, $ip, $result);
    }

    /**
     * This network's public address, or null if nobody would tell us.
     *
     * Static because it answers a question about the machine, not about the
     * household's settings — the setup form uses it before anything is saved.
     */
    public static function publicIp(): ?string
    {
        foreach (self::IP_SOURCES as $url) {
            $body = @file_get_contents($url, false, stream_context_create(['http' => [
                'method' => 'GET',
                'header' => "Accept: text/plain\r\n",
                'timeout' => 5,
                'ignore_errors' => true,
            ]]));

            if ($body === false || $body === '') {
                continue;
            }

            // Cloudflare's trace replies with key=value lines; the other two
            // reply with the bare address.
            $candidate = preg_match('/^ip=(\S+)$/m', $body, $m) === 1 ? $m[1] : trim($body);

            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Find, create or repoint the record.
     *
     * The record is looked up rather than assumed even when its id is cached:
     * that is what makes a record deleted in the dashboard heal itself instead
     * of failing forever with "not found".
     *
     * @param  array<string,mixed> $config
     * @param  array<string,mixed> $result
     * @return array{ran:bool,changed:bool,ip:?string,hostname:string,error:?string,message:string}
     */
    private function publish(Cloudflare $cloudflare, array $config, string $ip, array $result): array
    {
        $hostname = (string) $config['hostname'];
        $type = (string) $config['recordType'];

        $zoneId = (string) $config['zoneId'];
        if ($zoneId === '') {
            $lookup = $cloudflare->zoneId((string) $config['zone']);
            if (!$lookup['ok']) {
                return $this->failed($result, (string) $lookup['error']);
            }
            $zoneId = (string) $lookup['id'];
            $this->dns->rememberZoneId($zoneId);
        }

        $existing = $cloudflare->findRecord($zoneId, $type, $hostname);
        if (!$existing['ok']) {
            return $this->failed($result, (string) $existing['error']);
        }

        $record = [
            'type' => $type,
            'name' => $hostname,
            'content' => $ip,
            'ttl' => (int) $config['ttl'],
            'proxied' => (bool) $config['proxied'],
        ];

        if ($existing['id'] === null) {
            $created = $cloudflare->createRecord($zoneId, $record);
            if (!$created['ok']) {
                return $this->failed($result, (string) $created['error']);
            }

            $this->dns->recordConfirmed($ip, (string) $created['id']);
            $result['changed'] = true;
            $result['message'] = $hostname . ' created, pointing at ' . $ip . '.';

            return $result;
        }

        // Already right — adopt it. A name somebody set up by hand, or left over
        // from a previous install, is a record we should use rather than fight.
        if ($existing['content'] === $ip) {
            $this->dns->recordConfirmed($ip, (string) $existing['id']);
            $result['message'] = $hostname . ' already points at ' . $ip . '.';

            return $result;
        }

        $updated = $cloudflare->updateRecord($zoneId, (string) $existing['id'], $record);
        if (!$updated['ok']) {
            return $this->failed($result, (string) $updated['error']);
        }

        $was = (string) $existing['content'];
        $this->dns->recordConfirmed($ip, (string) $existing['id']);
        $result['changed'] = true;
        $result['message'] = $hostname . ' now points at ' . $ip
            . ($was !== '' ? ' (was ' . $was . ').' : '.');

        return $result;
    }

    /**
     * Note a failure against the setup and shape it for the caller.
     *
     * Failures are recorded, not thrown: a check that cannot reach Cloudflare
     * must not take a page render or the next minute's attempt with it.
     *
     * @param  array<string,mixed> $result
     * @return array{ran:bool,changed:bool,ip:?string,hostname:string,error:?string,message:string}
     */
    private function failed(array $result, string $error): array
    {
        $this->dns->recordError($error);

        $result['ran'] = true;
        $result['error'] = $error;
        $result['message'] = $error;

        return $result;
    }

    /** Whether a stored timestamp is missing, unreadable, or older than a window. */
    private static function older(?string $timestamp, int $window): bool
    {
        if ($timestamp === null || $timestamp === '') {
            return true;
        }

        $at = strtotime($timestamp);

        return $at === false || (time() - $at) >= $window;
    }
}
