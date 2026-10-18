<?php

namespace TomGould\RSSToJson;

/**
 * Classifies IP addresses for the block_private_ips option.
 *
 * The ranges are listed here rather than left to FILTER_FLAG_NO_PRIV_RANGE
 * and FILTER_FLAG_NO_RES_RANGE, whose coverage has changed between PHP
 * versions.
 *
 * @internal Not part of the public API; may change without notice.
 */
final class IpAddress
{
    /**
     * IPv4 ranges that are not publicly routable, or are used for cloud
     * metadata services.
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',        // "This network"
        '10.0.0.0/8',       // Private
        '100.64.0.0/10',    // Carrier-grade NAT (includes 100.100.100.200, Alibaba Cloud metadata)
        '127.0.0.0/8',      // Loopback
        '169.254.0.0/16',   // Link-local (includes 169.254.169.254, AWS/GCP/Azure metadata)
        '172.16.0.0/12',    // Private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // Documentation
        '192.88.99.0/24',   // 6to4 relay anycast
        '192.168.0.0/16',   // Private
        '198.18.0.0/15',    // Benchmarking
        '198.51.100.0/24',  // Documentation
        '203.0.113.0/24',   // Documentation
        '224.0.0.0/4',      // Multicast
        '240.0.0.0/4',      // Reserved, includes 255.255.255.255
    ];

    /**
     * IPv6 ranges that are not publicly routable.
     */
    private const BLOCKED_V6 = [
        '::/128',           // Unspecified
        '::1/128',          // Loopback
        '100::/64',         // Discard
        '2001::/32',        // Teredo
        '2001:db8::/32',    // Documentation
        'fc00::/7',         // Unique local (includes fd00:ec2::254, AWS metadata)
        'fe80::/10',        // Link-local
        'fec0::/10',        // Site-local (deprecated)
        'ff00::/8',         // Multicast
    ];

    /**
     * IPv6 ranges that carry an IPv4 address, with the byte offset where the
     * IPv4 address starts. The embedded address is checked as IPv4.
     */
    private const EMBEDDED_V4 = [
        '::ffff:0:0/96' => 12,  // IPv4-mapped
        '::/96' => 12,          // IPv4-compatible (deprecated)
        '64:ff9b::/96' => 12,   // NAT64
        '2002::/16' => 2,       // 6to4
    ];

    /**
     * Whether an address is safe to connect to from a server: a valid IPv4 or
     * IPv6 address outside every private, loopback, link-local, reserved and
     * metadata range. Anything that does not parse as an IP address is not
     * public.
     *
     * @param string $ip
     * @return bool
     */
    public static function isPublic(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if (!is_string($binary)) {
            return false;
        }

        if (strlen($binary) === 4) {
            return !self::inAnyRange($binary, self::BLOCKED_V4);
        }

        if (self::inAnyRange($binary, self::BLOCKED_V6)) {
            return false;
        }
        foreach (self::EMBEDDED_V4 as $range => $offset) {
            if (self::inRange($binary, $range)) {
                return !self::inAnyRange(substr($binary, $offset, 4), self::BLOCKED_V4);
            }
        }

        return true;
    }

    /**
     * @param string $binary Packed address from inet_pton()
     * @param string[] $ranges CIDR ranges
     * @return bool
     */
    private static function inAnyRange(string $binary, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($binary, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $binary Packed address from inet_pton()
     * @param string $range CIDR range, e.g. "10.0.0.0/8"
     * @return bool
     */
    private static function inRange(string $binary, string $range): bool
    {
        [$network, $bits] = explode('/', $range, 2);
        $networkBinary = inet_pton($network);
        if (!is_string($networkBinary) || strlen($networkBinary) !== strlen($binary)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if (substr($binary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($binary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }
}
