<?php
declare(strict_types=1);

/** E.164 normalisation — the one-way door that makes allowlist matching exact. */

return [
    test('keeps a full E.164 number as-is', function () {
        assertSame('+447700900123', ContactRepository::toE164('+44 7700 900123'));
    }),
    test('normalises a national number to E.164', function () {
        assertSame('+447700900123', ContactRepository::toE164('07700 900123'));
    }),
    test('normalises international 00 dialling', function () {
        assertSame('+447700900123', ContactRepository::toE164('0044 7700 900123'));
    }),
    test('keeps another country code intact', function () {
        assertSame('+12345678901', ContactRepository::toE164('+1 234 567 8901'));
    }),
    test('refuses short codes', function () {
        assertSame('', ContactRepository::toE164('911'));
        assertSame('', ContactRepository::toE164('123'));
    }),
    test('uses DEFAULT_COUNTRY_CODE for a bare national number', function () {
        $old = getenv('DEFAULT_COUNTRY_CODE');
        putenv('DEFAULT_COUNTRY_CODE=1');
        try {
            assertSame('+17700900123', ContactRepository::toE164('07700900123'));
        } finally {
            $old === false ? putenv('DEFAULT_COUNTRY_CODE') : putenv('DEFAULT_COUNTRY_CODE=' . $old);
        }
    }),
    test('digits() strips formatting', function () {
        assertSame('07700900123', ContactRepository::digits('07 700 900 123'));
    }),
];
