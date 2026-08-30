<?php
declare(strict_types=1);

/** Dial-plan prefix rules: normalisation, pattern shape, and guardrails. */

return [
    test('normalizePrefix strips non-digits', function () {
        assertSame('07', DialplanRuleRepository::normalizePrefix(' 07- '));
    }),
    test('pattern is prefix plus one-or-more digits', function () {
        assertSame('_07X.', DialplanRuleRepository::pattern('07'));
    }),
    test('problem() rejects an empty prefix', function () {
        assertTrue(DialplanRuleRepository::problem('') !== null);
    }),
    test('problem() rejects an emergency number', function () {
        assertTrue(DialplanRuleRepository::problem('999') !== null);
    }),
    test('problem() accepts an ordinary prefix', function () {
        assertNull(DialplanRuleRepository::problem('07'));
    }),
];
