<?php
declare(strict_types=1);

/** Fanvil GA10 adapters (untested): the settings file, its settings, and finding one. */

$ga10 = static fn(array $settings = [], string $hotline = ''): array => [
    'id' => 1, 'type' => 'ga10', 'name' => 'Den', 'sipUsername' => 'den', 'sipSecret' => 'secret-den',
    'mac' => '0C383E000001', 'hotline' => $hotline, 'hotlineDelay' => 4, 'phoneSettings' => $settings,
];

return [
    test("a GA10's file: Fanvil's XML, its line and a child's defaults", function () use ($ga10) {
        $xml = (new FanvilProvisioning())->xml($ga10());
        assertTrue(simplexml_load_string($xml) !== false, 'well-formed');
        assertContains('<sysConf>', $xml);
        assertContains('<line index="1">', $xml);
        assertContains('<RegisterUser>den</RegisterUser>', $xml);
        assertContains('<RegisterPswd>secret-den</RegisterPswd>', $xml);
        assertContains('<RegisterAddr>' . PjsipConfig::domain() . '</RegisterAddr>', $xml);
        assertContains('<RegisterPort>' . PjsipConfig::port('udp') . '</RegisterPort>', $xml);
        assertContains('<MWINum>' . PjsipConfig::VOICEMAIL_NUMBER . '</MWINum>', $xml);
        assertContains('<EnableHotline>0</EnableHotline>', $xml);
        assertContains('<CallWaiting>0</CallWaiting>', $xml);
        assertContains('<CallTransfer>0</CallTransfer>', $xml);
        assertContains('<AllowIPCall>0</AllowIPCall>', $xml);
        assertContains('<DialTimeoutvalue>4</DialTimeoutvalue>', $xml);
        assertContains('<EnableTelnet>0</EnableTelnet>', $xml);
        assertFalse(str_contains($xml, '<HandsetVol>'), 'its own volume, until one is chosen');
    }),
    test('its settings, and a hotline that waits a moment', function () use ($ga10) {
        $xml = (new FanvilProvisioning())->xml($ga10(['call_waiting' => true, 'earpiece' => 8, 'dial_wait' => 7], '07700900123'));
        assertContains('<CallWaiting>1</CallWaiting>', $xml);
        assertContains('<HandsetVol>8</HandsetVol>', $xml);
        assertContains('<DialTimeoutvalue>7</DialTimeoutvalue>', $xml);
        assertContains("<EnableHotline>1</EnableHotline>", $xml);
        assertContains('<HotlineNum>07700900123</HotlineNum>', $xml);
        assertContains('<WarmLineTime>4</WarmLineTime>', $xml);
        assertTrue(simplexml_load_string((new FanvilProvisioning())->xml(['name' => '</DisplayName><x>'] + $ga10())) !== false);
    }),
    test('a GA10 is marked untested, wherever it is', function () {
        assertTrue(DeviceRepository::untested('ga10'));
        assertFalse(DeviceRepository::untested('ht801'));
        assertFalse(DeviceRepository::untested('ghp621'));
        assertFalse(DeviceRepository::untested('w56h'), 'the W60B has been tried on a real one');
        foreach (['w70b', 'w52p', 'spa112', 'spa122', 'ata191', 'ata192', 'vvx150', 'vvx450', 'vvx600'] as $type) {
            assertTrue(DeviceRepository::untested($type), $type . ' is untested too');
        }
        assertSame('fanvil', DeviceRepository::TYPES['ga10']['brand']);
        assertTrue(DeviceRepository::isAdapter('ga10'));
        assertSame(1, DeviceRepository::ports('ga10'));
        assertTrue(PhoneSettings::can('ga10', 'hotline'));
        assertSame(FanvilProvisioning::PHONE_SETTINGS, PhoneSettings::catalog('ga10'));
        foreach (FanvilProvisioning::PHONE_SETTINGS as $key => $s) {
            assertTrue(PhoneSettings::valid('ga10', $key, $s['default']), $key . "'s default");
        }
        assertSame(['GA10', '2.4.0'], FoundPhones::fromUserAgent('Fanvil GA10 2.4.0 0c383e000001'));
        assertSame('ga10', FoundPhones::typeFor('GA10'));
    }),
];
