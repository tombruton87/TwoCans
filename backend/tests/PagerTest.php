<?php
declare(strict_types=1);

/** Multicast paging (Pager): addresses, the audio it sends, and where it's used. */

/** A WAV file of the app's own kind: 8 kHz mono 16-bit. */
$wav = static function (array $samples, int $rate = 8000): string {
    $data = pack('v*', ...array_map(static fn(int $s): int => $s & 0xFFFF, $samples));
    $file = tempnam(sys_get_temp_dir(), 'pg') . '.wav';
    file_put_contents($file, 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
        . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
        . 'data' . pack('V', strlen($data)) . $data);

    return $file;
};

return [
    test('each phone listens on its own even port of the group', function () {
        assertSame(7130, Pager::port(15));
        assertSame(0, Pager::port(15) % 2);
        assertSame('239.255.84.84:7130', Pager::address(15));
    }),
    test('µ-law: silence, loudest and quietest', function () {
        assertSame(0xFF, Pager::ulaw(0));
        assertSame(0x80, Pager::ulaw(32767));
        assertSame(0x00, Pager::ulaw(-32768));
        assertSame(0x7F, Pager::ulaw(-1));
        assertSame(0xFF, Pager::ulaw(1));
    }),
    test('reads the samples from an 8 kHz mono WAV, and refuses anything else', function () use ($wav) {
        $ok = $wav([0, 1000, -1000]);
        $wrong = $wav([0, 1000], 16000);
        try {
            assertSame(pack('v*', 0, 1000, (-1000) & 0xFFFF), Pager::pcm($ok));
            assertSame(null, Pager::pcm($wrong));
            assertSame(null, Pager::pcm('/nonexistent.wav'));
        } finally {
            @unlink($ok);
            @unlink($wrong);
        }
    }),
    test('the audio starts with a moment of silence, and repeats with a pause', function () use ($wav) {
        $file = $wav(array_fill(0, 800, 12000));
        try {
            $once = Pager::audio([$file]);
            assertSame(2400 + 800, strlen($once));
            assertSame(str_repeat("\xFF", 2400), substr($once, 0, 2400));
            assertSame(2400 + 800 + 16000 + 800, strlen(Pager::audio([$file, $file])));
        } finally {
            @unlink($file);
        }
    }),
    test('RTP packets: version 2, PCMU, marker on the first, 160 samples apart', function () {
        $first = Pager::packet(str_repeat("\xFF", 160), 0, 1234);
        $third = Pager::packet(str_repeat("\xFF", 160), 2, 1234);
        assertSame(172, strlen($first));
        $h = unpack('Cv/Cpt/nseq/Nts/Nssrc', $third);
        assertSame(0x80, $h['v']);
        assertSame(0, $h['pt']);
        assertSame(2, $h['seq']);
        assertSame(320, $h['ts']);
        assertSame(1234, $h['ssrc']);
        assertSame(0x80, unpack('Cv/Cpt', $first)['pt']);
    }),
    test('a phone on the same network as SIP_DOMAIN is at home; Docker and the internet are not', function () {
        $home = getenv('SIP_DOMAIN');
        putenv('SIP_DOMAIN=192.168.1.10');
        try {
            assertTrue(Pager::onHomeNetwork('192.168.1.40'));
            assertFalse(Pager::onHomeNetwork('192.168.2.5'));
            assertFalse(Pager::onHomeNetwork('172.24.0.9'));
            assertFalse(Pager::onHomeNetwork('81.2.69.160'));
            assertFalse(Pager::onHomeNetwork(''));
        } finally {
            putenv($home === false ? 'SIP_DOMAIN' : 'SIP_DOMAIN=' . $home);
        }
    }),
    test('a GHP621 is told to listen for its pages, and not to let them cut into calls', function () {
        $xml = (new GrandstreamProvisioning())->xml(
            ['id' => 15, 'name' => 'Hall', 'sipUsername' => 'hall-1', 'sipSecret' => 's', 'transport' => 'udp', 'extension' => '201'],
            []
        );
        assertContains('<P1569>239.255.84.84:7130</P1569>', $xml);
        assertContains('<P1567>1</P1567>', $xml);
        assertContains('<P1566>0</P1566>', $xml);
    }),
];
