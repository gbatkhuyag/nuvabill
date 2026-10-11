<?php

namespace Tests\Feature\Servers;

use App\Contracts\ChecksClientAccess;
use App\Contracts\HasConsole;
use App\Contracts\HasPanelSections;
use App\Contracts\HasResourceOptions;
use App\Contracts\LinksExistingAccount;
use App\Contracts\SignsInWithContext;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelException;
use App\Extensions\Servers\Panel\PanelSection;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Nuvabill\Extensions\Virtualizor\VirtualizorModule;
use stdClass;
use Tests\TestCase;

/**
 * Virtualizor 2.0: the module's contracts and settings, and the client server panel sections that
 * run on the Admin API (overview, power, console, reinstall and settings).
 */
class VirtualizorAdminSectionsTest extends TestCase
{
    use RefreshDatabase;

    private const API_PASSWORD = 'S3cretApiPass99';

    private const VNC_PASSWORD = 'Vnc7pass';

    private ?stdClass $api = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_the_module_offers_the_new_contracts_sections_and_product_settings(): void
    {
        $manifest = app(ExtensionManager::class)->find('virtualizor');
        $module = app(ExtensionManager::class)->serverModule('virtualizor');

        $this->assertSame('2.0.0', $manifest->version);
        $this->assertSame('>=0.7.0', $manifest->requires);
        $this->assertTrue($manifest->isCompatibleWith((string) config('nuvabill.version')));

        foreach ([HasPanelSections::class, SignsInWithContext::class, HasConsole::class, ChecksClientAccess::class, LinksExistingAccount::class, HasResourceOptions::class] as $contract) {
            $this->assertInstanceOf($contract, $module);
        }

        $this->assertInstanceOf(VirtualizorModule::class, $module);
        $this->assertSame('theme::client.services.server-panel', $module->clientPanelView());
        $this->assertArrayNotHasKey('vnc', $module->clientActions());

        $sections = $module->panelSections(new Product(['server_module' => 'virtualizor', 'module_config' => ['virt' => 'kvm']]));
        $this->assertSame(
            ['overview', 'console', 'graphs', 'reinstall', 'network', 'firewall', 'backups', 'rescue', 'ssh-keys', 'media', 'settings', 'activity', 'power'],
            array_map(fn (PanelSection $section): string => $section->key, $sections),
        );

        foreach ($sections as $section) {
            $this->assertSame([], $section->problems(), $section->key);
        }

        ['console' => $console, 'reinstall' => $reinstall, 'settings' => $settings, 'power' => $power] = collect($sections)->keyBy('key')->all();
        $this->assertSame(['start', 'stop', 'restart', 'poweroff'], array_keys($power->actions));
        $this->assertTrue($power->actions['poweroff']->destructive);
        $this->assertTrue($console->actions['details']->reveals);
        $this->assertFalse($console->actions['details']->changesServer);
        $this->assertTrue($reinstall->actions['run']->destructive);
        $this->assertSame([3, 3600], $reinstall->actions['run']->throttle);
        $this->assertSame(['password', 'password_confirmation'], $settings->actions['root-password']->secretFields);

        $fields = $module->productFields();
        $this->assertArrayNotHasKey('resource_addons', $fields);
        $this->assertSame('IP pool for extra IPv4', $fields['ipv4_pool_id']['label']);
        $this->assertSame('OS templates clients may choose', $fields['reinstall_os_ids']['label']);
        $this->assertStringContainsString('Test panel sign-in', $fields['client_login']['help']);
        $this->assertStringContainsString('DNS only', $module->serverHelp());

        // The HTTPS switch is named as the server form shows it.
        $this->assertStringContainsString('Keep "Connect with HTTPS" on', $module->serverHelp());
        $this->assertStringContainsString('"Connect with HTTPS" on for the server', $fields['client_login']['help']);
        $this->assertStringNotContainsString('Use SSL', $module->serverHelp().implode(' ', array_column($fields, 'help')));
    }

    public function test_the_vps_is_found_by_its_exact_id_among_up_to_fifty_rows(): void
    {
        $api = $this->fakeVirtualizor([
            'vps' => ['vs' => [
                '177' => ['vpsid' => '177', 'uid' => '61', 'hostname' => 'other.example.com', 'ips' => ['2' => '192.0.2.177']],
                '77' => ['vpsid' => '77', 'uid' => '58', 'hostname' => 'vps77.example.com', 'ips' => ['2' => '203.0.113.7']],
            ]],
        ]);
        $service = $this->service();

        $panel = app(Provisioner::class)->clientPanel($service)['data'];

        $this->assertSame('vps77.example.com', $panel['hostname']);
        $this->assertSame(['203.0.113.7'], $panel['ips']);
        $this->assertSame('50', collect($api->calls)->firstWhere('kind', 'vps')['query']['reslen']);
        // The OS templates load in the reinstall tab, not with every page.
        $this->assertNotContains('ostemplates', array_column($api->calls, 'kind'));
    }

    public function test_the_sign_in_button_shows_when_a_read_fails_and_the_panel_says_so_when_both_fail(): void
    {
        $down = fn () => throw new ConnectionException('cURL error 7: refused');
        $this->fakeVirtualizor(['status' => $down]);
        $service = $this->service(['client_login' => '1'], ['module_data' => ['vpsid' => '77', 'uid' => '58']]);
        $this->actingAs($service->client, 'web');

        $this->get(route('client.services.show', $service))->assertOk()->assertSee('Open Virtualizor panel');

        $this->fakeVirtualizor(['vps' => $down]);
        $this->get(route('client.services.show', $service))->assertOk()->assertSee('Open Virtualizor panel');

        $this->fakeVirtualizor(['vps' => $down, 'status' => $down]);
        $this->get(route('client.services.show', $service))->assertOk()->assertSee('The server did not answer.')->assertDontSee('Open Virtualizor panel');
    }

    public function test_the_overview_tab_gives_the_standard_shape_and_reads_the_vps_details_once_a_minute(): void
    {
        $api = $this->fakeVirtualizor([
            'status' => ['status' => ['77' => ['status' => 1, 'used_cpu' => 12.5, 'used_ram' => 512, 'ram' => 2048, 'used_disk' => 8, 'disk' => 40, 'used_bandwidth' => 3, 'bandwidth' => 0, 'net_in' => 2048, 'net_out' => 512]]],
        ]);
        $service = $this->service();
        $this->actingAs($service->client, 'web');
        $read = fn () => $this->getJson(route('client.services.sections.show', [$service, 'overview']))->assertOk();

        $read()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.state', 'running')
            ->assertJsonPath('data.cpu', 12.5)
            ->assertJsonPath('data.ram', ['used' => 512, 'total' => 2048, 'unit' => 'MB'])
            ->assertJsonPath('data.bandwidth', ['used' => 3, 'total' => null, 'unit' => 'GB'])
            ->assertJsonPath('data.network', ['in' => 2048, 'out' => 512])
            ->assertJsonPath('data.facts.0', ['label' => 'Hostname', 'value' => 'vps1.example.com'])
            ->assertJsonPath('data.ips', [['ip' => '203.0.113.7', 'version' => 4, 'primary' => true]])
            ->assertJsonPath('data.resources.0', ['label' => 'CPU cores', 'value' => '2'])
            ->assertJsonPath('live', true);
        // The end-user port does not answer here: the panel keeps its Admin API sections.
        $this->assertSame(['vpsmanage', 'vps', 'status'], array_column($api->calls, 'kind'));

        // A live refresh asks only for the figures while the details are fresh.
        $api->calls = [];
        $this->travel(10)->seconds();
        $read();
        $this->assertSame(['status'], array_column($api->calls, 'kind'));
    }

    public function test_the_console_connects_to_the_end_user_port_with_the_vnc_password(): void
    {
        $api = $this->fakeVirtualizor(['vnc' => ['info' => ['ip' => '198.51.100.2', 'port' => 5901, 'password' => self::VNC_PASSWORD]]]);
        $service = $this->service();
        $module = app(ExtensionManager::class)->serverModule('virtualizor');
        $context = new ClientContext($service->client_id, '198.51.100.20', true, route('client.services.show', $service), 'en');

        $session = $module->consoleSession($service, $context);

        $this->assertSame('wss://vz.example.test:4083/novnc/?virttoken=77', $session->url);
        $this->assertSame(['password' => self::VNC_PASSWORD], $session->credentials);
        $this->assertSame('wss://vz.example.test:4083', $session->connectSource());
        $this->assertSame(['novnc' => '77'], collect($api->calls)->firstWhere('kind', 'vnc')['data']);

        // The page itself only learns where to connect; the password comes from the single redeem.
        $this->actingAs($service->client, 'web')
            ->post(route('client.services.console.open', $service))
            ->assertStatus(303)
            ->assertHeader('Location');
        $this->assertTrue(ActivityLog::query()->where('action', 'service.console_opened')->exists());
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%'.self::VNC_PASSWORD.'%')->exists());

        $this->fakeVirtualizor(['vnc' => ['info' => ['port' => 5901, 'password' => '']]]);

        try {
            $module->consoleSession($service, $context);
            $this->fail('A VPS without a VNC password has no console.');
        } catch (PanelException $exception) {
            $this->assertSame('The console is off for this server. Contact support.', $exception->getMessage());
        }

        // Another client's service is not found; a product whose panel port is an admin port has no console.
        $adminPort = $this->service(['panel_port' => '4085']);
        $this->getJson(route('client.services.sections.show', [$adminPort, 'console']))->assertNotFound();
        $this->actingAs($adminPort->client, 'web')
            ->getJson(route('client.services.sections.show', [$adminPort, 'console']))
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }

    public function test_a_reinstall_keeps_to_the_allowed_templates_and_the_monthly_limit(): void
    {
        $api = $this->fakeVirtualizor([
            'ostemplates' => ['ostemplates' => [
                '100' => ['osid' => 100, 'type' => 'kvm', 'name' => 'Ubuntu 24.04'],
                '101' => ['osid' => 101, 'type' => 'kvm', 'name' => 'Debian 12'],
                '200' => ['osid' => 200, 'type' => 'openvz', 'name' => 'CentOS 7'],
            ]],
            'rebuild' => ['done' => 1],
        ]);
        $service = $this->service(['reinstall_os_ids' => '100, 200', 'osreinstall_limit' => '1']);
        $this->actingAs($service->client, 'web');
        $run = fn (string $template) => $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'run']), [
            'template' => $template, 'password' => 'Better12345', 'password_confirmation' => 'Better12345', 'confirm' => '1',
        ]);

        $this->getJson(route('client.services.sections.show', [$service, 'reinstall']))
            ->assertOk()
            ->assertJsonPath('data.templates', [['id' => '100', 'name' => 'Ubuntu 24.04', 'group' => 'Ubuntu']])
            ->assertJsonPath('data.left_this_month', 1)
            ->assertJsonPath('data.credential_mode', 'set')
            ->assertJsonPath('data.can.run', true);

        $run('101')->assertStatus(422)->assertJsonPath('message', 'Choose an operating system from the list.');
        $run('100')->assertOk()->assertJsonPath('message', 'The reinstall has started. All data on the VPS is being erased.');
        $run('100')->assertStatus(422)->assertJsonPath('message', 'You have used all OS reinstalls for this month. Contact support if you need another one.');

        $rebuilds = collect($api->calls)->where('kind', 'rebuild')->values();
        $this->assertCount(1, $rebuilds);
        $this->assertSame('100', $rebuilds[0]['data']['osid']);
        $this->assertSame('Better12345', $service->fresh()->password);
        $this->assertSame(1, $service->fresh()->module_data['reinstalls']['count']);
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%Better12345%')->exists());
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->bodyHtml, 'The operating system of your server was reinstalled.'));

        // A list staff typed wrong allows no template, and staff see why.
        $broken = $this->service(['reinstall_os_ids' => '100;rm']);
        $this->actingAs($broken->client, 'web')
            ->getJson(route('client.services.sections.show', [$broken, 'reinstall']))
            ->assertOk()
            ->assertJsonPath('data.templates', [])
            ->assertJsonPath('data.can.run', false);
        $this->assertTrue(ActivityLog::query()->where('description', 'like', '%cannot reinstall%')->exists());
    }

    public function test_settings_change_the_hostname_and_the_root_password(): void
    {
        $hostname = 'vps1.example.com';
        $api = $this->fakeVirtualizor([
            'vps' => function (array $query) use (&$hostname): array {
                return ['vs' => ['77' => ['vpsid' => '77', 'uid' => '58', 'suspended' => '0', 'hostname' => $hostname, 'ips' => ['2' => '203.0.113.7']]]];
            },
            'managevps' => function (array $query, array $data) use (&$hostname): array {
                $hostname = $data['hostname'] ?? $hostname;

                return ['done' => ['done' => true], 'vs_info' => ['vpsid' => '77']];
            },
        ]);
        $service = $this->service();
        $this->actingAs($service->client, 'web');

        $this->getJson(route('client.services.sections.show', [$service, 'settings']))->assertOk()->assertJsonPath('data.hostname', 'vps1.example.com');

        $this->postJson(route('client.services.sections.action', [$service, 'settings', 'hostname']), ['hostname' => 'New.Example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'Hostname changed. It takes effect after a restart.');
        $this->postJson(route('client.services.sections.action', [$service, 'settings', 'hostname']), ['hostname' => 'bad host'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['hostname']]);
        $this->getJson(route('client.services.sections.show', [$service, 'settings']))->assertOk()->assertJsonPath('data.hostname', 'new.example.com');

        $this->postJson(route('client.services.sections.action', [$service, 'settings', 'root-password']), ['password' => 'Better12345', 'password_confirmation' => 'Other12345'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
        $this->postJson(route('client.services.sections.action', [$service, 'settings', 'root-password']), ['password' => 'Better12345', 'password_confirmation' => 'Better12345'])
            ->assertOk();

        $this->assertSame(['editvps' => 1, 'rootpass' => 'Better12345'], collect($api->calls)->where('kind', 'managevps')->last()['data']);
        $this->assertSame('Better12345', $service->fresh()->password);
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%Better12345%')->exists());
        $this->assertStringNotContainsString('Better12345', (string) json_encode(session()->all()));
    }

    public function test_the_old_vnc_action_never_gives_the_password_to_a_direct_call(): void
    {
        $api = $this->fakeVirtualizor(['vnc' => ['info' => ['ip' => '198.51.100.2', 'port' => 5901, 'password' => self::VNC_PASSWORD]]]);
        $service = $this->service();

        $result = app(Provisioner::class)->clientAction($service, 'vnc', []);

        $this->assertFalse($result->success);
        $this->assertSame('This action is not available.', $result->message);
        $this->assertSame([], $api->calls);
    }

    /**
     * A fake Virtualizor answering by the kind of request (see kind()); a second call swaps the
     * answers and clears the log of calls.
     *
     * @param  array<string, mixed>  $answers
     */
    private function fakeVirtualizor(array $answers): stdClass
    {
        $answers += [
            'vps' => fn (array $query): array => ['vs' => [$query['vpsid'] => ['vpsid' => $query['vpsid'], 'uid' => '58', 'suspended' => '0', 'hostname' => 'vps1.example.com', 'os_name' => 'Ubuntu 24.04', 'virt' => 'kvm', 'cores' => '2', 'ram' => '2048', 'space' => '40', 'ips' => ['2' => '203.0.113.7']]]],
            'status' => ['status' => ['77' => ['status' => 1]]],
            'ostemplates' => ['ostemplates' => ['100' => ['osid' => 100, 'type' => 'kvm', 'name' => 'Ubuntu 24.04']]],
        ];

        if ($this->api === null) {
            $this->api = new stdClass;

            Http::preventStrayRequests();
            Http::fake(function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $act = (string) ($query['act'] ?? '');
                $kind = match (true) {
                    $act !== 'vs' => $act,
                    isset($query['vs_status']) => 'status',
                    isset($query['action']) => 'power',
                    isset($query['vpsid']) => 'vps',
                    default => 'list',
                };
                $this->api->calls[] = ['kind' => $kind, 'query' => $query, 'data' => $request->data(), 'url' => $request->url()];
                $answer = $this->api->answers[$kind] ?? ['error' => ['Unexpected request: '.$kind]];
                $answer = $answer instanceof Closure ? $answer($query, $request->data()) : $answer;

                return is_array($answer) ? Http::response($answer) : $answer;
            });
        }

        $this->api->answers = $answers;
        $this->api->calls = [];

        return $this->api;
    }

    /**
     * An active Virtualizor service of Mer Las with VPS 77, on a server with SSL.
     *
     * @param  array<string, string>  $config
     * @param  array<string, mixed>  $attributes
     */
    private function service(array $config = [], array $attributes = []): Service
    {
        $server = Server::factory()->create(['module' => 'virtualizor', 'hostname' => 'vz.example.test', 'port' => 4085, 'password' => self::API_PASSWORD]);
        $product = Product::factory()->create(['server_module' => 'virtualizor', 'server_id' => $server->id, 'module_config' => $config + ['virt' => 'kvm', 'os_id' => '100']]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null]);

        return Service::factory()->create($attributes + [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'status' => ServiceStatus::Active,
            'domain' => 'vps1.example.com',
            'username' => 'root',
            'module_data' => ['vpsid' => '77'],
        ]);
    }
}
