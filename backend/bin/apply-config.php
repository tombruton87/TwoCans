<?php
declare(strict_types=1);

/**
 * Writes Asterisk's generated config from the database and reloads it.
 *
 *   docker compose exec php php /var/www/html/bin/apply-config.php
 *
 * The app does this by itself whenever a phone or a rule changes. Running it by
 * hand is for after an install or a move: the SIP transports carry the address
 * phones register to, and that comes from SIP_DOMAIN rather than the database.
 *
 * Transports are not reloadable, so if this reports that they changed, Asterisk
 * needs a restart — a reload will not pick them up.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$result = (new PjsipConfig())->apply();

echo "wrote {$result['written']} config file(s) for SIP domain "
   . PjsipConfig::domain() . " — phones must be able to reach that address.\n";

if ($result['reloaded'] !== true) {
    fwrite(STDERR, 'could not reload Asterisk: ' . ($result['error'] ?? 'unknown reason')
        . "\nThe config is on disk and will load when Asterisk next starts.\n");
    exit(1);
}

echo "Asterisk reloaded.\n";
