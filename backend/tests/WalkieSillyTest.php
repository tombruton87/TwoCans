<?php
declare(strict_types=1);

/**
 * The walkie-talkie and silly voices — against the database, in a
 * transaction rolled back after.
 */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM settings WHERE name IN ('silly_number', 'walkie_number')");
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
    test('a phone walkie-talkies only to one that answers by itself, and isn\'t paused', function () use ($fresh, $endpoint) {
        $fresh(function () use ($endpoint) {
            $devices = new DeviceRepository();
            $a = $devices->create('Test bedroom', 'ghp621', 'udp');
            $b = $devices->create('Test playroom', 'ghp621', 'udp');
            $app = $devices->create('Test tablet', 'linphone', 'udp');
            $render = static fn(): array => (new PjsipConfig($devices))->render();

            $devices->setWalkie((int) $a['id'], (int) $b['id']);
            $files = $render();
            assertContains('set_var = TC_WALKIE=' . $b['sip_username'], $endpoint($files, $a));
            assertContains('Dial(PJSIP/${TC_WALKIE},20,b(' . PjsipConfig::PAGE_CONTEXT . '^intercom^1)A(beep))', $files['dialplan-devices.conf']);
            assertContains('DEVICE_STATE(PJSIP/${TC_WALKIE})', $files['dialplan-devices.conf']);
            assertContains('exten => 9255,1,NoOp(twocans: the walkie-talkie)', $files['dialplan-devices.conf']);

            $devices->pause((int) $b['id'], time() + 600);
            assertFalse(str_contains($endpoint($render(), $a), 'TC_WALKIE'), 'not to a paused phone');
            $devices->pause((int) $b['id'], null);

            $devices->setWalkie((int) $a['id'], (int) $app['id']);
            assertFalse(str_contains($endpoint($render(), $a), 'TC_WALKIE'), "an app can't answer by itself");

            $devices->setWalkie((int) $a['id'], (int) $a['id']);
            assertSame(null, DeviceRepository::toView($devices->find((int) $a['id']))['walkieTo'], 'not to itself');

            $devices->setWalkie((int) $a['id'], (int) $b['id']);
            $devices->remove((int) $b['id']);
            assertSame(null, DeviceRepository::toView($devices->find((int) $a['id']))['walkieTo'], 'gone with its phone');
        });
    }),
    test('silly voices: high then low, and the recording thrown away', function () use ($fresh) {
        $fresh(function () {
            $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
            assertContains('exten => 7455,1,NoOp(twocans: silly voices)', $plan);
            assertContains('Set(PITCH_SHIFT(tx)=highest)', $plan);
            assertContains('Set(PITCH_SHIFT(tx)=lowest)', $plan);
            assertContains('Set(PITCH_SHIFT(tx)=1.0)', $plan);
            assertContains('exten => h,1,System(rm -f /tmp/twocans-silly-${CUT(UNIQUEID,.,1)}-${CUT(UNIQUEID,.,2)}.wav)', $plan);
        });
    }),
    test('silly voices and the walkie-talkie keep numbers of their own', function () use ($fresh) {
        $fresh(function () {
            $s = new SettingsRepository();
            assertSame(null, $s->sillyNumberProblem('7455'));
            assertSame(null, $s->walkieNumberProblem('9255'));
            assertTrue($s->sillyNumberProblem('9255') !== null);
            assertTrue($s->timerNumberProblem('7455') !== null);
            assertTrue((new ContactRepository())->speedDialProblem('9255') !== null);
        });
    }),
];
