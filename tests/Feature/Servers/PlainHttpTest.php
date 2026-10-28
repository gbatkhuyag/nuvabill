<?php

namespace Tests\Feature\Servers;

use App\Extensions\ExtensionManager;
use App\Health\CheckResult;
use App\Health\SiteHealth;
use App\Health\Status;
use App\Models\Admin;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Security\PublicAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Plain HTTP to a control panel sends the password or API token without encryption, so it needs
 * its own box and a private-network address. Without the box, no module sends anything.
 */
class PlainHttpTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Host names the tests use, with the addresses they point to.
     *
     * @var array<string, list<string>>
     */
    private const HOSTS = [
        'panel.example.com' => ['198.51.100.20'],
        'panel.lan' => ['192.168.1.10'],
        'mixed.example.com' => ['10.0.0.9', '198.51.100.21'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        PublicAddress::$resolveUsing = fn (string $host): array => self::HOSTS[$host] ?? [];
    }

    protected function tearDown(): void
    {
        PublicAddress::$resolveUsing = null;

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function modules(): array
    {
        return [
            'cPanel' => ['cpanel', 2087],
            'DirectAdmin' => ['directadmin', 2222],
            'Plesk' => ['plesk', 8443],
            'Proxmox' => ['proxmox', 8006],
            'Virtualizor' => ['virtualizor', 4085],
        ];
    }

    #[DataProvider('modules')]
    public function test_plain_http_without_the_box_sends_nothing(string $module, int $port): void
    {
        Http::fake(['*' => Http::response([])]);
        $server = $this->server($module, $port, ['hostname' => 'panel.example.com', 'use_ssl' => false, 'allow_plain_http' => false]);

        $result = app(Provisioner::class)->testConnection($server);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('This server is set to plain HTTP.', $result->message);
        Http::assertNothingSent();
    }

    #[DataProvider('modules')]
    public function test_plain_http_with_the_box_uses_http(string $module, int $port): void
    {
        Http::fake(['*' => Http::response([])]);
        $server = $this->server($module, $port, ['hostname' => '10.0.0.5', 'use_ssl' => false, 'allow_plain_http' => true]);

        app(Provisioner::class)->testConnection($server);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), "http://10.0.0.5:{$port}/"));
    }

    #[DataProvider('modules')]
    public function test_https_is_used_when_it_is_on(string $module, int $port): void
    {
        Http::fake(['*' => Http::response([])]);
        $server = $this->server($module, $port, ['hostname' => 'panel.example.com', 'use_ssl' => true, 'allow_plain_http' => true]);

        app(Provisioner::class)->testConnection($server);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), "https://panel.example.com:{$port}/"));
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://'));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function loginModules(): array
    {
        return [
            'cPanel' => ['cpanel', 2087],
            'DirectAdmin' => ['directadmin', 2222],
            'Plesk' => ['plesk', 8443],
        ];
    }

    #[DataProvider('loginModules')]
    public function test_a_login_link_over_plain_http_without_the_box_sends_nothing(string $module, int $port): void
    {
        Http::fake(['*' => Http::response(['url' => 'http://panel.example.com/login'])]);
        $server = $this->server($module, $port, ['hostname' => 'panel.example.com', 'use_ssl' => false, 'allow_plain_http' => false]);
        $service = Service::factory()->create(['server_id' => $server->id, 'username' => 'mer']);

        $this->assertNull(app(ExtensionManager::class)->serverModule($module)->loginUrl($service));
        Http::assertNothingSent();
    }

    public function test_plesk_does_not_follow_a_redirect_with_the_api_key(): void
    {
        Http::fake([
            'plesk.example.com*' => Http::response('', 302, ['Location' => 'https://collector.example.net/api/v2/server']),
            'collector.example.net*' => Http::response(['panel_version' => '18']),
        ]);
        $server = $this->server('plesk', 8443, ['hostname' => 'plesk.example.com', 'api_token' => 'plesk-key']);

        $result = app(Provisioner::class)->testConnection($server);

        $this->assertFalse($result->success);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'collector.example.net'));
    }

    public function test_the_form_needs_the_box_for_plain_http(): void
    {
        $this->signInStaff();

        $this->post(route('admin.servers.store'), $this->form(['use_ssl' => '0', 'hostname' => '10.0.0.5']))
            ->assertSessionHasErrors('use_ssl');

        $this->assertSame(0, Server::query()->count());
    }

    public function test_the_form_refuses_plain_http_for_a_public_address(): void
    {
        $this->signInStaff();

        $this->post(route('admin.servers.store'), $this->form(['use_ssl' => '0', 'allow_plain_http' => '1', 'hostname' => 'panel.example.com']))
            ->assertSessionHasErrors('hostname');
        $this->post(route('admin.servers.store'), $this->form(['use_ssl' => '0', 'allow_plain_http' => '1', 'hostname' => '198.51.100.20']))
            ->assertSessionHasErrors('hostname');
        $this->post(route('admin.servers.store'), $this->form(['use_ssl' => '0', 'allow_plain_http' => '1', 'hostname' => 'mixed.example.com']))
            ->assertSessionHasErrors('hostname');
        $this->post(route('admin.servers.store'), $this->form(['use_ssl' => '0', 'allow_plain_http' => '1', 'hostname' => 'unknown.lan']))
            ->assertSessionHasErrors('hostname');
        $this->post(route('admin.servers.store'), $this->form(['use_ssl' => '0', 'allow_plain_http' => '1', 'hostname' => 'panel.lan', 'ip_address' => '198.51.100.20']))
            ->assertSessionHasErrors('ip_address');
        // Link-local addresses (cloud metadata) are not a private network.
        $this->post(route('admin.servers.store'), $this->form(['use_ssl' => '0', 'allow_plain_http' => '1', 'hostname' => '169.254.169.254']))
            ->assertSessionHasErrors('hostname');

        $this->assertSame(0, Server::query()->count());
    }

    public function test_the_form_saves_plain_http_for_a_private_address_with_the_box(): void
    {
        $this->signInStaff();

        $this->post(route('admin.servers.store'), $this->form(['use_ssl' => '0', 'allow_plain_http' => '1', 'hostname' => 'panel.lan']))
            ->assertSessionHasNoErrors();

        $server = Server::query()->sole();
        $this->assertFalse($server->use_ssl);
        $this->assertTrue($server->allow_plain_http);
    }

    public function test_the_box_is_cleared_when_https_is_on(): void
    {
        $this->signInStaff();

        $this->post(route('admin.servers.store'), $this->form(['use_ssl' => '1', 'allow_plain_http' => '1', 'hostname' => 'panel.example.com']))
            ->assertSessionHasNoErrors();

        $this->assertFalse(Server::query()->sole()->allow_plain_http);
    }

    public function test_allowing_plain_http_needs_the_token_again(): void
    {
        $this->signInStaff();
        $server = $this->server('cpanel', 2087, ['hostname' => '10.0.0.5', 'use_ssl' => false, 'allow_plain_http' => false, 'api_token' => 'stored-root-token']);

        $this->put(route('admin.servers.update', $server), $this->form(['use_ssl' => '0', 'allow_plain_http' => '1', 'hostname' => '10.0.0.5', 'api_token' => '']))
            ->assertSessionHasErrors('api_token');

        $this->assertFalse($server->fresh()->allow_plain_http);
        $this->assertSame('stored-root-token', $server->fresh()->api_token);
    }

    public function test_the_update_ticks_the_box_for_servers_already_on_plain_http(): void
    {
        Schema::table('servers', fn ($table) => $table->dropColumn('allow_plain_http'));
        $plain = DB::table('servers')->insertGetId(['name' => 'Old', 'module' => 'cpanel', 'hostname' => 'panel.example.com', 'use_ssl' => false, 'created_at' => now(), 'updated_at' => now()]);
        $secure = DB::table('servers')->insertGetId(['name' => 'New', 'module' => 'cpanel', 'hostname' => 'panel2.example.com', 'use_ssl' => true, 'created_at' => now(), 'updated_at' => now()]);

        $migration = require database_path('migrations/2027_08_01_000018_add_allow_plain_http_to_servers.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Server::query()->findOrFail($plain)->allow_plain_http);
        $this->assertFalse(Server::query()->findOrFail($secure)->allow_plain_http);
    }

    public function test_site_health_lists_plain_http_servers(): void
    {
        Server::factory()->create(['name' => 'Secure']);
        $this->assertSame(Status::Passed, $this->healthCheck()->status);

        Server::factory()->plainHttp()->create(['name' => 'Office', 'hostname' => 'panel.lan']);
        $check = $this->healthCheck();
        $this->assertSame(Status::Warning, $check->status);
        $this->assertSame(['Office'], array_column($check->items, 'label'));

        // A turned-off server that still has services keeps sending its token.
        $public = Server::factory()->plainHttp()->create(['name' => 'Old WHM', 'hostname' => 'panel.example.com', 'is_active' => false]);
        $this->assertSame(['Office'], array_column($this->healthCheck()->items, 'label'));
        Service::factory()->create(['server_id' => $public->id]);

        $check = $this->healthCheck();
        $this->assertSame(Status::Urgent, $check->status);
        $this->assertSame(['Office' => 'warning', 'Old WHM' => 'urgent'], array_column($check->items, 'status', 'label'));
    }

    private function healthCheck(): CheckResult
    {
        return app(SiteHealth::class)->run()->check('servers.https');
    }

    private function signInStaff(): void
    {
        $role = Role::query()->create(['name' => 'Servers', 'permissions' => ['products.manage']]);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz', 'role_id' => $role->id]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function server(string $module, int $port, array $attributes = []): Server
    {
        return Server::factory()->create($attributes + [
            'module' => $module,
            'port' => $port,
            'username' => $module === 'proxmox' ? 'root@pam!nuvabill' : 'root',
            'password' => 'stored-password',
            'api_token' => 'stored-token',
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'name' => 'WHM 1',
            'module' => 'cpanel',
            'hostname' => 'whm.example.com',
            'ip_address' => '',
            'port' => '2087',
            'use_ssl' => '1',
            'allow_plain_http' => '0',
            'username' => 'root',
            'password' => '',
            'api_token' => 'new-token',
            'max_accounts' => '',
            'nameservers' => '',
            'is_active' => '1',
        ];
    }
}
