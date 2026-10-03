<?php
declare(strict_types=1);

/**
 * Turns stored records into the labels, colours and CSS modifiers the views
 * render. Mirrors the `renderVals()` block of the design prototype.
 */
final class Presenter
{
    /** Call-window presets, per the design's WIN map. */
    public const WINDOWS = [
        'anytime' => ['label' => 'Anytime', 'sub' => 'Always reachable', 'mod' => 'teal'],
        'afterschool' => ['label' => 'After school', 'sub' => 'Weekdays 3:00–7:00pm', 'mod' => 'sun'],
        'weekends' => ['label' => 'Weekends', 'sub' => 'Sat & Sun, daytime only', 'mod' => 'lav'],
        'custom' => ['label' => 'Custom hours', 'sub' => 'You set the window', 'mod' => 'sky'],
    ];

    public const SCREENS = ['dashboard', 'phones', 'contacts', 'calllog', 'voicemail', 'jokes', 'guardians', 'trunk', 'dialplan', 'system', 'notifications', 'greetings', 'announcements', 'homeassistant', 'start', 'keepsakes', 'quiz', 'christmas', 'radio', 'week', 'helpers'];

    /** Header title + subtitle per screen. */
    public const TITLES = [
        'dashboard' => ['Hello, Priya 👋', "Here's how the family line is doing."],
        'phones' => ['Phones', 'The little tin cans on your line.'],
        'contacts' => ['People', "Who's allowed to call, and when."],
        'calllog' => ['Call log', 'Every call, with transcripts you can keep.'],
        'voicemail' => ['Voicemail', 'Messages left when no one could pick up.'],
        // No number here: it's the household's choice (258 by default), and
        // the banner on the screen says which it is.
        'jokes' => ['The joke line', 'A joke on tap from any phone. You choose what goes on it.'],
        'guardians' => ['Family & guardians', 'The grown-ups who help run this line.'],
        'trunk' => ['Phone line', 'Where your calls actually travel.'],
        'dialplan' => ['Dial plan', 'Which numbers the kids may dial beyond their contacts.'],
        'system' => ['System', 'How the box is doing, and its backups.'],
        'notifications' => ['Notifications', 'Email and uptime alerts from your line.'],
        'greetings' => ['Greetings', 'Everything the line says out loud, and who hears it.'],
        'announcements' => ['Announcements', 'Buttons that page the phones with a message.'],
        'homeassistant' => ['Home Assistant', 'Your line, its phones and its calls, in Home Assistant.'],
        'start' => ['Getting started', 'A few steps to a line the family can use.'],
        'keepsakes' => ['Keepsakes', 'Messages worth keeping for good.'],
        'quiz' => ['Games', 'Times tables, sums and more, on the keypad of any phone.'],
        'helpers' => ['Handy lines', 'A kitchen timer, the time, silly voices and walkie-talkies.'],
        'week' => ['The week', "Each child's week on the line: who they talked to, and what they got up to."],
        'radio' => ['The radio', 'Songs you choose, on a number any phone can dial.'],
        'christmas' => ['Sleeps till Christmas', 'Santa counts down the sleeps, and calls on the day.'],
    ];

    /** Symbols for the ISO 4217 codes a provider is likely to report. */
    public const CURRENCY_SYMBOLS = [
        'USD' => '$', 'GBP' => '£', 'EUR' => '€', 'JPY' => '¥',
        'AUD' => 'A$', 'CAD' => 'CA$', 'NZD' => 'NZ$',
    ];

    public static function window(string $key): array
    {
        return self::WINDOWS[$key] ?? self::WINDOWS['custom'];
    }

    /**
     * Page numbers to show in a pager, with nulls where a gap goes.
     *
     * Always offers the first and last page plus a couple either side of the
     * current one, so the row stays the same width whether there are three
     * pages or three hundred.
     *
     * @return array<int,?int>
     */
    public static function pageNumbers(int $current, int $total, int $around = 1): array
    {
        if ($total < 1) {
            return [];
        }

        // Up to seven fits comfortably on a phone, and hiding two pages behind
        // an ellipsis to save one button's width helps nobody.
        if ($total <= 7) {
            return range(1, $total);
        }

        $wanted = [1, $total];
        for ($n = $current - $around; $n <= $current + $around; $n++) {
            $wanted[] = $n;
        }

        $pages = array_values(array_unique(array_filter(
            $wanted,
            static fn(int $n): bool => $n >= 1 && $n <= $total
        )));
        sort($pages);

        // A gap of exactly one page is silly — show the page instead of an
        // ellipsis that hides a single number.
        $out = [];
        $previous = 0;
        foreach ($pages as $n) {
            if ($previous > 0 && $n - $previous > 1) {
                $out[] = $n - $previous === 2 ? $n - 1 : null;
            }
            $out[] = $n;
            $previous = $n;
        }

        return $out;
    }

    public static function device(array $d): array
    {
        // Three states, not two: a phone that has never registered is not the
        // same as one that registered and then went away.
        if ($d['online']) {
            $d['statusText'] = 'Online';
            $d['statusMod'] = 'ok';
            $d['lastSeenText'] = 'Heard a heartbeat just now';
        } elseif (!empty($d['registered'])) {
            $d['statusText'] = 'Offline';
            $d['statusMod'] = 'bad';
            $d['lastSeenText'] = 'Last seen ' . $d['lastSeen'];
        } else {
            $d['statusText'] = 'Not set up';
            $d['statusMod'] = 'muted';
            $d['lastSeenText'] = 'Waiting for the app to sign in';
        }

        $d['ruleSummary'] = !empty($d['pausedUntil'])
            ? 'Paused until ' . date('g:ia', (int) $d['pausedUntil'])
            : (!empty($d['adult'])
            ? 'Adult mode — no restrictions'
            : ($d['allowOut'] ? 'Can call out' : 'No outgoing') . ' · ' . Schedule::describe($d['hours'] ?? []));

        return $d;
    }

    public static function contact(array $c): array
    {
        $w = self::window($c['window']);
        $c['initial'] = initial($c['name']);
        $c['hasCode'] = $c['code'] !== '';
        $c['winLabel'] = $w['label'];
        $c['winSub'] = $w['sub'];
        // Custom hours say what they are, on the card and in the editor.
        if ($c['window'] === 'custom' && !empty($c['windowRules'])) {
            $c['winLabel'] = Schedule::describe($c['windowRules']);
            $c['winSub'] = Schedule::describe($c['windowRules']);
        }
        $c['winMod'] = $w['mod'];
        $c['inText'] = $c['allowIn'] ? 'on' : 'off';
        $c['outText'] = $c['allowOut'] ? 'on' : 'off';

        return $c;
    }

    /** Status pill copy + colour modifier for a call row. */
    public static function call(array $c): array
    {
        $dirLabel = $c['dir'] === 'in' ? 'Incoming' : 'Outgoing';

        if ($c['status'] === 'done') {
            $c['statusMod'] = 'ok';
            $c['statusLabel'] = $dirLabel . ' · ' . $c['dur'];
            $c['tag'] = $c['dir'] === 'in' ? '↙ in' : '↗ out';
        } elseif ($c['status'] === 'blocked') {
            $c['statusMod'] = 'bad';
            $c['statusLabel'] = 'Blocked';
            $c['tag'] = 'blocked';
        } else {
            $c['statusMod'] = 'muted';
            $c['statusLabel'] = 'Missed';
            $c['tag'] = 'missed';
        }

        $c['meta'] = $dirLabel . ' · ' . $c['date'] . ' ' . $c['time'] . ($c['dur'] !== '—' ? ' · ' . $c['dur'] : '');

        // "Kitchen called" / "Rang Kitchen": which phone, and which way, in the
        // words a parent would use. Falls back to the direction alone.
        $phone = (string) ($c['deviceName'] ?? '');
        $c['via'] = $phone === ''
            ? $dirLabel
            : ($c['dir'] === 'in' ? 'Rang ' . $phone : $phone . ' called');
        $c['dirLabel'] = $dirLabel;
        $c['showDownload'] = $c['status'] !== 'blocked';

        return $c;
    }

    public static function voicemail(array $v, ?string $playingId): array
    {
        $v['isPlaying'] = $playingId === $v['id'];
        $v['glyph'] = $v['isPlaying'] ? '❚❚' : '▶';
        $v['meta'] = $v['date'] . ' ' . $v['time'] . ' · ' . $v['dur'];

        return $v;
    }

    public static function guardian(array $g, ?int $currentUserId = null): array
    {
        $g['you'] = $currentUserId !== null && (int) $g['id'] === $currentUserId;
        $g['initial'] = initial($g['name']);
        $g['isPending'] = $g['status'] === 'pending';
        $g['password_set'] = ($g['password_hash'] ?? null) !== null;
        // The Owner's role is fixed, and you can't demote or delete yourself.
        $g['canEditRole'] = !$g['you'] && $g['role'] !== 'Owner';
        $g['roleMod'] = match ($g['role']) {
            'Owner' => 'owner',
            'Admin' => 'admin',
            default => 'viewer',
        };

        return $g;
    }

    /** Copy shown under the Listen / Whisper / Join picker. */
    public static function listenModeDescription(string $mode): string
    {
        return match ($mode) {
            'whisper' => "Only your child hears you — the other caller can't. Great for a quiet nudge.",
            'join' => "You've joined the call. Everyone can hear you now.",
            default => "You're on mute — just listening. Neither person can hear you.",
        };
    }

    /**
     * Credit as the screens show it. The stored currency is an ISO 4217 code,
     * so an unknown one is printed as-is ("CHF 4.20") rather than guessed at.
     */
    public static function money(array $trunk): string
    {
        if (($trunk['balance'] ?? null) === null) {
            return '—';
        }

        $code = strtoupper((string) ($trunk['currency'] ?? 'USD'));
        $amount = number_format((float) $trunk['balance'], 2);

        return isset(self::CURRENCY_SYMBOLS[$code])
            ? self::CURRENCY_SYMBOLS[$code] . $amount
            : $code . ' ' . $amount;
    }

    /**
     * Dynamic DNS, as the Phone line screen shows it.
     *
     * Three states, because "not working" and "not running" are different
     * problems: an error is something to fix, but checks that have quietly
     * stopped would otherwise look identical to everything being fine while the
     * record went stale. So an old timestamp is reported as a fault in itself.
     */
    public static function ddns(array $dns): array
    {
        $checkedAt = $dns['checkedAt'] === null ? null : strtotime((string) $dns['checkedAt']);
        $age = ($checkedAt === null || $checkedAt === false) ? null : max(0, time() - $checkedAt);

        $dns['checkedText'] = $age === null ? 'not yet' : self::ago($age);
        $dns['updatedText'] = $dns['updatedAt'] === null
            ? 'never'
            : date('j M Y, H:i', (int) strtotime((string) $dns['updatedAt']));
        $dns['stale'] = $age === null || $age > DynamicDns::STALE_SECONDS;

        if ($dns['error'] !== null) {
            $dns['statusMod'] = 'bad';
            $dns['statusLabel'] = 'Needs a look';
        } elseif ($dns['stale']) {
            $dns['statusMod'] = 'muted';
            $dns['statusLabel'] = 'Waiting for a check';
        } else {
            $dns['statusMod'] = 'ok';
            $dns['statusLabel'] = 'Pointing here';
        }

        return $dns;
    }

    /** "just now", "4 minutes ago" — coarse on purpose. */
    private static function ago(int $seconds): string
    {
        if ($seconds < 75) {
            return 'just now';
        }

        $plural = static fn(int $n, string $unit): string
            => $n . ' ' . $unit . ($n === 1 ? '' : 's') . ' ago';

        if ($seconds < 3600) {
            return $plural(intdiv($seconds, 60), 'minute');
        }
        if ($seconds < 86400) {
            return $plural(intdiv($seconds, 3600), 'hour');
        }

        return $plural(intdiv($seconds, 86400), 'day');
    }

    /** Bedtime in a few words, for the sidebar: the first rule, and how many more. */
    public static function quietRange(array $settings): string
    {
        if (!$settings['quietHours']) {
            return 'Off';
        }
        $rules = $settings['quietRules'] ?? [];
        if ($rules === []) {
            return $settings['quietFrom'] . '–' . $settings['quietTo'];
        }
        $first = Schedule::describe([$rules[0]]);

        return count($rules) > 1 ? $first . ' +' . (count($rules) - 1) : $first;
    }

    public static function quietStateText(array $settings): string
    {
        return $settings['quietHours']
            ? 'On — the line sleeps overnight'
            : "Off — calls allowed within each phone’s hours";
    }
}
