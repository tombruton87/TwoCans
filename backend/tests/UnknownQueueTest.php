<?php
declare(strict_types=1);

/**
 * The cards for numbers nobody recognises — messages on the dashboard, the
 * numbers a child tried on the call log.
 *
 * An ask from a child and a message from an unknown caller have to come out of
 * UnknownQueue looking like the same kind of thing — same buttons, same order —
 * while each row keeps what its own source knows: the child's voice note and the
 * count of tries on an ask, the words, the length and the mailbox on a message.
 *
 * The methods that talk to the database are not exercised here; this covers the
 * mapping and the merge, which is where two shapes are made to agree.
 */

/** An ask as the ask queue leaves it, once the voice note has been matched. */
$ask = static function (array $overrides = []): array {
    return $overrides + [
        'id' => '3',
        'number_e164' => '+447700900123',
        'device_id' => '1',
        'requested_at' => '2026-02-03 16:12:00',
        'last_asked_at' => '2026-02-03 16:12:00',
        'attempts' => '1',
        'resolution' => null,
        'label' => 'Nana',
        'transcript_status' => 'done',
        'recording_path' => '/var/spool/asterisk/asks/1699.1.wav',
    ];
};

/** A message left in the house mailbox by a number nobody recognises. */
$message = static function (array $overrides = []): array {
    return $overrides + [
        'id' => '7',
        'contact_id' => null,
        'peer_name' => 'Unknown number',
        'peer_number' => '+447700900456',
        'left_at' => '2026-02-03 14:05:00',
        'duration_secs' => '22',
        'heard' => '0',
        'mailbox' => '100',
        'audio_path' => '/var/spool/asterisk/voicemail/twocans/100/INBOX/msg0000.wav',
        'transcript' => 'Hello, is that the Wilsons?',
        'transcript_status' => 'done',
    ];
};

return [
    test('an ask and a message offer the same two buttons', function () use ($ask, $message) {
        $a = UnknownQueue::fromAsk($ask(), 1);
        $m = UnknownQueue::fromMessage($message());

        assertSame($a['approve']['label'], $m['approve']['label'], 'approve should read the same');
        assertSame($a['dismiss']['label'], $m['dismiss']['label'], 'dismiss should read the same');
        assertSame('Add them', $m['approve']['label']);
        assertSame('Not now', $m['dismiss']['label']);
    }),

    test('each button still posts the handler for its own kind', function () use ($ask, $message) {
        $a = UnknownQueue::fromAsk($ask(), 1);
        $m = UnknownQueue::fromMessage($message());

        assertSame('request_approve', $a['approve']['action']);
        assertSame('request_deny', $a['dismiss']['action']);
        assertSame('screening_allow', $m['approve']['action']);
        assertSame('screening_junk', $m['dismiss']['action']);
    }),

    test('a row says which way the number came at the house', function () use ($ask, $message) {
        $a = UnknownQueue::fromAsk($ask(), 1);
        $m = UnknownQueue::fromMessage($message());

        assertSame(UnknownQueue::KIND_ASK, $a['kind']);
        assertSame('Wants to call', $a['tag']);
        assertSame(UnknownQueue::KIND_MESSAGE, $m['kind']);
        assertSame('Rang us', $m['tag']);
    }),

    test("an ask offers the child's own words as a guess at a name", function () use ($ask) {
        $a = UnknownQueue::fromAsk($ask(), 3);

        assertSame('Maybe “Nana”?', $a['headline']);
        assertFalse($a['headlineLive'], 'a finished transcript is not still listening');
        assertContains('Tried 3 times', $a['note']);
    }),

    test('an ask tried once says so in the singular', function () use ($ask) {
        assertContains('Tried once', UnknownQueue::fromAsk($ask(), 1)['note']);
    }),

    test('an ask nobody has transcribed yet reads as still listening', function () use ($ask) {
        $a = UnknownQueue::fromAsk($ask(['label' => '', 'transcript_status' => 'pending']), 1);

        assertSame('Listening to what they said…', $a['headline']);
        assertTrue($a['headlineLive'], 'the listening line should carry the pulse');
    }),

    test('an ask with no voice note offers no player', function () use ($ask) {
        $a = UnknownQueue::fromAsk($ask(['recording_path' => null]), 1);

        assertNull($a['clip'], 'no clip for a child who said nothing');
        assertFalse($a['showTranscript'], 'an ask has nothing to quote');
    }),

    test("the child's voice note plays through the ask route", function () use ($ask) {
        $clip = UnknownQueue::fromAsk($ask(), 1)['clip'];

        assertSame('ask_audio', $clip['route']);
        assertSame(3, $clip['id']);
        assertSame('Hear them ask', $clip['label']);
    }),

    test('a message keeps its words, its length and where it landed', function () use ($message) {
        $m = UnknownQueue::fromMessage($message());

        assertTrue($m['showTranscript']);
        assertSame('Hello, is that the Wilsons?', $m['transcript']);
        assertSame('done', $m['transcriptStatus']);
        assertSame('', $m['headline'], 'a message is quoted, not guessed at');
        assertContains('0:22 · left in the house mailbox', $m['note']);
    }),

    test('a message plays from the mailbox spool, not the ask spool', function () use ($message) {
        $clip = UnknownQueue::fromMessage($message())['clip'];

        assertSame('voicemail_audio', $clip['route']);
        assertSame(7, $clip['id']);
        assertSame('Hear the message', $clip['label']);
    }),

    test('a message that could not be transcribed still shows the block', function () use ($message) {
        $m = UnknownQueue::fromMessage($message(['transcript' => '', 'transcript_status' => 'failed']));

        assertTrue($m['showTranscript']);
        assertSame('failed', $m['transcriptStatus']);
        assertSame('', $m['transcript']);
    }),

    test('both kinds are listed newest first', function () {
        $rows = UnknownQueue::mix([
            ['id' => 1, 'kind' => UnknownQueue::KIND_ASK, 'at' => 100],
            ['id' => 2, 'kind' => UnknownQueue::KIND_MESSAGE, 'at' => 300],
            ['id' => 3, 'kind' => UnknownQueue::KIND_ASK, 'at' => 200],
        ], 10);

        assertSame([2, 3, 1], array_column($rows, 'id'));
    }),

    test('a row with no usable timestamp falls to the bottom', function () {
        $rows = UnknownQueue::mix([
            ['id' => 1, 'at' => 0],
            ['id' => 2, 'at' => 50],
        ], 10);

        assertSame([2, 1], array_column($rows, 'id'));
    }),

    test('a number already turned down can still be added, but not turned down again', function () use ($ask) {
        $a = UnknownQueue::fromAsk($ask(['resolution' => 'denied']), 2);

        assertSame('request_approve', $a['approve']['action']);
        assertSame(null, $a['dismiss']);
        assertContains('you said not now', $a['note']);
    }),

    test('a number still waiting offers both buttons', function () use ($ask) {
        assertSame('request_deny', UnknownQueue::fromAsk($ask(), 1)['dismiss']['action']);
    }),

    test("the list stops at a card's worth", function () {
        $rows = UnknownQueue::mix([
            ['id' => 1, 'at' => 10],
            ['id' => 2, 'at' => 30],
            ['id' => 3, 'at' => 20],
        ], 2);

        assertSame([2, 3], array_column($rows, 'id'));
    }),
];
