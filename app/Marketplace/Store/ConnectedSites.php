<?php

namespace App\Marketplace\Store;

use App\Marketplace\SiteConnection;
use App\Models\Admin;
use App\Models\MarketplaceSite;
use App\Security\PublicAddress;
use App\Support\Activity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The marketplace store only: sites that connect to the marketplace ("Connect to marketplace").
 *
 * The store calls the site back at its own web address with the one-time code from the click,
 * so only the owner of the address can connect it. The site answers with its server's outgoing
 * address. The store lets that address through Cloudflare by itself only when it reached the
 * site's own server at that very address over HTTPS (a valid certificate for the site's name).
 * Anything else waits for store staff. One Cloudflare rule per address, shared by every site on
 * it, and only removed when no active site uses the address any more.
 */
class ConnectedSites
{
    private const DAILY_CACHE = 'nuvabill.marketplace.connects.';

    private const REMOVALS = 'marketplace_rule_removals';

    public function __construct(private CloudflareAccess $cloudflare) {}

    /**
     * Connect a site, or connect it again with a new key and address. Nothing about a site that is
     * connected already changes until its own server took the new key, so a stranger who starts
     * connecting someone else's site cannot break its connection.
     *
     * @return array{site: MarketplaceSite, url: string}
     *
     * @throws RuntimeException With a message for the person connecting.
     */
    public function connect(string $host, string $state, ?string $hint = null): array
    {
        $host = strtolower($host);
        $domain = self::registrableDomain($host);
        $today = self::DAILY_CACHE.now()->toDateString();
        $domainToday = $today.'.'.hash('sha256', $domain);

        if ((int) Cache::get($today, 0) >= max(1, (int) setting('marketplace.connect_daily_limit', 200))) {
            throw new RuntimeException(__('Too many sites connected today. Try again tomorrow.'));
        }

        if ((int) Cache::get($domainToday, 0) >= max(1, (int) setting('marketplace.connect_domain_daily_limit', 10))) {
            throw new RuntimeException(__('Too many sites of :domain connected today. Try again tomorrow.', ['domain' => $domain]));
        }

        $existing = MarketplaceSite::query()->where('host', $host)->first();

        if ($existing === null && $this->hostsOf($domain) >= max(1, (int) setting('marketplace.connect_hosts_per_domain', 20))) {
            throw new RuntimeException(__('Too many sites of :domain are connected to the marketplace already.', ['domain' => $domain]));
        }

        $key = 'nvb_site_'.SiteConnection::base64url(random_bytes(32));
        $hash = MarketplaceSite::hashKey($key);
        $created = false;
        $site = $existing;

        if ($site === null) {
            // A new site needs its number for the call. Its key is new, so nobody holds it yet.
            try {
                $site = MarketplaceSite::query()->create([
                    'host' => $host, 'ip' => '', 'ip_family' => '', 'key_hash' => $hash, 'key_created_at' => now(),
                    'status' => MarketplaceSite::STATUS_PENDING, 'pending_since' => now(),
                ]);
                $created = true;
            } catch (UniqueConstraintViolationException) {
                $site = MarketplaceSite::query()->where('host', $host)->firstOrFail();
            }
        }

        try {
            $answer = $this->callBack($host, $state, $key, $site->id, $hint);
        } catch (RuntimeException $exception) {
            if ($created) {
                // Only the row this call made, and only while nobody else connected it meanwhile.
                MarketplaceSite::query()->whereKey($site->id)->where('key_hash', $hash)->where('ip', '')->delete();
            }

            throw $exception;
        }

        foreach ([$today, $domainToday] as $counter) {
            Cache::add($counter, 0, now()->addDay());
            Cache::increment($counter);
        }

        $matchesDns = in_array($answer['ip'], PublicAddress::publicAddresses($host), true);
        $reachedDirectly = $answer['direct'];

        [$site, $oldIp, $oldRule, $active] = DB::transaction(function () use ($site, $host, $hash, $answer, $matchesDns, $reachedDirectly): array {
            $site = MarketplaceSite::query()->whereKey($site->id)->lockForUpdate()->first()
                ?? throw new RuntimeException($this->unreachable($host));
            $oldIp = $site->ip;
            $oldRule = $site->cloudflare_rule_id;
            $crowded = $this->crowded($answer['ip'], $site->id);
            // Staff removed this site before, so they decide again.
            $wasRevoked = $site->revoked_at !== null;
            $active = $reachedDirectly && ! $crowded && ! $wasRevoked;

            $site->forceFill([
                'key_hash' => $hash,
                'key_created_at' => now(),
                'previous_key_hash' => null,
                'previous_key_expires_at' => null,
                'ip' => $answer['ip'],
                'ip_family' => $answer['family'],
                'status' => $active ? MarketplaceSite::STATUS_ACTIVE : MarketplaceSite::STATUS_PENDING,
                'pending_since' => $active ? null : $this->pendingSince($site),
                'ip_matches_dns' => $matchesDns,
                'ip_reached_directly' => $reachedDirectly,
                'last_seen_at' => now(),
                'last_ip' => $answer['ip'],
                'approved_by' => $active ? null : $site->approved_by,
                'pending_reason' => match (true) {
                    $active => null,
                    $wasRevoked => MarketplaceSite::REASON_REVOKED,
                    $crowded => MarketplaceSite::REASON_CROWDED,
                    default => MarketplaceSite::REASON_NOT_REACHED,
                },
                'cloudflare_rule_id' => $oldIp === $answer['ip'] ? $oldRule : null,
            ])->save();

            return [$site, $oldIp, $oldRule, $active];
        });

        if ($oldRule !== null && $oldIp !== $answer['ip']) {
            $this->releaseRule($site, (string) $oldIp, $oldRule);
        }

        if ($active) {
            $this->ensureRule($site);
            Activity::log('marketplace.site_connected', "Site {$host} connected to the marketplace from {$answer['ip']}", $site);
        } else {
            $this->releaseIfUnused($site);
            Activity::log('marketplace.site_pending', "Site {$host} connected from {$answer['ip']} and waits for approval ({$site->pending_reason})", $site);
        }

        return ['site' => $site, 'url' => 'https://'.$host.$answer['return_path'].'?connected=1'];
    }

    /**
     * Staff allow a waiting site. This also lifts an earlier removal.
     */
    public function approve(MarketplaceSite $site, ?Admin $admin): void
    {
        $site->forceFill([
            'status' => MarketplaceSite::STATUS_ACTIVE,
            'approved_by' => $admin?->id,
            'pending_reason' => null,
            'pending_since' => null,
            'revoked_at' => null,
        ])->save();
        $this->ensureRule($site);
    }

    /**
     * Staff remove a site: its key stops working and its address is no longer let through. When
     * it connects again, it waits for staff.
     */
    public function revoke(MarketplaceSite $site): void
    {
        $this->end($site, MarketplaceSite::STATUS_REVOKED);
    }

    /**
     * A connected site says it is still here, from this address. A new address is only let through
     * when the store reaches the site's own server at it, with the one-time code the site sent.
     *
     * @return array{status: string, rotate: bool, ip: string}
     */
    public function heartbeat(MarketplaceSite $site, string $ip, ?string $moveToken = null, bool $previousKey = false): array
    {
        $site->forceFill(['last_seen_at' => now(), 'last_ip' => $ip])->save();

        if ($ip !== $site->ip && $site->ip !== '') {
            if (! $this->isAllowedAddress($ip)) {
                if ($site->pending_reason !== MarketplaceSite::REASON_IP_NOT_ALLOWED.':'.$ip) {
                    $this->hold($site, MarketplaceSite::REASON_IP_NOT_ALLOWED.':'.$ip);
                }
            } else {
                $this->moveTo($site, $ip, $moveToken !== null && $this->reachesAt($site->host, $ip, $moveToken));
            }
        }

        // A site that called with its previous key never got the new one: it gets another.
        return ['status' => $site->status, 'rotate' => $previousKey || $site->needsNewKey(), 'ip' => $site->ip];
    }

    /**
     * A new key for a site. The key the call came with keeps working for a day, in case the answer
     * never reaches the site; it stops as soon as the site uses the new key. A call with that
     * previous key gets a new key too, but the previous key keeps its first end time: otherwise
     * someone with an old key could ask again every day and keep it for ever.
     */
    public function rotate(MarketplaceSite $site, string $usedKey = ''): string
    {
        $key = 'nvb_site_'.SiteConnection::base64url(random_bytes(32));
        $keepPrevious = $usedKey !== '' && $site->usesPreviousKey($usedKey);
        $site->forceFill([
            'key_hash' => MarketplaceSite::hashKey($key),
            'key_created_at' => now(),
            'previous_key_hash' => $keepPrevious ? $site->previous_key_hash : ($usedKey !== '' ? MarketplaceSite::hashKey($usedKey) : null),
            'previous_key_expires_at' => $keepPrevious ? $site->previous_key_expires_at : ($usedKey !== '' ? now()->addDay() : null),
        ])->save();

        return $key;
    }

    /**
     * Sites not seen for 60 days, and waiting sites staff did not approve within 14 days of
     * starting to wait, end. Cloudflare rules that could not be removed before are tried again.
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
                ->orWhere(fn ($query) => $query->where('status', MarketplaceSite::STATUS_PENDING)->where(fn ($query) => $query
                    ->where('pending_since', '<', now()->subDays(14))
                    ->orWhere(fn ($query) => $query->whereNull('pending_since')->where('updated_at', '<', now()->subDays(14))))))
            ->get();

        foreach ($stale as $site) {
            $this->end($site, MarketplaceSite::STATUS_EXPIRED);
            $count++;
        }

        $this->retryRemovals();

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
     * Remove the Cloudflare rules that could not be removed before. A rule an active site uses
     * again is kept.
     *
     * @return int How many are still waiting.
     */
    public function retryRemovals(int $limit = 50): int
    {
        if (! $this->cloudflare->isConfigured()) {
            return $this->pendingRemovals();
        }

        foreach (DB::table(self::REMOVALS)->orderBy('id')->limit($limit)->get() as $row) {
            $inUse = MarketplaceSite::query()->where('cloudflare_rule_id', $row->rule_id)->exists()
                || MarketplaceSite::query()->where('ip', $row->ip)->where('status', MarketplaceSite::STATUS_ACTIVE)->exists();

            if ($inUse || $this->cloudflare->remove((string) $row->rule_id)) {
                DB::table(self::REMOVALS)->where('id', $row->id)->delete();
            } else {
                DB::table(self::REMOVALS)->where('id', $row->id)->update(['attempts' => min(65535, (int) $row->attempts + 1), 'updated_at' => now()]);
            }
        }

        return $this->pendingRemovals();
    }

    /**
     * Cloudflare rules the store still has to remove.
     */
    public function pendingRemovals(): int
    {
        return DB::table(self::REMOVALS)->count();
    }

    /**
     * Cloudflare rules the store made and still knows about: rules of sites, and rules it still
     * has to remove. While there are any, the zone may not be changed or cleared.
     */
    public function hasRules(): bool
    {
        return MarketplaceSite::query()->whereNotNull('cloudflare_rule_id')->exists() || $this->pendingRemovals() > 0;
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
     * The name a host was registered under, roughly: example.com for a.b.example.com, and
     * example.co.uk for a.example.co.uk. Limits count per registered name, so one domain with
     * many sub-domains cannot use up the store's limits.
     */
    public static function registrableDomain(string $host): string
    {
        $labels = explode('.', strtolower(trim($host, '.')));
        $count = count($labels);

        if ($count <= 2) {
            return implode('.', $labels);
        }

        $secondLevel = ['ac', 'co', 'com', 'edu', 'gob', 'go', 'gov', 'info', 'ltd', 'me', 'mil', 'ne', 'net', 'nic', 'or', 'org', 'plc', 'sch', 'web'];
        $take = strlen($labels[$count - 1]) === 2 && in_array($labels[$count - 2], $secondLevel, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    /**
     * Call the site at https://{host}/nuvabill-marketplace/connect. First straight to the address
     * the site said it uses, which also shows that the address is the site's own server. When that
     * does not work (a firewall, or a site behind Cloudflare), through the host's public DNS. The
     * site accepts the same code and key twice for a few minutes, so a lost answer can be asked again.
     *
     * "direct" is only true when the site's answer names the very address the call was pinned to.
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

                if ($answer !== null) {
                    return $answer + ['direct' => $answer['ip'] === $hint];
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

        return $answer + ['direct' => $answer['ip'] === $addresses[0]];
    }

    /**
     * @return array{ip: string, family: string, return_path: string}|null
     */
    private function readAnswer(Response $response): ?array
    {
        if (! $response->successful() || strlen($response->body()) > 65536 || $response->json('ok') !== true) {
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

    /**
     * Whether the site's own server answers at this address over HTTPS with the one-time code it
     * sent with its heartbeat (https://{host}/nuvabill-marketplace/verify).
     */
    private function reachesAt(string $host, string $ip, string $token): bool
    {
        try {
            $response = PublicAddress::pinned($host, $ip, maxBytes: 4096)->acceptJson()->asJson()->post("https://{$host}/nuvabill-marketplace/verify", ['token' => $token]);
        } catch (Throwable $exception) {
            Log::info('Marketplace heartbeat: the site could not be reached at its new address.', ['host' => $host, 'error' => class_basename($exception)]);

            return false;
        }

        return $response->successful() && $response->json('ok') === true && $response->json('ip') === $ip;
    }

    private function unreachable(string $host): string
    {
        return __(':host could not be reached, or did not accept the connection. Check that the site is online with a valid HTTPS certificate and runs Nuvabill 0.7.0 or newer, then click Connect on your Marketplace page again.', ['host' => $host]);
    }

    /**
     * Enough active sites use this address already: one more waits for staff.
     */
    private function crowded(string $ip, int $exceptId): bool
    {
        return MarketplaceSite::query()
            ->where('ip', $ip)
            ->where('status', MarketplaceSite::STATUS_ACTIVE)
            ->where('id', '!=', $exceptId)
            ->count() >= max(1, (int) setting('marketplace.connect_sites_per_ip', 5));
    }

    /**
     * Sites of one registered name (the name itself and its sub-domains) that still have a key.
     */
    private function hostsOf(string $domain): int
    {
        return MarketplaceSite::query()->usable()
            ->where(fn ($query) => $query->where('host', $domain)->orWhere('host', 'like', '%.'.$domain))
            ->count();
    }

    /**
     * A site that waits already keeps the time it started waiting.
     */
    private function pendingSince(MarketplaceSite $site): mixed
    {
        return $site->status === MarketplaceSite::STATUS_PENDING && $site->pending_since !== null ? $site->pending_since : now();
    }

    /**
     * A site's server now goes out from another address. The rule moves with it only when the
     * store reached the site's own server at the new address, and the address is not crowded;
     * otherwise the site waits for staff (or connects again).
     */
    private function moveTo(MarketplaceSite $site, string $ip, bool $reached): void
    {
        $oldIp = $site->ip;
        $oldRule = $site->cloudflare_rule_id;
        $crowded = $this->crowded($ip, $site->id);
        $wasRevoked = $site->revoked_at !== null;
        $active = $reached && ! $crowded && ! $wasRevoked;

        $site->forceFill([
            'ip' => $ip,
            'ip_family' => (string) PublicAddress::family($ip),
            'ip_matches_dns' => in_array($ip, PublicAddress::publicAddresses($site->host), true),
            'ip_reached_directly' => $reached,
            'status' => $active ? MarketplaceSite::STATUS_ACTIVE : MarketplaceSite::STATUS_PENDING,
            'pending_since' => $active ? null : $this->pendingSince($site),
            'pending_reason' => match (true) {
                $active => null,
                $wasRevoked => MarketplaceSite::REASON_REVOKED,
                $crowded => MarketplaceSite::REASON_CROWDED,
                default => MarketplaceSite::REASON_IP_CHANGED.':'.$oldIp,
            },
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

    /**
     * The site's server goes out from an address that may not be let through: the site waits, and
     * its old address is no longer let through.
     */
    private function hold(MarketplaceSite $site, string $reason): void
    {
        $rule = $site->cloudflare_rule_id;

        $site->forceFill([
            'status' => MarketplaceSite::STATUS_PENDING,
            'pending_since' => $this->pendingSince($site),
            'pending_reason' => $reason,
            'cloudflare_rule_id' => null,
        ])->save();

        if ($rule !== null) {
            $this->releaseRule($site, $site->ip, $rule);
        }

        Activity::log('marketplace.site_moved', "Site {$site->host} now goes out from an address that cannot be let through and waits for approval", $site);
    }

    private function end(MarketplaceSite $site, string $status): void
    {
        $rule = $site->cloudflare_rule_id;
        $site->forceFill(array_merge(
            ['status' => $status, 'cloudflare_rule_id' => null, 'pending_since' => null],
            $status === MarketplaceSite::STATUS_REVOKED ? ['revoked_at' => now(), 'pending_reason' => null] : [],
        ))->save();

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
            // The rule is used again, so it is no longer waiting to be removed.
            DB::table(self::REMOVALS)->where('rule_id', $rule)->delete();
        }
    }

    /**
     * A site no longer needs the rule for an address. The rule only goes when no other active
     * site uses that address; otherwise it stays with them. A rule Cloudflare did not remove is
     * noted and tried again later, so an address is never let through without the store knowing.
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

        if (! $this->cloudflare->remove($rule)) {
            DB::table(self::REMOVALS)->insertOrIgnore(['rule_id' => $rule, 'ip' => $ip, 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);
            Log::warning('A Cloudflare rule of a marketplace site could not be removed; it is tried again daily.', ['ip' => $ip]);
        }
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
