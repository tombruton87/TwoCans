<?php
declare(strict_types=1);

/**
 * Run the retention sweep by hand.
 *
 *   docker compose exec php php /var/www/html/bin/retention.php          # what would go
 *   docker compose exec php php /var/www/html/bin/retention.php --run    # do it now
 *
 * The app sweeps by itself on a page load, at most hourly, so this is only for
 * when you want it done this second — or for a household that would rather put
 * it in cron after all.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$settings = new SettingsRepository();
$retention = new Retention($settings);
$days = $settings->retentionDays();

if ($days === 0) {
    echo "Recordings are set to be kept forever — nothing to sweep.\n";
    echo "Change that on the call log page, or with:\n";
    echo "  php -r 'require \"/var/www/html/src/bootstrap_cli.php\"; (new SettingsRepository())->setRetentionDays(90);'\n";
    exit;
}

$pending = $retention->pending();
echo "Keeping recordings and transcripts for {$settings->retentionLabel()}.\n";
echo "Past that: {$pending['calls']} call(s), {$pending['voicemails']} message(s).\n";

if (!in_array('--run', $argv, true)) {
    echo "\nNothing changed. Pass --run to delete them.\n";
    exit;
}

$done = $retention->sweep(true);
echo "\nDeleted the audio and transcript of {$done['calls']} call(s) "
   . "and {$done['voicemails']} message(s). The log entries remain.\n";

if (($pending['calls'] + $pending['voicemails']) > ($done['calls'] + $done['voicemails'])) {
    echo "More are waiting — sweeps run in batches. Run this again.\n";
}
