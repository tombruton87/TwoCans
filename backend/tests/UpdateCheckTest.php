<?php
declare(strict_types=1);

/** Is there a newer twocans? What's remembered, and when it counts as newer. */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        SettingsRepository::forget();
        $fn(new SettingsRepository());
    } finally {
        $pdo->rollBack();
        SettingsRepository::forget();
    }
};

return [
    test('the running version is backend/VERSION', function () {
        assertTrue(preg_match('/^\d+\.\d+\.\d+$/', UpdateCheck::current()) === 1);
    }),
    test('a later release is newer; the same or an older one isn\'t; nothing known is nothing', function () use ($fresh) {
        $fresh(function (SettingsRepository $s) {
            $s->set('update_check', '1');
            $s->set('latest_version', '');
            assertFalse((new UpdateCheck($s))->isNewer());
            $s->set('latest_version', '99.0.0');
            assertTrue((new UpdateCheck($s))->isNewer());
            $s->set('latest_version', UpdateCheck::current());
            assertFalse((new UpdateCheck($s))->isNewer());
            $s->set('latest_version', '0.0.1');
            assertFalse((new UpdateCheck($s))->isNewer());
        });
    }),
    test('turned off, it never says so, and doesn\'t ask', function () use ($fresh) {
        $fresh(function (SettingsRepository $s) {
            $s->set('latest_version', '99.0.0');
            $s->set('latest_checked_at', '0');
            $u = new UpdateCheck($s);
            $u->setEnabled(false);
            assertFalse($u->isNewer());
            assertSame(0, $u->refresh(true)['checkedAt'], 'not asked');
        });
    }),
];
