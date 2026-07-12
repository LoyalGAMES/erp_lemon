<?php

declare(strict_types=1);

namespace App\Services\Products;

use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

final class RemoteImageSourceGuard
{
    /**
     * PHP's FILTER_FLAG_NO_RES_RANGE does not cover every non-global range
     * (for example CGNAT, benchmarking, documentation and multicast ranges).
     * Keep an explicit deny-list so a thumbnail request can never be used as
     * a route into an address that is not intended for the public Internet.
     *
     * @var list<string>
     */
    private const NON_PUBLIC_IPV4_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /**
     * Includes local/special-use ranges and transition mechanisms that can
     * embed an IPv4 destination (NAT64, Teredo and 6to4).
     *
     * @var list<string>
     */
    private const NON_PUBLIC_IPV6_RANGES = [
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2001:2::/48',
        '2001:10::/28',
        '2001:20::/28',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        '5f00::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /**
     * Resolve and pin a remote image URL to a public address.
     *
     * @return array{url:string,host:string,port:int,address:string}|null
     */
    public function target(string $url): ?array
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || preg_match('/[^a-z0-9.:-]/i', $host) === 1
            || str_contains($host, '%')) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, [80, 443], true)) {
            return null;
        }

        $addresses = $this->resolveAddresses($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $ip) {
            if (! $this->isPublicIp($ip)) {
                return null;
            }
        }

        $address = $addresses[0];
        $displayHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $path = isset($parts['path']) && $parts['path'] !== '' ? (string) $parts['path'] : '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return [
            'url' => $scheme.'://'.$displayHost.($defaultPort ? '' : ':'.$port).$path.$query,
            'host' => $host,
            'port' => $port,
            'address' => $address,
        ];
    }

    public function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[]');

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $packed = @inet_pton($ip);
            $mapped = is_string($packed) && strlen($packed) === 16
                ? @inet_ntop(substr($packed, 12, 4))
                : false;

            if (is_string($mapped) && filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ip = $mapped;
            }
        }

        $ranges = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? self::NON_PUBLIC_IPV4_RANGES
            : self::NON_PUBLIC_IPV6_RANGES;

        return ! IpUtils::checkIp($ip, $ranges);
    }

    /**
     * @return list<string>
     */
    private function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        try {
            foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        } catch (Throwable) {
            // A failed lookup is treated as an unsafe source below.
        }

        if ($addresses === []) {
            try {
                foreach (gethostbynamel($host) ?: [] as $address) {
                    if (is_string($address) && $address !== '') {
                        $addresses[] = $address;
                    }
                }
            } catch (Throwable) {
                // A failed lookup is treated as an unsafe source below.
            }
        }

        return array_values(array_unique($addresses));
    }
}
