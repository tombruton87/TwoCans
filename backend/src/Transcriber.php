<?php
declare(strict_types=1);

/**
 * Speech-to-text for call recordings, via a Whisper service on this box.
 *
 * The audio is a child's phone call, so it never leaves the machine: the
 * whisper container publishes no ports and is reachable only on the internal
 * compose network. That is the whole reason for running our own model rather
 * than posting the audio to a cloud API.
 */
final class Transcriber
{
    /** Give up after this many tries so one bad file can't spin forever. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Whisper is slow on CPU — a few seconds of audio can take a few seconds,
     * and a long call proportionally longer. Generous, because the worker runs
     * in the background and nothing is waiting on it.
     */
    private const TIMEOUT_SECONDS = 900;

    public function endpoint(): string
    {
        return rtrim(getenv('WHISPER_URL') ?: 'http://whisper:9000', '/');
    }

    public function language(): string
    {
        return getenv('WHISPER_LANGUAGE') ?: 'en';
    }

    /** True if the service is up and answering. */
    public function isAvailable(): bool
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 5, 'ignore_errors' => true,
        ]]);

        return @file_get_contents($this->endpoint() . '/health', false, $ctx) !== false;
    }

    /**
     * Transcribe one audio file.
     *
     * @return array{ok:bool,text:string,error:?string}
     */
    public function transcribe(string $file): array
    {
        if (!is_readable($file)) {
            return ['ok' => false, 'text' => '', 'error' => 'recording is missing'];
        }
        if (filesize($file) < 1024) {
            // A file this small holds no speech; treat it as nothing to do
            // rather than a failure worth retrying.
            return ['ok' => false, 'text' => '', 'error' => 'recording is empty'];
        }

        // Both ends of this are ours, so the audio goes up as the raw request
        // body — no multipart envelope to build here or parse at the far end.
        $body = (string) file_get_contents($file);

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: audio/wav\r\nContent-Length: " . strlen($body) . "\r\n",
            'content' => $body,
            'timeout' => self::TIMEOUT_SECONDS,
            'ignore_errors' => true,
        ]]);

        $url = $this->endpoint() . '/asr';

        $response = @file_get_contents($url, false, $ctx);
        $status = $this->statusFrom($http_response_header ?? []);

        if ($response === false) {
            return ['ok' => false, 'text' => '', 'error' => 'could not reach the transcription service'];
        }
        if ($status !== 200) {
            return ['ok' => false, 'text' => '', 'error' => 'transcription service returned HTTP ' . $status];
        }

        return ['ok' => true, 'text' => $this->tidy($response), 'error' => null];
    }


    private function statusFrom(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * Whisper pads short clips with its own filler. VAD in the service removes
     * most of it; this catches what slips through, so a silent recording reads
     * as empty rather than as a hallucinated sentence.
     */
    private function tidy(string $text): string
    {
        $text = trim($text);

        $noise = ['you', 'thank you.', 'thanks for watching!', 'bye.', '.', 'the'];
        if (in_array(mb_strtolower($text), $noise, true)) {
            return '';
        }

        return $text;
    }

    /** Model name reported alongside the transcript, for the record. */
    public function engine(): string
    {
        return 'whisper/' . (getenv('WHISPER_MODEL') ?: 'base');
    }
}
