<?php
declare(strict_types=1);

/**
 * Keep the household's dynamic DNS record pointed at this house.
 *
 *   docker exec twocans-php php /var/www/html/bin/ddns.php            # check once
 *   docker exec twocans-php php /var/www/html/bin/ddns.php --force    # check, ignoring the guard
 *   docker exec twocans-php php /var/www/html/bin/ddns.php --status    # what is stored
 *   docker exec twocans-php php /var/www/html/bin/ddns.php --watch     # keep going
 *
 * The container runs the plain one-shot form once a minute — see
 * docker/php/entrypoint.sh — so each check is a fresh short-lived process and
 * nothing can wedge for longer than a minute. --watch is for running it by hand.
 *
 * Quiet on purpose: it prints only when something changed or went wrong, because
 * its output is appended to a log file 1,440 times a day. Use --status, or the
 * Phone line screen, to confirm checks are still happening.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$watch = in_array('--watch', $argv, true);
$force = in_array('--force', $argv, true);
$statusOnly = in_array('--status', $argv, true);
// By hand, say something either way; from the once-a-minute loop, stay quiet.
$verbose = $watch || $force || in_array('--verbose', $argv, true);

$log = static fn(string $line): int|false => fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $line . "\n");

$dns = new DynamicDnsRepository();

if ($statusOnly) {
    $config = $dns->get();

    echo "twocans — dynamic DNS\n", str_repeat('=', 52), "\n";

    if (!$config['enabled'] || !$config['configured']) {
        echo "  not set up yet\n";
        printf("  address     %s\n", $config['ip'] !== '' ? $config['ip'] : '—');
        printf("  checked     %s\n", $config['checkedAt'] ?? 'never');
        printf("  right now   %s\n", DynamicDns::publicIp() ?? 'could not tell');
        echo "  Phone line screen → \"Where the outside world finds you\"\n";
        echo str_repeat('=', 52), "\n";
        exit(0);
    }

    printf("  name        %s\n", $config['hostname']);
    printf("  record      %s, TTL %d, proxied %s\n", $config['recordType'], $config['ttl'], $config['proxied'] ? 'yes' : 'no');
    printf("  zone        %s\n", $config['zone']);
    printf("  last seen   %s\n", $config['ip'] !== '' ? $config['ip'] : '—');
    printf("  checked     %s\n", $config['checkedAt'] ?? 'never');
    printf("  confirmed   %s\n", $config['updatedAt'] ?? 'never');
    printf("  right now   %s\n", DynamicDns::publicIp() ?? 'could not tell');

    if ($config['error'] !== null) {
        printf("  last error  %s\n", $config['error']);
    }

    echo str_repeat('=', 52), "\n";
    exit($config['error'] === null ? 0 : 1);
}

if ($watch) {
    $log('dynamic DNS watcher starting — every ' . DynamicDns::INTERVAL_SECONDS . 's');
}

$status = 0;

do {
    try {
        $result = (new DynamicDns($dns))->sync($force);

        if ($result['error'] !== null) {
            $log('error: ' . $result['error']);
            $status = 1;
        } elseif ($result['changed'] || $verbose) {
            $log($result['message']);
        }
    } catch (Throwable $e) {
        // A database blip costs this pass and nothing more — the next minute
        // tries again from scratch.
        $log('error: ' . $e->getMessage());
        $status = 1;
    }

    if ($watch) {
        sleep(DynamicDns::INTERVAL_SECONDS);
    }
} while ($watch);

exit($status);
