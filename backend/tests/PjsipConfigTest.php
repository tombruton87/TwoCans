<?php
declare(strict_types=1);

/**
 * The safety-critical "who can call when" logic, now pure and testable: call
 * windows, quiet hours, SOS, "always put through", what a caller nobody
 * recognises hears and where that call ends, and the shut branch.
 */

return [
    test('a port comes from the environment, then the database, then the default', function () {
        // The rule itself, without this box's own settings getting in the way —
        // a house that moved its handsets to 5070 must not fail the suite.
        assertSame(5060, PjsipConfig::choosePort(['', ''], 5060));
        assertSame(5061, PjsipConfig::choosePort([false, null], 5061));
        assertSame(5070, PjsipConfig::choosePort(['5070', '5080'], 5060));
        assertSame(5080, PjsipConfig::choosePort(['', '5080'], 5060));
        assertSame(5060, PjsipConfig::choosePort(['0', '99999'], 5060), 'nonsense falls back');
    }),
    test('the handset port follows SIP_PORT when it is set', function () {
        putenv('SIP_PORT=5070');
        putenv('SIP_TLS_PORT=5071');
        assertSame(5070, PjsipConfig::port('udp'));
        assertSame(5071, PjsipConfig::port('tls'));
        putenv('SIP_PORT');
        putenv('SIP_TLS_PORT');
    }),
    test('a trunk sharing the handset port is reported as a clash', function () {
        putenv('SIP_PORT=5060');
        putenv('TRUNK_SIP_PORT=5060');
        assertTrue(PjsipConfig::portClash());
        putenv('TRUNK_SIP_PORT=5062');
        assertTrue(!PjsipConfig::portClash());
        putenv('SIP_PORT');
        putenv('TRUNK_SIP_PORT');
    }),
    test('registrarUri builds the right scheme, port and transport', function () {
        // Whatever this box's ports are, the URI has to carry them.
        assertTrue(str_starts_with(PjsipConfig::registrarUri('udp'), 'sip:'));
        assertTrue(str_ends_with(PjsipConfig::registrarUri('udp'), ':' . PjsipConfig::port('udp') . ';transport=udp'));
        assertTrue(str_starts_with(PjsipConfig::registrarUri('tls'), 'sips:'));
        assertTrue(str_ends_with(PjsipConfig::registrarUri('tls'), ':' . PjsipConfig::port('tls') . ';transport=tls'));
    }),
    test('fixed service numbers never move', function () {
        assertSame(['700', '701', '600', '601', '500'], PjsipConfig::FIXED_SERVICE_NUMBERS);
    }),
    test('windowCondition is null for anytime', function () {
        assertNull(PjsipConfig::windowCondition(['call_window' => 'anytime']));
    }),
    test('windowCondition maps afterschool', function () {
        assertSame(['15:00-19:00,mon-fri,*,*'], PjsipConfig::windowCondition(['call_window' => 'afterschool']));
    }),
    test('windowCondition maps weekends', function () {
        assertSame(['09:00-19:00,sat-sun,*,*'], PjsipConfig::windowCondition(['call_window' => 'weekends']));
    }),
    test('windowCondition maps an old custom window as every day', function () {
        assertSame(['08:30-17:00,*,*,*'], PjsipConfig::windowCondition([
            'call_window' => 'custom', 'window_from' => '08:30', 'window_to' => '17:00',
        ]));
    }),
    test('windowCondition maps a custom weekly schedule', function () {
        assertSame(['16:00-18:00,mon-fri,*,*', '10:00-12:00,sat,*,*'], PjsipConfig::windowCondition([
            'call_window' => 'custom',
            'window_schedule' => Schedule::toJson([
                Schedule::rule(['mon', 'tue', 'wed', 'thu', 'fri'], '16:00', '18:00'),
                Schedule::rule(['sat'], '10:00', '12:00'),
            ]),
        ]));
    }),
    test('each time in a window is its own jump', function () {
        $out = PjsipConfig::renderTimeJumps(['16:00-18:00,mon-fri,*,*', '10:00-12:00,sat,*,*'], 'open');
        assertContains('GotoIfTime(16:00-18:00,mon-fri,*,*,' . PjsipConfig::timezone() . '?open)', $out);
        assertContains('GotoIfTime(10:00-12:00,sat,*,*,' . PjsipConfig::timezone() . '?open)', $out);
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
        // The calling phone's own line number if it has one, else the household's.
        assertContains('Set(CALLERID(num)=' . PjsipConfig::callerIdNumber('+442012345678') . ')', $out);
        assertContains(':+442012345678)}', $out);
        assertContains('Dial(PJSIP/+447700900123@twocans-trunk,60)', $out);
    }),
    test('a phone with a recording offers it as the refusal to speak', function () {
        $out = PjsipConfig::renderRefusalPick('REFUSAL', '/var/lib/twocans/refusals/abc123');
        // ExecIf rather than Set: the first phone to offer wins, so the phone
        // that would ring first is the one that speaks.
        assertContains('ExecIf($["${REFUSAL}" = ""]?Set(REFUSAL=/var/lib/twocans/refusals/abc123))', $out);
    }),
    test('a phone with no recording offers nothing', function () {
        assertSame('', PjsipConfig::renderRefusalPick('REFUSAL', null));
        assertSame('', PjsipConfig::renderRefusalPick('REFUSAL_ANY', ''));
    }),
    test('the refusal speaks the phone\'s own recording', function () {
        $out = PjsipConfig::renderInboundRefusal(false);
        // Anyone on the list skips the refusal entirely.
        assertContains('GotoIf($["${CALLER_ALLOWED}" = "1"]?allowed)', $out);
        assertContains('CDR(userfield)=blocked', $out);
        // Read, not Playback: the caller's key press has to be heard while the
        // recording is playing, not once it has finished.
        assertContains('Read(JOKE_KEY,${REFUSAL},1', $out);
        assertContains('Hangup()', $out);
    }),
    test('with screening off the refusal still just hangs up', function () {
        // The hand-off is the only difference between the two settings, so it
        // must not leak in when the household has declined it: an unknown
        // caller gets the recording and then silence, exactly as before.
        $out = PjsipConfig::renderInboundRefusal(false);
        assertContains('(refused),Hangup()', $out);
        assertNotContains('Goto(quiet)', $out);
    }),
    test('with screening on an unknown caller is handed the house mailbox', function () {
        $out = PjsipConfig::renderInboundRefusal(true);
        // quiet, never a handset: nobody recognised the number, so no phone
        // rings — the caller simply gets to say who they were.
        assertContains('(refused),Goto(quiet)', $out);
        assertNotContains('Hangup()', $out);
        // Still logged as blocked, so the call log keeps showing it refused
        // whether or not a message was left.
        assertContains('CDR(userfield)=blocked', $out);
    }),
    test('the refusal falls back to a phone with any recording, then the stock prompt', function () {
        $out = PjsipConfig::renderInboundRefusal(false);
        // Nothing could ring (every handset asleep, or outside its hours).
        assertContains('Set(REFUSAL=${REFUSAL_ANY})', $out);
        // Nobody has recorded anything at all.
        assertContains('Playback(' . PjsipConfig::BLOCKED_MESSAGE . ')', $out);
    }),
    test('a refused caller can press 5 for a joke either way', function () {
        // Offered both after a phone's own recording and after the stock
        // prompt, so a wrong number gets a joke whether or not the household
        // has recorded a refusal message — and whichever way the call ends.
        foreach ([false, true] as $takeMessage) {
            $out = PjsipConfig::renderInboundRefusal($takeMessage);
            assertSame(2, substr_count($out, '?jokeline'), 'both refusal paths should offer the joke line');
            assertContains('GotoIf($["${JOKE_KEY}" = "5"]?jokeline)', $out);

            // Offered before the call is handed on or hung up, so a caller who
            // wants the joke line never has to sit through the whole refusal.
            $ending = $takeMessage ? 'Goto(quiet)' : 'Hangup()';
            assertTrue(
                strpos($out, '?jokeline') < strpos($out, $ending),
                'the joke line should be offered before ' . $ending
            );
        }
    }),
    test('the quiet-time branch is a plain Goto until a message is recorded', function () {
        $out = PjsipConfig::renderNotNowBranch(null);
        assertContains('(notnow),Goto(quiet)', $out);
        // Nothing answered, nothing played: exactly the old bedtime behaviour.
        assertNotContains('Read(', $out);
        assertNotContains('Answer()', $out);
    }),
    test('the quiet-time branch plays the household recording and offers a joke', function () {
        $out = PjsipConfig::renderNotNowBranch('/var/lib/twocans/refusals/quiet/abc123');
        assertContains('(notnow),Answer()', $out);
        assertContains('Read(JOKE_KEY,/var/lib/twocans/refusals/quiet/abc123,1', $out);
        assertContains('GotoIf($["${JOKE_KEY}" = "5"]?jokeline)', $out);
        // Pressing nothing still reaches the mailbox, so a caller with
        // something to say can leave a message.
        assertContains('Goto(quiet)', $out);
    }),
    test('the joke key lands on the joke line, wherever it has been moved to', function () {
        assertContains(
            '(jokeline),Goto(' . PjsipConfig::DEVICES_CONTEXT . ',258,1)',
            PjsipConfig::renderJokeFallback('258')
        );
        // The number is a setting, so the escape follows it rather than
        // hard-coding the default.
        assertContains(
            '(jokeline),Goto(' . PjsipConfig::DEVICES_CONTEXT . ',999,1)',
            PjsipConfig::renderJokeFallback('999')
        );
    }),

    // ------------------------------------------------- always put through

    test('anyAlwaysRing ignores groups and anything that cannot be a caller', function () {
        assertFalse(PjsipConfig::anyAlwaysRing([]));
        // A group can never be looked up as a caller, so a flag on one is inert;
        // a flag on a row with no number (a half-built draft) is too.
        assertFalse(PjsipConfig::anyAlwaysRing([
            ['always_ring' => 1, 'is_group' => 1, 'number_e164' => '+447700900123'],
            ['always_ring' => 1, 'is_group' => 0, 'number_e164' => ''],
            ['always_ring' => 0, 'is_group' => 0, 'number_e164' => '+447700900999'],
        ]));
        assertTrue(PjsipConfig::anyAlwaysRing([
            ['number_e164' => '+447700900999', 'always_ring' => 1],
        ]));
        // A row read before migration 030 has run carries no flag at all.
        assertFalse(PjsipConfig::anyAlwaysRing([['number_e164' => '+447700900999']]));
    }),
    test('nobody flagged means no always-through gate at all', function () {
        assertSame('', PjsipConfig::renderAlwaysThrough(false));
    }),
    test('the always-through gate rings whatever the hour', function () {
        // Straight to the ring, past bedtime and the caller's own hours.
        assertContains('GotoIf($["${CALLER_ALWAYS}" = "1"]?ring)', PjsipConfig::renderAlwaysThrough(true));
    }),
    test('a phone\'s own hours are skipped only for an always-through caller', function () {
        $plain = PjsipConfig::renderDeviceHours('dev3', ['07:00-20:00,*,*,*'], false);
        assertNotContains('CALLER_ALWAYS', $plain);
        assertContains('GotoIfTime(07:00-20:00,*,*,*,' . PjsipConfig::timezone() . '?ondev3)', $plain);
        assertContains('Goto(offdev3)', $plain);

        // An off-duty phone is the whole point, so the bypass comes first.
        $bypassed = PjsipConfig::renderDeviceHours('dev3', ['07:00-20:00,*,*,*'], true);
        assertTrue(
            strpos($bypassed, 'CALLER_ALWAYS') < strpos($bypassed, 'GotoIfTime'),
            'the bypass should be tested before the opening hours'
        );
        assertContains('?ondev3)', $bypassed);
    }),
    test('always put through is inbound only: a dialled window still applies', function () {
        // Deliberate: the flag is how Mum and Dad reach the children, not a
        // licence for a child to dial out at 10pm. SOS is the outbound skip.
        $contact = ['name' => 'Dad', 'number_e164' => '+447700900123', 'sos' => 0,
                    'always_ring' => 1, 'call_window' => 'afterschool'];
        $out = PjsipConfig::renderReachRule('247', $contact, '', false, '19:30-07:00,*,*,*');
        assertContains('GotoIfTime(15:00-19:00,mon-fri,*,*,' . PjsipConfig::timezone() . '?open)', $out);
    }),
];
