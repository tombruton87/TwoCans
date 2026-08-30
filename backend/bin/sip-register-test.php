<?php
declare(strict_types=1);

/**
 * Prove a device's generated SIP credentials actually work, without touching a
 * phone. Sends a real REGISTER over UDP, answers the digest challenge, and
 * reports what Asterisk said.
 *
 *   docker exec twocans-php php /var/www/html/bin/sip-register-test.php <extension|username>
 *
 * A pass here means: the endpoint exists, the auth section matches, the
 * transport is listening, and the password in the UI is the one Asterisk wants.
 * What it does not prove is audio — for that, dial 600 from the real app.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$needle = $argv[1] ?? '';
if ($needle === '') {
    fwrite(STDERR, "Usage: sip-register-test.php <extension|sip-username>\n");
    exit(1);
}

$devices = new DeviceRepository();
$device = null;
foreach ($devices->all() as $row) {
    if ((string) $row['extension'] === $needle || (string) $row['sip_username'] === $needle) {
        $device = DeviceRepository::toView($row);
        break;
    }
}

if ($device === null) {
    fwrite(STDERR, "No device with extension or username '{$needle}'.\n");
    exit(1);
}

// Talk to Asterisk directly on the container network, not via the LAN address.
$host = getenv('AMI_HOST') ?: 'asterisk';
$port = 5060;
$user = $device['sipUsername'];
$pass = $device['sipSecret'];
$domain = PjsipConfig::domain();

echo "twocans — SIP registration test\n", str_repeat('=', 58), "\n";
printf("  device     %s (extension %s)\n", $device['name'], $device['extension']);
printf("  username   %s\n", $user);
printf("  target     %s:%d (udp)\n\n", $host, $port);

$socket = stream_socket_client("udp://{$host}:{$port}", $errno, $errstr, 5);
if ($socket === false) {
    fwrite(STDERR, "Cannot open UDP socket: {$errstr}\n");
    exit(1);
}
stream_set_timeout($socket, 5);

$callId = bin2hex(random_bytes(8));
$fromTag = bin2hex(random_bytes(4));
$branch = 'z9hG4bK' . bin2hex(random_bytes(6));
$localIp = gethostbyname(gethostname());
$contact = "sip:{$user}@{$localIp}:5060";

/** Build a REGISTER, optionally carrying an Authorization header. */
$register = static function (int $cseq, string $auth = '') use ($user, $domain, $callId, $fromTag, $branch, $contact): string {
    $lines = [
        "REGISTER sip:{$domain} SIP/2.0",
        "Via: SIP/2.0/UDP 0.0.0.0:5060;branch={$branch}" . ($cseq > 1 ? '-' . $cseq : '') . ';rport',
        'Max-Forwards: 70',
        "From: <sip:{$user}@{$domain}>;tag={$fromTag}",
        "To: <sip:{$user}@{$domain}>",
        "Call-ID: {$callId}",
        "CSeq: {$cseq} REGISTER",
        "Contact: <{$contact}>",
        'Expires: 60',
        'User-Agent: twocans-selftest',
    ];
    if ($auth !== '') {
        $lines[] = 'Authorization: ' . $auth;
    }
    $lines[] = 'Content-Length: 0';

    return implode("\r\n", $lines) . "\r\n\r\n";
};

$exchange = static function (string $message) use ($socket): string {
    fwrite($socket, $message);
    $response = fread($socket, 8192);

    return $response === false ? '' : $response;
};

// --- 1. unauthenticated REGISTER: expect a 401 challenge -------------------
$response = $exchange($register(1));
$status = substr($response, 0, 15);

if ($response === '') {
    echo "  [FAIL] no response — is the UDP transport listening?\n";
    exit(1);
}
printf("  [ OK ] challenge received     %s\n", trim(explode("\r\n", $response)[0] ?? ''));

if (!preg_match('/WWW-Authenticate:\s*Digest\s*(.+)/i', $response, $m)) {
    echo "  [FAIL] no digest challenge in the reply\n";
    exit(1);
}

$params = [];
preg_match_all('/(\w+)="?([^",]+)"?/', $m[1], $pairs, PREG_SET_ORDER);
foreach ($pairs as $pair) {
    $params[strtolower($pair[1])] = $pair[2];
}

$realm = $params['realm'] ?? 'asterisk';
$nonce = $params['nonce'] ?? '';
$qop = $params['qop'] ?? '';
$uri = "sip:{$domain}";

// --- 2. answer the challenge ----------------------------------------------
$ha1 = md5("{$user}:{$realm}:{$pass}");
$ha2 = md5("REGISTER:{$uri}");

if ($qop !== '') {
    $cnonce = bin2hex(random_bytes(4));
    $nc = '00000001';
    $responseHash = md5("{$ha1}:{$nonce}:{$nc}:{$cnonce}:auth:{$ha2}");
    $auth = sprintf(
        'Digest username="%s", realm="%s", nonce="%s", uri="%s", response="%s", qop=auth, nc=%s, cnonce="%s", algorithm=MD5',
        $user, $realm, $nonce, $uri, $responseHash, $nc, $cnonce
    );
} else {
    $responseHash = md5("{$ha1}:{$nonce}:{$ha2}");
    $auth = sprintf(
        'Digest username="%s", realm="%s", nonce="%s", uri="%s", response="%s", algorithm=MD5',
        $user, $realm, $nonce, $uri, $responseHash
    );
}

$response = $exchange($register(2, $auth));
$statusLine = trim(explode("\r\n", $response)[0] ?? '');

if (str_contains($statusLine, '200 OK')) {
    printf("  [ OK ] registered             %s\n", $statusLine);
    echo "\n", str_repeat('=', 58), "\n";
    echo "These credentials work. Type them into Linphone as shown in the app.\n";

    // Leave nothing registered behind unless --keep was passed (used to check
    // that the app reads registration state back correctly).
    if (!in_array('--keep', $argv, true)) {
        fwrite($socket, str_replace('Expires: 60', 'Expires: 0', $register(3, $auth)));
    } else {
        echo "Contact left registered for 60s (--keep).\n";
    }
    fclose($socket);
    exit(0);
}

printf("  [FAIL] %s\n", $statusLine === '' ? 'no response to the authenticated REGISTER' : $statusLine);
echo "\nFull reply:\n", $response, "\n";
fclose($socket);
exit(1);
