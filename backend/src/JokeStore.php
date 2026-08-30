<?php
declare(strict_types=1);

/**
 * Audio for the joke line.
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
 */
final class JokeStore
{
    /** What Asterisk plays without transcoding. */
    private const SAMPLE_RATE = 8000;
    private const CHANNELS = 1;

    private const MAX_UPLOAD_BYTES = 40 * 1024 * 1024;

    /**
     * A joke is a few seconds. The cap is generous enough for a shaggy-dog
     * story and mean enough that nobody parks an audiobook on the line.
     */
    private const MAX_SECONDS = 90;

    /** Below this there is no joke in there, just a click. */
    private const MIN_SECONDS = 0.4;

    public function path(): string
    {
        return rtrim(getenv('JOKES_PATH') ?: '/var/lib/twocans/jokes', '/');
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
     * Convert any audio file on disk into a stored joke.
     *
     * Separate from store() so the importer can use it on files that did not
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

        $seconds = $this->duration($source);
        if ($seconds === null) {
            return $fail("That doesn't look like audio we can play. Try an MP3, M4A, WAV or Opus file.");
        }
        if ($seconds < self::MIN_SECONDS) {
            return $fail('That clip is too short to be a joke.');
        }
        if ($seconds > self::MAX_SECONDS) {
            return $fail('That clip is longer than ' . self::MAX_SECONDS . ' seconds — trim it down first.');
        }

        $name = bin2hex(random_bytes(16)) . '.wav';
        $target = $this->path() . '/' . $name;

        // Write to a temporary name and move it into place, so the dialplan can
        // never catch a half-written file mid-call.
        $temp = $target . '.tmp';

        $ok = $this->run([
            'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-i', $source,
            // Take only the first audio stream: a video file dragged in by
            // mistake still yields its soundtrack rather than an error.
            '-map', '0:a:0',
            '-t', (string) self::MAX_SECONDS,
            // Even out the loudness. Jokes arrive from wildly different sources
            // — a studio TTS voice and a phone held at arm's length — and on a
            // handset the quiet ones are simply inaudible.
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

            return $fail("Couldn't save that clip.");
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

        // A stream with no duration in its header reports "N/A".
        return is_numeric($value) ? (float) $value : null;
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

    /** Absolute path of a stored joke, or null if it isn't there. */
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
