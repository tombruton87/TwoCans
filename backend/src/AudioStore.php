<?php
declare(strict_types=1);

/**
 * Audio a parent uploads, converted into something the handsets will play.
 *
 * Parents upload whatever they have — a voice memo off a phone, an MP3, a clip
 * from a text-to-speech site — and none of it is playable by Asterisk as it
 * arrives. Every upload is re-encoded to the one format the dialplan can count
 * on: 8kHz, 16-bit, mono, signed PCM in a WAV container, which is Asterisk's
 * native `wav` and the same shape MixMonitor already writes.
 *
 * 8kHz sounds thin next to the original, and that is the right trade: a phone
 * call is narrowband anyway, so anything above 4kHz would be thrown away at the
 * handset. Converting once here beats making Asterisk transcode on every call.
 *
 * Re-encoding is also what makes an upload safe. The stored file is produced by
 * ffmpeg from the decoded audio, so a file that merely claims to be audio never
 * reaches the disk, and nothing user-supplied is ever handed to a shell.
 *
 * Two kinds of audio need exactly this, and differ only in where the files
 * live, how long they may be and what to call one when a parent has to be told
 * off: the joke line (JokeStore) and the message a caller who isn't on the list
 * hears (RefusalStore). Both subclass this, so the conversion lives in one
 * place. Files are named by the hash of their own contents, which is what lets
 * the dialplan play a path nobody can guess from anything they typed.
 */
abstract class AudioStore
{
    /** What Asterisk plays without transcoding. */
    private const SAMPLE_RATE = 8000;
    private const CHANNELS = 1;

    private const MAX_UPLOAD_BYTES = 40 * 1024 * 1024;

    /** Below this there is nothing in there but a click. */
    private const MIN_SECONDS = 0.4;

    /** Where this kind of audio is kept. */
    abstract public function path(): string;

    /** Longest clip this store will accept, in seconds. */
    abstract protected function maxSeconds(): int;

    /** What one is called, for the messages a parent reads. */
    abstract protected function noun(): string;

    /**
     * Said when the directory isn't there to write into.
     *
     * Overridden where the cause is worth naming: a missing volume is a
     * deployment problem, and telling a parent to "try again" would be a lie.
     */
    protected function nowhereToSave(): string
    {
        return "Couldn't save that clip.";
    }

    /**
     * Convert an uploaded file and store it.
     *
     * @param  array $upload one entry from $_FILES
     * @return array{file:?string,seconds:int,sha256:?string,error:?string}
     */
    public function store(array $upload): array
    {
        $fail = static fn(string $why): array => ['file' => null, 'seconds' => 0, 'sha256' => null, 'error' => $why];
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return $fail('Choose an audio file first.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return $fail('That file is too big — try one under 40MB.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            return $fail("That file didn't upload properly. Try again.");
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            return $fail('That upload was not accepted.');
        }
        if (filesize($tmp) > self::MAX_UPLOAD_BYTES) {
            return $fail('That file is too big — try one under 40MB.');
        }

        return $this->convert($tmp, (string) ($upload['name'] ?? ''));
    }

    /**
     * Convert any audio file on disk into a stored clip.
     *
     * Separate from store() so an importer can use it on files that did not
     * arrive over HTTP.
     *
     * @return array{file:?string,seconds:int,sha256:?string,error:?string}
     */
    public function convert(string $source, string $originalName = ''): array
    {
        $fail = static fn(string $why): array => ['file' => null, 'seconds' => 0, 'sha256' => null, 'error' => $why];

        if (!is_readable($source)) {
            return $fail("That file couldn't be read.");
        }

        $dir = $this->path();

        // A store whose folder is a subfolder of another store's volume is the
        // first thing ever to touch it (QuietMessageStore, inside the refusals
        // volume), so it is made here rather than in a shell step somebody has
        // to remember after an upgrade. A store whose mount really is missing
        // still fails below, where the message names the path.
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return $fail($this->nowhereToSave());
        }

        $seconds = $this->duration($source);
        if ($seconds === null) {
            return $fail("That doesn't look like audio we can play. Try an MP3, M4A, WAV or Opus file.");
        }
        if ($seconds < self::MIN_SECONDS) {
            return $fail($this->tooShort());
        }
        if ($seconds > $this->maxSeconds()) {
            return $fail($this->tooLong());
        }

        $name = bin2hex(random_bytes(16)) . '.wav';
        $target = $dir . '/' . $name;

        // Write to a temporary name and move it into place, so the dialplan can
        // never catch a half-written file mid-call.
        $temp = $target . '.tmp';

        $ok = $this->run([
            'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-i', $source,
            // Take only the first audio stream: a video file dragged in by
            // mistake still yields its soundtrack rather than an error.
            '-map', '0:a:0',
            '-t', (string) $this->maxSeconds(),
            // Even out the loudness. Uploads arrive from wildly different
            // sources — a studio TTS voice and a phone held at arm's length —
            // and on a handset the quiet ones are simply inaudible.
            '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11,aresample=' . self::SAMPLE_RATE,
            '-ac', (string) self::CHANNELS,
            '-ar', (string) self::SAMPLE_RATE,
            '-c:a', 'pcm_s16le',
            '-f', 'wav',
            '-y', $temp,
        ]);

        if (!$ok || !is_file($temp) || filesize($temp) < 1024) {
            @unlink($temp);

            return $fail("That file couldn't be converted. Try an MP3, M4A, WAV or Opus file.");
        }

        if (!@rename($temp, $target)) {
            @unlink($temp);

            return $fail($this->nowhereToSave());
        }

        @chmod($target, 0644);

        // Measure the file that will actually be played, not the source:
        // loudnorm and resampling can shift the length very slightly.
        $final = $this->duration($target) ?? $seconds;

        return [
            'file' => $name,
            'seconds' => (int) round($final),
            // Conversion is deterministic, so this identifies the audio no
            // matter what format or filename it arrived as.
            'sha256' => hash_file('sha256', $target) ?: null,
            'error' => null,
        ];
    }

    /** Too short to be anything, said in this store's own words. */
    protected function tooShort(): string
    {
        return 'That clip is too short to be a ' . $this->noun() . '.';
    }

    /** Too long to accept, said in this store's own words. */
    protected function tooLong(): string
    {
        return 'That clip is longer than ' . $this->maxSeconds() . ' seconds — trim it down first.';
    }

    /** Length in seconds, or null if this isn't decodable audio. */
    private function duration(string $file): ?float
    {
        $output = [];
        $ok = $this->run([
            'ffprobe', '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $file,
        ], $output);

        if (!$ok) {
            return null;
        }

        $value = trim(implode('', $output));
        if (is_numeric($value)) {
            return (float) $value;
        }

        // A stream with no duration in its header reports "N/A" — which is
        // what a browser's own recorder writes (WebM from MediaRecorder is
        // streamed, so the length is never filled in). Decode it and see how
        // far it gets instead.
        $output = [];
        $ok = $this->run([
            'ffmpeg', '-nostdin', '-v', 'error', '-i', $file,
            '-map', '0:a:0', '-f', 'null', '-progress', 'pipe:1', '-',
        ], $output);
        $micros = null;
        foreach ($output as $line) {
            if (preg_match('/^out_time_us=(\d+)$/', trim($line), $m)) {
                $micros = (int) $m[1];
            }
        }

        return $ok && $micros !== null && $micros > 0 ? $micros / 1e6 : null;
    }

    /**
     * Run a command with its arguments kept apart from the shell.
     *
     * @param  array<int,string> $command
     * @param  array<int,string> $output
     */
    private function run(array $command, array &$output = []): bool
    {
        $escaped = implode(' ', array_map('escapeshellarg', $command));

        $status = 1;
        @exec($escaped . ' 2>/dev/null', $output, $status);

        return $status === 0;
    }

    /** Absolute path of a stored clip, or null if it isn't there. */
    /**
     * Which of $names (keyed by whatever they belong to) holds the same audio
     * as the one just converted — its sha256 — or null. For "that's the same
     * recording as Dinner": picking the wrong file is easy, and silent.
     *
     * @param array<int|string,string> $names owner => stored filename
     */
    public function sameAs(?string $sha256, array $names): int|string|null
    {
        if ($sha256 === null || $sha256 === '') {
            return null;
        }
        foreach ($names as $owner => $name) {
            $path = $this->file($name);
            if ($path !== null && hash_file('sha256', $path) === $sha256) {
                return $owner;
            }
        }

        return null;
    }

    public function file(?string $name): ?string
    {
        $name = trim((string) $name);

        // Names are generated here, so anything else is stale or someone
        // fishing for a path traversal.
        if ($name === '' || !preg_match('/^[a-f0-9]{32}\.wav$/', $name)) {
            return null;
        }

        $path = $this->path() . '/' . $name;

        return is_readable($path) ? $path : null;
    }

    /**
     * Path as Asterisk should play it: absolute, and without the extension,
     * which is how Playback() names a sound file.
     */
    public function playbackPath(string $name): ?string
    {
        if ($this->file($name) === null) {
            return null;
        }

        return $this->path() . '/' . preg_replace('/\.wav$/', '', $name);
    }

    public function delete(?string $name): void
    {
        $file = $this->file($name);
        if ($file !== null) {
            @unlink($file);
        }
    }

    /** True if the conversion tools are actually present. */
    public function isAvailable(): bool
    {
        return $this->run(['ffmpeg', '-version']);
    }
}
