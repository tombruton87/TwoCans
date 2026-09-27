<?php
declare(strict_types=1);

/**
 * Where the household's quiet-time recording is kept.
 *
 * It shares the refusals volume instead of having a mount of its own, which is
 * the reason an installed box needs no container recreate to record one — so
 * the path it derives, and the names it will accept back, are worth pinning
 * down.
 */

return [
    test('the quiet message lives in a folder of its own inside the refusals volume', function () {
        putenv('QUIET_MESSAGE_PATH');
        putenv('REFUSALS_PATH=/var/lib/twocans/refusals');
        assertSame('/var/lib/twocans/refusals/quiet', (new QuietMessageStore())->path());
        putenv('REFUSALS_PATH');
    }),
    test('a trailing slash on the volume is not doubled', function () {
        putenv('QUIET_MESSAGE_PATH');
        putenv('REFUSALS_PATH=/var/lib/twocans/refusals/');
        assertSame('/var/lib/twocans/refusals/quiet', (new QuietMessageStore())->path());
        putenv('REFUSALS_PATH');
    }),
    test('QUIET_MESSAGE_PATH moves it', function () {
        putenv('QUIET_MESSAGE_PATH=/mnt/quiet/');
        assertSame('/mnt/quiet', (new QuietMessageStore())->path());
        putenv('QUIET_MESSAGE_PATH');
    }),
    test('only a name the store itself would have written resolves to a file', function () {
        $store = new QuietMessageStore();

        // Names are 32 hex characters and .wav. Anything else is stale or
        // somebody fishing for a path, and either way not something to hand
        // back to a caller.
        assertNull($store->file('../../etc/passwd'));
        assertNull($store->file('not-a-hash.wav'));
        assertNull($store->file(''));
    }),
];
