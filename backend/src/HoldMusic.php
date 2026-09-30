<?php
declare(strict_types=1);

/**
 * Music on hold. Out of the box, Asterisk's own Opsound set (the "default"
 * class, fetched by install.sh — see musiconhold.conf). Once a grown-up
 * uploads tracks of their own, those play instead: they are the "twocans"
 * class, written to generated/musiconhold-custom.conf, and every phone's
 * endpoint asks for it (moh_suggest — see PjsipConfig), which is the class the
 * other party hears when that phone puts them on hold.
 */
final class HoldMusic
{
    public const CLASS_NAME = 'twocans';

    /** Where Asterisk sees the store's folder: storage is mounted read-only at the same path. */
    private const ASTERISK_DIR = '/var/lib/twocans/refusals/moh';

    /** @return array<int,array{id:int,file:string,name:string,seconds:int}> oldest first */
    public function all(): array
    {
        try {
            $rows = Database::pdo()->query('SELECT * FROM hold_music ORDER BY id')->fetchAll();
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'file' => (string) $r['audio_file'],
            'name' => (string) $r['name'],
            'seconds' => (int) $r['seconds'],
        ], $rows);
    }

    public function find(int $id): ?array
    {
        foreach ($this->all() as $t) {
            if ($t['id'] === $id) {
                return $t;
            }
        }

        return null;
    }

    /** Whether there's music of the household's own to play. */
    public function hasOwn(): bool
    {
        return $this->all() !== [];
    }

    public function add(string $file, string $name, int $seconds): void
    {
        Database::pdo()->prepare('INSERT INTO hold_music (audio_file, name, seconds) VALUES (?, ?, ?)')
            ->execute([$file, mb_substr($name, 0, 120), $seconds]);
    }

    public function remove(int $id): void
    {
        $track = $this->find($id);
        if ($track === null) {
            return;
        }
        (new HoldMusicStore())->delete($track['file']);
        Database::pdo()->prepare('DELETE FROM hold_music WHERE id = ?')->execute([$id]);
    }

    /** A track's name from what it was uploaded as: "Let It Go.mp3" is "Let It Go". */
    public static function nameFrom(string $filename): string
    {
        $name = trim((string) preg_replace('/[_]+/', ' ', pathinfo($filename, PATHINFO_FILENAME)));

        return $name !== '' ? $name : 'A track';
    }

    /** The "twocans" class, when there's music for it; empty otherwise. */
    public function render(): string
    {
        if (!$this->hasOwn()) {
            return "; No music of the household's own — the built-in set plays.\n";
        }

        return "; The household's own hold music, uploaded on the Greetings screen.\n"
            . '[' . self::CLASS_NAME . "]\nmode = files\ndirectory = " . self::ASTERISK_DIR . "\nsort = alpha\n";
    }
}
