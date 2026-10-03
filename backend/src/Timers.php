<?php
declare(strict_types=1);

/**
 * The kitchen timer — a number a child dials (2463, C-H-I-M-E, by default),
 * types the minutes and hangs up; the phone rings when they're up. One timer
 * a phone: setting another replaces it, 0 cancels it.
 *
 * Asterisk does the waiting: the dialplan drops a call file, dated for when
 * it's due, into its outgoing spool, and pbx_spool rings the phone at that
 * second (PjsipConfig::renderTimer). It notes each one in AstDB (FAMILY), so
 * this can list them, and cancel one through the dialplan.
 */
final class Timers
{
    public const FAMILY = 'tc_timer';

    /** Longest a timer can be set for, in minutes. */
    public const MAX_MINUTES = 180;

    /**
     * Timers running now: phone name, when due, the endpoint.
     *
     * @return array<int,array{endpoint:string,phone:string,due:int}>
     */
    public function running(): array
    {
        try {
            $ami = new Ami();
            $ami->connect();
            $lines = $ami->command('database show ' . self::FAMILY);
            $ami->disconnect();
        } catch (Throwable) {
            return [];
        }
        $names = [];
        foreach ((new DeviceRepository())->all() as $row) {
            $names[(string) $row['sip_username']] = (string) $row['name'];
        }
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('#^/' . self::FAMILY . '/(\S+)\s*:\s*(\d+)#', trim((string) $line), $m) && (int) $m[2] > time()) {
                $out[] = ['endpoint' => $m[1], 'phone' => $names[$m[1]] ?? $m[1], 'due' => (int) $m[2]];
            }
        }
        usort($out, static fn(array $a, array $b): int => $a['due'] <=> $b['due']);

        return $out;
    }

    /** Cancel a phone's timer, through the dialplan, which owns the spool. */
    public function cancel(string $endpoint): bool
    {
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $endpoint) !== 1) {
            return false;
        }
        try {
            $ami = new Ami();
            $ami->connect();
            $reply = $ami->send('Originate', [
                'Channel' => 'Local/cancel@' . PjsipConfig::TIMER_CONTEXT,
                'Application' => 'Wait',
                'Data' => '1',
                'Variable' => 'TIMER_PHONE=' . $endpoint,
                'Async' => 'true',
            ]);
            $ami->disconnect();
        } catch (Throwable) {
            return false;
        }

        return ($reply['response'] ?? '') === 'Success';
    }
}
