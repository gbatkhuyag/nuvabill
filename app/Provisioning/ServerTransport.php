<?php

namespace App\Provisioning;

use App\Models\Server;
use App\Security\PublicAddress;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * How a server module may reach a control panel. HTTPS always, unless staff turned it off and
 * ticked "Allow plain HTTP" for a server on a private network. Without that box, a module refuses
 * before it sends the password or API token anywhere.
 */
class ServerTransport
{
    /**
     * Private and loopback ranges where plain HTTP may be allowed. Link-local addresses
     * (169.254.0.0/16, fe80::/10, cloud metadata) are left out on purpose.
     *
     * @var list<string>
     */
    public const PRIVATE_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '::1/128',
        'fc00::/7',
    ];

    /**
     * "https", or "http" for a server with plain HTTP allowed. Throws when the server is set to
     * plain HTTP without the box, so nothing is sent.
     */
    public static function scheme(Server $server): string
    {
        if ($server->use_ssl !== false) {
            return 'https';
        }

        if ($server->allow_plain_http === true) {
            return 'http';
        }

        throw new RuntimeException(__('This server is set to plain HTTP. Turn on "Connect with HTTPS", or allow plain HTTP for a private-network server.'));
    }

    /**
     * A host name or address where every address is private or loopback. A name that does not
     * resolve is not private.
     */
    public static function isPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        if ($host === '') {
            return false;
        }

        $addresses = PublicAddress::resolve($host);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! self::isPrivateAddress($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * An IPv4 or IPv6 address in one of the private or loopback ranges. An IPv4-mapped IPv6
     * address (::ffff:10.0.0.5) counts by the IPv4 address it carries.
     */
    public static function isPrivateAddress(string $ip): bool
    {
        $family = PublicAddress::family($ip);

        if ($family === null) {
            return false;
        }

        if ($family === 'ipv6' && IpUtils::checkIp($ip, '::ffff:0:0/96')) {
            $ip = (string) inet_ntop(substr((string) inet_pton($ip), 12, 4));
        }

        return IpUtils::checkIp($ip, self::PRIVATE_RANGES);
    }
}
