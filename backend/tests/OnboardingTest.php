<?php
declare(strict_types=1);

/**
 * The Getting started guide (Onboarding): its state, skipping the phone line,
 * and when the dashboard reminds. Against the database, in a transaction that
 * is rolled back.
 */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        SettingsRepository::forget();
        $fn(new Onboarding());
    } finally {
        $pdo->rollBack();
        SettingsRepository::forget();
    }
};

return [
    test('there are four steps, in order', function () use ($fresh) {
        $fresh(function (Onboarding $o) {
            assertSame(['phone', 'people', 'line', 'test'], array_column($o->steps(), 'key'));
        });
    }),
    test('the state is new, later or done — nothing else', function () use ($fresh) {
        $fresh(function (Onboarding $o) {
            $o->setState('later');
            assertSame('later', $o->state());
            $o->setState('nonsense');
            assertSame('', $o->state());
        });
    }),
    test('only the phone line can be skipped, and it can be un-skipped', function () use ($fresh) {
        $fresh(function (Onboarding $o) {
            $o->skip('phone');
            $o->skip('line');
            assertSame(['line'], $o->skipped());
            $o->skip('line', false);
            assertSame([], $o->skipped());
        });
    }),
    test('a skipped line counts towards progress unless it is connected anyway', function () use ($fresh) {
        $fresh(function (Onboarding $o) {
            $before = $o->progress()['done'];
            $line = array_values(array_filter($o->steps(), static fn($s) => $s['key'] === 'line'))[0];
            $o->skip('line');
            assertSame($line['done'] ? $before : $before + 1, $o->progress()['done']);
        });
    }),
    test('the dashboard stops reminding once it is put off or finished', function () use ($fresh) {
        $fresh(function (Onboarding $o) {
            foreach (['later', 'done'] as $state) {
                $o->setState($state);
                assertFalse($o->remind(), "still reminding when {$state}");
            }
        });
    }),
    test('a test call that rang counts as the test step', function () use ($fresh) {
        $fresh(function (Onboarding $o) {
            $o->markTested();
            $test = array_values(array_filter($o->steps(), static fn($s) => $s['key'] === 'test'))[0];
            assertTrue($test['done']);
        });
    }),
    test('it leaves the menu once hidden or finished, and comes back when shown again', function () use ($fresh) {
        $fresh(function (Onboarding $o) {
            $o->setState('done');
            assertFalse($o->visible(), 'hidden');
            $o->setState('later');
            assertSame(!$o->complete(), $o->visible(), 'shown again, unless every step is done');
        });
    }),
];
