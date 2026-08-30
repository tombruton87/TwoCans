<?php
declare(strict_types=1);

/**
 * Assertion helpers for the tiny test runner. Keep them minimal on purpose —
 * enough to write a clear assertion, nothing that would want its own framework.
 */

/** Wrap a test in the [label, callable] shape the runner expects. */
function test(string $label, callable $fn): array
{
    return [$label, $fn];
}

function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $message = $message !== '' ? $message
            : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
        throw new RuntimeException($message);
    }
}

function assertTrue(bool $cond, string $message = 'expected true'): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

function assertFalse(bool $cond, string $message = 'expected false'): void
{
    if ($cond) {
        throw new RuntimeException($message);
    }
}

function assertNull($actual, string $message = 'expected null'): void
{
    if ($actual !== null) {
        throw new RuntimeException($message . ' (got ' . var_export($actual, true) . ')');
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message !== '' ? $message : 'expected to find "' . $needle . '"');
    }
}

function assertNotContains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message !== '' ? $message : 'expected NOT to find "' . $needle . '"');
    }
}
