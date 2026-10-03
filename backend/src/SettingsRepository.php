<?php
declare(strict_types=1);

/**
 * Household settings — currently quiet hours.
 *
 * These moved out of the session because the generated dialplan is built from
 * them: a bedtime setting that only existed in one browser session could not
 * possibly stop a call at 2am.
 */
final class SettingsRepository
{
    private const DEFAULTS = [
        'quiet_hours' => '1',
        'quiet_from' => '19:30',
        // Bedtime by day, as Schedule JSON. Empty: quiet_from–quiet_to every day.
        'quiet_schedule' => '',
        'quiet_to' => '07:00',
        // The message a caller hears while bedtime mode has the line asleep,
        // and how long that recording runs. Blank means nobody has recorded
        // one yet, and the caller gets the stock voicemail greeting exactly as
        // they did before this existed.
        'quiet_message' => '',
        'quiet_message_seconds' => '0',
        // The house's own "press 1 to join" for group calls. Empty: Asterisk's
        // stock prompt plays, unless the group has one of its own.
        'group_prompt' => '',
        'group_prompt_seconds' => '0',
        // Whether a caller nobody recognises is offered the house mailbox once
        // the refusal has been spoken, instead of the line simply hanging up.
        // On by default: an unknown number still never rings a phone, and a
        // message is easier to judge than a click.
        'screen_unknown' => '1',
        // How long recordings and transcripts are kept, in days. 0 means keep
        // them forever. A fresh install gets 90; an install that already had
        // call history when retention arrived is pinned to 0 by migration 016,
        // so nobody's recordings vanish because they upgraded.
        'retention_days' => '90',
        'retention_last_sweep' => '',
        // The joke line. Changeable, because it has to fit around whatever
        // numbers a household has already taught its children.
        'joke_number' => '258',
        // The times tables quiz: its number, the tables in a mix (a child can
        // also pick one), and the questions a game.
        'quiz_number' => '246',
        'quiz_tables' => '2,3,4,5,6,7,8,9,10,11,12',
        'quiz_questions' => '10',
        // The games line (G-A-M-E), and what its sums and number bonds go up to.
        'games_number' => '4263',
        'sums_max' => '10',
        'bonds_to' => '10',
        // How many sleeps until Christmas (12-25), and Santa's Christmas Day
        // message when the household records its own. Santa ringing the
        // children's phones on Christmas morning is off unless it's switched
        // on; santa_rang_year stops him ringing twice.
        // The radio's number (R-A-D-I-O) — see Radio.
        'radio_number' => '7234',
        // "What time is it?" (T-I-M-E) and the kitchen timer (C-H-I-M-E).
        'clock_number' => '8463',
        'timer_number' => '2463',
        // The silly voice line (S-I-L-L) and the walkie-talkie (W-A-L-K).
        'silly_number' => '7455',
        'walkie_number' => '9255',
        // Played in a shuffled order, or in the household's own.
        'radio_shuffle' => '1',
        // At bedtime: as normal, calm songs only, or off; and the sleep timer.
        'radio_bedtime' => 'normal',
        'radio_sleep_minutes' => '0',
        'sleeps_number' => '1225',
        'santa_message' => '',
        'santa_message_seconds' => '0',
        'santa_ring' => '0',
        'santa_ring_time' => '08:00',
        'santa_rang_year' => '',
        // A speed dial for "your messages", as well as 700; '' is none.
        'voicemail_speed_dial' => '',
        /*
         * SIP ports. Empty means "use the environment, then the default".
         *
         * Held here as well as in .env because .env only reaches the container
         * that compose starts, and the ports have to survive a regeneration
         * from anywhere — getting them wrong silently stops calls. .env still
         * drives which ports compose publishes, and the two must agree.
         */
        'sip_port' => '',
        'sip_tls_port' => '',
        'trunk_sip_port' => '',
        // The address the SIP provider reaches this box on, when it should not
        // be the dynamic DNS name twocans works out for itself. Blank means
        // "use dynamic DNS" — see PjsipConfig::trunkPublicHost().
        'trunk_public_host' => '',
        // HTTP-basic password a Grandstream phone uses to fetch its config.
        // Generated on first use and stored here so the UI can show it.
        'provision_pass' => '',
    ];

    /** What a parent can pick, longest-lived last. */
    public const RETENTION_CHOICES = [
        '7' => 'a week',
        '30' => '30 days',
        '90' => '90 days',
        '365' => 'a year',
        '0' => 'forever',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /**
     * Forget what was read, so the next read sees the database again — for a
     * long-running worker (the Home Assistant bridge), where the web app may
     * have changed a setting since. A page load never needs this.
     */
    public static function forget(): void
    {
        self::$cache = null;
    }

    public function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows = Database::pdo()->query('SELECT name, value FROM settings')->fetchAll();
        $values = self::DEFAULTS;
        foreach ($rows as $row) {
            $values[(string) $row['name']] = (string) $row['value'];
        }

        return self::$cache = $values;
    }

    public function set(string $name, string $value): void
    {
        Database::pdo()->prepare(
            'INSERT INTO settings (name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        )->execute([$name, $value]);

        self::$cache = null;
    }

    public function quietHours(): bool
    {
        return $this->all()['quiet_hours'] === '1';
    }

    public function quietFrom(): string
    {
        return substr($this->all()['quiet_from'], 0, 5);
    }

    public function quietTo(): string
    {
        return substr($this->all()['quiet_to'], 0, 5);
    }

    public function toggleQuietHours(): bool
    {
        $on = !$this->quietHours();
        $this->set('quiet_hours', $on ? '1' : '0');

        return $on;
    }

    // ------------------------------------------------- screen unknown callers

    /**
     * Whether an unrecognised caller is offered the house mailbox.
     *
     * The refusal itself is not optional — an unknown number never rings a
     * child's phone — but what happens after it is. With this on, the caller
     * hears the household's words and can leave a message, which the dashboard
     * lists until a grown-up decides about it. With it off, the line hangs up
     * the moment the recording has played, exactly as it always did.
     *
     * Read by the generated dialplan, so it is a setting rather than a session
     * flag: nothing in a browser can decide whether a caller gets to speak.
     */
    public function takesUnknownMessages(): bool
    {
        return $this->all()['screen_unknown'] === '1';
    }

    public function setTakesUnknownMessages(bool $on): void
    {
        $this->set('screen_unknown', $on ? '1' : '0');
    }

    // -------------------------------------------------------- bedtime message

    /**
     * Filename of the household's "we can't take your call right now" clip.
     *
     * A name rather than a path: the dialplan and the browser player both
     * re-derive the path from the store, so a garbled row cannot point
     * anywhere else on the disk.
     */
    public function quietMessage(): ?string
    {
        $name = trim($this->all()['quiet_message']);

        return $name === '' ? null : $name;
    }

    public function quietMessageSeconds(): int
    {
        return max(0, (int) $this->all()['quiet_message_seconds']);
    }

    /**
     * Remember a new recording, or forget the old one when $file is null.
     *
     * The length is kept beside the name because the dashboard shows it back:
     * "12 seconds · recorded for bedtime" is how a parent recognises the clip
     * they meant without playing it.
     */
    public function setQuietMessage(?string $file, int $seconds = 0): void
    {
        $this->set('quiet_message', (string) $file);
        $this->set('quiet_message_seconds', $file === null ? '0' : (string) max(0, $seconds));
    }

    // ------------------------------------------------------ group call prompt

    /** Filename of the house's "press 1 to join" clip — see GroupPromptStore. */
    public function groupPrompt(): ?string
    {
        $name = trim($this->all()['group_prompt']);

        return $name === '' ? null : $name;
    }

    public function groupPromptSeconds(): int
    {
        return max(0, (int) $this->all()['group_prompt_seconds']);
    }

    public function setGroupPrompt(?string $file, int $seconds = 0): void
    {
        $this->set('group_prompt', (string) $file);
        $this->set('group_prompt_seconds', $file === null ? '0' : (string) max(0, $seconds));
    }

    // ----------------------------------------------------------- joke line

    public function jokeNumber(): string
    {
        $value = trim($this->all()['joke_number']);

        // Never hand the dialplan something that isn't a number: this string
        // is written straight into an extension.
        return preg_match('/^\d{2,4}$/', $value) === 1 ? $value : '258';
    }

    public function setJokeNumber(string $number): void
    {
        $this->set('joke_number', $number);
    }

    // ------------------------------------------------------- times tables

    public function quizNumber(): string
    {
        $value = trim((string) ($this->all()['quiz_number'] ?? ''));

        // Written straight into an extension, like the joke line's.
        return preg_match('/^\d{2,4}$/', $value) === 1 ? $value : '246';
    }

    public function setQuizNumber(string $number): void
    {
        $this->set('quiz_number', $number);
    }

    /** The tables in a mix, 2 to 12 — every one when none are chosen. @return array<int,int> */
    public function quizTables(): array
    {
        $tables = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) ($this->all()['quiz_tables'] ?? ''))),
            static fn(int $n): bool => $n >= 2 && $n <= 12
        )));
        sort($tables);

        return $tables === [] ? range(2, 12) : $tables;
    }

    /** @param array<int,int|string> $tables */
    public function setQuizTables(array $tables): void
    {
        $tables = array_filter(array_map('intval', $tables), static fn(int $n): bool => $n >= 2 && $n <= 12);
        sort($tables);
        $this->set('quiz_tables', implode(',', array_unique($tables)));
    }

    public const QUIZ_LENGTHS = [5, 10, 20];

    public function quizQuestions(): int
    {
        $n = (int) ($this->all()['quiz_questions'] ?? 10);

        return in_array($n, self::QUIZ_LENGTHS, true) ? $n : 10;
    }

    public function setQuizQuestions(int $n): void
    {
        $this->set('quiz_questions', (string) (in_array($n, self::QUIZ_LENGTHS, true) ? $n : 10));
    }

    public function gamesNumber(): string
    {
        $value = trim((string) ($this->all()['games_number'] ?? ''));

        return preg_match('/^\d{2,4}$/', $value) === 1 ? $value : '4263';
    }

    public function setGamesNumber(string $number): void
    {
        $this->set('games_number', $number);
    }

    public const SUMS_LIMITS = [10, 20];

    /** Sums go up to this: 10 or 20. */
    public function sumsMax(): int
    {
        $n = (int) ($this->all()['sums_max'] ?? 10);

        return in_array($n, self::SUMS_LIMITS, true) ? $n : 10;
    }

    public function setSumsMax(int $n): void
    {
        $this->set('sums_max', (string) (in_array($n, self::SUMS_LIMITS, true) ? $n : 10));
    }

    /** Number bonds make these: 10, 20, or both. @return array<int,int> */
    public function bondsTo(): array
    {
        $to = array_values(array_intersect([10, 20], array_map('intval', explode(',', (string) ($this->all()['bonds_to'] ?? '10')))));

        return $to === [] ? [10] : $to;
    }

    /** @param array<int,int|string> $to */
    public function setBondsTo(array $to): void
    {
        $to = array_values(array_intersect([10, 20], array_map('intval', $to)));
        $this->set('bonds_to', implode(',', $to === [] ? [10] : $to));
    }

    // -------------------------------------------------------------- Christmas

    public function sleepsNumber(): string
    {
        $value = trim((string) ($this->all()['sleeps_number'] ?? ''));

        return preg_match('/^\d{2,4}$/', $value) === 1 ? $value : '1225';
    }

    public function setSleepsNumber(string $number): void
    {
        $this->set('sleeps_number', $number);
    }

    public function radioNumber(): string
    {
        $value = trim((string) ($this->all()['radio_number'] ?? ''));

        return preg_match('/^\d{2,4}$/', $value) === 1 ? $value : '7234';
    }

    public function setRadioNumber(string $number): void
    {
        $this->set('radio_number', $number);
    }

    public function radioShuffle(): bool
    {
        return ($this->all()['radio_shuffle'] ?? '1') !== '0';
    }

    public function setRadioShuffle(bool $on): void
    {
        $this->set('radio_shuffle', $on ? '1' : '0');
    }

    /** What the radio does at bedtime — a key of Radio::BEDTIME. */
    public function radioBedtime(): string
    {
        $value = (string) ($this->all()['radio_bedtime'] ?? 'normal');

        return isset(Radio::BEDTIME[$value]) ? $value : 'normal';
    }

    /** Minutes the radio plays for at bedtime before it says night night; 0 for no timer. */
    public function radioSleepMinutes(): int
    {
        $n = (int) ($this->all()['radio_sleep_minutes'] ?? 0);

        return in_array($n, Radio::SLEEP_MINUTES, true) ? $n : 0;
    }

    public function setRadioBedtime(string $mode, int $minutes): void
    {
        $this->set('radio_bedtime', isset(Radio::BEDTIME[$mode]) ? $mode : 'normal');
        $this->set('radio_sleep_minutes', (string) (in_array($minutes, Radio::SLEEP_MINUTES, true) ? $minutes : 0));
    }

    public function clockNumber(): string
    {
        $value = trim((string) ($this->all()['clock_number'] ?? ''));

        return preg_match('/^\d{2,4}$/', $value) === 1 ? $value : '8463';
    }

    public function timerNumber(): string
    {
        $value = trim((string) ($this->all()['timer_number'] ?? ''));

        return preg_match('/^\d{2,4}$/', $value) === 1 ? $value : '2463';
    }

    public function setClockNumber(string $number): void
    {
        $this->set('clock_number', $number);
    }

    public function setTimerNumber(string $number): void
    {
        $this->set('timer_number', $number);
    }

    public function sillyNumber(): string
    {
        $value = trim((string) ($this->all()['silly_number'] ?? ''));

        return preg_match('/^\d{2,4}$/', $value) === 1 ? $value : '7455';
    }

    public function walkieNumber(): string
    {
        $value = trim((string) ($this->all()['walkie_number'] ?? ''));

        return preg_match('/^\d{2,4}$/', $value) === 1 ? $value : '9255';
    }

    public function setSillyNumber(string $number): void
    {
        $this->set('silly_number', $number);
    }

    public function setWalkieNumber(string $number): void
    {
        $this->set('walkie_number', $number);
    }

    public function sillyNumberProblem(string $number): ?string
    {
        return $this->serviceLineProblem(trim($number), 'silly');
    }

    public function walkieNumberProblem(string $number): ?string
    {
        return $this->serviceLineProblem(trim($number), 'walkie');
    }

    public function clockNumberProblem(string $number): ?string
    {
        return $this->serviceLineProblem(trim($number), 'clock');
    }

    public function timerNumberProblem(string $number): ?string
    {
        return $this->serviceLineProblem(trim($number), 'timer');
    }

    /** Why the radio can't move here — or null if it can. */
    public function radioNumberProblem(string $number): ?string
    {
        return $this->serviceLineProblem(trim($number), 'radio');
    }

    /** Why the Christmas countdown can't move here — or null if it can. */
    public function sleepsNumberProblem(string $number): ?string
    {
        return $this->serviceLineProblem(trim($number), 'sleeps');
    }

    /** The household's own Santa message (a SantaStore name), or null for the built-in one. */
    public function santaMessage(): ?string
    {
        $name = trim((string) ($this->all()['santa_message'] ?? ''));

        return $name === '' ? null : $name;
    }

    public function santaMessageSeconds(): int
    {
        return max(0, (int) ($this->all()['santa_message_seconds'] ?? 0));
    }

    public function setSantaMessage(?string $file, int $seconds = 0): void
    {
        $this->set('santa_message', (string) $file);
        $this->set('santa_message_seconds', $file === null ? '0' : (string) max(0, $seconds));
    }

    public function santaRings(): bool
    {
        return ($this->all()['santa_ring'] ?? '0') === '1';
    }

    /** When Santa rings on Christmas morning, HH:MM. */
    public function santaRingTime(): string
    {
        $value = (string) ($this->all()['santa_ring_time'] ?? '');

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : '08:00';
    }

    public function setSantaRing(bool $on, string $time): void
    {
        $this->set('santa_ring', $on ? '1' : '0');
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
            $this->set('santa_ring_time', $time);
        }
    }

    public function santaRangYear(): string
    {
        return (string) ($this->all()['santa_rang_year'] ?? '');
    }

    public function setSantaRangYear(string $year): void
    {
        $this->set('santa_rang_year', $year);
    }

    /** Why the quiz can't move here — or null if it can. The joke line's checks. */
    public function quizNumberProblem(string $number): ?string
    {
        return $this->serviceLineProblem(trim($number), 'quiz');
    }

    /** Why the games line can't move here — or null if it can. */
    public function gamesNumberProblem(string $number): ?string
    {
        return $this->serviceLineProblem(trim($number), 'games');
    }

    /**
     * The joke line, the quiz and the games line: none may be on another's
     * number, nor on anything else a child dials (lineNumberProblem()).
     */
    private function serviceLineProblem(string $number, string $line): ?string
    {
        $lines = [
            'joke' => [$this->jokeNumber(), 'the joke line'],
            'quiz' => [$this->quizNumber(), 'the times tables quiz'],
            'games' => [$this->gamesNumber(), 'the games line'],
            'sleeps' => [$this->sleepsNumber(), 'the Christmas countdown'],
            'radio' => [$this->radioNumber(), 'the radio'],
            'clock' => [$this->clockNumber(), '"what time is it?"'],
            'timer' => [$this->timerNumber(), 'the kitchen timer'],
            'silly' => [$this->sillyNumber(), 'the silly voice line'],
            'walkie' => [$this->walkieNumber(), 'the walkie-talkie'],
        ];
        if ($number === $lines[$line][0]) {
            return null;
        }
        foreach ($lines as $key => [$taken, $label]) {
            if ($key !== $line && $number === $taken) {
                return $number . ' is ' . $label . '.';
            }
        }

        return $this->lineNumberProblem($number);
    }

    // ------------------------------------------------------------ voicemail

    /** The speed dial for "your messages" (as well as 700), or '' for none. */
    public function voicemailSpeedDial(): string
    {
        $value = trim((string) ($this->all()['voicemail_speed_dial'] ?? ''));

        // Written straight into an extension: digits only, or nothing.
        return preg_match('/^\d{1,4}$/', $value) === 1 ? $value : '';
    }

    public function setVoicemailSpeedDial(string $code): void
    {
        $this->set('voicemail_speed_dial', $code);
    }

    /**
     * Why "your messages" can't have this speed dial — or null if it can. The
     * same checks as a person's speed dial: nothing a child already dials.
     */
    public function voicemailSpeedDialProblem(string $code): ?string
    {
        $code = trim($code);
        if ($code === '') {
            return null; // none: fine
        }
        if (preg_match('/^\d{1,4}$/', $code) !== 1) {
            return 'Use 1 to 4 digits, like 1.';
        }
        if ($code === PjsipConfig::VOICEMAIL_NUMBER || $code === $this->voicemailSpeedDial()) {
            return null;
        }

        return (new ContactRepository())->speedDialProblem($code);
    }

    // ---------------------------------------------------------- provisioning

    /** The HTTP-basic password a Grandstream phone uses to fetch its config. */
    public function provisionPass(): string
    {
        $pass = $this->all()['provision_pass'];
        if ($pass === '') {
            $pass = bin2hex(random_bytes(12));
            $this->set('provision_pass', $pass);
        }

        return $pass;
    }

    /**
     * Why the joke line can't move here — or null if it can.
     *
     * Everything a child might dial has to stay reachable, so this checks the
     * lot: emergency and service numbers, the other twocans numbers, the
     * handsets' own extensions, and the speed dials already taught to a child.
     */
    public function jokeNumberProblem(string $number): ?string
    {
        $number = trim($number);
        // Not its own number: unlike the others, saving the joke line's number
        // unchanged has always been checked like a new one.
        foreach ([[$this->quizNumber(), 'the times tables quiz'], [$this->gamesNumber(), 'the games line'],
                  [$this->sleepsNumber(), 'the Christmas countdown'], [$this->radioNumber(), 'the radio'],
                  [$this->clockNumber(), '"what time is it?"'], [$this->timerNumber(), 'the kitchen timer'],
                  [$this->sillyNumber(), 'the silly voice line'], [$this->walkieNumber(), 'the walkie-talkie']] as [$taken, $label]) {
            if ($number === $taken) {
                return $number . ' is ' . $label . '.';
            }
        }

        return $this->lineNumberProblem($number);
    }

    /** What the joke line and the quiz can't be on — see jokeNumberProblem(). */
    private function lineNumberProblem(string $number): ?string
    {
        if (preg_match('/^\d{2,4}$/', $number) !== 1) {
            return 'Use 2 to 4 digits, like 258.';
        }
        if (in_array($number, ContactRepository::RESERVED_NUMBERS, true)) {
            return $number . ' is an emergency or service number and can never be used.';
        }
        if (in_array($number, PjsipConfig::FIXED_SERVICE_NUMBERS, true)) {
            $fixed = PjsipConfig::testNumbers();

            return $number . ' is already used by twocans for '
                . strtolower($fixed[$number]['label'] ?? 'something else') . '.';
        }

        if ($number === $this->voicemailSpeedDial()) {
            return $number . ' is the speed dial for your messages.';
        }

        $device = Database::pdo()->prepare('SELECT display_name FROM devices WHERE extension = ?');
        $device->execute([$number]);
        if (($name = $device->fetchColumn()) !== false) {
            return $number . ' is ' . $name . "'s extension.";
        }

        $contact = Database::pdo()->prepare('SELECT name FROM contacts WHERE speed_dial = ?');
        $contact->execute([$number]);
        if (($name = $contact->fetchColumn()) !== false) {
            return $number . ' is the speed dial for ' . $name . '.';
        }

        return null;
    }

    // ------------------------------------------------------------ retention

    /** Days to keep recordings and transcripts; 0 means forever. */
    public function retentionDays(): int
    {
        return max(0, (int) $this->all()['retention_days']);
    }

    public function setRetentionDays(int $days): void
    {
        // Only ever one of the offered windows, so a hand-edited form can't
        // set something like "keep for 1 day" that quietly eats everything.
        $days = isset(self::RETENTION_CHOICES[(string) $days]) ? $days : 90;
        $this->set('retention_days', (string) $days);
    }

    /** How the chosen window reads in a sentence. */
    public function retentionLabel(): string
    {
        return self::RETENTION_CHOICES[(string) $this->retentionDays()] ?? '90 days';
    }

    public function lastSweep(): ?int
    {
        $value = $this->all()['retention_last_sweep'];

        return $value === '' ? null : (int) $value;
    }

    public function markSwept(): void
    {
        $this->set('retention_last_sweep', (string) time());
    }

    /**
     * Bedtime by day — see Schedule. Until somebody sets one, the old single
     * from–until pair every night.
     *
     * @return array<int,array>
     */
    public function quietRules(): array
    {
        return Schedule::fromJson($this->all()['quiet_schedule'], $this->quietFrom(), $this->quietTo());
    }

    public function setQuietRules(array $rules): void
    {
        $this->set('quiet_schedule', Schedule::toJson($rules));
    }

    /**
     * Bedtime as GotoIfTime conditions; the line is asleep when any matches.
     * A night past midnight is split onto the next morning — see Schedule.
     *
     * @return array<int,string>
     */
    public function quietTimeRange(): array
    {
        return Schedule::conditions($this->quietRules());
    }

    /** The shape the existing views expect. */
    public function toView(): array
    {
        return [
            'quietHours' => $this->quietHours(),
            'quietFrom' => $this->quietFrom(),
            'quietTo' => $this->quietTo(),
            'quietRules' => $this->quietRules(),
            // '' when there is no recording, so a view can test it the way it
            // tests the other strings here.
            'quietMessage' => (string) $this->quietMessage(),
            'quietMessageSeconds' => $this->quietMessageSeconds(),
            // The dashboard's screening switch reads this, so the switch and
            // what the dialplan does can never disagree.
            'screenUnknown' => $this->takesUnknownMessages(),
        ];
    }
}
