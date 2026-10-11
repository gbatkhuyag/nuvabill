<?php

namespace Tests\Feature\Admin;

use App\Extensions\ExtensionManager;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * The old Virtualizor resource add-on JSON (module 1.x) that no product form shows any more: a
 * product save keeps it while the module stays, and staff remove it only with "Remove the old
 * add-on settings" on the Resource options page, which is logged.
 */
class OldAddonSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_JSON = '{"version":1,"ids":{"ram":6,"disk":7}}';

    private const CONVERTED_JSON = '{"version":1,"ids":{"cpu":5}}';

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        PanelFakeModule::reset();
        $this->setSettings(['billing.currency' => 'USD']);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
    }

    public function test_a_product_save_keeps_the_old_addon_settings_while_the_module_stays(): void
    {
        $product = $this->productWithOldAddons();

        $this->put(route('admin.products.update', $product), $this->productForm(['module_config' => ['plan' => 'vps-2']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['plan' => 'vps-2', 'resource_addons' => self::OLD_JSON, 'resource_addons_converted' => self::CONVERTED_JSON],
            $product->fresh()->module_config,
        );

        // The options page still warns, so nothing lifts the guard by accident.
        $this->get(route('admin.products.resources.index', $product))->assertOk()->assertSee('Remove the old add-on settings');

        // A posted value wins over the kept one.
        $this->put(route('admin.products.update', $product), $this->productForm(['module_config' => ['plan' => 'vps-2', 'resource_addons' => '{"version":1,"ids":{"ram":9}}']]))
            ->assertSessionHasNoErrors();
        $this->assertSame('{"version":1,"ids":{"ram":9}}', $product->fresh()->module_config['resource_addons']);

        // Another module starts from its own settings only.
        $this->put(route('admin.products.update', $product), $this->productForm(['server_module' => 'formpanel', 'module_config' => ['plan' => 'basic']]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['plan' => 'basic'], $product->fresh()->module_config);
    }

    public function test_staff_remove_the_old_addon_settings_with_a_confirmed_and_logged_action(): void
    {
        $product = $this->productWithOldAddons();

        $this->get(route('admin.products.resources.index', $product))
            ->assertOk()
            ->assertSee('This product still has old resource add-on settings that could not be moved to sliders.')
            ->assertSee('action="'.route('admin.products.resources.old-addons.destroy', $product).'"', false)
            ->assertSee('data-confirm="Remove the old add-on settings?', false)
            ->assertSee('name="_method" value="DELETE"', false);

        $this->delete(route('admin.products.resources.old-addons.destroy', $product))
            ->assertRedirect(route('admin.products.resources.index', $product))
            ->assertSessionHas('status', 'The old add-on settings were removed.');

        // Only the old JSON goes; the note of the earlier move and the module settings stay.
        $this->assertSame(['plan' => 'vps-2', 'resource_addons_converted' => self::CONVERTED_JSON], $product->fresh()->module_config);

        $log = ActivityLog::query()->where('action', 'product.resource_options_updated')->sole();
        $this->assertStringContainsString('old resource add-on settings removed', $log->description);
        $this->assertStringContainsString(self::OLD_JSON, $log->description);

        $this->get(route('admin.products.resources.index', $product))
            ->assertOk()
            ->assertDontSee('Remove the old add-on settings')
            ->assertSee('These options were made from the resource add-ons this product had before.');

        // A second press changes nothing.
        $this->delete(route('admin.products.resources.old-addons.destroy', $product))
            ->assertSessionHas('error', 'This product has no old add-on settings.');
        $this->assertSame(1, ActivityLog::query()->where('action', 'product.resource_options_updated')->count());
    }

    public function test_staff_without_products_manage_cannot_remove_the_old_addon_settings(): void
    {
        $product = $this->productWithOldAddons();
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view', 'services.manage'])->create(['name' => 'Raz']));

        $this->delete(route('admin.products.resources.old-addons.destroy', $product))->assertForbidden();

        $this->assertSame(self::OLD_JSON, $product->fresh()->module_config['resource_addons']);
    }

    private function productWithOldAddons(): Product
    {
        return Product::factory()->priced(1000)->create([
            'server_module' => 'panelfake',
            'slug' => 'fixture-plan',
            'module_config' => ['plan' => 'vps-2', 'resource_addons' => self::OLD_JSON, 'resource_addons_converted' => self::CONVERTED_JSON],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productForm(array $overrides): array
    {
        return $overrides + [
            'product_group_id' => ProductGroup::query()->value('id') ?? ProductGroup::factory()->create()->id,
            'name' => 'Fixture plan',
            'slug' => 'fixture-plan',
            'type' => 'server',
            'server_module' => 'panelfake',
            'auto_setup' => 'payment',
            'prices' => ['monthly' => ['enabled' => '1', 'price' => '20.00', 'setup_fee' => '0']],
        ];
    }
}
