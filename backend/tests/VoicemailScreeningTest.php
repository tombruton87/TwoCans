<?php
declare(strict_types=1);

/**
 * What the dashboard reads off a message left by a number nobody recognises:
 * the verdict a grown-up has recorded, and the shape a row takes while it is
 * still waiting on one.
 *
 * The repository's own queries need a database, so this covers the half that is
 * pure — the mapping every screening card and the voicemail list are built from.
 */

/** A message as the spool import leaves it, before anyone has decided. */
$unrecognised = static function (array $overrides = []): array {
    return $overrides + [
        'id' => '7',
        'contact_id' => null,
        'peer_name' => 'Unknown number',
        'peer_number' => '+447700900123',
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
    test('a message nobody has ruled on carries no resolution', function () use ($unrecognised) {
        $v = VoicemailRepository::toView($unrecognised([
            'resolution' => null,
            'resolved_by' => null,
            'resolved_at' => null,
        ]));

        assertSame('', $v['resolution']);
        assertSame(0, $v['resolvedBy']);
        assertSame(0, $v['resolvedAt']);
        // What the screening card shows: who rang, and for how long.
        assertSame('+447700900123', $v['number']);
        assertSame('0:22', $v['dur']);
    }),
    test('a row read before migration 031 reads as unresolved, not as an error', function () use ($unrecognised) {
        // An older query — or a row cached from one — simply has no such keys.
        $v = VoicemailRepository::toView($unrecognised());

        assertSame('', $v['resolution']);
        assertSame(0, $v['resolvedBy']);
        assertSame(0, $v['resolvedAt']);
    }),
    test('an approved message remembers who put the number on the list', function () use ($unrecognised) {
        $v = VoicemailRepository::toView($unrecognised([
            'resolution' => 'approved',
            'resolved_by' => '3',
            'resolved_at' => '2026-02-03 15:00:00',
        ]));

        assertSame('approved', $v['resolution']);
        assertSame(3, $v['resolvedBy']);
        assertSame((int) strtotime('2026-02-03 15:00:00'), $v['resolvedAt']);
    }),
    test('a junked message still reads back as one', function () use ($unrecognised) {
        $v = VoicemailRepository::toView($unrecognised([
            'resolution' => 'junk',
            'resolved_by' => '3',
            'resolved_at' => '2026-02-03 15:01:00',
        ]));

        assertSame('junk', $v['resolution']);
    }),
];
