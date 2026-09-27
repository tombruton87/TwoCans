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
     * With $refresh, calls already stored are re-read from their CDR as well —
     * who they were with, which way they went — and rows that should never have
     * been imported are dropped. For correcting history after the reading of a
     * CDR changes (bin/refresh-calls.php); a page load never does this, so a
     * contact renamed or removed later doesn't rewrite old calls.
     *
     * @return int number of new calls added
     */
    public function import(bool $refresh = false): int
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
            . ($refresh
                ? ' ON DUPLICATE KEY UPDATE device_id = VALUES(device_id), contact_id = VALUES(contact_id),
                        peer_name = VALUES(peer_name), peer_number = VALUES(peer_number),
                        dialled = VALUES(dialled), direction = VALUES(direction)'
                : '')
        );
        // Uniqueids of group-call member legs, and of every row that was kept:
        // one call can write several CDRs under the same uniqueid, so only a
        // uniqueid that produced nothing but member legs may be dropped.
        $confLegs = [];
        $kept = [];

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

            // A group call's leg, tagged by the dialplan: who it was and how far
            // they got. Not a call of its own — see call_participants.
            if (str_starts_with(trim((string) $cdr['userfield']), 'tcmember:')) {
                $this->recordParticipant($cdr);
                continue;
            }

            $call = $this->interpret($cdr, $byUsername);
            if ($call === null) {
                if (trim((string) $cdr['dcontext']) === PjsipConfig::CONF_CONTEXT) {
                    $confLegs[trim((string) $cdr['uniqueid'])] = true;
                }
                continue;
            }
            $kept[$call['uniqueid']] = true;

            $insert->execute($call);
            $added += $insert->rowCount();
        }

        fclose($handle);

        if ($refresh) {
            $this->dropConfLegs(array_keys(array_diff_key($confLegs, $kept)));
        }

        $this->linkRecordings();
        $this->mergeListenEvents();

        return $added;
    }

    /**
     * One leg of a group call, from its tagged CDR: tcmember:<contact>:<the
     * child's uniqueid>, with ":joined" once they pressed 1. A leg writes more
     * than one CDR, so the best outcome seen wins: joined, then answered (a
     * voicemail, or "not now"), then missed.
     */
    private function recordParticipant(array $cdr): void
    {
        $parts = explode(':', trim((string) $cdr['userfield']));
        $uniqueid = trim((string) $cdr['uniqueid']);
        $parent = (string) ($parts[2] ?? '');
        if ($uniqueid === '' || $parent === '') {
            return;
        }

        $status = ($parts[3] ?? '') === 'joined'
            ? 'joined'
            : (strtoupper(trim((string) $cdr['disposition'])) === 'ANSWERED' ? 'answered' : 'missed');

        Database::pdo()->prepare(
            "INSERT INTO call_participants (uniqueid, parent_uniqueid, contact_id, status, billsec, started_at)
             VALUES (:uniqueid, :parent, :contact, :status, :billsec, :started)
             ON DUPLICATE KEY UPDATE
                status = IF(FIELD(VALUES(status), 'missed', 'answered', 'joined') > FIELD(status, 'missed', 'answered', 'joined'),
                            VALUES(status), status),
                billsec = GREATEST(billsec, VALUES(billsec))"
        )->execute([
            'uniqueid' => $uniqueid,
            'parent' => $parent,
            'contact' => ctype_digit((string) ($parts[1] ?? '')) ? (int) $parts[1] : null,
            'status' => $status,
            'billsec' => (int) $cdr['billsec'],
            'started' => $this->timestamp((string) $cdr['start']),
        ]);
    }

    /**
     * Who each group call reached, keyed by the child's call uniqueid.
     *
     * @param  array<int,string> $uniqueids
     * @return array<string,array<int,array{name:string,status:string,seconds:int}>>
     */
    public function participants(array $uniqueids): array
    {
        $uniqueids = array_values(array_filter(array_unique($uniqueids)));
        if ($uniqueids === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($uniqueids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT p.parent_uniqueid, p.status, p.billsec, c.name
               FROM call_participants p
               LEFT JOIN contacts c ON c.id = p.contact_id
              WHERE p.parent_uniqueid IN ($marks)
              ORDER BY FIELD(p.status, 'joined', 'answered', 'missed'), c.name"
        );
        $st->execute($uniqueids);

        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(string) $row['parent_uniqueid']][] = [
                'name' => (string) ($row['name'] ?? 'Someone'),
                'status' => (string) $row['status'],
                'seconds' => (int) $row['billsec'],
            ];
        }

        return $out;
    }

    /**
     * "Anna joined · Tom didn't answer" — who a group call reached, in one line.
     *
     * @param array<int,array{name:string,status:string,seconds:int}> $people
     */
    public static function describeParticipants(array $people): string
    {
        $parts = [];
        foreach ($people as $p) {
            $parts[] = $p['name'] . ' ' . match ($p['status']) {
                'joined' => 'joined',
                'answered' => "answered but didn't join",
                default => "didn't answer",
            };
        }

        return implode(' · ', $parts);
    }

    /**
     * Remove group-call member legs imported before the import learned to skip
     * them. Only rows with nothing of their own attached — no recording, no
     * transcript — so a refresh can't take anything away.
     */
    private function dropConfLegs(array $uniqueids): void
    {
        $uniqueids = array_values(array_filter($uniqueids, static fn($u): bool => $u !== ''));
        foreach (array_chunk($uniqueids, 200) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            Database::pdo()->prepare(
                "DELETE FROM calls
                  WHERE uniqueid IN ($marks)
                    AND recording_path IS NULL
                    AND (transcript IS NULL OR transcript = '')"
            )->execute($chunk);
        }
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

        /*
         * A group call's member legs: each grown-up's phone, originated into
         * the conference. The child's own row already stands for the call — who
         * they called, how long for — so these would only add an "incoming
         * call from our own number" per person.
         */
        $context = trim((string) $cdr['dcontext']);
        if ($context === PjsipConfig::CONF_CONTEXT) {
            return null;
        }

        $src = trim((string) $cdr['src']);
        $dst = trim((string) $cdr['dst']);
        $extension = (string) $device['extension'];

        /*
         * Outgoing when the phone started it. The caller number alone can't say
         * so: a call out through the line presents the line's own number, not
         * the handset's extension. The phone being the originating channel in
         * the phones' own context can. An originated test call also has the
         * rung phone as originator, in that context, so it is told apart by
         * the caller number it presents and stays inbound.
         */
        $fromPhone = $this->deviceForChannel((string) $cdr['channel'], $byUsername) !== null
            && $context === PjsipConfig::DEVICES_CONTEXT
            // The test call and listening in both ring the phone as 929.
            && $src !== PjsipConfig::TEST_CALLER_NUMBER;
        $outbound = $src === $extension || $fromPhone;
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
        // An announcement paging the phone — named after its button.
        if ($number === AnnouncementRepository::CALLER_NUMBER) {
            $label = preg_match('/^"?([^"<]+?)"?\s*</', trim($clid), $m) ? trim($m[1]) : '';

            return [$label !== '' && $label !== $number ? 'Announcement: ' . $label : 'Announcement', null];
        }

        $contacts = new ContactRepository();
        $contact = $contacts->findByNumber($number);
        if ($contact !== null) {
            return [(string) $contact['name'], (int) $contact['id']];
        }

        // A speed dial — how a child calls nearly everyone, and the only way to
        // call a group, which has no number of its own.
        if (preg_match('/^\d{1,4}$/', $number)) {
            $contact = $contacts->findBySpeedDial($number);
            if ($contact !== null) {
                return [(string) $contact['name'], (int) $contact['id']];
            }
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
            'SELECT c.*, d.name AS device_name
               FROM calls c LEFT JOIN devices d ON d.id = c.device_id
              ORDER BY c.started_at DESC, c.id DESC LIMIT :limit'
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

    /**
     * Which page of the unfiltered log a call is on, so a link to one call can
     * open the log where it is. Same ordering as search(); null if it's gone.
     */
    public function pageOf(int $id, int $perPage = self::PER_PAGE): ?int
    {
        $st = Database::pdo()->prepare('SELECT started_at FROM calls WHERE id = ?');
        $st->execute([$id]);
        $started = $st->fetchColumn();
        if ($started === false) {
            return null;
        }

        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM calls WHERE started_at > :at OR (started_at = :at2 AND id > :id)'
        );
        $st->execute(['at' => $started, 'at2' => $started, 'id' => $id]);

        return intdiv((int) $st->fetchColumn(), max(1, $perPage)) + 1;
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
            // Asterisk's id for the call: how a group call finds its legs.
            'uniqueid' => (string) ($row['uniqueid'] ?? ''),
            'name' => (string) ($row['peer_name'] ?? 'Unknown number'),
            'initial' => initial((string) ($row['peer_name'] ?? '?')),
            'color' => self::colourFor((string) ($row['peer_name'] ?? '')),
            'number' => (string) $row['peer_number'],
            // What was dialled: for a call in from the line, which of our
            // numbers it came in on — see TrunkRepository::lineNumberFor().
            'dialled' => (string) ($row['dialled'] ?? ''),
            // Which of our phones it was on — only where the query joined it.
            'deviceName' => (string) ($row['device_name'] ?? ''),
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
