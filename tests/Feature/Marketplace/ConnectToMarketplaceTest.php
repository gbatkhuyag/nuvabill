<?php

namespace Tests\Feature\Marketplace;

use App\Health\Checks\ExtensionChecks;
use App\Marketplace\MarketplaceClient;
use App\Marketplace\PackageInstaller;
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
use RuntimeException;
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

        // The answer may never reach the site: its old key keeps working, and it is asked to change again.
        $this->withToken($key)->postJson(route('marketplace.api.sites.heartbeat'), ['ip' => self::SERVER_IP])->assertOk()->assertJson(['rotate' => true]);

        // Once the site uses the new key, the old one stops.
        $this->withToken($new)->getJson(route('marketplace.api.catalog'))->assertOk();
        $this->withToken($key)->getJson(route('marketplace.api.catalog'))->assertUnauthorized()->assertJson(['code' => 'reconnect']);

        // Without it, the old key stops after a day.
        $newer = $this->withToken($new)->postJson(route('marketplace.api.sites.rotate'))->assertOk()->json('key');
        $this->travel(25)->hours();
        $this->withToken($new)->getJson(route('marketplace.api.catalog'))->assertUnauthorized();
        $new = $newer;
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
        $waiting->forceFill(['pending_since' => now()->subDays(15)])->save();
        // An old site that only started to wait yesterday gets its 14 days.
        $moved = $this->storeSite('nvb_site_'.str_repeat('d', 43), 'four.hostco.net', ip: '45.33.10.40', status: MarketplaceSite::STATUS_PENDING, rule: null);
        $moved->forceFill(['key_created_at' => now()->subDays(80), 'pending_since' => now()->subDay()])->save();
        $fresh = $this->storeSite('nvb_site_'.str_repeat('c', 43), 'three.hostco.net', ip: '45.33.10.30', rule: 'rule3');

        Artisan::call('nuvabill:marketplace-sites-prune');

        $this->assertSame(MarketplaceSite::STATUS_EXPIRED, $quiet->refresh()->status);
        $this->assertNull($quiet->cloudflare_rule_id);
        $this->assertSame(MarketplaceSite::STATUS_EXPIRED, $waiting->refresh()->status);
        $this->assertSame(MarketplaceSite::STATUS_ACTIVE, $fresh->refresh()->status);
        $this->assertSame(MarketplaceSite::STATUS_PENDING, $moved->refresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/rules/rule1'));
    }

    public function test_staff_save_and_test_the_cloudflare_token_which_is_never_shown_again(): void
    {
        $admin = $this->enableStore(cloudflare: false);
        $this->actingAs($admin, 'admin');
        Http::fake(['https://api.cloudflare.com/client/v4/zones/*' => Http::response(['success' => true, 'result' => []])]);

        $this->get(route('admin.store.sites.index'))->assertOk()->assertSee('Cloudflare is not set up');

        $this->put(route('admin.store.sites.cloudflare'), ['zone_id' => str_repeat('a', 32), 'api_token' => 'secret-token', 'action' => 'test'])->assertSessionHas('status');
        // The test reads the IP Access rules, the permission the store needs, not only the zone.
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.cloudflare.com/client/v4/zones/'.str_repeat('a', 32).'/firewall/access_rules/rules?') && $request->hasHeader('Authorization', 'Bearer secret-token'));

        $this->put(route('admin.store.sites.cloudflare'), ['zone_id' => str_repeat('a', 32), 'api_token' => 'secret-token', 'action' => 'save'])->assertSessionHas('status');
        $this->assertSame('secret-token', setting('marketplace.cloudflare_token'));
        $this->assertStringNotContainsString('secret-token', (string) Setting::query()->where('key', 'marketplace.cloudflare_token')->value('value'));
        $this->get(route('admin.store.sites.index'))->assertOk()->assertDontSee('secret-token')->assertSee('Saved. Leave empty to keep it.');

        // An empty token field keeps the saved one.
        $this->put(route('admin.store.sites.cloudflare'), ['zone_id' => str_repeat('a', 32), 'api_token' => '', 'action' => 'save']);
        $this->assertSame('secret-token', setting('marketplace.cloudflare_token'));
    }

    // ---- Checks added after the review ----

    public function test_a_site_behind_cloudflare_is_let_through_when_the_store_reaches_its_server_directly(): void
    {
        $this->enableStore();
        PublicAddress::$resolveUsing = fn (string $host): array => ['104.16.1.1'];
        $pinned = [];
        Http::fake([
            'https://'.self::SITE.'/nuvabill-marketplace/connect' => function (Request $request, array $options) use (&$pinned) {
                $pinned[] = $options['curl'][CURLOPT_RESOLVE][0] ?? null;

                return Http::response(['ok' => true, 'ip' => self::SERVER_IP, 'family' => 'ipv4', 'return_path' => '/admin/marketplace']);
            },
            'https://api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'rule7']]),
        ]);

        $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => str_repeat('s', 43), 'ip' => self::SERVER_IP])->assertRedirect();

        $site = MarketplaceSite::query()->sole();
        $this->assertSame([self::SITE.':443:'.self::SERVER_IP], $pinned, 'The call went to the address the site named, and only there.');
        $this->assertSame(MarketplaceSite::STATUS_ACTIVE, $site->status);
        $this->assertTrue($site->ip_reached_directly);
        $this->assertFalse($site->ip_matches_dns);
        $this->assertSame('rule7', $site->cloudflare_rule_id);
    }

    public function test_an_address_the_store_did_not_call_is_never_let_through_even_when_it_is_in_dns(): void
    {
        $this->enableStore();
        // The site's own server answers, and names another address that its DNS also lists.
        PublicAddress::$resolveUsing = fn (string $host): array => [self::SERVER_IP, '45.33.10.77'];
        Http::fake([
            'https://'.self::SITE.'/nuvabill-marketplace/connect' => Http::response(['ok' => true, 'ip' => '45.33.10.77', 'family' => 'ipv4', 'return_path' => '/admin/marketplace']),
            'https://api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'rule9']]),
        ]);

        // Through DNS, and with the store calling the address the browser named.
        foreach ([null, self::SERVER_IP] as $hint) {
            $this->post(route('marketplace.connect.store'), array_filter(['site' => self::SITE, 'state' => str_repeat('s', 43), 'ip' => $hint]))->assertRedirect();

            $site = MarketplaceSite::query()->sole();
            $this->assertSame(MarketplaceSite::STATUS_PENDING, $site->status);
            $this->assertTrue($site->ip_matches_dns);
            $this->assertFalse($site->ip_reached_directly);
            $this->assertSame('The store could not reach the site at this address.', $site->reasonText());
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'cloudflare'));
    }

    public function test_cloudflare_and_private_addresses_are_never_called_or_accepted(): void
    {
        $this->enableStore();
        PublicAddress::$resolveUsing = fn (string $host): array => [self::SERVER_IP];
        $pinned = [];
        $answerIp = self::SERVER_IP;
        Http::fake(['https://'.self::SITE.'/nuvabill-marketplace/connect' => function (Request $request, array $options) use (&$pinned, &$answerIp) {
            $pinned[] = $options['curl'][CURLOPT_RESOLVE][0] ?? null;

            return Http::response(['ok' => true, 'ip' => $answerIp, 'family' => 'ipv4', 'return_path' => '/admin/marketplace']);
        }]);

        foreach (['104.16.5.5', '10.0.0.9', '169.254.169.254'] as $hint) {
            $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => str_repeat('s', 43), 'ip' => $hint])->assertRedirect();
        }

        $this->assertSame(array_fill(0, 3, self::SITE.':443:'.self::SERVER_IP), $pinned, 'Only the public DNS address is called.');

        // A site that names a private address is refused.
        MarketplaceSite::query()->delete();
        $answerIp = '10.0.0.9';
        $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => str_repeat('s', 43)])->assertSessionHas('error');
        $this->assertSame(0, MarketplaceSite::query()->count());
    }

    public function test_starting_to_connect_someone_elses_site_changes_nothing_for_it(): void
    {
        $this->enableStore();
        $key = 'nvb_site_'.str_repeat('k', 43);
        $site = $this->storeSite($key, self::SITE);
        PublicAddress::$resolveUsing = fn (string $host): array => [self::SERVER_IP];
        $usableMeanwhile = null;
        Http::fake(['https://'.self::SITE.'/*' => function () use ($key, &$usableMeanwhile) {
            $usableMeanwhile = MarketplaceSite::findByKey($key) !== null;

            return Http::response([], 404);
        }]);

        $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => str_repeat('x', 43), 'ip' => '45.33.10.99'])->assertSessionHas('error');

        $this->assertTrue($usableMeanwhile, 'The site\'s key keeps working while the store calls the site.');
        $this->assertSame(MarketplaceSite::hashKey($key), $site->refresh()->key_hash);
        $this->assertSame(MarketplaceSite::STATUS_ACTIVE, $site->status);
        $this->assertSame('rule1', $site->cloudflare_rule_id);
        $this->withToken($key)->getJson(route('marketplace.api.catalog'))->assertOk();
    }

    public function test_a_removed_site_waits_for_staff_however_often_it_connects_again(): void
    {
        $admin = $this->enableStore();
        PublicAddress::$resolveUsing = fn (string $host): array => [self::SERVER_IP];
        Http::fake([
            'https://'.self::SITE.'/nuvabill-marketplace/connect' => Http::response(['ok' => true, 'ip' => self::SERVER_IP, 'family' => 'ipv4', 'return_path' => '/admin/marketplace']),
            'https://api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'rule5']]),
        ]);
        $site = $this->storeSite('nvb_site_'.str_repeat('k', 43), self::SITE);

        // Staff remove it on the page.
        $this->actingAs($admin, 'admin')->post(route('admin.store.sites.revoke', $site))->assertRedirect();
        $this->assertSame(MarketplaceSite::STATUS_REVOKED, $site->refresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/rules/rule1'));

        foreach ([1, 2] as $attempt) {
            $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => str_repeat('s', 43), 'ip' => self::SERVER_IP])->assertRedirect();
            $this->assertSame(MarketplaceSite::STATUS_PENDING, $site->refresh()->status, "Attempt {$attempt}");
            $this->assertSame(MarketplaceSite::REASON_REVOKED, $site->pending_reason);
            $this->assertNull($site->cloudflare_rule_id);
        }

        $this->get(route('admin.store.sites.index'))->assertOk()->assertSee('Removed by staff before.');
        $this->post(route('admin.store.sites.approve', $site))->assertRedirect();
        $this->assertSame(MarketplaceSite::STATUS_ACTIVE, $site->refresh()->status);
        $this->assertNull($site->revoked_at);
    }

    public function test_store_staff_without_the_review_permission_cannot_see_or_change_sites(): void
    {
        $this->enableStore();
        $site = $this->storeSite('nvb_site_'.str_repeat('k', 43));
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view'])->create());

        $this->get(route('admin.store.sites.index'))->assertForbidden();
        $this->post(route('admin.store.sites.revoke', $site))->assertForbidden();
        $this->assertSame(MarketplaceSite::STATUS_ACTIVE, $site->refresh()->status);
    }

    public function test_the_limits_per_address_and_per_registered_name_apply(): void
    {
        $admin = $this->enableStore();
        PublicAddress::$resolveUsing = fn (string $host): array => [self::SERVER_IP];
        Http::fake([
            '*/nuvabill-marketplace/connect' => Http::response(['ok' => true, 'ip' => self::SERVER_IP, 'family' => 'ipv4', 'return_path' => '/admin/marketplace']),
            'https://api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'rule5']]),
        ]);

        $this->actingAs($admin, 'admin')->put(route('admin.store.sites.limits'), ['daily_limit' => 200, 'domain_daily_limit' => 2, 'hosts_per_domain' => 3, 'sites_per_ip' => 1])->assertSessionHas('status');
        $this->get(route('admin.store.sites.index'))->assertOk()->assertSee('Limits');

        $this->storeSite('nvb_site_'.str_repeat('k', 43), 'other.example.org');

        // One active site uses the address already.
        $this->post(route('marketplace.connect.store'), ['site' => 'a.hostco.net', 'state' => str_repeat('s', 43), 'ip' => self::SERVER_IP])->assertRedirect();
        $site = MarketplaceSite::query()->where('host', 'a.hostco.net')->sole();
        $this->assertSame(MarketplaceSite::STATUS_PENDING, $site->status);
        $this->assertSame(MarketplaceSite::REASON_CROWDED, $site->pending_reason);

        // Two a day for one registered name, whatever the sub-domain.
        $this->post(route('marketplace.connect.store'), ['site' => 'b.hostco.net', 'state' => str_repeat('s', 43), 'ip' => self::SERVER_IP])->assertRedirect();
        $this->post(route('marketplace.connect.store'), ['site' => 'c.hostco.net', 'state' => str_repeat('s', 43), 'ip' => self::SERVER_IP])->assertSessionHas('error');
        $this->assertFalse(MarketplaceSite::query()->where('host', 'c.hostco.net')->exists());

        // At most three sites with a key per registered name.
        $this->travel(1)->days();
        $this->storeSite('nvb_site_'.str_repeat('m', 43), 'd.hostco.net', ip: '45.33.10.50');
        $this->post(route('marketplace.connect.store'), ['site' => 'e.hostco.net', 'state' => str_repeat('s', 43), 'ip' => self::SERVER_IP])->assertSessionHas('error');
        $this->assertFalse(MarketplaceSite::query()->where('host', 'e.hostco.net')->exists());

        // The total a day.
        app(Settings::class)->set('marketplace.connect_daily_limit', 1);
        $this->post(route('marketplace.connect.store'), ['site' => 'one.othername.net', 'state' => str_repeat('s', 43), 'ip' => self::SERVER_IP])->assertRedirect();
        $this->post(route('marketplace.connect.store'), ['site' => 'two.thirdname.net', 'state' => str_repeat('s', 43), 'ip' => self::SERVER_IP])->assertSessionHas('error');

        $this->assertSame('hostco.net', ConnectedSites::registrableDomain('a.b.hostco.net'));
        $this->assertSame('hostco.co.uk', ConnectedSites::registrableDomain('billing.hostco.co.uk'));
    }

    public function test_a_new_server_address_moves_the_rule_only_when_the_store_reaches_the_site_there(): void
    {
        $this->enableStore();
        $key = 'nvb_site_'.str_repeat('k', 43);
        $site = $this->storeSite($key);
        $newIp = '45.33.10.99';
        $pinned = null;
        Http::fake([
            'https://one.hostco.net/nuvabill-marketplace/verify' => function (Request $request, array $options) use (&$pinned, $newIp) {
                $pinned = $options['curl'][CURLOPT_RESOLVE][0] ?? null;

                return $request['token'] === str_repeat('t', 43) ? Http::response(['ok' => true, 'ip' => $newIp]) : Http::response([], 404);
            },
            'https://api.cloudflare.com/*' => fn (Request $request) => Http::response(['success' => true, 'result' => ['id' => 'rule2']]),
        ]);

        $this->withToken($key)->postJson(route('marketplace.api.sites.heartbeat'), ['ip' => $newIp, 'move_token' => str_repeat('t', 43)])
            ->assertOk()->assertJson(['status' => 'active', 'ip' => $newIp]);

        $this->assertSame('one.hostco.net:443:'.$newIp, $pinned);
        $this->assertSame('rule2', $site->refresh()->cloudflare_rule_id);
        $this->assertTrue($site->ip_reached_directly);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/rules/rule1'));

        // A wrong code, or none: the site waits, and its old address is no longer let through.
        $this->withToken($key)->postJson(route('marketplace.api.sites.heartbeat'), ['ip' => '45.33.10.123', 'move_token' => str_repeat('w', 43)])
            ->assertOk()->assertJson(['status' => 'pending', 'ip' => '45.33.10.123']);
        $this->assertSame(MarketplaceSite::STATUS_PENDING, $site->refresh()->status);
        $this->assertNull($site->cloudflare_rule_id);
        $this->assertSame('The server address changed from '.$newIp.'.', $site->reasonText());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/rules/rule2'));
    }

    public function test_a_move_to_a_crowded_or_private_address_waits_for_staff(): void
    {
        $this->enableStore();
        app(Settings::class)->set('marketplace.connect_sites_per_ip', 1);
        $key = 'nvb_site_'.str_repeat('k', 43);
        $site = $this->storeSite($key);
        $this->storeSite('nvb_site_'.str_repeat('o', 43), 'other.example.org', ip: '45.33.10.99', rule: 'rule9');
        Http::fake([
            'https://one.hostco.net/nuvabill-marketplace/verify' => Http::response(['ok' => true, 'ip' => '45.33.10.99']),
            'https://api.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->withToken($key)->postJson(route('marketplace.api.sites.heartbeat'), ['ip' => '45.33.10.99', 'move_token' => str_repeat('t', 43)])->assertOk()->assertJson(['status' => 'pending']);
        $this->assertSame(MarketplaceSite::REASON_CROWDED, $site->refresh()->pending_reason);
        $this->assertNull($site->cloudflare_rule_id);

        // A private address is never let through.
        $other = $this->storeSite('nvb_site_'.str_repeat('p', 43), 'three.hostco.net', ip: '45.33.10.60', rule: 'rule6');
        $this->withToken('nvb_site_'.str_repeat('p', 43))->postJson(route('marketplace.api.sites.heartbeat'), ['ip' => '10.0.0.9'])->assertOk()->assertJson(['status' => 'pending', 'ip' => '45.33.10.60']);
        $this->assertSame(MarketplaceSite::STATUS_PENDING, $other->refresh()->status);
        $this->assertNull($other->cloudflare_rule_id);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/rules/rule6'));
    }

    public function test_a_rule_cloudflare_did_not_remove_is_kept_and_removed_later(): void
    {
        $admin = $this->enableStore();
        $site = $this->storeSite('nvb_site_'.str_repeat('k', 43));
        $cloudflareUp = false;
        Http::fake(['https://api.cloudflare.com/*' => function () use (&$cloudflareUp) {
            return $cloudflareUp ? Http::response(['success' => true]) : Http::response(['success' => false], 503);
        }]);

        app(ConnectedSites::class)->revoke($site);
        $this->assertSame(1, app(ConnectedSites::class)->pendingRemovals());
        $this->actingAs($admin, 'admin')->get(route('admin.store.sites.index'))->assertOk()->assertSee('Cloudflare did not remove 1 rule');
        $this->assertSame('warning', collect(app(ExtensionChecks::class)->run())->firstWhere('id', 'extensions.connected_sites')->status->value);

        // The zone cannot change while the store has rules in it.
        $this->put(route('admin.store.sites.cloudflare'), ['zone_id' => str_repeat('b', 32), 'action' => 'save'])->assertSessionHas('error');
        $this->assertSame(str_repeat('f', 32), setting('marketplace.cloudflare_zone_id'));

        $cloudflareUp = true;
        Artisan::call('nuvabill:marketplace-sites-prune');

        $this->assertSame(0, app(ConnectedSites::class)->pendingRemovals());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/rules/rule1'));
    }

    public function test_the_store_can_ask_twice_with_the_same_code_and_key(): void
    {
        $this->signInAdmin();
        Http::fake([self::STORE.'/cdn-cgi/trace' => Http::response('ip='.self::SERVER_IP."\n")]);
        parse_str((string) parse_url((string) $this->post(route('admin.marketplace.connect'))->headers->get('Location'), PHP_URL_QUERY), $query);
        $key = 'nvb_site_'.str_repeat('a', 43);

        $this->postJson('/nuvabill-marketplace/connect', ['state' => $query['state'], 'key' => $key, 'site_id' => 7])->assertOk();
        // The first answer was lost: the same code and key work once more, another key does not.
        $this->postJson('/nuvabill-marketplace/connect', ['state' => $query['state'], 'key' => $key, 'site_id' => 7])->assertOk()->assertJson(['ip' => self::SERVER_IP]);
        $this->postJson('/nuvabill-marketplace/connect', ['state' => $query['state'], 'key' => 'nvb_site_'.str_repeat('c', 43), 'site_id' => 7])->assertNotFound();
        $this->assertSame($key, setting('marketplace.site_key'));
    }

    public function test_the_one_time_code_ends_after_half_an_hour(): void
    {
        $this->signInAdmin();
        Http::fake([self::STORE.'/cdn-cgi/trace' => Http::response('ip='.self::SERVER_IP."\n")]);
        parse_str((string) parse_url((string) $this->post(route('admin.marketplace.connect'))->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->travel(31)->minutes();

        $this->postJson('/nuvabill-marketplace/connect', ['state' => $query['state'], 'key' => 'nvb_site_'.str_repeat('a', 43), 'site_id' => 7])->assertNotFound();
        $this->assertNull(app(SiteConnection::class)->key());
    }

    public function test_connecting_is_turned_off_in_demo_mode(): void
    {
        config(['nuvabill.demo' => true]);
        $this->signInAdmin();
        Http::fake();

        $response = $this->from(route('admin.marketplace.index'))->post(route('admin.marketplace.connect'));

        $this->assertFalse(str_starts_with((string) $response->headers->get('Location'), self::STORE));
        Http::assertNothingSent();
    }

    public function test_the_site_proves_its_new_address_with_a_code_that_works_once(): void
    {
        $this->connectLocally();
        $token = null;
        Http::fake([
            self::STORE.'/cdn-cgi/trace' => Http::response("ip=45.33.10.99\n"),
            self::STORE.'/api/marketplace/v1/sites/heartbeat' => function (Request $request) use (&$token) {
                $token = $request['move_token'] ?? null;

                return Http::response(['status' => 'active', 'rotate' => false, 'ip' => '45.33.10.99']);
            },
        ]);

        Artisan::call('nuvabill:marketplace-heartbeat');

        $this->assertIsString($token);
        $this->assertSame('45.33.10.99', setting('marketplace.site_ip'));
        $this->assertSame(SiteConnection::ACTIVE, app(SiteConnection::class)->status());
        $this->postJson('/nuvabill-marketplace/verify', ['token' => str_repeat('z', 43)])->assertNotFound();
        $this->postJson('/nuvabill-marketplace/verify', ['token' => $token])->assertOk()->assertExactJson(['ok' => true, 'ip' => '45.33.10.99']);
        $this->postJson('/nuvabill-marketplace/verify', ['token' => $token])->assertNotFound();
    }

    public function test_a_heartbeat_the_store_no_longer_knows_asks_staff_to_connect_again(): void
    {
        $this->connectLocally();
        Http::fake([
            self::STORE.'/cdn-cgi/trace' => Http::response('ip='.self::SERVER_IP."\n"),
            self::STORE.'/api/marketplace/v1/sites/heartbeat' => Http::response(['message' => 'No longer connected', 'code' => 'reconnect'], 401),
        ]);

        Artisan::call('nuvabill:marketplace-heartbeat');

        $this->assertSame(SiteConnection::RECONNECT, app(SiteConnection::class)->status());
        $check = collect(app(ExtensionChecks::class)->run())->firstWhere('id', 'extensions.marketplace_connection');
        $this->assertSame('warning', $check->status->value);
    }

    public function test_the_package_download_sends_the_key_and_tells_staff_when_cloudflare_stops_it(): void
    {
        $this->connectLocally();
        config(['nuvabill.marketplace.public_key' => base64_encode(str_repeat('p', 32))]);
        Http::fake([self::STORE.'/api/marketplace/v1/files/*' => Http::response('<html>Just a moment...</html>', 403, ['Content-Type' => 'text/html', 'cf-mitigated' => 'challenge'])]);

        try {
            app(PackageInstaller::class)->install(['slug' => 'aurora', 'type' => 'theme', 'version' => '1.0.0', 'url' => self::STORE.'/api/marketplace/v1/files/1/0?signature=x', 'sha256' => str_repeat('0', 64), 'signature' => 'x']);
            $this->fail('The download should have been stopped.');
        } catch (RuntimeException $exception) {
            $this->assertSame(MarketplaceClient::blockedMessage(), $exception->getMessage());
        }

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/files/') && $request->hasHeader('Authorization', 'Bearer '.$this->key()));
        $this->assertTrue((bool) Cache::get(SiteConnection::BLOCKED_CACHE));
        $check = collect(app(ExtensionChecks::class)->run())->firstWhere('id', 'extensions.marketplace_connection');
        $this->assertSame('warning', $check->status->value);
    }

    public function test_answers_to_calls_the_store_makes_are_cut_off_past_their_size(): void
    {
        $options = PublicAddress::pinned(self::SITE, self::SERVER_IP, maxBytes: 1000)->getOptions();

        ($options['progress'])(0, 1000);
        $this->expectException(RuntimeException::class);
        ($options['progress'])(0, 1001);
    }

    // ---- Checks added after the final security review ----

    public function test_an_old_key_cannot_keep_itself_alive_by_asking_for_new_keys(): void
    {
        $this->enableStore();
        $old = 'nvb_site_'.str_repeat('k', 43);
        $site = $this->storeSite($old);

        $this->withToken($old)->postJson(route('marketplace.api.sites.rotate'))->assertOk();
        $expires = $site->refresh()->previous_key_expires_at;

        // Asking again with the previous key gives a new key, but no more time for the old one.
        $this->travel(12)->hours();
        $this->withToken($old)->postJson(route('marketplace.api.sites.rotate'))->assertOk();
        $this->travel(11)->hours();
        $latest = $this->withToken($old)->postJson(route('marketplace.api.sites.rotate'))->assertOk()->json('key');
        $this->assertSame(MarketplaceSite::hashKey($old), $site->refresh()->previous_key_hash);
        $this->assertTrue($expires->equalTo($site->previous_key_expires_at));

        $this->travel(2)->hours();
        $this->withToken($old)->postJson(route('marketplace.api.sites.rotate'))->assertUnauthorized()->assertJson(['code' => 'reconnect']);
        $this->withToken($old)->getJson(route('marketplace.api.catalog'))->assertUnauthorized();
        $this->withToken($latest)->getJson(route('marketplace.api.catalog'))->assertOk();
    }

    public function test_reviewers_cannot_change_the_cloudflare_token_or_the_limits(): void
    {
        $this->enableStore();
        $this->signInAdmin(Admin::factory()->withPermissions(['marketplace.review'])->create());
        Http::fake();

        $this->get(route('admin.store.sites.index'))->assertOk()->assertDontSee(route('admin.store.sites.cloudflare'))->assertDontSee(route('admin.store.sites.limits'));
        $this->put(route('admin.store.sites.cloudflare'), ['zone_id' => str_repeat('b', 32), 'api_token' => 'other-token', 'action' => 'save'])->assertForbidden();
        $this->put(route('admin.store.sites.cloudflare'), ['zone_id' => str_repeat('b', 32), 'action' => 'test'])->assertForbidden();
        $this->put(route('admin.store.sites.limits'), ['daily_limit' => 100000, 'domain_daily_limit' => 100000, 'hosts_per_domain' => 100000, 'sites_per_ip' => 100000])->assertForbidden();

        $this->assertSame('cf-token', setting('marketplace.cloudflare_token'));
        $this->assertSame(str_repeat('f', 32), setting('marketplace.cloudflare_zone_id'));
        Http::assertNothingSent();
    }

    public function test_ipv6_addresses_that_carry_an_internal_ipv4_address_are_not_public(): void
    {
        foreach (['64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '64:ff9b::a00:9', '64:ff9b:1::808:808', '::ffff:127.0.0.1', '::ffff:169.254.169.254'] as $address) {
            $this->assertFalse(PublicAddress::isPublic($address), $address);
        }

        $this->assertTrue(PublicAddress::isPublic('64:ff9b::808:808'));
        $this->assertTrue(PublicAddress::isPublic('2606:4700:4700::1111'));

        // The connect form never calls such an address, whatever the visitor names.
        $this->enableStore();
        PublicAddress::$resolveUsing = fn (string $host): array => [self::SERVER_IP];
        $pinned = [];
        Http::fake(['https://'.self::SITE.'/nuvabill-marketplace/connect' => function (Request $request, array $options) use (&$pinned) {
            $pinned[] = $options['curl'][CURLOPT_RESOLVE][0] ?? null;

            return Http::response(['ok' => true, 'ip' => self::SERVER_IP, 'family' => 'ipv4', 'return_path' => '/admin/marketplace']);
        }]);

        foreach (['64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe'] as $hint) {
            $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => str_repeat('s', 43), 'ip' => $hint])->assertRedirect();
        }

        $this->assertSame(array_fill(0, 2, self::SITE.':443:'.self::SERVER_IP), $pinned);

        // Nor a site whose DNS points there.
        $pinned = [];
        PublicAddress::$resolveUsing = fn (string $host): array => [self::SERVER_IP, '64:ff9b::a9fe:a9fe'];
        MarketplaceSite::query()->delete();
        $this->post(route('marketplace.connect.store'), ['site' => self::SITE, 'state' => str_repeat('s', 43)]);
        $this->assertSame([], $pinned);
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
