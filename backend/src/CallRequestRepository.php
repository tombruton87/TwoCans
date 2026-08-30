<?php
declare(strict_types=1);

/**
 * Asks to call — numbers a child tried to reach that aren't on the list.
 *
 * These are derived from the call log rather than reported by Asterisk: every
 * blocked attempt is already written to `calls` by the CDR import, so the ask
 * is built from what is there. That keeps the dialplan free of any obligation
 * to reach the database mid-call — a child dialling a blocked number gets the
 * same answer whether or not the app is running.
 *
 * What Asterisk does contribute is the voice note: after the blocked message it
 * invites the child to say who they were trying to call, and that recording is
 * matched up here by the call's uniqueid.
 */
final class CallRequestRepository
{
    /** Where the dialplan leaves "who were you calling?" recordings. */
    public function spoolPath(): string
    {
        return rtrim(getenv('ASK_RECORDINGS_PATH') ?: '/var/spool/asterisk/asks', '/');
    }

    /**
     * Fold newly blocked calls into the pending asks.
     *
     * Cheap enough to run on a page load: it only looks at blocked calls since
     * the newest ask it already knows about.
     */
    public function import(): int
    {
        $pdo = Database::pdo();

        $rows = $pdo->query(
            "SELECT c.uniqueid, c.dialled, c.peer_number, c.device_id, c.started_at
               FROM calls c
              WHERE c.status = 'blocked'
                AND c.peer_number <> ''
                AND c.started_at > COALESCE(
                        (SELECT MAX(last_asked_at) FROM call_requests cr), '1970-01-01')
              ORDER BY c.started_at ASC"
        )->fetchAll();

        if ($rows === []) {
            return 0;
        }

        $insert = $pdo->prepare(
            // :at cannot appear twice — native prepares bind by position, so a
            // reused name is "Invalid parameter number".
            'INSERT INTO call_requests (number_e164, device_id, requested_at, last_asked_at)
             VALUES (:number, :device, :first, :last)
             ON DUPLICATE KEY UPDATE
                 last_asked_at = GREATEST(COALESCE(last_asked_at, requested_at), VALUES(last_asked_at)),
                 attempts = attempts + 1,
                 device_id = COALESCE(VALUES(device_id), device_id),
                 -- A number that was turned down and is being tried again comes
                 -- back to the top: the child is still asking.
                 resolved_at = IF(resolution = "denied", NULL, resolved_at),
                 resolution  = IF(resolution = "denied", NULL, resolution)'
        );

        $added = 0;
        foreach ($rows as $row) {
            $insert->execute([
                'number' => (string) $row['peer_number'],
                'device' => $row['device_id'] === null ? null : (int) $row['device_id'],
                'first' => (string) $row['started_at'],
                'last' => (string) $row['started_at'],
            ]);
            $added++;

            $this->attachRecording((string) $row['uniqueid'], (string) $row['peer_number']);
        }

        return $added;
    }

    /**
     * Match a "who were you calling?" recording to its ask.
     *
     * The dialplan names the file after the call's uniqueid, which is the only
     * thing both sides know about.
     */
    private function attachRecording(string $uniqueid, string $number): void
    {
        if ($uniqueid === '') {
            return;
        }

        $file = $this->spoolPath() . '/' . $uniqueid . '.' . PjsipConfig::RECORDING_FORMAT;
        if (!is_file($file) || filesize($file) < 1024) {
            return;                       // hung up without saying anything
        }

        Database::pdo()->prepare(
            "UPDATE call_requests
                SET recording_path = ?, transcript_status = 'pending'
              WHERE number_e164 = ? AND recording_path IS NULL"
        )->execute([$file, $number]);
    }

    /** @return array<int,array> */
    public function pending(): array
    {
        return Database::pdo()->query(
            "SELECT * FROM call_requests WHERE resolution IS NULL ORDER BY last_asked_at DESC"
        )->fetchAll();
    }

    public function countPending(): int
    {
        return (int) Database::pdo()
            ->query("SELECT COUNT(*) FROM call_requests WHERE resolution IS NULL")
            ->fetchColumn();
    }

    public function find(?int $id): ?array
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM call_requests WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** How many times this number has been tried, straight from the call log. */
    public function attemptCount(string $number): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM calls WHERE status = 'blocked' AND peer_number = ?"
        );
        $stmt->execute([$number]);

        return (int) $stmt->fetchColumn();
    }

    public function decide(int $id, string $status, ?int $guardianId): void
    {
        if (!in_array($status, ['approved', 'denied'], true)) {
            return;
        }

        Database::pdo()->prepare(
            'UPDATE call_requests
                SET resolution = ?, resolved_by = ?, resolved_at = NOW()
              WHERE id = ?'
        )->execute([$status, $guardianId, $id]);
    }

    /** Delete the row and the voice note with it. */
    public function forget(int $id): void
    {
        $row = $this->find($id);
        if ($row === null) {
            return;
        }

        $this->deleteRecording($row);
        Database::pdo()->prepare('DELETE FROM call_requests WHERE id = ?')->execute([$id]);
    }

    public function deleteRecording(array $row): void
    {
        $path = (string) ($row['recording_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return;
        }

        // Only ever inside the ask spool, whatever the row says.
        $real = realpath($path);
        $base = realpath($this->spoolPath());
        if ($real !== false && $base !== false && str_starts_with($real, $base . '/')) {
            @unlink($real);
        }
    }

    // ------------------------------------------------------------- views

    public static function toView(array $row): array
    {
        $number = (string) $row['number_e164'];
        // Whisper puts a newline between segments, and a child asked "who are
        // you calling?" sometimes tells you the whole story. This is a name on
        // a card, so it gets one line and a sensible length.
        $transcript = trim(preg_replace('/\s+/', ' ', (string) ($row['label'] ?? '')) ?? '');
        if (mb_strlen($transcript) > 60) {
            $transcript = mb_substr($transcript, 0, 57) . '…';
        }
        $asked = strtotime((string) ($row['last_asked_at'] ?? $row['requested_at']));
        $today = date('Y-m-d') === date('Y-m-d', $asked);

        return [
            'id' => (int) $row['id'],
            'number' => $number,
            // What the child said, when they said anything. It is a guess at a
            // name, so the UI presents it as one.
            'saidName' => $transcript,
            'transcriptStatus' => (string) $row['transcript_status'],
            'hasRecording' => ($row['recording_path'] ?? '') !== '',
            'when' => $today ? date('g:ia', $asked) : date('D j M', $asked),
        ];
    }
}
