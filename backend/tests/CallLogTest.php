<?php
declare(strict_types=1);

/** The call log: what it shows, and how. */

$row = static fn(array $over): array => $over + [
    'id' => 1, 'uniqueid' => 'u1', 'peer_name' => 'Unknown number', 'peer_number' => '123', 'contact_id' => null,
    'dialled' => '123', 'direction' => 'out', 'status' => 'blocked', 'started_at' => '2026-09-27 10:00:00',
    'billsec' => 0, 'duration_secs' => 0,
];

return [
    test('announcements and test calls are left out of the log', function () {
        $sql = CallRepository::shownSql('c');
        foreach (['announce', '929', '600', '601'] as $n) {
            assertContains("'" . $n . "'", $sql);
        }
        assertContains('c.peer_number NOT IN', $sql);
    }),
    test('a short number nobody answers to reads as a mis-dial', function () use ($row) {
        $v = CallRepository::toView($row([]));
        assertSame('Dialled 123', $v['name']);
        assertTrue($v['misdial']);
    }),
    test('a speed dial to a person, a phone or a real number is not a mis-dial', function () use ($row) {
        assertFalse(CallRepository::toView($row(['peer_name' => 'Tom', 'contact_id' => 4]))['misdial']);
        assertFalse(CallRepository::toView($row(['peer_name' => 'Hall phone', 'peer_number' => '201']))['misdial']);
        assertFalse(CallRepository::toView($row(['peer_number' => '+447700900123']))['misdial']);
    }),
];
