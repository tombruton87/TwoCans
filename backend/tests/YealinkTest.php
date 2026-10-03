<?php
declare(strict_types=1);

/**
 * Yealink W60B bases and their W56H handsets: the settings file, the time
 * zone, handsets sharing a base, and each kind of phone's own settings —
 * against the database in a transaction rolled back after.
 */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        $fn(new DeviceRepository());
    } finally {
        $pdo->rollBack();
    }
};

$handset = static fn(string $name, array $settings = []): array => [
    'type' => 'w56h', 'name' => $name, 'sipUsername' => strtolower($name), 'sipSecret' => 'secret-' . $name,
    'mac' => '805EC0AA0001', 'phoneSettings' => $settings,
];

return [
    test('each kind of phone has its own settings, and only those', function () {
        assertTrue(isset(PhoneSettings::catalog('ghp621')['ring_lock']));
        assertTrue(isset(PhoneSettings::catalog('w56h')['wallpaper']));
        assertFalse(isset(PhoneSettings::catalog('w56h')['ring_lock']), "a W56H's ring can't be locked");
        assertSame([], PhoneSettings::catalog('linphone'));
        assertTrue(PhoneSettings::has('w56h'));
        assertTrue(PhoneSettings::has('ghp610'));
        assertFalse(PhoneSettings::has('linphone'));
        assertTrue(PhoneSettings::can('ghp621', 'hotline'));
        assertFalse(PhoneSettings::can('w56h', 'hotline'), 'its off-hook dialling would stop it dialling anyone else');
        assertFalse(PhoneSettings::valid('w56h', 'wallpaper', 9));
        assertTrue(PhoneSettings::valid('w56h', 'wallpaper', 5));
        assertFalse(PhoneSettings::valid('ghp621', 'wallpaper', 1));
        assertSame(12, PhoneSettings::parse('w56h', 'time_format', '12'));
        assertSame(false, PhoneSettings::parse('w56h', 'clock', '0'));
        foreach (PhoneSettings::catalog('w56h') as $key => $s) {
            assertTrue(PhoneSettings::valid('w56h', $key, $s['default']), $key . "'s default");
        }
    }),
    test("a base's file: each handset on its own line, the empty slots off", function () use ($handset) {
        $cfg = (new YealinkProvisioning())->cfg([2 => $handset('Kitchen'), 1 => $handset('Landing')], 'phone.example.test:8083');
        assertContains("account.1.enable = 1\naccount.1.label = Landing", $cfg);
        assertContains('account.2.user_name = kitchen', $cfg);
        assertContains('account.2.password = secret-Kitchen', $cfg);
        assertContains('account.2.sip_server.1.address = ' . PjsipConfig::domain(), $cfg);
        assertContains('account.2.sip_server.1.port = ' . PjsipConfig::port('udp'), $cfg);
        assertContains("handset.2.incoming_lines = 2\nhandset.2.dial_out_lines = 2\nhandset.2.dial_out_default_line = 2", $cfg);
        assertContains('voice_mail.number.2 = ' . PjsipConfig::VOICEMAIL_NUMBER, $cfg);
        assertContains('account.3.enable = 0', $cfg);
        assertContains('account.8.enable = 0', $cfg);
        assertContains('sip.notify_reboot_enable = 0', $cfg);
        assertContains('auto_provision.handset_configured.enable = 1', $cfg);
        assertContains('remote_phonebook.data.1.url = http://twocans:', $cfg);
        assertContains('@phone.example.test:8083/phonebook/yealink.xml', $cfg);
        // The defaults: a child's handset.
        assertContains('custom.handset.backlight_in_charger.enable = 0', $cfg);
        assertContains('call_waiting.enable = 0', $cfg);
        assertContains('features.direct_ip_call_enable = 0', $cfg);
        assertContains('custom.handset.color_scheme = 1', $cfg);
        // No line of a name can start another setting.
        $sneaky = (new YealinkProvisioning())->cfg([1 => $handset("Kid\naccount.1.enable = 0")]);
        assertFalse(str_contains($sneaky, "\naccount.1.enable = 0\n"));
    }),
    test("the base's settings are its first handset's; a changed one is sent", function () use ($handset) {
        $cfg = (new YealinkProvisioning())->cfg([
            1 => $handset('One', ['wallpaper' => 4, 'time_format' => 12, 'phonebook' => false, 'unregister_on_reboot' => false]),
            2 => $handset('Two', ['wallpaper' => 2]),
        ], 'phone.example.test');
        assertContains('custom.handset.wallpaper = 4', $cfg);
        assertContains('custom.handset.time_format = 0', $cfg);
        assertContains('features.remote_phonebook.enable = 0', $cfg);
        assertContains('account.1.unregister_on_reboot = 0', $cfg);
        assertContains('account.2.unregister_on_reboot = 1', $cfg);
    }),
    test("the base keeps the household's time, not China's", function () {
        assertSame(['0', 'United Kingdom(London)'], YealinkProvisioning::timeZone('Europe/London'));
        assertSame(['-5', 'United States-Eastern Time'], YealinkProvisioning::timeZone('America/New_York'));
        assertSame(['+5:45', null], YealinkProvisioning::timeZone('Asia/Kathmandu'));
        assertSame(['-3', null], YealinkProvisioning::timeZone('America/Montevideo'));
        assertSame(['0', null], YealinkProvisioning::timeZone('Not/AZone'));
    }),
    test('handsets share a base: each its own number, its settings shared', function () use ($fresh) {
        $fresh(function (DeviceRepository $devices) {
            $a = (int) $devices->create('Test handset A', 'w56h', 'udp')['id'];
            $b = (int) $devices->create('Test handset B', 'w56h', 'udp')['id'];
            $c = (int) $devices->create('Test handset C', 'w56h', 'udp')['id'];
            $desk = (int) $devices->create('Test desk', 'ghp621', 'udp')['id'];
            $devices->setPort($b, 2);
            $devices->setPort($c, 9);
            assertSame(8, (int) $devices->find($c)['port'], 'eight handsets to a base');
            assertTrue($devices->setMac($a, '805EC0AA0099'));
            assertTrue($devices->setMac($b, '805EC0AA0099'), 'another handset of the same base');
            $devices->setPort($c, 2);
            assertFalse($devices->setMac($c, '805EC0AA0099'), 'not on a number that is taken');
            assertFalse($devices->setMac($desk, '805EC0AA0099'), 'not a desk phone');

            assertTrue($devices->setPhoneSetting($a, 'wallpaper', 3));
            assertSame(3, PhoneSettings::for(DeviceRepository::toView($devices->find($b)))['wallpaper'], 'shared with the base');
            assertTrue($devices->setPhoneSetting($a, 'unregister_on_reboot', false));
            assertSame(true, PhoneSettings::for(DeviceRepository::toView($devices->find($b)))['unregister_on_reboot'], 'its own');
            assertFalse($devices->setPhoneSetting($a, 'ring_lock', true), "not a setting a W56H has");

            // Moving the base moves every handset on it.
            assertTrue($devices->setMac($b, '805EC0AA0098'));
            assertSame('805EC0AA0098', (string) $devices->find($a)['mac']);
        });
    }),
    test('a base that asks for its settings is found, as somewhere for W56H handsets', function () {
        assertSame(['W60B', '77.85.0.20'], FoundPhones::fromUserAgent('Yealink W60B 77.85.0.20 80:5e:c0:aa:00:01'));
        assertSame('w56h', FoundPhones::typeFor('W60B'));
        assertSame('yealink', DeviceRepository::TYPES['w56h']['brand']);
        assertSame(['w56h', 'w70b', 'w52p', 't31g', 't33g', 't42u', 't43u', 't44u', 't46u', 't48u', 't53w', 't54w', 't57w', 't58w'],
            array_keys(DeviceRepository::typesBy('yealink')));
        foreach (DeviceRepository::TYPES as $type => $t) {
            assertTrue(isset(DeviceRepository::BRANDS[$t['brand']]), $type . "'s brand");
        }
    }),
    test('a W70B or W52P base: its own number of handsets, and only the settings it has', function () use ($handset) {
        assertSame(10, DeviceRepository::ports('w70b'));
        assertSame(5, DeviceRepository::ports('w52p'));
        assertTrue(DeviceRepository::isDect('w70b'));
        assertFalse(isset(PhoneSettings::catalog('w70b')['message_light']), 'the W70B has no such setting');
        assertFalse(isset(PhoneSettings::catalog('w52p')['wallpaper']), 'nor the W52P this one');
        assertTrue(isset(PhoneSettings::catalog('w52p')['colours']));

        $w70 = (new YealinkProvisioning())->cfg([1 => ['type' => 'w70b'] + $handset('Hall')]);
        assertContains('the Yealink W70B base', $w70);
        assertContains('account.10.enable = 0', $w70);
        assertContains('static.auto_provision.handset_configured.enable = 1', $w70);
        assertFalse(str_contains($w70, 'voice_mail_notify_light'));
        assertFalse(str_contains($w70, 'custom.handset.color_scheme'));

        $w52 = (new YealinkProvisioning())->cfg([1 => ['type' => 'w52p'] + $handset('Hall')]);
        assertContains('account.5.enable = 0', $w52);
        assertFalse(str_contains($w52, 'account.6.'), 'five handsets');
        assertFalse(str_contains($w52, 'custom.handset.wallpaper'));
        assertFalse(str_contains($w52, 'phone_setting.end_call_on_hook.enable'));
        assertContains('custom.handset.color_scheme = 1', $w52);
    }),
    test('a Yealink desk phone: its line on key 1, speed dials after, a hotline and a fixed ring', function () {
        $desk = [
            'id' => 1, 'type' => 't46u', 'name' => 'Study', 'sipUsername' => 'study', 'sipSecret' => 'secret-study',
            'mac' => '805EC0000002', 'hotline' => '07700900123', 'hotlineDelay' => 4, 'phoneSettings' => [],
        ];
        $cfg = (new YealinkProvisioning())->desk($desk, [1 => '07700900123', 3 => '700'], ['07700900123' => 'Mum', '700' => 'Messages'], 'phone.example.test');
        assertContains("linekey.1.type = 15\nlinekey.1.line = 1", $cfg);
        assertContains("linekey.2.type = 13\nlinekey.2.line = 1\nlinekey.2.value = 07700900123\nlinekey.2.label = Mum", $cfg);
        assertContains("linekey.3.type = 0", $cfg);
        assertContains("linekey.4.type = 13\nlinekey.4.line = 1\nlinekey.4.value = 700\nlinekey.4.label = Messages", $cfg);
        assertContains('linekey.10.type = 0', $cfg);
        assertFalse(str_contains($cfg, 'linekey.11.'), 'ten line keys');
        assertContains('account.1.user_name = study', $cfg);
        assertContains('features.intercom.allow = 1', $cfg);
        assertContains('features.hotline_number = 07700900123', $cfg);
        assertContains('features.hotline_delay = 4', $cfg);
        assertContains('force.voice.ring_vol = 3', $cfg);
        assertContains('call_waiting.enable = 0', $cfg);
        assertContains('features.dnd.allow = 0', $cfg);
        assertContains('screensaver.wait_time = 43200', $cfg);
        assertContains('voice_mail.number.1 = ' . PjsipConfig::VOICEMAIL_NUMBER, $cfg);

        $t31 = (new YealinkProvisioning())->desk(['type' => 't31g', 'hotline' => '', 'phoneSettings' => ['ring_volume' => 'phone']] + $desk);
        assertContains('linekey.2.type = 0', $t31);
        assertFalse(str_contains($t31, 'linekey.3.'), 'two line keys');
        assertFalse(str_contains($t31, 'screensaver'), 'a mono screen');
        assertContains('force.voice.ring_vol = ' . "\n", $t31);
        assertContains('features.hotline_number = ' . "\n", $t31);
    }),
    test('the Yealink desk phones: their keys, settings and hotline; found by their model', function () {
        assertSame(1, DeviceRepository::keys('t31g'));
        assertSame(3, DeviceRepository::keys('t33g'));
        assertSame(9, DeviceRepository::keys('t46u'));
        assertTrue(DeviceRepository::isDesk('t54w'));
        assertFalse(DeviceRepository::isGrandstreamDesk('t54w'));
        assertTrue(PhoneSettings::can('t33g', 'hotline'));
        assertTrue(isset(PhoneSettings::catalog('t33g')['screen_saver']));
        assertFalse(isset(PhoneSettings::catalog('t31g')['screen_saver']));
        assertSame('phone', PhoneSettings::parse('t46u', 'ring_volume', 'phone'));
        assertSame(5, PhoneSettings::parse('t46u', 'ring_volume', '5'));
        foreach (['t31g', 't54w', 'w70b', 'w52p'] as $type) {
            assertTrue(DeviceRepository::untested($type), $type);
            foreach (PhoneSettings::catalog($type) as $key => $s) {
                assertTrue(PhoneSettings::valid($type, $key, $s['default']), "$type $key default");
            }
        }
        assertSame(['T46U', '108.86.0.20'], FoundPhones::fromUserAgent('Yealink SIP-T46U 108.86.0.20 80:5e:c0:00:00:02'));
        assertSame('t46u', FoundPhones::typeFor('T46U'));
        assertSame('w70b', FoundPhones::typeFor('W70B'));
        assertSame('w56h', FoundPhones::typeFor('W60B'));
    }),
    test("a colour desk phone's own wallpaper: fetched from twocans, its labels see-through over it", function () use ($fresh) {
        $desk = [
            'id' => 1, 'type' => 't54w', 'name' => 'Study', 'sipUsername' => 'study', 'sipSecret' => 's',
            'mac' => '805EC0000002', 'hotline' => '', 'hotlineDelay' => 4, 'phoneSettings' => [],
            'wallpaper' => str_repeat('a', 32) . '.jpg',
        ];
        $cfg = (new YealinkProvisioning())->desk($desk, [], [], 'phone.example.test:8083');
        assertContains('@phone.example.test:8083/yealink/wallpaper/' . str_repeat('a', 32) . '.jpg', $cfg);
        assertContains('phone_setting.backgrounds = ' . str_repeat('a', 32) . '.jpg', $cfg);
        assertContains('phone_setting.idle_dsskey_and_title.transparency = 40%', $cfg);

        $none = (new YealinkProvisioning())->desk(['wallpaper' => ''] + $desk, [], [], 'phone.example.test');
        assertContains("wallpaper_upload.url = \n", $none);
        assertContains('phone_setting.backgrounds = Default.jpg', $none);
        assertContains('phone_setting.backgrounds = Default.png', (new YealinkProvisioning())->desk(['type' => 't46u', 'wallpaper' => ''] + $desk));
        assertFalse(str_contains((new YealinkProvisioning())->desk(['type' => 't31g'] + $desk), 'wallpaper'), 'a mono screen');
        assertSame([480, 272], YealinkProvisioning::WALLPAPER['t46u']);

        $fresh(function (DeviceRepository $devices) {
            $id = (int) $devices->create('Test wallpaper', 't33g', 'udp')['id'];
            $name = bin2hex(random_bytes(16)) . '.jpg';
            assertFalse($devices->isWallpaper($name));
            $devices->setWallpaper($id, $name);
            assertTrue($devices->isWallpaper($name), 'only a phone\'s wallpaper can be fetched');
            assertSame($name, DeviceRepository::toView($devices->find($id))['wallpaper']);
        });
    }),
    test('the T42, T43U and T48: their keys, screens and wallpaper; found by their model, siblings and all', function () {
        $desk = ['id' => 1, 'name' => 'Den', 'sipUsername' => 'den', 'sipSecret' => 's', 'mac' => '805EC0000003',
            'hotline' => '', 'hotlineDelay' => 4, 'phoneSettings' => [], 'wallpaper' => str_repeat('b', 32) . '.jpg'];
        $t42 = (new YealinkProvisioning())->desk(['type' => 't42u'] + $desk);
        assertContains('linekey.6.type = 0', $t42);
        assertFalse(str_contains($t42, 'linekey.7.'), 'six line keys');
        assertFalse(str_contains($t42, 'wallpaper'), 'a black-and-white screen');
        assertFalse(str_contains($t42, 'screensaver'));
        $t43 = (new YealinkProvisioning())->desk(['type' => 't43u'] + $desk);
        assertContains('linekey.8.type = 0', $t43);
        assertFalse(str_contains($t43, 'linekey.9.'));
        $t48 = (new YealinkProvisioning())->desk(['type' => 't48u'] + $desk, [], [], 'phone.example.test');
        assertContains('linekey.10.type = 0', $t48);
        assertContains('phone_setting.backgrounds = ' . str_repeat('b', 32) . '.jpg', $t48);
        assertContains('screensaver.wait_time', $t48);
        assertSame([800, 480], YealinkProvisioning::WALLPAPER['t48u']);
        assertSame([5, 7, 9], [DeviceRepository::keys('t42u'), DeviceRepository::keys('t43u'), DeviceRepository::keys('t48u')]);
        assertSame('t42u', FoundPhones::typeFor('T42S'));
        assertSame('t48u', FoundPhones::typeFor('T48S'));
        assertSame('t43u', FoundPhones::typeFor('T43U'));
        foreach (['t42u', 't43u', 't48u'] as $type) {
            assertTrue(DeviceRepository::untested($type));
            assertTrue(PhoneSettings::can($type, 'hotline'));
        }
    }),
    test('the T53W (black-and-white) and T57W (a colour touchscreen)', function () {
        $desk = ['id' => 1, 'name' => 'Den', 'sipUsername' => 'den', 'sipSecret' => 's', 'mac' => '805EC0000004',
            'hotline' => '', 'hotlineDelay' => 4, 'phoneSettings' => [], 'wallpaper' => str_repeat('c', 32) . '.jpg'];
        $t53 = (new YealinkProvisioning())->desk(['type' => 't53w'] + $desk, [], [], 'phone.example.test');
        assertContains('linekey.8.type = 0', $t53);
        assertFalse(str_contains($t53, 'linekey.9.'), 'eight line keys');
        assertFalse(str_contains($t53, 'wallpaper'), 'a black-and-white screen');
        assertFalse(isset(PhoneSettings::catalog('t53w')['screen_saver']));
        $t57 = (new YealinkProvisioning())->desk(['type' => 't57w'] + $desk, [], [], 'phone.example.test');
        assertContains('linekey.10.type = 0', $t57);
        assertContains('phone_setting.backgrounds = ' . str_repeat('c', 32) . '.jpg', $t57);
        assertContains('phone_setting.idle_dsskey_and_title.transparency = 40%', $t57);
        assertSame([800, 480], YealinkProvisioning::WALLPAPER['t57w']);
        assertSame([7, 9], [DeviceRepository::keys('t53w'), DeviceRepository::keys('t57w')]);
        assertSame('t53w', FoundPhones::typeFor('T53W'));
        assertSame('t57w', FoundPhones::typeFor('T57W'));
        assertTrue(DeviceRepository::untested('t57w'));
    }),
    test('the T44U and T58W, each with only the settings it has; a lit screen is "always on"', function () {
        $desk = ['id' => 1, 'name' => 'Den', 'sipUsername' => 'den', 'sipSecret' => 's', 'mac' => '805EC0000005',
            'hotline' => '', 'hotlineDelay' => 4, 'phoneSettings' => ['idle_light' => true], 'wallpaper' => str_repeat('d', 32) . '.jpg'];
        $t44 = (new YealinkProvisioning())->desk(['type' => 't44u'] + $desk, [], [], 'phone.example.test');
        assertContains('linekey.8.type = 0', $t44);
        assertFalse(str_contains($t44, 'linekey.9.'), 'eight keys on screen');
        assertContains('phone_setting.backgrounds = ' . str_repeat('d', 32) . '.jpg', $t44);
        assertFalse(str_contains($t44, 'transparency'));
        assertContains('phone_setting.inactive_backlight_level = 1', $t44);

        $t58 = (new YealinkProvisioning())->desk(['type' => 't58w', 'phoneSettings' => ['idle_light' => true, 'screen_saver' => true]] + $desk, [], [], 'phone.example.test');
        assertContains('linekey.9.type = 0', $t58);
        assertFalse(str_contains($t58, 'linekey.10.'), 'nine keys a page');
        assertContains('phone_setting.backlight_time = 0', $t58, '0 is always on');
        assertFalse(str_contains($t58, 'inactive_backlight_level'));
        assertFalse(str_contains($t58, 'transparency'));
        assertFalse(str_contains($t58, 'display_clock'));
        assertContains('screensaver.wait_time = 600', $t58);
        assertSame([1024, 600], YealinkProvisioning::WALLPAPER['t58w']);
        assertSame([7, 8], [DeviceRepository::keys('t44u'), DeviceRepository::keys('t58w')]);
        assertSame('t44u', FoundPhones::typeFor('T44W'));
        assertSame('t58w', FoundPhones::typeFor('T58W'));
        assertTrue(DeviceRepository::untested('t58w'));

        // Not lit when idle: dimmed after 30 seconds, never "always off" (1).
        $off = (new YealinkProvisioning())->desk(['type' => 't42u', 'phoneSettings' => []] + $desk);
        assertContains('phone_setting.backlight_time = 30', $off);
    }),
    test('newer firmware asks for a boot file first: it names the phone\'s own settings, overwriting nothing', function () {
        $boot = YealinkProvisioning::boot('805ec0aa0042');
        assertTrue(str_starts_with($boot, "#!version:1.0.0.1\n"), 'the version line, first');
        assertContains('include:config "805ec0aa0042.cfg"', $boot);
        assertContains('overwrite_mode = 0', $boot);
        assertContains('include:config "$mac.cfg"', YealinkProvisioning::boot(null));
    }),
    test('a handset\'s own ringtone, asked for call by call; a fixed ring volume for the base', function () use ($fresh, $handset) {
        assertTrue(isset(PhoneSettings::catalog('w56h')['ringtone']));
        assertFalse(PhoneSettings::catalog('w56h')['ringtone']['shared'] ?? false, 'each handset its own');
        assertTrue(PhoneSettings::catalog('w56h')['ring_volume']['shared']);
        assertFalse(isset(PhoneSettings::catalog('w52p')['ringtone']), 'not on the W52P\'s older firmware');
        assertSame(8, PhoneSettings::parse('w56h', 'ringtone', '8'));

        assertContains("force.voice.ring_vol = \n", (new YealinkProvisioning())->cfg([1 => $handset('Hall')]));
        assertContains('force.voice.ring_vol = 4', (new YealinkProvisioning())->cfg([1 => $handset('Hall', ['ring_volume' => 4])]));

        $fresh(function (DeviceRepository $devices) {
            $a = (int) $devices->create('Test ringtone', 'w56h', 'udp')['id'];
            $b = (int) $devices->create('Test no ringtone', 'w56h', 'udp')['id'];
            assertTrue($devices->setPhoneSetting($a, 'ringtone', 5));
            $plan = (new PjsipConfig($devices))->render()['dialplan-devices.conf'];
            $user = (string) $devices->find($a)['sip_username'];
            assertContains('[' . PjsipConfig::RINGTONE_CONTEXT . "]\nexten => s,1,GotoIf(", $plan);
            assertContains("exten => {$user},1,Set(PJSIP_HEADER(add,Alert-Info)=<http://twocans>\;info=ringtone-5)", $plan);
            assertFalse(str_contains($plan, 'exten => ' . $devices->find($b)['sip_username'] . ',1,Set(PJSIP_HEADER'), 'left to ring as it does');
        });
    }),
    test('a W60B or W70B handset answers an announcement by itself; a W52P\'s is rung', function () use ($handset) {
        assertTrue(DeviceRepository::TYPES['w56h']['autoAnswer']);
        assertTrue(DeviceRepository::TYPES['w70b']['autoAnswer']);
        assertFalse(DeviceRepository::TYPES['w52p']['autoAnswer']);
        $cfg = (new YealinkProvisioning())->cfg([1 => $handset('Hall'), 2 => $handset('Den', ['announcements' => 0])]);
        assertContains("account.1.auto_external_intercom = 2\naccount.1.external_intercom.barge.enable = 0", $cfg);
        assertContains('account.2.auto_external_intercom = 0', $cfg, 'its own choice');
        assertFalse(str_contains((new YealinkProvisioning())->cfg([1 => ['type' => 'w52p'] + $handset('Hall')]), 'auto_external_intercom'));
    }),
];
