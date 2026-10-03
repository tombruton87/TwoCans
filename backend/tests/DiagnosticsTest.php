<?php
declare(strict_types=1);

/** A phone's diagnostics: what's in them, and that the household is taken out first. */

return [
    test('the household is masked: passwords, names, numbers, email, MAC, IP addresses, keys', function () {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $devices = new DeviceRepository();
            $phone = $devices->create("Test Zebedee's Room", 'ghp621', 'udp');
            $other = $devices->create('Test Quillon', 'ht801', 'udp');
            $pdo->prepare('INSERT INTO contacts (name, number_e164, allow_in, allow_out) VALUES (?, ?, 1, 1)')
                ->execute(['Grandma Wilhelmina', '+447700900987']);
            $pdo->prepare("INSERT INTO guardians (name, email, role, status) VALUES ('Test Ottoline', 'ottoline@example.test', 'viewer', 'active')")->execute();
            $d = DeviceRepository::toView($devices->find((int) $phone['id']));
            $o = DeviceRepository::toView($devices->find((int) $other['id']));
            $pass = (new SettingsRepository())->provisionPass();

            $text = "user {$d['sipUsername']} secret {$d['sipSecret']} and {$o['sipUsername']}\n"
                . "account.1.label = Test Zebedee's Room — zebedee\n"
                . "call Grandma Wilhelmina on +447700900987 or 07700 900987, or ottoline@example.test, or someone@else.test\n"
                . "url http://twocans:{$pass}@192.168.1.10:8083/phonebook/yealink.xml\n"
                . "192.168.1.42 - - [02/Oct/2026:23:03:28 +0100] \"GET /yealink/805ec0aa0042.boot HTTP/1.1\" 200 141 \"-\" \"Yealink W60B 77.85.0.160 80:5e:c0:aa:00:42\"\n"
                . "event?d=15&amp;e=started&amp;k=c0ffee1234abcd567890\n"
                . "expiration_time : 1791021023, port 5060, firmware 108.86.0.20\n"
                . "Grandma's Room is on the landing\n";
            $masked = Masker::forHousehold($d)->mask($text);

            foreach ([$d['sipUsername'], $d['sipSecret'], $o['sipUsername'], 'Zebedee', 'zebedee', 'Wilhelmina', 'Quillon', 'Ottoline', 'ottoline@',
                '7700900987', '07700 900987', 'someone@else', $pass, '192.168.', 'aa0042', 'aa:00:42', 'c0ffee1234abcd567890'] as $leak) {
                assertFalse(str_contains($masked, $leak), "'{$leak}' is masked");
            }
            assertContains('user this-phone secret [password] and phone-', $masked);
            assertContains('805EC0-mac1', $masked);
            assertSame(2, substr_count($masked, '805EC0-mac1'), 'the same MAC, the same stand-in, either way it\'s written');
            assertContains('k=[key]', $masked);
            assertContains("Grandma's Room is on the landing", $masked, 'ordinary words stay');
            assertContains('1791021023', $masked, 'a timestamp is not a phone number');
            assertContains('firmware 108.86.0.20', $masked, 'a firmware version is not an address');
            assertContains('Yealink W60B 77.85.0.160', $masked);
            assertContains('http://twocans:[password]@', $masked);
        } finally {
            $pdo->rollBack();
        }
    }),
    test("a phone's diagnostics: what it is, its sign-in, its requests, its settings and its file — masked", function () {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $devices = new DeviceRepository();
            $phone = $devices->create('Test Bartholomew', 'w56h', 'udp');
            $devices->setMac((int) $phone['id'], '805EC0FEED01');
            $devices->setPhoneSetting((int) $phone['id'], 'ringtone', 4);
            $log = tempnam(sys_get_temp_dir(), 'tc-log');
            file_put_contents($log, "10.0.0.9 - - [03/Oct/2026:10:00:00 +0100] \"GET /yealink/805ec0feed01.boot HTTP/1.1\" 401 63 \"-\" \"Yealink W60B 77.85.0.160 80:5e:c0:fe:ed:01\"\n"
                . "10.0.0.8 - - [03/Oct/2026:10:00:01 +0100] \"GET / HTTP/1.1\" 200 5 \"-\" \"Mozilla\"\n");
            $text = (new Diagnostics($devices, $log))->forDevice($devices->find((int) $phone['id']));
            unlink($log);

            assertContains('twocans diagnostics — W60B', $text);
            assertContains('twocans type:           w56h (yealink, dect)', $text);
            assertContains('GET /yealink/805EC0-mac1.boot HTTP/1.1" 401', $text, 'its own requests');
            assertFalse(str_contains($text, 'Mozilla'), 'not other people\'s');
            assertContains('* ringtone = Ring 4', $text, 'what\'s been changed is marked');
            assertContains('include:config "805EC0-mac1.cfg"', $text);
            assertContains('account.1.password = [password]', $text);
            assertFalse(str_contains($text, 'Bartholomew'));
            assertFalse(str_contains($text, 'feed01'));
            assertSame('twocans-diagnostics-w60b-2026-10-03-1000.txt', Diagnostics::fileName(['model' => 'W60B'], new DateTimeImmutable('2026-10-03 10:00')));
        } finally {
            $pdo->rollBack();
        }
    }),
];
