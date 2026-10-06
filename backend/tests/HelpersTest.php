<?php
declare(strict_types=1);

/** The small output helpers used across every view. */

return [
    test('url() drops empty params', function () {
        assertSame('/?screen=phones', url(['screen' => 'phones', 'device' => '']));
    }),
    test('fmt_duration formats minutes:seconds', function () {
        assertSame('8:08', fmt_duration(488));
        assertSame('0:00', fmt_duration(0));
    }),
    test('initial() takes the first letter', function () {
        assertSame('P', initial('Priya'));
        assertSame('?', initial(''));
    }),
    test('e() escapes HTML', function () {
        assertSame('&lt;script&gt;', e('<script>'));
    }),
    test('back() keeps a path on this site', function () {
        $_POST['_back'] = '/?screen=phones&device=3';
        assertSame('/?screen=phones&device=3', back());
    }),
    test('back() refuses to send anyone off-site', function () {
        // The last two are the ones parse_url alone lets through: browsers
        // normalise the backslash to a slash, making it protocol-relative.
        foreach ([
            'https://evil.com/x' => '/x',
            '//evil.com/x' => '/x',
            '///evil.com/x' => '/',
            '/\\evil.com' => '/',
            '/\\/evil.com' => '/',
        ] as $ref => $want) {
            $_POST['_back'] = $ref;
            assertSame($want, back());
        }
        unset($_POST['_back']);
    }),
    test('highlight() escapes before it marks', function () {
        $out = highlight('<b>hello</b>', 'hello');
        assertContains('<mark class="tc-mark">hello</mark>', $out);
        assertContains('&lt;b&gt;', $out);
    }),
];
