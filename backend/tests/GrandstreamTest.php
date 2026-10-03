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
        // The voicemail button plays the phone's own messages.
        assertContains('<P33>' . PjsipConfig::VOICEMAIL_NUMBER . '</P33>', $xml);
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
    test('every Grandstream desk phone is set up the same way, with its own keys and colour', function () {
        foreach (DeviceRepository::typesIn('desk') as $type => $t) {
            assertTrue(DeviceRepository::isDesk($type), $type);
            if ($t['brand'] !== 'grandstream') {
                continue;
            }
            assertTrue(DeviceRepository::isGrandstreamDesk($type), $type);
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
    test('a child\'s phone: its ring volume locked, no night-light, no boot beep, no call waiting, nothing to switch off', function () {
        $device = ['id' => 7, 'name' => 'Bedroom', 'sipUsername' => 'bedroom-ab12', 'sipSecret' => 's3cret', 'transport' => 'udp',
            'extension' => '201', 'ringVolume' => 2, 'hotline' => '', 'hotlineDelay' => 4];
        $xml = (new GrandstreamProvisioning())->xml($device, []);
        foreach (['<P8352>2</P8352>', '<P8392>1</P8392>', '<P22482>0</P22482>', '<P8371>2</P8371>', '<P22486>0</P22486>',
                  '<P91>1</P91>', '<P1565>2</P1565>', '<P258>1</P258>', '<P1310>1</P1310>', '<P276>1</P276>',
                  '<P22513>0</P22513>', '<P145>0</P145>', '<P1414>0</P1414>', '<P238>2</P238>', '<P81>1</P81>', '<P71></P71>'] as $code) {
            assertContains($code, $xml);
        }
        // Without a request to say how it reached twocans, nothing that depends on it.
        assertFalse(str_contains($xml, '<P237>'));
        assertContains('<P8308></P8308>', $xml);
        // Not "only calls from the proxy": that waits for a daytime test.
        assertFalse(str_contains($xml, '<P2347>'));
    }),
    test('picked up and nothing pressed, it rings the grown-up chosen, after the delay chosen', function () {
        $device = ['id' => 7, 'name' => 'Bedroom', 'sipUsername' => 'bedroom-ab12', 'sipSecret' => 's3cret', 'transport' => 'udp',
            'extension' => '201', 'ringVolume' => 4, 'hotline' => '+447700900123', 'hotlineDelay' => 3];
        $xml = (new GrandstreamProvisioning())->xml($device, []);
        assertContains('<P71>+447700900123</P71>', $xml);
        assertContains('<P8388>3</P8388>', $xml);
    }),
    test('it keeps fetching from exactly where it reached twocans, and reports its handset there', function () {
        $device = ['id' => 7, 'name' => 'Bedroom', 'sipUsername' => 'bedroom-ab12', 'sipSecret' => 's3cret', 'transport' => 'udp',
            'extension' => '201', 'ringVolume' => 4, 'hotline' => '', 'hotlineDelay' => 4];
        $xml = (new GrandstreamProvisioning())->xml($device, [], [], '192.0.2.10:8083');
        assertContains('<P212>1</P212>', $xml);
        assertContains('<P237>192.0.2.10:8083/grandstream</P237>', $xml);
        $key = GrandstreamProvisioning::eventKey($device);
        assertSame(20, strlen($key));
        assertContains('<P8308>http://192.0.2.10:8083/grandstream/event?d=7&amp;e=offhook&amp;k=' . $key . '</P8308>', $xml);
        assertContains('e=onhook', $xml);
        assertContains('e=started', $xml);
        // Over HTTPS, it carries on over HTTPS.
        $secure = (new GrandstreamProvisioning())->xml($device, [], [], 'phone.example.com', true);
        assertContains('<P212>2</P212>', $secure);
        assertContains('<P8309>https://phone.example.com/grandstream/event?', $secure);
        // Another phone, or a new secret, is another key.
        assertFalse($key === GrandstreamProvisioning::eventKey(['id' => 8] + $device));
        assertFalse($key === GrandstreamProvisioning::eventKey(['sipSecret' => 'other'] + $device));
    }),
    test('what a phone says about its handset is kept: off the hook since when, back on, started up', function () {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $devices = new DeviceRepository();
            $phone = $devices->create('Test bedroom', 'ghp621', 'udp');
            $id = (int) $phone['id'];
            $devices->phoneEvent($id, 'offhook');
            $since = DeviceRepository::toView($devices->find($id))['offhookSince'];
            assertTrue($since !== null);
            $devices->phoneEvent($id, 'offhook');
            assertSame($since, DeviceRepository::toView($devices->find($id))['offhookSince'], 'still the first time');
            $devices->phoneEvent($id, 'onhook');
            assertSame(null, DeviceRepository::toView($devices->find($id))['offhookSince']);
            $devices->phoneEvent($id, 'started');
            assertTrue(DeviceRepository::toView($devices->find($id))['startedAt'] !== null);
            $devices->phoneEvent($id, 'nonsense');
            $devices->setRingVolume($id, 12);
            assertSame(8, DeviceRepository::toView($devices->find($id))['ringVolume']);
            $devices->setHotline($id, '+447700900123', 1);
            assertSame(2, DeviceRepository::toView($devices->find($id))['hotlineDelay']);
        } finally {
            $pdo->rollBack();
        }
    }),
    test('each setting can be changed for one phone, and only what differs is kept', function () {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $devices = new DeviceRepository();
            $phone = $devices->create('Test landing', 'ghp621', 'udp');
            $id = (int) $phone['id'];
            assertTrue($devices->setPhoneSetting($id, 'call_waiting', true));
            assertTrue($devices->setPhoneSetting($id, 'message_light', 'blink'));
            assertTrue($devices->setPhoneSetting($id, 'offhook_timeout', 60));
            assertTrue($devices->setPhoneSetting($id, 'report_status', false));
            assertFalse($devices->setPhoneSetting($id, 'message_light', 'disco'), 'not a choice');
            assertFalse($devices->setPhoneSetting($id, 'nonsense', true));
            assertFalse($devices->setPhoneSetting($id, 'ssh', 'yes'), 'a switch is true or false');

            $d = DeviceRepository::toView($devices->find($id));
            $xml = (new GrandstreamProvisioning())->xml($d, [], [], '192.0.2.10:8083');
            assertContains('<P91>0</P91>', $xml);        // call waiting on (0 is Yes)
            assertContains('<P8371>0</P8371>', $xml);    // blinking
            assertContains('<P1485>60</P1485>', $xml);
            assertContains('<P8308></P8308>', $xml);     // not reporting
            assertContains('<P1565>2</P1565>', $xml);    // the rest as they were

            // Back to the default: forgotten, so a new default would reach it.
            $devices->setPhoneSetting($id, 'call_waiting', false);
            assertFalse(array_key_exists('call_waiting', DeviceRepository::toView($devices->find($id))['phoneSettings']));
            assertSame(['message_light' => 'blink', 'offhook_timeout' => 60, 'report_status' => false],
                DeviceRepository::toView($devices->find($id))['phoneSettings']);
        } finally {
            $pdo->rollBack();
        }
    }),
    test('every setting has a group, a label, a default it accepts', function () {
        foreach (GrandstreamProvisioning::PHONE_SETTINGS as $key => $s) {
            assertTrue(in_array($s['group'], ['Ringing', 'Lights & sounds', 'Calls', 'Safety', 'Looking after it'], true), $key);
            assertTrue($s['label'] !== '' && $s['hint'] !== '', $key);
            assertTrue(GrandstreamProvisioning::validSetting($key, $s['default']), $key . "'s default");
        }
    }),
    test("an adapter's sockets each get their own settings; the adapter's are shared", function () {
        $phone = static fn(string $name, array $settings = [], string $hotline = ''): array => [
            'type' => 'ht802', 'name' => $name, 'sipUsername' => strtolower($name), 'sipSecret' => 's',
            'mac' => '000B82AA0001', 'hotline' => $hotline, 'hotlineDelay' => 6, 'phoneSettings' => $settings,
        ];
        $xml = (new GrandstreamProvisioning())->ataXml('ht802', [
            1 => $phone('Hall', ['earpiece' => '1', 'call_waiting' => true, 'ssh' => true], '07700900123'),
            2 => $phone('Den', ['caller_id' => '0', 'ssh' => false]),
        ]);
        assertContains('<P249>1</P249>', $xml);        // socket 1: louder in the earpiece
        assertFalse(str_contains($xml, '<P283>'), 'socket 2 keeps its own volume');
        assertContains('<P91>0</P91>', $xml);          // socket 1: call waiting on (0 is No to "disable")
        assertContains('<P791>1</P791>', $xml);        // socket 2: off
        assertContains('<P863>0</P863>', $xml);        // socket 2: Bellcore caller ID, as chosen
        assertContains('<P191>0</P191>', $xml);        // no star codes of its own
        assertContains('<P751>0</P751>', $xml);
        assertContains('<P4424>0</P4424>', $xml);      // a tap of the hook isn't hold
        assertContains('<P71>07700900123</P71><P4045>6</P4045>', str_replace(["\n", ' '], '', $xml));
        assertContains('<P771></P771>', $xml);
        assertContains('<P276>0</P276>', $xml, "the adapter's SSH: socket 1's choice");
        assertContains('<P88>1</P88>', $xml);
        assertContains('<P238>2</P238>', $xml);
        if (ContactRepository::countryCode() === '44') {
            assertContains('<P853>9</P853>', $xml);    // socket 1: BT caller ID, for the UK
            assertContains('<P854>10</P854>', $xml);   // UK line impedance
            assertContains('<P4430>25</P4430>', $xml); // and ring
        }
    }),
    test('each kind of Grandstream has its own settings; an adapter has a hotline but no ring volume', function () {
        assertTrue(isset(PhoneSettings::catalog('ht801')['earpiece']));
        assertFalse(isset(PhoneSettings::catalog('ht801')['ring_lock']));
        assertTrue(PhoneSettings::can('ht802', 'hotline'));
        assertFalse(PhoneSettings::can('ht802', 'ringVolume'));
        assertTrue(PhoneSettings::valid('ht801', 'earpiece', 'factory'));
        assertTrue(PhoneSettings::valid('ht801', 'earpiece', '6'));
        assertFalse(PhoneSettings::valid('ht801', 'earpiece', '7'));
        foreach (GrandstreamProvisioning::ATA_SETTINGS as $key => $s) {
            assertTrue($s['label'] !== '' && $s['hint'] !== '', $key);
            assertTrue(PhoneSettings::valid('ht802', $key, $s['default']), $key . "'s default");
        }
    }),
    test('a rotary phone: its clicks heard as numbers, and longer to finish dialling', function () {
        $phone = static fn(string $name, array $settings = []): array => [
            'type' => 'ht802', 'name' => $name, 'sipUsername' => strtolower($name), 'sipSecret' => 's',
            'mac' => '000B82AA0002', 'phoneSettings' => $settings,
        ];
        $xml = str_replace(["\n", ' '], '', (new GrandstreamProvisioning())->ataXml('ht802', [
            1 => $phone('Hall', ['rotary' => true]),
            2 => $phone('Den', ['dial_wait' => 10]),
        ]));
        assertContains('<P20521>1</P20521>', $xml);    // socket 1 hears pulses
        assertContains('<P85>7</P85>', $xml);          // and waits longer for the next number
        assertContains('<P20522>0</P20522>', $xml);    // socket 2 is touch-tone
        assertContains('<P292>10</P292>', $xml);       // as chosen: socket 2's own wait
        assertContains('<P72>1</P72>', $xml);
        assertContains('<P772>1</P772>', $xml, "# sends socket 2's number too");
        if (ContactRepository::countryCode() === '44') {
            assertContains('<P28165>0</P28165>', $xml); // the general pulse standard
        }
        assertSame(7, PhoneSettings::parse('ht801', 'dial_wait', '7'));
        assertTrue(PhoneSettings::valid('ht801', 'dial_wait', 'auto'));
    }),
];
