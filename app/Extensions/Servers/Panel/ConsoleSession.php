<?php

namespace App\Extensions\Servers\Panel;

use DateTimeImmutable;

/**
 * A browser console for one service, from HasConsole::consoleSession(). Core keeps it in a
 * single-use ticket and gives the URL and credentials to the console page by one POST, so they
 * never appear in HTML. Only connectSource() goes into the page's Content-Security-Policy.
 */
final readonly class ConsoleSession
{
    /**
     * @param  string  $url  wss://host[:port]/path[?query]. The query may hold a token.
     * @param  array{password?: string, username?: string, target?: string}  $credentials
     * @param  DateTimeImmutable|null  $expiresAt  When the panel stops accepting it. Core keeps the ticket 60 seconds at most.
     * @param  string  $protocol  Only "vnc" (noVNC) in this release.
     * @param  bool  $shared  Whether other viewers may stay connected.
     */
    public function __construct(
        public string $url,
        public array $credentials = [],
        public ?DateTimeImmutable $expiresAt = null,
        public string $protocol = 'vnc',
        public bool $shared = true,
    ) {}

    /**
     * The "wss://host:port" the console page may connect to, for its CSP. A missing port is 443.
     * Anything that is not a plain wss address of a DNS name or IPv4 address is refused, so
     * nothing else can reach the header. The refusal never repeats the URL, which may hold a token.
     *
     * @throws PanelException When the URL is not a plain wss address.
     */
    public function connectSource(): string
    {
        if (preg_match('/[\x00-\x20\x7F]/', $this->url) === 1) {
            throw $this->refused('The console address has spaces or control characters.');
        }

        if (preg_match('#^wss://([^/?\#]*)(?:[/?\#].*)?\z#s', $this->url, $match) !== 1) {
            throw $this->refused('The console address does not start with wss://.');
        }

        $authority = $match[1];

        if (str_contains($authority, '@')) {
            throw $this->refused('The console address has a user name or password in it.');
        }

        if (str_starts_with($authority, '[')) {
            throw $this->refused('The console address is an IPv6 address, which is not allowed.');
        }

        if (preg_match('/^([^:]+)(?::([0-9]{1,5}))?\z/', $authority, $parts) !== 1) {
            throw $this->refused('The console address has a host or port that is not valid.');
        }

        $host = rtrim(strtolower($parts[1]), '.');
        $port = isset($parts[2]) ? (int) $parts[2] : 443;

        if (! self::validHost($host)) {
            throw $this->refused('The console host is not a valid DNS name or IPv4 address.');
        }

        if ($port < 1 || $port > 65535) {
            throw $this->refused('The console port is not between 1 and 65535.');
        }

        return "wss://{$host}:{$port}";
    }

    /**
     * Keeps the address and credentials out of dumps and error pages.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'url' => '[hidden]',
            'credentials' => array_map(fn (): string => '[hidden]', $this->credentials),
            'expiresAt' => $this->expiresAt,
            'protocol' => $this->protocol,
            'shared' => $this->shared,
        ];
    }

    private static function validHost(string $host): bool
    {
        if (preg_match('/^[0-9.]+\z/', $host) === 1) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        return preg_match('/^(?=.{1,253}\z)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*\z/', $host) === 1;
    }

    private function refused(string $staffDetail): PanelException
    {
        return new PanelException(__('The console is not available for this server right now.'), $staffDetail, 503);
    }
}
