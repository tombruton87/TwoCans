<?php
declare(strict_types=1);

/**
 * Transcribe call recordings that don't have a transcript yet.
 *
 *   docker exec twocans-php php /var/www/html/bin/transcribe.php          # one pass
 *   docker exec twocans-php php /var/www/html/bin/transcribe.php --watch  # keep going
 *
 * Runs as its own worker container so a slow transcription never holds up a web
 * request. Safe to run twice: a row is claimed by flipping it to `running`
 * before any work starts, so two workers won't both take the same call.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$watch = in_array('--watch', $argv, true);
$once = in_array('--once', $argv, true) || !$watch;
$interval = 20;

$transcriber = new Transcriber();
$calls = new CallRepository();
$voicemails = new VoicemailRepository();

/**
 * Calls, voicemail and jokes transcribe identically — same engine, same retry
 * rules — so the queue is described once and walked over rather than duplicated.
 *
 * Jokes store a bare filename rather than a path, because the directory they
 * live in comes from the environment, hence `prefix`.
 */
$queues = [
    ['table' => 'calls', 'audio' => 'recording_path', 'label' => 'call'],
    ['table' => 'voicemails', 'audio' => 'audio_path', 'label' => 'message'],
    ['table' => 'jokes', 'audio' => 'audio_file', 'label' => 'joke',
     'prefix' => (new JokeStore())->path() . '/'],
    // "Who were you trying to call?" — a few words, so this is quick. The
    // baseline schema calls the text `label`, hence `column`.
    ['table' => 'call_requests', 'audio' => 'recording_path', 'label' => 'ask',
     'column' => 'label'],
];

// Keep going on a database blip rather than dying and restart-looping.
$log = static fn(string $line) => fwrite(STDOUT, date('[H:i:s] ') . $line . "\n");

$log('transcription worker starting — ' . $transcriber->endpoint() . ' (' . $transcriber->engine() . ')');

do {
    try {
        // Pick up anything that arrived since the last look.
        $calls->linkRecordings();
        $voicemails->import();

        $rows = [];
        foreach ($queues as $queue) {
            $pending = Database::pdo()->prepare(
                'SELECT id, ' . $queue['audio'] . ' AS audio
                   FROM ' . $queue['table'] . '
                  WHERE transcript_status = "pending"
                    AND ' . $queue['audio'] . ' IS NOT NULL
                    AND transcript_attempts < :max
               ORDER BY id DESC
                  LIMIT 5'
            );
            $pending->execute(['max' => Transcriber::MAX_ATTEMPTS]);
            foreach ($pending->fetchAll() as $row) {
                $rows[] = $row + [
                    'table' => $queue['table'],
                    'label' => $queue['label'],
                    'prefix' => $queue['prefix'] ?? '',
                    'column' => $queue['column'] ?? 'transcript',
                ];
            }
        }

        if ($rows === [] && $once) {
            $log('nothing to transcribe');
            break;
        }

        foreach ($rows as $row) {
            $table = $row['table'];
            $label = $row['label'];
            $column = $row['column'];
            $id = (int) $row['id'];

            // Claim it first, so a second worker skips it.
            Database::pdo()->prepare(
                "UPDATE {$table} SET transcript_status = 'running', transcript_attempts = transcript_attempts + 1
                  WHERE id = ? AND transcript_status = 'pending'"
            )->execute([$id]);

            $file = (string) $row['prefix'] . (string) $row['audio'];
            $started = microtime(true);
            $result = $transcriber->transcribe($file);
            $seconds = round(microtime(true) - $started, 1);

            if ($result['ok']) {
                // `voicemails` is the one table without the engine/timestamp
                // columns; the others record which model produced the text.
                $extra = $table === 'voicemails'
                    ? ''
                    : ", transcript_engine = '" . $transcriber->engine() . "', transcribed_at = NOW()";

                Database::pdo()->prepare(
                    "UPDATE {$table} SET {$column} = :text, transcript_status = 'done',
                            transcript_error = NULL{$extra}
                      WHERE id = :id"
                )->execute(['text' => $result['text'], 'id' => $id]);

                $words = $result['text'] === '' ? 0 : str_word_count($result['text']);
                $log("{$label} {$id}: transcribed in {$seconds}s ({$words} words)");
                continue;
            }

            /*
             * The service being unreachable is not this recording's fault.
             * Roll the attempt back and stop the batch — otherwise a Whisper
             * restart would burn through every call's retries and mark a whole
             * queue permanently failed for an outage that lasted a minute.
             */
            if (str_contains((string) $result['error'], 'could not reach')) {
                Database::pdo()->prepare(
                    "UPDATE {$table} SET transcript_status = 'pending',
                            transcript_attempts = GREATEST(transcript_attempts - 1, 0),
                            transcript_error = :error
                      WHERE id = :id"
                )->execute(['error' => $result['error'], 'id' => $id]);

                $log("transcription service is not answering — leaving the queue alone");
                break;
            }

            // A missing or empty recording will never succeed — stop trying.
            $permanent = str_contains((string) $result['error'], 'missing')
                      || str_contains((string) $result['error'], 'empty');

            $attempts = (int) Database::pdo()
                ->query("SELECT transcript_attempts FROM {$table} WHERE id = " . $id)
                ->fetchColumn();

            $status = $permanent ? 'skipped'
                : ($attempts >= Transcriber::MAX_ATTEMPTS ? 'failed' : 'pending');

            Database::pdo()->prepare(
                "UPDATE {$table} SET transcript_status = :status, transcript_error = :error WHERE id = :id"
            )->execute(['status' => $status, 'error' => $result['error'], 'id' => $id]);

            $log("{$label} {$id}: {$status} — {$result['error']}");
        }
    } catch (Throwable $e) {
        $log('error: ' . $e->getMessage());
    }

    if ($watch) {
        sleep($interval);
    }
} while ($watch);
