<?php
declare(strict_types=1);

/**
 * The pager: sends pages to Grandstream desk phones as multicast, which they
 * play through the speaker without a call, and looks over the home network
 * for Grandstreams when asked. See Pager.
 *
 * Runs in a container on the host's network (the "pager" service), because
 * multicast can't leave Docker's own. It needs no database: the web app leaves
 * each page as a job in PAGER_PATH/queue, and this sends them in turn.
 *
 *   php bin/pager.php            keep going (what the container runs)
 *   php bin/pager.php --once     send what's waiting, then stop
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Pager.php';

$once = in_array('--once', $argv, true);
$queue = Pager::path() . '/queue';
@mkdir($queue, 0777, true);

$say = static fn(string $line) => fwrite(STDOUT, date('Y-m-d H:i:s') . ' ' . $line . "\n");
$say('pager: sending to ' . Pager::GROUP . ', jobs from ' . $queue);

while (true) {
    @touch(Pager::path() . '/alive');

    $jobs = glob($queue . '/*.json') ?: [];
    sort($jobs);
    foreach ($jobs as $file) {
        $job = json_decode((string) @file_get_contents($file), true);
        @unlink($file);
        if (!is_array($job)) {
            continue;
        }
        if (($job['type'] ?? '') === 'scan') {
            $found = Pager::scan((string) ($job['home'] ?? ''));
            $say('pager: scanned the network, ' . count($found) . ' Grandstream' . (count($found) === 1 ? '' : 's'));
            continue;
        }
        // A page is for now: one stuck behind a long outage is dropped.
        if (time() - (int) ($job['at'] ?? 0) > 60) {
            $say('pager: dropped a page from ' . date('H:i:s', (int) ($job['at'] ?? 0)) . ', too old');
            continue;
        }
        $ports = array_map('intval', (array) ($job['ports'] ?? []));
        $files = array_filter((array) ($job['files'] ?? []), static fn($f): bool => is_string($f) && str_starts_with($f, '/var/lib/twocans/'));
        $packets = Pager::play($ports, array_values($files));
        $say(sprintf('pager: %.1f s to port%s %s', $packets * 0.02, count($ports) === 1 ? '' : 's', implode(', ', $ports)));
        @touch(Pager::path() . '/alive');
    }

    if ($once) {
        break;
    }
    usleep(200_000);
}
