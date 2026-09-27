<?php
declare(strict_types=1);

/**
 * Announcements — buttons that page the phones with a recorded message.
 *
 * Pressing one asks Asterisk to call each chosen phone (see PjsipConfig's
 * twocans-page context) and play the recording once it is picked up — by a
 * child, or by the phone itself when the announcement asks for auto-answer
 * and the phone supports it. The same press can come from the dashboard or
 * from the announcement's trigger URL, so a home-automation system can say
 * "dinner's ready" too.
 */
final class AnnouncementRepository
{
    /** Caller number the phones see; the call log uses it to tell pages apart. */
    public const CALLER_NUMBER = 'announce';

    /** A second press this soon is a double click or a retrying webhook. */
    private const MIN_GAP_SECONDS = 10;

    /** @return array<int,array> in the order the dashboard shows them */
    public function all(): array
    {
        return array_map(
            [self::class, 'toView'],
            Database::pdo()->query('SELECT * FROM announcements ORDER BY sort_order, id')->fetchAll()
        );
    }

    public function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM announcements WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();

        return $row ? self::toView($row) : null;
    }

    public function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM announcements WHERE token = ?');
        $st->execute([$token]);
        $row = $st->fetch();

        return $row ? self::toView($row) : null;
    }

    public function create(): int
    {
        $pdo = Database::pdo();
        $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM announcements')->fetchColumn();
        $pdo->prepare('INSERT INTO announcements (label, token, sort_order) VALUES (?, ?, ?)')
            ->execute(['', bin2hex(random_bytes(16)), $next]);

        return (int) $pdo->lastInsertId();
    }

    /** @return string|null what is wrong, or null when saved */
    public function save(int $id, array $input): ?string
    {
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') {
            return 'Give the button a name, like “Dinner’s ready”.';
        }
        if (mb_strlen($label) > 60) {
            $label = mb_substr($label, 0, 60);
        }

        $emoji = self::icon((string) ($input['emoji'] ?? ''));

        $mode = ($input['mode'] ?? 'auto') === 'ring' ? 'ring' : 'auto';

        // Every phone, or the ones ticked. Ticking all of them is "every
        // phone" too, so a phone added later is included.
        $known = array_map(static fn(array $r): int => (int) $r['id'], (new DeviceRepository())->all());
        $picked = array_values(array_intersect($known, array_map('intval', (array) ($input['devices'] ?? []))));
        $devices = ($input['to'] ?? 'all') === 'some' && $picked !== [] && count($picked) < count($known)
            ? (string) json_encode($picked)
            : null;

        Database::pdo()->prepare(
            'UPDATE announcements SET label = ?, emoji = ?, mode = ?, device_ids = ?, repeat_play = ? WHERE id = ?'
        )->execute([$label, $emoji, $mode, $devices, !empty($input['repeat']) ? 1 : 0, $id]);

        return null;
    }

    public function setAudio(int $id, ?string $file, int $seconds = 0): void
    {
        Database::pdo()->prepare('UPDATE announcements SET audio_file = ?, audio_seconds = ? WHERE id = ?')
            ->execute([$file, $file === null ? 0 : $seconds, $id]);
    }

    /** A new trigger URL, for when the old one has been shared too widely. */
    public function newToken(int $id): void
    {
        Database::pdo()->prepare('UPDATE announcements SET token = ? WHERE id = ?')
            ->execute([bin2hex(random_bytes(16)), $id]);
    }

    public function delete(int $id): void
    {
        $row = $this->find($id);
        if ($row !== null) {
            (new AnnouncementStore())->delete($row['audio']);
        }
        Database::pdo()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
    }

    /**
     * Page the phones.
     *
     * @return array{ok:bool,phones:int,error:?string}
     */
    public function send(int $id): array
    {
        $a = $this->find($id);
        if ($a === null) {
            return ['ok' => false, 'phones' => 0, 'error' => 'That announcement is gone.'];
        }
        $path = $a['audio'] === '' ? null : (new AnnouncementStore())->playbackPath($a['audio']);
        if ($path === null) {
            return ['ok' => false, 'phones' => 0, 'error' => 'Record the message for “' . $a['label'] . '” first.'];
        }
        if ($a['lastSentAt'] !== null && time() - strtotime($a['lastSentAt']) < self::MIN_GAP_SECONDS) {
            return ['ok' => false, 'phones' => 0, 'error' => '“' . $a['label'] . '” was only just sent.'];
        }

        $sent = $this->page($path, $a['label'], $a['mode'], $a['devices'], $a['repeat']);
        if ($sent['ok']) {
            Database::pdo()->prepare('UPDATE announcements SET last_sent_at = NOW() WHERE id = ?')->execute([$id]);
        }

        return $sent;
    }

    /**
     * Play any recording on the phones — an announcement's, or one made on the
     * spot (Home Assistant's "say this").
     *
     * @param  string          $path     playback path, as AudioStore::playbackPath() gives it
     * @param  array<int>|null $devices  phone ids, or null for every phone
     * @return array{ok:bool,phones:int,error:?string}
     */
    public function page(string $path, string $label, string $mode, ?array $devices, bool $repeat = false): array
    {
        $targets = $this->targets(['devices' => $devices]);
        if ($targets === []) {
            return ['ok' => false, 'phones' => 0, 'error' => 'None of its phones are set up yet.'];
        }

        // A second of silence first, so the start isn't clipped while the
        // phone's audio opens; repeated once when asked, with a pause between.
        $data = 'silence/1&' . $path . ($repeat ? '&silence/2&' . $path : '');
        $name = str_replace(['"', '<', '>'], '', $label);

        try {
            $ami = new Ami();
            $ami->connect();
            $sent = 0;
            foreach ($targets as $deviceId) {
                $reply = $ami->send('Originate', [
                    'Channel' => 'Local/' . ($mode === 'auto' ? 'a' : 'r') . $deviceId . '@' . PjsipConfig::PAGE_CONTEXT . '/n',
                    'Application' => 'Playback',
                    'Data' => $data,
                    'CallerID' => '"' . $name . '" <' . self::CALLER_NUMBER . '>',
                    'Timeout' => 30000,
                    'Async' => 'true',
                ]);
                if (($reply['response'] ?? '') === 'Success') {
                    $sent++;
                }
            }
            $ami->disconnect();
        } catch (Throwable $e) {
            return ['ok' => false, 'phones' => 0, 'error' => 'Could not reach the phone system: ' . $e->getMessage()];
        }

        return $sent > 0
            ? ['ok' => true, 'phones' => $sent, 'error' => null]
            : ['ok' => false, 'phones' => 0, 'error' => 'The phone system turned the page down.'];
    }

    /** @return array<int,int> the phone ids this announcement goes to */
    private function targets(array $a): array
    {
        $ids = [];
        foreach ((new DeviceRepository())->all() as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['sipUsername'] === '' || !$d['available']) {
                continue;
            }
            if ($a['devices'] !== null && !in_array((int) $d['id'], $a['devices'], true)) {
                continue;
            }
            $ids[] = (int) $d['id'];
        }

        return $ids;
    }

    /**
     * A Font Awesome icon ("fa-solid fa-utensils", from the picker) or an
     * emoji. Anything else — a hand-edited form — falls back to the megaphone.
     */
    public static function icon(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^fa-(solid|regular|brands) fa-[a-z0-9-]{1,48}$/', $value)) {
            return $value;
        }
        if ($value !== '' && !preg_match('/[<>"&]/', $value) && mb_strlen($value) <= 4) {
            return $value;
        }

        return '📣';
    }

    public static function toView(array $row): array
    {
        $devices = json_decode((string) ($row['device_ids'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'emoji' => (string) ($row['emoji'] ?? '📣'),
            'audio' => (string) ($row['audio_file'] ?? ''),
            'seconds' => (int) ($row['audio_seconds'] ?? 0),
            'mode' => (string) $row['mode'],
            // Null is every phone.
            'devices' => is_array($devices) ? array_map('intval', $devices) : null,
            'repeat' => (bool) $row['repeat_play'],
            'token' => (string) $row['token'],
            'lastSentAt' => $row['last_sent_at'] ?? null,
        ];
    }
}
