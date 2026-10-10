<?php

namespace Tests\Feature\Panel;

use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Panel\ClientContext;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Support\Cloudflare;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * What modules learn about the client: the signed-in client (never a value from the request), the
 * real IP behind trusted proxies only, whether that IP can be trusted, the service page to come
 * back to and the language. Sign-in uses it for modules that ask for it.
 */
class ClientContextTest extends TestCase
{
    use RefreshDatabase, UsesPanelFake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPanelFake();
    }

    public function test_behind_trusted_proxies_the_module_gets_the_real_ip(): void
    {
        config(['trustedproxy.proxies' => Cloudflare::IP_RANGES]);
        $service = $this->panelService();

        $this->actingAs($this->client, 'web')
            ->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'CF-Ray' => '8a1b2c3d4e5f6a7b-AMS'])
            ->postJson(route('client.services.sections.action', [$service, 'network', 'rdns-set']), [
                'ip' => '192.0.2.10', 'ptr' => 'mail.example.test', 'client_id' => 999, 'clientId' => 999,
            ])
            ->assertOk();

        $context = $this->lastContext('runPanelAction');
        $this->assertSame('203.0.113.9', $context->ip);
        $this->assertTrue($context->ipTrusted);
        $this->assertSame($this->client->id, $context->clientId);
        $this->assertSame('en', $context->locale);
    }

    public function test_a_cloudflare_edge_ip_is_not_trusted_when_the_proxies_are_not(): void
    {
        config(['trustedproxy.proxies' => []]);
        $service = $this->panelService();

        $this->actingAs($this->client, 'web')
            ->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'CF-Ray' => '8a1b2c3d4e5f6a7b-AMS'])
            ->getJson(route('client.services.sections.show', [$service, 'overview']))
            ->assertOk();

        $context = $this->lastContext('readPanelSection');
        $this->assertSame('173.245.48.10', $context->ip);
        $this->assertFalse($context->ipTrusted);
    }

    public function test_the_return_address_is_the_absolute_service_page(): void
    {
        $service = $this->panelService();

        $this->actingAs($this->client, 'web')->getJson(route('client.services.sections.show', [$service, 'overview']))->assertOk();

        $returnUrl = $this->lastContext('readPanelSection')->returnUrl;
        $this->assertSame(route('client.services.show', $service), $returnUrl);
        $this->assertMatchesRegularExpression('#^https?://#', $returnUrl);
    }

    public function test_sign_in_gives_the_context_to_modules_that_ask_and_others_keep_their_login_url(): void
    {
        $service = $this->panelService(['username' => 'fake1']);

        $this->actingAs($this->client, 'web')
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post(route('client.services.login', $service))
            ->assertRedirect('https://panel.example.test/sso/one-time')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertSame('198.51.100.20', $this->lastContext('signInUrl')->ip);
        $this->assertSame([], PanelFakeModule::calls('loginUrl'));

        // A module without the context sign-in (cPanel) still gives its own login link.
        config(['nuvabill.extensions_path' => base_path('extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        Http::fake(['https://cp.example.test:2087/json-api/create_user_session' => Http::response([
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => ['url' => 'https://cp.example.test:2083/cpsess0123456789/login/?session=razweb:abc'],
        ])]);
        $server = Server::factory()->create(['module' => 'cpanel', 'hostname' => 'cp.example.test', 'port' => 2087, 'use_ssl' => true]);
        $product = Product::factory()->create(['server_module' => 'cpanel', 'server_id' => $server->id]);
        $hosting = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'server_id' => $server->id, 'username' => 'razweb']);

        $this->post(route('client.services.login', $hosting))
            ->assertRedirect('https://cp.example.test:2083/cpsess0123456789/login/?session=razweb:abc');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://cp.example.test:2087/json-api/create_user_session' && $request['user'] === 'razweb');
    }

    public function test_a_context_needs_a_signed_in_client(): void
    {
        $service = $this->panelService();

        $this->expectException(AuthenticationException::class);

        ClientContext::fromRequest(request(), $service);
    }

    private function lastContext(string $method): ClientContext
    {
        $calls = PanelFakeModule::calls($method);
        $this->assertNotSame([], $calls, "The module's {$method}() was not called.");

        return end($calls)['args']['context'];
    }
}
