<?php

namespace Tests\Feature\Panel;

use App\Extensions\ExtensionManager;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * Admin → product form → "Client panel": one switch per section the server module declares, saved
 * on the product. Clients see only the tabs staff left on.
 */
class ProductPanelSwitchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        PanelFakeModule::reset();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
    }

    public function test_the_form_shows_a_switch_for_each_declared_section_of_the_chosen_module(): void
    {
        $product = Product::factory()->priced()->create(['server_module' => 'panelfake', 'panel_sections' => ['graphs' => false]]);

        $html = $this->get(route('admin.products.edit', $product))->assertOk()
            ->assertSee('Client panel')
            ->assertSee('Choose what clients can do in their server panel.')
            ->getContent();

        preg_match_all('/<input id="f-panel-panelfake-([a-z0-9-]+)" type="checkbox" name="panel_sections\[\1\]" value="1"( checked)?\s*>/', $html, $switches);
        $this->assertSame(['overview', 'power', 'console', 'graphs', 'network', 'reinstall', 'settings', 'snapshots'], $switches[1]);
        $this->assertSame([' checked', ' checked', ' checked', '', ' checked', ' checked', ' checked', ''], $switches[2]);

        // An untick is posted as 0, and the card is only for the chosen module.
        $this->assertSame(8, substr_count($html, '<input type="hidden" name="panel_sections['));
        $this->assertStringContainsString('<template x-if="module === \'panelfake\'">', $html);
        $this->assertStringContainsString('Off unless you turn it on', $html);
        $this->assertSame(['panelSections'], array_values(array_unique(array_column(PanelFakeModule::$calls, 'method'))), 'The form never calls a server.');
    }

    public function test_saving_keeps_only_declared_sections_and_an_untick_is_false(): void
    {
        $product = Product::factory()->priced()->create(['server_module' => 'panelfake', 'slug' => 'fixture-plan']);

        $this->put(route('admin.products.update', $product), $this->productForm([
            'server_module' => 'panelfake',
            'panel_sections' => ['overview' => '1', 'graphs' => '0', 'snapshots' => '1', 'shell' => '1'],
        ]))->assertSessionHasNoErrors();

        $saved = $product->fresh()->panel_sections;
        $this->assertFalse($saved['graphs']);
        $this->assertTrue($saved['snapshots']);
        $this->assertTrue($saved['overview']);
        $this->assertArrayNotHasKey('shell', $saved);
        $this->assertFalse($product->fresh()->panelSectionEnabled('graphs', true));

        // The form shows the saved switches again.
        $html = $this->get(route('admin.products.edit', $product))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="panel_sections\[graphs\]" value="1"\s*>/', $html);
        $this->assertMatchesRegularExpression('/name="panel_sections\[snapshots\]" value="1" checked\s*>/', $html);
    }

    public function test_a_module_without_sections_stores_nothing_and_shows_no_card(): void
    {
        $product = Product::factory()->priced()->create(['server_module' => 'panelfake', 'slug' => 'fixture-plan', 'panel_sections' => ['graphs' => false]]);

        $this->put(route('admin.products.update', $product), $this->productForm([
            'server_module' => 'formpanel',
            'panel_sections' => ['overview' => '1'],
        ]))->assertSessionHasNoErrors();

        $this->assertNull($product->fresh()->panel_sections);

        // The card is hidden at first paint for a module without sections.
        $this->get(route('admin.products.edit', $product))->assertOk()
            ->assertSee('style="display: none"', false)
            ->assertDontSee('f-panel-formpanel', false);
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
            'auto_setup' => 'payment',
            'prices' => ['monthly' => ['enabled' => '1', 'price' => '20.00', 'setup_fee' => '0']],
        ];
    }
}
