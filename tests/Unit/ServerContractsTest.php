<?php

namespace Tests\Unit;

use App\Contracts\ChecksClientAccess;
use App\Contracts\ChoosesWelcomeEmail;
use App\Contracts\FinishesSetupLater;
use App\Contracts\HasConsole;
use App\Contracts\HasPanelSections;
use App\Contracts\HasPanelState;
use App\Contracts\HasResourceOptions;
use App\Contracts\LimitsPlanChanges;
use App\Contracts\LinksExistingAccount;
use App\Contracts\QueuesPackageChanges;
use App\Contracts\SignsInWithContext;
use App\Contracts\UsesMachineInventory;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\ConsoleSession;
use App\Extensions\Servers\Panel\PanelAction;
use App\Extensions\Servers\Panel\PanelException;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Panel\PanelSection;
use App\Extensions\Servers\Panel\StandardSections;
use App\Extensions\Servers\Resources\ResourceCheck;
use App\Extensions\Servers\Resources\ResourceExtras;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

class ServerContractsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function consoleAddresses(): array
    {
        return [
            'no port is 443' => ['wss://h', 'wss://h:443'],
            'path and token are left out' => ['wss://h:4083/novnc/?virttoken=1', 'wss://h:4083'],
            'host is lowercased without the end dot' => ['wss://Console.Example.TEST.:4083/x', 'wss://console.example.test:4083'],
            'IPv4 host' => ['wss://192.0.2.5:4085', 'wss://192.0.2.5:4085'],
        ];
    }

    #[DataProvider('consoleAddresses')]
    public function test_a_console_address_gives_only_its_wss_host_and_port(string $url, string $source): void
    {
        $this->assertSame($source, (new ConsoleSession($url))->connectSource());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedConsoleAddresses(): array
    {
        return [
            'not wss' => ['ws://h'],
            'https' => ['https://h'],
            'user name' => ['wss://u@h'],
            'user and password' => ['wss://u:p@h'],
            'IPv6 literal' => ['wss://[::1]'],
            'port 0' => ['wss://h:0'],
            'port 70000' => ['wss://h:70000'],
            'port with letters' => ['wss://h:44a3'],
            'semicolon in the host' => ['wss://h;x'],
            'underscore in the host' => ['wss://h_x.test'],
            'backslash trick' => ['wss://h\\@evil.test'],
            'not an IPv4 address' => ['wss://999.1.1.1'],
            'header injection' => ["wss://h\r\nContent-Security-Policy: x"],
        ];
    }

    #[DataProvider('refusedConsoleAddresses')]
    public function test_any_other_console_address_is_refused(string $url): void
    {
        try {
            (new ConsoleSession($url))->connectSource();
            $this->fail("{$url} was accepted.");
        } catch (PanelException $exception) {
            $this->assertSame(503, $exception->status);
        }
    }

    public function test_a_refused_console_address_is_never_repeated(): void
    {
        $session = new ConsoleSession('wss://raz@h/?token=TOPSECRET', ['password' => 'VncPass123']);

        try {
            $session->connectSource();
            $this->fail('The address was accepted.');
        } catch (PanelException $exception) {
            $this->assertStringNotContainsString('TOPSECRET', $exception->getMessage().$exception->staffDetail);
        }

        $this->assertStringNotContainsString('TOPSECRET', print_r($session, true));
        $this->assertStringNotContainsString('VncPass123', print_r($session, true));
    }

    public function test_panel_section_declarations_report_their_problems(): void
    {
        $this->assertSame([], PanelSection::standard('network', actions: [
            'rdns-set' => new PanelAction('rdns-set', 'Save reverse DNS', rules: ['ip' => ['required', 'ip']]),
        ])->problems());
        $this->assertSame([], (new PanelSection('notes', 'Notes', view: 'ext-panelfake::notes'))->problems());

        $this->assertProblem('must be 1 to 32 lowercase letters', new PanelSection('Bad Key', 'Bad', view: 'ext-panelfake::notes'));
        $this->assertProblem('needs its own view', new PanelSection('extras', 'Extras'));
        $this->assertProblem('must be a view of the module', new PanelSection('extras', 'Extras', view: 'admin.dashboard'));
        $this->assertProblem("'confirm' => ['accepted']", PanelSection::standard('reinstall', actions: [
            'run' => new PanelAction('run', 'Reinstall', destructive: true),
        ]));
        $this->assertProblem('under its own key', PanelSection::standard('power', actions: [new PanelAction('start', 'Start')]));

        $this->assertSame([], PanelSection::standard('reinstall', actions: [
            'run' => new PanelAction('run', 'Reinstall', rules: ['confirm' => 'required|accepted'], destructive: true),
        ])->problems());
    }

    public function test_standard_sections_get_their_label_icon_and_standard_limits(): void
    {
        $network = PanelSection::standard('network', actions: [
            'rdns-set' => new PanelAction('rdns-set', 'Save reverse DNS'),
            'rdns-delete' => new PanelAction('rdns-delete', 'Delete', throttle: [50, 60]),
        ]);

        $this->assertSame('IPs and rDNS', $network->label);
        $this->assertSame('globe', $network->icon);
        $this->assertNull($network->view);
        $this->assertSame([20, 3600], $network->action('rdns-set')?->throttle);
        $this->assertSame([50, 60], $network->action('rdns-delete')?->throttle, 'A limit the module chose stays.');

        $poweroff = StandardSections::powerAction('poweroff');
        $this->assertSame('Power off', $poweroff->label);
        $this->assertTrue($poweroff->destructive);
        $this->assertSame(['confirm' => ['accepted']], $poweroff->rules);
        $this->assertSame('power', $poweroff->bucketFor('power'));
        $this->assertSame('network.rdns-set', $network->action('rdns-set')?->bucketFor('network'));
        $this->assertSame('running', StandardSections::expectedState('reset'));
        $this->assertNull(StandardSections::expectedState('wol'));
        $this->assertSame([], PanelSection::standard('power', actions: ['poweroff' => $poweroff])->problems());
    }

    public function test_secret_looking_keys_are_scrubbed_at_any_depth(): void
    {
        $data = StandardSections::scrub([
            'hostname' => 'vps.example.test',
            'root_password' => 'x',
            'credential_mode' => 'set',
            'can' => ['root-password' => true, 'vnc-password' => false],
            'ips' => [['ip' => '192.0.2.10', 'apikey' => 'x', 'api_key' => 'x', 'sid' => 'x']],
            'panel' => ['token' => 'x', 'secret' => 'x', 'passwd' => 'x', 'notes' => 'kept'],
        ]);

        $this->assertSame([
            'hostname' => 'vps.example.test',
            'credential_mode' => 'set',
            'can' => ['root-password' => true, 'vnc-password' => false],
            'ips' => [['ip' => '192.0.2.10']],
            'panel' => ['notes' => 'kept'],
        ], $data);

        $result = PanelResult::secret('Saved.', ['Root password' => 'Pass12345'], ['vnc_password' => 'x']);
        $this->assertSame([['label' => 'Root password', 'value' => 'Pass12345']], $result->secret);
        $this->assertNull($result->scrubbed()->secret);
        $this->assertSame([], $result->scrubbed(keepSecret: true)->data);
        $this->assertSame($result->secret, $result->scrubbed(keepSecret: true)->secret);
        $this->assertStringNotContainsString('Pass12345', print_r($result, true));
    }

    public function test_panel_results_carry_only_known_statuses(): void
    {
        $this->assertSame(422, PanelResult::fail('No.', status: 200)->status);
        $this->assertSame(409, PanelResult::fail('Busy.', status: 409)->status);
        $this->assertSame(403, PanelResult::unavailable('Off.')->status);
        $this->assertSame(409, (new PanelException('Busy.', 'Lock held.', 409))->toResult()->status);

        $throttled = PanelResult::throttled(42);
        $this->assertFalse($throttled->ok);
        $this->assertSame(429, $throttled->status);
        $this->assertSame(42, $throttled->retryAfter);
        $this->assertStringContainsString('42', $throttled->message);
    }

    public function test_resource_kind_keys(): void
    {
        foreach (['ram', 'ipv4', 'virtualizor_network_speed', 'a'.str_repeat('b', 39)] as $key) {
            $this->assertTrue(ResourceKind::isValidKey($key), $key);
        }

        foreach (['', 'r', 'Ram', '1ram', 'ram-size', "ram\n", 'a'.str_repeat('b', 40)] as $key) {
            $this->assertFalse(ResourceKind::isValidKey($key), json_encode($key));
        }

        $this->assertTrue(ResourceKind::isValidUnit('KB/s'));
        $this->assertFalse(ResourceKind::isValidUnit('GB '));
    }

    public function test_resource_extras(): void
    {
        $extras = new ResourceExtras(['ram' => 2048, 'disk' => 0, 'ipv4' => 1]);

        $this->assertSame(2048, $extras->get('ram'));
        $this->assertSame(0, $extras->get('cpu'));
        $this->assertTrue($extras->has('disk'));
        $this->assertFalse($extras->has('cpu'));
        $this->assertSame(['ram', 'disk', 'ipv4'], $extras->kinds());
        $this->assertSame(['ram' => 2048, 'ipv4' => 1], $extras->only(['ipv4', 'ram', 'cpu'])->units);
        $this->assertFalse($extras->isEmpty());
        $this->assertTrue($extras->only(['disk'])->isEmpty());
        $this->assertTrue((new ResourceExtras([]))->isEmpty());
    }

    public function test_a_resource_check_matches_only_when_every_kind_is_right(): void
    {
        $this->assertTrue((new ResourceCheck(['ram' => 4096], ['ram' => 4096, 'cpu' => 2]))->matches());
        $this->assertFalse((new ResourceCheck(['ram' => 4096], ['ram' => 2048]))->matches());
        $this->assertFalse((new ResourceCheck(['ram' => 4096], ['ram' => null]))->matches());
        $this->assertFalse((new ResourceCheck(['ram' => 4096], []))->matches());
        $this->assertFalse((new ResourceCheck(['ram' => 4096], ['ram' => 4096], ['Disk: could not read']))->matches());
    }

    public function test_the_client_context_comes_from_the_signed_in_client(): void
    {
        $client = new Client;
        $client->id = 7;
        $service = new Service;
        $service->id = 12;

        $request = Request::create('/', 'GET', ['client_id' => 99], server: ['REMOTE_ADDR' => '192.0.2.44']);
        $request->setUserResolver(fn (?string $guard = null): ?Client => $guard === 'web' ? $client : null);
        $context = ClientContext::fromRequest($request, $service);

        $this->assertSame(7, $context->clientId);
        $this->assertSame('192.0.2.44', $context->ip);
        $this->assertTrue($context->ipTrusted);
        $this->assertSame(route('client.services.show', $service), $context->returnUrl);
        $this->assertSame(app()->getLocale(), $context->locale);

        $edge = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_RAY' => '8c1f2a3b4c5d6e7f-FRA']);
        $edge->setUserResolver(fn (): Client => $client);
        $this->assertFalse(ClientContext::fromRequest($edge, $service)->ipTrusted);

        $this->expectException(AuthenticationException::class);
        ClientContext::fromRequest(Request::create('/'), $service);
    }

    public function test_the_fake_module_implements_every_server_contract(): void
    {
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->app->forgetInstance(ExtensionManager::class);
        $module = app(ExtensionManager::class)->serverModule('panelfake');
        PanelFakeModule::reset();

        foreach ([HasPanelSections::class, SignsInWithContext::class, HasConsole::class, ChecksClientAccess::class, LinksExistingAccount::class, HasResourceOptions::class, UsesMachineInventory::class, FinishesSetupLater::class, ChoosesWelcomeEmail::class, LimitsPlanChanges::class, QueuesPackageChanges::class, HasPanelState::class] as $contract) {
            $this->assertInstanceOf($contract, $module);
        }

        $product = new Product;
        $product->id = 3;
        $sections = $module->panelSections($product);

        $this->assertNotEmpty($sections);
        $this->assertSame([], array_merge(...array_map(fn (PanelSection $section): array => $section->problems(), [...$sections, PanelFakeModule::customSection()])));
        $this->assertSame([[5, 600]], array_map(fn (PanelSection $section): ?array => $section->action('details')?->throttle, array_values(array_filter($sections, fn (PanelSection $section): bool => $section->key === 'console'))));

        foreach ($module->resourceKinds($product) as $kind => $definition) {
            $this->assertTrue(ResourceKind::isValidKey($kind) && $kind === $definition->key, $kind);
        }

        $context = new ClientContext(7, '192.0.2.44', true, 'https://billing.example.test/client/services/12', 'en');
        $service = new Service;
        $service->id = 12;
        $this->assertSame('wss://console.example.test:4083', $module->consoleSession($service, $context)->connectSource());
        $this->assertSame(['panelSections', 'resourceKinds', 'consoleSession'], array_column(PanelFakeModule::calls(), 'method'));
    }

    private function assertProblem(string $expected, PanelSection $section): void
    {
        $problems = $section->problems();

        $this->assertNotEmpty(array_filter($problems, fn (string $problem): bool => str_contains($problem, $expected)), "No problem with \"{$expected}\" in: ".implode(' | ', $problems));
    }
}
