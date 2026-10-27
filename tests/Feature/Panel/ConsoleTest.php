<?php

namespace Tests\Feature\Panel;

use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\Panel\ConsoleSession;
use App\Extensions\Servers\Panel\PanelException;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Service;
use App\Provisioning\ConsoleTickets;
use App\Provisioning\PanelGate;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * The browser console: opening it makes a single-use ticket bound to the client, the service and
 * the browser session; the console page shows no secret and alone may connect to the console host;
 * the page's script redeems the ticket once for the address and password.
 */
class ConsoleTest extends TestCase
{
    use RefreshDatabase, UsesPanelFake;

    /**
     * The browser session of the client in these tests.
     */
    private string $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPanelFake();
        $this->session = Str::random(40);
    }

    public function test_opening_the_console_leads_to_a_page_that_alone_may_connect_and_shows_no_secret(): void
    {
        $service = $this->panelService();

        $open = $this->inSession()->post(route('client.services.console.open', $service))
            ->assertStatus(303)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $location = (string) $open->headers->get('Location');
        $this->assertStringStartsWith(route('client.services.console', $service).'?ticket=', $location);

        $page = $this->inSession()->get($location)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee(PanelFakeModule::CONSOLE_PASSWORD)
            ->assertDontSee('t=abc');
        $this->assertStringContainsString("connect-src 'self' wss://console.example.test:4083;", (string) $page->headers->get('Content-Security-Policy'));

        // No other page may connect to the console host.
        foreach ([route('client.services.show', $service), route('client.dashboard')] as $other) {
            $this->assertStringNotContainsString('wss:', (string) $this->inSession()->get($other)->assertOk()->headers->get('Content-Security-Policy'));
        }
    }

    public function test_a_terminal_console_gets_the_xterm_page_with_the_same_ticket_rules(): void
    {
        PanelFakeModule::$behaviour = ['console_protocol' => ConsoleSession::TERMINAL];
        $service = $this->panelService();
        $ticket = $this->openConsole($service);

        $page = $this->inSession()->get(route('client.services.console', [$service, 'ticket' => $ticket]))
            ->assertOk()
            ->assertSee('data-terminal', false)
            ->assertDontSee('data-console ', false)
            ->assertSee('xterm.js')
            ->assertDontSee('Ctrl+Alt+Del')
            ->assertDontSee(PanelFakeModule::CONSOLE_PASSWORD);
        $this->assertMatchesRegularExpression('#/build/assets/terminal-[^"]+\.js#', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('#/build/assets/console-[^"]+\.js#', $page->getContent());
        $this->assertStringContainsString("connect-src 'self' wss://console.example.test:4083;", (string) $page->headers->get('Content-Security-Policy'));

        $this->inSession()->postJson(route('client.services.console.redeem', $service), ['ticket' => $ticket])
            ->assertOk()
            ->assertJsonPath('protocol', 'terminal')
            ->assertJsonPath('credentials.password', PanelFakeModule::CONSOLE_PASSWORD);
        $this->inSession()->postJson(route('client.services.console.redeem', $service), ['ticket' => $ticket])->assertStatus(410);

        // A VNC console stays on noVNC.
        PanelFakeModule::$behaviour = [];
        $vnc = $this->openConsole($service);
        $this->inSession()->get(route('client.services.console', [$service, 'ticket' => $vnc]))->assertOk()->assertSee('data-console', false)->assertDontSee('data-terminal', false);
        $this->inSession()->postJson(route('client.services.console.redeem', $service), ['ticket' => $vnc])->assertJsonPath('protocol', 'vnc');
    }

    public function test_a_console_kind_the_page_cannot_show_is_refused(): void
    {
        PanelFakeModule::$behaviour = ['console_protocol' => 'spice'];
        $service = $this->panelService();

        $this->inSession()->postJson(route('client.services.console.open', $service))
            ->assertStatus(503)
            ->assertJsonPath('message', 'The console is not available for this server right now.');
        $this->assertSame([], $this->storedTickets());
    }

    public function test_the_ticket_gives_the_address_and_password_once(): void
    {
        $service = $this->panelService();
        $ticket = $this->openConsole($service);

        $this->inSession()->postJson(route('client.services.console.redeem', $service), ['ticket' => $ticket])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('url', PanelFakeModule::CONSOLE_URL)
            ->assertJsonPath('credentials.password', PanelFakeModule::CONSOLE_PASSWORD)
            ->assertJsonStructure(['expires_at']);

        $this->inSession()->postJson(route('client.services.console.redeem', $service), ['ticket' => $ticket])
            ->assertStatus(410)
            ->assertJsonPath('message', 'This console link has expired.');
    }

    public function test_a_ticket_works_only_for_its_client_service_and_session(): void
    {
        $service = $this->panelService();
        $sibling = $this->panelService();
        $ticket = $this->openConsole($service);
        $page = route('client.services.console', [$service, 'ticket' => $ticket]);

        $this->inSession(client: Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null]))->get($page)->assertNotFound();
        $this->inSession(client: Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null]))
            ->postJson(route('client.services.console.redeem', $service), ['ticket' => $ticket])
            ->assertNotFound();

        // A leaked link in another session, or on another service, neither connects nor opens the policy.
        $elsewhere = $this->inSession(Str::random(40))->get($page)->assertOk()->assertSee('This console link has expired.');
        $this->assertStringNotContainsString('wss:', (string) $elsewhere->headers->get('Content-Security-Policy'));
        $this->inSession()->get(route('client.services.console', [$sibling, 'ticket' => $ticket]))->assertOk()->assertSee('This console link has expired.');
        $this->inSession(Str::random(40))->postJson(route('client.services.console.redeem', $service), ['ticket' => $ticket])->assertStatus(410);
        $this->inSession()->postJson(route('client.services.console.redeem', $sibling), ['ticket' => $ticket])->assertStatus(410);

        // None of those used it up.
        $this->inSession()->postJson(route('client.services.console.redeem', $service), ['ticket' => $ticket])->assertOk();
    }

    public function test_a_ticket_ends_after_a_minute(): void
    {
        $service = $this->panelService();
        $ticket = $this->openConsole($service);

        $this->travel(61)->seconds();

        $page = $this->inSession()->get(route('client.services.console', [$service, 'ticket' => $ticket]))
            ->assertOk()
            ->assertSee('This console link has expired.');
        $this->assertStringNotContainsString('wss:', (string) $page->headers->get('Content-Security-Policy'));
        $this->inSession()->postJson(route('client.services.console.redeem', $service), ['ticket' => $ticket])->assertStatus(410);
    }

    public function test_a_ticket_ends_when_the_module_session_ends_first(): void
    {
        $service = $this->panelService();
        $tickets = app(ConsoleTickets::class);
        $context = $this->contextFor($service);
        $handle = $tickets->issue($service, $context, new ConsoleSession('wss://console.example.test/vnc', ['password' => 'Short-Lived-1'], new DateTimeImmutable('+20 seconds')), $this->session);

        // Stored encrypted, under the hash of the handle.
        $stored = Cache::get('nuvabill:console:'.hash('sha256', $handle));
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('Short-Lived-1', $stored);
        $this->assertSame(40, strlen($handle));
        $this->assertSame('wss://console.example.test:443', $tickets->peek($handle, $this->client->id, $service->id, $this->session));

        $this->travel(21)->seconds();
        $this->assertNull($tickets->peek($handle, $this->client->id, $service->id, $this->session));
        $this->assertNull($tickets->redeem($handle, $this->client->id, $service->id, $this->session));

        $this->expectException(PanelException::class);
        $tickets->issue($service, $context, new ConsoleSession('wss://console.example.test/vnc', [], new DateTimeImmutable('-1 second')), $this->session);
    }

    public function test_the_console_can_be_turned_off_by_setting_or_product_switch(): void
    {
        $service = $this->panelService();

        $this->setSettings(['services.panel_console' => false]);
        $this->inSession()->postJson(route('client.services.console.open', $service))->assertForbidden()->assertJsonPath('message', 'This feature is turned off for your plan.');
        $this->inSession()->post(route('client.services.console.open', $service))
            ->assertRedirect(route('client.services.show', $service).'#panel-console')
            ->assertSessionHas('error', 'This feature is turned off for your plan.');

        $this->setSettings(['services.panel_console' => true]);
        $service->product->update(['panel_sections' => ['console' => false]]);
        $this->inSession()->postJson(route('client.services.console.open', $service))->assertForbidden();

        $this->assertSame([], PanelFakeModule::calls('consoleSession'));
    }

    public function test_a_module_without_a_browser_console_has_none(): void
    {
        $service = $this->panelService();
        $module = $this->moduleWithoutConsole();

        $result = app(PanelGate::class)->openConsole($service, $module, $this->contextFor($service));

        $this->assertSame(404, $result->status);
        $this->assertNull(app(PanelGate::class)->serverPanel($service, $module, $this->contextFor($service))['console']);
    }

    public function test_a_console_address_that_is_not_plain_wss_is_refused_and_no_ticket_is_made(): void
    {
        $service = $this->panelService();

        foreach (['ws://console.example.test:4083/?t=abc', 'wss://[::1]:4083/?t=abc', 'wss://user:pass@console.example.test/?t=abc', 'wss://console.example.test:70000/?t=abc'] as $url) {
            PanelFakeModule::$behaviour['console_url'] = $url;

            $this->inSession()->postJson(route('client.services.console.open', $service))
                ->assertStatus(503)
                ->assertJsonPath('message', 'The console is not available for this server right now.');
        }

        $this->assertSame([], $this->storedTickets());
        $this->assertSame(4, ActivityLog::query()->where('action', 'service.module_failed')->count());
        $this->assertStringNotContainsString('t=abc', ActivityLog::query()->pluck('description')->implode("\n"));
        $this->assertFalse(ActivityLog::query()->where('action', 'service.console_opened')->exists());

        PanelFakeModule::$behaviour['console_url'] = PanelFakeModule::CONSOLE_URL;
        $this->openConsole($service);
        $this->assertCount(1, $this->storedTickets());
    }

    public function test_an_address_without_a_port_uses_443(): void
    {
        PanelFakeModule::$behaviour['console_url'] = 'wss://web-console.example.test/websockify?token=abc';
        $service = $this->panelService();
        $ticket = $this->openConsole($service);

        $page = $this->inSession()->get(route('client.services.console', [$service, 'ticket' => $ticket]))->assertOk();

        $this->assertStringContainsString('wss://web-console.example.test:443', (string) $page->headers->get('Content-Security-Policy'));
        $this->inSession()->postJson(route('client.services.console.redeem', $service), ['ticket' => $ticket])
            ->assertOk()
            ->assertJsonPath('url', 'wss://web-console.example.test/websockify?token=abc');
    }

    public function test_opening_is_logged_without_secrets_and_limited_per_service(): void
    {
        $service = $this->panelService();

        foreach (range(1, 6) as $try) {
            $ticket = $this->openConsole($service);
        }

        $this->inSession()->postJson(route('client.services.console.open', $service))->assertStatus(429)->assertHeader('Retry-After');

        $log = ActivityLog::query()->where('action', 'service.console_opened')->get();
        $this->assertCount(6, $log);
        $this->assertSame($service->id, $log->first()->subject_id);
        $descriptions = $log->pluck('description')->implode("\n");

        foreach ([PanelFakeModule::CONSOLE_PASSWORD, 't=abc', 'console.example.test', $ticket] as $secret) {
            $this->assertStringNotContainsString($secret, $descriptions);
        }
    }

    public function test_a_suspended_service_gets_no_console(): void
    {
        $service = $this->panelService();
        $ticket = $this->openConsole($service);

        $service->update(['status' => ServiceStatus::Suspended]);

        $this->inSession()->postJson(route('client.services.console.open', $service))->assertForbidden()->assertJsonPath('message', 'This service is suspended.');
        $this->inSession()->get(route('client.services.console', [$service, 'ticket' => $ticket]))->assertSee('This console link has expired.');
        $this->inSession()->postJson(route('client.services.console.redeem', $service), ['ticket' => $ticket])->assertStatus(410);
    }

    public function test_the_demo_locks_opening_and_redeeming(): void
    {
        $service = $this->panelService();
        $ticket = $this->openConsole($service);
        config(['nuvabill.demo' => true]);

        $this->inSession()->postJson(route('client.services.console.open', $service))->assertForbidden();
        $this->inSession()->postJson(route('client.services.console.redeem', $service), ['ticket' => $ticket])->assertForbidden();

        $this->assertCount(1, PanelFakeModule::calls('consoleSession'));
    }

    /**
     * Act as the client in a browser session (by its session cookie), so tickets bind to it.
     */
    private function inSession(?string $session = null, ?Client $client = null): static
    {
        // JSON requests send cookies only with credentials, like fetch() with credentials: 'same-origin'.
        return $this->actingAs($client ?? $this->client, 'web')->withCredentials()->withCookie((string) config('session.cookie'), $session ?? $this->session);
    }

    /**
     * The cache keys of stored console tickets (the test cache keeps everything in an array).
     *
     * @return list<string>
     */
    private function storedTickets(): array
    {
        $storage = (fn (): array => $this->storage)->call(Cache::store()->getStore());

        return array_values(array_filter(array_keys($storage), fn (string $key): bool => preg_match('/^nuvabill:console:[0-9a-f]{64}$/', $key) === 1));
    }

    /**
     * Open the console and give the ticket handle from the address of the console page.
     */
    private function openConsole(Service $service): string
    {
        $response = $this->inSession()->post(route('client.services.console.open', $service));
        $response->assertStatus(303);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertIsString($query['ticket'] ?? null);

        return $query['ticket'];
    }

    /**
     * A module with panel sections, including "console", but no browser console.
     */
    private function moduleWithoutConsole(): NoConsoleModule
    {
        $manifest = app(ExtensionManager::class)->find('panelfake');

        return new NoConsoleModule($manifest, new PanelFakeModule($manifest));
    }
}
