<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Outbound requests to addresses a user typed in (e.g. external calendars): only http(s) on the
 * standard ports, only to public IP addresses, the resolved address pinned for the request (no DNS
 * rebinding) and every redirect checked again – so nobody can make the server reach its database,
 * the cloud metadata service or other internal hosts (SSRF). Errors say "not reachable", nothing more.
 */
class SafeUrl
{
    public const MAX_REDIRECTS = 3;

    /**
     * Not public either, but let through by PHP's private/reserved filter: carrier-grade NAT (used
     * by VPN meshes and cloud-internal networks), protocol assignments, benchmark and documentation
     * networks, and the IPv6 forms that carry an IPv4 address inside (mapped, NAT64, 6to4, Teredo),
     * which would reach an internal IPv4 host through a gateway.
     */
    private const NOT_PUBLIC = [
        '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '2001::/23', '2001:db8::/32', '2002::/16',
    ];

    /** Resolves a host to its IP addresses; replaceable in tests */
    private static ?Closure $resolver = null;

    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function get(string $url, array $headers = [], int $timeout = 20): Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = self::check($url);
            $response = Http::timeout($timeout)->withHeaders($headers)
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$ip]]])
                ->get($url);
            if (! $response->redirect()) {
                return $response;
            }
            $location = (string) $response->header('Location');
            $url = str_starts_with($location, '/') ? preg_replace('#^(https?://[^/]+).*$#i', '$1', $url).$location : $location;
        }

        throw new RuntimeException('Zu viele Weiterleitungen.');
    }

    /** @return array{0: string, 1: int, 2: string} host, port and the public IP to use */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || ! in_array($port, [80, 443], true)) {
            throw new RuntimeException('Diese Adresse ist nicht erlaubt (nur http/https ohne eigenen Port).');
        }
        $ips = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false ? [trim($host, '[]')]
            : (self::$resolver ? (self::$resolver)($host) : self::resolve($host));
        $public = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($ip, self::NOT_PUBLIC)));
        if ($ips === [] || count($public) !== count($ips)) {
            throw new RuntimeException('Die Adresse ist nicht erreichbar.');
        }

        return [$host, $port, $public[0]];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
