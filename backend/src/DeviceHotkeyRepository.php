<?php
declare(strict_types=1);

/**
 * The hotkeys on a Grandstream GHP621 — which number each physical key dials.
 *
 * A key can only ever hold something a child is allowed to reach: a contact's
 * number or a twocans service number. Anything else is dropped in save(), so a
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
     * Every number a hotkey may dial: the allowlist plus the service numbers.
     *
     * @return array<string,bool>
     */
    private function validTargets(): array
    {
        $valid = [];
        $rows = Database::pdo()->query("SELECT number_e164 FROM contacts WHERE number_e164 <> ''")->fetchAll();
        foreach ($rows as $row) {
            $valid[(string) $row['number_e164']] = true;
        }
        foreach (array_keys(PjsipConfig::testNumbers()) as $number) {
            $valid[$number] = true;
        }

        return $valid;
    }
}
