<?php

namespace Tests\Feature\Marketplace;

use App\Marketplace\MarketplaceClient;
use App\Marketplace\SiteConnection;
use App\Marketplace\Store\ConnectedSites;
use App\Models\Admin;
use App\Models\MarketplaceSite;
use App\Models\Setting;
use App\Providers\MarketplaceStoreServiceProvider;
use App\Security\PublicAddress;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectToMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 'https://store.example.test';

    private const SITE = 'billing.hostco.net';

    private const SERVER_IP = '45.33.10.20';

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.marketplace.url' => self::STORE, 'app.url' => 'https://'.self::SITE]);
    }

    protected function tearDown(): void
    {
        PublicAddress::$resolveUsing = null;

        parent::tearDown();
    }

    // ---- The Nuvabill site that connects ----

    public function test_staff_connect_and_the_store_call_back_saves_an_encrypted_key_once(): void
    {
        $this->signInAdmin();
        Http::fake([self::STORE.'/cdn-cgi/trace' => Http::response("fl=1\nip=".self::SERVER_IP."\nts=1\n")]);

        $response = $this->post(route('admin.marketplace.connect'));
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::STORE.'/marketplace/connect?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(self::SITE, $query['site']);
        $this->assertSame(self::SERVER_IP, $query['ip']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['state']);

        $key = 'nvb_site_'.str_repeat('a', 43);

        // A wrong code is refused without saying why.
        $this->postJson('/nuvabill-marketplace/connect', ['state' => str_repeat('b', 43), 'key' => $key, 'site_id' => 7])->assertNotFound()->assertExactJson([]);

        $this->postJson('/nuvabill-marketplace/connect', ['state' => $query['state'], 'key' => $key, 'site_id' => 7])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'ip' => self::SERVER_IP, 'family' => 'ipv4', 'return_path' => route('admin.marketplace.index', [], false)]);

        $this->assertSame($key, setting('marketplace.site_key'));
        $this->assertStringNotContainsString($key, (string) Setting::query()->where('key', 'marketplace.site_key')->value('value'), 'The key is encrypted at rest.');
        $this->assertSame(SiteConnection::WAITING, app(SiteConnection::class)->status());

        // The code works once.
        $this->postJson('/nuvabill-marketplace/connect', ['state' => $query['state'], 'key' => 'nvb_site_'.str_repeat('c', 43), 'site_id' => 7])->assertNotFound();
        $this->assertSame($key, setting('marketplace.site_key'));
    }

    public function test_a_site_without_a_public_web_address_cannot_start_connecting(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->signInAdmin();

        $this->from(route('admin.marketplace.index'))->post(route('admin.marketplace.connect'))->assertRedirect(route('admin.marketplace.index'))->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_a_connected_site_sends_its_key_and_family_only_to_the_store(): void
    {
        $this->connectLocally();
        Http::fake([self::STORE.'/api/marketplace/v1/catalog*' => Http::response(['items' => []])]);
        $seen = null;
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response(['items' => []]);
        });

        app(MarketplaceClient::class)->catalog(fresh: true);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$this->key()));
        $this->assertSame('v4', $seen['force_ip_resolve'] ?? null);

        $client = app(MarketplaceClient::class);
        $this->assertFalse($client->authorize(Http::withOptions([]), 'https://evil.example.net/file.zip')->getOptions()['headers']['Authorization'] ?? false);
        $this->assertSame('Bearer '.$this->key(), $client->authorize(Http::withOptions([]), self::STORE.'/api/marketplace/v1/files/1/0?signature=x')->getOptions()['headers']['Authorization'] ?? null);
    }

    public function test_a_site_that_is_not_connected_calls_the_store_as_before(): void
    {
        Http::fake([self::STORE.'/api/marketplace/v1/catalog*' => Http::response(['items' => []])]);

        app(MarketplaceClient::class)->catalog(fresh: true);

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function test_a_cloudflare_challenge_tells_staff_to_connect(): void
    {
        $this->signInAdmin();
        Http::fake([self::STORE.'/api/marketplace/v1/catalog*' => Http::response('<html><title>Just a moment...</title></html>', 403, ['Content-Type' => 'text/html; charset=UTF-8', 'cf-mitigated' => 'challenge'])]);

        $this->get(route('admin.marketplace.index'))
            ->assertOk()
            ->assertSee('Cloudflare stopped this server from reaching the marketplace.')
            ->assertSee('Connect to marketplace');
    }

    public function test_a_key_the_store_no_longer_knows_asks_staff_to_connect_again(): void
    {
        $this->connectLocally();
        Http::fake(fn (Request $request) => $request->hasHeader('Authorization')
            ? Http::response(['message' => 'No longer connected', 'code' => 'reconnect'], 401)
            : Http::response(['items' => []]));

        $this->assertSame([], app(MarketplaceClient::class)->catalog(fresh: true));
        $this->assertSame(SiteConnection::RECONNECT, app(SiteConnection::class)->status());
        Http::assertSentCount(2);
    }

    public function test_the_heartbeat_renews_an_old_key_and_notices_a_new_server_address(): void
    {
        $this->connectLocally();
        $newKey = 'nvb_site_'.str_repeat('n', 43);
        $ip = self::SERVER_IP;
        Http::fake([
            self::STORE.'/cdn-cgi/trace' => function () use (&$ip) {
                return Http::response('ip='.$ip."\n");
            },
            self::STORE.'/api/marketplace/v1/sites/heartbeat' => function () use (&$ip) {
                return $ip === self::SERVER_IP
                    ? Http::response(['status' => 'active', 'rotate' => true, 'ip' => self::SERVER_IP])
                    : Http::response('<html>Just a moment...</html>', 403, ['Content-Type' => 'text/html']);
            },
            self::STORE.'/api/marketplace/v1/sites/rotate' => Http::response(['key' => $newKey]),
        ]);

        Artisan::call('nuvabill:marketplace-heartbeat');

        $this->assertSame($newKey, setting('marketplace.site_key'));
        $this->assertSame(SiteConnection::ACTIVE, app(SiteConnection::class)->status());

        // From a new address Cloudflare stops the call: staff are told to connect again.
        $ip = '45.33.10.99';

        Artisan::call('nuvabill:marketplace-heartbeat');

        $this->assertSame(SiteConnection::IP_CHANGED, app(SiteConnection::class)->status());
    }

    // ---- The marketplace store ----

    public function test_a_site_whose_dns_points_to_its_server_is_let_through_cloudflare(): void
    {
        $this->enableStore();
        PublicAddress::$resolveUsing = fn (string $host): array => $host === self::SITE ? [self::SERVER_IP] : [];
        $sent = null;
        Http::fake([
            'https://'.self::SITE.'/nuvabill-marketplace/connect' => function (Request $request) use (&$sent) {
                $sent = $request->data();

                return Http::response(['ok' => true, 'ip' => self::SERVER_IP, 'family' => 'ipv4', 'return_path' => '/admin/marketplace']);
            },
            'https://api.cloudflare.com/client/v4/zones/*/firewall/access_rules/rules' => Http::response(['success' => true, 'result' => ['id' => 'rule123']]),
        ]);

        $state = str_repeat('s', 43);
        $this->get(route('marketplace.connect', ['site' => self::SITE, 'state' => $state]))->assertOk()->assertSee('Connect '.self::SITE.' to the Nuvabill marketplace?');

        $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => $state, 'ip' => self::SERVER_IP])
            ->assertRedirect('https://'.self::SITE.'/admin/marketplace?connected=1');

        $site = MarketplaceSite::query()->sole();
        $this->assertSame(MarketplaceSite::STATUS_ACTIVE, $site->status);
        $this->assertTrue($site->ip_matches_dns);
        $this->assertTrue($site->ip_reached_directly);
        $this->assertSame('rule123', $site->cloudflare_rule_id);
        $this->assertSame($state, $sent['state']);
        $this->assertSame($site->id, $sent['site_id']);
        $this->assertSame(hash('sha256', $sent['key']), $site->key_hash, 'Only the hash of the key is kept.');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'access_rules')
            && $request['mode'] === 'whitelist'
            && $request['configuration'] === ['target' => 'ip', 'value' => self::SERVER_IP]
            && $request->hasHeader('Authorization', 'Bearer cf-token'));
    }

    public function test_an_address_that_is_not_the_sites_own_waits_for_staff(): void
    {
        $admin = $this->enableStore();
        PublicAddress::$resolveUsing = fn (string $host): array => [self::SERVER_IP];
        Http::fake([
            'https://'.self::SITE.'/nuvabill-marketplace/connect' => Http::response(['ok' => true, 'ip' => '45.33.99.1', 'family' => 'ipv4', 'return_path' => '/admin/marketplace']),
            'https://api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'rule9']]),
        ]);

        $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => str_repeat('s', 43)])->assertRedirect();

        $site = MarketplaceSite::query()->sole();
        $this->assertSame(MarketplaceSite::STATUS_PENDING, $site->status);
        $this->assertNull($site->cloudflare_rule_id);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'cloudflare'));

        $this->actingAs($admin, 'admin')->get(route('admin.store.sites.index'))->assertOk()->assertSee(self::SITE)->assertSee('Waiting');
        $this->post(route('admin.store.sites.approve', $site))->assertRedirect();

        $this->assertSame(MarketplaceSite::STATUS_ACTIVE, $site->refresh()->status);
        $this->assertSame('rule9', $site->cloudflare_rule_id);
    }

    public function test_a_site_that_cannot_be_reached_or_answers_wrong_is_not_kept(): void
    {
        $this->enableStore();
        PublicAddress::$resolveUsing = fn (string $host): array => [self::SERVER_IP];
        Http::fake(['https://'.self::SITE.'/*' => Http::response([], 404)]);

        $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => str_repeat('s', 43)])->assertSessionHas('error');
        $this->assertSame(0, MarketplaceSite::query()->count());

        // A Cloudflare address or a link to another site is refused too.
        foreach ([['ip' => '104.16.1.1', 'return_path' => '/admin'], ['ip' => self::SERVER_IP, 'return_path' => '//evil.example.net/x']] as $answer) {
            Http::fake(['https://'.self::SITE.'/*' => Http::response(['ok' => true, 'family' => 'ipv4'] + $answer)]);
            $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => str_repeat('s', 43)])->assertSessionHas('error');
            $this->assertSame(0, MarketplaceSite::query()->count());
        }

        // A site name that points to the local network is never called.
        PublicAddress::$resolveUsing = fn (string $host): array => ['10.0.0.5'];
        Http::fake();
        $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => str_repeat('s', 43)])->assertSessionHas('error');
        Http::assertNothingSent();

        // Test names and addresses are not sites.
        $this->get(route('marketplace.connect', ['site' => 'billing.test', 'state' => str_repeat('s', 43)]))->assertNotFound();
        $this->get(route('marketplace.connect', ['site' => '45.33.10.20', 'state' => str_repeat('s', 43)]))->assertNotFound();
    }

    public function test_the_api_accepts_known_keys_turns_away_removed_ones_and_works_without_a_key(): void
    {
        $this->enableStore();
        $key = 'nvb_site_'.str_repeat('k', 43);
        $site = $this->storeSite($key);

        $this->getJson(route('marketplace.api.catalog'))->assertOk();
        $this->withToken($key)->getJson(route('marketplace.api.catalog'))->assertOk();
        $this->assertNotNull($site->refresh()->last_seen_at);

        $this->withToken($key)->postJson(route('marketplace.api.sites.heartbeat'), ['ip' => self::SERVER_IP])
            ->assertOk()->assertJson(['status' => 'active', 'rotate' => false, 'ip' => self::SERVER_IP]);

        $new = $this->withToken($key)->postJson(route('marketplace.api.sites.rotate'))->assertOk()->json('key');
        $this->assertMatchesRegularExpression('/^nvb_site_[A-Za-z0-9_-]{43}$/', $new);
        $this->withToken($key)->getJson(route('marketplace.api.catalog'))->assertUnauthorized()->assertJson(['code' => 'reconnect']);
        $this->withToken($new)->getJson(route('marketplace.api.catalog'))->assertOk();

        $this->flushHeaders()->postJson(route('marketplace.api.sites.heartbeat'), ['ip' => self::SERVER_IP])->assertUnauthorized();

        app(ConnectedSites::class)->revoke($site->refresh());
        $this->withToken($new)->getJson(route('marketplace.api.catalog'))->assertUnauthorized()->assertJson(['code' => 'reconnect']);
    }

    public function test_one_rule_serves_every_site_on_an_address_and_goes_with_the_last_one(): void
    {
        $this->enableStore();
        Http::fake(['https://api.cloudflare.com/*' => Http::response(['success' => true])]);
        $first = $this->storeSite('nvb_site_'.str_repeat('a', 43), 'one.hostco.net');
        $second = $this->storeSite('nvb_site_'.str_repeat('b', 43), 'two.hostco.net');
        $sites = app(ConnectedSites::class);

        $sites->revoke($first);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');

        $sites->revoke($second);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/rules/rule1'));
    }

    public function test_a_rule_that_exists_already_is_found_and_used(): void
    {
        $this->enableStore();
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/*/firewall/access_rules/rules?*' => Http::response(['success' => true, 'result' => [['id' => 'old1', 'mode' => 'whitelist', 'notes' => 'Nuvabill site other.hostco.net #3', 'configuration' => ['target' => 'ip', 'value' => self::SERVER_IP]]]]),
            'https://api.cloudflare.com/client/v4/zones/*/firewall/access_rules/rules' => Http::response(['success' => false, 'errors' => [['code' => 10009, 'message' => 'firewallaccessrules.api.duplicate_of_existing']]], 400),
        ]);

        $site = $this->storeSite('nvb_site_'.str_repeat('a', 43), rule: null);
        app(ConnectedSites::class)->syncRules();

        $this->assertSame('old1', $site->refresh()->cloudflare_rule_id);
    }

    public function test_quiet_sites_and_old_requests_end_and_lose_their_rule(): void
    {
        $this->enableStore();
        Http::fake(['https://api.cloudflare.com/*' => Http::response(['success' => true])]);
        $quiet = $this->storeSite('nvb_site_'.str_repeat('a', 43));
        $quiet->forceFill(['last_seen_at' => now()->subDays(61)])->save();
        $waiting = $this->storeSite('nvb_site_'.str_repeat('b', 43), 'two.hostco.net', status: MarketplaceSite::STATUS_PENDING, rule: null);
        $waiting->forceFill(['key_created_at' => now()->subDays(15)])->save();
        $fresh = $this->storeSite('nvb_site_'.str_repeat('c', 43), 'three.hostco.net', ip: '45.33.10.30', rule: 'rule3');

        Artisan::call('nuvabill:marketplace-sites-prune');

        $this->assertSame(MarketplaceSite::STATUS_EXPIRED, $quiet->refresh()->status);
        $this->assertNull($quiet->cloudflare_rule_id);
        $this->assertSame(MarketplaceSite::STATUS_EXPIRED, $waiting->refresh()->status);
        $this->assertSame(MarketplaceSite::STATUS_ACTIVE, $fresh->refresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/rules/rule1'));
    }

    public function test_staff_save_and_test_the_cloudflare_token_which_is_never_shown_again(): void
    {
        $admin = $this->enableStore(cloudflare: false);
        $this->actingAs($admin, 'admin');
        Http::fake(['https://api.cloudflare.com/client/v4/zones/*' => Http::response(['success' => true, 'result' => ['id' => str_repeat('a', 32)]])]);

        $this->get(route('admin.store.sites.index'))->assertOk()->assertSee('Cloudflare is not set up');

        $this->put(route('admin.store.sites.cloudflare'), ['zone_id' => str_repeat('a', 32), 'api_token' => 'secret-token', 'action' => 'test'])->assertSessionHas('status');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/zones/'.str_repeat('a', 32) && $request->hasHeader('Authorization', 'Bearer secret-token'));

        $this->put(route('admin.store.sites.cloudflare'), ['zone_id' => str_repeat('a', 32), 'api_token' => 'secret-token', 'action' => 'save'])->assertSessionHas('status');
        $this->assertSame('secret-token', setting('marketplace.cloudflare_token'));
        $this->assertStringNotContainsString('secret-token', (string) Setting::query()->where('key', 'marketplace.cloudflare_token')->value('value'));
        $this->get(route('admin.store.sites.index'))->assertOk()->assertDontSee('secret-token')->assertSee('Saved. Leave empty to keep it.');

        // An empty token field keeps the saved one.
        $this->put(route('admin.store.sites.cloudflare'), ['zone_id' => str_repeat('a', 32), 'api_token' => '', 'action' => 'save']);
        $this->assertSame('secret-token', setting('marketplace.cloudflare_token'));
    }

    private function connectLocally(): void
    {
        app(Settings::class)->setMany([
            'marketplace.site_key' => $this->key(),
            'marketplace.site_id' => 7,
            'marketplace.site_ip' => self::SERVER_IP,
            'marketplace.site_family' => 'ipv4',
            'marketplace.site_status' => SiteConnection::ACTIVE,
            'marketplace.site_connected_at' => now()->toIso8601String(),
        ]);
        Cache::flush();
    }

    private function key(): string
    {
        return 'nvb_site_'.str_repeat('a', 43);
    }

    private function enableStore(bool $cloudflare = true): Admin
    {
        config(['nuvabill.marketplace.store' => true]);
        (new MarketplaceStoreServiceProvider($this->app))->enable();

        if ($cloudflare) {
            app(Settings::class)->setMany(['marketplace.cloudflare_zone_id' => str_repeat('f', 32), 'marketplace.cloudflare_token' => 'cf-token']);
        }

        return $this->signInAdmin();
    }

    private function storeSite(string $key, string $host = 'one.hostco.net', string $ip = self::SERVER_IP, string $status = MarketplaceSite::STATUS_ACTIVE, ?string $rule = 'rule1'): MarketplaceSite
    {
        return MarketplaceSite::query()->create([
            'host' => $host,
            'ip' => $ip,
            'ip_family' => 'ipv4',
            'key_hash' => MarketplaceSite::hashKey($key),
            'key_created_at' => now(),
            'status' => $status,
            'cloudflare_rule_id' => $rule,
            'ip_matches_dns' => true,
            'last_seen_at' => now(),
        ]);
    }
}
