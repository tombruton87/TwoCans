<?php
declare(strict_types=1);

/**
 * This week: Monday to Sunday, talk time read the way a grown-up reads it,
 * and only people counted as who a phone talked to — against the database,
 * in a transaction rolled back after.
 */

return [
    test('a week is Monday to Sunday, and names itself as an ISO week', function () {
        $tz = new DateTimeZone('Europe/London');
        $monday = Week::start(new DateTimeImmutable('2026-10-04 21:00', $tz)); // a Sunday
        assertSame('2026-09-28 00:00', $monday->format('Y-m-d H:i'));
        assertSame('2026-W40', Week::param($monday));
        assertSame('2026-09-28', Week::fromParam('2026-W40', new DateTimeImmutable('2026-12-01', $tz))->format('Y-m-d'));
        assertSame('2026-11-30', Week::fromParam('nonsense', new DateTimeImmutable('2026-12-01', $tz))->format('Y-m-d'));
    }),
    test('talk time is minutes and hours', function () {
        assertSame('45s', Week::talk(45));
        assertSame('12m', Week::talk(12 * 60 + 5));
        assertSame('1h 20m', Week::talk(80 * 60));
    }),
    test('a phone\'s week: its calls, and only people as who it talked to', function () {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $devices = new DeviceRepository();
            $kid = $devices->create('Test bedroom', 'ghp621', 'udp');
            $other = $devices->create('Test kitchen', 'ghp621', 'udp');
            $pdo->prepare('INSERT INTO contacts (name, number_e164, allow_in, allow_out) VALUES ("Test Granny", "+447700900111", 1, 1)')->execute();
            $granny = (int) $pdo->lastInsertId();
            $at = '2026-09-29 17:00:00';
            $call = $pdo->prepare('INSERT INTO calls (uniqueid, device_id, contact_id, peer_name, peer_number, dialled, direction, status, started_at, billsec)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $n = 0;
            $add = static function (?int $contact, string $name, string $number, string $dir, string $status, int $secs) use ($call, $kid, $at, &$n) {
                $call->execute(['weektest.' . ++$n, (int) $kid['id'], $contact, $name, $number, $number, $dir, $status, $at, $secs]);
            };
            $add($granny, 'Test Granny', '+447700900111', 'out', 'done', 300);
            $add($granny, 'Test Granny', '+447700900111', 'in', 'done', 120);
            $add(null, 'Test bedroom', (string) $other['extension'], 'out', 'done', 30);  // the house's other phone
            $add(null, 'The joke line', (new SettingsRepository())->jokeNumber(), 'out', 'done', 20); // not a person
            $add(null, 'Test bedroom', '2', 'out', 'done', 5);                            // a key in a menu
            $add(null, 'Unknown', '+447700900999', 'in', 'missed', 0);
            $add(null, 'Not allowed', '+447700900888', 'out', 'blocked', 0);

            $week = (new Week())->summary(Week::start(new DateTimeImmutable($at)));
            $mine = array_values(array_filter($week['phones'], static fn(array $p): bool => $p['id'] === (int) $kid['id']))[0];
            assertSame(4, $mine['callsOut']);
            assertSame(1, $mine['callsIn']);
            assertSame(1, $mine['missed']);
            assertSame(1, $mine['blocked']);
            assertSame(475, $mine['seconds']);
            assertSame(['Test Granny', 'Test kitchen'], array_column($mine['people'], 'name'));
            assertSame(2, $mine['people'][0]['calls']);
        } finally {
            $pdo->rollBack();
        }
    }),
];
