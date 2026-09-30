<?php
declare(strict_types=1);

/**
 * The hotkeys on a Grandstream desk phone — which number each physical key
 * dials: three on a GHP61x, six on a GHP62x.
 *
 * A key can only ever hold something a child is allowed to reach: a person's
 * number, a group's speed dial (a group has no number of its own), or a
 * twocans service number. Anything else is dropped in save(), so a
 * tampered form cannot provision a hotkey that dials off the allowlist.
 */
final class DeviceHotkeyRepository
{
    /** @return array<int,string> key index => number */
    public function forDevice(int $deviceId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT key_index, number FROM device_hotkeys WHERE device_id = ? ORDER BY key_index ASC'
        );
        $st->execute([$deviceId]);

        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int) $row['key_index']] = (string) $row['number'];
        }

        return $out;
    }

    /** @param array<int,string> $numbers key index => number */
    public function save(int $deviceId, array $numbers): void
    {
        $valid = $this->validTargets();

        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM device_hotkeys WHERE device_id = ?')->execute([$deviceId]);

        $insert = $pdo->prepare('INSERT INTO device_hotkeys (device_id, key_index, number) VALUES (?, ?, ?)');
        foreach ($numbers as $index => $number) {
            $number = trim((string) $number);
            if ($number === '' || !isset($valid[$number])) {
                continue;
            }
            $insert->execute([$deviceId, (int) $index, $number]);
        }
    }

    /**
     * Who each number a hotkey may dial is, for the key's label on the phone.
     *
     * @return array<string,string> number => name
     */
    public function labels(): array
    {
        $labels = [];
        foreach (PjsipConfig::testNumbers() as $number => $service) {
            $labels[(string) $number] = (string) $service['label'];
        }
        foreach ($this->contactTargets() as $target => $row) {
            if ((string) $row['name'] !== '') {
                $labels[$target] = (string) $row['name'];
            }
        }

        return $labels;
    }

    /**
     * Every number a hotkey may dial: the allowlist plus the service numbers.
     *
     * @return array<string,bool>
     */
    private function validTargets(): array
    {
        $valid = [];
        foreach (array_keys($this->contactTargets()) as $target) {
            $valid[(string) $target] = true;
        }
        foreach (array_keys(PjsipConfig::testNumbers()) as $number) {
            $valid[$number] = true;
        }

        return $valid;
    }

    /**
     * What a key dials for each contact who can be on one: a person's number,
     * or a group's speed dial. Groups are always callable (allow_out), but
     * only reachable once they have a speed dial.
     *
     * @return array<string,array> what to dial => contact row
     */
    public function contactTargets(): array
    {
        $out = [];
        $rows = Database::pdo()->query(
            "SELECT * FROM contacts
              WHERE (is_group = 0 AND number_e164 IS NOT NULL AND number_e164 <> '')
                 OR (is_group = 1 AND allow_out = 1 AND speed_dial IS NOT NULL AND speed_dial <> '')
              ORDER BY is_group, name"
        )->fetchAll();
        foreach ($rows as $row) {
            $target = (int) $row['is_group'] === 1 ? (string) $row['speed_dial'] : (string) $row['number_e164'];
            $out[$target] ??= $row;
        }

        return $out;
    }

    /**
     * A contact's number or speed dial changed: point their keys at the new
     * one, or clear them when there is none.
     */
    public function retarget(string $from, string $to): void
    {
        $pdo = Database::pdo();
        if ($to === '') {
            $pdo->prepare('DELETE FROM device_hotkeys WHERE number = ?')->execute([$from]);
            return;
        }
        $pdo->prepare('UPDATE device_hotkeys SET number = ? WHERE number = ?')->execute([$to, $from]);
    }

    /**
     * Send fresh settings to every desk phone with this person or group on a
     * key, so a changed name reaches the key's label. Quietly: a phone that
     * can't be reached picks it up when it next starts.
     */
    public function resyncPhonesWith(string $target): void
    {
        if ($target === '') {
            return;
        }
        $st = Database::pdo()->prepare('SELECT DISTINCT device_id FROM device_hotkeys WHERE number = ?');
        $st->execute([$target]);
        $devices = new DeviceRepository();
        foreach ($st->fetchAll() as $row) {
            $phone = $devices->find((int) $row['device_id']);
            if ($phone !== null) {
                GrandstreamProvisioning::notify(DeviceRepository::toView($phone));
            }
        }
    }

    /** The first key on a phone with nothing on it, of its $keys, or null. */
    public function freeKey(int $deviceId, int $keys): ?int
    {
        $taken = $this->forDevice($deviceId);
        for ($i = 1; $i <= $keys; $i++) {
            if (($taken[$i] ?? '') === '') {
                return $i;
            }
        }

        return null;
    }

    /** Whether any phone has this on a key. */
    public function onAnyKey(string $target): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM device_hotkeys WHERE number = ? LIMIT 1');
        $st->execute([$target]);

        return (bool) $st->fetchColumn();
    }

    /**
     * Desk phones with a free key for someone not on one yet — what to offer
     * after they're added.
     *
     * @return array<int,array{id:int,name:string,key:int}>
     */
    public function offersFor(string $target): array
    {
        if ($target === '' || $this->onAnyKey($target) || !isset($this->validTargets()[$target])) {
            return [];
        }
        $offers = [];
        foreach ((new DeviceRepository())->all() as $row) {
            if (!DeviceRepository::isDesk((string) $row['type'])) {
                continue;
            }
            $free = $this->freeKey((int) $row['id'], DeviceRepository::keys((string) $row['type']));
            if ($free !== null) {
                $offers[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'key' => $free];
            }
        }

        return $offers;
    }

    /** Put one on a phone's first free key; the key it went on, or null if none. */
    public function addToFreeKey(int $deviceId, int $keys, string $target): ?int
    {
        $free = $this->freeKey($deviceId, $keys);
        if ($free === null || !isset($this->validTargets()[$target])) {
            return null;
        }
        Database::pdo()->prepare('INSERT INTO device_hotkeys (device_id, key_index, number) VALUES (?, ?, ?)')
            ->execute([$deviceId, $free, $target]);

        return $free;
    }

    /** What a key dials for a contact row: its number, or a group's speed dial. */
    public static function targetOf(array $contact): string
    {
        return (int) ($contact['is_group'] ?? 0) === 1 ? (string) ($contact['speed_dial'] ?? '') : (string) ($contact['number_e164'] ?? '');
    }
}
