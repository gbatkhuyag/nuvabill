<?php

namespace App\Marketplace\Store;

use App\Marketplace\SiteConnection;
use App\Models\Admin;
use App\Models\MarketplaceSite;
use App\Security\PublicAddress;
use App\Support\Activity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The marketplace store only: sites that connect to the marketplace ("Connect to marketplace").
 *
 * The store calls the site back at its own web address with the one-time code from the click,
 * so only the owner of the address can connect it. The site answers with its server's outgoing
 * address. When that address is the site's own (its DNS points there, or the store reached the
 * site's server at it directly), the store lets it through Cloudflare by itself. Anything else
 * waits for store staff. One Cloudflare rule per address, shared by every site on it, and only
 * removed when no active site uses the address any more.
 */
class ConnectedSites
{
    private const DAILY_CACHE = 'nuvabill.marketplace.connects.';

    public function __construct(private CloudflareAccess $cloudflare) {}

    /**
     * Connect a site, or connect it again with a new key and address.
     *
     * @return array{site: MarketplaceSite, url: string}
     *
     * @throws RuntimeException With a message for the person connecting.
     */
    public function connect(string $host, string $state, ?string $hint = null): array
    {
        $host = strtolower($host);
        $today = self::DAILY_CACHE.now()->toDateString();

        if ((int) Cache::get($today, 0) >= max(1, (int) setting('marketplace.connect_daily_limit', 200))) {
            throw new RuntimeException(__('Too many sites connected today. Try again tomorrow.'));
        }

        $existing = MarketplaceSite::query()->where('host', $host)->first();
        $previous = $existing?->only(['key_hash', 'key_created_at']);
        $key = 'nvb_site_'.SiteConnection::base64url(random_bytes(32));

        $site = $existing ?? new MarketplaceSite(['host' => $host, 'ip' => '', 'ip_family' => '', 'status' => MarketplaceSite::STATUS_PENDING]);
        $site->fill(['key_hash' => MarketplaceSite::hashKey($key), 'key_created_at' => now()]);
        $site->save();

        try {
            $answer = $this->callBack($host, $state, $key, $site->id, $hint);
        } catch (RuntimeException $exception) {
            // Undo: a new site goes away, a known site keeps its old key.
            $existing === null ? $site->delete() : $site->forceFill($previous)->save();

            throw $exception;
        }

        Cache::add($today, 0, now()->addDay());
        Cache::increment($today);

        $oldIp = $existing?->ip;
        $oldRule = $existing?->cloudflare_rule_id;
        $addresses = PublicAddress::publicAddresses($host);
        $matchesDns = in_array($answer['ip'], $addresses, true);
        $reachedDirectly = $answer['direct'];
        $crowded = MarketplaceSite::query()
            ->where('ip', $answer['ip'])
            ->where('status', MarketplaceSite::STATUS_ACTIVE)
            ->where('id', '!=', $site->id)
            ->count() >= max(1, (int) setting('marketplace.connect_sites_per_ip', 5));
        // Staff removed this site before, so they decide again.
        $wasRevoked = $existing?->status === MarketplaceSite::STATUS_REVOKED;
        $active = ($matchesDns || $reachedDirectly) && ! $crowded && ! $wasRevoked;

        $site->forceFill([
            'ip' => $answer['ip'],
            'ip_family' => $answer['family'],
            'status' => $active ? MarketplaceSite::STATUS_ACTIVE : MarketplaceSite::STATUS_PENDING,
            'ip_matches_dns' => $matchesDns,
            'ip_reached_directly' => $reachedDirectly,
            'last_seen_at' => now(),
            'last_ip' => $answer['ip'],
            'approved_by' => $active ? null : $site->approved_by,
            'notes' => $active ? null : $this->pendingReason($matchesDns || $reachedDirectly, $crowded, $wasRevoked),
            'cloudflare_rule_id' => $oldIp === $answer['ip'] ? $oldRule : null,
        ])->save();

        if ($oldRule !== null && $oldIp !== $answer['ip']) {
            $this->releaseRule($site, (string) $oldIp, $oldRule);
        }

        if ($active) {
            $this->ensureRule($site);
            Activity::log('marketplace.site_connected', "Site {$host} connected to the marketplace from {$answer['ip']}", $site);
        } else {
            $this->releaseIfUnused($site);
            Activity::log('marketplace.site_pending', "Site {$host} connected from {$answer['ip']} and waits for approval: {$site->notes}", $site);
        }

        return ['site' => $site, 'url' => 'https://'.$host.$answer['return_path'].'?connected=1'];
    }

    /**
     * Staff allow a waiting site.
     */
    public function approve(MarketplaceSite $site, ?Admin $admin): void
    {
        $site->forceFill(['status' => MarketplaceSite::STATUS_ACTIVE, 'approved_by' => $admin?->id, 'notes' => null])->save();
        $this->ensureRule($site);
    }

    /**
     * Staff remove a site: its key stops working and its address is no longer let through.
     */
    public function revoke(MarketplaceSite $site): void
    {
        $this->end($site, MarketplaceSite::STATUS_REVOKED);
    }

    /**
     * A connected site says it is still here, from this address.
     *
     * @return array{status: string, rotate: bool, ip: string}
     */
    public function heartbeat(MarketplaceSite $site, string $ip): array
    {
        $site->forceFill(['last_seen_at' => now(), 'last_ip' => $ip])->save();

        if ($ip !== $site->ip && $this->isAllowedAddress($ip)) {
            $this->moveTo($site, $ip);
        }

        return ['status' => $site->status, 'rotate' => $site->needsNewKey(), 'ip' => $site->ip];
    }

    /**
     * A new key for a site. The old one stops working at once.
     */
    public function rotate(MarketplaceSite $site): string
    {
        $key = 'nvb_site_'.SiteConnection::base64url(random_bytes(32));
        $site->forceFill(['key_hash' => MarketplaceSite::hashKey($key), 'key_created_at' => now()])->save();

        return $key;
    }

    /**
     * Sites not seen for 60 days, and waiting sites staff never approved in 14 days, end.
     *
     * @return int How many ended.
     */
    public function prune(): int
    {
        $count = 0;
        $stale = MarketplaceSite::query()->usable()
            ->where(fn ($query) => $query
                ->where('last_seen_at', '<', now()->subDays(60))
                ->orWhere(fn ($query) => $query->whereNull('last_seen_at')->where('key_created_at', '<', now()->subDays(60)))
                ->orWhere(fn ($query) => $query->where('status', MarketplaceSite::STATUS_PENDING)->where('key_created_at', '<', now()->subDays(14))))
            ->get();

        foreach ($stale as $site) {
            $this->end($site, MarketplaceSite::STATUS_EXPIRED);
            $count++;
        }

        return $count;
    }

    /**
     * Add the missing Cloudflare rules of active sites, for example after Cloudflare was set up or
     * could not be reached. A limited number per run, to stay within Cloudflare's API limits.
     *
     * @return int How many sites got a rule.
     */
    public function syncRules(int $limit = 100): int
    {
        if (! $this->cloudflare->isConfigured()) {
            return 0;
        }

        $added = 0;

        foreach (MarketplaceSite::query()->where('status', MarketplaceSite::STATUS_ACTIVE)->whereNull('cloudflare_rule_id')->limit($limit)->get() as $site) {
            $this->ensureRule($site);
            $added += $site->cloudflare_rule_id !== null ? 1 : 0;
        }

        return $added;
    }

    /**
     * A public address that is not Cloudflare's own (a site behind Cloudflare would otherwise
     * let all of Cloudflare through).
     */
    public function isAllowedAddress(string $ip): bool
    {
        return PublicAddress::isPublic($ip) && ! PublicAddress::isCloudflare($ip);
    }

    /**
     * Call the site at https://{host}/nuvabill-marketplace/connect. First straight to the address
     * the site said it uses, which also shows that the address is the site's own server. When that
     * cannot connect (a firewall, or a site behind Cloudflare), through the host's public DNS.
     *
     * @return array{ip: string, family: string, return_path: string, direct: bool}
     */
    private function callBack(string $host, string $state, string $key, int $siteId, ?string $hint): array
    {
        $body = ['state' => $state, 'key' => $key, 'site_id' => $siteId];

        if ($hint !== null && $this->isAllowedAddress($hint)) {
            try {
                $response = PublicAddress::pinned($host, $hint)->acceptJson()->asJson()->post("https://{$host}/nuvabill-marketplace/connect", $body);
                $answer = $this->readAnswer($response);

                if ($answer !== null && $answer['ip'] === $hint) {
                    return $answer + ['direct' => true];
                }

                if ($answer !== null) {
                    return $answer + ['direct' => false];
                }
            } catch (Throwable) {
                // Not reachable at that address: try the public DNS below.
            }
        }

        $addresses = PublicAddress::publicAddresses($host);
        usort($addresses, fn (string $a, string $b): int => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        if ($addresses === []) {
            throw new RuntimeException(__(':host does not point to a public internet address.', ['host' => $host]));
        }

        try {
            $response = PublicAddress::pinned($host, $addresses[0])->acceptJson()->asJson()->post("https://{$host}/nuvabill-marketplace/connect", $body);
        } catch (Throwable $exception) {
            Log::info('Marketplace connect: the site could not be reached.', ['host' => $host, 'error' => class_basename($exception)]);

            throw new RuntimeException($this->unreachable($host));
        }

        $answer = $this->readAnswer($response);

        if ($answer === null) {
            Log::info('Marketplace connect: the site refused or sent an invalid answer.', ['host' => $host, 'status' => $response->status()]);

            throw new RuntimeException($this->unreachable($host));
        }

        return $answer + ['direct' => in_array($answer['ip'], [$addresses[0]], true)];
    }

    /**
     * @return array{ip: string, family: string, return_path: string}|null
     */
    private function readAnswer(Response $response): ?array
    {
        if (! $response->successful() || $response->json('ok') !== true || strlen($response->body()) > 65536) {
            return null;
        }

        $ip = $response->json('ip');
        $family = $response->json('family');
        $path = $response->json('return_path');

        if (! is_string($ip) || ! is_string($family) || ! is_string($path)
            || ! $this->isAllowedAddress($ip) || PublicAddress::family($ip) !== $family
            || ! preg_match('#^/(?!/)[A-Za-z0-9._~/-]{0,200}$#', $path)) {
            return null;
        }

        return ['ip' => $ip, 'family' => $family, 'return_path' => $path];
    }

    private function unreachable(string $host): string
    {
        return __(':host could not be reached, or did not accept the connection. Check that the site is online with a valid HTTPS certificate and runs Nuvabill 0.7.0 or newer, then click Connect on your Marketplace page again.', ['host' => $host]);
    }

    private function pendingReason(bool $ownAddress, bool $crowded, bool $wasRevoked): string
    {
        return match (true) {
            $wasRevoked => 'Removed by staff before.',
            $crowded => 'Many sites already use this address.',
            ! $ownAddress => 'The address is not in the site\'s DNS and could not be reached directly.',
            default => '',
        };
    }

    /**
     * A site's server now goes out from another address. When the site's DNS points there, the
     * rule moves with it; otherwise the site waits for staff.
     */
    private function moveTo(MarketplaceSite $site, string $ip): void
    {
        $oldIp = $site->ip;
        $oldRule = $site->cloudflare_rule_id;
        $matchesDns = in_array($ip, PublicAddress::publicAddresses($site->host), true);
        $active = $site->isActive() && $matchesDns;

        $site->forceFill([
            'ip' => $ip,
            'ip_family' => (string) PublicAddress::family($ip),
            'ip_matches_dns' => $matchesDns,
            'ip_reached_directly' => false,
            'status' => $active ? MarketplaceSite::STATUS_ACTIVE : MarketplaceSite::STATUS_PENDING,
            'notes' => $active ? null : 'The server address changed from '.$oldIp.'.',
            'cloudflare_rule_id' => null,
        ])->save();

        if ($oldRule !== null) {
            $this->releaseRule($site, $oldIp, $oldRule);
        }

        if ($active) {
            $this->ensureRule($site);
        }

        Activity::log('marketplace.site_moved', "Site {$site->host} now goes out from {$ip} (was {$oldIp})".($active ? '' : ' and waits for approval'), $site);
    }

    private function end(MarketplaceSite $site, string $status): void
    {
        $rule = $site->cloudflare_rule_id;
        $site->forceFill(['status' => $status, 'cloudflare_rule_id' => null])->save();

        if ($rule !== null) {
            $this->releaseRule($site, $site->ip, $rule);
        }
    }

    /**
     * Let an active site's address through Cloudflare: with the rule another site on the same
     * address has, or a new one.
     */
    private function ensureRule(MarketplaceSite $site): void
    {
        if (! $site->isActive() || $site->cloudflare_rule_id !== null || $site->ip === '') {
            return;
        }

        $shared = MarketplaceSite::query()
            ->where('ip', $site->ip)
            ->where('status', MarketplaceSite::STATUS_ACTIVE)
            ->whereNotNull('cloudflare_rule_id')
            ->where('id', '!=', $site->id)
            ->value('cloudflare_rule_id');

        $rule = $shared ?? $this->cloudflare->allow($site->ip, $site->ip_family, "Nuvabill site {$site->host} #{$site->id}");

        if ($rule !== null) {
            $site->forceFill(['cloudflare_rule_id' => $rule])->save();
        }
    }

    /**
     * A site no longer needs the rule for an address. The rule only goes when no other active
     * site uses that address; otherwise it stays with them.
     */
    private function releaseRule(MarketplaceSite $site, string $ip, string $rule): void
    {
        $others = MarketplaceSite::query()
            ->where('ip', $ip)
            ->where('status', MarketplaceSite::STATUS_ACTIVE)
            ->where('id', '!=', $site->id);

        if ($others->exists()) {
            // Make sure the rule stays noted on a site that still uses it.
            (clone $others)->whereNull('cloudflare_rule_id')->update(['cloudflare_rule_id' => $rule]);

            return;
        }

        $this->cloudflare->remove($rule);
    }

    /**
     * A waiting site has no rule of its own.
     */
    private function releaseIfUnused(MarketplaceSite $site): void
    {
        if ($site->cloudflare_rule_id !== null) {
            $rule = $site->cloudflare_rule_id;
            $site->forceFill(['cloudflare_rule_id' => null])->save();
            $this->releaseRule($site, $site->ip, $rule);
        }
    }
}
