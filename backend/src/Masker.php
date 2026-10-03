<?php
declare(strict_types=1);

/**
 * Takes the household out of text that's going to leave the house — see
 * Diagnostics. Passwords go; everything else that could say who or where
 * gets a stand-in, the same each time it appears, so what's left still makes
 * sense: "this-phone", "phone-2", "name-3", "number-1", "lan-ip-2",
 * "805EC0-mac1" (a MAC keeps its maker's part, which is no one's).
 *
 * Masked, in order — longest first within each, so one can't half-mask
 * another: passwords, and keys in addresses; SIP usernames (they're made from phones' names);
 * email addresses; the names of phones, contacts and grown-ups, then each
 * word of them that isn't an ordinary one ("Room", "Mum"); phone numbers;
 * MAC addresses; the household's own host names; private IP addresses,
 * and the address at the start of each web server log line.
 */
final class Masker
{
    /** @var array<string,string> exact text => its stand-in */
    private array $exact = [];

    /** @var array<string,string> word => its stand-in, matched as a whole word, any case */
    private array $words = [];

    /** @var array<string,array<string,string>> kind => (real => stand-in) */
    private array $seen = [];

    /** Words in names that aren't anyone's: left alone. */
    private const ORDINARY = ['the', 'and', 'room', 'phone', 'kitchen', 'office', 'landing', 'hall', 'hallway', 'den', 'study',
        'bedroom', 'lounge', 'living', 'loft', 'attic', 'garage', 'garden', 'playroom', 'nursery', 'upstairs', 'downstairs',
        'mum', 'mom', 'dad', 'nanny', 'nana', 'granny', 'grandma', 'grandad', 'grandpa', 'auntie', 'aunt', 'uncle', 'test',
        'home', 'house', 'family', 'friends', 'school', 'work', 'mobile', 'desk', 'handset', 'base', 'socket', 'line', 'adapter',
        'grandparents', 'grannies', 'grandparent', 'cousins', 'neighbours', 'babysitter', 'twocans'];

    /**
     * A masker for this household, with this phone as "this-phone".
     *
     * @param array $device DeviceRepository::toView() shape
     */
    public static function forHousehold(array $device): self
    {
        $m = new self();
        $m->secret((string) ($device['sipSecret'] ?? ''));
        $pass = (new SettingsRepository())->provisionPass();
        $m->secret($pass);
        $m->secret(rawurlencode($pass));

        if (($device['sipUsername'] ?? '') !== '') {
            $m->exact[(string) $device['sipUsername']] = 'this-phone';
        }
        $n = 0;
        foreach ((new DeviceRepository())->all() as $row) {
            $m->secret((string) ($row['sip_secret'] ?? ''));
            $user = (string) ($row['sip_username'] ?? '');
            if ($user !== '' && !isset($m->exact[$user])) {
                $m->exact[$user] = 'phone-' . (++$n);
            }
            $m->name((string) $row['name']);
            $m->number((string) ($row['hotline_number'] ?? ''));
        }
        foreach ((new ContactRepository())->all() as $row) {
            $m->name((string) $row['name']);
            $m->number((string) ($row['number_e164'] ?? ''));
            $m->number(ContactSheet::friendly((string) ($row['number_e164'] ?? '')));
        }
        foreach (Database::pdo()->query('SELECT name, email FROM guardians')->fetchAll() as $row) {
            $m->name((string) $row['name']);
            $m->exact[(string) $row['email']] ??= 'email-' . count($m->exact);
        }
        // The household's own host names: its SIP domain if it's a name, and
        // any public host it's set up with.
        foreach ($_ENV + getenv() as $key => $value) {
            if (is_string($value) && preg_match('/DOMAIN|HOST/', (string) $key) === 1
                && preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $value) === 1 && filter_var($value, FILTER_VALIDATE_IP) === false) {
                $m->exact[$value] ??= 'twocans-host';
            }
        }

        return $m;
    }

    private function secret(string $value): void
    {
        if (strlen($value) >= 4) {
            $this->exact[$value] = '[password]';
        }
    }

    private function name(string $name): void
    {
        $name = trim($name);
        if (mb_strlen($name) < 2) {
            return;
        }
        $this->exact[$name] ??= 'name-' . (count(array_filter($this->exact, static fn(string $v): bool => str_starts_with($v, 'name-'))) + 1);
        foreach (preg_split("/[^\\p{L}']+/u", $name) ?: [] as $word) {
            $word = trim($word, "'");
            $plain = mb_strtolower(preg_replace("/'s$/u", '', $word) ?? $word);
            if (mb_strlen($plain) >= 3 && !in_array($plain, self::ORDINARY, true)) {
                $this->words[$plain] ??= 'name-' . (count($this->words) + 100);
            }
        }
    }

    private function number(string $number): void
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        if (strlen($digits) >= 6) {
            $this->exact[$number] ??= 'number-' . (count(array_filter($this->exact, static fn(string $v): bool => str_starts_with($v, 'number-'))) + 1);
            $this->exact[$digits] ??= $this->exact[$number];
        }
    }

    /** A stand-in for $real of this kind: the same one each time. */
    private function standIn(string $kind, string $real, callable $make): string
    {
        return $this->seen[$kind][$real] ??= $make(count($this->seen[$kind] ?? []) + 1);
    }

    public function mask(string $text): string
    {
        // Exact text, longest first.
        $exact = array_filter($this->exact, static fn(string $k): bool => $k !== '', ARRAY_FILTER_USE_KEY);
        uksort($exact, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $text = strtr($text, $exact);

        // Keys in addresses (a desk phone's event addresses carry one).
        $text = preg_replace('/([?&](?:amp;)?k=)[0-9a-f]{8,}/i', '$1[key]', $text) ?? $text;

        // Email addresses not already known.
        $text = preg_replace_callback('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            fn(array $m): string => $this->standIn('email', strtolower($m[0]), static fn(int $n): string => 'email-' . $n), $text) ?? $text;

        // Each personal word of a name, as a whole word, any case ("sam" in "sam-1a2b" too).
        if ($this->words !== []) {
            $keys = array_keys($this->words);
            usort($keys, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            $pattern = '/(?<![\p{L}])(' . implode('|', array_map(static fn(string $w): string => preg_quote($w, '/'), $keys)) . ")(?:'s)?(?![\\p{L}])/iu";
            $text = preg_replace_callback($pattern, fn(array $m): string => $this->words[mb_strtolower($m[1])] ?? $m[0], $text) ?? $text;
        }

        // Phone numbers: a + or 0, then 9 to 14 more digits (with spaces or dashes).
        $text = preg_replace_callback('/(?<![\w.:\/])(\+|0)(\d[\d \-]{7,16}\d)(?![\w.])/',
            fn(array $m): string => $this->standIn('number', preg_replace('/\D/', '', $m[0]) ?? $m[0], static fn(int $n): string => 'number-x' . $n), $text) ?? $text;

        // MAC addresses: its maker's part stays.
        $text = preg_replace_callback('/(?<![0-9A-Fa-f:])((?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}|[0-9A-Fa-f]{12})(?![0-9A-Fa-f:])/',
            function (array $m): string {
                $mac = strtoupper(str_replace(':', '', $m[1]));
                if (preg_match('/^\d+$/', $mac) === 1) {
                    return $m[0];   // all digits: a number, not a MAC
                }
                $tag = $this->standIn('mac', $mac, static fn(int $n): string => 'mac' . $n);

                return substr($mac, 0, 6) . '-' . $tag;
            }, $text) ?? $text;

        // Private IP addresses anywhere; the address a web server log line starts with.
        $ip = '(?:\d{1,3}\.){3}\d{1,3}';
        $text = preg_replace_callback('/^(' . $ip . ')(?= )/m',
            fn(array $m): string => $this->ip($m[1]), $text) ?? $text;
        $text = preg_replace_callback('/(?<![\d.])(' . $ip . ')(?![\d.])/',
            fn(array $m): string => $this->isPrivate($m[1]) ? $this->ip($m[1]) : $m[0], $text) ?? $text;

        return $text;
    }

    private function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function ip(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $ip;
        }
        if ($ip === PjsipConfig::domain()) {
            return 'twocans-server';
        }

        return $this->standIn('ip', $ip, fn(int $n): string => ($this->isPrivate($ip) ? 'lan-ip-' : 'public-ip-') . $n);
    }
}
