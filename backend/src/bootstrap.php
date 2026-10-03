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

/*
 * Signed in for SESSION_DAYS, renewed each day it's used — not PHP's defaults,
 * which signed a grown-up out after 24 minutes idle, whenever the browser was
 * closed, and on every restart of this container (sessions lived in its /tmp).
 * They're kept in storage/ instead, which outlives the container.
 */
$sessionDir = rtrim(getenv('SESSIONS_PATH') ?: '/var/lib/twocans/sessions', '/');
if (is_dir($sessionDir) || @mkdir($sessionDir, 0700, true)) {
    session_save_path($sessionDir);
}
ini_set('session.gc_maxlifetime', (string) (Auth::SESSION_DAYS * 86400));
$sessionCookie = [
    'lifetime' => Auth::SESSION_DAYS * 86400,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (($_SERVER['HTTPS'] ?? '') === 'on'),
];
session_set_cookie_params($sessionCookie);
session_name('twocans');
session_start();

// PHP only sends the cookie when a session begins, so its expiry wouldn't move
// on: send it again once a day, so it's a month from the last visit, not the first.
if (isset($_SESSION['auth']) && (int) ($_SESSION['cookie_renewed_at'] ?? 0) < time() - 86400) {
    setcookie(session_name(), session_id(), [
        'expires' => time() + $sessionCookie['lifetime'],
    ] + array_diff_key($sessionCookie, ['lifetime' => 1]));
    $_SESSION['cookie_renewed_at'] = time();
}
