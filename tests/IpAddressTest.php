<?php

namespace TomGould\RSSToJson\Tests;

use PHPUnit\Framework\TestCase;
use TomGould\RSSToJson\IpAddress;

class IpAddressTest extends TestCase
{
    /**
     * @dataProvider blockedProvider
     */
    public function testBlocksNonPublicAddresses(string $ip): void
    {
        $this->assertFalse(IpAddress::isPublic($ip), "$ip should not be public");
    }

    /**
     * @return array<string, array{string}>
     */
    public function blockedProvider(): array
    {
        return [
            'this network' => ['0.0.0.0'],
            'private 10/8' => ['10.1.2.3'],
            'carrier-grade NAT' => ['100.64.0.1'],
            'Alibaba metadata' => ['100.100.100.200'],
            'loopback' => ['127.0.0.1'],
            'loopback range' => ['127.255.255.254'],
            'link-local' => ['169.254.1.1'],
            'cloud metadata' => ['169.254.169.254'],
            'private 172.16/12 low' => ['172.16.0.1'],
            'private 172.16/12 high' => ['172.31.255.255'],
            'IETF assignments' => ['192.0.0.170'],
            'documentation 192.0.2/24' => ['192.0.2.10'],
            'private 192.168/16' => ['192.168.1.1'],
            'benchmarking' => ['198.18.0.1'],
            'multicast' => ['224.0.0.251'],
            'reserved' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'IPv6 unspecified' => ['::'],
            'IPv6 loopback' => ['::1'],
            'IPv6 unique local' => ['fd00::1'],
            'AWS IPv6 metadata' => ['fd00:ec2::254'],
            'IPv6 link-local' => ['fe80::1'],
            'IPv6 site-local' => ['fec0::1'],
            'IPv6 multicast' => ['ff02::1'],
            'IPv6 documentation' => ['2001:db8::1'],
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'IPv4-mapped metadata' => ['::ffff:169.254.169.254'],
            'IPv4-compatible private' => ['::10.0.0.1'],
            'NAT64 private' => ['64:ff9b::192.168.0.1'],
            '6to4 loopback' => ['2002:7f00:0001::1'],
            'zone index' => ['fe80::1%eth0'],
            'host name' => ['localhost'],
            'empty' => [''],
            'decimal form' => ['2130706433'],
            'garbage' => ['999.1.1.1'],
        ];
    }

    /**
     * @dataProvider publicProvider
     */
    public function testAllowsPublicAddresses(string $ip): void
    {
        $this->assertTrue(IpAddress::isPublic($ip), "$ip should be public");
    }

    /**
     * @return array<string, array{string}>
     */
    public function publicProvider(): array
    {
        return [
            'public IPv4' => ['93.184.215.14'],
            'just below 172.16/12' => ['172.15.255.255'],
            'just above 172.16/12' => ['172.32.0.0'],
            'just above CGNAT' => ['100.128.0.1'],
            'public DNS' => ['8.8.8.8'],
            'public IPv6' => ['2606:4700:4700::1111'],
            'IPv4-mapped public' => ['::ffff:8.8.8.8'],
            'NAT64 public' => ['64:ff9b::8.8.8.8'],
            '6to4 public' => ['2002:0808:0808::1'],
        ];
    }
}
