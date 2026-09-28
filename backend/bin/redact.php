<?php
declare(strict_types=1);

/**
 * Take private details out of text, for `./twocans report`: reads stdin,
 * writes the same text with them replaced.
 *
 * Removed: every password, key and token twocans holds (from .env and the
 * database, decrypted where they're stored encrypted), the names and emails of
 * the household's contacts, grown-ups and phones, phone numbers, the house's
 * domain, public IP addresses, and long hex strings and provider IDs. Kept:
 * home-network addresses, ports, versions, error messages — what helps someone
 * else see what's wrong.
 *
 * Best effort by design: whoever shares the report should still read it first.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$text = (string) stream_get_contents(STDIN);

// ------------------------------------------------ exact values, longest first
$exact = [];
$add = static function (?string $value, string $as) use (&$exact): void {
    $value = trim((string) $value);
    if (mb_strlen($value) >= 4) {
        $exact[$value] = $as;
    }
};

foreach (['DB_PASSWORD', 'DB_ROOT_PASSWORD', 'APP_KEY', 'ARI_PASSWORD', 'AMI_PASSWORD', 'CLOUDFLARE_TUNNEL_TOKEN'] as $key) {
    $add(getenv($key) ?: null, '[secret]');
}

// Anything that fails to read just isn't there to hide.
$try = static function (callable $fn): mixed {
    try {
        return $fn();
    } catch (Throwable) {
        return null;
    }
};
$pdo = Database::pdo();

$add($try(fn() => (new SettingsRepository())->provisionPass()), '[secret]');
$add($try(fn() => (new TrunkRepository())->authToken()), '[secret]');
$add($try(fn() => (new DynamicDnsRepository())->apiToken()), '[secret]');
$ha = $try(fn() => (new HomeAssistant())->config());
if (is_array($ha)) {
    $add($ha['password'] ?? null, '[secret]');
    $add($ha['token'] ?? null, '[secret]');
}
$trunk = $try(fn() => (new TrunkRepository())->get());
if (is_array($trunk)) {
    $add($trunk['accountSid'] ?? null, '[provider id]');
    $add($trunk['terminationUsername'] ?? null, '[provider login]');
}
$ddns = $try(fn() => (new DynamicDnsRepository())->get());
if (is_array($ddns)) {
    $add($ddns['hostname'] ?? null, '[house domain]');
}
$appHost = (string) parse_url((string) getenv('APP_URL'), PHP_URL_HOST);
if ($appHost !== '' && !filter_var($appHost, FILTER_VALIDATE_IP)) {
    $add($appHost, '[house domain]');
}

foreach ($try(fn() => $pdo->query('SELECT sip_secret, sip_username, name FROM devices')->fetchAll()) ?? [] as $d) {
    $add($d['sip_secret'], '[secret]');
    $add($d['sip_username'], '[phone login]');
}
foreach ($try(fn() => $pdo->query('SELECT email FROM guardians')->fetchAll()) ?? [] as $g) {
    $add($g['email'], '[email]');
}

// Names are replaced as whole words, so "Nan" doesn't eat "Nancy".
$names = [];
foreach (['SELECT name FROM contacts', 'SELECT name FROM guardians', 'SELECT name FROM devices'] as $sql) {
    foreach ($try(fn() => $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)) ?? [] as $name) {
        foreach (array_merge([trim((string) $name)], preg_split('/[\s&,]+/', (string) $name) ?: []) as $part) {
            if (mb_strlen($part) >= 3 && !in_array(mb_strtolower($part), ['and', 'the', 'phone', 'house'], true)) {
                $names[$part] = true;
            }
        }
    }
}

echo Redactor::scrub($text, $exact, array_keys($names));
