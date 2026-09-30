<?php
declare(strict_types=1);

/**
 * Offering a free hotkey after someone is added, and phones spotted on the
 * network — against the database, in a transaction rolled back after.
 */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        $fn();
    } finally {
        $pdo->rollBack();
    }
};

/** A group with a free speed dial, as a contact row. */
$group = static function (ContactRepository $contacts): array {
    $id = $contacts->create();
    foreach (range(8600, 8699) as $n) {
        if ($contacts->speedDialProblem((string) $n) === null) {
            $contacts->save($id, ['name' => 'Cousins', 'isGroup' => 1, 'code' => (string) $n, 'window' => 'anytime']);
            break;
        }
    }

    return $contacts->find($id);
};

return [
    test('someone on no key is offered a desk phone\'s first free key, and then isn\'t', function () use ($fresh, $group) {
        $fresh(function () use ($group) {
            $contacts = new ContactRepository();
            $who = $group($contacts);
            $target = DeviceHotkeyRepository::targetOf($who);
            $phone = (new DeviceRepository())->create('Test landing', 'ghp610', 'udp');
            $hotkeys = new DeviceHotkeyRepository();
            $hotkeys->save((int) $phone['id'], [1 => '600']);

            $offer = array_values(array_filter($hotkeys->offersFor($target), static fn(array $o): bool => $o['id'] === (int) $phone['id']));
            assertSame(2, $offer[0]['key'] ?? null);
            assertSame(2, $hotkeys->addToFreeKey((int) $phone['id'], 3, $target));
            assertSame([], $hotkeys->offersFor($target));
            assertSame([1 => '600', 2 => $target], $hotkeys->forDevice((int) $phone['id']));
        });
    }),
    test('a full phone has no free key, and nothing off the list can be put on one', function () use ($fresh) {
        $fresh(function () {
            $phone = (new DeviceRepository())->create('Test landing', 'ghp611', 'udp');
            $hotkeys = new DeviceHotkeyRepository();
            $hotkeys->save((int) $phone['id'], [1 => '600', 2 => '601', 3 => '500']);
            assertSame(null, $hotkeys->freeKey((int) $phone['id'], 3));
            assertSame(null, $hotkeys->addToFreeKey((int) $phone['id'], 3, '600'));
            assertSame([], $hotkeys->offersFor('+447700900999'));
        });
    }),
    test('a spotted phone is offered until it has been added', function () use ($fresh) {
        $fresh(function () {
            $found = new FoundPhones();
            $found->sawFetch('000B82ABCDEF', '192.168.1.77', 'Grandstream Model HW GHP620W SW 1.0.1.37 DevId 000b82abcdef');
            $mine = array_values(array_filter($found->unassigned(), static fn(array $f): bool => $f['mac'] === '000B82ABCDEF'));
            assertSame('ghp620', $mine[0]['type'] ?? null);
            assertSame('GHP620W', $mine[0]['label'] ?? null);

            $devices = new DeviceRepository();
            $phone = $devices->create('Test landing', 'ghp620', 'udp');
            $devices->setMac((int) $phone['id'], '000B82ABCDEF');
            assertSame([], array_filter($found->unassigned(), static fn(array $f): bool => $f['mac'] === '000B82ABCDEF'));
        });
    }),
];
