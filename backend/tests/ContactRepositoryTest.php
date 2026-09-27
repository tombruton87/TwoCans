<?php
declare(strict_types=1);

/**
 * The row → view shape the contact screens are built from. Pure mapping, no
 * database: toView feeds the cards, the editor and the generated caller lookup,
 * so a key that goes missing takes a screen with it.
 */

return [
    test('toView maps a row, the always-through flag included', function () {
        $view = ContactRepository::toView([
            'id' => '7', 'name' => 'Mum', 'relationship' => 'mum',
            'number_e164' => '+447700900876', 'color' => '#FFC857', 'photo_path' => 'a.jpg',
            'call_window' => 'anytime', 'speed_dial' => '123',
            'allow_in' => '1', 'allow_out' => '0', 'sos' => '0', 'ring_both' => '1',
            'is_group' => '0', 'always_ring' => '1',
        ]);

        assertSame(7, $view['id']);
        assertSame('+447700900876', $view['number']);
        assertSame('123', $view['code']);
        assertTrue($view['allowIn']);
        assertFalse($view['allowOut']);
        assertTrue($view['ringboth']);
        assertFalse($view['sos']);
        assertFalse($view['isGroup']);
        assertTrue($view['alwaysRing']);
    }),
    test('toView reads a row from before migration 030 as not always through', function () {
        // The column arrives with the migration, so an old row simply has no key
        // — which has to mean "off" rather than a warning on every card.
        $view = ContactRepository::toView([
            'id' => '8', 'name' => 'Dad', 'relationship' => '', 'number_e164' => '',
            'color' => '#FF7A59', 'photo_path' => null, 'call_window' => 'anytime',
            'speed_dial' => null, 'allow_in' => 1, 'allow_out' => 1,
            'sos' => 0, 'ring_both' => 0, 'is_group' => 0,
        ]);

        assertFalse($view['alwaysRing']);
        assertSame('', $view['code']);
        assertSame('', $view['photo']);
    }),
];
