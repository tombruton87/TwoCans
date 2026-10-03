<?php
declare(strict_types=1);

/**
 * "What time is it?" and the kitchen timer — the time in words, bedtime,
 * and the dialplan that does both.
 */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM settings WHERE name IN ('clock_number', 'timer_number', 'quiet_schedule', 'quiet_from', 'quiet_to', 'quiet_hours')");
        SettingsRepository::forget();
        $fn(new SettingsRepository());
    } finally {
        $pdo->rollBack();
        SettingsRepository::forget();
    }
};

return [
    test('the time the way children learn it, to the nearest five minutes', function () {
        assertSame("six o'clock", Clock::words(18, 1));
        assertSame('ten past six', Clock::words(18, 9));
        assertSame('quarter past six', Clock::words(18, 14));
        assertSame('twenty-five past six', Clock::words(18, 26));
        assertSame('half past six', Clock::words(18, 30));
        assertSame('twenty-five to seven', Clock::words(18, 35));
        assertSame('quarter to seven', Clock::words(18, 44));
        assertSame('five to seven', Clock::words(18, 56));
        assertSame("seven o'clock", Clock::words(18, 58), 'rounds up into the next hour');
        assertSame("twelve o'clock", Clock::words(0, 0));
        assertSame('quarter to one', Clock::words(12, 45));
    }),
    test('bedtime: how long till it when it\'s close, and now when it is', function () use ($fresh) {
        $fresh(function (SettingsRepository $s) {
            $tz = new DateTimeZone('Europe/London');
            assertSame('19:30', Clock::bedtimes($s)[3] ?? null, "Wednesday's bedtime");
            $at = static fn(string $t): DateTimeImmutable => new DateTimeImmutable('2026-09-30 ' . $t, $tz); // a Wednesday
            assertSame("It's ten past six. Bedtime is in an hour and 20 minutes!", Clock::say($at('18:10'), $s));
            assertSame("It's half past six. Bedtime is in an hour.", Clock::say($at('18:30'), $s));
            assertSame("It's quarter past seven. Bedtime is in 15 minutes!", Clock::say($at('19:15'), $s));
            assertSame("It's four o'clock.", Clock::say($at('16:00'), $s), 'not close enough to mention');
            assertSame("It's bedtime! Night night, sleep tight!", Clock::say($at('20:10'), $s));
        });
    }),
    test('the dialplan says the time, and bedtime only on a child\'s phone', function () use ($fresh) {
        $fresh(function (SettingsRepository $s) {
            $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
            assertContains("exten => 8463,1,NoOp(twocans: what time is it)", $plan);
            assertContains('[' . PjsipConfig::CLOCK_CONTEXT . ']', $plan);
            assertContains('ExecIf($[${DOW} = 3]?Set(BED=1170))', $plan);
            assertContains('GotoIf($["${TC_ADULT}" = "1"]?bye)', $plan);
        });
    }),
    test('a timer is a call file dated for when it\'s due — none of it a comment to Asterisk', function () use ($fresh) {
        $fresh(function (SettingsRepository $s) {
            $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
            assertContains("exten => 2463,1,NoOp(twocans: the kitchen timer)", $plan);
            $system = '';
            foreach (explode("\n", $plan) as $line) {
                if (str_contains($line, 'System(mkdir -p /var/spool/asterisk/tc-timers')) {
                    $system = $line;
                }
            }
            assertTrue($system !== '');
            // Every ; escaped, or Asterisk would cut the line off at the first.
            assertSame(0, preg_match('/(?<!\\\\);/', $system));
            assertContains("echo 'Channel: PJSIP/\${PHONE}'", $system);
            assertContains('touch -d @${DUE}', $system);
            assertContains('/var/spool/asterisk/outgoing/tc-timer-${PHONE}.call)', $system);
            assertContains('exten => ding,1,Answer()', $plan);
            assertContains('?done)', $plan);
            assertTrue(in_array('timer', CallRepository::NOT_SHOWN, true), 'not in the call log');
        });
    }),
    test('the clock and timer keep numbers of their own', function () use ($fresh) {
        $fresh(function (SettingsRepository $s) {
            assertSame(null, $s->clockNumberProblem('8463'));
            assertSame(null, $s->timerNumberProblem('2463'));
            assertTrue($s->clockNumberProblem('2463') !== null);
            assertTrue($s->timerNumberProblem($s->jokeNumber()) !== null);
            assertTrue($s->jokeNumberProblem('8463') !== null);
            assertTrue((new ContactRepository())->speedDialProblem('2463') !== null);
        });
    }),
];
