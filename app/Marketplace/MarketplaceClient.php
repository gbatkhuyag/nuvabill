<?php

namespace App\Marketplace;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Talks to the marketplace store's API: the catalog, downloads and license checks. A site that is
 * connected to the marketplace (SiteConnection) sends its key with every call and goes out over
 * the address family the store allowed, so Cloudflare lets it through.
 */
class MarketplaceClient
{
    private const CATALOG_CACHE = 'nuvabill.marketplace.catalog';

    private const LAST_CATALOG = 'nuvabill.marketplace.catalog.last';

    private ?string $lastError = null;

    public function __construct(private SiteConnection $connection) {}

    public function baseUrl(): string
    {
        return (string) config('nuvabill.marketplace.url');
    }

    /**
     * Every item in the marketplace, cached for ten minutes. Pass $fresh to ask the store now, for
     * example to look for new versions. When the store cannot be reached, the last list it sent.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(bool $fresh = false): array
    {
        $cached = $fresh ? null : Cache::get(self::CATALOG_CACHE);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->send('get', '/api/marketplace/v1/catalog', ['nuvabill' => config('nuvabill.version')]);
        } catch (Throwable $exception) {
            $this->lastError = __('The marketplace cannot be reached right now. Try again in a few minutes.');
            report($exception);

            return $this->cachedCatalog() ?? [];
        }

        if (! $response->successful() || ! is_array($response->json('items'))) {
            $this->lastError = self::isChallenge($response) ? self::blockedMessage() : __('The marketplace cannot be reached right now. Try again in a few minutes.');

            return $this->cachedCatalog() ?? [];
        }

        $items = array_values(array_filter($response->json('items'), fn ($item): bool => is_array($item)
            && is_string($item['slug'] ?? null)
            && PackageType::tryFrom((string) ($item['type'] ?? '')) !== null));

        Cache::put(self::CATALOG_CACHE, $items, 600);
        Cache::forever(self::LAST_CATALOG, $items);

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function item(string $slug): ?array
    {
        foreach ($this->catalog() as $item) {
            if ($item['slug'] === $slug) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The last catalog the store sent, however old, without calling the store.
     *
     * @return list<array<string, mixed>>|null
     */
    public function cachedCatalog(): ?array
    {
        $cached = Cache::get(self::LAST_CATALOG);

        return is_array($cached) ? $cached : null;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Ask the store for a download of the newest version. Paid items need their license key; the
     * store ties the key to this site the first time it is used.
     *
     * @return array{slug: string, type: string, version: string, url: string, sha256: string, signature: string}
     *
     * @throws RuntimeException With a message for staff when the store refuses.
     */
    public function download(string $slug, ?string $licenseKey): array
    {
        try {
            $response = $this->send('post', '/api/marketplace/v1/download', [
                'slug' => $slug,
                'license_key' => $licenseKey ?: null,
                'site' => $this->site(),
                'nuvabill' => config('nuvabill.version'),
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException(__('The marketplace cannot be reached right now. Try again in a few minutes.'));
        }

        if (self::isChallenge($response)) {
            throw new RuntimeException(self::blockedMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('message') ?: __('The marketplace refused the download (error :status).', ['status' => $response->status()])));
        }

        $data = (array) $response->json();

        foreach (['slug', 'type', 'version', 'url', 'sha256', 'signature'] as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                throw new RuntimeException(__('The marketplace sent an incomplete answer. Try again later.'));
            }
        }

        return $data;
    }

    /**
     * Check a license with the store.
     *
     * @return array{valid: bool, status: string, message: string}|null Null when the store cannot be reached.
     */
    public function checkLicense(string $slug, string $licenseKey, ?string $version = null): ?array
    {
        try {
            $response = $this->send('post', '/api/marketplace/v1/licenses/check', [
                'slug' => $slug,
                'license_key' => $licenseKey,
                'site' => $this->site(),
                'version' => $version,
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->serverError() || self::isChallenge($response) || ! is_bool($response->json('valid'))) {
            return null;
        }

        return [
            'valid' => (bool) $response->json('valid'),
            'status' => (string) $response->json('status', ''),
            'message' => (string) $response->json('message', ''),
        ];
    }

    /**
     * This site's host name, which license keys are tied to.
     */
    public function site(): string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if (($host === '' || $host === 'localhost') && app()->bound('request')) {
            $host = request()->getHost();
        }

        return strtolower($host);
    }

    /**
     * Tell the store this site is still here, from this address. Null when the store cannot be
     * reached; status "reconnect" when the store no longer knows the key.
     *
     * @return array{status: string, rotate: bool, ip: ?string}|null
     */
    public function heartbeat(string $ip): ?array
    {
        if ($this->siteKey() === null) {
            return null;
        }

        try {
            $response = $this->send('post', '/api/marketplace/v1/sites/heartbeat', ['ip' => $ip], retryWithoutKey: false);
        } catch (Throwable) {
            return null;
        }

        if ($response->status() === 401 && $response->json('code') === 'reconnect') {
            return ['status' => SiteConnection::RECONNECT, 'rotate' => false, 'ip' => null];
        }

        if (! $response->successful() || ! is_string($response->json('status'))) {
            return null;
        }

        return [
            'status' => (string) $response->json('status'),
            'rotate' => $response->json('rotate') === true,
            'ip' => is_string($response->json('ip')) ? $response->json('ip') : null,
        ];
    }

    /**
     * Ask the store for a new key. The old one stops working at once.
     */
    public function rotateKey(): ?string
    {
        try {
            $response = $this->send('post', '/api/marketplace/v1/sites/rotate', [], retryWithoutKey: false);
        } catch (Throwable) {
            return null;
        }

        $key = $response->successful() ? $response->json('key') : null;

        return is_string($key) && preg_match('/^nvb_site_[A-Za-z0-9_-]{43}$/', $key) ? $key : null;
    }

    /**
     * Add this site's key and address family to a call to the store, for example the package
     * download. Calls to any other host are left as they are, so the key never goes to them.
     */
    public function authorize(PendingRequest $request, string $url): PendingRequest
    {
        $key = $this->siteKey();

        if ($key === null || ! str_starts_with(strtolower($url), 'https://') || strcasecmp((string) parse_url($url, PHP_URL_HOST), (string) parse_url($this->baseUrl(), PHP_URL_HOST)) !== 0) {
            return $request;
        }

        return $request->withToken($key)->withOptions(['force_ip_resolve' => $this->connection->family() === 'ipv6' ? 'v6' : 'v4']);
    }

    /**
     * Cloudflare answered with a "Just a moment..." check instead of the store.
     */
    public static function isChallenge(Response $response): bool
    {
        if (strtolower($response->header('cf-mitigated')) === 'challenge') {
            return true;
        }

        if (! in_array($response->status(), [403, 429, 503], true) || ! str_contains(strtolower($response->header('Content-Type')), 'text/html')) {
            return false;
        }

        $body = substr($response->body(), 0, 20000);

        return str_contains($body, 'Just a moment') || str_contains($body, 'challenge-platform') || str_contains($body, '_cf_chl');
    }

    public static function blockedMessage(): string
    {
        return __('Cloudflare stopped this server from reaching the marketplace. Use "Connect to marketplace" on the Marketplace page to let it through.');
    }

    /**
     * One call to the store's API. When the store no longer knows this site's key, the connection
     * is marked for reconnecting and the call is made once more without the key.
     *
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $path, array $data, bool $retryWithoutKey = true): Response
    {
        $url = $this->baseUrl().$path;
        $hasKey = $this->siteKey() !== null;
        $response = $this->authorize($this->http(), $url)->{$method}($url, $data);

        if ($hasKey && $response->status() === 401 && $response->json('code') === 'reconnect') {
            $this->connection->markReconnect();

            if ($retryWithoutKey) {
                $response = $this->http()->{$method}($url, $data);
            }
        }

        if (self::isChallenge($response)) {
            Cache::put(SiteConnection::BLOCKED_CACHE, true, now()->addDay());
        } elseif ($response->successful()) {
            Cache::forget(SiteConnection::BLOCKED_CACHE);
        }

        return $response;
    }

    /**
     * The key to send, unless the store said it no longer knows it.
     */
    private function siteKey(): ?string
    {
        return $this->connection->status() === SiteConnection::RECONNECT ? null : $this->connection->key();
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(20)->withUserAgent('Nuvabill/'.config('nuvabill.version'));
    }
}
