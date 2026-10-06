<?php
declare(strict_types=1);

/**
 * Moving a household to another box, a piece at a time.
 *
 * A Backup is the whole machine in one lump — the database and every file —
 * and only restores onto a box with the same APP_KEY. This is the other tool:
 * the sections a family would recognise (the line, the phones, the people…),
 * each as plain JSON plus the files it names, that can be brought into a
 * twocans that is already running somewhere else — new hardware, a Synology —
 * and chosen one at a time.
 *
 * Secrets are the awkward part. Tokens and passwords are encrypted at rest
 * with this box's APP_KEY, which the other box does not have, so they cannot
 * travel as they are stored. Here they are decrypted and re-sealed under a
 * passphrase the parent types (Argon2id → XSalsa20-Poly1305, in secrets.enc);
 * the other box opens them with the same passphrase and encrypts them again
 * with its own key. No passphrase means no secrets in the file at all, and the
 * line's credentials get typed into the wizard again on the far side.
 *
 * Importing replaces a section wholesale — the right thing for a fresh install,
 * and said plainly on the screen — with two deliberate exceptions. Grown-ups
 * are merged by email and the signed-in account is never touched, because
 * "replace" there would delete the Owner who is doing the importing. Settings
 * are merged key by key, because the per-box ones (ports, this network's
 * address) belong to the box, not the household, and must survive.
 */
final class Transfer
{
    public const VERSION = 1;

    /** What the archive is called inside storage/exports; the prefix keeps it clear of the Synology's own exports. */
    private const PREFIX = 'twocans-move-';

    /** A finished export, or an upload waiting to be previewed, lives this long. */
    private const KEEP_SECONDS = 3600;

    /**
     * The pieces, in the order a parent reads them. Import order is different —
     * see ORDER — because some sections point at others.
     */
    public const SECTIONS = [
        'line' => ['label' => 'Phone line', 'sub' => 'Provider, number, and which phones it rings'],
        'phones' => ['label' => 'Phones', 'sub' => 'Handsets, hotkeys, hours, photos and wallpapers'],
        'contacts' => ['label' => 'People', 'sub' => 'Contacts and groups, with photos and name clips'],
        'settings' => ['label' => 'Settings & rules', 'sub' => 'Bedtime, dial-plan rules, announcements, notifications, dynamic DNS'],
        'sounds' => ['label' => 'Sounds', 'sub' => 'Jokes, greetings, radio, hold music and Santa'],
        'guardians' => ['label' => 'Grown-ups', 'sub' => 'Accounts, matched by email — never the one signed in'],
        'history' => ['label' => 'Call history & keepsakes', 'sub' => 'Every call, voicemail, recording and keepsake — the big one'],
    ];

    /** Import order: whatever a section points at comes first. */
    private const ORDER = ['guardians', 'sounds', 'contacts', 'phones', 'line', 'settings', 'history'];

    /** The tables each section carries, in insert order. */
    private const TABLES = [
        'line' => ['trunk', 'trunk_number_rings', 'trunk_number_mailboxes'],
        'phones' => ['devices', 'device_hotkeys'],
        'contacts' => ['contacts', 'contact_members'],
        'settings' => ['settings', 'dialplan_rules', 'announcements', 'notifications', 'dynamic_dns'],
        'sounds' => ['jokes', 'hold_music', 'radio_songs', 'radio_stations', 'radio_station_songs'],
        'guardians' => ['guardians'],
        'history' => ['calls', 'call_participants', 'call_requests', 'voicemails', 'keepsakes', 'quiz_games', 'room_listens', 'listen_events'],
    ];

    /**
     * Columns that describe this box's moment rather than the household: a
     * phone's registration state, a provider balance, when something last ran.
     * Left out of the export entirely — not nulled, since several are NOT NULL —
     * so the other box's column defaults start them fresh.
     */
    private const RUNTIME = [
        'devices' => ['online', 'registered', 'last_seen_at', 'settings_fetched_at', 'settings_pending', 'started_at', 'offhook_since', 'paused_until', 'offhook_notified'],
        'trunk' => ['last_verified_at', 'balance', 'minutes_this_month'],
        'notifications' => ['last_ask_id', 'last_message_id', 'last_digest_at', 'last_low_credit_alerted', 'last_online_json', 'last_error', 'last_run_at'],
        'dynamic_dns' => ['last_ip', 'last_checked_at', 'last_updated_at', 'last_error'],
        'announcements' => ['last_sent_at', 'last_scheduled_at'],
        'guardians' => ['last_login_at', 'signed_out_at'],
    ];

    /**
     * Settings that belong to the box, not the family — ports, this network's
     * address, runtime marks, the push key — or that travel as secrets instead.
     */
    private const SETTINGS_SKIP = [
        'sip_port', 'sip_tls_port', 'trunk_sip_port', 'trunk_public_host', 'provision_pass',
        'ha_status', 'ha_install', 'ha_last_ask', 'ha_last_vm', 'ha_discovered',
        'latest_checked_at', 'latest_url', 'latest_version', 'onboarding', 'onboarding_tested',
        'retention_last_sweep', 'santa_rang_year', 'vapid_private',
        'ha_password', 'ha_token',
    ];

    /**
     * Encrypted columns, by table: decrypted into the sealed secrets on export,
     * encrypted again with the other box's key on import.
     */
    private const SECRET_COLUMNS = [
        'trunk' => ['auth_token_enc', 'api_key_enc', 'termination_password_enc'],
        'dynamic_dns' => ['api_token_enc'],
        'notifications' => ['mailgun_api_key_enc'],
    ];

    /** Settings whose value is base64(Crypto::encrypt(…)) — see HomeAssistant::save(). */
    private const SECRET_SETTINGS = ['ha_password', 'ha_token'];

    /**
     * Files a section names, as (table, column, store). The store's path() says
     * where they live on each box, so nothing here hard-codes a directory.
     */
    private const FILES = [
        'phones' => [['devices', 'photo_path', PhotoStore::class], ['devices', 'wallpaper_path', PhotoStore::class], ['devices', 'refusal_audio', RefusalStore::class]],
        'contacts' => [['contacts', 'photo_path', PhotoStore::class], ['contacts', 'announce_clip', CallerNameStore::class], ['contacts', 'group_prompt', GroupPromptStore::class]],
        'settings' => [['announcements', 'audio_file', AnnouncementStore::class]],
        'sounds' => [['jokes', 'audio_file', JokeStore::class], ['hold_music', 'audio_file', HoldMusicStore::class], ['radio_songs', 'audio_file', RadioStore::class], ['radio_stations', 'name_audio', RadioStationStore::class]],
    ];

    /** Settings keys that name a file, and the store that holds it. */
    private const SETTING_FILES = [
        'settings' => ['quiet_message' => QuietMessageStore::class, 'group_prompt' => GroupPromptStore::class],
        'sounds' => ['santa_message' => SantaStore::class, 'greeting_*' => GreetingStore::class],
    ];

    /**
     * History files keep their path relative to a root, because Asterisk's
     * voicemail layout (INBOX/msg0001.wav + .txt) is not ours to flatten.
     */
    private const HISTORY_ROOTS = [
        'recordings' => ['env' => 'RECORDINGS_PATH', 'default' => '/var/spool/asterisk/monitor', 'table' => 'calls', 'column' => 'recording_path'],
        'voicemail' => ['env' => 'VOICEMAIL_PATH', 'default' => '/var/spool/asterisk/voicemail', 'table' => 'voicemails', 'column' => 'audio_path'],
        'asks' => ['env' => 'ASK_RECORDINGS_PATH', 'default' => '/var/spool/asterisk/asks', 'table' => 'call_requests', 'column' => 'recording_path'],
        'keepsakes' => ['env' => 'KEEPSAKES_PATH', 'default' => '/var/lib/twocans/keepsakes', 'table' => 'keepsakes', 'column' => 'audio_file'],
    ];

    /** @var array<string,array<string,bool>> table => column => NOT NULL, read once */
    private array $columns = [];

    public function path(): string
    {
        return rtrim(getenv('EXPORTS_PATH') ?: '/var/lib/twocans/exports', '/');
    }

    // ------------------------------------------------------------------ export

    /**
     * Build the archive. Returns its file name, kept in storage/exports until
     * downloaded or an hour passes.
     *
     * @param  array<int,string> $sections
     * @return array{ok:bool,error:?string,name:?string,secrets:int}
     */
    public function export(array $sections, string $passphrase): array
    {
        $sections = $this->chosen($sections);
        if ($sections === []) {
            return ['ok' => false, 'error' => 'Tick at least one thing to take with you.', 'name' => null, 'secrets' => 0];
        }

        $this->sweep();
        $dir = $this->path();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'The exports folder could not be created.', 'name' => null, 'secrets' => 0];
        }

        $name = self::PREFIX . date('Ymd-Hi') . '-' . bin2hex(random_bytes(3)) . '.zip';
        $file = $dir . '/' . $name;
        $secrets = [];
        $counts = [];

        try {
            $zip = new PharData($file, 0, null, Phar::ZIP);

            foreach ($sections as $section) {
                $rows = $this->readSection($section, $secrets);
                $counts[$section] = array_map('count', $rows);
                $zip->addFromString($section . '.json', (string) json_encode($rows, JSON_UNESCAPED_UNICODE));

                foreach ($this->filesOf($section, $rows) as $inside => $onDisk) {
                    if (is_readable($onDisk)) {
                        $zip->addFile($onDisk, $inside);
                    }
                }
            }

            $sealed = 0;
            if ($passphrase !== '' && $secrets !== []) {
                $zip->addFromString('secrets.enc', self::seal($secrets, $passphrase));
                $sealed = count($secrets);
            }

            $zip->addFromString('manifest.json', (string) json_encode([
                'format' => 'twocans-move',
                'version' => self::VERSION,
                'created_at' => date('c'),
                'app_version' => trim((string) @file_get_contents(__DIR__ . '/../VERSION')),
                'sections' => $counts,
                'has_secrets' => $sealed > 0,
                'secrets_left_out' => $passphrase === '' && $secrets !== [],
            ], JSON_PRETTY_PRINT));
            unset($zip);
        } catch (Throwable $e) {
            @unlink($file);

            return ['ok' => false, 'error' => 'Could not build the export: ' . $e->getMessage(), 'name' => null, 'secrets' => 0];
        }

        // Plaintext secrets were only ever in memory; make sure of it.
        foreach ($secrets as &$v) {
            if (is_string($v)) {
                sodium_memzero($v);
            }
        }
        unset($v);

        return ['ok' => true, 'error' => null, 'name' => $name, 'secrets' => $sealed];
    }

    /**
     * One section's tables as rows, with secrets lifted out and runtime state
     * blanked.
     *
     * @param  array<string,string> $secrets  filled in: key => plaintext
     * @return array<string,array<int,array>>  table => rows
     */
    private function readSection(string $section, array &$secrets): array
    {
        $pdo = Database::pdo();
        $out = [];

        foreach (self::TABLES[$section] as $table) {
            if ($table === 'settings') {
                $rows = [];
                foreach ($pdo->query('SELECT name, value FROM settings ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $k = (string) $r['name'];
                    if (in_array($k, self::SECRET_SETTINGS, true)) {
                        $plain = self::openSetting((string) $r['value']);
                        if ($plain !== '') {
                            $secrets['settings.' . $k] = $plain;
                        }
                        continue;
                    }
                    if (self::isHouseholdSetting($k)) {
                        $rows[] = ['name' => $k, 'value' => (string) $r['value']];
                    }
                }
                $out['settings'] = $rows;
                continue;
            }

            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                foreach (self::RUNTIME[$table] ?? [] as $col) {
                    unset($row[$col]);
                }
                foreach (self::SECRET_COLUMNS[$table] ?? [] as $col) {
                    if (!empty($row[$col])) {
                        try {
                            $secrets[$table . '.' . $col] = Crypto::decrypt((string) $row[$col]);
                        } catch (Throwable) {
                            // Encrypted under a key this box no longer has: leave it behind.
                        }
                    }
                    $row[$col] = null;
                }
                if ($table === 'devices' && ($row['sip_secret'] ?? '') !== '') {
                    $secrets['devices.' . (int) $row['id'] . '.sip_secret'] = (string) $row['sip_secret'];
                    $row['sip_secret'] = '';
                }
                if ($table === 'guardians' && ($row['password_hash'] ?? '') !== null && ($row['password_hash'] ?? '') !== '') {
                    $secrets['guardians.' . strtolower((string) $row['email']) . '.password_hash'] = (string) $row['password_hash'];
                    $row['password_hash'] = null;
                }
            }
            unset($row);
            $out[$table] = $rows;
        }

        return $out;
    }

    /**
     * Every file a section's rows name: archive path => path on this box.
     *
     * @param  array<string,array<int,array>> $rows
     * @return array<string,string>
     */
    private function filesOf(string $section, array $rows): array
    {
        $files = [];

        foreach (self::FILES[$section] ?? [] as [$table, $column, $storeClass]) {
            $store = new $storeClass();
            foreach ($rows[$table] ?? [] as $row) {
                $name = (string) ($row[$column] ?? '');
                if (self::storeFileOk($name)) {
                    $files['files/' . self::storeKey($storeClass) . '/' . $name] = $store->path() . '/' . $name;
                }
            }
        }

        foreach (self::SETTING_FILES[$section] ?? [] as $key => $storeClass) {
            $store = new $storeClass();
            foreach ($rows['settings'] ?? $this->settingsRows() as $r) {
                if (!self::keyMatches((string) $r['name'], $key)) {
                    continue;
                }
                $name = (string) $r['value'];
                if (self::storeFileOk($name)) {
                    $files['files/' . self::storeKey($storeClass) . '/' . $name] = $store->path() . '/' . $name;
                }
            }
        }

        if ($section === 'history') {
            foreach (self::HISTORY_ROOTS as $kind => $root) {
                $base = self::root($root);
                foreach ($rows[$root['table']] ?? [] as $row) {
                    $rel = self::relativeTo((string) ($row[$root['column']] ?? ''), $base);
                    if ($rel === null) {
                        continue;
                    }
                    $files['files/history/' . $kind . '/' . $rel] = $base . '/' . $rel;
                    // Asterisk keeps a voicemail's envelope beside it.
                    if ($kind === 'voicemail') {
                        $txt = preg_replace('/\.[A-Za-z0-9]+$/', '.txt', $rel);
                        if ($txt !== null && $txt !== $rel) {
                            $files['files/history/voicemail/' . $txt] = $base . '/' . $txt;
                        }
                    }
                }
            }
        }

        return $files;
    }

    /** @return array<int,array{name:string,value:string}> */
    private function settingsRows(): array
    {
        $rows = [];
        foreach (Database::pdo()->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['name' => (string) $r['name'], 'value' => (string) $r['value']];
        }

        return $rows;
    }

    // ----------------------------------------------------------------- import

    /**
     * Keep an upload for the preview step. Returns its name, or null.
     */
    public function hold(string $tmpPath): ?string
    {
        if (!is_uploaded_file($tmpPath)) {
            return null;
        }
        $this->sweep();
        $dir = $this->path();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $name = 'pending-' . bin2hex(random_bytes(8)) . '.zip';

        return @move_uploaded_file($tmpPath, $dir . '/' . $name) ? $name : null;
    }

    /**
     * What is in an archive, without touching anything.
     *
     * @return array{ok:bool,error:?string,manifest:array,sections:array<string,array<string,int>>}
     */
    public function preview(string $name): array
    {
        $fail = static fn(string $why): array => ['ok' => false, 'error' => $why, 'manifest' => [], 'sections' => []];
        $file = $this->held($name);
        if ($file === null) {
            return $fail('That upload is no longer here — pick the file again.');
        }

        try {
            if (($bad = $this->unsafeMember($file)) !== null) {
                return $fail('That file contains a path twocans will not extract (' . $bad . ').');
            }
            $manifest = json_decode((string) @file_get_contents('phar://' . $file . '/manifest.json'), true);
        } catch (Throwable $e) {
            return $fail('That is not a twocans export: ' . $e->getMessage());
        }

        if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'twocans-move') {
            return $fail('That is not a twocans export — it has no manifest.');
        }
        if ((int) ($manifest['version'] ?? 0) > self::VERSION) {
            return $fail('That export was made by a newer twocans. Update this one first.');
        }

        $sections = [];
        foreach (array_keys(self::SECTIONS) as $section) {
            if (isset($manifest['sections'][$section]) && is_array($manifest['sections'][$section])) {
                $sections[$section] = array_map('intval', $manifest['sections'][$section]);
            }
        }

        return ['ok' => true, 'error' => null, 'manifest' => $manifest, 'sections' => $sections];
    }

    /**
     * Bring the chosen sections in.
     *
     * @param  array<int,string> $sections
     * @return array{ok:bool,error:?string,done:array<string,string>}
     */
    public function apply(string $name, array $sections, string $passphrase, int $byGuardianId): array
    {
        $fail = static fn(string $why): array => ['ok' => false, 'error' => $why, 'done' => []];

        $preview = $this->preview($name);
        if (!$preview['ok']) {
            return $fail((string) $preview['error']);
        }
        $file = (string) $this->held($name);

        $sections = array_values(array_filter($this->chosen($sections), static fn(string $s): bool => isset($preview['sections'][$s])));
        if ($sections === []) {
            return $fail('Tick at least one thing to bring in.');
        }

        $secrets = [];
        if (!empty($preview['manifest']['has_secrets'])) {
            if ($passphrase === '') {
                return $fail('This export has passwords and keys inside it — enter the passphrase it was made with.');
            }
            $opened = self::open((string) @file_get_contents('phar://' . $file . '/secrets.enc'), $passphrase);
            if ($opened === null) {
                return $fail("That passphrase doesn't open this export.");
            }
            $secrets = $opened;
        }

        $staging = sys_get_temp_dir() . '/twocans-move-' . bin2hex(random_bytes(4));
        @mkdir($staging, 0700, true);
        $done = [];

        try {
            (new PharData($file))->extractTo($staging, null, true);

            // Dependencies first, whatever order the boxes were ticked in.
            foreach (self::ORDER as $section) {
                if (!in_array($section, $sections, true)) {
                    continue;
                }
                $rows = json_decode((string) @file_get_contents($staging . '/' . $section . '.json'), true);
                if (!is_array($rows)) {
                    return $fail("The export's {$section} part is unreadable.");
                }
                $done[$section] = $this->importSection($section, $rows, $secrets, $staging, $byGuardianId);
            }
        } catch (Throwable $e) {
            return $fail('Import stopped: ' . $e->getMessage() . ' — sections before it were kept.');
        } finally {
            $this->removeTree($staging);
            foreach ($secrets as &$v) {
                if (is_string($v)) {
                    sodium_memzero($v);
                }
            }
            unset($v);
        }

        @unlink($file);

        return ['ok' => true, 'error' => null, 'done' => $done];
    }

    /**
     * One section, inside a transaction, files copied once the rows are in.
     *
     * @param  array<string,array<int,array>> $rows
     * @param  array<string,string>           $secrets
     */
    private function importSection(string $section, array $rows, array $secrets, string $staging, int $byGuardianId): string
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $summary = match ($section) {
                'guardians' => $this->importGuardians($rows['guardians'] ?? [], $secrets, $byGuardianId),
                'settings' => $this->importSettings($rows, $secrets),
                'line' => $this->importLine($rows, $secrets),
                default => $this->importReplace($section, $rows, $secrets, $byGuardianId),
            };
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException(self::SECTIONS[$section]['label'] . ': ' . $e->getMessage(), 0, $e);
        }

        $copied = $this->copyFiles($section, $rows, $staging);

        return $summary . ($copied > 0 ? ", {$copied} file" . ($copied === 1 ? '' : 's') : '');
    }

    /** The ordinary case: empty the section's tables, put the export's rows in. */
    private function importReplace(string $section, array $rows, array $secrets, int $byGuardianId): string
    {
        $pdo = Database::pdo();
        $tables = self::TABLES[$section];

        // Children first, so nothing is left pointing at a parent that has gone.
        foreach (array_reverse($tables) as $table) {
            $pdo->exec("DELETE FROM `{$table}`");
        }

        $counts = [];
        foreach ($tables as $table) {
            $list = $rows[$table] ?? [];
            $list = $this->fixUp($section, $table, $list, $rows, $byGuardianId);

            // A table that points at itself (a contact's failover) is inserted
            // without the pointer, then told about it once every row exists.
            $deferred = $table === 'contacts' ? 'failover_id' : null;
            $later = [];

            foreach ($list as $row) {
                if ($table === 'devices') {
                    $row['sip_secret'] = (string) ($secrets['devices.' . (int) ($row['id'] ?? 0) . '.sip_secret'] ?? '');
                    if ($row['sip_secret'] === '') {
                        // No password travelled with the phone: give it a fresh one
                        // rather than an empty one it could register with.
                        $row['sip_secret'] = bin2hex(random_bytes(16));
                    }
                }
                if ($deferred !== null && !empty($row[$deferred])) {
                    $later[(int) $row['id']] = (int) $row[$deferred];
                    $row[$deferred] = null;
                }
                $this->insert($table, $row);
            }

            foreach ($later as $id => $target) {
                $pdo->prepare("UPDATE `{$table}` SET `{$deferred}` = ? WHERE id = ?")->execute([$target, $id]);
            }
            $counts[] = count($list) . ' ' . str_replace('_', ' ', $table);
        }

        return implode(', ', $counts);
    }

    /**
     * References that no foreign key polices — fixed here so a partial import
     * cannot leave a phone pointing at a radio station that never came.
     *
     * @param array<int,array> $list
     */
    private function fixUp(string $section, string $table, array $list, array $rows, int $byGuardianId): array
    {
        $pdo = Database::pdo();
        $exists = static function (string $t, string $col = 'id') use ($pdo): array {
            return array_fill_keys(array_map('intval', $pdo->query("SELECT `{$col}` FROM `{$t}`")->fetchAll(PDO::FETCH_COLUMN)), true);
        };

        if ($table === 'devices') {
            $stations = $exists('radio_stations');
            $ids = array_fill_keys(array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $list), true);
            foreach ($list as &$r) {
                if (!empty($r['radio_station_id']) && !isset($stations[(int) $r['radio_station_id']])) {
                    $r['radio_station_id'] = null;
                }
                if (!empty($r['walkie_device_id']) && !isset($ids[(int) $r['walkie_device_id']])) {
                    $r['walkie_device_id'] = null;
                }
            }
            unset($r);
        }

        if ($table === 'contact_members') {
            $ids = array_fill_keys(array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $rows['contacts'] ?? []), true);
            $list = array_values(array_filter($list, static fn(array $r): bool => isset($ids[(int) ($r['contact_id'] ?? 0)]) && isset($ids[(int) ($r['member_contact_id'] ?? 0)])));
        }

        if ($table === 'radio_station_songs') {
            $songs = array_fill_keys(array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $rows['radio_songs'] ?? []), true);
            $stations = array_fill_keys(array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $rows['radio_stations'] ?? []), true);
            $list = array_values(array_filter($list, static fn(array $r): bool => isset($songs[(int) ($r['song_id'] ?? 0)]) && isset($stations[(int) ($r['station_id'] ?? 0)])));
        }

        // "Who added this" is a grown-up on the old box; here it is whoever is importing.
        foreach (['jokes' => 'added_by', 'radio_songs' => 'created_by', 'keepsakes' => 'kept_by'] as $t => $col) {
            if ($table === $t) {
                $guardians = $exists('guardians');
                foreach ($list as &$r) {
                    if (array_key_exists($col, $r) && !isset($guardians[(int) ($r[$col] ?? 0)])) {
                        $r[$col] = $byGuardianId;
                    }
                }
                unset($r);
            }
        }

        if ($section === 'history') {
            $devices = $exists('devices');
            $contacts = $exists('contacts');
            $guardians = $exists('guardians');
            foreach ($list as &$r) {
                foreach (['device_id' => $devices, 'listener_id' => $devices, 'contact_id' => $contacts,
                          'listened_by' => $guardians, 'resolved_by' => $guardians, 'guardian_id' => $guardians] as $col => $set) {
                    if (array_key_exists($col, $r) && $r[$col] !== null && !isset($set[(int) $r[$col]])) {
                        $r[$col] = null;
                    }
                }
            }
            unset($r);
            // An ask whose phone is gone cannot be kept: device_id is NOT NULL there.
            if ($table === 'call_requests' || $table === 'quiz_games' || $table === 'room_listens') {
                $list = array_values(array_filter($list, static fn(array $r): bool => !empty($r['device_id'])));
            }
        }

        return $list;
    }

    /** Grown-ups: merge by email, and leave the person doing the importing alone. */
    private function importGuardians(array $list, array $secrets, int $byGuardianId): string
    {
        $pdo = Database::pdo();
        $me = $pdo->prepare('SELECT LOWER(email) FROM guardians WHERE id = ?');
        $me->execute([$byGuardianId]);
        $myEmail = (string) $me->fetchColumn();

        $added = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($list as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '' || $email === $myEmail) {
                $skipped++;
                continue;
            }
            $hash = $secrets['guardians.' . $email . '.password_hash'] ?? null;
            $row['password_hash'] = $hash;
            // Without a password to bring, they are invited again rather than locked out.
            if ($hash === null) {
                $row['status'] = 'pending';
            }
            unset($row['id']);

            $st = $pdo->prepare('SELECT id FROM guardians WHERE LOWER(email) = ?');
            $st->execute([$email]);
            $existing = $st->fetchColumn();

            if ($existing !== false) {
                $cols = array_values(array_filter(array_keys($row), fn(string $c): bool => $c !== 'email' && $c !== 'password_hash' && in_array($c, $this->columns('guardians'), true)));
                if ($hash !== null) {
                    $cols[] = 'password_hash';
                }
                // The Owner role stays with whoever has it here.
                $cols = array_values(array_diff($cols, ['role', 'created_at']));
                if ($cols !== []) {
                    $set = implode(', ', array_map(static fn(string $c): string => "`{$c}` = ?", $cols));
                    $pdo->prepare("UPDATE guardians SET {$set} WHERE id = ?")
                        ->execute([...array_map(static fn(string $c) => $row[$c], $cols), (int) $existing]);
                }
                $updated++;
            } else {
                if (($row['role'] ?? '') === 'Owner') {
                    $row['role'] = 'Admin';   // one Owner per house, and that is the importer
                }
                $this->insert('guardians', $row);
                $added++;
            }
        }

        return "{$added} added, {$updated} updated" . ($skipped > 0 ? ", {$skipped} left as they are" : '');
    }

    /** Settings merge key by key; the single-row tables are replaced whole. */
    private function importSettings(array $rows, array $secrets): string
    {
        $pdo = Database::pdo();
        $n = 0;
        $up = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($rows['settings'] ?? [] as $r) {
            $k = (string) ($r['name'] ?? '');
            if ($k !== '' && self::isHouseholdSetting($k)) {
                $up->execute([$k, (string) ($r['value'] ?? '')]);
                $n++;
            }
        }
        foreach (self::SECRET_SETTINGS as $k) {
            if (isset($secrets['settings.' . $k])) {
                $up->execute([$k, base64_encode(Crypto::encrypt((string) $secrets['settings.' . $k]))]);
                $n++;
            }
        }

        $pdo->exec('DELETE FROM dialplan_rules');
        foreach ($rows['dialplan_rules'] ?? [] as $r) {
            $this->insert('dialplan_rules', $r);
        }
        $pdo->exec('DELETE FROM announcements');
        foreach ($rows['announcements'] ?? [] as $r) {
            $this->insert('announcements', $r);
        }

        foreach (['notifications', 'dynamic_dns'] as $table) {
            $r = ($rows[$table] ?? [])[0] ?? null;
            if ($r === null) {
                continue;
            }
            foreach (self::SECRET_COLUMNS[$table] as $col) {
                $plain = $secrets[$table . '.' . $col] ?? null;
                $r[$col] = $plain === null ? null : Crypto::encrypt($plain);
            }
            $pdo->exec("DELETE FROM `{$table}`");
            $this->insert($table, $r);
        }

        return "{$n} settings, " . count($rows['dialplan_rules'] ?? []) . ' rules, ' . count($rows['announcements'] ?? []) . ' announcements';
    }

    /** The line: one row, plus which phones its numbers ring. */
    private function importLine(array $rows, array $secrets): string
    {
        $pdo = Database::pdo();
        $trunk = ($rows['trunk'] ?? [])[0] ?? null;
        if ($trunk === null) {
            return 'no line in the export';
        }
        foreach (self::SECRET_COLUMNS['trunk'] as $col) {
            $plain = $secrets['trunk.' . $col] ?? null;
            $trunk[$col] = $plain === null ? null : Crypto::encrypt($plain);
        }
        $devices = array_fill_keys(array_map('intval', $pdo->query('SELECT id FROM devices')->fetchAll(PDO::FETCH_COLUMN)), true);
        if (!empty($trunk['ring_device_id']) && !isset($devices[(int) $trunk['ring_device_id']])) {
            $trunk['ring_device_id'] = null;
        }

        $pdo->exec('DELETE FROM trunk_number_rings');
        $pdo->exec('DELETE FROM trunk_number_mailboxes');
        $pdo->exec('DELETE FROM trunk');
        $this->insert('trunk', $trunk);

        $rings = 0;
        foreach ($rows['trunk_number_rings'] ?? [] as $r) {
            if (isset($devices[(int) ($r['device_id'] ?? 0)])) {
                $this->insert('trunk_number_rings', $r);
                $rings++;
            }
        }
        foreach ($rows['trunk_number_mailboxes'] ?? [] as $r) {
            $this->insert('trunk_number_mailboxes', $r);
        }

        $creds = isset($secrets['trunk.auth_token_enc']) || isset($secrets['trunk.api_key_enc']);

        return ($trunk['provider'] ?? 'line') . ' ' . ($trunk['number_e164'] ?? '') . ($creds ? ' with its credentials' : ' — credentials not included, run the wizard') . ", {$rings} number routes";
    }

    /** Put the section's files where this box keeps them. */
    private function copyFiles(string $section, array $rows, string $staging): int
    {
        $copied = 0;

        foreach (self::FILES[$section] ?? [] as [$table, $column, $storeClass]) {
            $store = new $storeClass();
            foreach ($rows[$table] ?? [] as $row) {
                $name = (string) ($row[$column] ?? '');
                $copied += $this->place($staging . '/files/' . self::storeKey($storeClass) . '/' . $name, $store->path(), $name);
            }
        }
        foreach (self::SETTING_FILES[$section] ?? [] as $key => $storeClass) {
            $store = new $storeClass();
            foreach ($rows['settings'] ?? $this->settingsRows() as $r) {
                if (self::keyMatches((string) $r['name'], $key)) {
                    $name = (string) $r['value'];
                    $copied += $this->place($staging . '/files/' . self::storeKey($storeClass) . '/' . $name, $store->path(), $name);
                }
            }
        }
        if ($section === 'history') {
            foreach (self::HISTORY_ROOTS as $kind => $root) {
                $from = $staging . '/files/history/' . $kind;
                if (!is_dir($from)) {
                    continue;
                }
                $base = self::root($root);
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    $rel = substr($f->getPathname(), strlen($from) + 1);
                    if (self::relOk($rel)) {
                        @mkdir(dirname($base . '/' . $rel), 0775, true);
                        if (@copy($f->getPathname(), $base . '/' . $rel)) {
                            $copied++;
                        }
                    }
                }
            }
        }

        return $copied;
    }

    /** Copy one store file in, only if its name is one a store would have made. */
    private function place(string $from, string $dir, string $name): int
    {
        if (!self::storeFileOk($name) || !is_file($from)) {
            return 0;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 0;
        }

        return @copy($from, $dir . '/' . $name) ? 1 : 0;
    }

    /**
     * INSERT a row, keeping only the columns this box's table actually has, and
     * dropping a null where the column refuses one — an export from another
     * version may carry a null the schema here will not take, and the column's
     * own default is the right answer in that case.
     */
    private function insert(string $table, array $row): void
    {
        $required = $this->notNull($table);
        $cols = array_values(array_filter(
            array_intersect(array_keys($row), $this->columns($table)),
            static fn(string $c): bool => $row[$c] !== null || !isset($required[$c])
        ));
        if ($cols === []) {
            return;
        }
        $sql = "INSERT INTO `{$table}` (" . implode(', ', array_map(static fn(string $c): string => "`{$c}`", $cols)) . ')'
             . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        Database::pdo()->prepare($sql)->execute(array_map(static fn(string $c) => $row[$c], $cols));
    }

    /** @return array<int,string> */
    private function columns(string $table): array
    {
        $this->describe($table);

        return array_keys($this->columns[$table]);
    }

    /** @return array<string,true> the columns that will not take a null */
    private function notNull(string $table): array
    {
        $this->describe($table);

        return array_filter($this->columns[$table]);
    }

    /** Read a table's columns once: name => true when NOT NULL. */
    private function describe(string $table): void
    {
        if (isset($this->columns[$table])) {
            return;
        }
        $this->columns[$table] = [];
        foreach (Database::pdo()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $this->columns[$table][(string) $c['Field']] = ($c['Null'] ?? 'YES') === 'NO';
        }
    }

    // ---------------------------------------------------------------- secrets

    /** Seal secrets under a passphrase: salt . nonce . secretbox(json). */
    public static function seal(array $secrets, string $passphrase): string
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = self::keyFrom($passphrase, $salt);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox((string) json_encode($secrets), $nonce, $key);
        sodium_memzero($key);

        return $salt . $nonce . $box;
    }

    /** @return array<string,string>|null  null when the passphrase is wrong or the blob is damaged */
    public static function open(string $blob, string $passphrase): ?array
    {
        $saltLen = SODIUM_CRYPTO_PWHASH_SALTBYTES;
        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($blob) < $saltLen + $nonceLen + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $key = self::keyFrom($passphrase, substr($blob, 0, $saltLen));
        $plain = sodium_crypto_secretbox_open(substr($blob, $saltLen + $nonceLen), substr($blob, $saltLen, $nonceLen), $key);
        sodium_memzero($key);
        if ($plain === false) {
            return null;
        }
        $data = json_decode($plain, true);
        sodium_memzero($plain);

        return is_array($data) ? array_map('strval', $data) : null;
    }

    private static function keyFrom(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    /** A Home Assistant secret as stored: base64 of the encrypted value, or empty. */
    private static function openSetting(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        try {
            return Crypto::decrypt((string) base64_decode($stored, true));
        } catch (Throwable) {
            return '';
        }
    }

    // ------------------------------------------------------------- the rules

    /** Settings a household takes with it, as opposed to ones that describe this box. */
    public static function isHouseholdSetting(string $key): bool
    {
        return !in_array($key, self::SETTINGS_SKIP, true) && preg_match('/^[a-z0-9_]{1,64}$/', $key) === 1;
    }

    /** The only names a store ever gives a file — anything else is not ours. */
    public static function storeFileOk(string $name): bool
    {
        return preg_match('/^[a-f0-9]{32}\.(wav|jpg)$/', $name) === 1;
    }

    /** A path inside a history root: relative, and never climbing out. */
    public static function relOk(string $rel): bool
    {
        if ($rel === '' || str_starts_with($rel, '/') || preg_match('#^[A-Za-z0-9._/-]+$#', $rel) !== 1) {
            return false;
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '..' || $segment === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The one member list the archive may contain. Checked before anything is
     * extracted — same discipline as Backup::unsafeMember().
     */
    public static function memberOk(string $member): bool
    {
        if (preg_match('/^(manifest\.json|secrets\.enc|[a-z]+\.json)$/', $member) === 1) {
            return true;
        }
        if (!str_starts_with($member, 'files/')) {
            return false;
        }

        return self::relOk(substr($member, 6));
    }

    private function unsafeMember(string $archive): ?string
    {
        $base = 'phar://' . $archive . '/';
        foreach (new RecursiveIteratorIterator(new PharData($archive)) as $f) {
            $member = substr($f->getPathname(), strlen($base));
            if (!self::memberOk($member)) {
                return $member;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<int,string> the known sections among those ticked, in display order */
    private function chosen(array $sections): array
    {
        return array_values(array_filter(array_keys(self::SECTIONS), static fn(string $s): bool => in_array($s, $sections, true)));
    }

    private static function storeKey(string $storeClass): string
    {
        return strtolower(preg_replace('/Store$/', '', $storeClass) ?: $storeClass);
    }

    private static function keyMatches(string $name, string $pattern): bool
    {
        return str_ends_with($pattern, '*') ? str_starts_with($name, substr($pattern, 0, -1)) : $name === $pattern;
    }

    private static function root(array $root): string
    {
        return rtrim(getenv($root['env']) ?: $root['default'], '/');
    }

    /** A recording's path relative to its root, or null if it is not under it (or not safe). */
    private static function relativeTo(string $path, string $base): ?string
    {
        if ($path === '') {
            return null;
        }
        $rel = str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : (str_starts_with($path, '/') ? null : $path);
        if ($rel === null || !self::relOk($rel)) {
            return null;
        }

        return $rel;
    }

    /** A held upload's full path, if it is still here. */
    private function held(string $name): ?string
    {
        if (preg_match('/^pending-[a-f0-9]{16}\.zip$/', $name) !== 1) {
            return null;
        }
        $file = $this->path() . '/' . $name;

        return is_file($file) ? $file : null;
    }

    /** A finished export's path, for the download — only names this class made. */
    public function exportFile(string $name): ?string
    {
        if (preg_match('/^' . preg_quote(self::PREFIX, '/') . '\d{8}-\d{4}-[a-f0-9]{6}\.zip$/', $name) !== 1) {
            return null;
        }
        $file = $this->path() . '/' . $name;

        return is_file($file) ? $file : null;
    }

    public function discard(string $name): void
    {
        $file = $this->held($name) ?? $this->exportFile($name);
        if ($file !== null) {
            @unlink($file);
        }
    }

    /** Exports and pending uploads older than an hour are nobody's any more. */
    private function sweep(): void
    {
        // Two globs, not one with braces: GLOB_BRACE does not exist on musl (Alpine).
        $stale = array_merge(glob($this->path() . '/' . self::PREFIX . '*.zip') ?: [], glob($this->path() . '/pending-*.zip') ?: []);
        foreach ($stale as $f) {
            if (time() - (int) @filemtime($f) > self::KEEP_SECONDS) {
                @unlink($f);
            }
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
