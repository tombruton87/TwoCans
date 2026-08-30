<?php
declare(strict_types=1);

/**
 * The jokes a child hears when they dial the joke line.
 *
 * Deliberately thin: a joke is a sound file, a transcript and a switch. The
 * choosing happens in the dialplan rather than here, so the line keeps working
 * when the database or the app is down — the same reason the rest of Asterisk's
 * config is generated to disk instead of read live.
 */
final class JokeRepository
{
    /**
     * A page's worth. Joke cards are taller than call-log rows — each carries a
     * player, an equalizer strip and an editable transcript — so fewer fit
     * comfortably before the page turns into a scroll.
     */
    public const PER_PAGE = 12;

    /** @return array<int,array> newest first */
    public function all(bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM jokes';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY id DESC';

        return Database::pdo()->query($sql)->fetchAll();
    }

    /**
     * One page of jokes, newest first.
     *
     * @return array<int,array>
     */
    public function page(int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        // LIMIT/OFFSET are ints computed here, never user text.
        return Database::pdo()
            ->query("SELECT * FROM jokes ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}")
            ->fetchAll();
    }

    public function find(?int $id): ?array
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM jokes WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function count(bool $enabledOnly = false): int
    {
        $sql = 'SELECT COUNT(*) FROM jokes' . ($enabledOnly ? ' WHERE enabled = 1' : '');

        return (int) Database::pdo()->query($sql)->fetchColumn();
    }

    /**
     * Record a converted joke.
     *
     * The audio is already on disk by the time this is called — JokeStore puts
     * it there — so this only stores the bookkeeping.
     */
    public function create(
        string $audioFile,
        int $seconds,
        ?string $sourceName,
        ?int $addedBy,
        ?string $sha256 = null
    ): int {
        Database::pdo()->prepare(
            'INSERT INTO jokes (audio_file, audio_sha256, duration_seconds, source_name, added_by)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$audioFile, $sha256, $seconds, $sourceName, $addedBy]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * The joke with this audio, if we already have it.
     *
     * The hash is of the converted audio, so the same clip is recognised
     * however it arrived — a re-added folder, a different filename, a different
     * source format.
     */
    public function findByHash(?string $sha256): ?array
    {
        if ($sha256 === null || $sha256 === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM jokes WHERE audio_sha256 = ?');
        $stmt->execute([$sha256]);

        return $stmt->fetch() ?: null;
    }

    /** Fill in the hash for a row imported before dedupe existed. */
    public function setHash(int $id, string $sha256): void
    {
        Database::pdo()->prepare('UPDATE jokes SET audio_sha256 = ? WHERE id = ?')
            ->execute([$sha256, $id]);
    }

    /** A parent correcting what Whisper heard. */
    public function setTranscript(int $id, string $text): void
    {
        Database::pdo()->prepare(
            "UPDATE jokes
                SET transcript = ?, transcript_status = 'done', transcript_error = NULL
              WHERE id = ?"
        )->execute([trim($text), $id]);
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        Database::pdo()->prepare('UPDATE jokes SET enabled = ? WHERE id = ?')
            ->execute([$enabled ? 1 : 0, $id]);
    }

    /** Remove the row and the audio with it. */
    public function delete(int $id): void
    {
        $row = $this->find($id);
        if ($row === null) {
            return;
        }

        (new JokeStore())->delete((string) $row['audio_file']);
        Database::pdo()->prepare('DELETE FROM jokes WHERE id = ?')->execute([$id]);
    }

    // ------------------------------------------------------ transcription

    /**
     * Jokes still waiting on Whisper.
     *
     * @return array<int,array>
     */
    public function pendingTranscription(int $limit = 10): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM jokes
              WHERE transcript_status IN ('pending', 'running')
                AND transcript_attempts < ?
              ORDER BY id ASC
              LIMIT " . max(1, $limit)
        );
        $stmt->execute([Transcriber::MAX_ATTEMPTS]);

        return $stmt->fetchAll();
    }

    public function markTranscriptionRunning(int $id): void
    {
        Database::pdo()->prepare(
            "UPDATE jokes
                SET transcript_status = 'running', transcript_attempts = transcript_attempts + 1
              WHERE id = ?"
        )->execute([$id]);
    }

    public function saveTranscript(int $id, string $text, string $engine): void
    {
        Database::pdo()->prepare(
            "UPDATE jokes
                SET transcript = ?, transcript_status = 'done', transcript_engine = ?,
                    transcript_error = NULL, transcribed_at = NOW()
              WHERE id = ?"
        )->execute([$text, $engine, $id]);
    }

    public function failTranscript(int $id, string $error, bool $permanent): void
    {
        Database::pdo()->prepare(
            'UPDATE jokes
                SET transcript_status = ?, transcript_error = ?
              WHERE id = ?'
        )->execute([$permanent ? 'failed' : 'pending', mb_substr($error, 0, 255), $id]);
    }

    /** Put an attempt back so an unreachable service costs nothing. */
    public function releaseTranscription(int $id): void
    {
        Database::pdo()->prepare(
            "UPDATE jokes
                SET transcript_status = 'pending',
                    transcript_attempts = GREATEST(transcript_attempts - 1, 0)
              WHERE id = ?"
        )->execute([$id]);
    }

    // ------------------------------------------------------------- views

    public static function toView(array $row): array
    {
        $transcript = trim((string) ($row['transcript'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'audioFile' => (string) $row['audio_file'],
            'transcript' => $transcript,
            'transcriptStatus' => (string) $row['transcript_status'],
            'seconds' => (int) $row['duration_seconds'],
            'duration' => fmt_duration((int) $row['duration_seconds']),
            'enabled' => (bool) $row['enabled'],
            'sourceName' => (string) ($row['source_name'] ?? ''),
            'addedOn' => date('j M Y', strtotime((string) $row['created_at'])),
        ];
    }
}
