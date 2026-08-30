<?php
declare(strict_types=1);

/**
 * Grandstream GHP621 provisioning: hand the phone its SIP account and its
 * hotkeys as a Grandstream config file (cfg{MAC}.xml).
 *
 * Grandstream phones fetch this file from a "Config Server Path" (set in the
 * phone's web UI, or via DHCP option 66) on boot and on reprovision. Unlike
 * Linphone's expiring QR token, the URL is stable — so it is served with HTTP
 * basic auth (see index.php) rather than a one-time token.
 *
 * The file contains the SIP password in clear, because that is exactly what the
 * phone needs to be told.
 *
 * SIP account P-codes below (P271/P3/P47/P35/P36/P34) are the standard account-1
 * codes shared across Grandstream models. The hotkey codes are the GHP series'
 * own and are marked TODO(verify): confirm them against the official GHP621
 * config template before relying on them.
 */
final class GrandstreamProvisioning
{
    /**
     * Physical hotkeys on the GHP621, key index => the P-code that holds that
     * key's speed-dial number.
     *
     * TODO(verify): confirm these P-codes against the official GHP621 config
     * template. They are the one part of this file that is not yet proven.
     */
    public const HOTKEY_PCODES = [
        1 => 'P2440',
        2 => 'P2441',
        3 => 'P2442',
        4 => 'P2443',
    ];

    public static function keyCount(): int
    {
        return count(self::HOTKEY_PCODES);
    }

    /** Normalise a pasted MAC to 12 uppercase hex chars, or '' when it cannot be one. */
    public static function normalizeMac(string $input): string
    {
        $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $input) ?? '');

        return preg_match('/^[0-9A-F]{12}$/', $mac) ? $mac : '';
    }

    /**
     * @param array            $device  DeviceRepository::toView() shape
     * @param array<int,string> $hotkeys key index => number to dial
     */
    public function xml(array $device, array $hotkeys): string
    {
        $domain = PjsipConfig::domain();

        $p = static fn(string $code, string $value): string
            => '<' . $code . '>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $code . '>';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= "<gs_provision version=\"1\">\n";
        $xml .= "  <config version=\"2\">\n";

        // SIP account 1.
        $xml .= '    ' . $p('P271', '1') . "\n";                          // account active
        $xml .= '    ' . $p('P3', $device['name']) . "\n";                // display name
        $xml .= '    ' . $p('P47', $domain) . "\n";                       // SIP server
        $xml .= '    ' . $p('P35', $device['sipUsername']) . "\n";        // SIP user ID
        $xml .= '    ' . $p('P36', $device['sipUsername']) . "\n";        // authenticate ID
        $xml .= '    ' . $p('P34', $device['sipSecret']) . "\n";          // authenticate password

        // Hotkeys: one speed dial per physical key.
        foreach (self::HOTKEY_PCODES as $index => $code) {
            $xml .= '    ' . $p($code, (string) ($hotkeys[$index] ?? '')) . "\n";
        }

        $xml .= "  </config>\n";
        $xml .= "</gs_provision>\n";

        return $xml;
    }
}
