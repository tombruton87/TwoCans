<?php
declare(strict_types=1);

/**
 * Voicemail: how long each phone rings first, and a speed dial for messages —
 * against the database, in a transaction rolled back after.
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

return [
    test('a phone rings five times (30 seconds) until it is set', function () use ($fresh) {
        $fresh(function () {
            $devices = new DeviceRepository();
            $id = (int) $devices->create('Test landing', 'ghp621', 'udp')['id'];
            $d = DeviceRepository::toView($devices->find($id));
            assertSame(5, $d['rings']);
            assertSame(30, $d['ringSeconds']);

            $devices->setRings($id, 3);
            assertSame(18, DeviceRepository::toView($devices->find($id))['ringSeconds']);
            $devices->setRings($id, 7); // not a choice
            assertSame(30, DeviceRepository::toView($devices->find($id))['ringSeconds']);
        });
    }),
    test('the dialplan rings each phone for its own time, and a house call for the longest', function () use ($fresh) {
        $fresh(function () {
            $devices = new DeviceRepository();
            $phone = $devices->create('Test landing', 'ghp621', 'udp');
            $devices->setRings((int) $phone['id'], 8);
            $plan = (new PjsipConfig($devices))->render()['dialplan-devices.conf'];
            assertContains('Dial(PJSIP/' . $phone['sip_username'] . ',48)', $plan);
            assertContains('Set(RINGSECS=${IF($[${RINGSECS} < 48]?48:${RINGSECS})})', $plan);
        });
    }),
    test('a speed dial for messages goes to 700, and can\'t take a number already used', function () use ($fresh) {
        $fresh(function () {
            $settings = new SettingsRepository();
            assertSame(null, $settings->voicemailSpeedDialProblem(''));
            assertTrue($settings->voicemailSpeedDialProblem('999') !== null, 'emergency');
            assertTrue($settings->voicemailSpeedDialProblem('600') !== null, 'echo test');
            assertTrue($settings->voicemailSpeedDialProblem('12345') !== null, 'too long');

            $free = '';
            foreach (range(1, 9999) as $n) {
                if ($settings->voicemailSpeedDialProblem((string) $n) === null) {
                    $free = (string) $n;
                    break;
                }
            }
            $settings->setVoicemailSpeedDial($free);
            SettingsRepository::forget();
            assertSame($free, (new SettingsRepository())->voicemailSpeedDial());
            assertSame('Your messages', PjsipConfig::testNumbers()[$free]['label'] ?? null);
            assertContains("exten => {$free},1,Goto(700,1)", (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf']);
            assertTrue((new ContactRepository())->speedDialProblem($free) !== null, 'nobody else can have it now');
        });
    }),
];
