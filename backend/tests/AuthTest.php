<?php
declare(strict_types=1);

/** Password policy and the role-permission matrix. */

return [
    test('passwordProblem rejects a short password', function () {
        assertTrue(Auth::passwordProblem('short') !== null);
    }),
    test('passwordProblem rejects a mismatched confirmation', function () {
        assertTrue(Auth::passwordProblem('longenough123', 'different123') !== null);
    }),
    test('passwordProblem rejects a weak password', function () {
        assertTrue(Auth::passwordProblem('twocans123') !== null);
    }),
    test('passwordProblem accepts a strong password', function () {
        assertNull(Auth::passwordProblem('correct-horse-battery'));
    }),
    test('canFor grants the Owner everything', function () {
        assertTrue(Auth::canFor('Owner', 'billing'));
        assertTrue(Auth::canFor('Owner', 'system'));
        assertTrue(Auth::canFor('Owner', 'backups'));
    }),
    test('canFor grants Admin the listed permissions only', function () {
        assertTrue(Auth::canFor('Admin', 'rules'));
        assertTrue(Auth::canFor('Admin', 'system'));
        assertFalse(Auth::canFor('Admin', 'billing'));
        assertFalse(Auth::canFor('Admin', 'backups'));
    }),
    test('canFor grants Viewer nothing', function () {
        assertFalse(Auth::canFor('Viewer', 'rules'));
        assertFalse(Auth::canFor('Viewer', 'system'));
        assertFalse(Auth::canFor('Viewer', 'listen'));
    }),
];
