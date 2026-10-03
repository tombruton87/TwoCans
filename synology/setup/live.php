<?php
declare(strict_types=1);

/**
 * The calls going on now, for twocans' window in DSM, as JSON: which phone,
 * with whom, which way and for how long — from the app's own LiveCalls, as
 * its Live screen shows them. Run inside the web container, like stats.php.
 */

require '/var/www/html/src/bootstrap_cli.php';

$calls = [];
try {
    foreach ((new LiveCalls())->active() as $c) {
        $calls[] = [
            'channel' => (string) $c['channel'],
            'phone' => (string) $c['deviceName'],
            'with' => (string) $c['peerName'],
            'number' => (string) $c['peerNumber'],
            'dir' => (string) $c['dir'],
            'seconds' => (int) $c['seconds'],
            'connected' => (bool) $c['connected'],
        ];
    }
} catch (Throwable) {
    // No answer from the phone service: no calls to show.
}

echo json_encode(['updated' => time(), 'calls' => $calls], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
