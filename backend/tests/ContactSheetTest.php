<?php
declare(strict_types=1);

/** The printable contact sheet: numbers as written at home, and what's on it. */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        SettingsRepository::forget();
        $fn($pdo);
    } finally {
        $pdo->rollBack();
        SettingsRepository::forget();
    }
};

$labels = static fn(array $services): array => array_column($services, 'label');

return [
    test('a number is written the way it is at home', function () {
        if (ContactRepository::countryCode() !== '44') {
            return;
        }
        assertSame('07700 900123', ContactSheet::friendly('+447700900123'));
        assertSame('+15555550123', ContactSheet::friendly('+15555550123'));
        assertSame('999', ContactSheet::emergencyNumber());
    }),
    test('the emergency number is on the sheet only with a line, and only if wanted', function () use ($fresh, $labels) {
        $fresh(function (PDO $pdo) use ($labels) {
            $pdo->exec('INSERT INTO trunk (id, provider, connected) VALUES (1, "Twilio", 0)
                        ON DUPLICATE KEY UPDATE connected = 0');
            assertFalse(in_array('Emergency', $labels(ContactSheet::services()), true));
            assertFalse(array_key_exists('sos', ContactSheet::available()), "can't be ticked without a line");

            $pdo->exec('UPDATE trunk SET connected = 1 WHERE id = 1');
            $with = ContactSheet::services();
            assertTrue(in_array('Emergency', $labels($with), true));
            assertSame(ContactSheet::emergencyNumber(), end($with)['dial']);
            assertTrue(end($with)['sos']);

            assertFalse(in_array('Emergency', $labels(ContactSheet::services(null, ['messages', 'jokes'])), true));
        });
    }),
    test('every line a child can dial is on offer, ticked to begin with', function () use ($fresh, $labels) {
        $fresh(function (PDO $pdo) use ($labels) {
            $pdo->exec("DELETE FROM settings WHERE name IN ('games_number', 'quiz_number', 'joke_number', 'sleeps_number', 'radio_number', 'clock_number', 'timer_number', 'silly_number', 'walkie_number')");
            $pdo->exec('UPDATE devices SET walkie_device_id = NULL');
            SettingsRepository::forget();
            $settings = new SettingsRepository();
            $available = ContactSheet::available();
            foreach (['messages', 'jokes', 'games', 'times', 'clock', 'timer', 'silly', 'christmas'] as $key) {
                assertTrue(isset($available[$key]), "$key is on offer");
            }
            assertSame($settings->clockNumber(), $available['clock']['dial']);
            assertSame($settings->sillyNumber(), $available['silly']['dial']);
            assertFalse(isset($available['walkie']), 'no phones paired');
            assertFalse(isset($available['house']), "the house's messages need a phone");

            $services = $labels(ContactSheet::services());
            assertSame(['My messages', 'The joke line', 'Games', 'Times tables'], array_slice($services, 0, 4));
            foreach (['What time is it?', 'Kitchen timer', 'Silly voices'] as $label) {
                assertTrue(in_array($label, $services, true), "$label is ticked");
            }
            // The Christmas countdown is ticked in the run-up to it, and can be any time.
            $season = in_array(date('n'), ['11', '12'], true) && Christmas::sleeps(new DateTimeImmutable()) > 0;
            assertSame($season, in_array('Sleeps till Christmas', $services, true));
            assertSame(['Sleeps till Christmas'], $labels(ContactSheet::services(null, ['christmas'])));

            // Only what's chosen, in the sheet's own order; nothing that isn't a line.
            assertSame(['Games', 'Kitchen timer'], $labels(ContactSheet::services(null, ['timer', 'games', 'nonsense'])));
            assertSame([], ContactSheet::services(null, []));
        });
    }),
    test('the walkie-talkie is on the sheet for a paired phone', function () use ($fresh) {
        $fresh(function (PDO $pdo) {
            $pdo->exec('UPDATE devices SET walkie_device_id = NULL');
            $devices = new DeviceRepository();
            $a = (int) $devices->create('Test walkie A', 'ghp621', 'udp')['id'];
            $b = (int) $devices->create('Test walkie B', 'ghp621', 'udp')['id'];
            $c = (int) $devices->create('Test walkie C', 'ghp621', 'udp')['id'];
            assertFalse(isset(ContactSheet::available($a)['walkie']));
            $devices->setWalkie($a, $b);
            assertSame((new SettingsRepository())->walkieNumber(), ContactSheet::available($a)['walkie']['dial'] ?? null);
            assertFalse(isset(ContactSheet::available($b)['walkie']), 'pairing is one way: B has no partner of its own');
            assertFalse(isset(ContactSheet::available($c)['walkie']), 'not paired');
            assertTrue(isset(ContactSheet::available()['walkie']), 'every phone: some are paired');
        });
    }),
    test("a rotary phone's sheet leaves out the lines that need keys pressed during the call", function () use ($fresh) {
        $fresh(function () {
            $devices = new DeviceRepository();
            $id = (int) $devices->create('Test rotary', 'ht801', 'udp')['id'];
            $touch = ContactSheet::available($id);
            assertTrue(isset($touch['games'], $touch['timer'], $touch['messages']), 'a touch-tone phone has them');

            assertTrue($devices->setPhoneSetting($id, 'rotary', true));
            $rotary = ContactSheet::available($id);
            foreach (['messages', 'games', 'times', 'timer'] as $key) {
                assertFalse(isset($rotary[$key]), "$key needs keys");
            }
            foreach (['jokes', 'clock', 'silly', 'christmas'] as $key) {
                assertTrue(isset($rotary[$key]), "$key is fine on a dial");
            }
            assertFalse(array_key_exists('keys', reset($rotary)));
            assertSame([], array_intersect(['Games', 'Kitchen timer'], array_column(ContactSheet::services($id, ['games', 'timer', 'jokes']), 'label')),
                'not even when asked for');
            assertTrue(isset(ContactSheet::available()['games']), 'every phone: still there');
            assertSame(['My messages', "The house's messages", 'Games', 'Times tables', 'Kitchen timer'], ContactSheet::keyLines());
        });
    }),
    test('every theme and paper has a label; themes with art ship it', function () {
        foreach (ContactSheet::THEMES as $key => $theme) {
            assertTrue($theme['label'] !== '', "$key has no label");
            if ($theme['art'] !== null) {
                assertTrue(is_file(__DIR__ . '/../' . $theme['art']), "$key art is missing");
            }
        }
        assertSame(['a4', 'a5'], array_keys(ContactSheet::PAPERS));
    }),
];
