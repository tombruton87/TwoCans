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
 *  - GitHub is asked, twice a day, whether there's a newer twocans (UpdateCheck)
 *  - times tables games and listens to a room, noted by the dialplan, are
 *    brought in (Quiz, RoomListen)
 *  - on Christmas morning, if it's switched on, Santa rings (Christmas)
 *  - a phone paused for a while is let go when its time comes
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
    // Is there a newer twocans? At most twice a day — see UpdateCheck.
    $before = (new UpdateCheck())->latest()['version'];
    $latest = (new UpdateCheck())->refresh()['version'];
    if ($latest !== $before && $latest !== '') {
        $say('latest twocans is ' . $latest);
    }
} catch (Throwable $e) {
    $say('update check: ' . $e->getMessage());
}

try {
    foreach ((new AnnouncementRepository())->runSchedule() as $line) {
        $say('scheduled announcement ' . $line);
    }
} catch (Throwable $e) {
    $say('scheduled announcements: ' . $e->getMessage());
}

try {
    // Noted by the dialplan as they happen — see PjsipConfig::QUIZ_FAMILY and ROOM_LISTEN_FAMILY.
    (new Quiz())->import();
    (new RoomListen())->import();
} catch (Throwable $e) {
    $say('quiz and room listens: ' . $e->getMessage());
}

try {
    foreach (Christmas::ringIfDue() as $name) {
        $say('Santa rang ' . $name);
    }
} catch (Throwable $e) {
    $say('Santa: ' . $e->getMessage());
}

try {
    // A paused phone's time is up: it rings and calls out again.
    $devices = new DeviceRepository();
    if ($devices->endPauses() > 0) {
        (new PjsipConfig($devices))->apply();
        $say('let a paused phone go');
    }
} catch (Throwable $e) {
    $say('pauses: ' . $e->getMessage());
}
