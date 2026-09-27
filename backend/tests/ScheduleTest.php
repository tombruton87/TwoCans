<?php
declare(strict_types=1);

/**
 * Weekly timetables — bedtime, a phone's hours, a contact's call window.
 *
 * The part that matters most is conditions(): Asterisk reads a time range
 * against today's weekday only, so a rule that runs past midnight has to be
 * split, or "Friday night" silently becomes "Friday's small hours".
 */

return [
    test('an old single time pair reads as every day', function () {
        $rules = Schedule::fromJson(null, '19:30', '07:00');
        assertSame([Schedule::rule(Schedule::DAYS, '19:30', '07:00')], $rules);
    }),
    test('a daytime rule is one condition', function () {
        assertSame(
            ['15:00-19:00,mon-fri,*,*'],
            Schedule::conditions([Schedule::rule(['mon', 'tue', 'wed', 'thu', 'fri'], '15:00', '19:00')])
        );
    }),
    test('every day is a star', function () {
        assertSame(['09:00-17:00,*,*,*'], Schedule::conditions([Schedule::rule(Schedule::DAYS, '09:00', '17:00')]));
    }),
    test('a night past midnight is split onto the next morning', function () {
        assertSame(
            ['22:00-23:59,fri-sat,*,*', '00:00-07:00,sat-sun,*,*'],
            Schedule::conditions([Schedule::rule(['fri', 'sat'], '22:00', '07:00')])
        );
    }),
    test('sunday night runs into monday morning', function () {
        assertSame(
            ['20:00-23:59,sun,*,*', '00:00-07:00,mon,*,*'],
            Schedule::conditions([Schedule::rule(['sun'], '20:00', '07:00')])
        );
    }),
    test('days that are not next to each other are joined with &', function () {
        assertSame(
            ['16:00-18:00,mon&wed&fri,*,*'],
            Schedule::conditions([Schedule::rule(['mon', 'wed', 'fri'], '16:00', '18:00')])
        );
    }),
    test('the same time twice is the whole day', function () {
        assertSame(['00:00-23:59,sat-sun,*,*'], Schedule::conditions([Schedule::rule(['sat', 'sun'], '00:00', '00:00')]));
    }),
    test('the editor fields read into rules, and a blank row is dropped', function () {
        [$rules, $error] = Schedule::fromInput([
            ['days' => ['mon', 'tue'], 'from' => '19:30', 'to' => '07:00'],
            ['from' => '09:00', 'to' => '10:00'],
        ]);
        assertSame(null, $error);
        assertSame([Schedule::rule(['mon', 'tue'], '19:30', '07:00')], $rules);
    }),
    test('a row with days but no time is an error', function () {
        [, $error] = Schedule::fromInput([['days' => ['mon'], 'from' => '', 'to' => '07:00']]);
        assertContains('from and an until', (string) $error);
    }),
    test('no days at all is an error', function () {
        [, $error] = Schedule::fromInput([['from' => '09:00', 'to' => '10:00']]);
        assertContains('Tick at least one day', (string) $error);
    }),
    test('it reads out the way a parent would say it', function () {
        assertSame(
            'Weekdays 19:30–07:00 · Fri, Sat 21:30–08:30',
            Schedule::describe([
                Schedule::rule(['mon', 'tue', 'wed', 'thu', 'fri'], '19:30', '07:00'),
                Schedule::rule(['fri', 'sat'], '21:30', '08:30'),
            ])
        );
        assertSame('Mon–Wed, Fri all day', Schedule::describe([Schedule::rule(['mon', 'tue', 'wed', 'fri'], '08:00', '08:00')]));
    }),
    test('friday night is on in the small hours of saturday, not friday', function () {
        $rules = [Schedule::rule(['fri'], '22:00', '07:00')];
        assertSame(true, Schedule::isOn($rules, new DateTimeImmutable('2026-10-03 03:00')));   // Sat
        assertSame(false, Schedule::isOn($rules, new DateTimeImmutable('2026-10-02 03:00')));  // Fri
        assertSame(true, Schedule::isOn($rules, new DateTimeImmutable('2026-10-02 23:00')));   // Fri
    }),
];
