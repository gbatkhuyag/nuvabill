<?php

namespace Tests\Feature\Servers;

use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Virtualizor 2.0: the client's one-click sign-in to their own VPS on the end-user port. The call
 * is signed with the API key and a salted hash, tied to the client's IP, and made only after the
 * Virtualizor user behind the VPS is found to hold nothing of another client.
 */
class VirtualizorSignInTest extends TestCase
{
    use FakesVirtualizor, RefreshDatabase;

    private const TOKEN = 'sessvkD9MBpOg0aFkHfD';

    private const SID = 'efgBrcHtvyVEGcvaeI9q2A9X4kaOAU18';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeVirtualizor(['sso' => ['token_key' => self::TOKEN, 'sid' => self::SID]]);
    }

    public function test_the_sign_in_call_is_signed_pinned_and_tied_to_the_clients_ip(): void
    {
        $service = $this->vpsService(['client_login' => '1']);
        $back = route('client.services.show', $service);

        $url = (string) app(Provisioner::class)->signInUrl($service, $this->context($service));

        $this->assertSame('vz.example.test', parse_url($url, PHP_URL_HOST));
        $this->assertSame(4083, parse_url($url, PHP_URL_PORT));
        $this->assertSame('/'.self::TOKEN.'/', parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $link);
        $this->assertSame(['as' => self::SID, 'goto_cp' => $back, 'svs' => '77'], $link);

        $sso = $this->sent('sso')[0];
        $this->assertSame(4083, $sso['port']);
        $this->assertEqualsCanonicalizing(['act', 'svs', 'api', 'adminapikey', 'apikey', 'SET_REMOTE_IP', 'goto_cp'], array_keys($sso['query']));
        $this->assertSame(['sso', '77', 'json', self::API_KEY, '198.51.100.20', $back], [$sso['query']['act'], $sso['query']['svs'], $sso['query']['api'], $sso['query']['adminapikey'], $sso['query']['SET_REMOTE_IP'], $sso['query']['goto_cp']]);
        $salt = substr($sso['query']['apikey'], 0, 8);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $salt);
        $this->assertSame($salt.md5(self::API_PASSWORD.$salt), $sso['query']['apikey']);
        $this->assertStringNotContainsString(self::API_PASSWORD, $sso['url']);
        $this->assertStringContainsString('adminapikey='.self::API_KEY, $sso['url']);

        // Every end-user call is on 4083; only the Admin API reads go to 4085, and never sign in there.
        $this->assertSame([4085, 4085, 4083, 4083], array_column($this->api->calls, 'port'));
        $this->assertFalse(collect($this->api->calls)->contains(fn (array $call): bool => $call['port'] === 4085 && $call['kind'] === 'sso'));

        foreach (ActivityLog::query()->pluck('description') as $description) {
            $this->assertStringNotContainsString(self::TOKEN, $description);
            $this->assertStringNotContainsString(self::SID, $description);
            $this->assertStringNotContainsString($sso['query']['apikey'], $description);
        }
    }

    public function test_the_clients_live_services_on_the_same_master_may_share_a_virtualizor_user(): void
    {
        $client = $this->client();
        $service = $this->vpsService(['client_login' => '1'], client: $client);
        // The same master added a second time, by its name in capitals with a final dot.
        $sameMaster = $this->master(['hostname' => 'VZ.Example.test.']);
        $this->vpsService(attributes: ['status' => ServiceStatus::Suspended, 'module_data' => ['vpsid' => '78']], server: $sameMaster, client: $client);
        $this->vpsService(attributes: ['status' => ServiceStatus::Pending, 'module_data' => ['vpsid' => '79']], server: $service->server, client: $client);
        $this->fakeVirtualizor([
            'sso' => ['token_key' => self::TOKEN, 'sid' => self::SID],
            'listvs' => ['vs' => ['77' => ['vpsid' => '77', 'uid' => '58'], '78' => ['vpsid' => '78', 'uid' => '58'], '79' => ['vpsid' => '79', 'uid' => '58']]],
        ]);

        $this->assertNotNull(app(Provisioner::class)->signInUrl($service, $this->context($service)));
        $this->assertSame(['vps', 'users', 'listvs', 'sso'], array_column($this->api->calls, 'kind'));
        $this->assertSame(['page' => '1', 'reslen' => '100'], array_intersect_key($this->sent('listvs')[0]['query'], ['page' => 1, 'reslen' => 1]));
    }

    public function test_a_vps_linked_to_no_service_needs_the_users_email_to_be_the_clients_confirmed_email(): void
    {
        $client = $this->client('Mer Las', ['email' => self::CLIENT_EMAIL]);
        $service = $this->vpsService(['client_login' => '1'], client: $client);
        $this->fakeVirtualizor([
            'sso' => ['token_key' => self::TOKEN, 'sid' => self::SID],
            'listvs' => ['vs' => ['77' => ['vpsid' => '77', 'uid' => '58'], '90' => ['vpsid' => '90', 'uid' => '58']]],
        ]);

        // Not confirmed yet.
        $this->assertNull(app(Provisioner::class)->signInUrl($service, $this->context($service)));
        $this->assertSame([], $this->sent('sso'));
        $this->assertStringContainsString('VPS #90 of Virtualizor user 58 is linked to no service', (string) ActivityLog::query()->where('action', 'service.panel_login_refused')->latest('id')->value('description'));

        // Confirmed: allowed, and staff are told once a day.
        $client->forceFill(['email_verified_at' => now()])->save();
        $this->assertNotNull(app(Provisioner::class)->signInUrl($service->fresh(), $this->context($service)));
        $this->assertNotNull(app(Provisioner::class)->signInUrl($service->fresh(), $this->context($service)));
        $unlinked = ActivityLog::query()->where('action', 'service.panel_login_unlinked')->get();
        $this->assertCount(1, $unlinked);
        $this->assertStringContainsString('Link or delete these VPS: #90', $unlinked->first()->description);

        // Another email, even a confirmed one, is refused.
        $client->forceFill(['email' => 'raz@example.test', 'email_verified_at' => now()])->save();
        $this->assertNull(app(Provisioner::class)->signInUrl($service->fresh(), $this->context($service)));
    }

    public function test_a_vps_of_another_clients_service_refuses_the_sign_in_whatever_its_status(): void
    {
        $service = $this->vpsService(['client_login' => '1']);
        $other = $this->vpsService(attributes: ['module_data' => ['vpsid' => '80']], server: $this->master(['hostname' => 'vz.example.test']), client: $this->client('Raz'));
        $this->fakeVirtualizor([
            'sso' => ['token_key' => self::TOKEN, 'sid' => self::SID],
            'listvs' => ['vs' => ['77' => ['vpsid' => '77', 'uid' => '58'], '80' => ['vpsid' => '80', 'uid' => '58']]],
        ]);

        foreach ([ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending, ServiceStatus::Terminated, ServiceStatus::Cancelled, ServiceStatus::Fraud] as $status) {
            $other->update(['status' => $status]);

            $this->assertNull(app(Provisioner::class)->signInUrl($service, $this->context($service)), $status->value);
        }

        $this->assertSame([], $this->sent('sso'));
        $this->assertSame(6, ActivityLog::query()->where('action', 'service.panel_login_refused')->where('description', 'like', "%VPS #80 belongs to service #{$other->id} of another client.%")->count());
    }

    public function test_a_vps_left_over_from_an_ended_service_of_the_client_refuses_the_sign_in(): void
    {
        $client = $this->client();
        $service = $this->vpsService(['client_login' => '1'], client: $client);
        $ended = $this->vpsService(attributes: ['status' => ServiceStatus::Terminated, 'module_data' => ['vpsid' => '80']], server: $service->server, client: $client);
        $this->fakeVirtualizor([
            'sso' => ['token_key' => self::TOKEN, 'sid' => self::SID],
            'listvs' => ['vs' => ['77' => ['vpsid' => '77', 'uid' => '58'], '80' => ['vpsid' => '80', 'uid' => '58']]],
        ]);

        $this->assertNull(app(Provisioner::class)->signInUrl($service, $this->context($service)));
        $this->assertStringContainsString("VPS #80 of ended service #{$ended->id} still exists in Virtualizor.", (string) ActivityLog::query()->where('action', 'service.panel_login_refused')->value('description'));
        $this->assertSame([], $this->sent('sso'));
    }

    public function test_admin_and_cloud_users_never_sign_in(): void
    {
        $service = $this->vpsService(['client_login' => '1']);

        foreach (['1', '2'] as $type) {
            $this->fakeVirtualizor([
                'sso' => ['token_key' => self::TOKEN, 'sid' => self::SID],
                'users' => ['users' => ['58' => ['uid' => '58', 'email' => self::CLIENT_EMAIL, 'type' => $type]]],
            ]);

            $this->assertNull(app(Provisioner::class)->signInUrl($service, $this->context($service)));
            $this->assertSame(['vps', 'users'], array_column($this->api->calls, 'kind'), "type {$type}");
        }

        $this->assertSame(2, ActivityLog::query()->where('description', 'like', '%is an admin or cloud user%')->count());
    }

    public function test_a_list_that_fails_or_cannot_be_trusted_refuses_the_sign_in(): void
    {
        $service = $this->vpsService(['client_login' => '1']);
        $cases = [
            'the list fails' => ['error' => ['Permission denied']],
            'no connection' => fn () => throw new ConnectionException('cURL error 7: refused for https://vz.example.test:4083/index.php?apikey=abc'),
            '100 rows or more' => ['vs' => collect(range(1, 100))->mapWithKeys(fn (int $id): array => [(string) ($id + 76) => ['vpsid' => (string) ($id + 76), 'uid' => '58']])->all()],
            'a row of another user' => ['vs' => ['77' => ['vpsid' => '77', 'uid' => '58'], '81' => ['vpsid' => '81', 'uid' => '61']]],
            'a row without a number' => ['vs' => ['77' => ['vpsid' => '77', 'uid' => '58'], 'x' => ['vpsid' => 'abc']]],
            'not this VPS' => ['vs' => ['81' => ['vpsid' => '81', 'uid' => '58']]],
        ];

        foreach ($cases as $case => $answer) {
            $this->fakeVirtualizor(['sso' => ['token_key' => self::TOKEN, 'sid' => self::SID], 'listvs' => $answer]);

            $this->assertNull(app(Provisioner::class)->signInUrl($service, $this->context($service)), $case);
            $this->assertSame([], $this->sent('sso'), $case);
        }

        // A failed call is a failure for staff, the others are refusals; no key is ever kept.
        $this->assertSame(2, ActivityLog::query()->where('action', 'service.module_failed')->where('description', 'like', 'Panel sign-in%')->count());
        $this->assertSame(4, ActivityLog::query()->where('action', 'service.panel_login_refused')->count());
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%apikey=abc%')->exists());
    }

    public function test_behind_cloudflare_without_trusted_proxies_sign_in_is_refused_and_staff_are_told(): void
    {
        config(['trustedproxy.proxies' => []]);
        $service = $this->vpsService(['client_login' => '1']);

        foreach (range(1, 2) as $try) {
            $this->actingAs($service->client, 'web')
                ->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
                ->withHeaders(['CF-Ray' => '8a1b2c3d4e5f6a7b-AMS'])
                ->post(route('client.services.login', $service))
                ->assertRedirect(route('client.services.show', $service))
                ->assertSessionHas('error');
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'act=sso'));
        $logs = ActivityLog::query()->where('action', 'service.panel_login_refused')->pluck('description');
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('NUVABILL_TRUSTED_PROXIES=cloudflare', $logs->first());

        // The staff check says so too.
        $check = collect(app(Provisioner::class)->checkClientAccess($service))->firstWhere('label', 'Client IP address');
        $this->assertSame('fail', $check['status']);
    }

    public function test_the_button_shows_when_the_status_or_the_templates_cannot_be_read(): void
    {
        $service = $this->vpsService(['client_login' => '1']);
        $this->fakeVirtualizor([
            'status' => fn () => throw new ConnectionException('cURL error 28: timed out'),
            'ostemplates' => ['error' => ['Templates are not available']],
        ]);

        $this->actingAs($service->client, 'web')
            ->get(route('client.services.show', $service))
            ->assertOk()
            ->assertSee('Open Virtualizor panel');

        $this->assertSame([], $this->sent('ostemplates'));
    }

    public function test_the_vps_is_read_by_its_exact_id_among_fifty_rows(): void
    {
        $service = $this->vpsService(['client_login' => '1']);
        $this->fakeVirtualizor([
            'sso' => ['token_key' => self::TOKEN, 'sid' => self::SID],
            'vps' => ['vs' => ['177' => $this->vpsRow('177', ['uid' => '61']), '77' => $this->vpsRow('77')]],
        ]);

        $this->assertNotNull(app(Provisioner::class)->signInUrl($service, $this->context($service)));
        $this->assertSame('50', $this->sent('vps')[0]['query']['reslen']);
        $this->assertSame('58', $this->sent('users')[0]['query']['uid']);
    }

    public function test_the_connection_test_reports_the_end_user_port(): void
    {
        $server = $this->master();
        $cases = [
            'End-user port 4083: reachable, certificate valid.' => ['error' => ['VPS not found']],
            'End-user port 4083: the API key was refused. Use the key from Server Info, or allow listvs and sso for this key.' => ['error' => ['Invalid API key']],
            'End-user port 4083: could not connect. Allow port 4083 from this server and install a valid SSL certificate. Without it clients get only basic panel features, no sign-in and no console.' => fn () => throw new ConnectionException('cURL error 60: SSL certificate problem'),
        ];

        foreach ($cases as $line => $answer) {
            $this->fakeVirtualizor(['list' => ['counts' => ['vs' => 3], 'vs' => []], 'listvs' => $answer]);

            $result = app(Provisioner::class)->testConnection($server);

            $this->assertTrue($result->success, $line);
            $this->assertSame('Connected to Virtualizor. 3 VPS found. '.$line, $result->message);
            $this->assertSame(['0', '1', '1'], [$this->sent('listvs')[0]['query']['svs'], $this->sent('listvs')[0]['query']['page'], $this->sent('listvs')[0]['query']['reslen']]);
            $this->assertSame(4083, $this->sent('listvs')[0]['port']);
        }
    }
}
