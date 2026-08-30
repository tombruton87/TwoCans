<?php
declare(strict_types=1);

/** Grandstream GHP621 provisioning: MAC normalisation and config generation. */

return [
    test('normalizeMac accepts colons and dashes', function () {
        assertSame('000B82C12345', GrandstreamProvisioning::normalizeMac('00:0B:82:C1:23:45'));
        assertSame('000B82C12345', GrandstreamProvisioning::normalizeMac('00-0b-82-c1-23-45'));
        assertSame('000B82C12345', GrandstreamProvisioning::normalizeMac('000B82C12345'));
    }),
    test('normalizeMac rejects short or empty input', function () {
        assertSame('', GrandstreamProvisioning::normalizeMac('00:0B:82'));
        assertSame('', GrandstreamProvisioning::normalizeMac(''));
    }),
    test('xml includes the SIP account P-codes', function () {
        $device = ['name' => 'Playroom', 'sipUsername' => 'playroom-ab12', 'sipSecret' => 'secret', 'transport' => 'udp', 'extension' => '201'];
        $xml = (new GrandstreamProvisioning())->xml($device, []);
        assertContains('<P271>1</P271>', $xml);
        assertContains('<P3>Playroom</P3>', $xml);
        assertContains('<P47>' . PjsipConfig::domain() . '</P47>', $xml);
        assertContains('<P35>playroom-ab12</P35>', $xml);
        assertContains('<P36>playroom-ab12</P36>', $xml);
        assertContains('<P34>secret</P34>', $xml);
    }),
    test('xml emits one P-code per hotkey', function () {
        $device = ['name' => 'Playroom', 'sipUsername' => 'playroom-ab12', 'sipSecret' => 'secret', 'transport' => 'udp', 'extension' => '201'];
        $xml = (new GrandstreamProvisioning())->xml($device, [1 => '+447700900123', 2 => '700']);
        assertContains('<P2440>+447700900123</P2440>', $xml);
        assertContains('<P2441>700</P2441>', $xml);
        assertContains('<P2442></P2442>', $xml);
    }),
    test('keyCount matches the hotkey map', function () {
        assertSame(count(GrandstreamProvisioning::HOTKEY_PCODES), GrandstreamProvisioning::keyCount());
    }),
];
