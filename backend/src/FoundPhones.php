<?php
declare(strict_types=1);

/**
 * Grandstream phones and adapters on the home network that twocans hasn't
 * been told about yet — so adding one is picking it, not copying a MAC off
 * its label. Two ways in:
 *
 *  - one asks for its settings file with a MAC twocans doesn't know (see
 *    index.php); its request says its model: "Grandstream Model HW GHP621W
 *    SW 1.0.1.37 DevId 000b82c12345"
 *  - a scan of the network finds it (Pager::scan), and asks it its model
 *
 * See migration 051.
 */
final class FoundPhones
{
    /** Forget one not seen for this long: it has gone, or been set up. */
    private const KEEP_DAYS = 7;

    /** Note one that asked for its settings, from its request's User-Agent. */
    public function sawFetch(string $mac, string $ip, string $userAgent): void
    {
        [$model, $firmware] = self::fromUserAgent($userAgent);
        $this->remember($mac, $ip, $model, $firmware, 'fetch');
    }

    public function remember(string $mac, string $ip, string $model, string $firmware, string $source): void
    {
        $mac = GrandstreamProvisioning::normalizeMac($mac);
        if ($mac === '') {
            return;
        }
        Database::pdo()->prepare(
            'INSERT INTO found_phones (mac, ip, model, firmware, source, seen_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ip = VALUES(ip), seen_at = VALUES(seen_at),
               model = IF(VALUES(model) <> "", VALUES(model), model),
               firmware = IF(VALUES(firmware) <> "", VALUES(firmware), firmware)'
        )->execute([$mac, substr($ip, 0, 45), substr($model, 0, 40), substr($firmware, 0, 40), $source, date('Y-m-d H:i:s')]);
    }

    /**
     * The ones not added yet, newest first, each with the twocans type its
     * model is (or '' when it didn't say). The latest scan is taken in first.
     *
     * @return array<int,array{mac:string,ip:string,model:string,firmware:string,type:string,label:string,seen:string}>
     */
    public function unassigned(): array
    {
        $scan = Pager::scanResults();
        foreach ($scan['found'] as $f) {
            $this->remember((string) ($f['mac'] ?? ''), (string) ($f['ip'] ?? ''), (string) ($f['model'] ?? ''), '', 'scan');
        }

        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM found_phones WHERE seen_at < ?')->execute([date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]);
        $rows = $pdo->query(
            "SELECT f.* FROM found_phones f
              WHERE NOT EXISTS (SELECT 1 FROM devices d WHERE UPPER(REPLACE(d.mac, ':', '')) = f.mac)
              ORDER BY f.seen_at DESC"
        )->fetchAll();

        return array_map(static function (array $r): array {
            $type = self::typeFor((string) $r['model']);

            return [
                'mac' => (string) $r['mac'],
                'ip' => (string) $r['ip'],
                'model' => (string) $r['model'],
                'firmware' => (string) $r['firmware'],
                'type' => $type,
                'label' => $type !== '' ? DeviceRepository::TYPES[$type]['label'] . (str_ends_with(strtoupper((string) $r['model']), 'W') ? 'W' : '') : ((string) $r['model'] ?: 'A Grandstream'),
                'seen' => (string) $r['seen_at'],
            ];
        }, $rows);
    }

    /** The twocans type for a Grandstream model name — GHP621W is a ghp621 — or ''. */
    public static function typeFor(string $model): string
    {
        $m = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $model) ?? '');
        if (preg_match('/^(ghp61[01]|ghp62[01]|ht80[12])w?$/', $m, $x)) {
            return $x[1];
        }

        return '';
    }

    /**
     * Model and firmware from a Grandstream's settings request.
     *
     * @return array{0:string,1:string}
     */
    public static function fromUserAgent(string $ua): array
    {
        $model = preg_match('/\bHW\s+([A-Za-z0-9]+)/', $ua, $m) ? $m[1] : '';
        $firmware = preg_match('/\bSW\s+([0-9][0-9.]*)/', $ua, $f) ? $f[1] : '';

        return [$model, $firmware];
    }
}
