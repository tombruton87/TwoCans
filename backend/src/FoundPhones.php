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
     * @return array<int,array{mac:string,ip:string,model:string,firmware:string,type:string,brand:string,label:string,seen:string}>
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
            // Who made it, from its MAC: one whose model it wouldn't say can
            // still be added — its maker's models are offered.
            $brand = $type !== '' ? (string) DeviceRepository::TYPES[$type]['brand'] : (Pager::brandFor((string) $r['mac']) ?? '');

            return [
                'mac' => (string) $r['mac'],
                'ip' => (string) $r['ip'],
                'model' => (string) $r['model'],
                'firmware' => (string) $r['firmware'],
                'type' => $type,
                'brand' => $brand,
                'label' => match (true) {
                    DeviceRepository::isDect($type) => 'Yealink ' . DeviceRepository::TYPES[$type]['label'] . ' base',
                    (DeviceRepository::TYPES[$type]['brand'] ?? '') === 'yealink' => 'Yealink ' . DeviceRepository::TYPES[$type]['label'],
                    $type === 'ga10' => 'Fanvil GA10',
                    (DeviceRepository::TYPES[$type]['brand'] ?? '') === 'poly' => 'Poly ' . strtoupper((string) $r['model']),
                    DeviceRepository::isAdapter($type) && DeviceRepository::TYPES[$type]['brand'] === 'cisco' => 'Cisco ' . DeviceRepository::TYPES[$type]['label'],
                    $type !== '' => DeviceRepository::TYPES[$type]['label'] . (str_ends_with(strtoupper((string) $r['model']), 'W') ? 'W' : ''),
                    $brand !== '' => (string) $r['model'] ?: 'A ' . DeviceRepository::BRANDS[$brand]['label'] . ' phone',
                    default => (string) $r['model'] ?: 'A phone',
                },
                'seen' => (string) $r['seen_at'],
            ];
        }, $rows);
    }

    /**
     * The twocans type for a model name — GHP621W is a ghp621; a Yealink
     * W60B base is where W56H handsets are — or ''.
     */
    public static function typeFor(string $model): string
    {
        $m = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $model) ?? '');
        if (preg_match('/^(ghp61[01]|ghp62[01]|ht80[12])w?$/', $m, $x)) {
            return $x[1];
        }
        // A Yealink: a cordless base by its own name (the W60B's type is
        // w56h, from when it was the only one); the desk phones twocans knows.
        if ($m === 'w60b') {
            return 'w56h';
        }
        if (in_array($m, ['w70b', 'w52p', 't31g', 't33g', 't43u', 't46u', 't53w', 't54w', 't57w', 't58w'], true)) {
            return $m;
        }
        // Siblings set up as one: a T42S as a T42U, a T48S as a T48U.
        if (in_array($m, ['t42u', 't42s'], true)) {
            return 't42u';
        }
        if (in_array($m, ['t48u', 't48s'], true)) {
            return 't48u';
        }
        if (in_array($m, ['t44u', 't44w'], true)) {
            return 't44u';
        }
        if (preg_match('/^(spa112|spa122|ata191|ata192)/', $m, $c) && isset(DeviceRepository::TYPES[$c[1]])) {
            return $c[1];
        }
        if ($m === 'ga10') {
            return 'ga10';
        }
        // A VVX: its siblings set up as one (a 311 as a 300, a 601 as a 600).
        if (preg_match('/^vvx(\d{3})/', $m, $v)) {
            $n = (int) $v[1];
            $type = match (true) {
                in_array($n, [150, 250, 350, 450], true) => 'vvx' . $n,
                in_array($n, [101, 201], true) => 'vvx201',
                in_array($n, [300, 301, 310, 311], true) => 'vvx300',
                in_array($n, [400, 401, 410, 411], true) => 'vvx400',
                in_array($n, [500, 501], true) => 'vvx500',
                in_array($n, [600, 601], true) => 'vvx600',
                default => '',
            };

            return $type;
        }

        return '';
    }

    /**
     * Model and firmware from a Grandstream's settings request — or a
     * Yealink's: "Yealink W60B 77.85.0.20 80:5e:c0:12:34:56".
     *
     * @return array{0:string,1:string}
     */
    public static function fromUserAgent(string $ua): array
    {
        if (preg_match('/^Yealink\s+(?:SIP-)?([A-Za-z0-9]+)\s+([0-9][0-9.]*)/', $ua, $y)) {
            return [$y[1], $y[2]];
        }
        // A Fanvil: "Fanvil GA10 2.4.0 0c383e123456".
        if (preg_match('/^Fanvil\s+([A-Za-z0-9]+)\s+([0-9][0-9.]*)/', $ua, $f)) {
            return [$f[1], $f[2]];
        }
        // A Poly: "FileTransport PolycomVVX-VVX_450-UA/6.4.0.1234".
        if (preg_match('#Polycom\w*-(VVX_\d+)-UA/([0-9][0-9.]*)#', $ua, $p)) {
            return [str_replace('_', '', $p[1]), $p[2]];
        }
        // A Cisco: "Cisco/SPA112-1.4.1(SR5) (88755A123456)".
        if (preg_match('#^(?:Cisco|Linksys)/((?:SPA|ATA)\d+[A-Za-z]?)-([0-9][0-9.]*)#', $ua, $c)) {
            return [$c[1], $c[2]];
        }
        $model = preg_match('/\bHW\s+([A-Za-z0-9]+)/', $ua, $m) ? $m[1] : '';
        $firmware = preg_match('/\bSW\s+([0-9][0-9.]*)/', $ua, $f) ? $f[1] : '';

        return [$model, $firmware];
    }
}
