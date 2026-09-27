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
