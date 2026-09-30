<?php
declare(strict_types=1);

/** The GHP621 faceplate card and hotkeys: keys line up with the phone's, and groups can be on them. */

/** A group with a speed dial, and a GHP621, in a transaction rolled back after. */
$withGroup = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        $contacts = new ContactRepository();
        $group = $contacts->create();
        $code = '';
        foreach (range(8700, 8799) as $n) {
            if ($contacts->speedDialProblem((string) $n) === null) {
                $code = (string) $n;
                break;
            }
        }
        assertSame(null, $contacts->save($group, ['name' => 'Cousins', 'isGroup' => 1, 'code' => $code, 'window' => 'anytime']));
        $phone = (new DeviceRepository())->create('Test hall', 'ghp621', 'udp');
        $fn($contacts, $group, $code, (int) $phone['id']);
    } finally {
        $pdo->rollBack();
    }
};

return [
    test('keys sit on the cover\'s centre line, 20.5 mm apart', function () {
        assertSame(Faceplate::WIDTH / 2, Faceplate::KEY_X[2]);
        assertSame(20.5, Faceplate::KEY_X[2] - Faceplate::KEY_X[1]);
        assertSame(20.5, Faceplate::KEY_X[3] - Faceplate::KEY_X[2]);
    }),
    test('the bottom row sits under the top row', function () {
        foreach ([1, 2, 3] as $i) {
            assertSame(Faceplate::keyX($i), Faceplate::keyX($i + 3));
        }
    }),
    test('the cut for the top row fits a key, and stays on the card', function () {
        $tall = Faceplate::CUT_BOTTOM - Faceplate::CUT_TOP;
        assertTrue(abs($tall - (Faceplate::KEY_HEIGHT + 2 * Faceplate::CUT_SPARE)) < 0.01, 'a key\'s height with room to spare');
        assertTrue(Faceplate::KEY_X[1] - Faceplate::KEY_WIDTH / 2 - Faceplate::CUT_SPARE > 0, 'inside the left edge');
        assertTrue(Faceplate::KEY_X[3] + Faceplate::KEY_WIDTH / 2 + Faceplate::CUT_SPARE < Faceplate::WIDTH, 'inside the right edge');
        assertTrue(Faceplate::CUT_BOTTOM < Faceplate::HEIGHT, 'above the bottom edge');
    }),
    test('a GHP61x label has its three keys', function () {
        assertSame([1, 2, 3], array_keys(Faceplate::keys([], 3)));
        assertSame(6, count(Faceplate::keys([])));
    }),
    test('an unset key is blank; a service number is named', function () {
        $keys = Faceplate::keys([2 => '600']);
        assertSame(6, count($keys));
        assertSame('', $keys[1]['name']);
        assertSame('Echo test', $keys[2]['name']);
        assertSame('fa-solid fa-microphone', $keys[2]['icon']);
    }),
    test('a group goes on a key by its speed dial, and is labelled and pictured', function () use ($withGroup) {
        $withGroup(function (ContactRepository $contacts, int $group, string $code, int $phone) {
            $hotkeys = new DeviceHotkeyRepository();
            $hotkeys->save($phone, [1 => $code]);
            assertSame([1 => $code], $hotkeys->forDevice($phone));
            assertSame('Cousins', $hotkeys->labels()[$code] ?? null);
            $key = Faceplate::keys($hotkeys->forDevice($phone))[1];
            assertSame('Cousins', $key['name']);
            assertSame('fa-solid fa-users', $key['icon']);
        });
    }),
    test('a key follows a group to its new speed dial, and clears when it has none', function () use ($withGroup) {
        $withGroup(function (ContactRepository $contacts, int $group, string $code, int $phone) {
            $hotkeys = new DeviceHotkeyRepository();
            $hotkeys->save($phone, [2 => $code]);
            $moved = '';
            foreach (range(8800, 8899) as $n) {
                if ($contacts->speedDialProblem((string) $n) === null) {
                    $moved = (string) $n;
                    break;
                }
            }
            $contacts->save($group, ['name' => 'Cousins', 'isGroup' => 1, 'code' => $moved, 'window' => 'anytime']);
            assertSame([2 => $moved], $hotkeys->forDevice($phone));
            $contacts->save($group, ['name' => 'Cousins', 'isGroup' => 1, 'code' => '', 'window' => 'anytime']);
            assertSame([], $hotkeys->forDevice($phone));
        });
    }),
];
