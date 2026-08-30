<?php
declare(strict_types=1);

/**
 * Connectivity check for the pieces the line depends on. Reads the same
 * environment variables the app does, so a pass here means the app's own
 * configuration is good. Shares its logic with the "System" screen via
 * SystemHealth, so the two can never drift apart.
 *
 *   docker exec twocans-php php /var/www/html/bin/check-asterisk.php
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$pass = 0;
$fail = 0;

echo "twocans — system health\n";
echo str_repeat('=', 62), "\n";

foreach (SystemHealth::checks() as $check) {
    $check['ok'] ? $pass++ : $fail++;
    printf("  [%s] %-34s %s\n", $check['ok'] ? ' OK ' : 'FAIL', $check['label'], $check['detail']);
}

echo "\n", str_repeat('=', 62), "\n";
printf("%d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
