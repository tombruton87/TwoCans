<?php
declare(strict_types=1);

/** Grandstream provisioning (GHP621, HT801, HT802): MAC normalisation and config generation. */

$phone = static fn(string $name, string $user): array
    => ['name' => $name, 'sipUsername' => $user, 'sipSecret' => 's3cret-' . $user, 'transport' => 'udp', 'extension' => '201'];

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
        assertContains('<P47>' . PjsipConfig::domain() . ':' . PjsipConfig::port('udp') . '</P47>', $xml);
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
    test('the SIP server carries the port, so a box not on 5060 still works', function () {
        assertSame(PjsipConfig::domain() . ':' . PjsipConfig::port('udp'), GrandstreamProvisioning::server());
    }),
    test('an HT801 gets its one socket and nothing for a second', function () use ($phone) {
        $xml = (new GrandstreamProvisioning())->ataXml('ht801', [1 => $phone('Hall', 'hall-1')]);
        assertContains('<P271>1</P271>', $xml);
        assertContains('<P47>' . GrandstreamProvisioning::server() . '</P47>', $xml);
        assertContains('<P35>hall-1</P35>', $xml);
        assertContains('<P34>s3cret-hall-1</P34>', $xml);
        assertContains('<P130>0</P130>', $xml);
        assertNotContains('<P401>', $xml);
        assertNotContains('<P735>', $xml);
    }),
    test('an HT802 gets each socket its own account', function () use ($phone) {
        $xml = (new GrandstreamProvisioning())->ataXml('ht802', [1 => $phone('Hall', 'hall-1'), 2 => $phone('Kitchen', 'kitchen-2')]);
        assertContains('<P35>hall-1</P35>', $xml);
        assertContains('<P401>1</P401>', $xml);
        assertContains('<P703>Kitchen</P703>', $xml);
        assertContains('<P747>' . GrandstreamProvisioning::server() . '</P747>', $xml);
        assertContains('<P735>kitchen-2</P735>', $xml);
        assertContains('<P736>kitchen-2</P736>', $xml);
        assertContains('<P734>s3cret-kitchen-2</P734>', $xml);
    }),
    test('an HT802 with one phone switches the empty socket off', function () use ($phone) {
        $xml = (new GrandstreamProvisioning())->ataXml('ht802', [1 => $phone('Hall', 'hall-1')]);
        assertContains('<P271>1</P271>', $xml);
        assertContains('<P401>0</P401>', $xml);
        assertNotContains('<P735>', $xml);
    }),
    test('only socket 2 in use still switches socket 1 off', function () use ($phone) {
        $xml = (new GrandstreamProvisioning())->ataXml('ht802', [2 => $phone('Kitchen', 'kitchen-2')]);
        assertContains('<P271>0</P271>', $xml);
        assertContains('<P401>1</P401>', $xml);
    }),
    test('names are escaped for XML', function () use ($phone) {
        $xml = (new GrandstreamProvisioning())->ataXml('ht801', [1 => $phone('Tom & Jo\'s', 'tj-1')]);
        assertContains('<P3>Tom &amp; Jo&apos;s</P3>', $xml);
        assertTrue(simplexml_load_string($xml) !== false, 'well-formed XML');
    }),
    test('keyCount matches the hotkey map', function () {
        assertSame(count(GrandstreamProvisioning::HOTKEY_PCODES), GrandstreamProvisioning::keyCount());
    }),
];
