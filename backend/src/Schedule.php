<?php
declare(strict_types=1);

/**
 * A weekly timetable: bedtime, a phone's hours, a contact's call window.
 *
 * One shape for all three. A schedule is a list of rules, and each rule is
 * some days of the week with a from–until time: "weekdays 19:30–07:00" and
 * "Fri, Sat 21:30–08:30" is two rules. The schedule is on whenever any rule
 * is.
 *
 * A rule whose until is earlier than its from runs past midnight, and belongs
 * to the day it starts: "Fri 22:00–07:00" is Friday night into Saturday
 * morning. Asterisk reads a time range against today's weekday only, so
 * conditions() splits such a rule in two — the evening on its own days, the
 * morning on the day after each.
 *
 * Stored as JSON: [{"days":["mon",…],"from":"19:30","to":"07:00"}, …].
 */
final class Schedule
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const NAMES = [
        'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu',
        'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun',
    ];

    /** More than a household would sensibly set; stops a runaway form. */
    private const MAX_RULES = 12;

    /**
     * Read a stored schedule. Anything unreadable — no value yet, a garbled
     * row — falls back to one rule, every day, $from–$to, which is exactly
     * how these times behaved before schedules existed.
     *
     * @return array<int,array{days:array<int,string>,from:string,to:string}>
     */
    public static function fromJson(?string $json, string $from, string $to): array
    {
        $decoded = json_decode((string) $json, true);
        $rules = is_array($decoded) ? self::clean($decoded) : [];

        return $rules !== [] ? $rules : [self::rule(self::DAYS, $from, $to)];
    }

    /** @param array<int,array> $rules */
    public static function toJson(array $rules): string
    {
        return (string) json_encode(array_values($rules));
    }

    /**
     * Read the schedule editor's fields: schedule[i][days][], [from], [to].
     *
     * A row with no days ticked is taken as removed rather than as an error —
     * that is how the editor's blank spare row arrives.
     *
     * @return array{0:array<int,array>,1:?string} the rules, or an error
     */
    public static function fromInput(mixed $input): array
    {
        $rules = [];
        foreach (is_array($input) ? $input : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $days = array_values(array_intersect(self::DAYS, (array) ($row['days'] ?? [])));
            if ($days === []) {
                continue;
            }
            $from = self::time((string) ($row['from'] ?? ''));
            $to = self::time((string) ($row['to'] ?? ''));
            if ($from === null || $to === null) {
                return [[], 'Give every row a from and an until time.'];
            }
            $rules[] = self::rule($days, $from, $to);
        }

        if ($rules === []) {
            return [[], 'Tick at least one day.'];
        }
        if (count($rules) > self::MAX_RULES) {
            return [[], 'That is more rows than a week needs — ' . self::MAX_RULES . ' at most.'];
        }

        return [$rules, null];
    }

    /**
     * GotoIfTime conditions, without the timezone: the schedule is on when
     * any one of them matches.
     *
     * @param  array<int,array> $rules
     * @return array<int,string>
     */
    public static function conditions(array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            $days = $rule['days'];
            $from = $rule['from'];
            $to = $rule['to'];

            if ($from === $to) {
                // The same time twice means the whole day.
                $out[] = '00:00-23:59,' . self::dowSpec($days) . ',*,*';
            } elseif ($from < $to) {
                $out[] = $from . '-' . $to . ',' . self::dowSpec($days) . ',*,*';
            } else {
                // Past midnight: the evening on its own days, the morning on
                // the day after each of them.
                $out[] = $from . '-23:59,' . self::dowSpec($days) . ',*,*';
                if ($to !== '00:00') {
                    $out[] = '00:00-' . $to . ',' . self::dowSpec(self::nextDays($days)) . ',*,*';
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Is the schedule on at this moment? Same reading as conditions(), for the
     * screens that say "bedtime now" without asking Asterisk.
     *
     * @param array<int,array> $rules
     */
    public static function isOn(array $rules, DateTimeInterface $at): bool
    {
        $today = strtolower($at->format('D'));
        $yesterday = strtolower((new DateTimeImmutable($at->format('Y-m-d H:i:s')))->modify('-1 day')->format('D'));
        $now = $at->format('H:i');

        foreach ($rules as $rule) {
            $from = $rule['from'];
            $to = $rule['to'];
            if ($from === $to) {
                if (in_array($today, $rule['days'], true)) {
                    return true;
                }
            } elseif ($from < $to) {
                if (in_array($today, $rule['days'], true) && $now >= $from && $now <= $to) {
                    return true;
                }
            } else {
                if (in_array($today, $rule['days'], true) && $now >= $from) {
                    return true;
                }
                if (in_array($yesterday, $rule['days'], true) && $now <= $to) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * "Weekdays 19:30–07:00 · Fri, Sat 21:30–08:30" — for cards and chips.
     *
     * @param array<int,array> $rules
     */
    public static function describe(array $rules): string
    {
        $parts = [];
        foreach ($rules as $rule) {
            $range = $rule['from'] === $rule['to'] ? 'all day' : $rule['from'] . '–' . $rule['to'];
            $parts[] = self::describeDays($rule['days']) . ' ' . $range;
        }

        return implode(' · ', $parts);
    }

    /** @param array<int,string> $days */
    public static function describeDays(array $days): string
    {
        $days = array_values(array_intersect(self::DAYS, $days));
        if ($days === self::DAYS) {
            return 'Every day';
        }
        if ($days === ['mon', 'tue', 'wed', 'thu', 'fri']) {
            return 'Weekdays';
        }
        if ($days === ['sat', 'sun']) {
            return 'Weekends';
        }

        $runs = [];
        foreach (self::runs($days) as [$start, $end]) {
            $runs[] = $start === $end
                ? self::NAMES[$start]
                : self::NAMES[$start] . (self::index($end) - self::index($start) === 1 ? ', ' : '–') . self::NAMES[$end];
        }

        return implode(', ', $runs);
    }

    /** One rule, days in week order. */
    public static function rule(array $days, string $from, string $to): array
    {
        return [
            'days' => array_values(array_intersect(self::DAYS, $days)),
            'from' => $from,
            'to' => $to,
        ];
    }

    // --------------------------------------------------------------- inside

    /** @return array<int,array> only the well-formed rules */
    private static function clean(array $decoded): array
    {
        $rules = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $days = array_values(array_intersect(self::DAYS, (array) ($row['days'] ?? [])));
            $from = self::time((string) ($row['from'] ?? ''));
            $to = self::time((string) ($row['to'] ?? ''));
            if ($days !== [] && $from !== null && $to !== null) {
                $rules[] = self::rule($days, $from, $to);
            }
        }

        return $rules;
    }

    /** "7:5" or "07:05:00" → "07:05"; anything else → null. */
    private static function time(string $value): ?string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $i);
    }

    /** Asterisk's day-of-week field: runs joined with &, e.g. mon-wed&fri. */
    private static function dowSpec(array $days): string
    {
        if (array_values(array_intersect(self::DAYS, $days)) === self::DAYS) {
            return '*';
        }
        $parts = [];
        foreach (self::runs($days) as [$start, $end]) {
            $parts[] = $start === $end ? $start : $start . '-' . $end;
        }

        return implode('&', $parts);
    }

    /** @return array<int,array{0:string,1:string}> consecutive days as [start, end] */
    private static function runs(array $days): array
    {
        $set = array_values(array_intersect(self::DAYS, $days));
        $runs = [];
        foreach ($set as $day) {
            $last = count($runs) - 1;
            if ($last >= 0 && self::index($day) === self::index($runs[$last][1]) + 1) {
                $runs[$last][1] = $day;
            } else {
                $runs[] = [$day, $day];
            }
        }

        return $runs;
    }

    /** @return array<int,string> the day after each, Sunday into Monday */
    private static function nextDays(array $days): array
    {
        $next = [];
        foreach ($days as $day) {
            $next[] = self::DAYS[(self::index($day) + 1) % 7];
        }

        return array_values(array_intersect(self::DAYS, $next));
    }

    private static function index(string $day): int
    {
        return (int) array_search($day, self::DAYS, true);
    }
}
