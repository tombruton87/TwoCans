<?php
declare(strict_types=1);

/**
 * Paging Grandstream desk phones the way they page natively: the message sent
 * as multicast RTP, which a phone set to listen plays straight through its
 * speaker — no call to answer, no open microphone.
 *
 * Asterisk runs on Docker's private network, where multicast can't reach the
 * house's. So the sending is done by bin/pager.php, in a container of its own
 * on the host's network: the web app leaves it a job in a folder they share
 * (queue()), and it streams the recording to each phone's address (play()).
 * No port is opened for this, and nothing but the web app can queue a job.
 *
 * Each phone listens on its own port of one group address (port()), set in its
 * settings file by GrandstreamProvisioning; a page to several phones is sent
 * to each of theirs.
 */
final class Pager
{
    /** Administratively scoped (239.255/16): stays inside the house. */
    public const GROUP = '239.255.84.84';

    public const BASE_PORT = 7100;

    /** A pager that hasn't checked in for this long is taken to be gone. */
    private const ALIVE_SECONDS = 15;

    /** 20 ms of 8 kHz audio: the packet size phones expect. */
    private const SAMPLES_PER_PACKET = 160;

    /** The port a phone listens on for pages to it: even, as RTP's are. */
    public static function port(int $deviceId): int
    {
        return self::BASE_PORT + 2 * $deviceId;
    }

    /** What goes in a phone's listening address. */
    public static function address(int $deviceId): string
    {
        return self::GROUP . ':' . self::port($deviceId);
    }

    public static function path(): string
    {
        return rtrim(getenv('PAGER_PATH') ?: '/var/lib/twocans/pager', '/');
    }

    /** Whether bin/pager.php is running and can take a page. */
    public static function alive(): bool
    {
        $beat = @filemtime(self::path() . '/alive');

        return $beat !== false && time() - $beat <= self::ALIVE_SECONDS;
    }

    /**
     * Whether a phone fetching its settings from $ip is on this house's own
     * network — where the pages can reach it. The same /24 as SIP_DOMAIN: a
     * page crosses no router, and a phone further away is paged with a call.
     */
    public static function onHomeNetwork(string $ip): bool
    {
        $home = (string) (getenv('SIP_DOMAIN') ?: '');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($home, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        return (ip2long($ip) & -256) === (ip2long($home) & -256);
    }

    /**
     * Leave the pager a page to send.
     *
     * @param array<int>    $ports  the phones' ports
     * @param array<string> $files  WAV recordings, 8 kHz mono 16-bit, played in turn
     */
    public static function queue(array $ports, array $files): bool
    {
        $dir = self::path() . '/queue';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }
        $job = json_encode(['ports' => array_values(array_map('intval', $ports)), 'files' => array_values($files), 'at' => time()]);
        $name = sprintf('%s/%.6f-%s', $dir, microtime(true), bin2hex(random_bytes(4)));

        // Written aside, then renamed in: the pager never reads half a job.
        if (@file_put_contents($name . '.tmp', (string) $job) === false) {
            return false;
        }

        return @rename($name . '.tmp', $name . '.json');
    }

    /**
     * A WAV file's samples, or null when it isn't the 8 kHz mono 16-bit the
     * app records and converts to.
     */
    public static function pcm(string $file): ?string
    {
        $data = @file_get_contents($file);
        if ($data === false || strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
            return null;
        }
        $pos = 12;
        $format = null;
        while ($pos + 8 <= strlen($data)) {
            $id = substr($data, $pos, 4);
            $size = unpack('V', substr($data, $pos + 4, 4))[1];
            if ($id === 'fmt ') {
                $format = unpack('vcodec/vchannels/Vrate/Vbytes/valign/vbits', substr($data, $pos + 8, 16));
            } elseif ($id === 'data') {
                if ($format === null || $format['codec'] !== 1 || $format['channels'] !== 1
                    || $format['rate'] !== 8000 || $format['bits'] !== 16) {
                    return null;
                }

                return substr($data, $pos + 8, $size);
            }
            $pos += 8 + $size + ($size % 2);
        }

        return null;
    }

    /** One 16-bit sample as G.711 µ-law — what every phone can play. */
    public static function ulaw(int $sample): int
    {
        $sign = $sample < 0 ? 0x80 : 0;
        $magnitude = min(abs($sample), 32635) + 0x84;
        $exponent = 7;
        for ($mask = 0x4000; ($magnitude & $mask) === 0 && $exponent > 0; $mask >>= 1) {
            $exponent--;
        }
        $mantissa = ($magnitude >> ($exponent + 3)) & 0x0F;

        return ~($sign | ($exponent << 4) | $mantissa) & 0xFF;
    }

    /**
     * The audio for a page, as µ-law: a moment of silence while the phones
     * open their speakers, then each recording with a pause between.
     *
     * @param array<string> $files
     */
    public static function audio(array $files): string
    {
        $out = str_repeat("\xFF", 2400); // 0.3 s of µ-law silence
        foreach ($files as $i => $file) {
            $pcm = self::pcm($file);
            if ($pcm === null) {
                continue;
            }
            if ($i > 0) {
                $out .= str_repeat("\xFF", 16000); // 2 s between repeats
            }
            foreach (unpack('v*', $pcm) ?: [] as $u) {
                $out .= chr(self::ulaw($u >= 0x8000 ? $u - 0x10000 : $u));
            }
        }

        return $out;
    }

    /** RTP packet $n of a stream: PCMU (payload type 0), 20 ms each. */
    public static function packet(string $payload, int $n, int $ssrc): string
    {
        $marker = $n === 0 ? 0x80 : 0x00;

        return pack('CCnNN', 0x80, $marker, $n & 0xFFFF, ($n * self::SAMPLES_PER_PACKET) & 0xFFFFFFFF, $ssrc) . $payload;
    }

    /**
     * Stream a page to the phones' ports, in real time. Blocks until done.
     *
     * @param array<int>    $ports
     * @param array<string> $files
     */
    public static function play(array $ports, array $files): int
    {
        $audio = self::audio($files);
        $sockets = [];
        foreach (array_unique($ports) as $port) {
            $s = @stream_socket_client('udp://' . self::GROUP . ':' . (int) $port, $errno, $error);
            if ($s !== false) {
                $sockets[] = $s;
            }
        }
        if ($sockets === [] || strlen($audio) <= 2400) {
            return 0;
        }

        $ssrc = random_int(1, 0x7FFFFFFF);
        $chunks = str_split($audio, self::SAMPLES_PER_PACKET);
        $start = hrtime(true);
        foreach ($chunks as $n => $chunk) {
            $chunk = str_pad($chunk, self::SAMPLES_PER_PACKET, "\xFF");
            $packet = self::packet($chunk, $n, $ssrc);
            foreach ($sockets as $s) {
                @fwrite($s, $packet);
            }
            // Keep to the clock, not to how long sending took.
            $due = $start + ($n + 1) * 20_000_000;
            $wait = $due - hrtime(true);
            if ($wait > 0) {
                usleep(intdiv($wait, 1000));
            }
        }
        foreach ($sockets as $s) {
            fclose($s);
        }

        return count($chunks);
    }

    // ------------------------------------------------------------ finding phones

    /** Grandstream's MAC prefixes. */
    public const GRANDSTREAM_OUIS = ['000B82', 'C074AD', 'EC74D7'];

    /**
     * Ask the pager to look for Grandstream phones on the home network: the
     * /24 that $home (SIP_DOMAIN, this machine's address) is on.
     */
    public static function queueScan(string $home): bool
    {
        if (!filter_var($home, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $dir = self::path() . '/queue';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }
        $name = sprintf('%s/%.6f-scan', $dir, microtime(true));
        if (@file_put_contents($name . '.tmp', (string) json_encode(['type' => 'scan', 'home' => $home, 'at' => time()])) === false) {
            return false;
        }

        return @rename($name . '.tmp', $name . '.json');
    }

    /**
     * What the last scan found, and when; a scan still running has no 'at'.
     *
     * @return array{at:?int,running:bool,found:array<int,array{ip:string,mac:string,model:string}>}
     */
    public static function scanResults(): array
    {
        $data = json_decode((string) @file_get_contents(self::path() . '/scan.json'), true);

        return [
            'at' => is_array($data) && isset($data['at']) ? (int) $data['at'] : null,
            'running' => is_array($data) && !empty($data['running']),
            'found' => is_array($data) ? array_values(array_filter((array) ($data['found'] ?? []), 'is_array')) : [],
        ];
    }

    /** Whether a MAC (12 hex digits) is a Grandstream's. */
    public static function isGrandstream(string $mac): bool
    {
        return in_array(strtoupper(substr($mac, 0, 6)), self::GRANDSTREAM_OUIS, true);
    }

    /**
     * Grandstreams in an ARP table (/proc/net/arp), on the home /24.
     *
     * @return array<string,string> ip => MAC, 12 uppercase hex digits
     */
    public static function grandstreamsIn(string $arp, string $home): array
    {
        $prefix = implode('.', array_slice(explode('.', $home), 0, 3)) . '.';
        $found = [];
        foreach (preg_split('/\R/', $arp) ?: [] as $line) {
            $cols = preg_split('/\s+/', trim($line)) ?: [];
            if (count($cols) < 4 || !str_starts_with($cols[0], $prefix) || $cols[2] === '0x0') {
                continue;
            }
            $mac = strtoupper(str_replace(':', '', $cols[3]));
            if (preg_match('/^[0-9A-F]{12}$/', $mac) && self::isGrandstream($mac)) {
                $found[$cols[0]] = $mac;
            }
        }

        return $found;
    }

    /** The model a GHP says it is, unasked-for credentials not needed; '' if it won't say. */
    public static function askModel(string $ip): string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $body = @file_get_contents('http://' . $ip . '/json/configs/model.define.js', false, $ctx);
        $json = json_decode($body === false ? '' : $body, true);

        return is_array($json) ? substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($json['model'] ?? '')) ?? '', 0, 20) : '';
    }

    /**
     * Look over the home /24 for Grandstreams: a packet to every address makes
     * the host learn each one's MAC, which the ARP table then shows; each
     * Grandstream is asked what model it is. Needs the host's network, so it
     * runs in the pager. Writes scan.json.
     */
    public static function scan(string $home): array
    {
        $out = self::path() . '/scan.json';
        @file_put_contents($out, (string) json_encode(['running' => true, 'found' => []]));

        $prefix = implode('.', array_slice(explode('.', $home), 0, 3));
        for ($i = 1; $i <= 254; $i++) {
            $s = @stream_socket_client('udp://' . $prefix . '.' . $i . ':9', $errno, $error);
            if ($s !== false) {
                @fwrite($s, 'twocans');
                fclose($s);
            }
        }
        sleep(3); // time for every address to answer ARP

        $found = [];
        foreach (self::grandstreamsIn((string) @file_get_contents('/proc/net/arp'), $home) as $ip => $mac) {
            $found[] = ['ip' => $ip, 'mac' => $mac, 'model' => self::askModel($ip)];
        }
        @file_put_contents($out, (string) json_encode(['at' => time(), 'found' => $found]));

        return $found;
    }
}
