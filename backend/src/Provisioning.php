<?php
declare(strict_types=1);

/**
 * Linphone remote provisioning: hand a phone its whole configuration by QR code
 * instead of asking a parent to type a SIP username, password, domain and
 * transport correctly on a touchscreen.
 *
 * Linphone fetches an XML config from a URL — the QR code just carries that URL
 * — and applies it. The format is <config><section name><entry name>, which maps
 * onto liblinphone's own `linphonerc` keys.
 *
 * The file contains the device's SIP password in clear, because that is exactly
 * what the phone needs to be told. So the URL is an unguessable random token
 * that expires quickly: it is worth nothing once its short window has passed.
 */
final class Provisioning
{
    /** Long enough that guessing is hopeless within the lifetime. */
    private const TOKEN_BYTES = 24;

    /** Time a parent needs to walk to the phone and scan. */
    public const TTL_SECONDS = 600;

    public function __construct(private DeviceRepository $devices = new DeviceRepository())
    {
    }

    /** Mint a fresh token for a device, retiring any it already had. */
    public function issue(int $deviceId): string
    {
        $pdo = Database::pdo();

        // Only one live token per phone: re-opening the QR should invalidate
        // the previous one rather than leave several usable.
        $pdo->prepare('DELETE FROM provisioning_tokens WHERE device_id = ?')->execute([$deviceId]);

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $pdo->prepare(
            'INSERT INTO provisioning_tokens (token, device_id, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        )->execute([$token, $deviceId, self::TTL_SECONDS]);

        return $token;
    }

    /**
     * Look up a token and return the device it was minted for.
     *
     * The token stays usable until it expires; `used_at` records the first
     * fetch for auditing only.
     *
     * @return array{device:array,error:?string}
     */
    public function redeem(string $token): array
    {
        $this->purgeExpired();

        if (!preg_match('/^[a-f0-9]{' . (self::TOKEN_BYTES * 2) . '}$/', $token)) {
            return ['device' => [], 'error' => 'not a valid setup link'];
        }

        // The token stays valid for its whole lifetime, not just its first
        // fetch. Desktop clients in particular can fetch twice (or a parent
        // opens the link in a browser to peek), and a "single use" URL that
        // 410s on the second request turns Linphone's provisioning into a
        // frustrating "http error". The 10-minute expiry below is the real
        // protection — the token itself is 24 random bytes and unguessable.
        $st = Database::pdo()->prepare(
            'SELECT * FROM provisioning_tokens WHERE token = ? AND expires_at > NOW()'
        );
        $st->execute([$token]);
        $row = $st->fetch();

        if ($row === false) {
            return ['device' => [], 'error' => 'this setup link has expired or is not valid'];
        }

        $device = $this->devices->find((int) $row['device_id']);
        if ($device === null) {
            return ['device' => [], 'error' => 'that phone no longer exists'];
        }

        // Record the first use (for auditing) without turning later fetches away.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = $ip === '' ? null : (@inet_pton($ip) ?: null);
        Database::pdo()->prepare(
            'UPDATE provisioning_tokens
                SET used_at = COALESCE(used_at, NOW()), used_by_ip = COALESCE(used_by_ip, ?)
              WHERE token = ?'
        )->execute([$ip, $token]);

        return ['device' => $device, 'error' => null];
    }

    public function purgeExpired(): void
    {
        Database::pdo()->exec(
            'DELETE FROM provisioning_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
    }

    /** The URL a phone fetches — must use the LAN address, not localhost. */
    public function url(string $token): string
    {
        $host = getenv('APP_URL');
        if ($host === false || $host === '') {
            // Fall back to the SIP domain, which is already the LAN address.
            $host = 'http://' . PjsipConfig::domain() . ':8083';
        }

        return rtrim($host, '/') . '/?provision=' . $token;
    }

    /**
     * The provisioning file itself.
     *
     * Section and entry names are liblinphone config keys: `proxy_N` holds the
     * registration, `auth_info_N` the credentials. `overwrite="true"` replaces
     * whatever the app had rather than adding a second account.
     */
    public function xml(array $deviceRow): string
    {
        $d = DeviceRepository::toView($deviceRow);
        $domain = PjsipConfig::domain();
        $transport = $d['transport'];
        $port = PjsipConfig::port($transport);

        $identity = 'sip:' . $d['sipUsername'] . '@' . $domain;
        $proxy = '<sip:' . $domain . ':' . $port . ';transport=' . $transport . '>';

        $entry = static fn(string $name, string $value): string
            => '      <entry name="' . $name . '" overwrite="true">'
             . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
             . "</entry>\n";

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<config xmlns="http://www.linphone.org/xsds/lpconfig.xsd"' . "\n";
        $xml .= '        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n";
        $xml .= '        xsi:schemaLocation="http://www.linphone.org/xsds/lpconfig.xsd lpconfig.xsd">' . "\n";

        $xml .= '  <section name="sip">' . "\n";
        $xml .= $entry('default_proxy', '0');
        // The phone is on the same wifi as the server; no ICE or STUN needed.
        $xml .= $entry('use_ipv6', '0');
        $xml .= $entry('register_only_when_network_is_up', '1');
        // A client has no business owning 5060 — that is the server's port. Use
        // random local ports (-1) so Linphone still works when it runs on the
        // same machine as Asterisk, where binding 5060 would collide.
        $xml .= $entry('sip_port', '-1');
        $xml .= $entry('sip_tcp_port', '-1');
        $xml .= '  </section>' . "\n";

        $xml .= '  <section name="proxy_0">' . "\n";
        $xml .= $entry('reg_proxy', $proxy);
        $xml .= $entry('reg_identity', $identity);
        $xml .= $entry('reg_expires', '3600');
        $xml .= $entry('reg_sendregister', '1');
        $xml .= $entry('publish', '0');
        // Keeps the registration alive through a home router's NAT timeout.
        $xml .= $entry('quality_reporting_enabled', '0');
        $xml .= '  </section>' . "\n";

        $xml .= '  <section name="auth_info_0">' . "\n";
        $xml .= $entry('username', $d['sipUsername']);
        $xml .= $entry('userid', $d['sipUsername']);
        $xml .= $entry('passwd', $d['sipSecret']);
        // Asterisk challenges with this realm by default.
        $xml .= $entry('realm', 'asterisk');
        $xml .= $entry('domain', $domain);
        $xml .= '  </section>' . "\n";

        $xml .= '  <section name="net">' . "\n";
        $xml .= $entry('firewall_policy', '0');
        $xml .= '  </section>' . "\n";

        $xml .= '</config>' . "\n";

        return $xml;
    }

    /**
     * QR code as inline SVG.
     *
     * Rendered by libqrencode rather than a vendored PHP library — it is in the
     * image already and produces clean scalable output.
     */
    public function qrSvg(string $url): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(
            ['qrencode', '-t', 'SVG', '-s', '6', '-m', '2', '-o', '-', $url],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            return '';
        }

        $svg = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // Drop the XML prolog and DOCTYPE so it can be inlined in HTML.
        $svg = preg_replace('/<\?xml.*?\?>\s*/s', '', $svg) ?? $svg;
        $svg = preg_replace('/<!DOCTYPE.*?>\s*/s', '', $svg) ?? $svg;

        return trim($svg);
    }
}
