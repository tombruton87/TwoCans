<?php
declare(strict_types=1);

/**
 * Home Assistant, over MQTT with discovery.
 *
 * twocans publishes to the household's MQTT broker (the Mosquitto add-on,
 * usually) in the shape Home Assistant's MQTT discovery reads, so everything
 * appears in HA by itself: a "twocans" hub device, and one device per phone.
 * bin/homeassistant.php is the long-running bridge; this class is what it
 * knows — the settings, the entities, the state, and what each command does.
 *
 * Topics, under the base (default "twocans"):
 *   twocans/status                  online / offline (the bridge's last will)
 *   twocans/state                   the hub's state, JSON
 *   twocans/phone/<id>/state        a phone's state, JSON
 *   twocans/event/<kind>            events: calls, unknown, line
 *   twocans/cmd/...                 commands from HA to the hub
 *   twocans/phone/<id>/cmd/...      commands from HA to a phone
 * Discovery configs go under the discovery prefix (default "homeassistant").
 *
 * Adult mode is shown but never controllable from here: switching it on stays
 * behind the confirmation on the phone's own page.
 */
final class HomeAssistant
{
    /** Settings, as stored — see config(). */
    private const KEYS = [
        'ha_enabled' => '0', 'ha_host' => '', 'ha_port' => '1883', 'ha_tls' => '0',
        'ha_username' => '', 'ha_password' => '', 'ha_prefix' => 'homeassistant', 'ha_base' => 'twocans',
        'ha_url' => '', 'ha_token' => '', 'ha_tts_engine' => '', 'ha_status' => '', 'ha_install' => '',
        'ha_discovered' => '', 'ha_last_vm' => '', 'ha_last_ask' => '',
    ];

    public function __construct(private SettingsRepository $settings = new SettingsRepository())
    {
    }

    /** Held by the running bridge, so there is only ever one. */
    public const LOCK = '/tmp/twocans-homeassistant.lock';

    /**
     * Start the bridge if it isn't running.
     *
     * Called every minute by the notifier (bin/notify.php), which the PHP
     * container already runs on a loop — so the bridge comes back after a
     * restart without the container needing anything new. The bridge holds
     * LOCK for as long as it runs: if the lock is free, nothing is running.
     *
     * @return bool whether it had to start one
     */
    public static function ensureRunning(): bool
    {
        $handle = @fopen(self::LOCK, 'c');
        if ($handle === false) {
            return false;
        }
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        if (!$free) {
            return false;
        }
        // Two checks in the same moment (the container's minute loop and a
        // hand-run notifier) would both see the lock free before either new
        // bridge has taken it: whoever started one in the last 30 seconds wins.
        $marker = self::LOCK . '.starting';
        if (is_file($marker) && time() - (int) @filemtime($marker) < 30) {
            return false;
        }
        @touch($marker);

        $php = PHP_BINARY !== '' ? PHP_BINARY : '/usr/local/bin/php';
        $script = dirname(__DIR__) . '/bin/homeassistant.php';
        $log = is_dir('/var/log/php-fpm') ? '/var/log/php-fpm/homeassistant.log' : '/dev/null';
        // Detached, so it outlives this minute's notifier run.
        exec('nohup setsid ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --watch >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &');

        return true;
    }

    // -------------------------------------------------------------- settings

    public function config(): array
    {
        $all = $this->settings->all();
        $get = static fn(string $k): string => (string) ($all[$k] ?? self::KEYS[$k]);
        $secret = static function (string $v): string {
            if ($v === '') {
                return '';
            }
            try {
                return Crypto::decrypt(base64_decode($v, true) ?: '');
            } catch (Throwable) {
                return '';
            }
        };

        return [
            'enabled' => $get('ha_enabled') === '1',
            'host' => $get('ha_host'),
            'port' => (int) $get('ha_port') ?: 1883,
            'tls' => $get('ha_tls') === '1',
            'username' => $get('ha_username'),
            'password' => $secret($get('ha_password')),
            'hasPassword' => $get('ha_password') !== '',
            'prefix' => $get('ha_prefix') ?: 'homeassistant',
            'base' => $get('ha_base') ?: 'twocans',
            'url' => rtrim($get('ha_url'), '/'),
            'token' => $secret($get('ha_token')),
            'hasToken' => $get('ha_token') !== '',
            'ttsEngine' => $get('ha_tts_engine'),
            'configured' => $get('ha_host') !== '',
        ];
    }

    /** @return string|null what's wrong, or null when saved */
    public function save(array $input): ?string
    {
        $host = trim((string) ($input['host'] ?? ''));
        $port = (int) ($input['port'] ?? 1883);
        $base = trim((string) ($input['base'] ?? 'twocans'), " /");
        $prefix = trim((string) ($input['prefix'] ?? 'homeassistant'), " /");
        $url = rtrim(trim((string) ($input['url'] ?? '')), '/');

        if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
            return "That broker address doesn't look right — a host name or IP address, like 192.168.1.10.";
        }
        if ($port < 1 || $port > 65535) {
            return 'The broker port is a number, usually 1883 (or 8883 with TLS).';
        }
        if (!preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $base) || !preg_match('#^[A-Za-z0-9_\-]+$#', $prefix)) {
            return 'Topics may only use letters, numbers, - and _.';
        }
        if ($url !== '' && !preg_match('#^https?://#', $url)) {
            return 'The Home Assistant address starts with http:// or https://.';
        }

        $set = fn(string $k, string $v) => $this->settings->set($k, $v);
        $set('ha_enabled', !empty($input['enabled']) ? '1' : '0');
        $set('ha_host', $host);
        $set('ha_port', (string) $port);
        $set('ha_tls', !empty($input['tls']) ? '1' : '0');
        $set('ha_username', trim((string) ($input['username'] ?? '')));
        $set('ha_prefix', $prefix);
        $set('ha_base', $base);
        $set('ha_url', $url);
        $set('ha_tts_engine', trim((string) ($input['tts'] ?? '')));
        // Secrets: a blank field keeps what's saved; the form never shows them.
        foreach (['password' => 'ha_password', 'token' => 'ha_token'] as $field => $key) {
            $value = (string) ($input[$field] ?? '');
            if (!empty($input['clear_' . $field])) {
                $set($key, '');
            } elseif ($value !== '') {
                $set($key, base64_encode(Crypto::encrypt($value)));
            }
        }

        return null;
    }

    /** Try the broker with the saved settings; null when it answered. */
    public function test(): ?string
    {
        $c = $this->config();
        if (!$c['configured']) {
            return 'no broker address saved yet';
        }
        try {
            $m = new Mqtt($c['host'], $c['port'], $c['username'], $c['password'],
                'twocans-test-' . bin2hex(random_bytes(3)), $c['tls']);
            $m->connect();
            $m->disconnect();
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** What the bridge last said about itself, for the settings screen. */
    public function status(): array
    {
        $s = json_decode((string) ($this->settings->all()['ha_status'] ?? ''), true);

        return is_array($s) ? $s : [];
    }

    public function setStatus(bool $connected, ?string $error): void
    {
        $this->settings->set('ha_status', (string) json_encode([
            'connected' => $connected, 'error' => $error, 'at' => time(),
        ]));
    }

    /** Settings the bridge must reconnect for when they change. */
    public function fingerprint(): string
    {
        $c = $this->config();
        unset($c['hasPassword'], $c['hasToken'], $c['url'], $c['token'], $c['ttsEngine']);

        return hash('sha256', (string) json_encode($c));
    }

    /** A stable id for this box, so two twocans on one broker don't collide. */
    public function installId(): string
    {
        $id = (string) ($this->settings->all()['ha_install'] ?? '');
        if ($id === '') {
            $id = bin2hex(random_bytes(4));
            $this->settings->set('ha_install', $id);
        }

        return $id;
    }

    public function mark(string $key): int
    {
        return (int) ($this->settings->all()[$key] ?? 0);
    }

    public function setMark(string $key, int $value): void
    {
        $this->settings->set($key, (string) $value);
    }

    // ------------------------------------------------------------- discovery

    /**
     * Every discovery config to publish, topic => payload.
     *
     * @return array<string,array>
     */
    public function discovery(): array
    {
        $c = $this->config();
        $base = $c['base'];
        $prefix = $c['prefix'];
        $iid = $this->installId();
        $hubId = 'twocans_' . $iid;
        $avail = ['availability_topic' => $base . '/status'];
        $hub = [
            'identifiers' => [$hubId],
            'name' => 'twocans',
            'manufacturer' => 'twocans',
            'model' => 'Family phone line',
        ];

        $out = [];
        $add = function (string $component, string $object, array $config) use (&$out, $prefix, $hubId, $avail): void {
            $config['unique_id'] = $hubId . '_' . $object;
            $config['object_id'] = 'twocans_' . $object;
            $out["{$prefix}/{$component}/{$hubId}/{$object}/config"] = $config + $avail;
        };
        $hubState = ['state_topic' => $base . '/state', 'device' => $hub];

        // What HA can see about the line.
        $add('sensor', 'calls_today', $hubState + ['name' => 'Calls today', 'icon' => 'mdi:phone-log',
            'state_class' => 'total_increasing', 'value_template' => '{{ value_json.calls_today }}']);
        $add('sensor', 'active_calls', $hubState + ['name' => 'Active calls', 'icon' => 'mdi:phone-in-talk',
            'state_class' => 'measurement', 'value_template' => '{{ value_json.active_calls }}',
            'json_attributes_topic' => $base . '/state', 'json_attributes_template' => '{{ {"calls": value_json.calls} | tojson }}']);
        $add('sensor', 'unheard_voicemails', $hubState + ['name' => 'Unheard voicemails', 'icon' => 'mdi:voicemail',
            'value_template' => '{{ value_json.unheard_voicemails }}']);
        $add('sensor', 'waiting', $hubState + ['name' => 'Waiting for a decision', 'icon' => 'mdi:account-question',
            'value_template' => '{{ value_json.waiting }}',
            'json_attributes_topic' => $base . '/state',
            'json_attributes_template' => '{{ {"unknown_callers": value_json.waiting_unknown, "kids_asked": value_json.waiting_asks} | tojson }}']);
        $add('sensor', 'credit', $hubState + ['name' => 'Call credit', 'icon' => 'mdi:cash',
            'device_class' => 'monetary', 'unit_of_measurement' => $this->currency(),
            'value_template' => '{{ value_json.credit if value_json.credit is not none else "unknown" }}']);
        $add('binary_sensor', 'line_connected', $hubState + ['name' => 'Phone line', 'device_class' => 'connectivity',
            'value_template' => '{{ "ON" if value_json.line_connected else "OFF" }}']);
        $add('binary_sensor', 'low_credit', $hubState + ['name' => 'Low credit', 'device_class' => 'problem',
            'value_template' => '{{ "ON" if value_json.low_credit else "OFF" }}']);
        $add('binary_sensor', 'bedtime_now', $hubState + ['name' => 'Bedtime in force', 'icon' => 'mdi:weather-night',
            'value_template' => '{{ "ON" if value_json.bedtime_now else "OFF" }}']);

        // What HA can do to the line.
        $add('switch', 'bedtime', $hubState + ['name' => 'Bedtime mode', 'icon' => 'mdi:bed',
            'command_topic' => $base . '/cmd/bedtime', 'value_template' => '{{ "ON" if value_json.bedtime else "OFF" }}']);
        $add('switch', 'take_messages', $hubState + ['name' => 'Unknown callers can leave a message', 'icon' => 'mdi:message-question',
            'command_topic' => $base . '/cmd/take_messages', 'value_template' => '{{ "ON" if value_json.take_messages else "OFF" }}']);
        $add('text', 'say', ['device' => $hub, 'name' => 'Announce', 'icon' => 'mdi:bullhorn',
            'command_topic' => $base . '/cmd/say', 'mode' => 'text', 'max' => 255, 'min' => 0]);
        foreach ((new AnnouncementRepository())->all() as $a) {
            if ($a['label'] === '') {
                continue;
            }
            $add('button', 'announce_' . $a['id'], ['device' => $hub, 'name' => 'Announce: ' . $a['label'],
                'icon' => 'mdi:bullhorn', 'command_topic' => $base . '/cmd/announce/' . $a['id']]);
        }

        // Events for automations.
        $events = [
            'calls' => ['Call', ['ringing', 'answered', 'ended'], 'mdi:phone'],
            'unknown' => ['Unknown caller or ask', ['message_left', 'asked'], 'mdi:account-question'],
            'line' => ['Limits and bedtime', ['limit_reached', 'bedtime_started', 'bedtime_ended'], 'mdi:timer-sand'],
        ];
        foreach ($events as $kind => [$name, $types, $icon]) {
            $add('event', 'event_' . $kind, ['device' => $hub, 'name' => $name, 'icon' => $icon,
                'state_topic' => $base . '/event/' . $kind, 'event_types' => $types]);
        }

        // One device per phone.
        foreach ((new DeviceRepository())->all() as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['sipUsername'] === '' || !$d['available']) {
                continue;
            }
            $pid = $d['id'];
            $device = [
                'identifiers' => [$hubId . '_phone_' . $pid],
                'name' => $d['name'],
                'manufacturer' => 'twocans',
                'model' => $d['model'],
                'via_device' => $hubId,
            ];
            $state = ['state_topic' => "{$base}/phone/{$pid}/state", 'device' => $device];
            $p = 'phone_' . $pid . '_';
            $add('binary_sensor', $p . 'online', $state + ['name' => 'Online', 'device_class' => 'connectivity',
                'value_template' => '{{ "ON" if value_json.online else "OFF" }}']);
            $add('sensor', $p . 'call', $state + ['name' => 'Call', 'icon' => 'mdi:phone',
                'device_class' => 'enum', 'options' => ['idle', 'ringing', 'on_call'],
                'value_template' => '{{ value_json.call }}',
                'json_attributes_topic' => "{$base}/phone/{$pid}/state",
                'json_attributes_template' => '{{ {"with": value_json.with, "direction": value_json.direction} | tojson }}']);
            $add('binary_sensor', $p . 'adult', $state + ['name' => 'Adult mode', 'icon' => 'mdi:lock-open-variant',
                'value_template' => '{{ "ON" if value_json.adult else "OFF" }}']);
            $add('sensor', $p . 'minutes_today', $state + ['name' => 'Minutes today', 'icon' => 'mdi:timer-outline',
                'unit_of_measurement' => 'min', 'state_class' => 'total_increasing',
                'value_template' => '{{ value_json.minutes_today }}',
                'json_attributes_topic' => "{$base}/phone/{$pid}/state",
                'json_attributes_template' => '{{ {"daily_limit": value_json.daily_limit} | tojson }}']);
            $add('switch', $p . 'incoming', $state + ['name' => 'Incoming calls', 'icon' => 'mdi:phone-incoming',
                'command_topic' => "{$base}/phone/{$pid}/cmd/incoming",
                'value_template' => '{{ "ON" if value_json.incoming else "OFF" }}']);
            $add('switch', $p . 'outgoing', $state + ['name' => 'Outgoing calls', 'icon' => 'mdi:phone-outgoing',
                'command_topic' => "{$base}/phone/{$pid}/cmd/outgoing",
                'value_template' => '{{ "ON" if value_json.outgoing else "OFF" }}']);
            $add('switch', $p . 'announce', $state + ['name' => "Say who's calling", 'icon' => 'mdi:account-voice',
                'entity_category' => 'config',
                'command_topic' => "{$base}/phone/{$pid}/cmd/announce",
                'value_template' => '{{ "ON" if value_json.announce else "OFF" }}']);
            $add('button', $p . 'ring', ['device' => $device, 'name' => 'Ring', 'icon' => 'mdi:phone-ring',
                'command_topic' => "{$base}/phone/{$pid}/cmd/ring"]);
            $add('button', $p . 'hangup', ['device' => $device, 'name' => 'Hang up', 'icon' => 'mdi:phone-hangup',
                'command_topic' => "{$base}/phone/{$pid}/cmd/hangup"]);
        }

        return $out;
    }

    /** Topics published last time, to clear the ones that have gone. */
    public function discovered(): array
    {
        $list = json_decode((string) ($this->settings->all()['ha_discovered'] ?? ''), true);

        return is_array($list) ? $list : [];
    }

    public function setDiscovered(array $topics): void
    {
        $this->settings->set('ha_discovered', (string) json_encode(array_values($topics)));
    }

    // ----------------------------------------------------------------- state

    /**
     * The hub's state and each phone's, from the database and Asterisk.
     *
     * @return array{hub:array,phones:array<int,array>}
     */
    public function snapshot(): array
    {
        $settings = $this->settings;
        $devices = new DeviceRepository();
        $trunk = (new TrunkRepository())->get();
        $calls = new CallRepository($devices);
        $live = (new LiveCalls($devices))->active();
        $queue = new UnknownQueue();
        $unknown = count($queue->pending());
        $asks = count(array_filter($queue->tried(), static fn(array $t): bool => $t['dismiss'] !== null));
        $bedtime = $settings->quietHours();

        $hub = [
            'calls_today' => $calls->countToday('done'),
            'active_calls' => count($live),
            'calls' => array_map(static fn(array $c): array => [
                'phone' => $c['deviceName'], 'with' => $c['peerName'], 'direction' => $c['dir'],
                'connected' => $c['connected'], 'seconds' => $c['seconds'],
            ], $live),
            'unheard_voicemails' => (new VoicemailRepository())->unheardCount(),
            'waiting' => $unknown + $asks,
            'waiting_unknown' => $unknown,
            'waiting_asks' => $asks,
            'credit' => $trunk['balance'],
            'line_connected' => (bool) $trunk['connected'],
            'low_credit' => (new TrunkRepository())->isLowCredit(),
            'bedtime' => $bedtime,
            'bedtime_now' => $bedtime && Schedule::isOn($settings->quietRules(), new DateTimeImmutable()),
            'take_messages' => $settings->takesUnknownMessages(),
        ];

        $phones = [];
        foreach ($devices->all() as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['sipUsername'] === '' || !$d['available']) {
                continue;
            }
            $mine = array_values(array_filter($live, static fn(array $c): bool => (int) $c['deviceId'] === (int) $d['id']));
            $call = $mine[0] ?? null;
            $phones[(int) $d['id']] = [
                'online' => (bool) $d['online'],
                'call' => $call === null ? 'idle' : ($call['connected'] ? 'on_call' : 'ringing'),
                'with' => $call['peerName'] ?? null,
                'direction' => $call['dir'] ?? null,
                'adult' => (bool) $d['adult'],
                'minutes_today' => $devices->minutesToday((int) $d['id']),
                'daily_limit' => $d['dailyMinutes'],
                'incoming' => (bool) $d['allowIn'],
                'outgoing' => (bool) $d['allowOut'],
                'announce' => (bool) $d['announceCaller'],
            ];
        }

        return ['hub' => $hub, 'phones' => $phones];
    }

    private function currency(): string
    {
        return (string) ((new TrunkRepository())->get()['currency'] ?? 'GBP') ?: 'GBP';
    }

    // -------------------------------------------------------------- commands

    /**
     * Act on a command from Home Assistant.
     *
     * @return string|null what happened, for the bridge's log
     */
    public function command(string $topic, string $payload): ?string
    {
        $base = $this->config()['base'];
        $on = strtoupper(trim($payload)) === 'ON';
        $devices = new DeviceRepository();
        $apply = static fn() => (new PjsipConfig($devices))->apply();

        if ($topic === "{$base}/cmd/bedtime") {
            $this->settings->set('quiet_hours', $on ? '1' : '0');
            $apply();

            return 'bedtime ' . ($on ? 'on' : 'off');
        }
        if ($topic === "{$base}/cmd/take_messages") {
            $this->settings->setTakesUnknownMessages($on);
            $apply();

            return 'take messages ' . ($on ? 'on' : 'off');
        }
        if (preg_match('#^' . preg_quote($base, '#') . '/cmd/announce/(\d+)$#', $topic, $m)) {
            $sent = (new AnnouncementRepository())->send((int) $m[1]);

            return $sent['ok'] ? 'announcement ' . $m[1] . ' sent to ' . $sent['phones'] . ' phone(s)' : 'announcement: ' . $sent['error'];
        }
        if ($topic === "{$base}/cmd/say") {
            return $this->say(trim($payload));
        }
        if (preg_match('#^' . preg_quote($base, '#') . '/phone/(\d+)/cmd/(incoming|outgoing|announce|ring|hangup)$#', $topic, $m)) {
            $id = (int) $m[1];
            $phone = $devices->find($id);
            if ($phone === null) {
                return 'no phone ' . $id;
            }
            $d = DeviceRepository::toView($phone);
            switch ($m[2]) {
                case 'incoming':
                case 'outgoing':
                    $devices->setAllow($id, $m[2] === 'incoming' ? 'allowIn' : 'allowOut', $on);
                    $apply();

                    return $d['name'] . ' ' . $m[2] . ' ' . ($on ? 'on' : 'off');
                case 'announce':
                    $devices->setAllow($id, 'announceCaller', $on);
                    $apply();

                    return $d['name'] . " says who's calling: " . ($on ? 'on' : 'off');
                case 'ring':
                    return $this->ring($d);
                case 'hangup':
                    $live = new LiveCalls($devices);
                    $ended = 0;
                    foreach ($live->active() as $call) {
                        if ((int) $call['deviceId'] === $id && $live->hangup((string) $call['channel'])) {
                            $ended++;
                        }
                    }

                    return $d['name'] . ': ' . ($ended > 0 ? 'hung up' : 'no call to hang up');
            }
        }

        return null;
    }

    /** Ring a phone: answered, it hears a chime, then the call ends. */
    private function ring(array $d): string
    {
        try {
            $ami = new Ami();
            $ami->connect();
            $reply = $ami->send('Originate', [
                'Channel' => 'PJSIP/' . $d['sipUsername'],
                'Application' => 'Playback',
                'Data' => 'silence/1&beep&silence/1&beep',
                'CallerID' => '"Home Assistant" <' . AnnouncementRepository::CALLER_NUMBER . '>',
                'Timeout' => 30000,
                'Async' => 'true',
            ]);
            $ami->disconnect();
        } catch (Throwable $e) {
            return 'ring: ' . $e->getMessage();
        }

        return ($reply['response'] ?? '') === 'Success' ? $d['name'] . ' ringing' : 'ring: refused';
    }

    /**
     * "Say this" on every phone, spoken by Home Assistant's own text-to-speech:
     * twocans asks HA for the audio, converts it, and pages the phones with it.
     */
    public function say(string $text): string
    {
        if ($text === '') {
            return 'say: nothing to say';
        }
        $store = new AnnouncementStore();
        $clip = $this->speak($text, $store);
        if ($clip['error'] !== null) {
            return 'say: ' . $clip['error'];
        }
        $playback = $store->playbackPath((string) $clip['file']);
        $sent = (new AnnouncementRepository())->page((string) $playback, 'Home Assistant', 'auto', null);
        // Asterisk reads the file while the phones pick up; clear it after.
        self::$spoken[] = ['file' => (string) $clip['file'], 'at' => time()];

        return $sent['ok'] ? 'said "' . mb_substr($text, 0, 40) . '" on ' . $sent['phones'] . ' phone(s)' : 'say: ' . $sent['error'];
    }

    /** Whether speak() has what it needs: an address, a token and a voice. */
    public function canSpeak(): bool
    {
        $c = $this->config();

        return $c['url'] !== '' && $c['token'] !== '' && $c['ttsEngine'] !== '';
    }

    /**
     * Have Home Assistant's text-to-speech say something, and keep it in
     * $store as a clip the phones can play.
     *
     * @return array{file:?string,seconds:int,sha256:?string,error:?string}
     */
    public function speak(string $text, AudioStore $store): array
    {
        $fail = static fn(string $why): array => ['file' => null, 'seconds' => 0, 'sha256' => null, 'error' => $why];
        if (!$this->canSpeak()) {
            return $fail('set the Home Assistant address, token and voice first');
        }
        $c = $this->config();

        $response = $this->haRequest('POST', '/api/tts_get_url', [
            'engine_id' => $c['ttsEngine'], 'message' => $text, 'cache' => true,
        ]);
        $audioUrl = is_array($response) ? (string) ($response['url'] ?? '') : '';
        if ($audioUrl === '') {
            return $fail('Home Assistant made no audio');
        }
        // HA answers with its own external URL; fetch it the way we reach HA.
        $path = parse_url($audioUrl, PHP_URL_PATH) ?: '';
        $audio = $this->haFetch($path);
        if ($audio === null) {
            return $fail('could not fetch the audio from Home Assistant');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tts');
        file_put_contents($tmp, $audio);
        $clip = $store->convert($tmp, 'say.mp3');
        @unlink($tmp);

        return $clip;
    }

    /** @var array<int,array{file:string,at:int}> spoken clips waiting to be removed */
    private static array $spoken = [];

    /** Remove spoken clips once the phones have had time to play them. */
    public static function tidySpoken(): void
    {
        $store = new AnnouncementStore();
        foreach (self::$spoken as $i => $clip) {
            if (time() - $clip['at'] > 300) {
                $store->delete($clip['file']);
                unset(self::$spoken[$i]);
            }
        }
    }

    /** The text-to-speech voices Home Assistant has, for the settings screen. */
    public function ttsEngines(): array
    {
        $states = $this->haRequest('GET', '/api/states');
        if (!is_array($states)) {
            return [];
        }
        $engines = [];
        foreach ($states as $s) {
            $id = (string) ($s['entity_id'] ?? '');
            if (str_starts_with($id, 'tts.')) {
                $engines[$id] = (string) ($s['attributes']['friendly_name'] ?? $id);
            }
        }

        return $engines;
    }

    private function haRequest(string $method, string $path, ?array $body = null): mixed
    {
        $c = $this->config();
        if ($c['url'] === '' || $c['token'] === '') {
            return null;
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Authorization: Bearer {$c['token']}\r\nContent-Type: application/json\r\n",
            'content' => $body === null ? '' : (string) json_encode($body),
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($c['url'] . $path, false, $ctx);

        return $raw === false ? null : json_decode($raw, true);
    }

    private function haFetch(string $path): ?string
    {
        $c = $this->config();
        $ctx = stream_context_create(['http' => [
            'header' => "Authorization: Bearer {$c['token']}\r\n",
            'timeout' => 20,
        ]]);
        $raw = @file_get_contents($c['url'] . $path, false, $ctx);

        return $raw === false || $raw === '' ? null : $raw;
    }
}
