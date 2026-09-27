<?php
declare(strict_types=1);

/**
 * A small MQTT 3.1.1 client — just what the Home Assistant bridge needs.
 *
 * Connect (with a username, password and a last will, so Home Assistant marks
 * twocans unavailable if the bridge dies), publish (QoS 0 or 1, retained or
 * not), subscribe, keep-alive pings, and reading what the broker sends. Plain
 * TCP or TLS. No library: the project has no Composer step, and the protocol
 * subset is a few hundred bytes of framing.
 *
 * Reads are buffered, so a long-running loop can stream_select() on stream()
 * and call poll() whenever it is readable: complete packets come back as
 * messages, a half-arrived one waits in the buffer for the rest.
 */
final class Mqtt
{
    private const CONNECT = 1;
    private const CONNACK = 2;
    private const PUBLISH = 3;
    private const PUBACK = 4;
    private const SUBSCRIBE = 8;
    private const SUBACK = 9;
    private const PINGREQ = 12;
    private const PINGRESP = 13;
    private const DISCONNECT = 14;

    /** @var resource|null */
    private $socket = null;
    private string $buffer = '';
    private int $packetId = 0;
    private int $lastSent = 0;

    public function __construct(
        private string $host,
        private int $port = 1883,
        private string $username = '',
        private string $password = '',
        private string $clientId = 'twocans',
        private bool $tls = false,
        private int $keepAlive = 60,
    ) {
    }

    /**
     * @param array{topic:string,payload:string,retain:bool}|null $will
     * @throws RuntimeException when the broker can't be reached or says no
     */
    public function connect(?array $will = null, int $timeout = 5): void
    {
        $scheme = $this->tls ? 'tls' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("Can't reach the MQTT broker at {$this->host}:{$this->port} — {$errstr}");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $timeout);

        $flags = 0x02;                                   // clean session
        $payload = self::str($this->clientId);
        if ($will !== null) {
            $flags |= 0x04 | ($will['retain'] ? 0x20 : 0);
            $payload .= self::str($will['topic']) . self::str($will['payload']);
        }
        if ($this->username !== '') {
            $flags |= 0x80;
            $payload .= self::str($this->username);
            if ($this->password !== '') {
                $flags |= 0x40;
                $payload .= self::str($this->password);
            }
        }
        $variable = self::str('MQTT') . chr(4) . chr($flags) . pack('n', $this->keepAlive);
        $this->write(self::CONNECT << 4, $variable . $payload);

        $reply = $this->readPacketBlocking();
        if ($reply === null || $reply['type'] !== self::CONNACK) {
            $this->close();
            throw new RuntimeException("The MQTT broker didn't answer the login.");
        }
        $code = ord($reply['body'][1] ?? "\x05");
        if ($code !== 0) {
            $this->close();
            throw new RuntimeException(match ($code) {
                1 => 'The MQTT broker refused the protocol version.',
                2 => 'The MQTT broker refused the client id.',
                3 => 'The MQTT broker is unavailable.',
                4 => 'The MQTT broker rejected that username or password.',
                5 => 'The MQTT broker says this login may not connect.',
                default => 'The MQTT broker refused the connection (code ' . $code . ').',
            });
        }
        stream_set_blocking($this->socket, false);
    }

    public function publish(string $topic, string $payload, bool $retain = false, int $qos = 0): void
    {
        $header = (self::PUBLISH << 4) | ($qos > 0 ? 0x02 : 0) | ($retain ? 0x01 : 0);
        $body = self::str($topic) . ($qos > 0 ? pack('n', $this->nextId()) : '') . $payload;
        $this->write($header, $body);
    }

    /** @param array<int,string> $topics */
    public function subscribe(array $topics): void
    {
        $body = pack('n', $this->nextId());
        foreach ($topics as $topic) {
            $body .= self::str($topic) . chr(0);
        }
        $this->write((self::SUBSCRIBE << 4) | 0x02, $body);
    }

    /** Keep the connection alive: call now and then; pings only when due. */
    public function keepAlive(): void
    {
        if (time() - $this->lastSent >= (int) ($this->keepAlive * 0.6)) {
            $this->write(self::PINGREQ << 4, '');
        }
    }

    /**
     * Read whatever has arrived and return the messages it completes.
     *
     * @return array<int,array{topic:string,payload:string}>
     */
    public function poll(): array
    {
        if ($this->socket === null) {
            throw new RuntimeException('Not connected to the MQTT broker.');
        }
        $chunk = @fread($this->socket, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            $this->close();
            throw new RuntimeException('The MQTT broker closed the connection.');
        }
        $this->buffer .= $chunk;

        $messages = [];
        while (($packet = self::take($this->buffer)) !== null) {
            if ($packet['type'] === self::PUBLISH) {
                $qos = ($packet['flags'] >> 1) & 0x03;
                $length = unpack('n', substr($packet['body'], 0, 2))[1];
                $topic = substr($packet['body'], 2, $length);
                $offset = 2 + $length;
                if ($qos > 0) {
                    $id = substr($packet['body'], $offset, 2);
                    $offset += 2;
                    $this->write(self::PUBACK << 4, $id);
                }
                $messages[] = ['topic' => $topic, 'payload' => (string) substr($packet['body'], $offset)];
            }
            // CONNACK, SUBACK, PUBACK, PINGRESP: nothing to do.
        }

        return $messages;
    }

    /** @return resource|null */
    public function stream()
    {
        return $this->socket;
    }

    public function connected(): bool
    {
        return $this->socket !== null;
    }

    public function disconnect(): void
    {
        if ($this->socket !== null) {
            @fwrite($this->socket, chr(self::DISCONNECT << 4) . chr(0));
        }
        $this->close();
    }

    // -------------------------------------------------------------- framing

    private function write(int $header, string $body): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('Not connected to the MQTT broker.');
        }
        $packet = chr($header) . self::length(strlen($body)) . $body;
        $sent = 0;
        while ($sent < strlen($packet)) {
            $n = @fwrite($this->socket, substr($packet, $sent));
            if ($n === false) {
                $this->close();
                throw new RuntimeException('Lost the connection to the MQTT broker.');
            }
            if ($n === 0) {
                usleep(2000);   // non-blocking socket, send buffer full: wait a moment
                continue;
            }
            $sent += $n;
        }
        $this->lastSent = time();
    }

    /** Only while connecting, before the socket goes non-blocking. */
    private function readPacketBlocking(): ?array
    {
        while (($packet = self::take($this->buffer)) === null) {
            $chunk = fread($this->socket, 4096);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $this->buffer .= $chunk;
        }

        return $packet;
    }

    /**
     * Cut one complete packet off the front of $buffer, or null if it isn't
     * all here yet.
     *
     * @return array{type:int,flags:int,body:string}|null
     */
    public static function take(string &$buffer): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }
        $multiplier = 1;
        $length = 0;
        $i = 1;
        do {
            if (!isset($buffer[$i])) {
                return null;
            }
            $byte = ord($buffer[$i]);
            $length += ($byte & 0x7f) * $multiplier;
            $multiplier *= 128;
            $i++;
        } while ($byte & 0x80 && $i < 5);

        if (strlen($buffer) < $i + $length) {
            return null;
        }
        $first = ord($buffer[0]);
        $packet = ['type' => $first >> 4, 'flags' => $first & 0x0f, 'body' => (string) substr($buffer, $i, $length)];
        $buffer = (string) substr($buffer, $i + $length);

        return $packet;
    }

    public static function length(int $n): string
    {
        $out = '';
        do {
            $byte = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        } while ($n > 0);

        return $out;
    }

    private static function str(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }

    private function nextId(): int
    {
        $this->packetId = $this->packetId % 65535 + 1;

        return $this->packetId;
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';
    }
}
