<?php
declare(strict_types=1);

/**
 * The phone line's number box, which takes one number or several.
 *
 * Numbers are separated by spaces, but a single number is often written with
 * spaces in it, so the two have to be told apart — and the first number has to
 * stay first, because it is the caller ID for every outgoing call.
 */

$uk = static function (callable $fn): void {
    $old = getenv('DEFAULT_COUNTRY_CODE');
    putenv('DEFAULT_COUNTRY_CODE=44');
    try {
        $fn();
    } finally {
        $old === false ? putenv('DEFAULT_COUNTRY_CODE') : putenv('DEFAULT_COUNTRY_CODE=' . $old);
    }
};

return [
    test('one number written with spaces stays one number', function () {
        assertSame([['+441632960753'], null], TrunkRepository::parseNumbers('+44 1632 960753'));
    }),
    test('several numbers separated by spaces', function () {
        assertSame(
            [['+441632960753', '+447700900123'], null],
            TrunkRepository::parseNumbers('+441632960753 +447700900123')
        );
    }),
    test('several numbers that each have spaces in them', function () {
        assertSame(
            [['+441632960753', '+447700900123'], null],
            TrunkRepository::parseNumbers('+44 1632 960753 +44 7700 900123')
        );
    }),
    test('national numbers take the house country code', function () use ($uk) {
        $uk(function () {
            assertSame(
                [['+441632960753', '+447700900123'], null],
                TrunkRepository::parseNumbers('01632960753 07700900123')
            );
        });
    }),
    test('commas separate too', function () use ($uk) {
        $uk(function () {
            assertSame(
                [['+441632960753', '+447700900123'], null],
                TrunkRepository::parseNumbers('+441632960753, 07700 900123')
            );
        });
    }),
    test('the first number stays first and repeats are dropped', function () {
        assertSame(
            [['+447700900123', '+441632960753'], null],
            TrunkRepository::parseNumbers('+447700900123 +441632960753 +447700900123')
        );
    }),
    test('anything that is not a number is named back', function () {
        [, $bad] = TrunkRepository::parseNumbers('+441632960753 hello');
        assertSame('+441632960753 hello', $bad);
    }),
    test('an incoming call is matched to the line number it came in on', function () {
        $line = ['+441632960753', '+447700900123'];
        assertSame('+447700900123', TrunkRepository::lineNumberFor('447700900123', $line));
        assertSame('+441632960753', TrunkRepository::lineNumberFor('+441632960753', $line));
    }),
    test('a call that did not come in on the line has no line number', function () {
        $line = ['+441632960753'];
        assertSame(null, TrunkRepository::lineNumberFor('601', $line));
        assertSame(null, TrunkRepository::lineNumberFor('s', $line));
        assertSame(null, TrunkRepository::lineNumberFor('441234567890', $line));
    }),
    test('an empty box is no numbers, not an error', function () {
        assertSame([[], null], TrunkRepository::parseNumbers('   '));
    }),
];
