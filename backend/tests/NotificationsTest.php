<?php
declare(strict_types=1);

/** Notification config validation and the Mailgun region mapping. */

return [
    test('Mailgun base URL maps regions', function () {
        assertSame('https://api.mailgun.net', Mailgun::baseUrl('us'));
        assertSame('https://api.eu.mailgun.net', Mailgun::baseUrl('eu'));
        assertSame('https://api.mailgun.net', Mailgun::baseUrl('bogus'));
    }),
    test('normalizeDomain accepts a bare or URL domain', function () {
        assertSame('mg.example.com', NotificationRepository::normalizeDomain('mg.example.com'));
        assertSame('mg.example.com', NotificationRepository::normalizeDomain('https://mg.example.com/'));
    }),
    test('normalizeDomain rejects junk', function () {
        assertSame('', NotificationRepository::normalizeDomain('not a domain'));
        assertSame('', NotificationRepository::normalizeDomain(''));
    }),
    test('normalizeEmails keeps only valid addresses and de-duplicates', function () {
        assertSame(
            'a@example.com, b@example.com',
            NotificationRepository::normalizeEmails('a@example.com, b@example.com, a@example.com, not-an-email')
        );
    }),
    test('normalizeKumaUrl accepts http(s) only', function () {
        assertSame('https://kuma.example.com/api/push/abc', NotificationRepository::normalizeKumaUrl('https://kuma.example.com/api/push/abc'));
        assertSame('', NotificationRepository::normalizeKumaUrl('ftp://nope'));
        assertSame('', NotificationRepository::normalizeKumaUrl(''));
    }),
];
