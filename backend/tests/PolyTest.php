<?php
declare(strict_types=1);

/** Poly VVX desk phones: the master file, its settings, its speed dials, and finding one. */

$vvx = static fn(array $settings = [], string $type = 'vvx450'): array => [
    'id' => 1, 'type' => $type, 'name' => 'Study', 'sipUsername' => 'study', 'sipSecret' => 'secret-study',
    'mac' => '0004F2000001', 'phoneSettings' => $settings,
];

return [
    test('its master file: no firmware to fetch, and twocans\' settings file', function () {
        $xml = PolyProvisioning::master();
        assertContains('APP_FILE_PATH=""', $xml);
        assertContains('CONFIG_FILES="twocans-[PHONE_MAC_ADDRESS].cfg"', $xml);
        assertTrue(simplexml_load_string($xml) !== false);
    }),
    test('its settings: the account, the message key, announcements, and a child\'s defaults', function () use ($vvx) {
        $xml = (new PolyProvisioning())->config($vvx());
        assertTrue(simplexml_load_string($xml) !== false, 'well-formed');
        assertContains('reg.1.auth.userId="study"', $xml);
        assertContains('reg.1.auth.password="secret-study"', $xml);
        assertContains('reg.1.server.1.address="' . PjsipConfig::domain() . '"', $xml);
        assertContains('reg.1.server.1.port="' . PjsipConfig::port('udp') . '"', $xml);
        assertContains('reg.1.server.1.transport="UDPOnly"', $xml);
        assertContains('msg.mwi.1.callBack="' . PjsipConfig::VOICEMAIL_NUMBER . '"', $xml);
        assertContains('voIpProt.SIP.alertInfo.1.class="autoAnswer"', $xml);
        assertContains('voIpProt.SIP.specialEvent.checkSync.alwaysReboot="0"', $xml);
        assertContains('dir.local.contacts.maxFavIx="11"', $xml);
        assertContains('call.callWaiting.enable="0"', $xml);
        assertContains('feature.doNotDisturb.enable="0"', $xml);
        assertContains('feature.forward.enable="0"', $xml);
        assertContains('up.backlight.idleIntensity="0"', $xml);
        assertContains('dir.local.readonly="1"', $xml);

        $changed = (new PolyProvisioning())->config($vvx(['call_waiting' => true, 'idle_light' => 2, 'edit_contacts' => true]));
        assertContains('call.callWaiting.enable="1"', $changed);
        assertContains('up.backlight.idleIntensity="2"', $changed);
        assertContains('dir.local.readonly="0"', $changed);
        // A name can't break out of its attribute.
        assertTrue(simplexml_load_string((new PolyProvisioning())->config(['name' => 'Kid" reg.1.address="x'] + $vvx())) !== false);
    }),
    test('its clock: the offset, and when the clocks change', function () {
        assertSame([0, ['start.month' => 3, 'start.dayOfWeek' => 1, 'start.dayOfWeek.lastInMonth' => 1, 'start.time' => 1,
            'stop.month' => 10, 'stop.dayOfWeek' => 1, 'stop.dayOfWeek.lastInMonth' => 1, 'stop.time' => 2]], PolyProvisioning::timeZone('Europe/London'));
        assertSame(-18000, PolyProvisioning::timeZone('America/New_York')[0]);
        assertSame(8, PolyProvisioning::timeZone('America/New_York')[1]['start.date']);
        assertSame([19800, null], PolyProvisioning::timeZone('Asia/Kolkata'));
    }),
    test('its speed dials are its hotkeys, in order, by name', function () {
        $xml = (new PolyProvisioning())->directory([2 => '700', 1 => '07700900123', 3 => ''], ['07700900123' => 'Mum & Dad', '700' => 'Messages']);
        assertTrue(simplexml_load_string($xml) !== false);
        assertContains('<item><fn>Mum &amp; Dad</fn><ct>07700900123</ct><sd>1</sd></item>', $xml);
        assertContains('<item><fn>Messages</fn><ct>700</ct><sd>2</sd></item>', $xml);
        assertSame(2, substr_count($xml, '<item>'));
    }),
    test('a VVX: its own settings, its keys, no hotline, no faceplate', function () {
        assertTrue(DeviceRepository::isDesk('vvx450'));
        assertFalse(DeviceRepository::isGrandstreamDesk('vvx450'));
        assertSame(11, DeviceRepository::keys('vvx450'));
        assertSame(15, DeviceRepository::keys('vvx600'));
        assertSame(null, DeviceRepository::TYPES['vvx450']['faceplate']);
        assertSame(PolyProvisioning::PHONE_SETTINGS, PhoneSettings::catalog('vvx350'));
        assertFalse(PhoneSettings::can('vvx350', 'hotline'));
        assertFalse(PhoneSettings::can('vvx350', 'ringVolume'));
        assertTrue(PhoneSettings::can('ghp621', 'ringVolume'), 'the Grandstreams keep theirs');
        assertSame(GrandstreamProvisioning::PHONE_SETTINGS, PhoneSettings::catalog('ghp621'));
        foreach (PolyProvisioning::PHONE_SETTINGS as $key => $s) {
            assertTrue(PhoneSettings::valid('vvx150', $key, $s['default']), $key . "'s default");
        }
    }),
    test('a VVX that asks for its settings is found, as the model twocans sets it up as', function () {
        assertSame(['VVX450', '6.4.0.1234'], FoundPhones::fromUserAgent('FileTransport PolycomVVX-VVX_450-UA/6.4.0.1234 Type/Application'));
        assertSame('vvx450', FoundPhones::typeFor('VVX450'));
        assertSame('vvx300', FoundPhones::typeFor('VVX311'));
        assertSame('vvx600', FoundPhones::typeFor('VVX601'));
        assertSame('vvx201', FoundPhones::typeFor('VVX101'));
        assertSame('', FoundPhones::typeFor('VVX999'));
    }),
];
