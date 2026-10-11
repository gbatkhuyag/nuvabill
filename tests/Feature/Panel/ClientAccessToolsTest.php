<?php

namespace Tests\Feature\Panel;

use App\Http\Controllers\Admin\ServiceAccessController;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * Staff tools for the client panel: "Test panel sign-in" and "Link existing account" on the admin
 * service page, and the data of the admin cards (the product's panel switches and the service's
 * client access card).
 */
class ClientAccessToolsTest extends TestCase
{
    use RefreshDatabase, UsesPanelFake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPanelFake();
    }

    public function test_staff_test_the_panel_sign_in_and_see_each_result(): void
    {
        $service = $this->panelService();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.services.access-check', $service))
            ->assertRedirect(route('admin.services.show', $service).'#client-access')
            ->assertSessionHas('status', 'The check is done. See the results under Client access.')
            ->assertSessionHas(ServiceAccessController::FLASH.'.service', $service->id)
            ->assertSessionHas(ServiceAccessController::FLASH.'.rows.0.label', 'Panel answers');

        $log = ActivityLog::query()->where('action', 'service.access_checked')->sole();
        $this->assertStringContainsString('2 checks, 1 passed, 0 warnings, 0 failed', $log->description);
        $this->assertCount(1, PanelFakeModule::calls('checkClientAccess'));
    }

    public function test_the_check_warns_when_the_site_is_behind_a_proxy_that_is_not_trusted(): void
    {
        config(['trustedproxy.proxies' => []]);
        $service = $this->panelService();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $warning = ['label' => 'Proxy in front of this site', 'status' => 'warn', 'detail' => 'This site is behind a proxy that is not trusted, so the panel sign-in sees the proxy\'s address, not the client\'s. Set NUVABILL_TRUSTED_PROXIES in .env.'];

        foreach (['X-Forwarded-For' => '198.51.100.20', 'X-Real-IP' => '198.51.100.20', 'Forwarded' => 'for=198.51.100.20'] as $header => $value) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeaders([$header => $value])
                ->post(route('admin.services.access-check', $service))
                ->assertSessionHas(ServiceAccessController::FLASH.'.rows', fn (array $rows): bool => end($rows) === $warning);
            $this->flushHeaders();
        }

        // A trusted proxy passes the client's address on: no warning.
        config(['trustedproxy.proxies' => ['10.0.0.5']]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeaders(['X-Forwarded-For' => '198.51.100.20'])
            ->post(route('admin.services.access-check', $service))
            ->assertSessionHas(ServiceAccessController::FLASH.'.rows', fn (array $rows): bool => ! in_array('Proxy in front of this site', array_column($rows, 'label'), true));
        $this->flushHeaders();

        // Reached directly: no warning either, and the forwarded address is never shown.
        $this->post(route('admin.services.access-check', $service))
            ->assertSessionHas(ServiceAccessController::FLASH.'.rows', fn (array $rows): bool => count($rows) === 2 && ! str_contains((string) json_encode($rows), '198.51.100.20'));
    }

    public function test_a_module_without_sign_in_checks_says_so(): void
    {
        $product = Product::factory()->create(['server_module' => null]);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id]);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.services.access-check', $service))->assertSessionHas('error', 'This server module has no sign-in checks.');
        $this->post(route('admin.services.link', $service), ['vps_id' => '77'])->assertSessionHas('error', 'This server module cannot link an existing account.');
        $this->assertFalse(ActivityLog::query()->where('action', 'service.access_checked')->exists());
    }

    public function test_staff_link_an_existing_account_with_the_module_fields_only(): void
    {
        $service = $this->panelService();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.services.link', $service), ['uid' => '5'])
            ->assertRedirect(route('admin.services.show', $service).'#client-access')
            ->assertSessionHasErrors('vps_id');
        $this->assertSame([], PanelFakeModule::calls('linkExistingAccount'));

        $this->post(route('admin.services.link', $service), ['vps_id' => '77', 'uid' => '5', 'module_data' => ['fake_id' => 'x']])
            ->assertSessionHas('status', 'Linked.');

        $this->assertSame(['vps_id' => '77'], PanelFakeModule::calls('linkExistingAccount')[0]['args']['input']);
        $this->assertSame('77', $service->fresh()->module_data['fake_id']);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.account_linked')->exists());
    }

    public function test_the_tools_need_the_services_permission_and_are_locked_in_the_demo(): void
    {
        $service = $this->panelService();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz', 'role_id' => Role::factory()->with(['clients.view'])]));

        $this->post(route('admin.services.access-check', $service))->assertForbidden();
        $this->post(route('admin.services.link', $service), ['vps_id' => '77'])->assertForbidden();

        config(['nuvabill.demo' => true]);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));
        $this->post(route('admin.services.access-check', $service))->assertSessionHas('error', 'This is turned off in the demo, so it keeps working for every visitor.');
        $this->post(route('admin.services.link', $service), ['vps_id' => '77'])->assertSessionHas('error');

        $this->assertSame([], PanelFakeModule::calls('checkClientAccess'));
        $this->assertSame([], PanelFakeModule::calls('linkExistingAccount'));
    }

    public function test_the_client_access_card_gets_the_tools_and_the_last_results_of_its_service(): void
    {
        $service = $this->panelService();
        $other = $this->panelService();
        $rows = [['label' => 'Panel answers', 'status' => 'ok', 'detail' => 'Fine.'], ['label' => 'Sign-in link', 'status' => 'warn', 'detail' => 'Check it.']];
        session()->put(ServiceAccessController::FLASH, ['service' => $service->id, 'rows' => $rows]);

        $access = $this->composed('admin.services.partials.client-access', ['service' => $service])['clientAccess'];

        $this->assertSame(route('admin.services.access-check', $service), $access['check_url']);
        $this->assertSame(route('admin.services.link', $service), $access['link_url']);
        $this->assertSame(['vps_id'], array_keys($access['link_fields']));
        $this->assertSame($rows, $access['checks']);
        $this->assertSame(['ok' => 1, 'warn' => 1, 'fail' => 0, 'skip' => 0], $access['summary']);

        // Results of another service are not shown here, and a service without a module gets no card.
        $this->assertNull($this->composed('admin.services.partials.client-access', ['service' => $other])['clientAccess']['checks']);
        $plain = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => Product::factory()->create(['server_module' => null])->id]);
        $this->assertNull($this->composed('admin.services.partials.client-access', ['service' => $plain])['clientAccess']);
        $this->assertSame([], PanelFakeModule::calls('checkClientAccess'), 'The card never runs the check by itself.');
    }

    public function test_the_product_form_gets_the_panel_switches_of_each_module(): void
    {
        $product = Product::factory()->create(['server_module' => 'panelfake', 'panel_sections' => ['network' => false]]);

        $modules = $this->composed('admin.products.partials.panel-sections', ['product' => $product])['panelSectionModules'];

        $this->assertSame(['panelfake'], array_keys($modules));
        $sections = array_column($modules['panelfake'], null, 'key');
        $this->assertSame(['overview', 'power', 'console', 'graphs', 'network', 'reinstall', 'settings', 'snapshots'], array_keys($sections));
        $this->assertTrue($sections['overview']['enabled']);
        $this->assertFalse($sections['network']['enabled']);
        $this->assertTrue($sections['network']['default']);
        $this->assertFalse($sections['snapshots']['enabled']);
        $this->assertSame('IPs and rDNS', $sections['network']['label']);

        // After a form error the posted switches show again.
        request()->setLaravelSession(app('session.store'));
        session()->put('_old_input', ['panel_sections' => ['network' => '1', 'overview' => '0']]);
        $sections = array_column($this->composed('admin.products.partials.panel-sections', ['product' => $product])['panelSectionModules']['panelfake'], null, 'key');
        $this->assertTrue($sections['network']['enabled']);
        $this->assertFalse($sections['overview']['enabled']);

        $this->assertSame(['panelSections', 'panelSections'], array_column(PanelFakeModule::$calls, 'method'), 'The form never calls a server.');
    }

    /**
     * Render a partial and give its data, with what its composer added.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function composed(string $view, array $data): array
    {
        $partial = view($view, $data);
        $partial->render();

        return $partial->getData();
    }
}
