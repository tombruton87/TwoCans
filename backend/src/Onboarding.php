<?php
declare(strict_types=1);

/**
 * The Getting started guide: the few steps between a fresh install and a line
 * the family can use. Each is ticked from what's really there — a phone that
 * has signed in, someone on the call list, a connected line, a call that got
 * through — so it can't fall out of step with the app.
 *
 * Settings:
 *   onboarding          '' (new: shown, with a reminder on the dashboard),
 *                       'later' (put off: only in the menu) or 'done'
 *                       (finished, or hidden: gone from the menu, and back
 *                       only from System)
 *   onboarding_skipped  steps passed over, comma-separated (only the line can be)
 *   onboarding_tested   '1' once a test call has rung a phone
 */
final class Onboarding
{
    public function __construct(private SettingsRepository $settings = new SettingsRepository())
    {
    }

    public function state(): string
    {
        return (string) ($this->settings->all()['onboarding'] ?? '');
    }

    public function setState(string $state): void
    {
        $this->settings->set('onboarding', in_array($state, ['', 'later', 'done'], true) ? $state : '');
    }

    /** @return array<int,string> */
    public function skipped(): array
    {
        return array_values(array_filter(explode(',', (string) ($this->settings->all()['onboarding_skipped'] ?? ''))));
    }

    public function skip(string $step, bool $skip = true): void
    {
        $skipped = array_diff($this->skipped(), [$step]);
        if ($skip && $step === 'line') {
            $skipped[] = $step;
        }
        $this->settings->set('onboarding_skipped', implode(',', $skipped));
    }

    public function markTested(): void
    {
        $this->settings->set('onboarding_tested', '1');
    }

    /**
     * The steps, in order, each with whether it's done (or skipped).
     *
     * @return array<int,array{key:string,done:bool,skipped:bool,waiting:bool}>
     */
    public function steps(): array
    {
        $pdo = Database::pdo();
        $phones = (int) $pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();
        $signedIn = (int) $pdo->query('SELECT COUNT(*) FROM devices WHERE registered = 1')->fetchColumn();
        // With a number: "Add a person" makes a blank one before it's filled in.
        $people = (int) $pdo->query(
            "SELECT COUNT(*) FROM contacts WHERE is_group = 0 AND number_e164 IS NOT NULL AND number_e164 <> ''"
        )->fetchColumn();
        $line = (new TrunkRepository())->get()['connected'];
        $called = ($this->settings->all()['onboarding_tested'] ?? '') === '1'
            || (int) $pdo->query("SELECT COUNT(*) FROM calls WHERE status = 'done'")->fetchColumn() > 0;
        $skipped = $this->skipped();

        return [
            ['key' => 'phone', 'done' => $signedIn > 0, 'skipped' => false, 'waiting' => $phones > 0 && $signedIn === 0],
            ['key' => 'people', 'done' => $people > 0, 'skipped' => false, 'waiting' => false],
            ['key' => 'line', 'done' => $line, 'skipped' => !$line && in_array('line', $skipped, true), 'waiting' => false],
            ['key' => 'test', 'done' => $called, 'skipped' => false, 'waiting' => false],
        ];
    }

    /** @return array{done:int,total:int} steps done or skipped, of all */
    public function progress(): array
    {
        $steps = $this->steps();

        return [
            'done' => count(array_filter($steps, static fn(array $s): bool => $s['done'] || $s['skipped'])),
            'total' => count($steps),
        ];
    }

    public function complete(): bool
    {
        $p = $this->progress();

        return $p['done'] === $p['total'];
    }

    /**
     * Whether it's in the menu at all: not once it's finished, or hidden from
     * its own page. Every step done counts as finished, pressed or not.
     */
    public function visible(): bool
    {
        return $this->state() !== 'done' && !$this->complete();
    }

    /** Whether the dashboard reminds them: new, and not everything done. */
    public function remind(): bool
    {
        return $this->state() === '' && !$this->complete();
    }
}
