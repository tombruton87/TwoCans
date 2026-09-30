<?php
declare(strict_types=1);

/** Announcements that play by themselves: when they're due, and how they read. */

$a = static fn(array $days, string $time, ?string $last = null): array
    => ['scheduleDays' => $days, 'scheduleTime' => $time, 'lastScheduledAt' => $last];
$at = static fn(string $when): DateTimeImmutable => new DateTimeImmutable($when);

return [
    test('due on one of its days, from its time for five minutes', function () use ($a, $at) {
        // 2026-09-28 is a Monday.
        $bath = $a([1, 2, 3, 4, 7], '18:50');
        assertSame(null, AnnouncementRepository::dueAt($bath, $at('2026-09-28 18:49:30')));
        assertSame('2026-09-28 18:50:00', AnnouncementRepository::dueAt($bath, $at('2026-09-28 18:50:10'))?->format('Y-m-d H:i:s'));
        assertSame('2026-09-28 18:50:00', AnnouncementRepository::dueAt($bath, $at('2026-09-28 18:54:59'))?->format('Y-m-d H:i:s'));
        assertSame(null, AnnouncementRepository::dueAt($bath, $at('2026-09-28 18:55:00')), 'too late: not played late');
        assertSame(null, AnnouncementRepository::dueAt($bath, $at('2026-10-02 18:51:00')), 'a Friday');
    }),
    test('not played twice for the same time', function () use ($a, $at) {
        $bath = $a([1], '18:50', '2026-09-28 18:50:00');
        assertSame(null, AnnouncementRepository::dueAt($bath, $at('2026-09-28 18:52:00')));
        $lastWeek = $a([1], '18:50', '2026-09-21 18:50:00');
        assertSame('2026-09-28 18:50:00', AnnouncementRepository::dueAt($lastWeek, $at('2026-09-28 18:52:00'))?->format('Y-m-d H:i:s'));
    }),
    test('off means never due', function () use ($a, $at) {
        assertSame(null, AnnouncementRepository::dueAt($a([], ''), $at('2026-09-28 18:50:00')));
    }),
    test('the days read the way a parent says them', function () {
        assertSame('school nights at 18:50', AnnouncementRepository::describeSchedule([1, 2, 3, 4, 7], '18:50'));
        assertSame('every day at 07:30', AnnouncementRepository::describeSchedule([1, 2, 3, 4, 5, 6, 7], '07:30'));
        assertSame('weekends at 09:00', AnnouncementRepository::describeSchedule([6, 7], '09:00'));
        assertSame('every Wednesday at 16:00', AnnouncementRepository::describeSchedule([3], '16:00'));
        assertSame('Mon, Wed and Fri at 16:00', AnnouncementRepository::describeSchedule([1, 3, 5], '16:00'));
        assertSame('', AnnouncementRepository::describeSchedule([], ''));
    }),
];
