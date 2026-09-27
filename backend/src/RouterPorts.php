<?php
declare(strict_types=1);

/**
 * Talking to the house's router about opening ports: UPnP (Internet Gateway
 * Device) first, NAT-PMP when that isn't there. No library — the project has
 * no Composer step, and both protocols are a handful of messages.
 *
 * Discovery asks the router directly (unicast M-SEARCH) rather than shouting
 * at the multicast group. From inside Docker's bridge network the multicast
 * question goes out but the answers never come back: they arrive from the
 * router's own address, which the NAT has no record of having asked. A
 * question sent to the router's address comes back through the NAT like any
 * other reply.
 *
 * The mapping is requested for the host's LAN address. The request reaches
 * the router from that address too — Docker rewrites the container's source
 * to the host's — which matters: most routers only let a device open ports
 * for itself.
 */
final class RouterPorts
{
    private const SSDP_PORT = 1900;
    private const NATPMP_PORT = 5351;

    /** The WAN services that can open ports, newest first. */
    private const SERVICES = [
        'urn:schemas-upnp-org:service:WANIPConnection:2',
        'urn:schemas-upnp-org:service:WANIPConnection:1',
        'urn:schemas-upnp-org:service:WANPPPConnection:1',
    ];

    /** @var array{method:string,router:string,control?:string,service?:string,name?:string}|null */
    private ?array $gateway = null;

    /** Set once the router refuses a timed opening: ask for permanent ones from then on. */
    private bool $permanentOnly = false;

    public function __construct(private string $router, private int $timeout = 2)
    {
    }

    /**
     * Find something on the router that will open ports.
     *
     * @return array{method:string,router:string,name:string}|null method is 'upnp' or 'natpmp'
     */
    public function find(): ?array
    {
        foreach (['upnp' => fn() => $this->findUpnp(), 'natpmp' => fn() => $this->findNatPmp()] as $found) {
            $gateway = $found();
            if ($gateway !== null) {
                $this->gateway = $gateway;

                return ['method' => $gateway['method'], 'router' => $gateway['router'], 'name' => $gateway['name'] ?? ''];
            }
        }

        return null;
    }

    /** The address the router has on the internet, or null. */
    public function externalIp(): ?string
    {
        $g = $this->gateway ?? throw new LogicException('find() first');
        if ($g['method'] === 'upnp') {
            $reply = $this->soap('GetExternalIPAddress', []);

            return $reply['ok'] ? ($reply['values']['NewExternalIPAddress'] ?? null) : null;
        }
        $reply = $this->natPmp("\x00\x00", 12);
        if ($reply === null || self::u16($reply, 2) !== 0) {
            return null;
        }

        return inet_ntop(substr($reply, 8, 4)) ?: null;
    }

    /**
     * Open one port through to $client, the same number outside and in.
     *
     * @return array{ok:bool,note:string}
     */
    public function open(string $proto, int $port, string $client, string $label, int $lease): array
    {
        $g = $this->gateway ?? throw new LogicException('find() first');
        $proto = strtoupper($proto);

        if ($g['method'] === 'natpmp') {
            $op = $proto === 'UDP' ? 1 : 2;
            $reply = $this->natPmp(pack('CCnnnN', 0, $op, 0, $port, $port, $lease), 16);
            if ($reply === null) {
                return ['ok' => false, 'note' => "The router didn't answer."];
            }
            $result = self::u16($reply, 2);
            if ($result !== 0) {
                return ['ok' => false, 'note' => self::natPmpError($result)];
            }
            $given = self::u16($reply, 10);
            if ($given === $port) {
                return ['ok' => true, 'note' => ''];
            }
            // No use to SIP, which names its own port: take it back.
            $this->natPmp(pack('CCnnnN', 0, $op, 0, $port, 0, 0), 16);

            return ['ok' => false, 'note' => "The router would only open port {$given} for it, and it has to be {$port}."];
        }

        $args = [
            'NewRemoteHost' => '',
            'NewExternalPort' => (string) $port,
            'NewProtocol' => $proto,
            'NewInternalPort' => (string) $port,
            'NewInternalClient' => $client,
            'NewEnabled' => '1',
            'NewPortMappingDescription' => 'twocans ' . $label,
            'NewLeaseDuration' => (string) ($this->permanentOnly ? 0 : $lease),
        ];
        $reply = $this->soap('AddPortMapping', $args);
        if (!$reply['ok'] && $reply['code'] === 725 && !$this->permanentOnly) {
            // OnlyPermanentLeasesSupported: fine — twocans takes them away itself.
            $this->permanentOnly = true;
            $args['NewLeaseDuration'] = '0';
            $reply = $this->soap('AddPortMapping', $args);
        }
        if ($reply['ok']) {
            return ['ok' => true, 'note' => ''];
        }
        if ($reply['code'] === 718) {
            // ConflictInMappingEntry. Ours already, pointed here, is a success.
            $mine = $this->soap('GetSpecificPortMappingEntry', [
                'NewRemoteHost' => '', 'NewExternalPort' => (string) $port, 'NewProtocol' => $proto,
            ]);
            if ($mine['ok'] && ($mine['values']['NewInternalClient'] ?? '') === $client
                && (int) ($mine['values']['NewInternalPort'] ?? 0) === $port) {
                return ['ok' => true, 'note' => 'Already open to this box.'];
            }

            return ['ok' => false, 'note' => 'Already taken — by a rule on the router, or another device.'];
        }

        return ['ok' => false, 'note' => self::upnpError($reply['code'], $reply['error'])];
    }

    /** Close a port this box opened. Quietly: it may be long gone. */
    public function close(string $proto, int $port): void
    {
        $g = $this->gateway ?? throw new LogicException('find() first');
        $proto = strtoupper($proto);
        if ($g['method'] === 'natpmp') {
            // A lifetime of 0 removes the mapping.
            $this->natPmp(pack('CCnnnN', 0, $proto === 'UDP' ? 1 : 2, 0, $port, 0, 0), 16);

            return;
        }
        $this->soap('DeletePortMapping', ['NewRemoteHost' => '', 'NewExternalPort' => (string) $port, 'NewProtocol' => $proto]);
    }

    // ----------------------------------------------------------------- UPnP

    private function findUpnp(): ?array
    {
        $question = "M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nMAN: \"ssdp:discover\"\r\nMX: 2\r\n"
            . "ST: urn:schemas-upnp-org:device:InternetGatewayDevice:1\r\n\r\n";

        foreach ($this->udpAsk($this->router, self::SSDP_PORT, $question, 4) as $answer) {
            if (!preg_match('/^LOCATION:\s*(\S+)/mi', $answer, $m)) {
                continue;
            }
            $description = $this->http('GET', $m[1]);
            if ($description === null) {
                continue;
            }
            // Things that answer every search (a Hue bridge will) have no
            // WAN service in their description, so they drop out here.
            $service = self::wanService($description['body'], $m[1]);
            if ($service !== null) {
                return ['method' => 'upnp', 'router' => $this->router] + $service;
            }
        }

        return null;
    }

    /**
     * The WAN connection service in a device description: its type and the
     * absolute control URL, and the router's friendly name.
     *
     * @return array{service:string,control:string,name:string}|null
     */
    public static function wanService(string $xml, string $location): ?array
    {
        $doc = new DOMDocument();
        if (@$doc->loadXML($xml, LIBXML_NONET) === false) {
            return null;
        }
        $base = trim((string) ($doc->getElementsByTagName('URLBase')->item(0)?->textContent ?? ''));
        $base = $base !== '' ? $base : $location;
        $name = trim((string) ($doc->getElementsByTagName('friendlyName')->item(0)?->textContent ?? ''));

        $found = [];
        foreach ($doc->getElementsByTagName('service') as $service) {
            $type = trim((string) ($service->getElementsByTagName('serviceType')->item(0)?->textContent ?? ''));
            $control = trim((string) ($service->getElementsByTagName('controlURL')->item(0)?->textContent ?? ''));
            if (in_array($type, self::SERVICES, true) && $control !== '') {
                $found[$type] ??= $control;
            }
        }
        foreach (self::SERVICES as $type) {
            if (isset($found[$type])) {
                return ['service' => $type, 'control' => self::resolve($base, $found[$type]), 'name' => $name];
            }
        }

        return null;
    }

    /** A relative URL from a description, made absolute against its base. */
    public static function resolve(string $base, string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');

        return $origin . $dir . $url;
    }

    /**
     * @param  array<string,string> $args in the order the action defines them
     * @return array{ok:bool,code:int,error:string,values:array<string,string>}
     */
    private function soap(string $action, array $args): array
    {
        $g = $this->gateway;
        $body = self::soapBody($g['service'], $action, $args);
        $reply = $this->http('POST', $g['control'], $body, [
            'Content-Type: text/xml; charset="utf-8"',
            'SOAPAction: "' . $g['service'] . '#' . $action . '"',
        ]);
        if ($reply === null) {
            return ['ok' => false, 'code' => 0, 'error' => "The router didn't answer.", 'values' => []];
        }

        return self::soapReply($reply['status'], $reply['body']);
    }

    public static function soapBody(string $service, string $action, array $args): string
    {
        $inner = '';
        foreach ($args as $name => $value) {
            $inner .= "<{$name}>" . htmlspecialchars($value, ENT_XML1) . "</{$name}>";
        }

        return '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            . "<s:Body><u:{$action} xmlns:u=\"{$service}\">{$inner}</u:{$action}></s:Body></s:Envelope>";
    }

    /**
     * Read a SOAP answer: the response's values, or the UPnP error in a fault.
     *
     * @return array{ok:bool,code:int,error:string,values:array<string,string>}
     */
    public static function soapReply(int $status, string $xml): array
    {
        $doc = new DOMDocument();
        if (@$doc->loadXML($xml, LIBXML_NONET) === false) {
            return ['ok' => false, 'code' => 0, 'error' => "The router's answer made no sense (HTTP {$status}).", 'values' => []];
        }
        $code = $doc->getElementsByTagName('errorCode')->item(0);
        if ($code !== null || $status >= 300) {
            $desc = $doc->getElementsByTagName('errorDescription')->item(0);

            return [
                'ok' => false,
                'code' => (int) ($code?->textContent ?? 0),
                'error' => trim((string) ($desc?->textContent ?? "HTTP {$status}")),
                'values' => [],
            ];
        }
        $values = [];
        $body = $doc->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body')->item(0);
        $response = $body?->firstElementChild;
        foreach ($response?->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement) {
                $values[$node->localName] = trim($node->textContent);
            }
        }

        return ['ok' => true, 'code' => 0, 'error' => '', 'values' => $values];
    }

    private static function upnpError(int $code, string $error): string
    {
        return match ($code) {
            606 => 'The router wants a password to change this, which UPnP has no way to give.',
            714, 0 => $error !== '' ? 'The router said no: ' . $error : 'The router said no.',
            716 => "The router won't open that port by UPnP.",
            724 => 'The router needs the same port inside and out, and refused anyway.',
            725 => 'The router only allows permanent openings, and refused this one.',
            726, 727 => "The router won't open a port for this.",
            728 => "The router's list of opened ports is full.",
            729 => 'The router says that port is already used on it.',
            default => 'The router said no (' . $code . ($error !== '' ? ': ' . $error : '') . ').',
        };
    }

    /**
     * @param  array<int,string> $headers
     * @return array{status:int,body:string}|null
     */
    private function http(string $method, string $url, string $body = '', array $headers = []): ?array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $this->timeout + 1,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return null;
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $raw];
    }

    // -------------------------------------------------------------- NAT-PMP

    private function findNatPmp(): ?array
    {
        $reply = $this->natPmp("\x00\x00", 12);
        if ($reply === null || ord($reply[1]) !== 128 || self::u16($reply, 2) !== 0) {
            return null;
        }

        return ['method' => 'natpmp', 'router' => $this->router, 'name' => ''];
    }

    private function natPmp(string $request, int $length): ?string
    {
        foreach ($this->udpAsk($this->router, self::NATPMP_PORT, $request, 1) as $reply) {
            if (strlen($reply) >= $length && ord($reply[0]) === 0) {
                return $reply;
            }
        }

        return null;
    }

    private static function natPmpError(int $result): string
    {
        return match ($result) {
            2 => 'The router has opening ports switched off.',
            3 => "The router isn't connected to the internet.",
            4 => 'The router has no room for more openings.',
            default => 'The router said no (NAT-PMP ' . $result . ').',
        };
    }

    private static function u16(string $bytes, int $offset): int
    {
        return unpack('n', substr($bytes, $offset, 2))[1];
    }

    // ----------------------------------------------------------------- UDP

    /**
     * Send one datagram and gather what comes back from that address within
     * the timeout, up to $max answers.
     *
     * @return array<int,string>
     */
    private function udpAsk(string $host, int $port, string $message, int $max): array
    {
        $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($socket === false) {
            return [];
        }
        @stream_socket_sendto($socket, $message, 0, $host . ':' . $port);

        $answers = [];
        $until = microtime(true) + $this->timeout;
        while (count($answers) < $max && ($left = $until - microtime(true)) > 0) {
            $read = [$socket];
            $none = null;
            if (!@stream_select($read, $none, $none, 0, (int) ($left * 1e6))) {
                break;
            }
            $data = stream_socket_recvfrom($socket, 8192, 0, $from);
            if ($data !== '' && $data !== false && str_starts_with((string) $from, $host . ':')) {
                $answers[] = $data;
            }
        }
        fclose($socket);

        return $answers;
    }
}
