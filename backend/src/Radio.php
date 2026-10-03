<?php
declare(strict_types=1);

/**
 * The radio: songs a household uploads, played one after another on a number
 * a child dials (7234, R-A-D-I-O, by default), until they hang up. # skips to
 * the next song, 5 pauses, 6 and 4 jump forward and back, ★ the stations.
 *
 * Stations are playlists on it: up to MAX_STATIONS, offered from a menu, or
 * straight away on a phone with a favourite. At bedtime it can play only the
 * calm songs, or be off, and stop by itself after a while (the sleep timer).
 *
 * The dialplan is PjsipConfig::renderRadio(); the songs are kept by
 * RadioStore, the stations' spoken names by RadioStationStore.
 */
final class Radio
{
    /** Stations on the menu: a key each, 1 to 5. */
    public const MAX_STATIONS = 5;

    /** What the radio does at bedtime. */
    public const BEDTIME = [
        'normal' => 'Plays as normal',
        'calm' => 'Plays only the calm songs',
        'off' => "Is off — \"Shh, it's bedtime\"",
    ];

    /** The sleep timer at bedtime, in minutes; 0 is none. */
    public const SLEEP_MINUTES = [0, 10, 20, 30, 45, 60];

    /** @return array<int,array> rows, in the household's order */
    public function all(bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM radio_songs' . ($enabledOnly ? ' WHERE enabled = 1' : '') . ' ORDER BY position, id';

        return Database::pdo()->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM radio_songs WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    public function findByHash(?string $sha256): ?array
    {
        if ($sha256 === null || $sha256 === '') {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM radio_songs WHERE sha256 = ?');
        $st->execute([$sha256]);

        return $st->fetch() ?: null;
    }

    public function create(string $file, int $seconds, string $title, ?int $by, ?string $sha256): int
    {
        // On the end of the list.
        $next = (int) Database::pdo()->query('SELECT COALESCE(MAX(position), 0) + 1 FROM radio_songs')->fetchColumn();
        Database::pdo()->prepare(
            'INSERT INTO radio_songs (title, audio_file, seconds, sha256, created_by, position) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([self::titleFrom($title), $file, $seconds, $sha256, $by, $next]);

        return (int) Database::pdo()->lastInsertId();
    }

    public function rename(int $id, string $title): void
    {
        Database::pdo()->prepare('UPDATE radio_songs SET title = ? WHERE id = ?')
            ->execute([mb_substr(trim($title), 0, 120), $id]);
    }

    /**
     * Put the songs in this order: their ids, first to last. Any song not
     * named keeps its place after them, so a list that's out of date can't
     * lose one.
     *
     * @param array<int,int|string> $ids
     */
    public function reorder(array $ids): void
    {
        $order = [];
        foreach ($ids as $id) {
            if ((int) $id > 0 && !in_array((int) $id, $order, true)) {
                $order[] = (int) $id;
            }
        }
        foreach ($this->all() as $row) {
            if (!in_array((int) $row['id'], $order, true)) {
                $order[] = (int) $row['id'];
            }
        }
        $st = Database::pdo()->prepare('UPDATE radio_songs SET position = ? WHERE id = ?');
        foreach ($order as $i => $id) {
            $st->execute([$i + 1, $id]);
        }
    }

    public function setCalm(int $id, bool $calm): void
    {
        Database::pdo()->prepare('UPDATE radio_songs SET calm = ? WHERE id = ?')->execute([$calm ? 1 : 0, $id]);
    }

    // ----------------------------------------------------------- stations

    /** @return array<int,array> rows, in menu order — the first is key 1 */
    public function stations(): array
    {
        return Database::pdo()->query('SELECT * FROM radio_stations ORDER BY position, id')->fetchAll();
    }

    public function station(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM radio_stations WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    /** Add a station, on the end of the menu — or null when there are already MAX_STATIONS. */
    public function addStation(string $name): ?int
    {
        if (count($this->stations()) >= self::MAX_STATIONS) {
            return null;
        }
        $next = (int) Database::pdo()->query('SELECT COALESCE(MAX(position), 0) + 1 FROM radio_stations')->fetchColumn();
        $name = mb_substr(trim($name), 0, 60);
        Database::pdo()->prepare('INSERT INTO radio_stations (name, position) VALUES (?, ?)')
            ->execute([$name !== '' ? $name : 'Station ' . $next, $next]);

        return (int) Database::pdo()->lastInsertId();
    }

    public function renameStation(int $id, string $name): void
    {
        $name = mb_substr(trim($name), 0, 60);
        if ($name !== '') {
            Database::pdo()->prepare('UPDATE radio_stations SET name = ? WHERE id = ?')->execute([$name, $id]);
        }
    }

    public function setStationShuffle(int $id, bool $on): void
    {
        Database::pdo()->prepare('UPDATE radio_stations SET shuffle = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    }

    /** The household saying the station's name (a RadioStationStore name), or null for "station one". */
    public function setStationAudio(int $id, ?string $file): void
    {
        $old = $this->station($id);
        if ($old !== null && ($old['name_audio'] ?? null) !== null && $old['name_audio'] !== $file) {
            (new RadioStationStore())->delete((string) $old['name_audio']);
        }
        Database::pdo()->prepare('UPDATE radio_stations SET name_audio = ? WHERE id = ?')->execute([$file, $id]);
    }

    /** Take a station off: its songs stay, and phones with it as favourite go back to the menu. */
    public function deleteStation(int $id): void
    {
        $this->setStationAudio($id, null);
        Database::pdo()->prepare('UPDATE devices SET radio_station_id = NULL WHERE radio_station_id = ?')->execute([$id]);
        Database::pdo()->prepare('DELETE FROM radio_stations WHERE id = ?')->execute([$id]);
    }

    /** @return array<int,int> the ids of the songs on a station */
    public function stationSongIds(int $stationId): array
    {
        $st = Database::pdo()->prepare('SELECT song_id FROM radio_station_songs WHERE station_id = ?');
        $st->execute([$stationId]);

        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** song id => the station ids it's on. @return array<int,array<int,int>> */
    public function songStations(): array
    {
        $out = [];
        foreach (Database::pdo()->query('SELECT song_id, station_id FROM radio_station_songs') as $r) {
            $out[(int) $r['song_id']][] = (int) $r['station_id'];
        }

        return $out;
    }

    /** Put a song on a station, or take it off. */
    public function setOnStation(int $songId, int $stationId, bool $on): void
    {
        Database::pdo()->prepare($on
            ? 'INSERT IGNORE INTO radio_station_songs (station_id, song_id) VALUES (?, ?)'
            : 'DELETE FROM radio_station_songs WHERE station_id = ? AND song_id = ?')
            ->execute([$stationId, $songId]);
    }

    public function setEnabled(int $id, bool $on): void
    {
        Database::pdo()->prepare('UPDATE radio_songs SET enabled = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    }

    /** Take a song off, and its recording with it. */
    public function delete(int $id): void
    {
        $row = $this->find($id);
        if ($row === null) {
            return;
        }
        (new RadioStore())->delete((string) $row['audio_file']);
        Database::pdo()->prepare('DELETE FROM radio_songs WHERE id = ?')->execute([$id]);
    }

    /**
     * Playback paths of every song that's on and still has its audio, in
     * order: all of them, or one station's; all, or only the calm ones.
     *
     * @return array<int,string>
     */
    public function playlist(?int $stationId = null, bool $calmOnly = false): array
    {
        $store = new RadioStore();
        $files = [];
        $on = $stationId === null ? null : $this->stationSongIds($stationId);
        foreach ($this->all(true) as $row) {
            if (($on !== null && !in_array((int) $row['id'], $on, true)) || ($calmOnly && !(bool) $row['calm'])) {
                continue;
            }
            $path = $store->playbackPath((string) $row['audio_file']);
            if ($path !== null) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /** A title from an uploaded file's name: "01 - Baby Shark (Remix).mp3" is "Baby Shark (Remix)". */
    public static function titleFrom(string $name): string
    {
        $title = preg_replace('/\.[a-z0-9]{2,5}$/i', '', trim($name)) ?? '';
        $title = preg_replace('/^\d{1,3}\s*[-._)]?\s*/', '', $title) ?? $title;
        $title = trim(str_replace('_', ' ', $title));

        return mb_substr($title !== '' ? $title : 'A song', 0, 120);
    }

    public static function toView(array $row): array
    {
        $secs = (int) $row['seconds'];

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'file' => (string) $row['audio_file'],
            'enabled' => (bool) $row['enabled'],
            'calm' => (bool) ($row['calm'] ?? false),
            'length' => intdiv($secs, 60) . ':' . str_pad((string) ($secs % 60), 2, '0', STR_PAD_LEFT),
            'seconds' => $secs,
        ];
    }
}
