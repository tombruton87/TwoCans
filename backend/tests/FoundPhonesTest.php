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
];
