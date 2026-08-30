<?php
declare(strict_types=1);

/**
 * The safety-critical "who can call when" logic, now pure and testable: call
 * windows, quiet hours, SOS, and the shut branch.
 */

return [
    test('port() maps tls to 5061 and the rest to 5060', function () {
        assertSame(5061, PjsipConfig::port('tls'));
        assertSame(5060, PjsipConfig::port('udp'));
        assertSame(5060, PjsipConfig::port('tcp'));
    }),
    test('registrarUri builds the right scheme, port and transport', function () {
        assertTrue(str_starts_with(PjsipConfig::registrarUri('udp'), 'sip:'));
        assertTrue(str_ends_with(PjsipConfig::registrarUri('udp'), ':5060;transport=udp'));
        assertTrue(str_starts_with(PjsipConfig::registrarUri('tls'), 'sips:'));
        assertTrue(str_ends_with(PjsipConfig::registrarUri('tls'), ':5061;transport=tls'));
    }),
    test('fixed service numbers never move', function () {
        assertSame(['700', '600', '601', '500'], PjsipConfig::FIXED_SERVICE_NUMBERS);
    }),
    test('windowCondition is null for anytime', function () {
        assertNull(PjsipConfig::windowCondition(['call_window' => 'anytime']));
    }),
    test('windowCondition maps afterschool', function () {
        assertSame('15:00-19:00,mon-fri,*,*', PjsipConfig::windowCondition(['call_window' => 'afterschool']));
    }),
    test('windowCondition maps weekends', function () {
        assertSame('09:00-19:00,sat-sun,*,*', PjsipConfig::windowCondition(['call_window' => 'weekends']));
    }),
    test('windowCondition maps a custom window', function () {
        assertSame('08:30-17:00,*,*,*', PjsipConfig::windowCondition([
            'call_window' => 'custom', 'window_from' => '08:30', 'window_to' => '17:00',
        ]));
    }),
    test('timeCondition pins the timezone on', function () {
        assertSame('19:30-07:00,*,*,*,' . PjsipConfig::timezone(), PjsipConfig::timeCondition('19:30-07:00,*,*,*'));
    }),
    test('renderShutBranch emits nothing when not needed', function () {
        assertSame('', PjsipConfig::renderShutBranch('Grandma', false));
    }),
    test('renderShutBranch blocks and plays the window message', function () {
        $out = PjsipConfig::renderShutBranch('Grandma', true);
        assertContains('(shut)', $out);
        assertContains('CDR(userfield)=blocked', $out);
        assertContains('Playback(vm-nobodyavail)', $out);
    }),
    test('an SOS contact skips quiet hours and its window', function () {
        $contact = ['name' => 'Mum', 'number_e164' => '+447700900123', 'sos' => 1, 'call_window' => 'afterschool'];
        $out = PjsipConfig::renderReachRule('247', $contact, '', true, '19:30-07:00,*,*,*');
        assertContains('calling Mum', $out);
        assertNotContains('GotoIfTime', $out);
        assertNotContains('(shut)', $out);
    }),
    test('a normal contact is blocked outside quiet hours', function () {
        $contact = ['name' => 'Grandma', 'number_e164' => '+447700900123', 'sos' => 0, 'call_window' => 'anytime'];
        $out = PjsipConfig::renderReachRule('247', $contact, '', true, '19:30-07:00,*,*,*');
        assertContains('GotoIfTime(19:30-07:00,*,*,*,' . PjsipConfig::timezone() . '?shut)', $out);
        assertContains('(shut)', $out);
    }),
    test('a normal contact gets a window check', function () {
        $contact = ['name' => 'Grandma', 'number_e164' => '+447700900123', 'sos' => 0, 'call_window' => 'afterschool'];
        $out = PjsipConfig::renderReachRule('247', $contact, '', false, '19:30-07:00,*,*,*');
        assertContains('GotoIfTime(15:00-19:00,mon-fri,*,*,' . PjsipConfig::timezone() . '?open)', $out);
        assertContains('(open)', $out);
        assertContains('(shut)', $out);
    }),
    test('an allowed call dials the trunk with the household number', function () {
        $contact = ['name' => 'Grandma', 'number_e164' => '+447700900123', 'sos' => 0, 'call_window' => 'anytime'];
        $out = PjsipConfig::renderReachRule('+447700900123', $contact, '+442012345678', false, '19:30-07:00,*,*,*');
        assertContains('Set(CALLERID(num)=+442012345678)', $out);
        assertContains('Dial(PJSIP/+447700900123@twocans-trunk,60)', $out);
    }),
];
