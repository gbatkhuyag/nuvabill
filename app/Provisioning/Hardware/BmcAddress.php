<?php

namespace App\Provisioning\Hardware;

use App\Models\Machine;
use Closure;

/**
 * The checked network address of a machine's BMC, made at call time.
 *
 * Staff type the BMC host; no address ever comes from a client. It must be an IP address or a host
 * name: no scheme, path, login or port. Private networks are allowed (BMCs live there), but
 * loopback, link-local and cloud metadata addresses, multicast, broadcast and "unspecified" are
 * not, also when hidden in an IPv4-mapped, NAT64 or 6to4 IPv6 address. A host name is resolved
 * here and every address it resolves to must pass; the HTTP client is then pinned to the checked
 * address (CURLOPT_RESOLVE), so DNS cannot point the request elsewhere between the check and the
 * connection.
 */
final class BmcAddress
{
    /**
     * Refused IPv4 ranges: "this network", loopback, link-local (cloud metadata), multicast,
     * reserved and broadcast, and the Alibaba Cloud metadata address.
     *
     * @var list<string>
     */
    private const DENIED_V4 = ['0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4', '100.100.100.200/32'];

    /**
     * Refused IPv6 ranges: unspecified, loopback, link-local, multicast and the AWS metadata address.
     *
     * @var list<string>
     */
    private const DENIED_V6 = ['::/128', '::1/128', 'fe80::/10', 'ff00::/8', 'fd00:ec2::254/128'];

    /**
     * IPv6 ranges that carry an IPv4 address, checked again as that IPv4 address: IPv4-mapped,
     * IPv4-compatible and NAT64 (last 32 bits), and 6to4 (bits 16 to 48).
     *
     * @var array<string, int>
     */
    private const EMBEDDED_V4 = ['::ffff:0:0/96' => 12, '::/96' => 12, '64:ff9b::/96' => 12, '2002::/16' => 2];

    /**
     * Replaces the DNS lookup in tests.
     *
     * @var (Closure(string): list<string>)|null
     */
    private static ?Closure $lookup = null;

    public function __construct(
        public readonly string $host,
        public readonly string $ip,
        public readonly int $port,
    ) {}

    /**
     * Why staff may not use this BMC host, or null when it is fine.
     */
    public static function validateHost(string $host): ?string
    {
        if ($host === '' || strlen($host) > 190) {
            return __('Enter the BMC address.');
        }

        if (preg_match('/[\s\/\\\\@?#\[\]%]/', $host) === 1 || str_contains($host, '://')) {
            return __('Enter only an IP address or a host name, without https://, a path, a login or a port.');
        }

        $ip = self::normalizeIp($host);

        if ($ip !== null) {
            return self::ipAllowed($ip) ? null : __('This address cannot be used for a BMC.');
        }

        if (str_contains($host, ':')) {
            return __('Enter only an IP address or a host name, without https://, a path, a login or a port.');
        }

        $name = strtolower(rtrim($host, '.'));
        $labels = explode('.', $name);

        // A last part made of digits only is not a real name, but a short form of an IP address
        // (such as 2130706433 or 127.1) that some systems would read as one.
        if (filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', (string) end($labels)) === 1) {
            return __('Enter a valid IP address or host name.');
        }

        if ($name === 'localhost' || str_ends_with($name, '.localhost')) {
            return __('This address cannot be used for a BMC.');
        }

        return null;
    }

    /**
     * Check the machine's BMC address now and resolve it to one checked IP address.
     *
     * @throws BmcException when the address is not allowed or does not resolve.
     */
    public static function for(Machine $machine, int $defaultPort): self
    {
        $host = (string) $machine->bmc_host;
        $problem = self::validateHost($host);

        if ($problem !== null) {
            throw BmcException::refused($problem);
        }

        $port = (int) ($machine->bmc_port ?: $defaultPort);

        if ($port < 1 || $port > 65535) {
            throw BmcException::refused(__('The BMC port must be between 1 and 65535.'));
        }

        $ip = self::normalizeIp($host);

        if ($ip !== null) {
            return new self($ip, $ip, $port);
        }

        $host = strtolower(rtrim($host, '.'));
        $resolved = [];

        foreach (self::lookup($host) as $address) {
            $checked = self::normalizeIp($address);

            // Every address must pass: a name that also points at a refused address is refused.
            if ($checked === null || ! self::ipAllowed($checked)) {
                throw BmcException::refused(__('The BMC host name points to an address that cannot be used.'));
            }

            $resolved[] = $checked;
        }

        if ($resolved === []) {
            throw BmcException::unreachable(__('The BMC host name does not resolve to an address.'));
        }

        return new self($host, $resolved[0], $port);
    }

    /**
     * The checked IP address of the machine's BMC at call time.
     *
     * @throws BmcException
     */
    public static function resolve(Machine $machine): string
    {
        return self::for($machine, $machine->bmc_type === 'ipmi' ? 623 : 443)->ip;
    }

    /**
     * Use another DNS lookup, or null for the real one. For tests.
     *
     * @param  (Closure(string): list<string>)|null  $lookup
     */
    public static function lookupUsing(?Closure $lookup): void
    {
        self::$lookup = $lookup;
    }

    /**
     * Whether staff typed a host name rather than an IP address.
     */
    public function isHostName(): bool
    {
        return $this->host !== $this->ip;
    }

    /**
     * The host part of the BMC's URL: the host name, or the IP address (IPv6 in brackets).
     */
    public function urlHost(): string
    {
        return $this->isHostName() ? $this->host : self::bracket($this->ip);
    }

    /**
     * The base URL of the BMC, such as https://10.20.0.11:443.
     */
    public function baseUrl(): string
    {
        return 'https://'.$this->urlHost().':'.$this->port;
    }

    /**
     * The CURLOPT_RESOLVE line that pins a host name to the checked address, or null for an IP
     * address.
     */
    public function curlResolve(): ?string
    {
        return $this->isHostName() ? "{$this->host}:{$this->port}:".self::bracket($this->ip) : null;
    }

    /**
     * Whether an IP address may be used for a BMC.
     */
    public static function ipAllowed(string $ip): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 4) {
            foreach (self::DENIED_V4 as $range) {
                if (self::inRange($binary, $range)) {
                    return false;
                }
            }

            return true;
        }

        foreach (self::DENIED_V6 as $range) {
            if (self::inRange($binary, $range)) {
                return false;
            }
        }

        foreach (self::EMBEDDED_V4 as $range => $offset) {
            if (self::inRange($binary, $range) && ! self::ipAllowed((string) inet_ntop(substr($binary, $offset, 4)))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The canonical form of an IP address, with an IPv4-mapped IPv6 address turned into IPv4, or
     * null when the value is not an IP address.
     */
    private static function normalizeIp(string $value): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = (string) inet_pton($value);

        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            $binary = substr($binary, 12);
        }

        return (string) inet_ntop($binary);
    }

    private static function inRange(string $binary, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $network = (string) inet_pton($network);
        $bits = (int) $bits;

        if (strlen($network) !== strlen($binary)) {
            return false;
        }

        $bytes = intdiv($bits, 8);

        if (substr($binary, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /**
     * The A and AAAA addresses of a host name.
     *
     * @return list<string>
     */
    private static function lookup(string $host): array
    {
        if (self::$lookup !== null) {
            return array_values((self::$lookup)($host));
        }

        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            $fallback = @gethostbynamel($host);
            $addresses = is_array($fallback) ? $fallback : [];
        }

        return array_values(array_unique($addresses));
    }

    private static function bracket(string $ip): string
    {
        return str_contains($ip, ':') ? "[{$ip}]" : $ip;
    }
}
