<?php
declare(strict_types=1);

/**
 * Voicemail, backed by Asterisk's app_voicemail.
 *
 * Asterisk owns the messages: it records them, moves them between folders when
 * someone listens on the handset, and deletes them. This imports what it finds
 * in the spool so messages can be listed, transcribed and played in the web UI,
 * but the spool stays the source of truth.
 *
 * Messages are keyed on Asterisk's own `msg_id`, not the filename: listening on
 * the phone moves msg0000 from INBOX to Old and renumbers what is left behind,
 * so a filename is not a stable identity.
 */
final class VoicemailRepository
{
    /** Folders app_voicemail uses that we care about. */
    private const FOLDERS = ['INBOX', 'Old'];

    public function spoolPath(): string
    {
        return rtrim(getenv('VOICEMAIL_PATH') ?: '/var/spool/asterisk/voicemail', '/');
    }

    private function contextPath(): string
    {
        return $this->spoolPath() . '/' . PjsipConfig::VOICEMAIL_CONTEXT;
    }

    /**
     * Read the spool and bring the database in line with it.
     *
     * @return int number of newly seen messages
     */
    public function import(): int
    {
        $root = $this->contextPath();
        if (!is_dir($root)) {
            return 0;
        }

        $devices = [];
        foreach ((new DeviceRepository())->all() as $row) {
            $devices[(string) $row['extension']] = $row;
        }

        $pdo = Database::pdo();
        $insert = $pdo->prepare(
            'INSERT INTO voicemails
                (msg_id, device_id, mailbox, folder, contact_id, peer_name, peer_number,
                 left_at, duration_secs, heard, audio_path, transcript, transcript_status)
             VALUES
                (:msg_id, :device_id, :mailbox, :folder, :contact_id, :peer_name, :peer_number,
                 :left_at, :duration, :heard, :audio, NULL, "pending")
             ON DUPLICATE KEY UPDATE
                -- A message moved to another mailbox keeps its msg_id (see move()).
                mailbox = VALUES(mailbox),
                device_id = VALUES(device_id),
                folder = VALUES(folder),
                heard = VALUES(heard),
                audio_path = VALUES(audio_path)'
        );

        $seen = [];
        $added = 0;

        foreach ((array) glob($root . '/*', GLOB_ONLYDIR) as $mailboxDir) {
            $mailbox = basename($mailboxDir);

            foreach (self::FOLDERS as $folder) {
                foreach ((array) glob($mailboxDir . '/' . $folder . '/msg*.txt') as $meta) {
                    $message = $this->readMessage($meta, $mailbox, $folder, $devices);
                    if ($message === null) {
                        continue;
                    }

                    $seen[] = $message['msg_id'];
                    $insert->execute($message);
                    // rowCount is 1 for an insert, 2 for an update that changed.
                    $added += $insert->rowCount() === 1 ? 1 : 0;
                }
            }
        }

        $this->forgetMissing($seen);

        return $added;
    }

    /** Parse one msgNNNN.txt into a row. */
    private function readMessage(string $metaPath, string $mailbox, string $folder, array $devices): ?array
    {
        $audio = preg_replace('/\.txt$/', '.wav', $metaPath);
        if ($audio === null || !is_readable($audio)) {
            return null;                        // still being written
        }

        $meta = parse_ini_file($metaPath, false, INI_SCANNER_RAW) ?: [];
        $msgId = (string) ($meta['msg_id'] ?? '');
        if ($msgId === '') {
            // Older Asterisk builds omit msg_id; fall back to something stable
            // for this message: mailbox plus the moment it was recorded.
            $msgId = $mailbox . '-' . (string) ($meta['origtime'] ?? filemtime($audio));
        }

        $callerId = (string) ($meta['callerid'] ?? '');
        $number = '';
        $name = '';
        if (preg_match('/"?([^"<]*)"?\s*<([^>]*)>/', $callerId, $m)) {
            $name = trim($m[1]);
            $number = trim($m[2]);
        } else {
            $number = trim($callerId);
        }

        $device = $devices[$mailbox] ?? null;
        $contact = $number !== '' ? (new ContactRepository())->findByNumber($number) : null;

        if ($contact !== null) {
            $name = (string) $contact['name'];
        } elseif ($name === '' || $name === $number) {
            $name = $this->labelFor($number);
        }

        $origtime = (int) ($meta['origtime'] ?? 0);

        return [
            'msg_id' => $msgId,
            'device_id' => $device === null ? null : (int) $device['id'],
            'mailbox' => $mailbox,
            'folder' => $folder,
            'contact_id' => $contact === null ? null : (int) $contact['id'],
            'peer_name' => $name,
            'peer_number' => $number !== '' ? $number : 'unknown',
            'left_at' => date('Y-m-d H:i:s', $origtime > 0 ? $origtime : (int) filemtime($audio)),
            'duration' => (int) ($meta['duration'] ?? 0),
            // Asterisk moves a message out of INBOX once it has been played on
            // the handset, so the folder is the read/unread flag.
            'heard' => $folder === 'INBOX' ? 0 : 1,
            'audio' => $audio,
        ];
    }

    private function labelFor(string $number): string
    {
        if ($number === PjsipConfig::TEST_CALLER_NUMBER) {
            return PjsipConfig::TEST_CALLER_NAME;
        }

        $st = Database::pdo()->prepare('SELECT name FROM devices WHERE extension = ?');
        $st->execute([$number]);
        $device = $st->fetchColumn();

        return $device !== false ? (string) $device : 'Unknown number';
    }

    /** Drop rows for messages deleted on the handset. */
    private function forgetMissing(array $seenIds): void
    {
        $pdo = Database::pdo();
        if ($seenIds === []) {
            $pdo->exec('DELETE FROM voicemails WHERE msg_id IS NOT NULL');

            return;
        }

        $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
        $pdo->prepare("DELETE FROM voicemails WHERE msg_id IS NOT NULL AND msg_id NOT IN ({$placeholders})")
            ->execute($seenIds);
    }

    // -------------------------------------------------------------- reading

    public function all(int $limit = 200): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM voicemails ORDER BY left_at DESC, id DESC LIMIT :limit'
        );
        $st->bindValue('limit', $limit, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    /**
     * Messages from numbers nobody recognises, still waiting on a decision.
     *
     * A message counts as unrecognised when it came from nobody on the call
     * list — the importer leaves `contact_id` NULL for those — and from
     * something that is not one of this household's own extensions, since a
     * handset dialling its own mailbox is not an outside caller. Rows a
     * grown-up has already ruled on are left out, so the dashboard shows only
     * what is still waiting on someone.
     *
     * @return array<int,array>
     */
    public function unrecognised(int $limit = 20): array
    {
        $st = Database::pdo()->prepare(
            'SELECT v.*
               FROM voicemails v
              WHERE v.contact_id IS NULL
                AND v.resolution IS NULL
                AND NOT EXISTS (SELECT 1 FROM devices d WHERE d.extension = v.peer_number)
              ORDER BY v.left_at DESC, v.id DESC
              LIMIT :limit'
        );
        $st->bindValue('limit', $limit, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    /**
     * Record what a grown-up decided about an unrecognised caller's message.
     *
     * The message is left exactly where it is — this only stops it asking for a
     * decision again. Both the verdict and the `resolution IS NULL` guard are
     * deliberate: a hand-edited form cannot put a third state in an ENUM
     * column, and two tabs open on the same card cannot overwrite the first
     * decision.
     */
    public function resolve(int $id, string $resolution, ?int $guardianId): void
    {
        if (!in_array($resolution, ['approved', 'junk'], true)) {
            return;
        }

        Database::pdo()->prepare(
            'UPDATE voicemails
                SET resolution = ?, resolved_by = ?, resolved_at = NOW()
              WHERE id = ? AND resolution IS NULL'
        )->execute([$resolution, $guardianId, $id]);
    }

    public function find(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM voicemails WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    public function unheardCount(): int
    {
        return (int) Database::pdo()
            ->query('SELECT COUNT(*) FROM voicemails WHERE heard = 0')
            ->fetchColumn();
    }

    /**
     * Delete a message. Asterisk removes it (VoicemailRemove), so it closes
     * the gap in the mailbox's numbering and the phone's message light is
     * right; the files are only removed here if Asterisk can't be reached, or
     * no longer knows the message — say, one whose files are half gone.
     */
    public function remove(int $id): bool
    {
        $row = $this->find($id);
        if ($row === null) {
            return false;
        }

        $removed = false;
        try {
            $ami = new Ami();
            $ami->connect();
            $reply = $ami->send('VoicemailRemove', [
                'Context' => PjsipConfig::VOICEMAIL_CONTEXT,
                'Mailbox' => (string) $row['mailbox'],
                'Folder' => (string) $row['folder'],
                'ID' => (string) $row['msg_id'],
            ]);
            $removed = ($reply['response'] ?? '') === 'Success';
            $ami->disconnect();
        } catch (Throwable) {
            // Asterisk is down: remove the files ourselves, below.
        }

        if (!$removed) {
            $audio = (string) $row['audio_path'];
            $base = preg_replace('/\.[a-z0-9]+$/i', '', $audio);
            if ($base !== null && str_starts_with($base, $this->spoolPath() . '/')) {
                foreach ((array) glob($base . '.*') as $file) {
                    @unlink($file);
                }
            }
        }

        Database::pdo()->prepare('DELETE FROM voicemails WHERE id = ?')->execute([$id]);

        return true;
    }

    /**
     * Move a message to another mailbox — the house's, or a phone's, which it
     * can then hear by dialling 700. Asterisk does it (VoicemailForward, then
     * VoicemailRemove), so its numbering and message lights stay right; the
     * message keeps its msg_id, so its row — transcript and all — follows it.
     * It arrives unheard, in the new mailbox's INBOX.
     *
     * @return string|null what went wrong, or null when moved
     */
    public function move(int $id, string $toMailbox): ?string
    {
        $row = $this->find($id);
        if ($row === null) {
            return 'That message has gone.';
        }
        $from = (string) $row['mailbox'];
        if ($toMailbox === $from) {
            return null;
        }
        $known = [PjsipConfig::HOUSE_MAILBOX => null];
        foreach ((new DeviceRepository())->all() as $d) {
            if ((string) $d['extension'] !== '') {
                $known[(string) $d['extension']] = (int) $d['id'];
            }
        }
        if (!array_key_exists($toMailbox, $known)) {
            return 'There is no such mailbox.';
        }

        $where = ['Context' => PjsipConfig::VOICEMAIL_CONTEXT, 'Mailbox' => $from, 'Folder' => (string) $row['folder'], 'ID' => (string) $row['msg_id']];
        try {
            $ami = new Ami();
            $ami->connect();
            $copied = $ami->send('VoicemailForward', $where + [
                'ToContext' => PjsipConfig::VOICEMAIL_CONTEXT, 'ToMailbox' => $toMailbox, 'ToFolder' => 'INBOX',
            ]);
            if (($copied['response'] ?? '') !== 'Success') {
                $ami->disconnect();

                return 'Asterisk could not move it: ' . (string) ($copied['message'] ?? 'no reply');
            }
            $ami->send('VoicemailRemove', $where);
            $ami->disconnect();
        } catch (Throwable $e) {
            return 'Could not reach the phone system: ' . $e->getMessage();
        }

        // Follow it now, rather than at the next import: where it is, unheard.
        $audio = '';
        foreach ((array) glob($this->contextPath() . '/' . $toMailbox . '/INBOX/msg*.txt') as $meta) {
            $info = parse_ini_file($meta, false, INI_SCANNER_RAW) ?: [];
            if ((string) ($info['msg_id'] ?? '') === (string) $row['msg_id']) {
                $audio = (string) preg_replace('/\.txt$/', '.wav', $meta);
            }
        }
        Database::pdo()->prepare(
            "UPDATE voicemails SET mailbox = ?, device_id = ?, folder = 'INBOX', heard = 0,
                    audio_path = IF(? <> '', ?, audio_path) WHERE id = ?"
        )->execute([$toMailbox, $known[$toMailbox], $audio, $audio, $id]);

        return null;
    }

    /** Map a row to the shape the voicemail screen expects. */
    public static function toView(array $row): array
    {
        $left = strtotime((string) $row['left_at']);
        $today = date('Y-m-d') === date('Y-m-d', $left);
        $yesterday = date('Y-m-d', strtotime('-1 day')) === date('Y-m-d', $left);
        $name = (string) ($row['peer_name'] ?? 'Unknown number');

        return [
            'id' => (int) $row['id'],
            'msgId' => (string) ($row['msg_id'] ?? ''),
            'name' => $name,
            'initial' => initial($name),
            'color' => self::colourFor($name),
            'number' => (string) $row['peer_number'],
            'date' => $today ? 'Today' : ($yesterday ? 'Yesterday' : date('D j M', $left)),
            'time' => date('g:ia', $left),
            'dur' => fmt_duration((int) $row['duration_secs']),
            'heard' => (bool) $row['heard'],
            'mailbox' => (string) ($row['mailbox'] ?? ''),
            'transcript' => (string) ($row['transcript'] ?? ''),
            'transcriptStatus' => (string) ($row['transcript_status'] ?? 'pending'),
            'audio' => (string) ($row['audio_path'] ?? ''),
            'hasAudio' => ($row['audio_path'] ?? '') !== '',
            // Deleted by the retention policy rather than never recorded.
            'contentExpired' => ($row['content_expired_at'] ?? null) !== null,
            // Set once a grown-up has dealt with an unrecognised caller's
            // message: 'approved' when the number joined the call list, 'junk'
            // when it was dismissed. '' while it is still waiting on someone.
            'resolution' => (string) ($row['resolution'] ?? ''),
            'resolvedBy' => ($row['resolved_by'] ?? null) === null ? 0 : (int) $row['resolved_by'],
            'resolvedAt' => ($row['resolved_at'] ?? null) === null
                ? 0
                : (int) strtotime((string) $row['resolved_at']),
        ];
    }

    private static function colourFor(string $name): string
    {
        $palette = ['#FFC857', '#FF7A59', '#5BC7B8', '#A78BD0', '#6FB7E8'];
        if ($name === '' || $name === 'Unknown number') {
            return '#C9B79E';
        }

        return $palette[abs(crc32($name)) % count($palette)];
    }
}
