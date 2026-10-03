<?php
declare(strict_types=1);

/**
 * The generated dialplan, as this box would load it, checked for the kinds of
 * mistake that don't show until a call takes that path: a jump to a label or
 * context that isn't there, Dial's U() given more than a context name (which
 * Asterisk logs and then carries on without — how incoming call limits once
 * silently never ran), and a recording started for a phone in adult mode.
 *
 * Rendered from the real database (PjsipConfig::render()), so it checks this
 * house's own phones, contacts and rules. Nothing is written or reloaded.
 */

/**
 * Parse Asterisk dialplan text into contexts => extensions => lines, keeping
 * each line's labels. Enough of the syntax for what twocans writes.
 *
 * @return array{contexts:array<string,array<string,array{labels:array<string,true>,lines:array<int,string>}>>}
 */
$parse = static function (string ...$texts): array {
    $contexts = [];
    foreach ($texts as $text) {
        $context = null;
        $exten = null;
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim(preg_replace('/(?<!\\\\);.*$/', '', $line) ?? '');
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                $context = $m[1];
                $contexts[$context] ??= [];
                $exten = null;
                continue;
            }
            if ($context === null) {
                continue;
            }
            if (preg_match('/^exten\s*=>\s*([^,]+),([^,(]+)(?:\(([^)]+)\))?,(.*)$/', $line, $m)) {
                $exten = $m[1];
                $contexts[$context][$exten] ??= ['labels' => [], 'lines' => []];
                if ($m[3] !== '') {
                    $contexts[$context][$exten]['labels'][$m[3]] = true;
                }
                $contexts[$context][$exten]['lines'][] = $m[4];
            } elseif ($exten !== null && preg_match('/^same\s*=>\s*n(?:\(([^)]+)\))?,(.*)$/', $line, $m)) {
                if ($m[1] !== '') {
                    $contexts[$context][$exten]['labels'][$m[1]] = true;
                }
                $contexts[$context][$exten]['lines'][] = $m[2];
            }
        }
    }

    return ['contexts' => $contexts];
};

/** The dialplan this box would load: the generated files and the static one. */
$load = static function () use ($parse): array {
    $files = (new PjsipConfig(new DeviceRepository()))->render();
    $static = is_readable('/etc/asterisk/extensions.conf') ? (string) file_get_contents('/etc/asterisk/extensions.conf') : '';

    return [$parse($static, ...array_values(array_filter(
        $files,
        static fn(string $name): bool => str_starts_with($name, 'dialplan-'),
        ARRAY_FILTER_USE_KEY
    ))), $files];
};

/**
 * Every jump target on a line: [kind, target]. GotoIf's true and false
 * branches, Goto, Gosub, and Dial's b() and U() options.
 *
 * @return array<int,array{0:string,1:string}>
 */
$jumps = static function (string $line): array {
    $out = [];
    if (preg_match('/^GotoIf\(.*\?([^:)]*)(?::([^)]*))?\)$/', $line, $m)) {
        foreach ([$m[1], $m[2] ?? ''] as $t) {
            if ($t !== '') {
                $out[] = ['goto', $t];
            }
        }
    } elseif (preg_match('/^Goto\((.*)\)$/', $line, $m)) {
        $out[] = ['goto', $m[1]];
    } elseif (preg_match('/^Gosub\(([^)(]*)/', $line, $m)) {
        $out[] = ['gosub', $m[1]];
    }
    if (preg_match('/^(?:Dial|Originate)\(/', $line)) {
        if (preg_match_all('/\bb\(([^()^]+)\^([^()^]+)\^/', $line, $mm)) {
            foreach ($mm[1] as $ctx) {
                $out[] = ['context', $ctx];
            }
        }
        if (preg_match_all('/\bU\(([^)]*)\)/', $line, $mm)) {
            foreach ($mm[1] as $arg) {
                $out[] = ['U', explode('^', $arg)[0]];
            }
        }
    }

    return $out;
};

return [
    test('every jump lands on a label, extension or context that exists', function () use ($load, $jumps) {
        [$plan] = $load();
        $contexts = $plan['contexts'];
        $problems = [];
        foreach ($contexts as $context => $extens) {
            foreach ($extens as $exten => $block) {
                foreach ($block['lines'] as $line) {
                    foreach ($jumps($line) as [$kind, $target]) {
                        if (str_contains($target, '$')) {
                            continue;       // decided at call time
                        }
                        $parts = array_map('trim', explode(',', $target));
                        if ($kind === 'context' || $kind === 'U') {
                            if (!isset($contexts[$parts[0]])) {
                                $problems[] = "[{$context}] {$exten}: {$kind} names missing context {$parts[0]}";
                            }
                            continue;
                        }
                        if (count($parts) === 1 && !ctype_digit($parts[0]) && !isset($block['labels'][$parts[0]])) {
                            $problems[] = "[{$context}] {$exten}: no label '{$parts[0]}' for {$line}";
                        }
                        if (count($parts) === 3 && !isset($contexts[$parts[0]])) {
                            $problems[] = "[{$context}] {$exten}: missing context {$parts[0]}";
                        }
                    }
                }
            }
        }
        assertSame([], array_slice($problems, 0, 5), implode("\n", $problems));
    }),
    test("Dial's U() is only ever given a context, which starts at s", function () use ($load) {
        [$plan, $files] = $load();
        preg_match_all('/\bU\(([^)]*)\)/', $files['dialplan-devices.conf'], $m);
        assertTrue($m[1] !== [], 'expected an incoming Dial with U()');
        foreach ($m[1] as $arg) {
            $context = explode('^', $arg)[0];
            assertTrue(isset($plan['contexts'][$context]['s']), "U({$arg}) — [{$context}] needs an s extension");
            assertFalse(str_contains($context, '('), "U({$arg}) — only a context name may come before the arguments");
        }
    }),
    test('recording is never started for a phone in adult mode', function () use ($load) {
        [$plan] = $load();
        foreach ($plan['contexts'] as $context => $extens) {
            foreach ($extens as $exten => $block) {
                foreach ($block['lines'] as $line) {
                    // (string): PHP turns a key like '601' into the number 601.
                    if (!str_contains($line, 'MixMonitor(') || (string) $exten === '601') {
                        continue;   // 601 is the test greeting: no phone's call
                    }
                    assertContains('TC_ADULT', $line, "[{$context}] {$exten} records without asking: {$line}");
                }
            }
        }
    }),
    test('every phone carries its id for the call limits', function () use ($load) {
        [, $files] = $load();
        $endpoints = preg_match_all('/^type = endpoint$/m', $files['pjsip-devices.conf']);
        $ids = preg_match_all('/^set_var = TC_DEV=\d+$/m', $files['pjsip-devices.conf']);
        assertSame($endpoints, $ids);
    }),
    test("the caller's name is played before recording starts, and only on a phone that says it", function () use ($load) {
        [$plan] = $load();
        $lines = $plan['contexts'][PjsipConfig::ANSWERED_CONTEXT]['s']['lines'] ?? [];
        $play = $record = null;
        foreach ($lines as $n => $line) {
            if (str_contains($line, 'Playback(${ARG3})')) {
                $play ??= $n;
            }
            if (str_contains($line, 'MixMonitor(')) {
                $record ??= $n;
            }
        }
        assertTrue($play !== null && $record !== null, 'expected a Playback and a MixMonitor');
        assertTrue($play < $record, 'the name must play before the recording starts');
        assertContains('TC_ANNOUNCE', $lines[0], 'the first step decides whether to say it');
    }),
    test("the incoming Dial hands the caller's name to the phone that answers", function () use ($load) {
        [, $files] = $load();
        assertContains('^${CALLER_ANNOUNCE})b(' . PjsipConfig::RINGTONE_CONTEXT . '^s^1))', $files['dialplan-devices.conf']);
    }),
    test('every caller lookup sets the name clip, so one caller\'s can\'t carry over', function () use ($load) {
        [$plan] = $load();
        foreach ($plan['contexts']['twocans-callers'] ?? [] as $exten => $block) {
            if (str_starts_with((string) $exten, '_[+]')) {
                continue;   // only re-routes to the digits
            }
            $sets = array_filter($block['lines'], static fn(string $l): bool => str_contains($l, 'Set(CALLER_ANNOUNCE='));
            assertTrue($sets !== [], "caller {$exten} doesn't set CALLER_ANNOUNCE");
        }
    }),
    test('a group call records under its own name, and never in adult mode', function () use ($load) {
        [$plan] = $load();
        $checked = 0;
        foreach ($plan['contexts'] as $context => $extens) {
            foreach ($extens as $exten => $block) {
                $text = implode("\n", $block['lines']);
                if (!str_contains($text, 'CONFBRIDGE(bridge,record_file)')) {
                    continue;
                }
                $checked++;
                // Named on ConfBridge, the profile wins and all of this is ignored.
                assertContains('CONFBRIDGE(bridge,template)=twocans_bridge', $text, "[{$context}] {$exten}");
                assertContains('CONFBRIDGE(bridge,record_file_timestamp)=no', $text, "[{$context}] {$exten}");
                assertContains(',,twocans_user)', $text, "[{$context}] {$exten} must join with no bridge profile named");
                assertContains('TC_ADULT', $text, "[{$context}] {$exten}");
                assertContains('CONFBRIDGE(bridge,record_conference)=no', $text, "[{$context}] {$exten} records in adult mode");
            }
        }
        assertTrue($checked > 0 || !str_contains(json_encode(array_keys($plan['contexts'])), 'twocans-conf'), 'expected the group calls to be checked');
    }),
];
