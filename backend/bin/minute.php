<?php
declare(strict_types=1);

/**
 * Once a minute (docker/web/loop-ddns.sh): the things that happen at a time
 * rather than when somebody presses something.
 *
 *  - a Grandstream that was offline when its settings changed, and is back,
 *    is sent them (GrandstreamProvisioning::sendPending)
 *  - announcements set to play by themselves are played when they're due
 *    (AnnouncementRepository::runSchedule)
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$say = static fn(string $line) => fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $line . "\n");

try {
    (new PjsipConfig(new DeviceRepository()))->syncRegistrations();
    foreach (GrandstreamProvisioning::sendPending() as $name) {
        $say('sent waiting settings to ' . $name);
    }
} catch (Throwable $e) {
    $say('waiting settings: ' . $e->getMessage());
}

try {
    foreach ((new AnnouncementRepository())->runSchedule() as $line) {
        $say('scheduled announcement ' . $line);
    }
} catch (Throwable $e) {
    $say('scheduled announcements: ' . $e->getMessage());
}
