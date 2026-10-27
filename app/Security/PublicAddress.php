<?php

namespace App\Security;

use App\Support\Cloudflare;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Calls to addresses that someone else chose (automation web addresses, sites that connect to the
 * marketplace) may only reach the public internet: never this server, the local network or cloud
 * metadata. A host name is looked up once, every answer must be public, and the call is pinned to
 * the checked address, so a second DNS answer cannot lead somewhere else.
 */
class PublicAddress
{
    /**
     * Looks up the IPv4 and IPv6 addresses of a host name. Tests replace it, since made-up names do not resolve.
     *
     * @var (Closure(string): list<string>)|null
     */
    public static ?Closure $resolveUsing = null;

    /**
     * A public internet address: no private, reserved, shared or special ranges, such as
     * 100.64.0.0/10 (carrier networks, cloud metadata) or fc00::/7.
     */
    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    public static function family(string $ip): ?string
    {
        return match (true) {
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 'ipv4',
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 'ipv6',
            default => null,
        };
    }

    public static function isCloudflare(string $ip): bool
    {
        return IpUtils::checkIp($ip, Cloudflare::IP_RANGES);
    }

    /**
     * A host name (not an address) that can be on the internet: letters, digits and hyphens in
     * labels, at least one dot, and no names that only exist on a local network or for tests.
     */
    public static function isInternetHost(string $host): bool
    {
        $host = strtolower($host);

        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false
            || ! preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $host)) {
            return false;
        }

        foreach (['localhost', 'test', 'local', 'localdomain', 'example', 'invalid', 'internal', 'lan', 'home.arpa', 'intranet', 'corp', 'onion'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every address a host name points to (IPv4 and IPv6), or the address itself.
     *
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (self::$resolveUsing !== null) {
            return array_values((self::$resolveUsing)($host));
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses ?: (gethostbynamel($host) ?: [])));
    }

    /**
     * The addresses of a host when every one of them is public, otherwise none.
     *
     * @return list<string>
     */
    public static function publicAddresses(string $host): array
    {
        $addresses = self::resolve($host);

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                return [];
            }
        }

        return $addresses;
    }

    /**
     * An HTTPS request pinned to one checked address: no redirects, certificates checked, a short
     * timeout and a small answer. Port 443 unless another one is given.
     */
    public static function pinned(string $host, string $address, int $port = 443, int $timeout = 10, int $maxBytes = 65536): PendingRequest
    {
        $target = str_contains($address, ':') ? '['.$address.']' : $address;

        return Http::timeout($timeout)
            ->connectTimeout(min(5, $timeout))
            ->withoutRedirecting()
            ->withOptions([
                'verify' => true,
                'force_ip_resolve' => str_contains($address, ':') ? 'v6' : 'v4',
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$target}"]],
                // Do not read an answer that says it is larger than expected. The timeout limits the rest.
                'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new RuntimeException('The answer is too large.');
                    }
                },
            ]);
    }
}
