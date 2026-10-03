<?php
declare(strict_types=1);

/**
 * Keepsakes: a kept voicemail is a copy of its own, named sensibly, that
 * stays when the message goes — against the database, in a transaction
 * rolled back after, and folders in the temp directory.
 */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $tmp = sys_get_temp_dir() . '/tc-keepsakes-test-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/spool/twocans/201/INBOX', 0777, true);
    $before = [getenv('VOICEMAIL_PATH'), getenv('KEEPSAKES_PATH')];
    putenv('VOICEMAIL_PATH=' . $tmp . '/spool');
    putenv('KEEPSAKES_PATH=' . $tmp . '/keepsakes');
    $pdo->beginTransaction();
    try {
        $fn($pdo, $tmp);
    } finally {
        $pdo->rollBack();
        putenv($before[0] === false ? 'VOICEMAIL_PATH' : 'VOICEMAIL_PATH=' . $before[0]);
        putenv($before[1] === false ? 'KEEPSAKES_PATH' : 'KEEPSAKES_PATH=' . $before[1]);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
};

$message = static function (PDO $pdo, string $tmp, string $msgId): int {
    $audio = $tmp . '/spool/twocans/201/INBOX/msg0000.wav';
    file_put_contents($audio, 'RIFF-not-really-audio');
    $pdo->prepare(
        'INSERT INTO voicemails (msg_id, mailbox, folder, peer_name, peer_number, left_at, duration_secs, heard,
                                 audio_path, transcript, transcript_status)
         VALUES (?, "201", "INBOX", "Grandad", "+447700900123", "2026-03-04 19:05:00", 12, 0, ?, "Goodnight, sleep tight", "done")'
    )->execute([$msgId, $audio]);

    return (int) $pdo->lastInsertId();
};

return [
    test('keeping a message copies it, once, and the copy outlives the message', function () use ($fresh, $message) {
        $fresh(function (PDO $pdo, string $tmp) use ($message) {
            $id = $message($pdo, $tmp, 'test-keep-1');
            $keepsakes = new Keepsakes();
            $kept = $keepsakes->keep($id);
            assertTrue($kept['ok'], (string) $kept['error']);
            assertSame($kept['id'], $keepsakes->keep($id)['id'], 'kept twice is still one');

            unlink($tmp . '/spool/twocans/201/INBOX/msg0000.wav');
            $row = $keepsakes->find((int) $kept['id']);
            assertTrue($keepsakes->audioFile($row) !== null, 'the copy is still there');
            assertSame((int) $kept['id'], $keepsakes->keptMessages()['test-keep-1'] ?? null);

            $k = Keepsakes::toView($row);
            assertSame('Grandad', $k['from']);
            assertSame('Goodnight, sleep tight', $k['transcript']);
            assertSame(2026, $k['year']);
        });
    }),
    test('a keepsake is named by date, who and what it\'s called', function () use ($fresh, $message) {
        $fresh(function (PDO $pdo, string $tmp) use ($message) {
            $keepsakes = new Keepsakes();
            $kept = $keepsakes->keep($message($pdo, $tmp, 'test-keep-2'));
            $keepsakes->rename((int) $kept['id'], 'First goodnight / ever?');
            $k = Keepsakes::toView($keepsakes->find((int) $kept['id']));
            assertSame('2026-03-04 Grandad - First goodnight  ever', Keepsakes::fileName($k));
        });
    }),
    test('a year downloads as a zip of its recordings and what they say', function () use ($fresh, $message) {
        $fresh(function (PDO $pdo, string $tmp) use ($message) {
            $keepsakes = new Keepsakes();
            $keepsakes->keep($message($pdo, $tmp, 'test-keep-3'));
            $zip = $keepsakes->zipYear(2026);
            assertTrue($zip !== null && is_file($zip));
            $archive = new PharData($zip);
            $names = [];
            foreach (new RecursiveIteratorIterator($archive) as $file) {
                $names[] = $file->getFilename();
            }
            @unlink($zip);
            assertTrue(in_array('keepsakes.txt', $names, true));
            assertTrue(in_array('2026-03-04 Grandad.wav', $names, true));
            assertSame(null, $keepsakes->zipYear(1999));
        });
    }),
    test('removing a keepsake deletes its copy, not the message', function () use ($fresh, $message) {
        $fresh(function (PDO $pdo, string $tmp) use ($message) {
            $id = $message($pdo, $tmp, 'test-keep-4');
            $keepsakes = new Keepsakes();
            $kept = $keepsakes->keep($id);
            $file = $keepsakes->audioFile($keepsakes->find((int) $kept['id']));
            assertTrue($keepsakes->remove((int) $kept['id']));
            assertFalse(is_file((string) $file));
            assertTrue(is_file($tmp . '/spool/twocans/201/INBOX/msg0000.wav'));
            assertTrue((new VoicemailRepository())->find($id) !== null);
        });
    }),
];
