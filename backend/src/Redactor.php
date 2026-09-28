<?php
declare(strict_types=1);

/**
 * Takes private details out of text — see bin/redact.php, which gathers the
 * household's own values to remove, and ./twocans report, which uses it.
 */
final class Redactor
{
    /**
     * @param array<string,string> $exact value => what to show instead (passwords, logins, the domain…)
     * @param array<int,string>    $names names to replace as whole words, any case
     */
    public static function scrub(string $text, array $exact, array $names): string
    {
        // Longest first, so a password containing a shorter one goes whole.
        $exact = array_filter($exact, static fn($v, $k): bool => mb_strlen((string) $k) >= 4, ARRAY_FILTER_USE_BOTH);
        uksort($exact, static fn($a, $b): int => mb_strlen((string) $b) <=> mb_strlen((string) $a));
        $text = strtr($text, $exact);

        $patterns = [
            // Provider IDs (Twilio account, key and number SIDs).
            '/\b(AC|SK|PN|CA|RE)[0-9a-f]{32}\b/' => '[provider id]',
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[email]',
            // Long hex: keys, tokens, hashes.
            '/\b[0-9a-f]{24,}\b/i' => '[hex]',
            // Phone numbers: international, and UK-style national.
            '/\+\d{8,15}\b/' => '[number]',
            '/(?<![\d.])0\d{9,10}(?![\d.])/' => '[number]',
            '/(?<![\d.:+])44\d{9,10}(?![\d.])/' => '[number]',
        ];
        $text = (string) preg_replace(array_keys($patterns), array_values($patterns), $text);

        // Public IPv4 addresses; private and local ones stay, they help.
        $text = (string) preg_replace_callback('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', static function (array $m): string {
            return filter_var($m[0], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                ? $m[0] : '[public ip]';
        }, $text);

        // Whole words only, so "Tom" leaves "automatically" alone.
        $names = array_values(array_filter($names, static fn($n): bool => mb_strlen((string) $n) >= 3));
        if ($names !== []) {
            usort($names, static fn($a, $b): int => mb_strlen((string) $b) <=> mb_strlen((string) $a));
            $alternation = implode('|', array_map(static fn($n): string => preg_quote((string) $n, '/'), $names));
            $text = (string) preg_replace('/(?<![\p{L}\p{N}])(?:' . $alternation . ')(?![\p{L}\p{N}])/iu', '[name]', $text);
        }

        return $text;
    }
}
