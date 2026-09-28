<?php
declare(strict_types=1);

/**
 * Signing a grown-up out everywhere (migration 046): a session begun before
 * the cut-off is refused; one begun after it is fine. Against the database,
 * inside a transaction that is rolled back.
 */

$withGuardian = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    $saved = $_SESSION ?? [];
    try {
        $id = (new GuardianRepository())->add('Test Grown-up', 'signout-test-' . bin2hex(random_bytes(4)) . '@example.com', 'Admin', 'correct horse battery');
        $fn($id);
    } finally {
        $_SESSION = $saved;
        (new ReflectionProperty(Auth::class, 'cachedUser'))->setValue(null, null);
        $pdo->rollBack();
    }
};

$sessionFrom = static function (int $id, int $signedInAt): ?array {
    $_SESSION = ['auth' => ['guardian_id' => $id, 'signed_in_at' => $signedInAt]];
    (new ReflectionProperty(Auth::class, 'cachedUser'))->setValue(null, null);

    return Auth::user();
};

return [
    test('a session stays signed in when nobody has signed the account out', function () use ($withGuardian, $sessionFrom) {
        $withGuardian(function (int $id) use ($sessionFrom) {
            assertSame($id, (int) ($sessionFrom($id, time() - 3600)['id'] ?? 0));
        });
    }),
    test('signing out everywhere ends a session begun before it', function () use ($withGuardian, $sessionFrom) {
        $withGuardian(function (int $id) use ($sessionFrom) {
            (new GuardianRepository())->signOutEverywhere($id);
            assertNull($sessionFrom($id, time() - 60));
            assertFalse(isset($_SESSION['auth']), 'the stale session is dropped');
        });
    }),
    test('a sign-in after signing out everywhere works', function () use ($withGuardian, $sessionFrom) {
        $withGuardian(function (int $id) use ($sessionFrom) {
            (new GuardianRepository())->signOutEverywhere($id);
            assertSame($id, (int) ($sessionFrom($id, time() + 1)['id'] ?? 0));
        });
    }),
    test('making someone the Owner and changing their email', function () use ($withGuardian) {
        $withGuardian(function (int $id) {
            $repo = new GuardianRepository();
            $repo->makeOwner($id);
            $repo->setEmail($id, '  New-Owner@Example.COM ');
            $row = $repo->find($id);
            assertSame('Owner', $row['role']);
            assertSame('new-owner@example.com', $row['email']);
            assertTrue(in_array($id, array_map('intval', array_column($repo->owners(), 'id')), true));
        });
    }),
];
