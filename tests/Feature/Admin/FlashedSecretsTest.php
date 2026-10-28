<?php

namespace Tests\Feature\Admin;

use App\Extensions\Addons\Addon;
use App\Extensions\ExtensionManager;
use App\Http\SecretInput;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Product;
use App\Provisioning\OrderFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * A secret typed into a settings form (an API key, a token, a client secret, a license key) is
 * never kept in the session after a form error. The other fields still come back.
 */
class FlashedSecretsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'typed-secret-7c41e9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
    }

    public function test_a_gateway_secret_is_not_kept_after_a_form_error(): void
    {
        $this->from(route('admin.settings.gateways.edit', 'stripe'))
            ->put(route('admin.settings.gateways.update', 'stripe'), ['enabled' => '1', 'settings' => [
                'display_name' => str_repeat('n', 1001),
                'secret_key' => self::SECRET,
                'webhook_secret' => self::SECRET,
            ]])
            ->assertRedirect(route('admin.settings.gateways.edit', 'stripe'))
            ->assertSessionHasErrors('settings.display_name');

        $this->assertSame(str_repeat('n', 1001), session()->getOldInput('settings.display_name'));
        $this->assertNoSecretInSession();
    }

    public function test_a_registrar_secret_is_not_kept_after_a_form_error(): void
    {
        $this->from(route('admin.settings.registrars.edit', 'namecheap'))
            ->put(route('admin.settings.registrars.update', 'namecheap'), ['enabled' => '1', 'settings' => [
                'api_user' => 'raz-api',
                'api_key' => self::SECRET,
                'client_ip' => '203.0.113.7',
                'mode' => 'bogus',
            ]])
            ->assertSessionHasErrors('settings.mode');

        $this->assertSame('raz-api', session()->getOldInput('settings.api_user'));
        $this->assertNoSecretInSession();
    }

    public function test_an_addon_secret_is_not_kept_after_a_form_error(): void
    {
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $extensions = Mockery::mock(ExtensionManager::class.'[addon]', [base_path('tests/Fixtures/extensions')]);
        $manifest = $extensions->find('probe');
        $extensions->shouldReceive('addon')->with('probe')->andReturn(new class($manifest) extends Addon
        {
            public function settingsFields(): array
            {
                return [
                    'label' => ['label' => 'Label', 'type' => 'text', 'options' => ['a' => 'A', 'b' => 'B']],
                    'api_key' => ['label' => 'API key', 'type' => 'password'],
                    'token' => ['label' => 'Token', 'type' => 'textarea', 'secret' => true],
                ];
            }
        });
        $this->app->instance(ExtensionManager::class, $extensions);

        $this->put(route('admin.marketplace.settings.update', 'probe'), ['enabled' => '1', 'settings' => [
            'label' => 'c',
            'api_key' => self::SECRET,
            'token' => self::SECRET,
        ]])->assertSessionHasErrors('settings.label');

        $this->assertSame('c', session()->getOldInput('settings.label'));
        $this->assertNoSecretInSession();
    }

    public function test_a_telegram_token_is_not_kept_when_telegram_refuses_it(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);
        $token = '123456789:'.str_repeat('A', 30).self::SECRET;

        $this->put(route('admin.settings.chat.telegram'), ['token' => $token])->assertSessionHas('error');

        $this->assertStringNotContainsString(self::SECRET, (string) json_encode(session()->all()));
    }

    public function test_whatsapp_secrets_are_not_kept_after_a_form_error(): void
    {
        $this->put(route('admin.settings.chat.whatsapp.manual'), [
            'phone_number_id' => '1234567890',
            'waba_id' => '9876543210',
            'token' => self::SECRET,
            'app_secret' => 'not-hex-'.self::SECRET,
        ])->assertSessionHasErrors('app_secret');

        $this->assertSame('1234567890', session()->getOldInput('phone_number_id'));
        $this->assertNoSecretInSession();
    }

    public function test_social_login_client_secrets_are_not_kept(): void
    {
        // Google is switched on without a secret: the controller sends the form back itself.
        $this->put(route('admin.settings.social.update'), [
            'google' => ['enabled' => '1', 'client_id' => 'google-id'],
            'github' => ['client_id' => 'github-id', 'client_secret' => self::SECRET],
        ])->assertSessionHasErrors('google.client_secret');

        $this->assertSame('github-id', session()->getOldInput('github.client_id'));
        $this->assertNoSecretInSession();

        // Facebook is switched on without a client ID: a normal validation error.
        $this->put(route('admin.settings.social.update'), [
            'facebook' => ['enabled' => '1', 'client_secret' => self::SECRET],
            'github' => ['client_id' => 'github-id', 'client_secret' => self::SECRET],
        ])->assertSessionHasErrors('facebook.client_id');

        $this->assertSame('github-id', session()->getOldInput('github.client_id'));
        $this->assertNoSecretInSession();
    }

    public function test_top_level_secrets_are_not_kept_after_a_form_error(): void
    {
        $this->put(route('admin.settings.ai.update'), ['key' => 'wrong-'.self::SECRET, 'model' => 'bogus'])->assertSessionHasErrors('key');
        $this->assertNoSecretInSession();

        $this->put(route('admin.settings.security.update'), ['captcha_provider' => 'bogus', 'captcha_secret' => self::SECRET, 'captcha_site_key' => 'site-key'])
            ->assertSessionHasErrors('captcha_provider');
        $this->assertSame('site-key', session()->getOldInput('captcha_site_key'));
        $this->assertNoSecretInSession();

        $domain = Domain::factory()->create();
        $this->put(route('admin.domains.update', $domain), ['status' => 'bogus', 'epp_code' => self::SECRET])->assertSessionHasErrors('status');
        $this->assertNoSecretInSession();
    }

    public function test_a_license_key_is_not_kept_when_the_download_fails(): void
    {
        Http::fake(['*' => Http::response(['message' => 'This license key is not valid.'], 403)]);

        $this->post(route('admin.marketplace.install', 'some-theme'), ['license_key' => self::SECRET])->assertSessionHas('error');

        $this->assertNoSecretInSession();
    }

    public function test_a_failed_captcha_does_not_keep_secrets_from_the_order_form(): void
    {
        $this->setSettings([
            'security.captcha_provider' => 'turnstile',
            'security.captcha_site_key' => 'site-key',
            'security.captcha_secret' => 'secret-key',
            'security.captcha_forms' => ['checkout'],
            'security.captcha_checked_key' => 'site-key',
        ]);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);

        $this->actingAs($client, 'web')->post(route('order.store'), [
            'product_id' => Product::factory()->priced(999)->create()->id,
            'billing_cycle' => 'monthly',
            'domain' => 'mer-example.test',
            'epp_code' => self::SECRET,
            OrderFields::SECRET => ['root_pass' => self::SECRET],
            'cf-turnstile-response' => 'token',
        ])->assertSessionHasErrors('captcha');

        $this->assertSame('mer-example.test', session()->getOldInput('domain'));
        $this->assertNoSecretInSession();
    }

    public function test_the_secret_names_cover_every_kind_of_secret(): void
    {
        $names = SecretInput::names();

        foreach (['password', 'current_password', 'details_current_password', 'root_password', 'api_token', OrderFields::SECRET, 'key', 'token', 'epp_code', 'license_key', 'google.client_secret'] as $name) {
            $this->assertContains($name, $names);
        }
    }

    private function assertNoSecretInSession(): void
    {
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode(session()->all()));
    }
}
