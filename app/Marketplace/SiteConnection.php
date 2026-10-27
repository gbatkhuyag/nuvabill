<?php

namespace App\Marketplace;

use App\Security\PublicAddress;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * "Connect to marketplace": this site proves to the marketplace store that it owns its web
 * address, and gets a key of its own. The store then lets this server's internet address past
 * Cloudflare's bot checks, which otherwise stop server-to-server calls from data centres.
 *
 * How it works: staff click Connect. This site notes its outgoing address and a one-time code,
 * and sends the browser to the store. The store calls this site back at its own web address with
 * the code and the new key, so only the real owner of the address can connect it. Sites that
 * never connect keep working as before.
 */
class SiteConnection
{
    /**
     * Waiting for the store to confirm the connection (right after connecting).
     */
    public const WAITING = 'waiting';

    public const ACTIVE = 'active';

    /**
     * The store keeps the key but waits for its staff to allow this server's address.
     */
    public const PENDING = 'pending';

    /**
     * The store no longer knows the key: connect again.
     */
    public const RECONNECT = 'reconnect';

    /**
     * This server now goes out from another address than the one the store allowed.
     */
    public const IP_CHANGED = 'ip_changed';

    /**
     * How long the one-time code waits for the store's call.
     */
    public const STATE_MINUTES = 30;

    /**
     * Set for a day when Cloudflare stopped a call to the store with a challenge.
     */
    public const BLOCKED_CACHE = 'nuvabill.marketplace.blocked';

    private const STATE_CACHE = 'nuvabill.marketplace.connect.';

    private const USED_CACHE = 'nuvabill.marketplace.connect.used.';

    /**
     * The one-time code for the store's check of a new server address (see verifyMove()).
     */
    private const MOVE_CACHE = 'nuvabill.marketplace.move.';

    public function __construct(private Settings $settings) {}

    public function isConnected(): bool
    {
        return $this->key() !== null;
    }

    public function key(): ?string
    {
        $key = setting('marketplace.site_key');

        return is_string($key) && str_starts_with($key, 'nvb_site_') ? $key : null;
    }

    /**
     * The address family (ipv4 or ipv6) the store allowed. Every call to the store goes out over it.
     */
    public function family(): ?string
    {
        $family = setting('marketplace.site_family');

        return $this->isConnected() && in_array($family, ['ipv4', 'ipv6'], true) ? $family : null;
    }

    public function status(): ?string
    {
        return $this->isConnected() ? ((string) setting('marketplace.site_status') ?: self::WAITING) : null;
    }

    /**
     * What the admin page shows.
     *
     * @return array{connected: bool, status: ?string, ip: ?string, since: ?Carbon, blocked: bool}
     */
    public function summary(): array
    {
        $since = setting('marketplace.site_connected_at');

        return [
            'connected' => $this->isConnected(),
            'status' => $this->status(),
            'ip' => $this->isConnected() ? (string) setting('marketplace.site_ip') : null,
            'since' => is_string($since) && $since !== '' ? rescue(fn () => Carbon::parse($since), null, false) : null,
            'blocked' => (bool) Cache::get(self::BLOCKED_CACHE),
        ];
    }

    /**
     * Start connecting: note this server's outgoing address and a one-time code, and give the
     * store page the browser goes to.
     *
     * @throws RuntimeException With a message for staff.
     */
    public function begin(string $site): string
    {
        if (! PublicAddress::isInternetHost($site)) {
            throw new RuntimeException(__('Only a site on a public web address can connect. Set APP_URL to the address clients use, for example https://billing.yourname.com.'));
        }

        $egress = $this->detectEgress();

        if ($egress === null) {
            throw new RuntimeException(__('This server\'s internet address could not be found. Check that it can reach the internet, then try again.'));
        }

        $state = self::base64url(random_bytes(32));

        Cache::put(self::STATE_CACHE.hash('sha256', $state), [
            'hash' => hash('sha256', $state),
            'ip' => $egress['ip'],
            'family' => $egress['family'],
        ], now()->addMinutes(self::STATE_MINUTES));

        return config('nuvabill.marketplace.url').'/marketplace/connect?'.http_build_query([
            'site' => $site,
            'state' => $state,
            'ip' => $egress['ip'],
        ]);
    }

    /**
     * The store's call back with the key. Works once per code, and only within half an hour of the
     * click. Returns what the store needs to know, or null.
     *
     * @return array{ip: string, family: string}|null
     */
    public function accept(string $state, string $key, int $siteId): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43,128}$/', $state) || ! preg_match('/^nvb_site_[A-Za-z0-9_-]{43}$/', $key) || $siteId < 1) {
            return null;
        }

        $stateHash = hash('sha256', $state);
        $saved = Cache::pull(self::STATE_CACHE.$stateHash);

        if (! is_array($saved) || ! is_string($saved['hash'] ?? null) || ! hash_equals($saved['hash'], $stateHash)) {
            // The store may ask a second time with the same code and key when it lost the first
            // answer (for example a time-out). Only the very same key, for a few minutes.
            $used = Cache::get(self::USED_CACHE.$stateHash);

            return is_array($used) && is_string($used['key'] ?? null) && hash_equals($used['key'], hash('sha256', $key)) && ($used['site_id'] ?? null) === $siteId
                && setting('marketplace.site_key') === $key
                ? ['ip' => (string) $used['ip'], 'family' => (string) $used['family']]
                : null;
        }

        Cache::put(self::USED_CACHE.$stateHash, ['key' => hash('sha256', $key), 'site_id' => $siteId, 'ip' => (string) $saved['ip'], 'family' => (string) $saved['family']], now()->addMinutes(5));

        $this->settings->setMany([
            'marketplace.site_key' => $key,
            'marketplace.site_id' => $siteId,
            'marketplace.site_ip' => (string) $saved['ip'],
            'marketplace.site_family' => (string) $saved['family'],
            'marketplace.site_status' => self::WAITING,
            'marketplace.site_connected_at' => now()->toIso8601String(),
        ]);
        Cache::forget(self::BLOCKED_CACHE);

        return ['ip' => (string) $saved['ip'], 'family' => (string) $saved['family']];
    }

    /**
     * This server's address on the internet, as Cloudflare sees it: first from the store's own
     * Cloudflare, then from Cloudflare's public 1.1.1.1. IPv4 is preferred. Pass a family to only
     * look at that one.
     *
     * @return array{ip: string, family: string}|null
     */
    public function detectEgress(?string $family = null): ?array
    {
        $families = $family !== null ? [$family] : ['ipv4', 'ipv6'];
        $sources = [config('nuvabill.marketplace.url').'/cdn-cgi/trace', 'https://1.1.1.1/cdn-cgi/trace'];

        foreach ($families as $wanted) {
            foreach ($sources as $source) {
                try {
                    $response = Http::timeout(8)->connectTimeout(5)->withoutRedirecting()
                        ->withOptions(['force_ip_resolve' => $wanted === 'ipv6' ? 'v6' : 'v4'])
                        ->get($source);
                } catch (Throwable) {
                    continue;
                }

                if (! $response->successful() || ! preg_match('/^ip=([0-9A-Fa-f:.]{2,45})$/m', $response->body(), $match)) {
                    continue;
                }

                $ip = $match[1];

                if (PublicAddress::isPublic($ip) && PublicAddress::family($ip) === $wanted) {
                    return ['ip' => $ip, 'family' => $wanted];
                }
            }
        }

        return null;
    }

    /**
     * Ask the store how this site's connection stands, change the key when the store asks for a new
     * one, and notice when this server goes out from another address. Returns the status.
     */
    public function heartbeat(MarketplaceClient $client): ?string
    {
        if (! $this->isConnected() || $this->status() === self::RECONNECT) {
            return $this->status();
        }

        $egress = $this->detectEgress($this->family());
        $moveToken = null;

        if ($egress !== null && $egress['ip'] !== setting('marketplace.site_ip')) {
            // The store lets the new address through only when it reaches this site at it with this code.
            $moveToken = self::base64url(random_bytes(32));
            Cache::put(self::MOVE_CACHE.hash('sha256', $moveToken), ['ip' => $egress['ip']], now()->addMinutes(10));
        }

        $answer = $client->heartbeat($egress['ip'] ?? (string) setting('marketplace.site_ip'), $moveToken);

        if ($answer === null) {
            // The store could not be reached. From another address Cloudflare stops it, so tell staff.
            if ($egress !== null && $egress['ip'] !== setting('marketplace.site_ip')) {
                $this->settings->set('marketplace.site_status', self::IP_CHANGED);
            }

            return $this->status();
        }

        if ($answer['status'] === self::RECONNECT) {
            $this->markReconnect();

            return self::RECONNECT;
        }

        $status = $answer['status'] === self::ACTIVE ? self::ACTIVE : self::PENDING;
        $values = ['marketplace.site_status' => $status, 'marketplace.site_checked_at' => now()->toIso8601String()];

        if ($egress !== null && $egress['ip'] !== setting('marketplace.site_ip')) {
            if ($status === self::ACTIVE && ($answer['ip'] ?? null) === $egress['ip']) {
                // The store moved its allow rule to the new address.
                $values['marketplace.site_ip'] = $egress['ip'];
            } else {
                $values['marketplace.site_status'] = self::IP_CHANGED;
            }
        }

        $this->settings->setMany($values);

        if ($answer['rotate']) {
            $key = $client->rotateKey();

            if ($key !== null) {
                $this->settings->set('marketplace.site_key', $key);
            }
        }

        return (string) $values['marketplace.site_status'];
    }

    /**
     * The store's check of a new server address: it calls this site at that address with the code
     * from the heartbeat. Works once. Returns the address the code was made for, or null.
     */
    public function verifyMove(string $token): ?string
    {
        if (! $this->isConnected() || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }

        $saved = Cache::pull(self::MOVE_CACHE.hash('sha256', $token));

        return is_array($saved) && is_string($saved['ip'] ?? null) ? $saved['ip'] : null;
    }

    public function markReconnect(): void
    {
        if ($this->isConnected() && setting('marketplace.site_status') !== self::RECONNECT) {
            $this->settings->set('marketplace.site_status', self::RECONNECT);
        }
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
