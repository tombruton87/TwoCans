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
    test('hotkeys are the six MPKs, as labelled speed dials', function () use ($phone) {
        $xml = (new GrandstreamProvisioning())->xml(
            $phone('Playroom', 'playroom-ab12'),
            [1 => '+447700900123', 6 => '700'],
            ['+447700900123' => 'Grandma']
        );
        // Key 1: speed dial on account 1, labelled with who it rings.
        assertContains('<P365>0</P365>', $xml);
        assertContains('<P366>0</P366>', $xml);
        assertContains('<P367>Grandma</P367>', $xml);
        assertContains('<P368>+447700900123</P368>', $xml);
        // Key 6, with no name known: the number is the label.
        assertContains('<P385>0</P385>', $xml);
        assertContains('<P387>700</P387>', $xml);
        assertContains('<P388>700</P388>', $xml);
        // Key 2 is unset, and cleared on the phone.
        assertContains('<P369>-1</P369>', $xml);
        assertContains('<P372></P372>', $xml);
    }),
    test('the file is the P-value format (config version 1), with the MAC', function () use ($phone) {
        // Version 2 means named settings; a phone given P-values under it
        // fetches the file and quietly ignores every one.
        $gs = (new GrandstreamProvisioning())->xml($phone('Hall', 'hall-1') + ['mac' => '00:0B:82:C1:23:45'], []);
        assertContains('<config version="1">', $gs);
        assertNotContains('<config version="2">', $gs);
        assertContains('<mac>000b82c12345</mac>', $gs);
        $ata = (new GrandstreamProvisioning())->ataXml('ht801', [1 => $phone('Hall', 'hall-1') + ['mac' => '000B82C12345']]);
        assertContains('<config version="1">', $ata);
        assertContains('<mac>000b82c12345</mac>', $ata);
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
        $xml = (new GrandstreamProvisioning())->ataXml('ht801', [1 => $phone('Sam & Jo\'s', 'tj-1')]);
        assertContains('<P3>Sam &amp; Jo&apos;s</P3>', $xml);
        assertTrue(simplexml_load_string($xml) !== false, 'well-formed XML');
    }),
    test('the GHP621 has six hotkeys', function () {
        assertSame(6, GrandstreamProvisioning::keyCount());
    }),
    test('a GHP61x is sent its three keys, and nothing for keys it lacks', function () use ($phone) {
        $xml = (new GrandstreamProvisioning())->xml($phone('Hall', 'hall-1') + ['type' => 'ghp611'], [1 => '600', 3 => '601']);
        assertContains('<P368>600</P368>', $xml);
        assertContains('<P376>601</P376>', $xml);
        assertFalse(str_contains($xml, '<P377>'), 'no key 4');
        assertFalse(str_contains($xml, '<P388>'), 'no key 6');
    }),
    test('every desk phone is set up the same way, with its own keys and colour', function () {
        foreach (DeviceRepository::typesIn('desk') as $type => $t) {
            assertTrue(DeviceRepository::isDesk($type), $type);
            assertTrue(in_array($t['keys'], [3, 6], true), $type . ' keys');
            assertTrue(in_array($t['body'], ['white', 'black'], true), $type . ' colour');
            assertSame($t['keys'] === 3 ? 'strip' : 'card', $t['faceplate'], $type . ' faceplate');
            assertTrue($t['autoAnswer'], $type . ' answers pages');
        }
        assertSame(3, DeviceRepository::keys('ghp610'));
        assertSame(6, DeviceRepository::keys('ghp620'));
        assertSame(0, DeviceRepository::keys('linphone'));
        assertFalse(DeviceRepository::isDesk('ht802'));
    }),
    test('each kind of phone is offered, and every type belongs to one', function () {
        foreach (DeviceRepository::TYPES as $type => $t) {
            assertTrue(isset(DeviceRepository::FAMILIES[$t['family']]), $type);
        }
        assertSame(['linphone'], array_keys(DeviceRepository::typesIn('app')));
    }),
];
