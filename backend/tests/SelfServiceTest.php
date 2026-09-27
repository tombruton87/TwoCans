<?php
declare(strict_types=1);

/**
 * Self-service links (ContactLinkRepository) and the browser recordings they
 * send. The link round trips run against the real database inside a
 * transaction that is rolled back, so nothing is left behind.
 */

$inTransaction = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        $fn();
    } finally {
        $pdo->rollBack();
    }
};

$person = static function (bool $group = false): int {
    $pdo = Database::pdo();
    $pdo->prepare("INSERT INTO contacts (name, is_group) VALUES ('Test Nana', ?)")->execute([$group ? 1 : 0]);

    return (int) $pdo->lastInsertId();
};

return [
    test('a link finds its person, and only while it works', function () use ($inTransaction, $person) {
        $inTransaction(function () use ($person) {
            $links = new ContactLinkRepository();
            $id = $person();
            $token = $links->create($id);
            assertTrue((bool) preg_match('/^[a-f0-9]{32}$/', $token), 'a 32-hex token');
            assertSame($id, (int) $links->contactFor($token)['id']);

            Database::pdo()->prepare('UPDATE contact_links SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE contact_id = ?')->execute([$id]);
            assertNull($links->contactFor($token), 'an expired link finds nobody');
            assertNull($links->forContact($id), 'and the editor no longer shows it');
        });
    }),
    test('a link lasts a week', function () use ($inTransaction, $person) {
        $inTransaction(function () use ($person) {
            $links = new ContactLinkRepository();
            $id = $person();
            $links->create($id);
            $days = (strtotime($links->forContact($id)['expiresAt']) - time()) / 86400;
            assertTrue($days > 6.9 && $days <= 7.0, "expires in {$days} days");
        });
    }),
    test('a link made when they lasted longer is held to a week too', function () use ($inTransaction, $person) {
        $inTransaction(function () use ($person) {
            $links = new ContactLinkRepository();
            $id = $person();
            $token = $links->create($id);
            // Made eight days ago with a fortnight on it, as links once were.
            Database::pdo()->prepare('UPDATE contact_links SET created_at = NOW() - INTERVAL 8 DAY,
                expires_at = NOW() + INTERVAL 6 DAY WHERE contact_id = ?')->execute([$id]);
            assertNull($links->contactFor($token));
            assertNull($links->forContact($id));
        });
    }),
    test('making a new link kills the old one', function () use ($inTransaction, $person) {
        $inTransaction(function () use ($person) {
            $links = new ContactLinkRepository();
            $id = $person();
            $old = $links->create($id);
            $new = $links->create($id);
            assertNull($links->contactFor($old));
            assertSame($id, (int) $links->contactFor($new)['id']);
        });
    }),
    test('a stopped link finds nobody', function () use ($inTransaction, $person) {
        $inTransaction(function () use ($person) {
            $links = new ContactLinkRepository();
            $id = $person();
            $token = $links->create($id);
            $links->revoke($id);
            assertNull($links->contactFor($token));
        });
    }),
    test('a group can never be reached through a link', function () use ($inTransaction, $person) {
        $inTransaction(function () use ($person) {
            $links = new ContactLinkRepository();
            $token = $links->create($person(true));
            assertNull($links->contactFor($token));
        });
    }),
    test('anything that is not a token is refused before the database', function () {
        $links = new ContactLinkRepository();
        assertNull($links->contactFor(''));
        assertNull($links->contactFor("' OR 1=1 --"));
        assertNull($links->contactFor(str_repeat('A', 32)));
    }),
    test("a browser's recording, which has no length in its header, still converts", function () {
        $webm = sys_get_temp_dir() . '/tc-test-' . bin2hex(random_bytes(4)) . '.webm';
        // Streamed to a pipe, as MediaRecorder does, so the duration is never written.
        exec('ffmpeg -nostdin -loglevel error -f lavfi -i sine=f=300:d=2 -c:a libopus -f webm - > ' . escapeshellarg($webm), $o, $status);
        assertSame(0, $status, 'ffmpeg made the test file');
        $store = new CallerNameStore();
        $clip = $store->convert($webm);
        @unlink($webm);
        if ($clip['file'] !== null) {
            $store->delete($clip['file']);
        }
        assertNull($clip['error']);
        assertSame(2, $clip['seconds']);
    }),
];
