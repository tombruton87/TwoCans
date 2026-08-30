<?php
declare(strict_types=1);

/**
 * Deleting old recordings, transcripts and messages.
 *
 * What expires is the *content*, not the record: the audio file is deleted and
 * the transcript blanked, but the call log entry stays. A parent can still see
 * that Grandma rang on the 3rd of March a year later — they just can't listen
 * to it. That keeps the log useful while not keeping recordings of a child's
 * conversations on disk indefinitely.
 *
 * There is no cron. The sweep runs on a backend page load, at most once an
 * hour, which for a household phone system is often enough: the alternative is
 * another moving part to install, monitor and explain.
 */
final class Retention
{
    /** Leave at least this long between sweeps, however many pages are loaded. */
    private const INTERVAL_SECONDS = 3600;

    /**
     * Rows per sweep, per table.
     *
     * A first run against years of history should not make somebody's dashboard
     * hang. Whatever is left over is picked up on the next sweep.
     */
    private const BATCH = 200;

    public function __construct(private SettingsRepository $settings = new SettingsRepository())
    {
    }

    /**
     * Expire anything past its keep-until date.
     *
     * @param  bool $force ignore the once-an-hour guard (for the CLI)
     * @return array{calls:int,voicemails:int,ran:bool}
     */
    public function sweep(bool $force = false): array
    {
        $idle = ['calls' => 0, 'voicemails' => 0, 'ran' => false];

        $days = $this->settings->retentionDays();
        if ($days <= 0) {
            return $idle;                       // keeping everything, by choice
        }

        if (!$force && !$this->isDue()) {
            return $idle;
        }

        // Claim the sweep before doing the work, so two page loads arriving
        // together don't both walk the same rows. Losing a sweep to a crash
        // costs nothing — the next one picks the same rows up.
        $this->settings->markSwept();

        return [
            'calls' => $this->expireCalls($days),
            'voicemails' => $this->expireVoicemails($days),
            'ran' => true,
        ];
    }

    private function isDue(): bool
    {
        $last = $this->settings->lastSweep();

        return $last === null || (time() - $last) >= self::INTERVAL_SECONDS;
    }

    /**
     * Drop the audio and transcript of calls older than the window.
     */
    private function expireCalls(int $days): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, recording_path
               FROM calls
              WHERE started_at < (NOW() - INTERVAL :days DAY)
                AND content_expired_at IS NULL
                AND (recording_path IS NOT NULL OR transcript IS NOT NULL)
              ORDER BY started_at ASC
              LIMIT ' . self::BATCH
        );
        $stmt->execute(['days' => $days]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return 0;
        }

        $recordings = rtrim(getenv('RECORDINGS_PATH') ?: '/var/spool/asterisk/monitor', '/');

        $update = Database::pdo()->prepare(
            "UPDATE calls
                SET recording_path = NULL,
                    transcript = NULL,
                    transcript_error = NULL,
                    content_expired_at = NOW(),
                    -- Anything still queued must not come back round: the audio
                    -- it was waiting for has just been deleted.
                    transcript_status = IF(transcript_status IN ('pending','running'),
                                           'skipped', transcript_status)
              WHERE id = ?"
        );

        foreach ($rows as $row) {
            $this->deleteWithin($recordings, (string) ($row['recording_path'] ?? ''));
            $update->execute([(int) $row['id']]);
        }

        return count($rows);
    }

    private function expireVoicemails(int $days): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, audio_path
               FROM voicemails
              WHERE left_at < (NOW() - INTERVAL :days DAY)
                AND content_expired_at IS NULL
                AND (audio_path IS NOT NULL OR transcript IS NOT NULL)
              ORDER BY left_at ASC
              LIMIT ' . self::BATCH
        );
        $stmt->execute(['days' => $days]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return 0;
        }

        $spool = rtrim(getenv('VOICEMAIL_PATH') ?: '/var/spool/asterisk/voicemail', '/');

        $update = Database::pdo()->prepare(
            "UPDATE voicemails
                SET audio_path = NULL,
                    transcript = NULL,
                    transcript_error = NULL,
                    content_expired_at = NOW(),
                    transcript_status = IF(transcript_status IN ('pending','running'),
                                           'skipped', transcript_status)
              WHERE id = ?"
        );

        foreach ($rows as $row) {
            $path = (string) ($row['audio_path'] ?? '');
            $this->deleteWithin($spool, $path);

            // Asterisk stores a message as msgNNNN.wav plus msgNNNN.txt beside
            // it; leaving the metadata behind would leave the mailbox claiming
            // to hold a message whose audio has gone.
            if ($path !== '') {
                $this->deleteWithin($spool, preg_replace('/\.[A-Za-z0-9]+$/', '.txt', $path) ?? '');
            }

            $update->execute([(int) $row['id']]);
        }

        return count($rows);
    }

    /**
     * Delete a file, but only if it really sits inside the directory it should.
     *
     * The path comes from a database column. Checking it here means a bad or
     * tampered row can delete its own audio and nothing else.
     */
    private function deleteWithin(string $directory, string $path): void
    {
        if ($path === '' || !is_file($path)) {
            return;
        }

        $real = realpath($path);
        $base = realpath($directory);

        if ($real === false || $base === false || !str_starts_with($real, $base . '/')) {
            return;
        }

        @unlink($real);
    }

    /**
     * What the sweep would do right now, without doing it.
     *
     * @return array{calls:int,voicemails:int}
     */
    public function pending(): array
    {
        $days = $this->settings->retentionDays();
        if ($days <= 0) {
            return ['calls' => 0, 'voicemails' => 0];
        }

        $count = static function (string $table, string $when, string $audio, int $days): int {
            $stmt = Database::pdo()->prepare(
                "SELECT COUNT(*) FROM {$table}
                  WHERE {$when} < (NOW() - INTERVAL :days DAY)
                    AND content_expired_at IS NULL
                    AND ({$audio} IS NOT NULL OR transcript IS NOT NULL)"
            );
            $stmt->execute(['days' => $days]);

            return (int) $stmt->fetchColumn();
        };

        return [
            'calls' => $count('calls', 'started_at', 'recording_path', $days),
            'voicemails' => $count('voicemails', 'left_at', 'audio_path', $days),
        ];
    }
}
