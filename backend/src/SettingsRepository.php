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
        'quiet_to' => '07:00',
        // How long recordings and transcripts are kept, in days. 0 means keep
        // them forever. A fresh install gets 90; an install that already had
        // call history when retention arrived is pinned to 0 by migration 016,
        // so nobody's recordings vanish because they upgraded.
        'retention_days' => '90',
        'retention_last_sweep' => '',
        // The joke line. Changeable, because it has to fit around whatever
        // numbers a household has already taught its children.
        'joke_number' => '258',
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
     * Quiet hours as a GotoIfTime range.
     *
     * Bedtime normally wraps midnight (19:30 to 07:00); Asterisk handles a
     * range whose end is before its start, so no special casing is needed.
     */
    public function quietTimeRange(): string
    {
        return $this->quietFrom() . '-' . $this->quietTo() . ',*,*,*';
    }

    /** The shape the existing views expect. */
    public function toView(): array
    {
        return [
            'quietHours' => $this->quietHours(),
            'quietFrom' => $this->quietFrom(),
            'quietTo' => $this->quietTo(),
        ];
    }
}
