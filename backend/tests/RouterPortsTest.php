<?php
declare(strict_types=1);

/**
 * Asking the router to open ports (RouterPorts, PortOpener): reading what a
 * router says about itself and what it answers. The talking itself was tried
 * against a pretend UPnP router and a pretend NAT-PMP gateway; these pin the
 * parsing, which is where real routers differ.
 */

$describe = static fn(string $services, string $base = ''): string
    => '<?xml version="1.0"?><root xmlns="urn:schemas-upnp-org:device-1-0">' . $base
     . '<device><friendlyName>Home Router</friendlyName><deviceList><device><serviceList>'
     . $services . '</serviceList></device></deviceList></device></root>';
$service = static fn(string $type, string $control): string
    => "<service><serviceType>{$type}</serviceType><controlURL>{$control}</controlURL></service>";

return [
    test('finds the WAN service and makes its control URL absolute', function () use ($describe, $service) {
        $xml = $describe($service('urn:schemas-upnp-org:service:Layer3Forwarding:1', '/l3f')
            . $service('urn:schemas-upnp-org:service:WANIPConnection:1', '/ctl/IPConn'));
        $found = RouterPorts::wanService($xml, 'http://192.168.1.1:5000/rootDesc.xml');
        assertSame('urn:schemas-upnp-org:service:WANIPConnection:1', $found['service']);
        assertSame('http://192.168.1.1:5000/ctl/IPConn', $found['control']);
        assertSame('Home Router', $found['name']);
    }),
    test('prefers WANIPConnection:2, and takes a PPP connection when that is all there is', function () use ($describe, $service) {
        $both = $describe($service('urn:schemas-upnp-org:service:WANIPConnection:1', '/v1')
            . $service('urn:schemas-upnp-org:service:WANIPConnection:2', '/v2'));
        assertSame('http://r/v2', RouterPorts::wanService($both, 'http://r/d.xml')['control']);
        $ppp = $describe($service('urn:schemas-upnp-org:service:WANPPPConnection:1', 'ppp'));
        assertSame('http://r/igd/ppp', RouterPorts::wanService($ppp, 'http://r/igd/d.xml')['control']);
    }),
    test('URLBase wins over where the description came from', function () use ($describe, $service) {
        $xml = $describe($service('urn:schemas-upnp-org:service:WANIPConnection:1', '/ctl'), '<URLBase>http://10.0.0.1:49000/</URLBase>');
        assertSame('http://10.0.0.1:49000/ctl', RouterPorts::wanService($xml, 'http://10.0.0.1:1900/x.xml')['control']);
    }),
    test('something that answers every search but opens nothing is not a router', function () use ($describe, $service) {
        // A Hue bridge answers a search for a gateway; its description gives it away.
        $hue = $describe($service('urn:schemas-upnp-org:service:Dummy:1', '/dummy'));
        assertNull(RouterPorts::wanService($hue, 'http://192.168.1.216/description.xml'));
        assertNull(RouterPorts::wanService('not xml at all', 'http://x/'));
    }),
    test('a SOAP answer gives its values', function () {
        $reply = RouterPorts::soapReply(200, '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            . '<u:GetExternalIPAddressResponse xmlns:u="urn:schemas-upnp-org:service:WANIPConnection:1">'
            . '<NewExternalIPAddress>203.0.113.7</NewExternalIPAddress></u:GetExternalIPAddressResponse></s:Body></s:Envelope>');
        assertTrue($reply['ok']);
        assertSame('203.0.113.7', $reply['values']['NewExternalIPAddress']);
    }),
    test('a SOAP fault gives the UPnP error code', function () {
        $reply = RouterPorts::soapReply(500, '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault>'
            . '<faultcode>s:Client</faultcode><faultstring>UPnPError</faultstring><detail><UPnPError xmlns="urn:schemas-upnp-org:control-1-0">'
            . '<errorCode>718</errorCode><errorDescription>ConflictInMappingEntry</errorDescription></UPnPError></detail></s:Fault></s:Body></s:Envelope>');
        assertFalse($reply['ok']);
        assertSame(718, $reply['code']);
        assertSame('ConflictInMappingEntry', $reply['error']);
    }),
    test('the request names its arguments in order, escaped', function () {
        $body = RouterPorts::soapBody('urn:schemas-upnp-org:service:WANIPConnection:1', 'AddPortMapping',
            ['NewExternalPort' => '443', 'NewPortMappingDescription' => 'twocans <web>']);
        assertContains('<u:AddPortMapping xmlns:u="urn:schemas-upnp-org:service:WANIPConnection:1"><NewExternalPort>443</NewExternalPort>', $body);
        assertContains('twocans &lt;web&gt;', $body);
    }),
    test('relative URLs resolve like a browser would', function () {
        assertSame('http://h:1/a/b', RouterPorts::resolve('http://h:1/x/y.xml', '/a/b'));
        assertSame('http://h:1/x/b', RouterPorts::resolve('http://h:1/x/y.xml', 'b'));
        assertSame('http://other/z', RouterPorts::resolve('http://h:1/x/y.xml', 'http://other/z'));
    }),
    test('a private or shared outside address means another router in front', function () {
        foreach (['10.1.2.3', '172.20.0.1', '192.168.0.9', '100.64.12.1', '100.127.255.254'] as $ip) {
            assertTrue(PortOpener::isPrivate($ip), $ip);
        }
        foreach (['198.51.100.20', '100.128.0.1', '203.0.113.7', '172.32.0.1'] as $ip) {
            assertFalse(PortOpener::isPrivate($ip), $ip);
        }
    }),
    test('the router is guessed as .1 on the box\'s own network', function () {
        assertSame('192.168.50.1', PortOpener::guessRouter('192.168.50.23'));
        assertSame('', PortOpener::guessRouter(''));
        assertSame('', PortOpener::guessRouter('phone.example.com'));
    }),
    test('ports are described as a run, by protocol', function () {
        $p = static fn(string $proto, int ...$ports): array => array_map(static fn(int $n): array => ['proto' => $proto, 'port' => $n], $ports);
        assertSame('10000–10002 UDP', PortOpener::describeGroup($p('UDP', 10000, 10001, 10002)));
        assertSame('5070 UDP · 5070–5071 TCP', PortOpener::describeGroup(array_merge($p('UDP', 5070), $p('TCP', 5070, 5071))));
        assertSame('443, 8443 TCP', PortOpener::describeGroup($p('TCP', 443, 8443)));
    }),
];
