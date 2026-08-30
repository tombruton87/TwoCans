<?php
declare(strict_types=1);

/**
 * Read-only health checks for the parts the line depends on: Asterisk (ARI and
 * AMI), MariaDB, Whisper, disk space, and the generated-config directory.
 *
 * Shared by the "System" screen and bin/check-asterisk.php so the two can never
 * drift apart — a check that says "fine" in one place must say "fine" in both.
 */
final class SystemHealth
{
    private static function env(string $name, string $default = ''): string
    {
        $v = getenv($name);

        return $v === false || $v === '' ? $default : $v;
    }

    /**
     * Flat list of checks. Each is a label, whether it passed, and a short
     * human detail — enough for the UI to render without further work.
     *
     * @return array<int,array{label:string,ok:bool,detail:string}>
     */
    public static function checks(): array
    {
        return array_merge(
            self::ariChecks(),
            self::amiChecks(),
            self::databaseChecks(),
            self::whisperChecks(),
            self::diskChecks(),
            self::configChecks(),
        );
    }

    /** @return array{checks:array<int,array{label:string,ok:bool,detail:string}>,passed:int,failed:int} */
    public static function summary(): array
    {
        $checks = self::checks();
        $failed = count(array_filter($checks, static fn(array $c): bool => !$c['ok']));

        return ['checks' => $checks, 'failed' => $failed, 'passed' => count($checks) - $failed];
    }

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    private static function ariChecks(): array
    {
        $base = rtrim(self::env('ARI_BASE_URL', 'http://asterisk:8088'), '/');
        $user = self::env('ARI_USERNAME');
        $pass = self::env('ARI_PASSWORD');

        $get = static function (string $path, bool $auth) use ($base, $user, $pass): array {
            $ctx = stream_context_create(['http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
            ] + ($auth ? ['header' => 'Authorization: Basic ' . base64_encode($user . ':' . $pass) . "\r\n"] : [])]);

            $body = @file_get_contents($base . $path, false, $ctx);
            $status = 0;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int) $m[1];
                }
            }

            return [$status, $body === false ? '' : $body];
        };

        [$anonStatus] = $get('/ari/asterisk/info', false);
        $checks[] = ['label' => 'ARI rejects anonymous access', 'ok' => $anonStatus === 401, 'detail' => 'HTTP ' . $anonStatus];

        [$status, $body] = $get('/ari/asterisk/info', true);
        $info = json_decode($body, true);
        $checks[] = ['label' => 'ARI authenticated', 'ok' => $status === 200, 'detail' => 'HTTP ' . $status];
        $checks[] = ['label' => 'Asterisk version', 'ok' => isset($info['system']['version']), 'detail' => (string) ($info['system']['version'] ?? '—')];

        return $checks;
    }

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    private static function amiChecks(): array
    {
        try {
            $ami = new Ami();
            $ami->connect();
            $checks[] = ['label' => 'AMI login', 'ok' => true, 'detail' => 'connected'];

            $ping = $ami->send('Ping');
            $checks[] = ['label' => 'AMI ping', 'ok' => ($ping['response'] ?? '') === 'Success', 'detail' => (string) ($ping['ping'] ?? 'no reply')];

            // Asterisk rejects the entire pjsip.conf if any part fails to parse
            // (including a wildcard #include matching nothing), then silently has
            // no transport at all while every other check passes. Assert it here.
            $show = $ami->command('pjsip show transports');
            $out = implode("\n", $show);
            $hasTransport = str_contains($out, 'transport-udp');
            $checks[] = ['label' => 'SIP transport bound', 'ok' => $hasTransport, 'detail' => $hasTransport ? 'transport-udp present' : 'no transport — pjsip.conf failed to parse'];

            $ami->disconnect();
        } catch (Throwable $e) {
            $checks[] = ['label' => 'AMI login', 'ok' => false, 'detail' => $e->getMessage()];
        }

        return $checks;
    }

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    private static function databaseChecks(): array
    {
        $available = Database::isAvailable();
        $checks[] = ['label' => 'Database reachable', 'ok' => $available, 'detail' => $available ? 'MariaDB answered' : 'could not reach MariaDB'];

        if (!$available) {
            return $checks;
        }

        try {
            $pdo = Database::pdo();
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version    VARCHAR(120) NOT NULL,
                    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (version)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
            $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
            $pending = 0;
            foreach ($files as $file) {
                if (!in_array(basename($file, '.sql'), $applied, true)) {
                    $pending++;
                }
            }
            $checks[] = ['label' => 'Migrations', 'ok' => $pending === 0, 'detail' => $pending === 0 ? 'up to date' : $pending . ' pending'];
        } catch (Throwable $e) {
            $checks[] = ['label' => 'Migrations', 'ok' => false, 'detail' => $e->getMessage()];
        }

        return $checks;
    }

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    private static function whisperChecks(): array
    {
        $url = rtrim(self::env('WHISPER_URL', 'http://whisper:9000'), '/');
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
        $body = @file_get_contents($url . '/health', false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }

        $json = json_decode($body === false ? '' : $body, true);
        $ready = is_array($json) && ($json['ok'] ?? false) === true;

        return [[
            'label' => 'Whisper ready',
            'ok' => $status === 200 && $ready,
            'detail' => is_array($json) ? 'model ' . (string) ($json['model'] ?? '?') : 'no reply',
        ]];
    }

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    private static function diskChecks(): array
    {
        $dir = rtrim(self::env('RECORDINGS_PATH', '/var/spool/asterisk/monitor'), '/');

        if (!is_dir($dir)) {
            return [['label' => 'Disk space', 'ok' => false, 'detail' => $dir . ' missing']];
        }

        $free = @disk_free_space($dir);
        $total = @disk_total_space($dir);
        if ($free === false || $total === false || $total === 0) {
            return [['label' => 'Disk space', 'ok' => true, 'detail' => 'size unknown']];
        }

        $pct = (int) round(100 * $free / $total);

        return [[
            'label' => 'Disk space',
            'ok' => $pct >= 10,
            'detail' => $pct . '% free (' . self::bytes($free) . ' of ' . self::bytes($total) . ')',
        ]];
    }

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    private static function configChecks(): array
    {
        $dir = rtrim(self::env('ASTERISK_GENERATED_DIR', '/etc/asterisk/generated'), '/');
        $checks[] = ['label' => 'Generated config dir', 'ok' => is_dir($dir) && is_writable($dir), 'detail' => $dir];

        $probe = $dir . '/.health-probe';
        $wrote = is_dir($dir) && @file_put_contents($probe, "; probe\n") !== false;
        @unlink($probe);
        $checks[] = ['label' => 'Generated config writable', 'ok' => $wrote, 'detail' => $wrote ? 'ok' : 'could not write'];

        return $checks;
    }

    private static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
