<?php
declare(strict_types=1);

/**
 * Pausing a phone for a while: no calls in or out, no fun lines, then it
 * lets itself go — against the database, in a transaction rolled back after.
 */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        SettingsRepository::forget();
        $fn();
    } finally {
        $pdo->rollBack();
        SettingsRepository::forget();
    }
};

$endpoint = static function (array $files, array $phone): string {
    $conf = $files['pjsip-devices.conf'];
    $start = strpos($conf, '[' . $phone['sip_username'] . ']');

    return substr($conf, $start, strpos($conf, 'type = aor', $start) - $start);
};

return [
    test('a paused phone can\'t call out, and the fun lines turn it away', function () use ($fresh, $endpoint) {
        $fresh(function () use ($endpoint) {
            $devices = new DeviceRepository();
            $phone = $devices->create('Test bedroom', 'ghp621', 'udp');
            $render = static fn(): array => (new PjsipConfig($devices))->render();
            assertFalse(str_contains($endpoint($render(), $phone), 'TC_PAUSED'));

            $devices->pause((int) $phone['id'], time() + 3600);
            $files = $render();
            assertContains('set_var = TC_NOOUT=1', $endpoint($files, $phone));
            assertContains('set_var = TC_PAUSED=1', $endpoint($files, $phone));
            // The joke line, like every fun line, checks first.
            $joke = (new SettingsRepository())->jokeNumber();
            assertContains("exten => {$joke},1,NoOp(twocans: joke line", $files['dialplan-devices.conf']);
            assertContains('[' . PjsipConfig::PAUSED_CONTEXT . ']', $files['dialplan-devices.conf']);
            assertContains('Paused until', Presenter::device(DeviceRepository::toView($devices->find((int) $phone['id'])))['ruleSummary']);
        });
    }),
    test('a pause lets itself go when its time comes', function () use ($fresh, $endpoint) {
        $fresh(function () use ($endpoint) {
            $devices = new DeviceRepository();
            $phone = $devices->create('Test bedroom', 'ghp621', 'udp');
            $devices->pause((int) $phone['id'], time() - 60);
            // Past its time, it isn't paused — even before the minute loop tidies up.
            assertSame(null, DeviceRepository::toView($devices->find((int) $phone['id']))['pausedUntil']);
            assertFalse(str_contains($endpoint((new PjsipConfig($devices))->render(), $phone), 'TC_PAUSED'));
            assertTrue($devices->endPauses() >= 1);
            assertSame(0, $devices->endPauses(), 'nothing left to let go');
        });
    }),
];
