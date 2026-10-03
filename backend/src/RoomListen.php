<?php
declare(strict_types=1);

/**
 * Listening to a room: a grown-up's phone rings a child's, which answers by
 * itself on speaker and sends the room's sound one way — a baby monitor made
 * of the phones already in the house.
 *
 * Both phones have to be switched on for it, on their Rules: the room's phone
 * (roomListen — only one that can answer by itself) and the phone listening
 * (canRoomListen). The room always knows: a beep as the phone picks up, and
 * "Listening in" on its screen. The dialplan does the work — see
 * PjsipConfig::renderRoomListenTargets() — and notes every listen, which this
 * brings in for the phone's page.
 */
final class RoomListen
{
    /**
     * Ring $listenOn and, when it's answered, listen to $room.
     *
     * @return array{ok:bool,error:?string}
     */
    public function start(array $listenOn, array $room): array
    {
        $listener = DeviceRepository::toView($listenOn);
        $target = DeviceRepository::toView($room);

        if (!$target['roomListen']) {
            return ['ok' => false, 'error' => "Listening to {$target['name']}'s room isn't switched on"];
        }
        if (!$listener['canRoomListen']) {
            return ['ok' => false, 'error' => "{$listener['name']} isn't allowed to listen to rooms"];
        }
        if (!$listener['online']) {
            return ['ok' => false, 'error' => "{$listener['name']} isn't online, so it can't be used to listen"];
        }
        if (!$target['online']) {
            return ['ok' => false, 'error' => "{$target['name']} isn't online"];
        }

        try {
            $ami = new Ami();
            $ami->connect();
            $reply = $ami->originate(
                'PJSIP/' . $listener['sipUsername'],
                PjsipConfig::PAGE_CONTEXT,
                'l' . $target['id'],
                '"Listen: ' . str_replace('"', '', $target['name']) . '" <' . PjsipConfig::TEST_CALLER_NUMBER . '>',
            );
            $ami->disconnect();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (($reply['response'] ?? '') !== 'Success') {
            return ['ok' => false, 'error' => $reply['message'] ?? 'Asterisk refused'];
        }

        return ['ok' => true, 'error' => null];
    }

    /** Bring in the listens the dialplan has noted. Returns how many. */
    public function import(): int
    {
        try {
            $ami = new Ami();
            $ami->connect();
            $notes = $ami->takeNotes(PjsipConfig::ROOM_LISTEN_FAMILY);
            $ami->disconnect();
        } catch (Throwable) {
            return 0;
        }

        return $this->record($notes);
    }

    /**
     * Save notes "<listening endpoint>|<room phone id>|<epoch>", keyed by the call.
     *
     * @param array<string,string> $notes
     */
    public function record(array $notes): int
    {
        $byUsername = [];
        foreach ((new DeviceRepository())->all() as $row) {
            $byUsername[(string) $row['sip_username']] = (int) $row['id'];
        }

        $insert = Database::pdo()->prepare(
            'INSERT IGNORE INTO room_listens (uniqueid, device_id, listener_id, listened_at) VALUES (?, ?, ?, ?)'
        );
        $added = 0;
        foreach ($notes as $uniqueid => $note) {
            [$endpoint, $deviceId, $at] = array_pad(explode('|', $note), 3, '');
            $insert->execute([
                $uniqueid,
                (int) $deviceId ?: null,
                $byUsername[$endpoint] ?? null,
                date('Y-m-d H:i:s', (int) $at ?: time()),
            ]);
            $added += $insert->rowCount();
        }

        return $added;
    }

    /**
     * The latest listens to a room, newest first, with who listened.
     *
     * @return array<int,array{listener:string,when:string}>
     */
    public function recentFor(int $deviceId, int $limit = 5): array
    {
        $st = Database::pdo()->prepare(
            'SELECT r.listened_at, d.name AS listener
               FROM room_listens r LEFT JOIN devices d ON d.id = r.listener_id
              WHERE r.device_id = ?
              ORDER BY r.listened_at DESC, r.id DESC
              LIMIT ' . max(1, $limit)
        );
        $st->execute([$deviceId]);

        return array_map(static fn(array $r): array => [
            'listener' => (string) ($r['listener'] ?? 'A phone'),
            'when' => date('D j M, g:ia', (int) strtotime((string) $r['listened_at'])),
        ], $st->fetchAll());
    }
}
