<?php

namespace Tests\Feature\Admin;

use App\Billing\ResourceOptions;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Models\ServiceResourceOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * Admin → a product → Resource options: the option list with its preview, the editor, the kind
 * snapshot and the shared price grid (also used by add-ons).
 */
class ResourceOptionsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        $this->setSettings(['billing.currency' => 'USD', 'currency.rates' => ['IQD' => 1310]]);
        $this->useFixtureModules();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
    }

    public function test_staff_without_products_manage_cannot_open_or_save_options(): void
    {
        $product = $this->vpsProduct();
        $option = $this->ramOption($product);
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view', 'services.manage'])->create(['name' => 'Raz']));

        $this->get(route('admin.products.resources.index', $product))->assertForbidden();
        $this->get(route('admin.products.resources.create', [$product, 'kind' => 'cpu']))->assertForbidden();
        $this->get(route('admin.products.resources.edit', [$product, $option]))->assertForbidden();
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'cpu']))->assertForbidden();
        $this->put(route('admin.products.resources.update', [$product, $option]), $this->form())->assertForbidden();
        $this->delete(route('admin.products.resources.destroy', [$product, $option]))->assertForbidden();
        $this->post(route('admin.products.resources.order', $product), ['option' => $option->id, 'action' => 'hide'])->assertForbidden();
        $this->postJson(route('admin.products.resources.base', $product))->assertForbidden();

        $this->assertSame(1, ResourceOption::query()->count());
        $this->assertTrue($option->fresh()->is_active);
    }

    public function test_the_list_shows_the_options_and_a_preview_of_what_clients_see(): void
    {
        $product = $this->vpsProduct();
        $this->ramOption($product, ['description' => 'For busy sites']);
        ResourceOption::factory()->kind('ipv4', 1)->priced(300)->priced(390000, 'IQD')->create(['product_id' => $product->id, 'max_steps' => 2, 'sort_order' => 2]);
        ResourceOption::factory()->kind('cpu', 1)->retired()->priced(500)->create(['product_id' => $product->id, 'sort_order' => 3]);

        $this->get(route('admin.products.resources.index', $product))
            ->assertOk()
            ->assertSee('Resource options')
            ->assertSee('1 GB steps · up to +8 GB · $2.00/mo a step')
            ->assertSee('No price for Yearly')
            ->assertSee('Hidden')
            ->assertSee('What clients see')
            // The preview is the store's own slider: a labelled range with its price per step.
            ->assertSee('<label class="range-label" for="pv-USD-ram">', false)
            ->assertSee('type="range"', false)
            ->assertSee('Each step adds 1 GB. Price per step: $2.00/mo.')
            ->assertSee('For busy sites')
            ->assertSee('id="pv-IQD-ipv4"', false)
            // A hidden option is not in the preview, and the add menu offers only kinds not added yet.
            ->assertDontSee('id="pv-USD-cpu"', false)
            ->assertSee('<option value="disk">', false)
            ->assertDontSee('<option value="ram">', false);

        $this->get(route('admin.products.edit', $product))->assertOk()->assertSee('Resource options')->assertSee('<span class="count">3</span>', false);
        $this->get(route('admin.products.index'))->assertOk()->assertSee('3 resource options');
    }

    public function test_kind_must_be_one_the_module_supports_and_unused_on_the_product(): void
    {
        $product = $this->vpsProduct();
        $this->ramOption($product);

        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'bandwidth']))
            ->assertSessionHasErrors(['kind' => 'Choose a kind this product can sell.']);
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'other_module_size']))
            ->assertSessionHasErrors('kind');
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'ram']))
            ->assertSessionHasErrors(['kind' => 'This product already has this option. Edit it instead.']);
        $this->get(route('admin.products.resources.create', [$product, 'kind' => 'bandwidth']))
            ->assertRedirect(route('admin.products.resources.index', $product));

        // A module whose settings are wrong says so in plain words, on the list and on save.
        PanelFakeModule::$behaviour['throw'] = ['resourceKinds'];
        $this->get(route('admin.products.resources.index', $product))->assertOk()->assertSee('The fake product settings are wrong.');
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'cpu']))
            ->assertSessionHasErrors(['kind' => 'The fake product settings are wrong.']);

        $this->assertSame(['ram'], ResourceOption::query()->pluck('kind')->all());

        // A module without the contract sells nothing; a product without a module sells the built-ins by hand.
        $other = Product::factory()->priced(1000)->create(['server_module' => 'formpanel']);
        $this->get(route('admin.products.resources.index', $other))->assertOk()->assertSee('This server module cannot change resources yet.');

        $manual = Product::factory()->priced(1000)->create();
        $this->get(route('admin.products.resources.index', $manual))->assertOk()->assertSee('Built-in kinds. Changes are set by staff by hand.')->assertSee('<option value="bandwidth">', false);
        $this->post(route('admin.products.resources.store', $manual), $this->form(['kind' => 'bandwidth', 'step_size' => '1000']))
            ->assertRedirect(route('admin.products.resources.index', $manual));
    }

    public function test_an_option_is_saved_with_a_copy_of_the_modules_kind(): void
    {
        $product = $this->vpsProduct();

        $this->get(route('admin.products.resources.create', [$product, 'kind' => 'ram']))
            ->assertOk()
            ->assertSee('New option: RAM')
            ->assertSee('<option value="GB" selected>', false)
            ->assertSee('id="pv-option"', false);

        $this->post(route('admin.products.resources.store', $product), $this->form([
            'kind' => 'ram',
            'step_size' => '2',
            'step_unit' => 'GB',
            'included' => '2048',
            'included_unit' => 'MB',
            'max_steps' => '4',
            'stock' => '20',
            'client_can_remove' => '1',
        ]))->assertRedirect(route('admin.products.resources.index', $product))->assertSessionHasNoErrors();

        $option = ResourceOption::query()->sole();
        $this->assertSame(['ram', 'MB', 2048, 2048, 4, 20], [$option->kind, $option->unit, $option->step_size, $option->included, $option->max_steps, $option->stock]);
        $this->assertTrue($option->restarts);
        $this->assertSame('Takes effect after a restart.', $option->note);
        $this->assertTrue($option->can_shrink);
        $this->assertNull($option->label, 'A built-in kind keeps its translated name.');
        $this->assertSame(200, $option->priceFor('USD', BillingCycle::Monthly)->price);
        $this->assertSame(1, ActivityLog::query()->where('action', 'product.resource_options_updated')->count());

        // Disk cannot shrink, so clients can never remove it, whatever is posted.
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'disk', 'step_size' => '10', 'client_can_remove' => '1']))->assertSessionHasNoErrors();
        $disk = ResourceOption::query()->where('kind', 'disk')->sole();
        $this->assertFalse($disk->can_shrink);
        $this->assertFalse($disk->client_can_remove);

        // A module's own kind keeps the module's name and its ladder; its steps are sizes of one.
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'panelfake_size', 'step_size' => '5', 'max_steps' => '2']))->assertSessionHasNoErrors();
        $size = ResourceOption::query()->where('kind', 'panelfake_size')->sole();
        $this->assertSame(['Server size', 1, ['Plan size', 'Bigger', 'Biggest'], 2], [$size->label, $size->step_size, $size->ladder, $size->max_extra]);
    }

    public function test_step_size_and_max_follow_the_kind_limits(): void
    {
        $product = $this->vpsProduct();

        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'ram', 'step_size' => '64', 'step_unit' => 'MB']))
            ->assertSessionHasErrors(['step_size' => 'Each step must add at least 128 MB.']);
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'ipv4', 'step_size' => '1', 'max_steps' => '5']))
            ->assertSessionHasErrors(['max_steps' => 'Most extra steps × step size is more than this server type allows (4).']);
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'panelfake_size', 'max_steps' => '3']))
            ->assertSessionHasErrors(['max_steps' => 'This kind has 2 sizes above the plan, so choose at most 2.']);
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'cpu', 'min_steps' => '3', 'default_steps' => '1']))
            ->assertSessionHasErrors(['default_steps' => 'Steps chosen at first must be between the fewest and the most.']);
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'cpu', 'min_steps' => '9', 'max_steps' => '4', 'default_steps' => '4']))
            ->assertSessionHasErrors(['min_steps' => 'The fewest steps cannot be more than the most steps.']);
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'cpu', 'max_steps' => '1001', 'stock' => '-1', 'step_size' => 'abc']))
            ->assertSessionHasErrors(['max_steps', 'stock', 'step_size']);

        PanelFakeModule::$behaviour['kinds'] = ['ram' => new ResourceKind('ram', 'RAM', 'MB', minStep: 256, stepMultiple: 256)];
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'ram', 'step_size' => '768', 'step_unit' => 'MB']))->assertSessionHasNoErrors();
        $this->assertSame(768, ResourceOption::query()->sole()->step_size);

        // A switch (one step at most) is saved as one step of one.
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'backups', 'step_size' => '50', 'max_steps' => '8']))->assertSessionHasErrors('kind');
        PanelFakeModule::reset();
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'backups', 'step_size' => '50', 'max_steps' => '8']))->assertSessionHasNoErrors();
        $backups = ResourceOption::query()->where('kind', 'backups')->sole();
        $this->assertSame([1, 1], [$backups->step_size, $backups->max_steps]);

        $this->assertSame(2, ResourceOption::query()->count());
    }

    public function test_prices_save_per_currency_and_an_empty_price_removes_it(): void
    {
        $product = Product::factory()->priced(1000)->priced(10000, BillingCycle::Annually)->create(['server_module' => 'panelfake']);
        Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'EUR']);

        $this->assertSame(['USD', 'EUR', 'IQD'], app(ResourceOptions::class)->currencies());

        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'cpu', 'prices' => [
            'USD' => ['monthly' => ['price' => '5', 'setup_fee' => '1.50'], 'annually' => ['price' => '50.00', 'setup_fee' => '']],
            'IQD' => ['monthly' => ['price' => '6550', 'setup_fee' => '0'], 'annually' => ['price' => '', 'setup_fee' => '']],
        ]]))->assertSessionHasNoErrors();

        $option = ResourceOption::query()->sole()->load('prices');
        $this->assertSame([500, 150], [$option->priceFor('USD', BillingCycle::Monthly)->price, $option->priceFor('USD', BillingCycle::Monthly)->setup_fee]);
        $this->assertSame(5000, $option->priceFor('USD', BillingCycle::Annually)->price);
        $this->assertSame(655000, $option->priceFor('IQD', BillingCycle::Monthly)->price);
        $this->assertNull($option->priceFor('IQD', BillingCycle::Annually));

        // An emptied price is removed; a currency that was not posted stays as it was.
        $this->put(route('admin.products.resources.update', [$product, $option]), $this->form(['prices' => [
            'USD' => ['monthly' => ['price' => '6', 'setup_fee' => '0'], 'annually' => ['price' => '', 'setup_fee' => '']],
        ]]))->assertSessionHasNoErrors();

        $option = $option->fresh('prices');
        $this->assertSame(600, $option->priceFor('USD', BillingCycle::Monthly)->price);
        $this->assertNull($option->priceFor('USD', BillingCycle::Annually));
        $this->assertSame(655000, $option->priceFor('IQD', BillingCycle::Monthly)->price);

        // A price in the billing currency is needed, and only known currencies and the product's cycles are taken.
        $this->put(route('admin.products.resources.update', [$product, $option]), $this->form(['prices' => ['USD' => ['monthly' => ['price' => '']]]]))
            ->assertSessionHasErrors(['prices' => 'Give a price in USD for at least one billing cycle.']);
        $this->put(route('admin.products.resources.update', [$product, $option]), $this->form(['prices' => ['USD' => ['monthly' => ['price' => '1']], 'GBP' => ['monthly' => ['price' => '1']]]]))
            ->assertSessionHasErrors('prices');
        $this->put(route('admin.products.resources.update', [$product, $option]), $this->form(['prices' => ['USD' => ['quarterly' => ['price' => '1'], 'monthly' => ['price' => '1']]]]))
            ->assertSessionHasErrors(['prices' => 'Prices can only be for the billing cycles this product is sold with.']);
        $this->put(route('admin.products.resources.update', [$product, $option]), $this->form(['prices' => ['USD' => ['monthly' => ['price' => '-1']]]]))
            ->assertSessionHasErrors('prices.USD.monthly.price');

        $this->assertSame(600, $option->fresh('prices')->priceFor('USD', BillingCycle::Monthly)->price);

        // The editor shows the grid with a tab per currency and the fill helpers.
        $this->get(route('admin.products.resources.edit', [$product, $option]))
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('name="prices[IQD][monthly][price]" value="6550.00"', false)
            ->assertSee('Fill other cycles from Monthly')
            ->assertSee('Fill from USD at today&#039;s rate', false);
    }

    public function test_step_size_and_kind_lock_and_delete_is_refused_once_services_use_the_option(): void
    {
        $product = $this->vpsProduct();
        $option = $this->ramOption($product);
        $unused = ResourceOption::factory()->kind('cpu', 1)->priced(500)->create(['product_id' => $product->id]);
        $service = Service::factory()->create(['product_id' => $product->id, 'client_id' => Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']), 'status' => ServiceStatus::Active]);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $option->id, 'steps' => 2, 'recurring_amount' => 400]);

        $this->put(route('admin.products.resources.update', [$product, $option]), $this->form(['step_size' => '2', 'step_unit' => 'GB']))
            ->assertSessionHasErrors(['step_size' => 'Step size and kind cannot change while services use this option. Make a new option instead.']);
        $this->put(route('admin.products.resources.update', [$product, $option]), $this->form(['kind' => 'cpu']))
            ->assertSessionHasErrors(['kind' => 'The kind of an option cannot change. Add a new option instead.']);

        // The rest can change, and the step size stays.
        $this->put(route('admin.products.resources.update', [$product, $option]), $this->form(['step_size' => '1024', 'step_unit' => 'MB', 'max_steps' => '12']))->assertSessionHasNoErrors();
        $this->assertSame([1024, 12, 'ram'], [$option->fresh()->step_size, $option->fresh()->max_steps, $option->fresh()->kind]);

        $this->get(route('admin.products.resources.edit', [$product, $option]))->assertOk()->assertSee('Services use this option, so its kind and step size are locked.');
        $this->get(route('admin.products.resources.index', $product))->assertOk()->assertSee('Used by 1 service');

        $this->delete(route('admin.products.resources.destroy', [$product, $option]))
            ->assertSessionHas('error', 'Clients have this option, so it cannot be deleted. Hide it instead.');
        $this->assertNotNull($option->fresh());

        $this->delete(route('admin.products.resources.destroy', [$product, $unused]))->assertRedirect(route('admin.products.resources.index', $product));
        $this->assertNull($unused->fresh());

        // An option of another product is not found inside this one.
        $elsewhere = $this->ramOption($this->vpsProduct());
        $this->get(route('admin.products.resources.edit', [$product, $elsewhere]))->assertNotFound();
        $this->delete(route('admin.products.resources.destroy', [$product, $elsewhere]))->assertNotFound();
    }

    public function test_options_can_be_reordered_and_hidden(): void
    {
        $product = $this->vpsProduct();
        $ram = $this->ramOption($product, ['sort_order' => 0]);
        $cpu = ResourceOption::factory()->kind('cpu', 1)->priced(500)->create(['product_id' => $product->id, 'sort_order' => 1]);
        $disk = ResourceOption::factory()->kind('disk', 10)->priced(100)->create(['product_id' => $product->id, 'sort_order' => 2]);
        $order = fn (): array => $product->resourceOptions()->pluck('kind')->all();

        $this->post(route('admin.products.resources.order', $product), ['option' => $disk->id, 'action' => 'up'])->assertRedirect(route('admin.products.resources.index', $product));
        $this->assertSame(['ram', 'disk', 'cpu'], $order());

        $this->post(route('admin.products.resources.order', $product), ['option' => $ram->id, 'action' => 'up']);
        $this->assertSame(['ram', 'disk', 'cpu'], $order(), 'The first cannot go higher.');

        $this->postJson(route('admin.products.resources.order', $product), ['ids' => [$cpu->id, $ram->id, $disk->id]])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(['cpu', 'ram', 'disk'], $order());

        $this->post(route('admin.products.resources.order', $product), ['ids' => [$cpu->id, $ram->id]])->assertSessionHas('error');
        $this->assertSame(['cpu', 'ram', 'disk'], $order());

        $this->post(route('admin.products.resources.order', $product), ['option' => $cpu->id, 'action' => 'hide']);
        $this->assertFalse($cpu->fresh()->is_active);
        $this->assertSame([], collect(app(ResourceOptions::class)->forStore($product->fresh(), 'USD'))->where('kind', 'cpu')->all());

        $this->post(route('admin.products.resources.order', $product), ['option' => $cpu->id, 'action' => 'show']);
        $this->assertTrue($cpu->fresh()->is_active);

        $this->post(route('admin.products.resources.order', $product), ['option' => $this->ramOption($this->vpsProduct())->id, 'action' => 'hide'])->assertSessionHas('error');
        $this->post(route('admin.products.resources.order', $product), ['option' => $cpu->id, 'action' => 'delete'])->assertSessionHasErrors('action');
    }

    public function test_read_from_server_uses_the_provisioner_and_hides_server_errors_from_html(): void
    {
        $product = $this->vpsProduct();

        $this->postJson(route('admin.products.resources.base', $product))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('base.ram', 2048)
            ->assertJsonPath('base.panelfake_speed', null);
        $this->assertCount(1, PanelFakeModule::calls('baseResources'));

        PanelFakeModule::$behaviour['throw'] = ['baseResources'];
        $response = $this->postJson(route('admin.products.resources.base', $product))
            ->assertStatus(422)
            ->assertJson(['message' => 'The server did not answer. Check the server and try again.']);
        $this->assertStringNotContainsString(PanelFakeModule::API_KEY, (string) $response->getContent());

        $this->postJson(route('admin.products.resources.base', Product::factory()->priced(1000)->create(['server_module' => 'formpanel'])))
            ->assertStatus(422)
            ->assertJson(['message' => 'This server module cannot sell resource options.']);

        $this->get(route('admin.products.resources.create', [$product, 'kind' => 'ram']))->assertOk()->assertSee('Read from server');
    }

    public function test_the_store_and_the_list_still_work_after_the_module_is_uninstalled(): void
    {
        $product = $this->vpsProduct();
        $this->post(route('admin.products.resources.store', $product), $this->form(['kind' => 'ram', 'step_size' => '1', 'step_unit' => 'GB', 'client_can_remove' => '1']))->assertSessionHasNoErrors();
        $option = ResourceOption::query()->sole();
        PanelFakeModule::reset();

        // The module is gone: an empty extensions folder.
        config(['nuvabill.extensions_path' => storage_path('framework/testing/no-extensions')]);
        app()->forgetInstance(ExtensionManager::class);

        $sliders = app(ResourceOptions::class)->forStore($product->fresh(), 'USD');
        $this->assertSame('ram', $sliders[0]['kind']);
        $this->assertTrue($sliders[0]['restarts'], 'Read from the copy saved with the option.');
        $this->assertSame('Takes effect after a restart.', $sliders[0]['note']);
        $this->assertSame([], PanelFakeModule::calls());

        $this->get(route('store.product', [$product->group, $product]))->assertOk();
        $this->get(route('admin.products.resources.index', $product))->assertOk()->assertSee('The server module &quot;panelfake&quot; is not installed.', false);

        // Prices can still be changed; the saved copy of the kind stays.
        $this->get(route('admin.products.resources.edit', [$product, $option]))->assertOk()->assertSee('The server module does not sell this kind now');
        $this->put(route('admin.products.resources.update', [$product, $option]), $this->form(['step_size' => '1', 'step_unit' => 'GB']))->assertSessionHasNoErrors();
        $this->assertTrue($option->fresh()->restarts);
    }

    public function test_the_addon_form_saves_prices_in_several_currencies(): void
    {
        $this->get(route('admin.product-addons.create'))->assertOk()->assertSee('name="prices[IQD][monthly][price]"', false);

        $this->post(route('admin.product-addons.store'), [
            'name' => 'Daily backups',
            'is_visible' => '1',
            'prices' => [
                'USD' => ['monthly' => ['price' => '2', 'setup_fee' => ''], 'annually' => ['price' => '', 'setup_fee' => '']],
                'IQD' => ['monthly' => ['price' => '2620', 'setup_fee' => '100']],
            ],
        ])->assertRedirect(route('admin.product-addons.index'));

        $addon = ProductAddon::query()->sole();
        $this->assertSame(200, $addon->priceFor('USD', BillingCycle::Monthly)->price);
        $this->assertSame([262000, 10000], [$addon->priceFor('IQD', BillingCycle::Monthly)->price, $addon->priceFor('IQD', BillingCycle::Monthly)->setup_fee]);
        $this->assertNull($addon->priceFor('USD', BillingCycle::Annually));

        // Posting only the dollar prices leaves the dinar prices alone.
        $this->put(route('admin.product-addons.update', $addon), [
            'name' => 'Daily backups',
            'prices' => ['USD' => ['monthly' => ['price' => '', 'setup_fee' => ''], 'annually' => ['price' => '20', 'setup_fee' => '0']]],
        ])->assertRedirect(route('admin.product-addons.index'));

        $addon = $addon->fresh('prices');
        $this->assertNull($addon->priceFor('USD', BillingCycle::Monthly));
        $this->assertSame(2000, $addon->priceFor('USD', BillingCycle::Annually)->price);
        $this->assertSame(262000, $addon->priceFor('IQD', BillingCycle::Monthly)->price);

        $this->get(route('admin.product-addons.edit', $addon))->assertOk()->assertSee('value="2620.00"', false);

        $this->post(route('admin.product-addons.store'), ['name' => 'Bad', 'prices' => ['GBP' => ['monthly' => ['price' => '1']]]])->assertSessionHasErrors('prices');
        $this->post(route('admin.product-addons.store'), ['name' => 'Bad', 'prices' => ['USD' => ['free' => ['price' => '1']]]])->assertSessionHasErrors('prices');
        $this->post(route('admin.product-addons.store'), ['name' => 'Bad', 'prices' => ['USD' => ['monthly' => ['price' => 'abc']]]])->assertSessionHasErrors('prices.USD.monthly.price');
        $this->assertSame(1, ProductAddon::query()->count());
    }

    public function test_add_ons_moved_to_resource_options_say_so_on_the_service_page(): void
    {
        $product = $this->vpsProduct();
        $service = Service::factory()->create(['product_id' => $product->id, 'client_id' => Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']), 'status' => ServiceStatus::Active]);
        ServiceAddon::query()->create(['service_id' => $service->id, 'name' => 'Extra RAM', 'recurring_amount' => 500, 'status' => ServiceAddon::STATUS_CONVERTED]);

        $this->get(route('admin.services.show', $service))->assertOk()->assertSee('Moved to resource options');
    }

    /**
     * Point the extension manager at the test modules: panelfake sells resources, formpanel does not.
     */
    private function useFixtureModules(): void
    {
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        PanelFakeModule::reset();
    }

    private function vpsProduct(): Product
    {
        return Product::factory()->priced(1000)->priced(10000, BillingCycle::Annually)->create(['server_module' => 'panelfake']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ramOption(Product $product, array $attributes = []): ResourceOption
    {
        return ResourceOption::factory()->priced(200)->create(['product_id' => $product->id, 'restarts' => true, ...$attributes]);
    }

    /**
     * A filled option form; RAM in GB by default, priced $2.00 a month.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'label' => '',
            'description' => '',
            'step_size' => '1',
            'step_unit' => 'GB',
            'included' => '',
            'min_steps' => '0',
            'max_steps' => '4',
            'default_steps' => '0',
            'stock' => '',
            'client_can_add' => '1',
            'client_can_remove' => '0',
            'is_active' => '1',
            'prices' => ['USD' => ['monthly' => ['price' => '2.00', 'setup_fee' => '0']]],
            ...$overrides,
        ];
    }
}
