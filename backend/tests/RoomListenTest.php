<?php
declare(strict_types=1);

/**
 * Listening to a room: only between phones switched on for it, one way, with
 * a beep — against the database, in a transaction rolled back after.
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
    test('nothing listens until both phones are switched on for it', function () use ($fresh) {
        $fresh(function () {
            $devices = new DeviceRepository();
            $room = $devices->create('Test bedroom', 'ghp621', 'udp');
            $grownup = $devices->create('Test kitchen', 'ghp621', 'udp');
            $number = PjsipConfig::ROOM_LISTEN_PREFIX . $room['extension'];
            $render = static fn(): array => (new PjsipConfig($devices))->render();

            // Its own endpoint's settings, not the whole file: a real phone
            // in the house may be listening.
            $endpoint = static function (array $files, array $phone): string {
                $conf = $files['pjsip-devices.conf'];
                $start = strpos($conf, '[' . $phone['sip_username'] . ']');

                return substr($conf, $start, strpos($conf, 'type = aor', $start) - $start);
            };
            $files = $render();
            assertFalse(str_contains($files['dialplan-devices.conf'], "exten => {$number},"));
            assertFalse(str_contains($endpoint($files, $grownup), 'TC_ROOMLISTEN'));

            $devices->toggle((int) $room['id'], 'roomListen');
            $devices->toggle((int) $grownup['id'], 'canRoomListen');
            $files = $render();
            $plan = $files['dialplan-devices.conf'];
            assertContains("exten => {$number},1,GotoIf(\$[\"\${TC_ROOMLISTEN}\" = \"1\"]?" . PjsipConfig::PAGE_CONTEXT . ",l{$room['id']},1)", $plan);
            assertContains('set_var = TC_ROOMLISTEN=1', $endpoint($files, $grownup));
            // One way, with a beep, answered by itself, never into a call.
            assertContains('Set(MUTEAUDIO(in)=on)', $plan);
            assertContains("Dial(PJSIP/{$room['sip_username']},20,b(" . PjsipConfig::PAGE_CONTEXT . '^intercom^1)A(beep))', $plan);
            assertContains("DEVICE_STATE(PJSIP/{$room['sip_username']})", $plan);
            assertContains('DB(' . PjsipConfig::ROOM_LISTEN_FAMILY . '/${UNIQUEID})', $plan);
        });
    }),
    test('a phone that can\'t answer by itself is never a room to listen to', function () use ($fresh) {
        $fresh(function () {
            $devices = new DeviceRepository();
            $app = $devices->create('Test tablet', 'linphone', 'udp');
            $devices->toggle((int) $app['id'], 'roomListen');
            assertFalse(DeviceRepository::toView($devices->find((int) $app['id']))['roomListen']);
            $plan = (new PjsipConfig($devices))->render()['dialplan-devices.conf'];
            assertFalse(str_contains($plan, 'exten => l' . $app['id'] . ','));
        });
    }),
    test('the web app won\'t start a listen either phone isn\'t switched on for', function () use ($fresh) {
        $fresh(function () {
            $devices = new DeviceRepository();
            $room = $devices->create('Test bedroom', 'ghp621', 'udp');
            $grownup = $devices->create('Test kitchen', 'ghp621', 'udp');
            $r = (new RoomListen())->start($devices->find((int) $grownup['id']), $devices->find((int) $room['id']));
            assertFalse($r['ok']);
            assertContains("isn't switched on", (string) $r['error']);
            $devices->toggle((int) $room['id'], 'roomListen');
            $r = (new RoomListen())->start($devices->find((int) $grownup['id']), $devices->find((int) $room['id']));
            assertContains("isn't allowed to listen", (string) $r['error']);
        });
    }),
    test('every listen is noted against the room, with who listened', function () use ($fresh) {
        $fresh(function () {
            $devices = new DeviceRepository();
            $room = $devices->create('Test bedroom', 'ghp621', 'udp');
            $grownup = $devices->create('Test kitchen', 'ghp621', 'udp');
            $listens = new RoomListen();
            assertSame(1, $listens->record(['1790000000.5' => $grownup['sip_username'] . '|' . $room['id'] . '|1790000000']));
            assertSame(0, $listens->record(['1790000000.5' => $grownup['sip_username'] . '|' . $room['id'] . '|1790000000']));
            $recent = $listens->recentFor((int) $room['id']);
            assertSame(1, count($recent));
            assertSame('Test kitchen', $recent[0]['listener']);
        });
    }),
];
