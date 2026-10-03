<?php
declare(strict_types=1);

/**
 * Keepsakes: voicemails a family keeps for good — a five-year-old saying
 * goodnight to Grandad, the first message a child left by themselves.
 *
 * Keeping one copies its audio out of Asterisk's spool into a folder of its
 * own (storage/keepsakes), with who it was from, when, and what was said. So
 * the copy stays whatever happens to the message — deleted on the handset by
 * a child, moved, or expired by retention (see Retention), which never looks
 * in here. Only a grown-up removing a keepsake takes it away.
 *
 * A year's keepsakes download as one zip: the recordings, named by date and
 * who, and a list of what each one says.
 */
final class Keepsakes
{
    /** `storage/keepsakes`. KEEPSAKES_PATH moves it. */
    public function path(): string
    {
        return rtrim(getenv('KEEPSAKES_PATH') ?: '/var/lib/twocans/keepsakes', '/');
    }

    /**
     * Keep a voicemail.
     *
     * @return array{ok:bool,error:?string,id:?int}
     */
    public function keep(int $voicemailId, ?int $keptBy = null): array
    {
        $fail = static fn(string $why): array => ['ok' => false, 'error' => $why, 'id' => null];
        $repo = new VoicemailRepository();
        $row = $repo->find($voicemailId);
        if ($row === null) {
            return $fail('That message has gone.');
        }

        $existing = $this->findByMessage((string) $row['msg_id']);
        if ($existing !== null) {
            return ['ok' => true, 'error' => null, 'id' => (int) $existing['id']];
        }

        $source = (string) ($row['audio_path'] ?? '');
        // Only ever copy something inside the spool, whatever the row says.
        if ($source === '' || !str_starts_with($source, $repo->spoolPath() . '/') || !is_readable($source)) {
            return $fail("That message's recording isn't there any more, so it can't be kept.");
        }

        $dir = $this->path();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $fail("Couldn't keep it — nothing is writable at {$dir}.");
        }
        $file = bin2hex(random_bytes(12)) . '.wav';
        if (!@copy($source, $dir . '/' . $file)) {
            return $fail("Couldn't copy the recording into {$dir}.");
        }

        Database::pdo()->prepare(
            'INSERT INTO keepsakes
                (voicemail_msg_id, peer_name, peer_number, mailbox, recorded_at, duration_secs, transcript, audio_file, kept_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (string) $row['msg_id'],
            (string) $row['peer_name'],
            (string) $row['peer_number'],
            (string) $row['mailbox'],
            (string) $row['left_at'],
            (int) $row['duration_secs'],
            ($row['transcript'] ?? '') !== '' ? (string) $row['transcript'] : null,
            $file,
            $keptBy,
        ]);

        return ['ok' => true, 'error' => null, 'id' => (int) Database::pdo()->lastInsertId()];
    }

    public function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM keepsakes WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    private function findByMessage(string $msgId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM keepsakes WHERE voicemail_msg_id = ?');
        $st->execute([$msgId]);

        return $st->fetch() ?: null;
    }

    /**
     * Which of these messages are kept: msg_id => keepsake id.
     *
     * @return array<string,int>
     */
    public function keptMessages(): array
    {
        $out = [];
        foreach (Database::pdo()->query('SELECT id, voicemail_msg_id FROM keepsakes WHERE voicemail_msg_id IS NOT NULL') as $r) {
            $out[(string) $r['voicemail_msg_id']] = (int) $r['id'];
        }

        return $out;
    }

    /**
     * Every keepsake, by year, newest first.
     *
     * @return array<int,array<int,array>> year => keepsakes (as toView)
     */
    public function byYear(): array
    {
        // A message kept before it was transcribed takes its words when they come.
        Database::pdo()->exec(
            "UPDATE keepsakes k JOIN voicemails v ON v.msg_id = k.voicemail_msg_id
                SET k.transcript = v.transcript
              WHERE k.transcript IS NULL AND v.transcript IS NOT NULL AND v.transcript <> ''"
        );

        $out = [];
        foreach (Database::pdo()->query('SELECT * FROM keepsakes ORDER BY recorded_at DESC, id DESC') as $row) {
            $view = self::toView($row);
            $out[$view['year']][] = $view;
        }

        return $out;
    }

    public function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM keepsakes')->fetchColumn();
    }

    public function rename(int $id, string $title): void
    {
        Database::pdo()->prepare('UPDATE keepsakes SET title = ? WHERE id = ?')
            ->execute([mb_substr(trim($title), 0, 120), $id]);
    }

    /** Remove a keepsake, and its copy of the recording. The message itself isn't touched. */
    public function remove(int $id): bool
    {
        $row = $this->find($id);
        if ($row === null) {
            return false;
        }
        $file = $this->audioFile($row);
        if ($file !== null) {
            @unlink($file);
        }
        Database::pdo()->prepare('DELETE FROM keepsakes WHERE id = ?')->execute([$id]);

        return true;
    }

    /** The keepsake's recording on disk, or null when it's missing. */
    public function audioFile(array $row): ?string
    {
        $name = (string) $row['audio_file'];
        if (preg_match('/^[0-9a-f]{24}\.wav$/', $name) !== 1) {
            return null;
        }
        $file = $this->path() . '/' . $name;

        return is_readable($file) ? $file : null;
    }

    /**
     * A year's keepsakes as a zip in a temporary file: each recording named
     * by date and who ("2026-03-04 Grandad - Goodnight.wav"), and
     * keepsakes.txt, saying what each one says. The caller deletes the file.
     */
    public function zipYear(int $year): ?string
    {
        $rows = $this->byYear()[$year] ?? [];
        if ($rows === []) {
            return null;
        }

        $zip = sys_get_temp_dir() . '/twocans-keepsakes-' . bin2hex(random_bytes(6)) . '.zip';
        $archive = new PharData($zip, 0, null, Phar::ZIP);
        $index = "twocans keepsakes, {$year}\n\n";
        $used = [];
        foreach (array_reverse($rows) as $k) {
            $file = $this->audioFile($k['row']);
            if ($file === null) {
                continue;
            }
            $name = self::fileName($k);
            $base = $name;
            for ($n = 2; isset($used[$name]); $n++) {
                $name = $base . ' (' . $n . ')';
            }
            $used[$name] = true;
            $archive->addFile($file, $name . '.wav');
            $index .= $name . '.wav' . "\n  From " . $k['from'] . ', ' . $k['date'] . ' at ' . $k['time']
                . ($k['transcript'] !== '' ? "\n  “" . $k['transcript'] . '”' : '') . "\n\n";
        }
        $archive->addFromString('keepsakes.txt', $index);
        unset($archive);

        return $zip;
    }

    /** "2026-03-04 Grandad - Goodnight", with nothing a filesystem minds. */
    public static function fileName(array $k): string
    {
        $name = $k['isoDate'] . ' ' . $k['from'] . ($k['title'] !== '' ? ' - ' . $k['title'] : '');

        return trim((string) preg_replace('/[^\p{L}\p{N} ._\'-]+/u', '', $name));
    }

    public static function toView(array $row): array
    {
        $at = (int) strtotime((string) $row['recorded_at']);
        $from = (string) $row['peer_name'] !== '' ? (string) $row['peer_name'] : (string) $row['peer_number'];
        $secs = (int) $row['duration_secs'];

        return [
            'id' => (int) $row['id'],
            'row' => $row,
            'title' => (string) $row['title'],
            'from' => $from !== '' ? $from : 'Someone',
            'initial' => initial($from !== '' ? $from : '?'),
            'year' => (int) date('Y', $at),
            'isoDate' => date('Y-m-d', $at),
            'date' => date('D j M Y', $at),
            'time' => date('g:ia', $at),
            'dur' => intdiv($secs, 60) . ':' . str_pad((string) ($secs % 60), 2, '0', STR_PAD_LEFT),
            'transcript' => (string) ($row['transcript'] ?? ''),
        ];
    }
}
