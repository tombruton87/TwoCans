<?php
declare(strict_types=1);

/**
 * The allowlist, published as a remote phonebook for IP phones.
 *
 * Grandstream and Yealink each expect their own XML shape, but both get the
 * same list: the contacts a child is actually allowed to call (allow_out = 1,
 * a real number, and not a group). That is the whole point of a kids' phone —
 * the directory shows Grandma and Mum, never the outside world.
 */
final class Phonebook
{
    /**
     * Contacts worth listing, sorted by name.
     *
     * @param array<int,array> $contacts raw rows from ContactRepository::all()
     * @return array<int,array{name:string,number:string}>
     */
    private static function entries(array $contacts): array
    {
        $out = [];
        foreach ($contacts as $c) {
            $number = (string) ($c['number_e164'] ?? '');
            if ($number === '' || !$c['allow_out'] || (int) ($c['is_group'] ?? 0) === 1) {
                continue;
            }
            $out[] = ['name' => (string) $c['name'], 'number' => $number];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /** Grandstream remote-phonebook XML (GXP/GXV/GHP all read this shape). */
    public static function grandstream(array $contacts): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= "<AddressBook>\n";
        $xml .= "  <pbgroup>\n    <id>1</id>\n    <name>twocans</name>\n  </pbgroup>\n";

        $id = 0;
        foreach (self::entries($contacts) as $entry) {
            $id++;
            $xml .= "  <pbentry>\n";
            $xml .= "    <pbgroup>1</pbgroup>\n";
            $xml .= '    <id>' . $id . "</id>\n";
            $xml .= '    <Firstname>' . self::x($entry['name']) . "</Firstname>\n";
            $xml .= '    <Phonenumber>' . self::x($entry['number']) . "</Phonenumber>\n";
            $xml .= "  </pbentry>\n";
        }

        $xml .= "</AddressBook>\n";

        return $xml;
    }

    /** Yealink remote-phonebook XML. */
    public static function yealink(array $contacts): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= "<YealinkIPPhoneDirectory>\n";

        foreach (self::entries($contacts) as $entry) {
            $xml .= "  <DirectoryEntry>\n";
            $xml .= '    <Name>' . self::x($entry['name']) . "</Name>\n";
            $xml .= '    <Telephone>' . self::x($entry['number']) . "</Telephone>\n";
            $xml .= "  </DirectoryEntry>\n";
        }

        $xml .= "</YealinkIPPhoneDirectory>\n";

        return $xml;
    }

    private static function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
