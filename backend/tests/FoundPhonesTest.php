<?php
declare(strict_types=1);

/** Spotting Grandstreams on the network: their MACs, models and requests. */

return [
    test('a model name is the twocans type, W or not', function () {
        assertSame('ghp621', FoundPhones::typeFor('GHP621W'));
        assertSame('ghp610', FoundPhones::typeFor('GHP610'));
        assertSame('ht802', FoundPhones::typeFor('HT802'));
        assertSame('', FoundPhones::typeFor('GXP2170'));
        assertSame('', FoundPhones::typeFor(''));
    }),
    test('a settings request says its model and firmware', function () {
        assertSame(['GHP621W', '1.0.1.37'], FoundPhones::fromUserAgent('Grandstream Model HW GHP621W SW 1.0.1.37 DevId 000b82c12345'));
        assertSame(['', ''], FoundPhones::fromUserAgent('curl/8.0'));
    }),
    test('Grandstreams are picked out of the ARP table, on the home network only', function () {
        $arp = "IP address       HW type     Flags       HW address            Mask     Device\n"
            . "192.168.1.40    0x1         0x2         00:0b:82:c1:23:45     *        eth0\n"
            . "192.168.1.9      0x1         0x2         aa:bb:cc:dd:ee:ff     *        eth0\n"
            . "192.168.1.30     0x1         0x0         ec:74:d7:00:00:01     *        eth0\n"
            . "10.0.0.5         0x1         0x2         c0:74:ad:00:00:02     *        eth1\n";
        assertSame(['192.168.1.40' => '000B82C12345'], Pager::grandstreamsIn($arp, '192.168.1.10'));
        assertTrue(Pager::isGrandstream('C074AD000001'));
        assertFalse(Pager::isGrandstream('AABBCCDDEEFF'));
    }),
    test('every phone maker is spotted by its MAC; a Cisco only when it says it\'s an adapter', function () {
        assertSame('grandstream', Pager::brandFor('144CFF000001'));
        assertSame('yealink', Pager::brandFor('80:5e:c0:00:00:01'));
        assertSame('poly', Pager::brandFor('0004F2000001'));
        assertSame('fanvil', Pager::brandFor('0C383E000001'));
        assertSame('cisco', Pager::brandFor('000E08000001'), 'a Linksys/Cisco prefix');
        assertSame(null, Pager::brandFor('AABBCC000001'));
        foreach (array_keys(Pager::OUIS) as $brand) {
            assertTrue(isset(DeviceRepository::BRANDS[$brand]), $brand);
        }

        $arp = "IP address       HW type     Flags       HW address            Mask     Device\n"
            . "192.168.1.40    0x1         0x2         00:0b:82:c1:23:45     *        eth0\n"
            . "192.168.1.41    0x1         0x2         80:5e:c0:00:00:01     *        eth0\n"
            . "192.168.1.42    0x1         0x2         00:04:f2:00:00:01     *        eth0\n"
            . "192.168.1.9     0x1         0x2         aa:bb:cc:dd:ee:ff     *        eth0\n";
        assertSame([
            '192.168.1.40' => ['mac' => '000B82C12345', 'brand' => 'grandstream'],
            '192.168.1.41' => ['mac' => '805EC0000001', 'brand' => 'yealink'],
            '192.168.1.42' => ['mac' => '0004F2000001', 'brand' => 'poly'],
        ], Pager::phonesIn($arp, '192.168.1.10'));
        assertSame(['192.168.1.40' => '000B82C12345'], Pager::grandstreamsIn($arp, '192.168.1.10'));

        assertSame('T46U', Pager::modelIn('<title>Yealink SIP-T46U Phone</title>', 'yealink'));
        assertSame('W60B', Pager::modelIn('Server: yealink embed httpd\n<title>W60B</title>', 'yealink'));
        assertSame('VVX450', Pager::modelIn('<title>Polycom - VVX_450 Configuration</title>', 'poly'));
        assertSame('SPA112', Pager::modelIn('Cisco SPA112 Configuration Utility', 'cisco'));
        assertSame('ATA191', Pager::modelIn('Cisco ATA 191 Multiplatform', 'cisco'));
        assertSame('', Pager::modelIn('Cisco Catalyst 2960-X Series', 'cisco'), 'not a switch');
        assertSame('', Pager::modelIn('Yealink T99Z', 'yealink'), 'not a model twocans knows');
        assertSame('GA10', Pager::modelIn('Fanvil GA10 Gateway', 'fanvil'));
    }),
];
