<?php
declare(strict_types=1);

/**
 * Which of twocans' ports the outside world needs, and — when the household
 * lets it — asking the router to open them (RouterPorts: UPnP or NAT-PMP),
 * keeping them open, and closing them again.
 *
 * Openings are asked for with a lease and renewed by the minute worker
 * (bin/notify.php), so if twocans is switched off or removed, the router
 * closes them by itself within the hour. Only what twocans opened is ever
 * closed: a rule set up by hand on the router is left alone.
 *
 * Everything is kept in the settings table:
 *   ports_auto    '1' to open them automatically
 *   ports_router  the router's address, when the guess is wrong
 *   ports_open    which groups (see GROUPS), comma-separated
 *   ports_state   JSON: what happened last time, for the Phone line screen
 *   ports_mapped  JSON: [proto, port] pairs twocans opened, to close later
 */
final class PortOpener
{
    /** How long each opening lasts before the router drops it by itself. */
    public const LEASE = 3600;

    /** How often the minute worker renews them — well inside the lease. */
    private const RENEW_EVERY = 1500;

    /** What can be opened, in the order the screen lists them. */
    public const GROUPS = [
        'web' => [
            'label' => 'The app and self-service links',
            'why' => 'Opening twocans from outside the house, and the links grandparents are sent.',
        ],
        'trunk' => [
            'label' => 'Calls from your phone line',
            'why' => 'Your provider sends incoming calls here.',
        ],
        'rtp' => [
            'label' => 'The sound of those calls',
            'why' => "The voices, both ways, on calls with the outside world.",
        ],
        'away' => [
            'label' => 'Phones away from home',
            'why' => 'A Linphone phone signing in over mobile data. Leave off unless you use one.',
        ],
    ];

    /** Picked when nobody has chosen yet: everything but away-from-home phones. */
    private const DEFAULT_GROUPS = ['web', 'trunk', 'rtp'];

    public function __construct(private ?SettingsRepository $settings = null)
    {
        $this->settings ??= new SettingsRepository();
    }

    // -------------------------------------------------------------- what

    /**
     * Every port a group needs: the same number outside and in, pointed at
     * this box.
     *
     * @return array<string,array<int,array{proto:string,port:int}>> group => ports
     */
    public static function ports(): array
    {
        [$rtpFrom, $rtpTo] = self::rtpRange();

        return [
            'web' => [['proto' => 'TCP', 'port' => (int) (getenv('HTTPS_PORT') ?: 443)]],
            'trunk' => [['proto' => 'UDP', 'port' => PjsipConfig::trunkPort()]],
            'rtp' => array_map(
                static fn(int $p): array => ['proto' => 'UDP', 'port' => $p],
                range($rtpFrom, $rtpTo)
            ),
            'away' => [
                ['proto' => 'UDP', 'port' => PjsipConfig::handsetPort('udp')],
                ['proto' => 'TCP', 'port' => PjsipConfig::handsetPort('udp')],
                ['proto' => 'TCP', 'port' => PjsipConfig::handsetPort('tls')],
            ],
        ];
    }

    /**
     * The RTP range as the host publishes it: RTP_PORT_START/END when compose
     * passed them on, else Asterisk's own rtp.conf.
     *
     * @return array{0:int,1:int}
     */
    public static function rtpRange(): array
    {
        $from = (int) (getenv('RTP_PORT_START') ?: 0);
        $to = (int) (getenv('RTP_PORT_END') ?: 0);
        if ($from <= 0 || $to < $from) {
            $conf = (string) @file_get_contents('/etc/asterisk/rtp.conf');
            $from = preg_match('/^\s*rtpstart\s*=\s*(\d+)/m', $conf, $m) ? (int) $m[1] : 10000;
            $to = preg_match('/^\s*rtpend\s*=\s*(\d+)/m', $conf, $m) ? (int) $m[1] : 10100;
        }

        return [$from, max($from, $to)];
    }

    /** "5060", or "10000–10100" for a run. */
    public static function describe(array $ports): string
    {
        $numbers = array_column($ports, 'port');
        if (count($numbers) > 1 && max($numbers) - min($numbers) === count($numbers) - 1) {
            return min($numbers) . '–' . max($numbers);
        }

        return implode(', ', array_unique($numbers));
    }

    /** A group's ports by protocol: "10000–10100 UDP", "5070 UDP · 5070, 5071 TCP". */
    public static function describeGroup(array $ports): string
    {
        $parts = [];
        foreach (['UDP', 'TCP'] as $proto) {
            $these = array_values(array_filter($ports, static fn(array $p): bool => $p['proto'] === $proto));
            if ($these !== []) {
                $parts[] = self::describe($these) . ' ' . $proto;
            }
        }

        return implode(' · ', $parts);
    }

    /** The groups the household picked (or the default). */
    public function groups(): array
    {
        $raw = trim((string) ($this->settings->all()['ports_open'] ?? ''));
        if ($raw === '') {
            return self::DEFAULT_GROUPS;
        }

        return array_values(array_intersect(array_keys(self::GROUPS), explode(',', $raw)));
    }

    public function auto(): bool
    {
        return ($this->settings->all()['ports_auto'] ?? '') === '1';
    }

    public function routerSetting(): string
    {
        return trim((string) ($this->settings->all()['ports_router'] ?? ''));
    }

    /** The router's address: the one set, else .1 on the box's own network. */
    public function router(): string
    {
        $set = $this->routerSetting();
        if ($set !== '') {
            return $set;
        }

        return self::guessRouter(self::lanAddress());
    }

    public static function guessRouter(string $lan): string
    {
        return preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', $lan, $m) ? $m[1] . '.1' : '';
    }

    /** This box on the house network — where the openings point. */
    public static function lanAddress(): string
    {
        return (string) (getenv('SIP_DOMAIN') ?: '');
    }

    /** @param array<int,string> $groups */
    public function save(bool $auto, array $groups, string $router): void
    {
        $groups = array_values(array_intersect(array_keys(self::GROUPS), $groups));
        $this->settings->set('ports_auto', $auto ? '1' : '0');
        // An empty choice is a real choice: 'none' keeps it from reading as the default.
        $this->settings->set('ports_open', $groups === [] ? 'none' : implode(',', $groups));
        $this->settings->set('ports_router', trim($router));
    }

    /**
     * What happened last time, for the screen.
     *
     * @return array{at:int,method:string,router:string,name:string,externalIp:string,error:string,warning:string,ports:array<string,array{ok:bool,note:string}>}
     */
    public function state(): array
    {
        $state = json_decode((string) ($this->settings->all()['ports_state'] ?? ''), true);

        return (is_array($state) ? $state : []) + [
            'at' => 0, 'method' => '', 'router' => '', 'name' => '', 'externalIp' => '',
            'error' => '', 'warning' => '', 'ports' => [],
        ];
    }

    // --------------------------------------------------------------- doing

    /**
     * The minute worker's step: renew when due, close what is no longer
     * wanted. Returns a line worth logging, or null.
     */
    public function maintain(): ?string
    {
        $mapped = $this->mapped();
        if (!$this->auto()) {
            if ($mapped === []) {
                return null;
            }
            $this->closeAll();

            return 'closed the ports twocans had opened';
        }
        if (time() - $this->state()['at'] < self::RENEW_EVERY) {
            return null;
        }
        $state = $this->run();

        return $state['error'] !== '' ? 'router ports: ' . $state['error'] : null;
    }

    /**
     * Ask the router for everything wanted, and close what twocans opened
     * that no longer is. Saves and returns the outcome.
     */
    public function run(?RouterPorts $router = null): array
    {
        $state = [
            'at' => time(), 'method' => '', 'router' => $this->router(), 'name' => '',
            'externalIp' => '', 'error' => '', 'warning' => '', 'ports' => [],
        ];
        $client = self::lanAddress();

        if ($state['router'] === '' || $client === '') {
            $state['error'] = "twocans doesn't know this box's address on the house network (SIP_DOMAIN).";

            return $this->remember($state);
        }

        $router ??= new RouterPorts($state['router']);
        $found = $router->find();
        if ($found === null) {
            $state['error'] = "The router at {$state['router']} didn't offer to open ports. Switch on UPnP "
                . '(or NAT-PMP) in its settings — or, if that isn\'t your router\'s address, set it below.';

            return $this->remember($state);
        }
        $state['method'] = $found['method'];
        $state['name'] = $found['name'];
        $state['externalIp'] = (string) ($router->externalIp() ?? '');
        if ($state['externalIp'] !== '' && self::isPrivate($state['externalIp'])) {
            $state['warning'] = "The router's own internet address is {$state['externalIp']}, which is private: "
                . 'there is another router in front of it (or your provider shares addresses), so opening '
                . "ports here won't reach the house from outside. A Cloudflare Tunnel gets round that for the app.";
        }

        $wanted = [];
        $all = self::ports();
        foreach ($this->groups() as $group) {
            foreach ($all[$group] as $p) {
                $key = $p['proto'] . ' ' . $p['port'];
                if (isset($wanted[$key])) {
                    continue;
                }
                $wanted[$key] = $p;
                $state['ports'][$key] = $router->open($p['proto'], $p['port'], $client, $group, self::LEASE);
            }
        }

        // Close what twocans opened and no longer wants.
        $mapped = [];
        foreach ($this->mapped() as [$proto, $port]) {
            if (!isset($wanted[$proto . ' ' . $port])) {
                $router->close($proto, (int) $port);
            }
        }
        foreach ($state['ports'] as $key => $result) {
            if ($result['ok']) {
                [$proto, $port] = explode(' ', $key);
                $mapped[] = [$proto, (int) $port];
            }
        }
        $this->settings->set('ports_mapped', (string) json_encode($mapped));

        $failed = count(array_filter($state['ports'], static fn(array $r): bool => !$r['ok']));
        if ($failed > 0) {
            $state['error'] = $failed === count($state['ports'])
                ? 'The router refused every port.'
                : "The router refused {$failed} of " . count($state['ports']) . ' ports.';
        }

        return $this->remember($state);
    }

    /** Close everything twocans opened, e.g. when switched off. */
    public function closeAll(?RouterPorts $router = null): void
    {
        $router ??= new RouterPorts($this->router());
        if ($router->find() !== null) {
            foreach ($this->mapped() as [$proto, $port]) {
                $router->close($proto, (int) $port);
            }
        }
        // Whether or not the router answered: the leases run out by themselves.
        $this->settings->set('ports_mapped', '[]');
        $this->settings->set('ports_state', '');
    }

    /** @return array<int,array{0:string,1:int}> */
    private function mapped(): array
    {
        $mapped = json_decode((string) ($this->settings->all()['ports_mapped'] ?? ''), true);

        return is_array($mapped) ? $mapped : [];
    }

    private function remember(array $state): array
    {
        $this->settings->set('ports_state', (string) json_encode($state));

        return $state;
    }

    /** A private or shared (CGNAT) address — not reachable from the internet. */
    public static function isPrivate(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $long = ip2long($ip);
        foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16'] as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if ((ip2long($net) & $mask) === ($long & $mask)) {
                return true;
            }
        }

        return false;
    }
}
