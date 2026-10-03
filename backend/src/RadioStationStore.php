<?php
declare(strict_types=1);

/**
 * A radio station's name, said by the household — "Party songs" — for the
 * radio's menu ("Press 1 for… party songs"). See Radio.
 */
final class RadioStationStore extends AudioStore
{
    /** A name, not a speech. */
    private const MAX_SECONDS = 8;

    /** `storage/refusals/radio/stations`. RADIO_STATIONS_PATH moves it. */
    public function path(): string
    {
        $override = trim((string) (getenv('RADIO_STATIONS_PATH') ?: ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim(getenv('REFUSALS_PATH') ?: '/var/lib/twocans/refusals', '/') . '/radio-stations';
    }

    protected function maxSeconds(): int
    {
        return self::MAX_SECONDS;
    }

    protected function noun(): string
    {
        return "station's name";
    }
}
