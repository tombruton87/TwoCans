<?php
declare(strict_types=1);

/**
 * How many sleeps until Christmas, and Santa's call on Christmas morning —
 * the guards only: nothing here ever rings a phone.
 */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        // The defaults, whatever this household has chosen (rolled back after).
        $pdo->exec("DELETE FROM settings WHERE name IN ('games_number', 'quiz_number', 'joke_number', 'sleeps_number', 'radio_number')");
        SettingsRepository::forget();
        $fn();
    } finally {
        $pdo->rollBack();
        SettingsRepository::forget();
    }
};

$day = static fn(string $date): DateTimeImmutable => new DateTimeImmutable($date, new DateTimeZone('Europe/London'));

return [
    test('the sleeps count down to Christmas Day, then start again', function () use ($day) {
        assertSame(85, Christmas::sleeps($day('2026-10-01')));
        assertSame(1, Christmas::sleeps($day('2026-12-24 23:59')));
        assertSame(0, Christmas::sleeps($day('2026-12-25 00:01')));
        assertSame(364, Christmas::sleeps($day('2026-12-26')));
        assertSame(366, Christmas::sleeps($day('2027-12-25 +1 day')) + 1, 'a leap year has one more');
        // Across the clocks changing, still whole days.
        assertSame(57, Christmas::sleeps($day('2026-10-29')));
    }),
    test('Santa says the sleeps, one more sleep on Christmas Eve, and his message on the day', function () use ($day) {
        assertContains('There are 85 sleeps', Christmas::saying($day('2026-10-01')));
        assertContains('Christmas Eve', Christmas::saying($day('2026-12-24')));
        assertContains("Santa's message", Christmas::saying($day('2026-12-25')));
    }),
    test('the dialplan counts on the day, and says up to 365 in Santa\'s voice', function () use ($fresh) {
        $fresh(function () {
            $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
            assertContains("exten => 1225,1,NoOp(twocans: how many sleeps until Christmas)\n same => n,GotoIf(\$[\"\${TC_PAUSED}\" = \"1\"]?" . PjsipConfig::PAUSED_CONTEXT . ",s,1)\n same => n,Goto(" . PjsipConfig::CHRISTMAS_CONTEXT . ',s,1)', $plan);
            assertContains('Set(SLEEPS=${MATH(${DIFF}/86400,int)})', $plan);
            assertContains('Playback(' . Christmas::SOUNDS . '/christmas-day)', $plan);
            $c = Christmas::SOUNDS;
            assertContains("exten => 365,1,Return({$c}/n-300-and&{$c}/n-60&{$c}/n-5)", $plan);
            assertContains("exten => 200,1,Return({$c}/n-200)", $plan);
        });
    }),
    test('the household\'s own Santa message plays instead, when there is one', function () use ($fresh) {
        $fresh(function () {
            $dir = sys_get_temp_dir() . '/tc-santa-test-' . bin2hex(random_bytes(4));
            mkdir($dir);
            $name = str_repeat('a', 32) . '.wav';
            file_put_contents("{$dir}/{$name}", 'RIFF');
            putenv('SANTA_PATH=' . $dir);
            try {
                (new SettingsRepository())->setSantaMessage($name, 30);
                $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
                assertContains("Playback({$dir}/" . str_repeat('a', 32) . ')', $plan);
                // And Santa's own if it's gone missing, rather than silence.
                assertContains('GotoIf($["${PLAYBACKSTATUS}" = "SUCCESS"]?bye)', $plan);
            } finally {
                putenv('SANTA_PATH');
                exec('rm -rf ' . escapeshellarg($dir));
            }
        });
    }),
    test('the countdown keeps a number of its own', function () use ($fresh) {
        $fresh(function () {
            $s = new SettingsRepository();
            assertSame(null, $s->sleepsNumberProblem('1225'));
            assertTrue($s->sleepsNumberProblem($s->jokeNumber()) !== null);
            assertTrue($s->gamesNumberProblem('1225') !== null);
            assertTrue($s->jokeNumberProblem('1225') !== null);
            assertTrue((new ContactRepository())->speedDialProblem('1225') !== null);
        });
    }),
    test('Santa only rings when switched on, on Christmas morning, after his time, once a year', function () use ($fresh) {
        $fresh(function () {
            $s = new SettingsRepository();
            $at = static fn(string $when): DateTimeImmutable => new DateTimeImmutable($when, new DateTimeZone('Europe/London'));
            assertSame([], Christmas::ringIfDue($at('2026-12-25 09:00'), $s), 'off');
            $s->setSantaRing(true, '08:30');
            assertSame('08:30', $s->santaRingTime());
            assertSame([], Christmas::ringIfDue($at('2026-12-24 09:00'), $s), 'not Christmas');
            assertSame([], Christmas::ringIfDue($at('2026-12-25 08:29'), $s), 'too early');
            $s->setSantaRangYear('2026');
            assertSame([], Christmas::ringIfDue($at('2026-12-25 09:00'), $s), 'already rang');
            $s->setSantaRing(true, '25:00');
            assertSame('08:30', $s->santaRingTime(), 'not a time');
        });
    }),
    test('Santa rings children\'s phones only: not adult mode, not incoming off', function () use ($fresh) {
        $fresh(function () {
            $devices = new DeviceRepository();
            $child = $devices->create('Test bedroom', 'ghp621', 'udp');
            $off = $devices->create('Test playroom', 'ghp621', 'udp');
            $devices->setAllow((int) $off['id'], 'allowIn', false);
            $names = array_column(Christmas::childPhones(), 'name');
            assertTrue(in_array('Test bedroom', $names, true));
            assertFalse(in_array('Test playroom', $names, true));
        });
    }),
];
