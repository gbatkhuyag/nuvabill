<?php

namespace Tests\Feature\Billing;

use App\Billing\ResourceKinds;
use App\Billing\ResourceOptions;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Resources\ResourceLimit;
use App\Models\Client;
use App\Models\Product;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * The resource catalog: the sliders a product offers, the steps a new order may choose and their
 * price, and the range an existing service may change to. Store pages never ask a server.
 */
class ResourceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private ResourceOptions $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->setSettings(['billing.currency' => 'USD']);
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        PanelFakeModule::reset();
        $this->catalog = app(ResourceOptions::class);
    }

    public function test_the_store_gets_active_priced_options_with_their_prices_per_cycle(): void
    {
        $product = Product::factory()->priced(1000)->create(['server_module' => 'panelfake']);
        ResourceOption::factory()->priced(200, setup: 50)->priced(2000, cycle: BillingCycle::Annually)->priced(250, 'EUR')
            ->create(['product_id' => $product->id, 'included' => 4096, 'default_steps' => 2, 'restarts' => true, 'description' => 'For busy sites']);
        ResourceOption::factory()->kind('backups', 1)->priced(300)->create(['product_id' => $product->id, 'sort_order' => 1]);
        ResourceOption::factory()->kind('cpu', 1)->retired()->priced(500)->create(['product_id' => $product->id]);
        ResourceOption::factory()->kind('disk', 10)->priced(100, 'EUR')->create(['product_id' => $product->id]);

        $sliders = $this->catalog->forStore($product, 'USD');

        $this->assertSame(['ram', 'backups'], array_column($sliders, 'kind'));
        $this->assertSame([
            'kind' => 'ram', 'label' => 'RAM', 'description' => 'For busy sites', 'unit' => 'MB', 'step' => 1024, 'included' => 4096,
            'toggle' => false, 'ladder' => null, 'restarts' => true, 'note' => 'Takes effect after a restart.', 'currency' => 'USD',
            'prices' => ['monthly' => ['step' => 200, 'setup' => 50], 'annually' => ['step' => 2000, 'setup' => 0]],
            'min' => 0, 'max' => 8, 'value' => 2, 'stock_left' => null,
        ], $sliders[0]);
        $this->assertTrue($sliders[1]['toggle']);
        $this->assertSame(['ram', 'disk'], array_column($this->catalog->forStore($product, 'EUR'), 'kind'));
        $this->assertSame([], PanelFakeModule::calls(), 'Store pages never ask the module.');
    }

    public function test_stock_lowers_the_most_a_client_can_choose(): void
    {
        $product = Product::factory()->priced(1000)->create();
        $option = ResourceOption::factory()->kind('ipv4', 1)->priced(300)->create(['product_id' => $product->id, 'max_steps' => 4, 'stock' => 5]);
        $service = Service::factory()->create(['product_id' => $product->id, 'client_id' => $this->client(), 'status' => ServiceStatus::Active]);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $option->id, 'steps' => 3]);

        $slider = $this->catalog->forStore($product, 'USD')[0];

        $this->assertSame([2, 2], [$slider['max'], $slider['stock_left']]);
    }

    public function test_an_order_may_choose_only_offered_kinds_inside_their_range(): void
    {
        $product = Product::factory()->priced(1000)->create();
        ResourceOption::factory()->priced(200)->create(['product_id' => $product->id, 'min_steps' => 1, 'default_steps' => 1]);
        ResourceOption::factory()->kind('ipv4', 1)->priced(300)->create(['product_id' => $product->id, 'max_steps' => 2]);
        ResourceOption::factory()->kind('cpu', 1)->priced(5000, cycle: BillingCycle::Annually)->create(['product_id' => $product->id]);
        $other = ResourceOption::factory()->kind('disk', 10)->priced(100)->create();

        $this->assertSame(['ram' => 3, 'ipv4' => 2], $this->catalog->validateForOrder($product, BillingCycle::Monthly, 'USD', ['ram' => '3', 'ipv4' => 2]));
        $this->assertSame(['ram' => 1], $this->catalog->validateForOrder($product, BillingCycle::Monthly, 'USD', []), 'Kinds not sent get their default.');
        $this->assertSame(['ram' => 1], $this->catalog->validateForOrder($product, BillingCycle::Monthly, 'USD', ['cpu' => '0', 'ipv4' => false]), 'A hidden switch posts 0.');

        $refused = [
            [['ram' => 9], 'resources.ram', 'Choose between 1 and 8 for RAM.'],
            [['ram' => -1], 'resources.ram', 'Choose between 1 and 8 for RAM.'],
            [['ram' => '2.5'], 'resources.ram', 'Choose between 1 and 8 for RAM.'],
            [['ram' => 'abc'], 'resources.ram', 'Choose between 1 and 8 for RAM.'],
            [['ram' => 0], 'resources.ram', 'Choose between 1 and 8 for RAM.'],
            [['cpu' => 1], 'resources.cpu', 'This option is not offered with Monthly.'],
            [['disk' => 1], 'resources', 'We do not know the option disk.'],
            [['Disk<b>' => 1], 'resources', 'We do not know the option diskb.'],
            [array_fill_keys(array_map(fn (int $i): string => "kind_{$i}", range(1, 21)), 1), 'resources', 'Choose at most 20 extras.'],
        ];

        foreach ($refused as [$input, $key, $message]) {
            try {
                $this->catalog->validateForOrder($product, BillingCycle::Monthly, 'USD', $input);
                $this->fail('Refused: '.json_encode($input));
            } catch (ValidationException $exception) {
                $this->assertSame([$message], $exception->errors()[$key] ?? null, json_encode($input));
            }
        }

        $this->assertNotNull($other->fresh());
    }

    public function test_cart_lines_are_priced_from_the_database_and_kept_inside_the_range(): void
    {
        $product = Product::factory()->priced(1000)->create();
        ResourceOption::factory()->priced(200, setup: 50)->create(['product_id' => $product->id]);
        ResourceOption::factory()->kind('backups', 1)->priced(300)->create(['product_id' => $product->id, 'sort_order' => 1]);
        ResourceOption::factory()->kind('cpu', 1)->retired()->priced(500)->create(['product_id' => $product->id]);

        $lines = $this->catalog->cartLines($product, BillingCycle::Monthly, 'USD', ['ram' => 3, 'backups' => 1, 'cpu' => 2, 'gone' => 1]);

        $this->assertSame([
            ['kind' => 'ram', 'option_id' => $lines[0]['option_id'], 'label' => 'RAM', 'steps' => 3, 'step_size' => 1024, 'unit' => 'MB', 'units' => 3072, 'text' => 'RAM +3 GB', 'price' => 600, 'setup_fee' => 150],
            ['kind' => 'backups', 'option_id' => $lines[1]['option_id'], 'label' => 'Backups', 'steps' => 1, 'step_size' => 1, 'unit' => '', 'units' => 1, 'text' => 'Backups', 'price' => 300, 'setup_fee' => 0],
        ], $lines);

        $this->assertSame(8, $this->catalog->cartLines($product, BillingCycle::Monthly, 'USD', ['ram' => 50])[0]['steps'], 'A cart made before the most was lowered is clamped.');
        $this->assertSame([], $this->catalog->cartLines($product, BillingCycle::Annually, 'USD', ['ram' => 2]));
    }

    public function test_a_service_may_change_inside_its_options_and_the_servers_live_limits(): void
    {
        $product = Product::factory()->priced(1000)->create(['server_module' => 'panelfake']);
        $ram = ResourceOption::factory()->priced(200)->create(['product_id' => $product->id, 'max_steps' => 8]);
        $disk = ResourceOption::factory()->kind('disk', 10)->priced(100)->create(['product_id' => $product->id]);
        $ipv4 = ResourceOption::factory()->kind('ipv4', 1)->priced(300)->create(['product_id' => $product->id, 'client_can_add' => false, 'max_extra' => 4]);
        $service = Service::factory()->create(['product_id' => $product->id, 'client_id' => $this->client(), 'status' => ServiceStatus::Active, 'billing_cycle' => BillingCycle::Monthly, 'currency' => 'USD']);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $ram->id, 'steps' => 2, 'recurring_amount' => 400]);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $disk->id, 'steps' => 1, 'recurring_amount' => 100]);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $ipv4->id, 'steps' => 1, 'recurring_amount' => 300]);
        // A row whose option is gone stays listed, so it can still go down.
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => null, 'kind' => 'ipv6', 'unit' => 'IP', 'step_size' => 1, 'steps' => 2, 'recurring_amount' => 200]);
        PanelFakeModule::$behaviour['limits'] = ['ram' => new ResourceLimit(null, 4096, 'The node has room for 4 GB more.')];

        $sliders = collect($this->catalog->forService($service))->keyBy('kind');

        $this->assertSame([0, 4, 2, true, true, 'The node has room for 4 GB more.'], [$sliders['ram']['min'], $sliders['ram']['max'], $sliders['ram']['current'], $sliders['ram']['can_add'], $sliders['ram']['can_remove'], $sliders['ram']['limit_reason']]);
        $this->assertSame([1, 8, false], [$sliders['disk']['min'], $sliders['disk']['max'], $sliders['disk']['can_remove']], 'Disk only grows.');
        $this->assertSame([0, 1, false], [$sliders['ipv4']['min'], $sliders['ipv4']['max'], $sliders['ipv4']['can_add']], 'Adding is off for this option.');
        $this->assertSame([0, 2, true, []], [$sliders['ipv6']['min'], $sliders['ipv6']['max'], $sliders['ipv6']['retired'], $sliders['ipv6']['prices']]);
        $this->assertSame(['monthly' => ['step' => 200, 'setup' => 0]], $sliders['ram']['prices']);

        // Staff may go from 0 up to what the module allows.
        $staff = collect($this->catalog->forService($service, staff: true))->keyBy('kind');
        $this->assertSame([0, 4], [$staff['ipv4']['min'], $staff['ipv4']['max']]);
        $this->assertSame([0, 4], [$staff['ram']['min'], $staff['ram']['max']], 'The live limit holds for staff too.');
    }

    public function test_units_read_the_same_everywhere(): void
    {
        $this->assertSame('1.5 GB', ResourceKinds::format('MB', 1536));
        $this->assertSame('2 TB', ResourceKinds::format('GB', 2000));
        $this->assertSame('2', ResourceKinds::format('core', 2));
        $this->assertSame('CPU cores +2', ResourceKinds::describe('CPU cores', 'core', 2));
        $this->assertSame('RAM +2 GB', ResourceKinds::describe('RAM', 'MB', 2048, toggle: true), 'A one-step RAM option still says how much.');
        $this->assertSame('Backups', ResourceKinds::describe('Backups', '', 1, toggle: true));
        $this->assertSame('Server size: CX33', ResourceKinds::describe('Server size', '', 1, ['CX23', 'CX33'], 1));
    }

    public function test_staff_price_in_the_billing_currency_first_then_the_ones_in_use(): void
    {
        $this->setSettings(['currency.rates' => ['IQD' => 1310, 'XXX' => 2]]);
        Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'currency' => 'EUR']);
        Product::factory()->priced(1000, currency: 'GBP')->create();

        $this->assertSame(['USD', 'EUR', 'GBP', 'IQD'], $this->catalog->currencies());
        $this->assertSame([BillingCycle::Monthly, BillingCycle::Annually], $this->catalog->cycles(Product::factory()->priced(1000, BillingCycle::Annually)->priced(1000)->priced(0, BillingCycle::Free)->create()));
    }

    private function client(): Client
    {
        return Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);
    }
}
