<?php
declare(strict_types=1);

/**
 * Tiny test runner — deliberately no PHPUnit, matching the project's "no build
 * step" rule. Discovers backend/tests/*Test.php, each returning an array of
 * [label, callable] pairs from the test() helper, and runs them all.
 *
 *   docker compose exec php php /var/www/html/bin/test.php
 *   docker compose exec php php /var/www/html/bin/test.php --filter=E164
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require __DIR__ . '/../tests/helpers.php';

$filter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, strlen('--filter='));
    }
}

$files = glob(__DIR__ . '/../tests/*Test.php') ?: [];
sort($files);

$pass = 0;
$fail = 0;
$failures = [];

echo "twocans — tests\n", str_repeat('=', 62), "\n";

foreach ($files as $file) {
    $name = basename($file, '.php');
    if ($filter !== null && $filter !== '' && !str_contains($name, $filter)) {
        continue;
    }

    $tests = require $file;
    if (!is_array($tests)) {
        fwrite(STDERR, "  [FAIL] {$name} did not return an array of tests\n");
        $fail++;
        $failures[] = "{$name}: did not return an array of tests";
        continue;
    }

    foreach ($tests as $test) {
        [$label, $fn] = $test;
        try {
            $fn();
            $pass++;
            printf("  [ OK ] %s — %s\n", $name, $label);
        } catch (Throwable $e) {
            $fail++;
            $failures[] = "{$name} — {$label}: {$e->getMessage()}";
            printf("  [FAIL] %s — %s\n        %s\n", $name, $label, $e->getMessage());
        }
    }
}

echo str_repeat('=', 62), "\n";
printf("%d passed, %d failed\n", $pass, $fail);

if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
}

exit($fail === 0 ? 0 : 1);
