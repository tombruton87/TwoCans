<?php
declare(strict_types=1);

/** Music on hold: the household's own class, and when the phones ask for it. */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        $fn(new HoldMusic());
    } finally {
        $pdo->rollBack();
    }
};

return [
    test('a track is named after the file it came from', function () {
        assertSame('Let It Go', HoldMusic::nameFrom('Let It Go.mp3'));
        assertSame('hold music', HoldMusic::nameFrom('hold_music.m4a'));
        assertSame('A track', HoldMusic::nameFrom('.mp3'));
    }),
    test('with no music of their own there is no class, and the phones ask for none', function () use ($fresh) {
        $fresh(function (HoldMusic $m) {
            Database::pdo()->exec('DELETE FROM hold_music');
            assertFalse($m->hasOwn());
            assertFalse(str_contains($m->render(), '['));
            $pjsip = (new PjsipConfig(new DeviceRepository()))->render()['pjsip-devices.conf'];
            assertFalse(str_contains($pjsip, 'moh_suggest'));
        });
    }),
    test('with a track, the twocans class plays it and every phone asks for it', function () use ($fresh) {
        $fresh(function (HoldMusic $m) {
            $m->add('0123456789abcdef0123456789abcdef.wav', 'Test', 60);
            assertTrue($m->hasOwn());
            assertContains('[twocans]', $m->render());
            assertContains('directory = /var/lib/twocans/refusals/moh', $m->render());
            $pjsip = (new PjsipConfig(new DeviceRepository()))->render()['pjsip-devices.conf'];
            assertContains('moh_suggest = twocans', $pjsip);
        });
    }),
];
