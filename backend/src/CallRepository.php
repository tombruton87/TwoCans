<?php
declare(strict_types=1);

/**
 * The call log, built from Asterisk's CDR output.
 *
 * Asterisk writes a CSV row per call; this imports those rows into `calls`,
 * keyed on Asterisk's uniqueid so re-importing is a no-op. Going through the
 * file rather than a live AMI listener means no daemon to keep running, and
 * calls that happen while the web app is down still get recorded.
 */
final class CallRepository
{
    /**
     * Column order of Asterisk's Master.csv with loguniqueid + loguserfield on.
     * Documented at https://docs.asterisk.org — do not reorder.
     */
    private const CSV_COLUMNS = [
        'accountcode', 'src', 'dst', 'dcontext', 'clid', 'channel', 'dstchannel',
        'lastapp', 'lastdata', 'start', 'answer', 'end', 'duration', 'billsec',
        'disposition', 'amaflags', 'uniqueid', 'userfield',
    ];

    /** Asterisk disposition -> the three states the design shows. */
    private const DISPOSITIONS = [
        'ANSWERED' => 'done',
        'NO ANSWER' => 'missed',
        'NOANSWER' => 'missed',
        'BUSY' => 'missed',
        'FAILED' => 'missed',
        'CONGESTION' => 'missed',
    ];

    public function __construct(private DeviceRepository $devices = new DeviceRepository())
    {
    }

    public function csvPath(): string
    {
        return getenv('CDR_CSV_PATH') ?: '/var/log/asterisk/cdr-csv/Master.csv';
    }

    public function recordingsPath(): string
    {
        return rtrim(getenv('RECORDINGS_PATH') ?: '/var/spool/asterisk/monitor', '/');
    }

    /**
     * Absolute path of a call's recording, or null if there isn't one.
     *
     * MixMonitor names the file after the call's uniqueid, which is the same
     * key the record is imported under — so no path needs storing to match them
     * up, and a recording deleted from disk simply stops being offered.
     */
    public function recordingFile(string $uniqueid): ?string
    {
        if ($uniqueid === '' || !preg_match('/^[0-9a-z._-]+$/i', $uniqueid)) {
            return null;                        // never build a path from junk
        }

        $path = $this->recordingsPath() . '/' . $uniqueid . '.' . PjsipConfig::RECORDING_FORMAT;

        return is_readable($path) && filesize($path) > 0 ? $path : null;
    }

    /**
     * Attach recordings to calls that have one on disk.
     *
     * Done as a sweep rather than at insert time because MixMonitor finishes
     * writing at hangup, which can be after the CDR row has already appeared.
     */
    public function linkRecordings(): int
    {
        $pdo = Database::pdo();
        $rows = $pdo->query(
            'SELECT id, uniqueid FROM calls WHERE recording_path IS NULL AND uniqueid IS NOT NULL'
        )->fetchAll();

        $update = $pdo->prepare('UPDATE calls SET recording_path = ? WHERE id = ?');
        $linked = 0;

        foreach ($rows as $row) {
            $file = $this->recordingFile((string) $row['uniqueid']);
            if ($file === null) {
                continue;
            }
            $update->execute([$file, (int) $row['id']]);
            $linked++;
        }

        return $linked;
    }

    /**
     * Import any call records not already stored.
     *
     * @return int number of new calls added
     */
    public function import(): int
    {
        $path = $this->csvPath();
        if (!is_readable($path)) {
            return 0;
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return 0;
        }

        // Index devices by SIP username so a channel name can be resolved.
        $byUsername = [];
        foreach ($this->devices->all() as $row) {
            $byUsername[(string) $row['sip_username']] = $row;
        }

        $pdo = Database::pdo();
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO calls
                (uniqueid, device_id, contact_id, peer_name, peer_number, dialled,
                 direction, status, disposition, block_reason,
                 started_at, answered_at, duration_secs, billsec, transcript)
             VALUES
                (:uniqueid, :device_id, :contact_id, :peer_name, :peer_number, :dialled,
                 :direction, :status, :disposition, :block_reason,
                 :started_at, :answered_at, :duration, :billsec, NULL)'
        );

        $added = 0;
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            /*
             * Empty escape character, not the PHP default backslash: Asterisk
             * writes RFC 4180 CSV where an embedded quote is doubled ("" ), so
             * treating backslash as an escape would mangle any field that
             * legitimately contains one.
             */
            $values = str_getcsv($line, ',', '"', '');
            if (count($values) < 17) {
                continue;                       // not a row we understand
            }

            $cdr = array_combine(
                self::CSV_COLUMNS,
                array_pad(array_slice($values, 0, count(self::CSV_COLUMNS)), count(self::CSV_COLUMNS), '')
            );

            $call = $this->interpret($cdr, $byUsername);
            if ($call === null) {
                continue;
            }

            $insert->execute($call);
            $added += $insert->rowCount();
        }

        fclose($handle);

        $this->linkRecordings();
        $this->mergeListenEvents();

        return $added;
    }

    /**
     * Fold in the fact that somebody listened.
     *
     * Recorded while the call was live, when no `calls` row existed yet.
     */
    private function mergeListenEvents(): void
    {
        Database::pdo()->exec(
            'UPDATE calls c
               JOIN listen_events e ON e.uniqueid = c.uniqueid
                SET c.listened_in = 1,
                    c.listened_by = e.guardian_id,
                    c.listen_mode = e.mode
              WHERE c.listened_in = 0'
        );
    }

    /**
     * Turn one raw CDR row into a call record.
     *
     * Direction is worked out from who the call is *from*: if the caller number
     * is the device's own extension the child dialled out, otherwise somebody
     * rang in. Reading it from the channel alone gets originated calls (our
     * "test call" button) backwards, because Asterisk makes the rung device the
     * originating channel.
     */
    private function interpret(array $cdr, array $byUsername): ?array
    {
        $uniqueid = trim((string) $cdr['uniqueid']);
        if ($uniqueid === '') {
            return null;
        }

        $device = $this->deviceForChannel((string) $cdr['channel'], $byUsername)
               ?? $this->deviceForChannel((string) $cdr['dstchannel'], $byUsername);

        /*
         * Ignore records that involve none of our phones. Asterisk logs a CDR
         * for internal plumbing too — a Local channel produces one per half —
         * and none of that is a call a parent made or received.
         */
        if ($device === null) {
            return null;
        }

        $src = trim((string) $cdr['src']);
        $dst = trim((string) $cdr['dst']);
        $extension = $device === null ? null : (string) $device['extension'];

        $outbound = $extension !== null && $src === $extension;
        $peerNumber = $outbound ? $dst : $src;

        // The dialplan tags refused calls; anything else follows the disposition.
        $userfield = strtolower(trim((string) $cdr['userfield']));
        $status = $userfield === 'blocked'
            ? 'blocked'
            : (self::DISPOSITIONS[strtoupper(trim((string) $cdr['disposition']))] ?? 'missed');

        [$peerName, $contactId] = $this->identify($peerNumber, (string) $cdr['clid']);

        return [
            'uniqueid' => $uniqueid,
            'device_id' => $device === null ? null : (int) $device['id'],
            'contact_id' => $contactId,
            'peer_name' => $peerName,
            'peer_number' => $peerNumber !== '' ? $peerNumber : 'unknown',
            'dialled' => $dst,
            'direction' => $outbound ? 'out' : 'in',
            'status' => $status,
            'disposition' => trim((string) $cdr['disposition']),
            'block_reason' => $status === 'blocked' ? 'Not on the call list' : null,
            'started_at' => $this->timestamp((string) $cdr['start']) ?? date('Y-m-d H:i:s'),
            'answered_at' => $this->timestamp((string) $cdr['answer']),
            'duration' => (int) $cdr['duration'],
            'billsec' => (int) $cdr['billsec'],
        ];
    }

    private function deviceForChannel(string $channel, array $byUsername): ?array
    {
        // "PJSIP/playroom-a1b2-00000003" -> "playroom-a1b2"
        if (!preg_match('#^PJSIP/(.+)-[0-9a-f]{8}$#i', trim($channel), $m)) {
            return null;
        }

        return $byUsername[$m[1]] ?? null;
    }

    /** Put a name to a number: a test number, an allowlisted contact, or nothing. */
    private function identify(string $number, string $clid): array
    {
        $service = PjsipConfig::testNumbers();
        if (isset($service[$number])) {
            return [$service[$number]['label'], null];
        }
        if ($number === PjsipConfig::TEST_CALLER_NUMBER) {
            return [PjsipConfig::TEST_CALLER_NAME, null];
        }

        $contact = (new ContactRepository())->findByNumber($number);
        if ($contact !== null) {
            return [(string) $contact['name'], (int) $contact['id']];
        }

        // Fall back to the display name the caller presented, if any.
        if (preg_match('/^"?([^"<]+?)"?\s*</', trim($clid), $m)) {
            $name = trim($m[1]);
            if ($name !== '' && $name !== $number) {
                return [$name, null];
            }
        }

        return ['Unknown number', null];
    }

    /** Compare numbers by their digits, so formatting differences don't matter. */
    private function sameNumber(string $a, string $b): bool
    {
        $a = preg_replace('/\D/', '', $a) ?? '';
        $b = preg_replace('/\D/', '', $b) ?? '';

        return $a !== '' && $b !== '' && (str_ends_with($a, $b) || str_ends_with($b, $a));
    }

    private function timestamp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    // ------------------------------------------------------------- reading

    public function all(int $limit = 200): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM calls ORDER BY started_at DESC, id DESC LIMIT :limit'
        );
        $st->bindValue('limit', $limit, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    /** How many calls a page of the log shows. */
    public const PER_PAGE = 15;

    /**
     * Build the WHERE clause for the call log's filters.
     *
     * @return array{0:string,1:array} SQL fragment and its bound values
     */
    private function filterSql(array $filters): array
    {
        $where = ['1 = 1'];
        $bind = [];

        // Free-text search covers who the call was with and what was said —
        // a parent looking for "dentist" is as likely to remember the word from
        // the transcript as the name.
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            /*
             * Three separate placeholders for the same value: prepared
             * statements are not emulated (see Database), and MySQL's native
             * protocol cannot reuse one named parameter across several
             * positions — it fails with "Invalid parameter number".
             */
            $where[] = '(peer_name LIKE :term_name OR peer_number LIKE :term_num'
                     . ' OR peer_number LIKE :term_e164 OR transcript LIKE :term_text)';

            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
            $bind['term_name'] = $like;
            $bind['term_num'] = $like;
            $bind['term_text'] = $like;

            /*
             * Numbers are stored E.164 (+447700900456) but a parent types them
             * the way they write them (07700 900456). Search the normalised
             * form too, so both find the same calls.
             */
            $e164 = ContactRepository::toE164($term);
            $bind['term_e164'] = $e164 !== '' ? '%' . $e164 . '%' : $like;
        }

        $contactId = (int) ($filters['contact'] ?? 0);
        if ($contactId > 0) {
            // Match on the contact link where we have one, and fall back to the
            // number so calls logged before the person was added still show.
            $where[] = '(contact_id = :contact OR peer_number = :contact_number)';
            $bind['contact'] = $contactId;
            $contact = (new ContactRepository())->find($contactId);
            $bind['contact_number'] = $contact === null ? '' : (string) $contact['number_e164'];
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['done', 'missed', 'blocked'], true)) {
            $where[] = 'status = :status';
            $bind['status'] = $status;
        }

        return [implode(' AND ', $where), $bind];
    }

    /** One page of the call log, newest first. */
    public function search(array $filters = [], int $page = 1, int $perPage = self::PER_PAGE): array
    {
        [$where, $bind] = $this->filterSql($filters);
        $offset = max(0, ($page - 1) * $perPage);

        $st = Database::pdo()->prepare(
            "SELECT * FROM calls WHERE {$where} ORDER BY started_at DESC, id DESC
              LIMIT :limit OFFSET :offset"
        );
        foreach ($bind as $key => $value) {
            $st->bindValue($key, $value);
        }
        $st->bindValue('limit', $perPage, PDO::PARAM_INT);
        $st->bindValue('offset', $offset, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    public function countMatching(array $filters = []): int
    {
        [$where, $bind] = $this->filterSql($filters);

        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM calls WHERE {$where}");
        $st->execute($bind);

        return (int) $st->fetchColumn();
    }

    /**
     * People who actually appear in the log, for the filter list.
     *
     * Built from the calls themselves rather than the whole allowlist, so the
     * dropdown never offers a name that would return nothing.
     */
    public function callers(): array
    {
        return Database::pdo()->query(
            'SELECT c.id, c.name, COUNT(*) AS calls
               FROM calls k
               JOIN contacts c ON c.id = k.contact_id
           GROUP BY c.id, c.name
           ORDER BY c.name'
        )->fetchAll();
    }

    public function find(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM calls WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    public function countToday(string $status): int
    {
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM calls WHERE DATE(started_at) = CURDATE() AND status = ?'
        );
        $st->execute([$status]);

        return (int) $st->fetchColumn();
    }

    /** Map a stored row into the shape the call-log views expect. */
    public static function toView(array $row): array
    {
        $started = strtotime((string) $row['started_at']);
        $today = date('Y-m-d') === date('Y-m-d', $started);
        $yesterday = date('Y-m-d', strtotime('-1 day')) === date('Y-m-d', $started);

        $seconds = (int) $row['billsec'] > 0 ? (int) $row['billsec'] : (int) $row['duration_secs'];

        return [
            'id' => (int) $row['id'],
            'name' => (string) ($row['peer_name'] ?? 'Unknown number'),
            'initial' => initial((string) ($row['peer_name'] ?? '?')),
            'color' => self::colourFor((string) ($row['peer_name'] ?? '')),
            'number' => (string) $row['peer_number'],
            'dir' => (string) $row['direction'],
            'status' => (string) $row['status'],
            'date' => $today ? 'Today' : ($yesterday ? 'Yesterday' : date('D j M', $started)),
            'time' => date('g:ia', $started),
            'dur' => (int) $row['billsec'] > 0 ? fmt_duration($seconds) : '—',
            'transcript' => (string) ($row['transcript'] ?? ''),
            'recording' => (string) ($row['recording_path'] ?? ''),
            'hasRecording' => ($row['recording_path'] ?? '') !== '',
            'disposition' => (string) ($row['disposition'] ?? ''),
            'transcriptStatus' => (string) ($row['transcript_status'] ?? 'skipped'),
            'listenedIn' => (bool) ($row['listened_in'] ?? false),
            'listenMode' => (string) ($row['listen_mode'] ?? ''),
            'transcriptError' => (string) ($row['transcript_error'] ?? ''),
            'blockReason' => (string) ($row['block_reason'] ?? ''),
            // Content deleted by the retention policy, as opposed to never
            // having existed. The log entry stays; the recording does not.
            'contentExpired' => ($row['content_expired_at'] ?? null) !== null,
        ];
    }

    /** Stable colour per name, from the design palette. */
    private static function colourFor(string $name): string
    {
        $palette = ['#FFC857', '#FF7A59', '#5BC7B8', '#A78BD0', '#6FB7E8'];
        if ($name === '' || $name === 'Unknown number') {
            return '#C9B79E';
        }

        return $palette[abs(crc32($name)) % count($palette)];
    }
}
