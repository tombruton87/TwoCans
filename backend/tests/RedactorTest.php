<?php
declare(strict_types=1);

/** What ./twocans report takes out of a support report, and what it leaves. */

$scrub = static fn(string $text, array $exact = [], array $names = []): string => Redactor::scrub($text, $exact, $names);

return [
    test('passwords and the house domain go, longest first', function () use ($scrub) {
        $out = $scrub('pw hunter22hunter22 short hunter22 at phone.example.org', [
            'hunter22' => '[secret]', 'hunter22hunter22' => '[secret]', 'phone.example.org' => '[house domain]',
        ]);
        assertSame('pw [secret] short [secret] at [house domain]', $out);
    }),
    test('phone numbers, emails and provider IDs go', function () use ($scrub) {
        // Built here rather than written out: a literal in the right shape trips
        // GitHub's secret scanning, though it's no one's account.
        $sid = 'AC' . str_repeat('0123456789abcdef', 2);
        $out = $scrub("from +447700900123 to 07700900123 via 447700900123, mail nan@example.com, {$sid}");
        assertSame('from [number] to [number] via [number], mail [email], [provider id]', $out);
    }),
    test('public addresses go; home-network ones stay', function () use ($scrub) {
        assertSame('wan [public ip] lan 192.168.1.20 docker 172.18.0.5 any 0.0.0.0',
            $scrub('wan 203.0.113.9 lan 192.168.1.20 docker 172.18.0.5 any 0.0.0.0'));
    }),
    test('names go as whole words, in any case, and nothing else does', function () use ($scrub) {
        assertSame('[name] rang [name]; automatically, Nancy and Tomorrow stay',
            $scrub('Nana rang TOM; automatically, Nancy and Tomorrow stay', [], ['Nana', 'Tom']));
    }),
    test('versions, ports, call ids and errors are left alone', function () use ($scrub) {
        $text = 'Asterisk 22.5.1 on port 5060, call 1790545957.142: 403 Forbidden';
        assertSame($text, $scrub($text));
    }),
    test('short values are never used as patterns', function () use ($scrub) {
        assertSame('a 12 b', $scrub('a 12 b', ['12' => '[secret]'], ['ab']));
    }),
];
