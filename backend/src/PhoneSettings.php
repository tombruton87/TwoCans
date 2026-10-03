<?php
declare(strict_types=1);

/**
 * What each kind of phone can have changed on its Phone settings tab — its
 * own list, from what that hardware can do: a Grandstream desk phone's
 * (GrandstreamProvisioning::PHONE_SETTINGS), an HT80x adapter's
 * (GrandstreamProvisioning::ATA_SETTINGS), a Cisco SPA112, SPA122, ATA 191 or ATA 192's
 * (CiscoProvisioning::PHONE_SETTINGS), a Poly VVX's
 * (PolyProvisioning::PHONE_SETTINGS), a Fanvil GA10's
 * (FanvilProvisioning::PHONE_SETTINGS), a Yealink cordless handset's
 * (YealinkProvisioning::PHONE_SETTINGS). A phone whose list is empty has no
 * such tab.
 *
 * Each setting: what it's called, a line about it, its group and its default
 * — what every phone gets until somebody changes it. A choice's choices are
 * value => label. 'shared': it's the box's, not the phone's — every handset
 * on a W60B base, both of an HT802's sockets, has it the same, so changing
 * it on one changes it on all.
 */
final class PhoneSettings
{
    /** @return array<string,array> the settings a type of phone has */
    public static function catalog(string $type): array
    {
        return match (true) {
            DeviceRepository::isGrandstreamDesk($type) => GrandstreamProvisioning::PHONE_SETTINGS,
            (DeviceRepository::TYPES[$type]['brand'] ?? '') === 'poly' => PolyProvisioning::PHONE_SETTINGS,
            in_array($type, ['ht801', 'ht802'], true) => GrandstreamProvisioning::ATA_SETTINGS,
            isset(YealinkProvisioning::HANDSETS[$type]) => YealinkProvisioning::settingsFor($type),
            YealinkProvisioning::isDesk($type) => YealinkProvisioning::deskSettingsFor($type),
            in_array($type, ['spa112', 'spa122'], true) => CiscoProvisioning::PHONE_SETTINGS,
            CiscoProvisioning::isAta19x($type) => CiscoProvisioning::ataSettings(),
            $type === 'ga10' => FanvilProvisioning::PHONE_SETTINGS,
            default => [],
        };
    }

    /**
     * What else a type of phone can do on its Phone settings tab, beyond its
     * list: set how loud it rings, and ring a grown-up when it's picked up
     * and nothing's pressed (a hotline).
     */
    public static function can(string $type, string $feature): bool
    {
        return match ($feature) {
            'ringVolume', 'status' => DeviceRepository::isGrandstreamDesk($type),
            // Off-hook auto-dial, after a pause: the Grandstream desk phones and
            // the adapters have it. A W56H's or a VVX's dials the moment it's
            // picked up, which would stop it dialling anyone else.
            'hotline' => DeviceRepository::isGrandstreamDesk($type) || DeviceRepository::isAdapter($type) || YealinkProvisioning::isDesk($type),
            default => false,
        };
    }

    /** Whether this type of phone has a Phone settings tab. */
    public static function has(string $type): bool
    {
        return self::catalog($type) !== [] || self::can($type, 'ringVolume') || self::can($type, 'hotline');
    }

    /**
     * A phone's settings: the defaults, with whatever's been chosen for it.
     *
     * @param array $device DeviceRepository::toView() shape
     * @return array<string,bool|int|string>
     */
    public static function for(array $device): array
    {
        $type = (string) ($device['type'] ?? '');
        $chosen = (array) ($device['phoneSettings'] ?? []);
        $out = [];
        foreach (self::catalog($type) as $key => $s) {
            $out[$key] = array_key_exists($key, $chosen) && self::valid($type, $key, $chosen[$key])
                ? $chosen[$key] : $s['default'];
        }

        return $out;
    }

    /** Whether $value is one this type's setting can have. */
    public static function valid(string $type, string $key, mixed $value): bool
    {
        $s = self::catalog($type)[$key] ?? null;
        if ($s === null) {
            return false;
        }

        return isset($s['choices'])
            ? (is_int($value) || is_string($value)) && array_key_exists($value, $s['choices'])
            : is_bool($value);
    }

    /** A posted value as the setting's own type: a bool, or one of its choices as that choice is written. */
    public static function parse(string $type, string $key, string $raw): mixed
    {
        $s = self::catalog($type)[$key] ?? null;
        if ($s === null) {
            return null;
        }

        if (!isset($s['choices'])) {
            return $raw === '1';
        }
        // The choice itself, as its own type: 7, or 'auto'.
        foreach (array_keys($s['choices']) as $choice) {
            if ((string) $choice === $raw) {
                return $choice;
            }
        }

        return $raw;
    }
}
