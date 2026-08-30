<?php
declare(strict_types=1);

/**
 * twocans — parents-only admin for a self-hosted kids' phone line.
 *
 * Guardians and credentials are in MariaDB; the rest of the data is still
 * session-backed (see Store) until the telephony side is wired. Seams meant for
 * wiring are marked `TODO(wire)`.
 */

require __DIR__ . '/init.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (($_SERVER['HTTPS'] ?? '') === 'on'),
]);
session_name('twocans');
session_start();
