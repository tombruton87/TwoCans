<?php
declare(strict_types=1);

/**
 * Notification worker: heartbeat to Uptime Kuma, and email via Mailgun when
 * something needs a grown-up's attention.
 *
 *   docker exec twocans-php php /var/www/html/bin/notify.php             # run once
 *   docker exec twocans-php php /var/www/html/bin/notify.php --watch     # keep going
 *   docker exec twocans-php php /var/www/html/bin/notify.php --status    # what is stored
 *
 * The container runs the one-shot form once a minute — see entrypoint.sh.
 * Quiet on purpose: it prints only when something changed or went wrong.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$watch = in_array('--watch', $argv, true);
$statusOnly = in_array('--status', $argv, true);
$log = static fn(string $line): int|false => fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $line . "\n");

if ($statusOnly) {
    $config = (new NotificationRepository())->get();
    echo "twocans — notifications\n", str_repeat('=', 52), "\n";
    printf("  enabled      %s\n", $config['enabled'] ? 'yes' : 'no');
    printf("  mailgun      %s\n", $config['mailgunConfigured'] ? $config['from'] . ' → ' . $config['to'] : 'not configured');
    printf("  uptime kuma  %s\n", $config['kumaUrl'] !== '' ? 'set' : 'not set');
    printf("  asks         %s\n", $config['notifyAsks'] ? 'on' : 'off');
    printf("  offline      %s\n", $config['notifyOffline'] ? 'on' : 'off');
    printf("  low credit   %s\n", $config['notifyLowCredit'] ? 'on' : 'off');
    printf("  emergency    %s\n", $config['notifyEmergency'] ? 'on' : 'off');
    printf("  messages     %s\n", $config['notifyMessages'] ? 'on' : 'off');
    printf("  weekly       %s\n", $config['notifyDigest'] ? 'on' : 'off');
    printf("  last run     %s\n", $config['lastRunAt'] ?? 'never');
    if ($config['lastError'] !== null) {
        printf("  last error   %s\n", $config['lastError']);
    }
    echo str_repeat('=', 52), "\n";
    exit(0);
}

if ($watch) {
    $log('notification watcher starting — every 60s');
}

// Keep the Home Assistant bridge running — see HomeAssistant::ensureRunning().
if (!$watch) {
    try {
        if (HomeAssistant::ensureRunning()) {
            $log('started the Home Assistant bridge');
        }
    } catch (Throwable $e) {
        $log('could not start the Home Assistant bridge: ' . $e->getMessage());
    }
}

do {
    // Keep the router's openings renewed, or close them — see PortOpener.
    try {
        $line = (new PortOpener())->maintain();
        if ($line !== null) {
            $log($line);
        }
    } catch (Throwable $e) {
        $log('router ports: ' . $e->getMessage());
    }

    try {
        $result = (new Notifier())->run();

        if ($result['error'] !== null) {
            $log('error: ' . $result['error']);
        } elseif ($result['ran'] && ($result['emailed'] > 0 || $result['sections'] > 0)) {
            $log('sent ' . $result['emailed'] . ' email(s), ' . $result['sections'] . ' section(s)');
        }
    } catch (Throwable $e) {
        $log('error: ' . $e->getMessage());
    }

    if ($watch) {
        sleep(60);
    }
} while ($watch);
