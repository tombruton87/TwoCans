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
 * Calls, voicemail, jokes and the phones' refusal messages all transcribe
 * identically — same engine, same retry rules — so the queue is described once
 * and walked over rather than duplicated.
 *
 * An entry says where its audio is (a bare filename needs `prefix`, because the
 * directory it lives in comes from the environment) and what its bookkeeping
 * columns are called. Most tables carry the `transcript_*` names from the
 * baseline schema; the two that are about something other than a call spell
 * them out, and a null means the table has no such column at all.
 */
$queues = [
    ['table' => 'calls', 'audio' => 'recording_path', 'label' => 'call'],
    // Voicemail predates the engine and timestamp columns; there is nowhere to
    // record which model did the work, so nothing is stamped.
    ['table' => 'voicemails', 'audio' => 'audio_path', 'label' => 'message',
     'engine' => null, 'stamp' => null],
    ['table' => 'jokes', 'audio' => 'audio_file', 'label' => 'joke',
     'prefix' => (new JokeStore())->path() . '/'],
    // "Who were you trying to call?" — a few words, so this is quick. The
    // baseline schema calls the text `label`, hence `column`.
    ['table' => 'call_requests', 'audio' => 'recording_path', 'label' => 'ask',
     'column' => 'label'],
    // The message a caller who isn't on the list hears, recorded on the phone's
    // page. One row per phone, so its columns are named after the phone's
    // message rather than after the work.
    ['table' => 'devices', 'audio' => 'refusal_audio', 'label' => 'refusal message',
     'prefix' => (new RefusalStore())->path() . '/', 'column' => 'refusal_transcript',
     'status' => 'refusal_status', 'attempts' => 'refusal_attempts',
     'engine' => 'refusal_engine', 'error' => 'refusal_error',
     'stamp' => 'refusal_transcribed_at'],
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
            // Fill in the column names this table doesn't spell out, so the walk
            // below never has to ask again.
            $q = $queue + [
                'prefix' => '',
                'column' => 'transcript',
                'status' => 'transcript_status',
                'attempts' => 'transcript_attempts',
                'engine' => 'transcript_engine',
                'error' => 'transcript_error',
                'stamp' => 'transcribed_at',
            ];

            $pending = Database::pdo()->prepare(
                'SELECT id, ' . $q['audio'] . ' AS audio
                   FROM ' . $q['table'] . '
                  WHERE ' . $q['status'] . ' = "pending"
                    AND ' . $q['audio'] . ' IS NOT NULL
                    AND ' . $q['attempts'] . ' < :max
               ORDER BY id DESC
                  LIMIT 5'
            );
            $pending->execute(['max' => Transcriber::MAX_ATTEMPTS]);
            foreach ($pending->fetchAll() as $row) {
                $rows[] = $row + [
                    'table' => $q['table'],
                    'label' => $q['label'],
                    'prefix' => $q['prefix'],
                    'column' => $q['column'],
                    'status' => $q['status'],
                    'attempts' => $q['attempts'],
                    'engine' => $q['engine'],
                    'error' => $q['error'],
                    'stamp' => $q['stamp'],
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
            $statusColumn = $row['status'];
            $attemptsColumn = $row['attempts'];
            $errorColumn = $row['error'];
            $engineColumn = $row['engine'];
            $stampColumn = $row['stamp'];
            $id = (int) $row['id'];

            // Claim it first, so a second worker skips it.
            Database::pdo()->prepare(
                "UPDATE {$table} SET {$statusColumn} = 'running', {$attemptsColumn} = {$attemptsColumn} + 1
                  WHERE id = ? AND {$statusColumn} = 'pending'"
            )->execute([$id]);

            $file = (string) $row['prefix'] . (string) $row['audio'];
            $started = microtime(true);
            $result = $transcriber->transcribe($file);
            $seconds = round(microtime(true) - $started, 1);

            if ($result['ok']) {
                // Which model produced the text, where the table has room to
                // record it: voicemail has neither column.
                $extra = $engineColumn === null || $stampColumn === null
                    ? ''
                    : ", {$engineColumn} = '" . $transcriber->engine() . "', {$stampColumn} = NOW()";
                $clearError = $errorColumn === null ? '' : ", {$errorColumn} = NULL";

                Database::pdo()->prepare(
                    "UPDATE {$table} SET {$column} = :text, {$statusColumn} = 'done'{$clearError}{$extra}
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
                    "UPDATE {$table} SET {$statusColumn} = 'pending',
                            {$attemptsColumn} = GREATEST({$attemptsColumn} - 1, 0),
                            {$errorColumn} = :error
                      WHERE id = :id"
                )->execute(['error' => $result['error'], 'id' => $id]);

                $log("transcription service is not answering — leaving the queue alone");
                break;
            }

            // A missing or empty recording will never succeed — stop trying.
            $permanent = str_contains((string) $result['error'], 'missing')
                      || str_contains((string) $result['error'], 'empty');

            $attempts = (int) Database::pdo()
                ->query("SELECT {$attemptsColumn} FROM {$table} WHERE id = " . $id)
                ->fetchColumn();

            $status = $permanent ? 'skipped'
                : ($attempts >= Transcriber::MAX_ATTEMPTS ? 'failed' : 'pending');

            Database::pdo()->prepare(
                "UPDATE {$table} SET {$statusColumn} = :status, {$errorColumn} = :error WHERE id = :id"
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
