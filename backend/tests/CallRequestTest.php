<?php
declare(strict_types=1);

/** Numbers the kids tried: only ones a grown-up could add are listed. */

return [
    test('a real phone number can be asked for; an extension or short code can\'t', function () {
        assertTrue(CallRequestRepository::isRealNumber('+447700900123'));
        assertTrue(CallRequestRepository::isRealNumber('01632 960123'));
        assertFalse(CallRequestRepository::isRealNumber('123'));
        assertFalse(CallRequestRepository::isRealNumber('201'));
        assertFalse(CallRequestRepository::isRealNumber('600'));
        assertFalse(CallRequestRepository::isRealNumber(''));
    }),
    test('short numbers already asked for are left off the list', function () {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec("INSERT INTO call_requests (number_e164, requested_at, last_asked_at) VALUES ('98765', NOW(), NOW()), ('+447700900987', NOW(), NOW())");
            $numbers = array_column((new CallRequestRepository())->pending(), 'number_e164');
            assertTrue(in_array('+447700900987', $numbers, true));
            assertFalse(in_array('98765', $numbers, true));
        } finally {
            $pdo->rollBack();
        }
    }),
];
