<?php
declare(strict_types=1);

/**
 * The jokes twocans ships with (storage/defaults/jokes/), added to a
 * household's own joke line — so a new install has something to dial 258 for.
 *
 * jokes.json pairs each clip with its transcript. The transcripts are written
 * by hand, not heard: puns are homophones, and speech-to-text writes "away for
 * so long" for "a wafer so long".
 *
 * Run by bin/migrate.php, so every install and update adds the ones it hasn't
 * had yet. Each joke ever offered is remembered (by the hash of its audio), so
 * one the household deleted stays deleted, and one it already had — uploaded
 * itself, say — isn't added twice.
 */
final class DefaultJokes
{
    /** The hashes of every default joke this household has been offered. */
    private const OFFERED = 'default_jokes_offered';

    public static function dir(): string
    {
        return rtrim(getenv('DEFAULT_JOKES_PATH') ?: PjsipConfig::DEFAULTS_DIR . '/jokes', '/');
    }

    /**
     * The shipped jokes: file (a path) and transcript, each.
     *
     * @return list<array{file:string,transcript:string}>
     */
    public static function all(): array
    {
        $manifest = json_decode((string) @file_get_contents(self::dir() . '/jokes.json'), true);
        $jokes = [];
        foreach (is_array($manifest) ? $manifest : [] as $j) {
            $file = basename((string) ($j['file'] ?? ''));
            $text = trim((string) ($j['transcript'] ?? ''));
            if ($file !== '' && $text !== '' && is_file(self::dir() . '/' . $file)) {
                $jokes[] = ['file' => self::dir() . '/' . $file, 'transcript' => $text];
            }
        }

        return $jokes;
    }

    /**
     * Add the default jokes this household hasn't been offered yet.
     *
     * The clips are already in the format the line plays, so each is copied
     * in as it is — the same bytes, so the same hash as anywhere else.
     *
     * @return int How many were added.
     */
    public static function seed(?JokeStore $store = null, ?JokeRepository $jokes = null): int
    {
        $store ??= new JokeStore();
        $jokes ??= new JokeRepository();
        $settings = new SettingsRepository();
        $offered = array_filter(explode(',', $settings->all()[self::OFFERED] ?? ''));
        $added = 0;

        foreach (self::all() as $joke) {
            $sha = hash_file('sha256', $joke['file']) ?: '';
            if ($sha === '' || in_array($sha, $offered, true)) {
                continue;
            }
            if ($jokes->findByHash($sha) === null) {
                $name = bin2hex(random_bytes(16)) . '.wav';
                if (!is_dir($store->path()) || !@copy($joke['file'], $store->path() . '/' . $name)) {
                    // Nowhere to put it: left un-offered, so the next run tries again.
                    continue;
                }
                @chmod($store->path() . '/' . $name, 0644);
                $seconds = (int) round(max(1, (filesize($joke['file']) - 44) / 16000));   // 8 kHz, 16-bit, mono
                $id = $jokes->create($name, $seconds, basename($joke['file']), null, $sha);
                $jokes->saveTranscript($id, $joke['transcript'], 'twocans');
                $added++;
            }
            $offered[] = $sha;
        }

        $settings->set(self::OFFERED, implode(',', $offered));

        return $added;
    }
}
