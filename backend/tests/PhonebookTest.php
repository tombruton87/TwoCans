<?php
declare(strict_types=1);

/** Remote phonebook: the allowlist in Grandstream and Yealink XML. */

return [
    test('grandstream() emits AddressBook entries', function () {
        $contacts = [
            ['name' => 'Grandma', 'number_e164' => '+447700900123', 'allow_out' => 1, 'is_group' => 0],
            ['name' => 'Mum', 'number_e164' => '+447700900124', 'allow_out' => 1, 'is_group' => 0],
        ];
        $xml = Phonebook::grandstream($contacts);
        assertContains('<AddressBook>', $xml);
        assertContains('<Firstname>Grandma</Firstname>', $xml);
        assertContains('<Phonenumber>+447700900123</Phonenumber>', $xml);
    }),
    test('yealink() emits DirectoryEntry entries', function () {
        $contacts = [
            ['name' => 'Grandma', 'number_e164' => '+447700900123', 'allow_out' => 1, 'is_group' => 0],
        ];
        $xml = Phonebook::yealink($contacts);
        assertContains('<YealinkIPPhoneDirectory>', $xml);
        assertContains('<Name>Grandma</Name>', $xml);
        assertContains('<Telephone>+447700900123</Telephone>', $xml);
    }),
    test('groups, blocked and empty contacts are excluded', function () {
        $contacts = [
            ['name' => 'Allowed', 'number_e164' => '+447700900123', 'allow_out' => 1, 'is_group' => 0],
            ['name' => 'Blocked out', 'number_e164' => '+447700900124', 'allow_out' => 0, 'is_group' => 0],
            ['name' => 'Grandma & Grandad', 'number_e164' => '', 'allow_out' => 1, 'is_group' => 1],
            ['name' => 'No number', 'number_e164' => '', 'allow_out' => 1, 'is_group' => 0],
        ];
        $xml = Phonebook::yealink($contacts);
        assertContains('Allowed', $xml);
        assertNotContains('Blocked out', $xml);
        assertNotContains('Grandma &amp; Grandad', $xml);
        assertNotContains('No number', $xml);
    }),
];
