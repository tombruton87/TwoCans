<?php
declare(strict_types=1);

/**
 * Minimal Asterisk Manager Interface client.
 *
 * AMI interleaves unsolicited events with action replies — logging in is
 * immediately followed by a FullyBooted event — so every action carries an
 * ActionID and replies that don't match are skipped.
 *
 * Uses core stream functions; ext-sockets is not required.
 */
final class Ami
{
    private $socket = null;
    private int $sequence = 0;

    public function __construct(
        private string $host = '',
        private int $port = 0,
        private string $username = '',
        private string $password = '',
        private int $timeout = 5,
    ) {
        $this->host = $host !== '' ? $host : (getenv('AMI_HOST') ?: 'asterisk');
        $this->port = $port > 0 ? $port : (int) (getenv('AMI_PORT') ?: 5038);
        $this->username = $username !== '' ? $username : (getenv('AMI_USERNAME') ?: '');
        $this->password = $password !== '' ? $password : (getenv('AMI_PASSWORD') ?: '');
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /** @throws RuntimeException when Asterisk is unreachable or rejects the login. */
    public function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }

        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout
        );

        if ($socket === false) {
            throw new RuntimeException("Cannot reach Asterisk on {$this->host}:{$this->port} — {$errstr}");
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);
        fgets($this->socket);                       // banner

        $login = $this->send('Login', ['Username' => $this->username, 'Secret' => $this->password]);
        if (($login['response'] ?? '') !== 'Success') {
            $this->disconnect();
            throw new RuntimeException('Asterisk rejected the AMI login — check AMI_USERNAME / AMI_PASSWORD.');
        }
    }

    public function disconnect(): void
    {
        if ($this->socket === null) {
            return;
        }
        @fwrite($this->socket, "Action: Logoff\r\n\r\n");
        @fclose($this->socket);
        $this->socket = null;
    }

    /**
     * Send an action and return its reply, skipping unrelated events.
     *
     * @return array<string,string|array> lower-cased keys; repeated `Output`
     *                                    headers are collected into an array
     */
    public function send(string $action, array $fields = []): array
    {
        if ($this->socket === null) {
            $this->connect();
        }

        $id = 'twocans-' . (++$this->sequence) . '-' . bin2hex(random_bytes(3));

        $message = "Action: {$action}\r\nActionID: {$id}\r\n";
        foreach ($fields as $key => $value) {
            $message .= "{$key}: {$value}\r\n";
        }
        fwrite($this->socket, $message . "\r\n");

        $deadline = time() + $this->timeout;
        while (time() < $deadline) {
            $block = $this->readBlock();
            if ($block === []) {
                continue;
            }
            if (($block['actionid'] ?? null) === $id) {
                return $block;
            }
        }

        return [];
    }

    /**
     * Ring a device and, when it answers, run it through the dialplan.
     *
     * Async so the web request returns straight away instead of blocking for
     * the whole ring timeout. The reply therefore only confirms Asterisk
     * accepted the request — not that anyone picked up.
     */
    public function originate(
        string $channel,
        string $context,
        string $extension,
        string $callerId,
        int $ringSeconds = 30,
    ): array {
        return $this->send('Originate', [
            'Channel' => $channel,
            'Context' => $context,
            'Exten' => $extension,
            'Priority' => 1,
            'CallerID' => $callerId,
            'Timeout' => $ringSeconds * 1000,
            'Async' => 'true',
        ]);
    }

    /** Run a CLI command and return its output lines. */
    public function command(string $command): array
    {
        $reply = $this->send('Command', ['Command' => $command]);

        return (array) ($reply['output'] ?? []);
    }

    /** Apply generated config without dropping active calls. */
    public function reloadPjsip(): bool
    {
        return ($this->send('Reload', ['Module' => 'res_pjsip'])['response'] ?? '') === 'Success';
    }

    public function reloadDialplan(): bool
    {
        return ($this->send('Reload', ['Module' => 'pbx_config'])['response'] ?? '') === 'Success';
    }

    /**
     * Endpoint names that currently have a registered contact.
     *
     * @return array<string,bool>
     */
    public function registeredEndpoints(): array
    {
        $online = [];
        foreach ($this->command('pjsip show aors') as $line) {
            /*
             * Rows look like:
             *   Contact:  my-phone-a1b2/sip:my-phone@10.0.0.5 6621a3f93e Avail 12.030
             *
             * Note there is no leading indentation here even though `asterisk
             * -rx` shows some — AMI strips it. The header row also begins with
             * "Contact:", so the endpoint group refuses the "<" it starts with.
             */
            if (!preg_match('/^\s*Contact:\s+([^\/\s<]+)\/\S+\s+\S+\s+(\S+)/', $line, $m)) {
                continue;
            }

            $aor = $m[1];

            // A contact exists, so the device is registered. "Unavail" means it
            // registered but has stopped answering qualify probes; anything else
            // (Avail, or NonQual before the first probe) counts as reachable.
            $reachable = !str_starts_with($m[2], 'Unavail');

            /*
             * An AOR can hold several contacts — a phone that moved to a new
             * source port leaves the old one behind until it expires. The
             * device is online if ANY contact is reachable, so OR them together
             * rather than letting the last row listed win: a stale Unavail
             * contact would otherwise mask a perfectly healthy one.
             */
            $online[$aor] = ($online[$aor] ?? false) || $reachable;
        }

        return $online;
    }

    private function readBlock(): array
    {
        $block = [];
        while (($line = fgets($this->socket)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                break;
            }
            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $key = strtolower(trim($key));
            $value = trim($value);

            if ($key === 'output') {
                $block['output'][] = $value;
            } else {
                $block[$key] = $value;
            }
        }

        return $block;
    }
}
