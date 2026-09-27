<?php
declare(strict_types=1);

/**
 * The Home Assistant bridge: twocans on MQTT, with discovery.
 *
 *   php bin/homeassistant.php --watch    run for good (the container does this)
 *   php bin/homeassistant.php --test     try the broker with the saved settings
 *   php bin/homeassistant.php --status   what the bridge last reported
 *
 * One process, one loop, three sources: the MQTT broker (commands from HA),
 * Asterisk's event stream (calls ringing, answered, ended — as they happen),
 * and a timer (state every 15 seconds; new messages, asks, used-up limits and
 * bedtime starting or ending every 20). Settings are re-read each minute, so
 * saving them on the Home Assistant screen is enough — no restart. Anything
 * that drops is reconnected after a pause, and the last will marks twocans
 * unavailable in HA if this process dies.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$log = static fn(string $line): int|false => fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $line . "\n");
$ha = new HomeAssistant();

if (in_array('--status', $argv, true)) {
    $c = $ha->config();
    $s = $ha->status();
    echo "twocans — Home Assistant\n", str_repeat('=', 52), "\n";
    printf("  enabled     %s\n", $c['enabled'] ? 'yes' : 'no');
    printf("  broker      %s\n", $c['configured'] ? $c['host'] . ':' . $c['port'] . ($c['tls'] ? ' (TLS)' : '') : 'not set');
    printf("  topics      %s/…  discovery %s/…\n", $c['base'], $c['prefix']);
    printf("  connected   %s\n", !empty($s['connected']) ? 'yes' : 'no');
    printf("  last seen   %s\n", isset($s['at']) ? date('Y-m-d H:i:s', (int) $s['at']) : 'never');
    if (!empty($s['error'])) {
        printf("  last error  %s\n", $s['error']);
    }
    exit(0);
}

if (in_array('--test', $argv, true)) {
    $error = HomeAssistantBridge::test($ha);
    echo $error === null ? "Connected to the broker ✓\n" : "Failed: {$error}\n";
    exit($error === null ? 0 : 1);
}

$watch = in_array('--watch', $argv, true);

// Only ever one bridge: whoever starts it — the notifier's minute check, the
// container's supervisor, a hand — the lock decides. Held until this exits.
$lock = fopen(HomeAssistant::LOCK, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$log('Home Assistant bridge starting');

do {
    $config = $ha->config();
    if (!$config['enabled'] || !$config['configured']) {
        sleep(15);
        continue;
    }
    try {
        (new HomeAssistantBridge($ha, $log))->run();
    } catch (Throwable $e) {
        // A database connection the server has timed out comes back fresh.
        Database::reset();
        SettingsRepository::forget();
        try {
            $ha->setStatus(false, $e->getMessage());
        } catch (Throwable) {
        }
        $log('stopped: ' . $e->getMessage() . ' — trying again shortly');
    }
    sleep(10);
    SettingsRepository::forget();
} while ($watch);

/**
 * One connected session: runs until something drops or the settings change.
 */
final class HomeAssistantBridge
{
    private Mqtt $mqtt;
    private ?Ami $ami = null;
    private int $amiRetryAt = 0;
    private array $config;
    private string $fingerprint;
    private string $discoveryHash = '';
    /** @var array<string,string> topic => last published JSON */
    private array $published = [];
    private bool $stateDue = true;
    /** @var array<string,array{phone:string,answered:?int,with:string}> live channels on our phones */
    private array $channels = [];
    /** @var array<string,true> "phone:date" limits already reported */
    private array $limitsSeen = [];
    private ?bool $bedtimeWas = null;

    public function __construct(private HomeAssistant $ha, private Closure $log)
    {
        $this->config = $ha->config();
        $this->fingerprint = $ha->fingerprint();
    }

    public static function test(HomeAssistant $ha): ?string
    {
        $c = $ha->config();
        if (!$c['configured']) {
            return 'no broker set';
        }
        try {
            $m = new Mqtt($c['host'], $c['port'], $c['username'], $c['password'], 'twocans-test-' . bin2hex(random_bytes(3)), $c['tls']);
            $m->connect();
            $m->disconnect();
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function run(): void
    {
        $c = $this->config;
        $base = $c['base'];
        $this->mqtt = new Mqtt($c['host'], $c['port'], $c['username'], $c['password'],
            'twocans-' . $this->ha->installId(), $c['tls']);
        $this->mqtt->connect(['topic' => $base . '/status', 'payload' => 'offline', 'retain' => true]);
        $this->mqtt->publish($base . '/status', 'online', true);
        $this->mqtt->subscribe([$base . '/cmd/#', $base . '/phone/+/cmd/#', $c['prefix'] . '/status']);
        ($this->log)('connected to ' . $c['host'] . ':' . $c['port']);
        $this->ha->setStatus(true, null);

        $this->publishDiscovery(true);
        $this->primeEvents();

        $nextState = 0;
        $nextEvents = time() + 20;
        $nextCheck = time() + 60;

        while (true) {
            $this->connectAmi();

            $read = [$this->mqtt->stream()];
            if ($this->ami !== null && $this->ami->stream() !== null) {
                $read[] = $this->ami->stream();
            }
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, 1);

            if ($ready > 0) {
                foreach ($read as $stream) {
                    if ($stream === $this->mqtt->stream()) {
                        foreach ($this->mqtt->poll() as $message) {
                            $this->onMessage($message['topic'], $message['payload']);
                        }
                    } elseif ($this->ami !== null && $stream === $this->ami->stream()) {
                        $this->readAmi();
                    }
                }
            }

            $now = time();
            // The web app changes settings in another process: read afresh.
            SettingsRepository::forget();
            if ($this->stateDue || $now >= $nextState) {
                $this->publishState($now >= $nextState);
                $this->stateDue = false;
                $nextState = $now + 15;
            }
            if ($now >= $nextEvents) {
                $this->pollEvents();
                $nextEvents = $now + 20;
            }
            if ($now >= $nextCheck) {
                if ($this->ha->fingerprint() !== $this->fingerprint) {
                    ($this->log)('settings changed — reconnecting');
                    $this->mqtt->disconnect();

                    return;
                }
                $this->publishDiscovery(false);
                $this->ha->setStatus(true, null);
                $nextCheck = $now + 60;
            }
            HomeAssistant::tidySpoken();
            $this->mqtt->keepAlive();
        }
    }

    // ------------------------------------------------------------- publish

    private function publishDiscovery(bool $force): void
    {
        $configs = $this->ha->discovery();
        $hash = hash('sha256', (string) json_encode($configs));
        if (!$force && $hash === $this->discoveryHash) {
            return;
        }
        foreach ($configs as $topic => $payload) {
            $this->mqtt->publish($topic, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
        }
        // Entities that have gone — a phone removed, an announcement deleted.
        foreach (array_diff($this->ha->discovered(), array_keys($configs)) as $gone) {
            $this->mqtt->publish($gone, '', true);
        }
        $this->ha->setDiscovered(array_keys($configs));
        $this->discoveryHash = $hash;
        $this->stateDue = true;
        ($this->log)('published ' . count($configs) . ' entities');
    }

    private function publishState(bool $force): void
    {
        $base = $this->config['base'];
        $snap = $this->ha->snapshot();
        $this->send($base . '/state', $snap['hub'], $force);
        foreach ($snap['phones'] as $id => $phone) {
            $this->send("{$base}/phone/{$id}/state", $phone, $force);
        }
    }

    private function send(string $topic, array $payload, bool $force): void
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($force || ($this->published[$topic] ?? null) !== $json) {
            $this->mqtt->publish($topic, $json, true);
            $this->published[$topic] = $json;
        }
    }

    private function event(string $kind, string $type, array $data = []): void
    {
        $payload = ['event_type' => $type] + $data;
        $this->mqtt->publish($this->config['base'] . '/event/' . $kind,
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        ($this->log)("event {$kind}/{$type}" . (isset($data['phone']) ? ' ' . $data['phone'] : ''));
    }

    // ------------------------------------------------------------ commands

    private function onMessage(string $topic, string $payload): void
    {
        // Home Assistant restarted: it has forgotten the entities, so say them again.
        if ($topic === $this->config['prefix'] . '/status') {
            if (trim($payload) === 'online') {
                $this->publishDiscovery(true);
                $this->publishState(true);
            }

            return;
        }
        try {
            $result = $this->ha->command($topic, $payload);
        } catch (Throwable $e) {
            $result = 'failed: ' . $e->getMessage();
        }
        if ($result !== null) {
            ($this->log)('command ' . substr($topic, strlen($this->config['base']) + 1) . ': ' . $result);
            $this->stateDue = true;
        }
    }

    // ------------------------------------------------------ Asterisk events

    private function connectAmi(): void
    {
        if ($this->ami !== null || time() < $this->amiRetryAt) {
            return;
        }
        try {
            $ami = new Ami('', 0, '', '', 3);
            $ami->connect();
            $this->ami = $ami;
        } catch (Throwable $e) {
            $this->amiRetryAt = time() + 30;
            ($this->log)('no live call events for now: ' . $e->getMessage());
        }
    }

    private function readAmi(): void
    {
        try {
            $event = $this->ami->readEvent();
        } catch (Throwable) {
            $this->ami = null;
            $this->amiRetryAt = time() + 5;

            return;
        }
        if ($event === null || !isset($event['event'])) {
            return;
        }
        $phone = $this->phoneFor((string) ($event['channel'] ?? ''));
        if ($phone === null) {
            return;
        }
        $channel = (string) $event['channel'];
        $with = trim((string) ($event['connectedlinename'] ?? ''));
        if ($with === '' || $with === '<unknown>') {
            $with = trim((string) ($event['connectedlinenum'] ?? ''));
        }

        switch ($event['event']) {
            case 'Newstate':
                $this->channels[$channel] ??= ['phone' => $phone, 'answered' => null, 'with' => $with];
                if ($with !== '' && $with !== '<unknown>') {
                    $this->channels[$channel]['with'] = $with;
                }
                if (($event['channelstatedesc'] ?? '') === 'Ringing') {
                    $this->event('calls', 'ringing', ['phone' => $phone, 'with' => $this->channels[$channel]['with']]);
                    $this->stateDue = true;
                }
                break;
            case 'BridgeEnter':
                $this->channels[$channel] ??= ['phone' => $phone, 'answered' => null, 'with' => $with];
                if ($this->channels[$channel]['answered'] === null) {
                    $this->channels[$channel]['answered'] = time();
                    if ($with !== '' && $with !== '<unknown>') {
                        $this->channels[$channel]['with'] = $with;
                    }
                    $this->event('calls', 'answered', ['phone' => $phone, 'with' => $this->channels[$channel]['with']]);
                    $this->stateDue = true;
                }
                break;
            case 'Hangup':
                $known = $this->channels[$channel] ?? null;
                unset($this->channels[$channel]);
                if ($known !== null) {
                    $this->event('calls', 'ended', [
                        'phone' => $phone,
                        'with' => $known['with'],
                        'answered' => $known['answered'] !== null,
                        'seconds' => $known['answered'] === null ? 0 : time() - $known['answered'],
                    ]);
                    $this->stateDue = true;
                }
                break;
        }
    }

    /** The phone's name for an Asterisk channel like PJSIP/laptop2-daff-0000002d. */
    private function phoneFor(string $channel): ?string
    {
        if (!preg_match('#^PJSIP/(.+)-[0-9a-f]{8}$#i', $channel, $m)) {
            return null;
        }
        static $byUser = null, $at = 0;
        if ($byUser === null || time() - $at > 60) {
            $byUser = [];
            foreach ((new DeviceRepository())->all() as $row) {
                $byUser[(string) $row['sip_username']] = (string) $row['name'];
            }
            $at = time();
        }

        return $byUser[$m[1]] ?? null;
    }

    // --------------------------------------------------------- timed events

    /** Start the bookmarks at "now", so connecting isn't news about old things. */
    private function primeEvents(): void
    {
        $pdo = Database::pdo();
        if ($this->ha->mark('ha_last_vm') === 0) {
            $this->ha->setMark('ha_last_vm', (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM voicemails')->fetchColumn());
        }
        if ($this->ha->mark('ha_last_ask') === 0) {
            $this->ha->setMark('ha_last_ask', (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM call_requests')->fetchColumn());
        }
        $devices = new DeviceRepository();
        foreach ($devices->all() as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['dailyMinutes'] !== null && $devices->minutesToday((int) $d['id']) >= $d['dailyMinutes']) {
                $this->limitsSeen[$d['id'] . ':' . date('Y-m-d')] = true;
            }
        }
    }

    private function pollEvents(): void
    {
        // Bring the log up to date, as the notifier does.
        foreach ([
            static fn() => (new CallRepository(new DeviceRepository()))->import(),
            static fn() => (new VoicemailRepository())->import(),
            static fn() => (new CallRequestRepository())->import(),
        ] as $step) {
            try {
                $step();
            } catch (Throwable) {
            }
        }
        $pdo = Database::pdo();

        // Somebody not on the list left a message.
        $last = $this->ha->mark('ha_last_vm');
        foreach ((new VoicemailRepository())->unrecognised(50) as $vm) {
            if ((int) $vm['id'] > $last) {
                $this->event('unknown', 'message_left', [
                    'number' => (string) $vm['peer_number'],
                    'transcript' => (string) ($vm['transcript'] ?? ''),
                ]);
            }
        }
        $this->ha->setMark('ha_last_vm', max($last, (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM voicemails')->fetchColumn()));

        // A child tried a number that isn't allowed.
        $last = $this->ha->mark('ha_last_ask');
        $st = $pdo->prepare('SELECT cr.id, cr.number_e164, d.name FROM call_requests cr
                              LEFT JOIN devices d ON d.id = cr.device_id WHERE cr.id > ? ORDER BY cr.id');
        $st->execute([$last]);
        foreach ($st->fetchAll() as $ask) {
            $this->event('unknown', 'asked', ['number' => (string) $ask['number_e164'], 'phone' => (string) ($ask['name'] ?? '')]);
            $last = max($last, (int) $ask['id']);
        }
        $this->ha->setMark('ha_last_ask', $last);

        // A phone has used up today's time.
        $devices = new DeviceRepository();
        foreach ($devices->all() as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['dailyMinutes'] === null || $d['adult']) {
                continue;
            }
            $key = $d['id'] . ':' . date('Y-m-d');
            $used = $devices->minutesToday((int) $d['id']);
            if ($used >= $d['dailyMinutes'] && !isset($this->limitsSeen[$key])) {
                $this->limitsSeen[$key] = true;
                $this->event('line', 'limit_reached', ['phone' => $d['name'], 'minutes' => $used, 'limit' => $d['dailyMinutes']]);
            }
        }

        // Bedtime starting or ending.
        $settings = new SettingsRepository();
        $now = $settings->quietHours() && Schedule::isOn($settings->quietRules(), new DateTimeImmutable());
        if ($this->bedtimeWas !== null && $now !== $this->bedtimeWas) {
            $this->event('line', $now ? 'bedtime_started' : 'bedtime_ended');
            $this->stateDue = true;
        }
        $this->bedtimeWas = $now;
    }
}
