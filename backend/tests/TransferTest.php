<?php
declare(strict_types=1);

/** Moving house: the pure parts — sealing secrets, and what the archive may contain. */

return [
    test('secrets round-trip under the right passphrase and not the wrong one', function () {
        $secrets = ['trunk.auth_token_enc' => 'tok-123', 'devices.3.sip_secret' => 'pw'];
        $blob = Transfer::seal($secrets, 'correct horse');
        assertSame($secrets, Transfer::open($blob, 'correct horse'));
        assertSame(null, Transfer::open($blob, 'wrong horse'));
        assertSame(null, Transfer::open('', 'correct horse'));
        assertSame(null, Transfer::open(substr($blob, 0, -1) . "\0", 'correct horse'));
    }),
    test('settings that describe the box stay behind', function () {
        foreach (['sip_port', 'trunk_public_host', 'provision_pass', 'vapid_private', 'ha_token', 'ha_status'] as $k) {
            assertTrue(!Transfer::isHouseholdSetting($k));
        }
        foreach (['quiet_hours', 'joke_number', 'greeting_ask', 'ha_url', 'santa_message'] as $k) {
            assertTrue(Transfer::isHouseholdSetting($k));
        }
        assertTrue(!Transfer::isHouseholdSetting('../etc'));
    }),
    test('only names a store would have made are copied', function () {
        assertTrue(Transfer::storeFileOk(str_repeat('a', 32) . '.wav'));
        assertTrue(Transfer::storeFileOk(str_repeat('0', 32) . '.jpg'));
        assertTrue(!Transfer::storeFileOk('../' . str_repeat('a', 32) . '.wav'));
        assertTrue(!Transfer::storeFileOk(str_repeat('a', 32) . '.php'));
        assertTrue(!Transfer::storeFileOk('clip.wav'));
    }),
    test('archive members are a closed list, and never climb out', function () {
        foreach (['manifest.json', 'secrets.enc', 'phones.json', 'files/photo/' . str_repeat('b', 32) . '.jpg',
                  'files/history/voicemail/twocans/100/INBOX/msg0001.wav'] as $ok) {
            assertTrue(Transfer::memberOk($ok));
        }
        foreach (['../manifest.json', '/etc/passwd', 'files/../src/x.php', 'files/history/../../x', 'index.php',
                  'Phones.json', 'files//x', 'secrets.enc.bak'] as $bad) {
            assertTrue(!Transfer::memberOk($bad));
        }
    }),
    test('history paths are relative and clean', function () {
        assertTrue(Transfer::relOk('twocans/100/INBOX/msg0001.wav'));
        assertTrue(!Transfer::relOk('/abs/path.wav'));
        assertTrue(!Transfer::relOk('a/../b.wav'));
        assertTrue(!Transfer::relOk('a//b.wav'));
        assertTrue(!Transfer::relOk('a b.wav'));
    }),
];
