<?php
declare(strict_types=1);

/** Cisco SPA112 adapters: the settings file, its time zone, and its own settings. */

$port = static fn(string $name, array $settings = [], string $hotline = ''): array => [
    'type' => 'spa112', 'name' => $name, 'sipUsername' => strtolower($name), 'sipSecret' => 'secret-' . $name,
    'mac' => '88755A000001', 'hotline' => $hotline, 'hotlineDelay' => 4, 'phoneSettings' => $settings,
];

return [
    test("an SPA112's file: each phone on its own line, an empty port off", function () use ($port) {
        $xml = (new CiscoProvisioning())->xml([2 => $port('Den'), 1 => $port('Hall')], 'phone.example.test:8083');
        assertContains('<Line_Enable_1_>Yes</Line_Enable_1_>', $xml);
        assertContains('<User_ID_1_>hall</User_ID_1_>', $xml);
        assertContains('<Password_2_>secret-Den</Password_2_>', $xml);
        assertContains('<Proxy_2_>' . GrandstreamProvisioning::server() . '</Proxy_2_>', $xml);
        assertContains('<SIP_Port_2_>5061</SIP_Port_2_>', $xml);
        assertContains('<Resync_From_SIP>Yes</Resync_From_SIP>', $xml);
        assertContains('/cisco/$MA.xml</Profile_Rule>', $xml);
        assertContains('[--uid twocans --pwd ', $xml);
        assertContains('<Dial_Plan_1_>(x.)</Dial_Plan_1_>', $xml);
        // A child's adapter: no star codes, no hold on a tap of the hook, no call waiting.
        assertContains('<Cfwd_All_Serv_1_>No</Cfwd_All_Serv_1_>', $xml);
        assertContains('<DND_Serv_2_>No</DND_Serv_2_>', $xml);
        assertContains('<Three_Way_Call_Serv_1_>No</Three_Way_Call_Serv_1_>', $xml);
        assertContains('<Call_Waiting_Serv_1_>No</Call_Waiting_Serv_1_>', $xml);
        assertContains('<CID_Serv_1_>Yes</CID_Serv_1_>', $xml);
        assertContains('<Protect_IVR_FactoryReset>Yes</Protect_IVR_FactoryReset>', $xml);
        assertContains('<Upgrade_Enable>No</Upgrade_Enable>', $xml);

        $one = (new CiscoProvisioning())->xml([1 => $port('Hall')]);
        assertContains('<Line_Enable_2_>No</Line_Enable_2_>', $one);
        assertFalse(str_contains($one, '<Profile_Rule>'), 'without a host it keeps its own');
        // Nothing in a name gets out of its tag.
        assertFalse(str_contains((new CiscoProvisioning())->xml([1 => $port('<Line_Enable_2_>')]), '<Line_Enable_2_><'));
    }),
    test('its settings: a hotline in the dial plan, the adapter-wide ones from PHONE 1', function () use ($port) {
        $xml = (new CiscoProvisioning())->xml([
            1 => $port('Hall', ['call_waiting' => true, 'earpiece' => 3, 'dial_wait' => 7, 'caller_id' => 'Bellcore(N.Amer,China)'], '07700900123'),
            2 => $port('Den', ['earpiece' => -9, 'star_codes' => true]),
        ]);
        assertContains('<Dial_Plan_1_>(P4&lt;:07700900123&gt;|x.)</Dial_Plan_1_>', $xml);
        assertContains('<CW_Setting_1_>Yes</CW_Setting_1_>', $xml);
        assertContains('<CW_Setting_2_>No</CW_Setting_2_>', $xml);
        assertContains('<Cfwd_All_Serv_2_>Yes</Cfwd_All_Serv_2_>', $xml);
        assertContains('<FXS_Port_Output_Gain>3</FXS_Port_Output_Gain>', $xml);
        assertContains('<FXS_Port_Input_Gain>-3</FXS_Port_Input_Gain>', $xml);
        assertContains('<Interdigit_Short_Timer>7</Interdigit_Short_Timer>', $xml);
        assertContains('<Caller_ID_Method>Bellcore(N.Amer,China)</Caller_ID_Method>', $xml);
        assertContains('<Caller_ID_FSK_Standard>bell 202</Caller_ID_FSK_Standard>', $xml);
        if (ContactRepository::countryCode() === '44') {
            $uk = (new CiscoProvisioning())->xml([1 => $port('Hall')]);
            assertContains('<Caller_ID_Method>ETSI FSK With PR(UK)</Caller_ID_Method>', $uk);
            assertContains('<Caller_ID_FSK_Standard>v.23</Caller_ID_FSK_Standard>', $uk);
            assertContains('<FXS_Port_Impedance>270+750||150nF</FXS_Port_Impedance>', $uk);
            assertContains('<Ring_Frequency>25</Ring_Frequency>', $uk);
        }
    }),
    test("its clock: the household's zone and when the clocks change", function () {
        assertSame(['GMT', 'start=3/-1/7/1;end=10/-1/7/2;save=1'], CiscoProvisioning::timeZone('Europe/London'));
        assertSame(['GMT+01:00', 'start=3/-1/7/2;end=10/-1/7/3;save=1'], CiscoProvisioning::timeZone('Europe/Paris'));
        assertSame(['GMT-05:00', 'start=3/8/7/2;end=11/1/7/2;save=1'], CiscoProvisioning::timeZone('America/New_York'));
        assertSame(['GMT+05:30', null], CiscoProvisioning::timeZone('Asia/Kolkata'));
        assertSame(['GMT', null], CiscoProvisioning::timeZone('Not/AZone'));
    }),
    test('SPA112 phones are added on one adapter, a port each', function () {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $devices = new DeviceRepository();
            $a = (int) $devices->create('Test SPA hall', 'spa112', 'udp')['id'];
            $b = (int) $devices->create('Test SPA den', 'spa112', 'udp')['id'];
            $devices->setPort($b, 3);
            assertSame(2, (int) $devices->find($b)['port'], 'two ports');
            assertTrue($devices->setMac($a, '88755A0000AA'));
            assertTrue($devices->setMac($b, '88755A0000AA'));
            assertSame('SPA112', DeviceRepository::toView($devices->find($a))['model']);
            assertTrue($devices->setPhoneSetting($a, 'earpiece', 3));
            assertSame(3, PhoneSettings::for(DeviceRepository::toView($devices->find($b)))['earpiece'], "the adapter's");
            assertTrue($devices->setPhoneSetting($a, 'call_waiting', true));
            assertSame(false, PhoneSettings::for(DeviceRepository::toView($devices->find($b)))['call_waiting'], 'its own');
        } finally {
            $pdo->rollBack();
        }
    }),
    test('an SPA112 is a two-phone adapter from Cisco, with no rotary dial', function () {
        assertSame('cisco', DeviceRepository::TYPES['spa112']['brand']);
        assertSame(2, DeviceRepository::ports('spa112'));
        assertTrue(DeviceRepository::isAdapter('spa112'));
        assertTrue(PhoneSettings::can('spa112', 'hotline'));
        assertFalse(PhoneSettings::can('spa112', 'ringVolume'));
        assertFalse(isset(PhoneSettings::catalog('spa112')['rotary']), "it can't hear a dial");
        assertSame(-9, PhoneSettings::parse('spa112', 'earpiece', '-9'));
        foreach (CiscoProvisioning::PHONE_SETTINGS as $key => $s) {
            assertTrue(PhoneSettings::valid('spa112', $key, $s['default']), $key . "'s default");
        }
        assertSame(['SPA112', '1.4.1'], FoundPhones::fromUserAgent('Cisco/SPA112-1.4.1(SR5) (88755A000001)'));
        assertSame('spa112', FoundPhones::typeFor('SPA112'));
    }),
    test('an SPA122 is set up as an SPA112: its own settings, the same file', function () {
        assertSame(2, DeviceRepository::ports('spa122'));
        assertSame('cisco', DeviceRepository::TYPES['spa122']['brand']);
        assertSame(CiscoProvisioning::PHONE_SETTINGS, PhoneSettings::catalog('spa122'));
        assertTrue(PhoneSettings::can('spa122', 'hotline'));
        assertSame('spa122', FoundPhones::typeFor('SPA122'));
        assertSame(['SPA122', '1.4.1'], FoundPhones::fromUserAgent('Cisco/SPA122-1.4.1(SR5) (88755A000002)'));
        $xml = (new CiscoProvisioning())->xml([1 => [
            'type' => 'spa122', 'name' => 'Loft', 'sipUsername' => 'loft', 'sipSecret' => 's', 'mac' => '88755A000002',
            'hotline' => '', 'hotlineDelay' => 4, 'phoneSettings' => ['dial_wait' => 10, 'call_waiting' => true],
        ]]);
        assertContains('<Interdigit_Short_Timer>10</Interdigit_Short_Timer>', $xml);
        assertContains('<CW_Setting_1_>Yes</CW_Setting_1_>', $xml);
        assertContains('<Line_Enable_2_>No</Line_Enable_2_>', $xml);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $devices = new DeviceRepository();
            $id = (int) $devices->create('Test SPA122', 'spa122', 'udp')['id'];
            assertSame('SPA122', DeviceRepository::toView($devices->find($id))['model']);
            assertTrue(DeviceRepository::toView($devices->find($id))['ata']);
        } finally {
            $pdo->rollBack();
        }
    }),
    test('an ATA 191 or 192: the SPA settings, its own clock and impedance, no "protect factory reset"', function () {
        foreach (['ata191', 'ata192'] as $type) {
            assertSame(2, DeviceRepository::ports($type));
            assertSame('cisco', DeviceRepository::TYPES[$type]['brand']);
            assertTrue(PhoneSettings::can($type, 'hotline'));
            assertFalse(isset(PhoneSettings::catalog($type)['keypad_reset']));
            assertTrue(isset(PhoneSettings::catalog($type)['star_codes']));
            assertSame($type, FoundPhones::typeFor(strtoupper($type)));
        }
        assertSame('ata191', FoundPhones::typeFor('ATA191-3PW'));
        assertSame('spa122', FoundPhones::typeFor('SPA122'));
        assertSame(['ATA192', '12.0.1'], FoundPhones::fromUserAgent('Cisco/ATA192-12.0.1(SR3) (88755A000003)'));
        assertSame('+00 2 2', CiscoProvisioning::ataTimeZone('Europe/London'));
        assertSame('-05 2 1', CiscoProvisioning::ataTimeZone('America/New_York'));
        assertSame(null, CiscoProvisioning::ataTimeZone('Asia/Kathmandu'));

        $xml = (new CiscoProvisioning())->xml([1 => [
            'type' => 'ata191', 'name' => 'Loft', 'sipUsername' => 'loft', 'sipSecret' => 's', 'mac' => '88755A000003',
            'hotline' => '', 'hotlineDelay' => 4, 'phoneSettings' => ['call_waiting' => true],
        ]]);
        assertContains('<CW_Setting_1_>Yes</CW_Setting_1_>', $xml);
        assertContains('<Cfwd_All_Serv_1_>No</Cfwd_All_Serv_1_>', $xml);
        assertFalse(str_contains($xml, 'Protect_IVR_FactoryReset'));
        assertFalse(str_contains($xml, 'Daylight_Saving_Time_Rule'), 'its clock is in the router configuration');
        if (PjsipConfig::timezone() === 'Europe/London') {
            assertContains("<router-configuration>\n    <Time_Setup>\n    <Time_Zone>+00 2 2</Time_Zone>", $xml);
        }
        if (ContactRepository::countryCode() === '44') {
            assertContains('<FXS_Port_Impedance>220+820||115nF</FXS_Port_Impedance>', $xml);
        }
        assertSame(1, substr_count($xml, '</flat-profile>'));
        assertTrue(simplexml_load_string($xml) !== false, 'well-formed');
    }),
];
