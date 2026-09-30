<?php
declare(strict_types=1);

/**
 * Which of the line's numbers a call goes out from — against the database, in
 * a transaction rolled back after.
 */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        // A line with two numbers of its own.
        $pdo->exec("INSERT INTO trunk (id, provider, connected, number_e164, extra_numbers)
                    VALUES (1, 'Twilio', 1, '+447700900001', '+447700900002')
                    ON DUPLICATE KEY UPDATE connected = 1, number_e164 = '+447700900001',
                        extra_numbers = '+447700900002', outgoing_number = NULL");
        $pdo->exec('DELETE FROM trunk_number_rings');
        $fn(new TrunkRepository(), (int) (new DeviceRepository())->create('Test landing', 'ghp621', 'udp')['id']);
    } finally {
        $pdo->rollBack();
    }
};

return [
    test('the line calls out from its first number, or the one chosen', function () use ($fresh) {
        $fresh(function (TrunkRepository $t, int $phone) {
            assertSame('+447700900001', $t->get()['outgoing']);
            $t->setOutgoing('+447700900002');
            assertSame('+447700900002', $t->get()['outgoing']);
            $t->setOutgoing('+447700900999'); // not the line's
            assertSame('+447700900001', $t->get()['outgoing']);
        });
    }),
    test('a phone calls out from its own number by itself, the line\'s otherwise', function () use ($fresh) {
        $fresh(function (TrunkRepository $t, int $phone) {
            assertSame(null, $t->outgoingNumberFor($phone), 'nothing of its own: the line\'s');
            $t->setRingDevice('+447700900002', $phone);
            assertSame('+447700900002', $t->outgoingNumberFor($phone), 'the number pointed at it');
        });
    }),
    test('a phone\'s own choice wins, and a number off the line is ignored', function () use ($fresh) {
        $fresh(function (TrunkRepository $t, int $phone) {
            $t->setRingDevice('+447700900002', $phone);
            $t->setDeviceOutgoing($phone, '+447700900001');
            assertSame('+447700900001', $t->outgoingNumberFor($phone));
            $t->setDeviceOutgoing($phone, '+447700900999');
            assertSame('+447700900002', $t->outgoingNumberFor($phone), 'back to automatic');
        });
    }),
    test('messages go to the phone a number rings, else the house — unless chosen', function () use ($fresh) {
        $fresh(function (TrunkRepository $t, int $phone) {
            $ext = (string) (new DeviceRepository())->find($phone)['extension'];
            assertSame('100', $t->mailboxFor('+447700900001')['mailbox'], 'rings every phone: the house');
            $t->setRingDevice('+447700900002', $phone);
            assertSame($ext, $t->mailboxFor('+447700900002')['mailbox'], 'rings one phone: its mailbox');
            assertTrue($t->mailboxFor('+447700900002')['auto']);

            $t->setMailbox('+447700900002', 'house');
            assertSame('100', $t->mailboxFor('+447700900002')['mailbox']);
            $t->setMailbox('+447700900001', (string) $phone);
            assertSame($ext, $t->mailboxFor('+447700900001')['mailbox']);
            $t->setMailbox('+447700900001', '');
            assertSame('100', $t->mailboxFor('+447700900001')['mailbox'], 'back to automatic');

            $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
            assertContains('Set(MBOX=100)', $plan);
            assertContains('VoiceMail(${MBOX}@twocans', $plan);
        });
    }),
];
