<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Ai;

/**
 * SSRF guard for the AI classifier endpoint (P3.4, same approach as the
 * nettools guard matrix): the configured host must resolve to global unicast
 * addresses — loopback / RFC1918 / link-local (cloud metadata) endpoints are
 * rejected. Pure classification over resolved IPs; no scheme games.
 */
final class EndpointGuard
{
    /** @var list<string> */
    private const array BLOCKED_V4 = [
        '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
        '169.254.0.0/16', '100.64.0.0/10', '224.0.0.0/4', '240.0.0.0/4',
        '255.255.255.255/32', '0.0.0.0/8',
    ];

    /** @var list<string> */
    private const array BLOCKED_V6 = [
        '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '::/128',
    ];

    /**
     * Test seam: callable(string $host): array<array{ip?: string, ipv6?: string}>|false.
     * When set, replaces real DNS resolution.
     */
    public static ?\Closure $dnsResolver = null;

    /**
     * @return bool true when the endpoint may be called
     */
    public static function allows(string $endpoint): bool
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        // Literal IP: classify directly.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! self::isBlockedIp($host);
        }

        // Hostnames must be HTTPS.
        if (strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        $records = self::$dnsResolver !== null
            ? (self::$dnsResolver)($host)
            : @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            // Unresolvable at call time → fail closed for an outbound call
            return false;
        }

        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip === '' || self::isBlockedIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private static function isBlockedIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        foreach (self::BLOCKED_V4 as $cidr) {
            if (str_contains($ip, ':')) {
                continue;
            }
            if (self::inCidr($packed, $cidr)) {
                return true;
            }
        }

        foreach (self::BLOCKED_V6 as $cidr) {
            if (! str_contains($ip, ':') && ! str_contains($cidr, ':')) {
                continue;
            }
            if (self::inCidr($packed, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $packedIp, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);
        $subnetPacked = @inet_pton($subnet);
        if ($subnetPacked === false) {
            return false;
        }

        $maxMask = strlen($subnetPacked) * 8;
        $bits = (int) $mask;
        if ($bits < 0 || $bits > $maxMask) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($packedIp, 0, $bytes) !== substr($subnetPacked, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $maskByte = 0xFF << (8 - $remainder);

        return ((ord($packedIp[$bytes]) ^ ord($subnetPacked[$bytes])) & $maskByte) === 0;
    }
}
