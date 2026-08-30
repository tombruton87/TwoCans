<?php
declare(strict_types=1);

/**
 * Structural check of the deny-by-default rule: every action case in
 * actions.php must be listed in Permissions::ACTIONS, self-authorised, or
 * allowed before a session exists. If this ever fails, an action has shipped
 * unguarded.
 */

return [
    test('every switch case is guarded by the permission map', function () {
        $src = (string) file_get_contents(__DIR__ . '/../src/actions.php');
        preg_match_all("/case '([a-z_]+)'/", $src, $m);
        $cases = array_unique($m[1]);

        assertTrue(count($cases) > 0, 'could not find any action cases');

        foreach ($cases as $case) {
            $guarded = array_key_exists($case, Permissions::ACTIONS)
                || in_array($case, Permissions::SELF_AUTHORISED, true)
                || in_array($case, Permissions::PRE_AUTH, true);
            assertTrue($guarded, "action '{$case}' is not in the permission map");
        }
    }),
    test('guarded actions do not overlap the self-authorised or pre-auth sets', function () {
        foreach (Permissions::SELF_AUTHORISED as $action) {
            assertFalse(array_key_exists($action, Permissions::ACTIONS), "{$action} is both guarded and self-authorised");
        }
        foreach (Permissions::PRE_AUTH as $action) {
            assertFalse(array_key_exists($action, Permissions::ACTIONS), "{$action} is both guarded and pre-auth");
        }
    }),
];
