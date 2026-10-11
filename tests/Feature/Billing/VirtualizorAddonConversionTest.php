<?php

namespace Tests\Feature\Billing;

use App\Billing\RenewalGenerator;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ResourceOption;
use App\Models\ResourceOptionPrice;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Models\ServiceResourceOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The 0.7.0 migration that moves Virtualizor resource add-ons (the "resource_addons" product JSON)
 * to resource options: options with the add-ons' prices, a resource row per kind for each service,
 * the same renewal totals, one product at a time, and nothing twice.
 */
class VirtualizorAddonConversionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2027_08_01_000010_move_virtualizor_resource_addons_to_resource_options.php';

    private Server $server;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setSettings(['billing.currency' => 'USD']);
        $this->server = Server::factory()->create(['module' => 'virtualizor', 'hostname' => 'vz.example.test', 'port' => 4085]);
        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null, 'email' => 'mer.las@example.test', 'currency' => 'USD']);
    }

    public function test_the_rapidnet_policy_becomes_four_sliders_with_the_same_renewal_totals(): void
    {
        $catalog = $this->rapidnetAddons();
        $backups = ProductAddon::factory()->create(['id' => 9, 'name' => 'Daily backups']);
        $json = '{"version":1,"ids":{"cpu":5,"ram":6,"disk":7,"ipv4":8}}';
        $vps2 = $this->vpsProduct('VPS 2', ['resource_addons' => $json]);
        $vps4 = $this->vpsProduct('VPS 4', ['resource_addons' => $json, 'ipv4_pool_id' => '3']);

        $monthly = $this->service($vps2, ['ram' => 700, 'ipv4' => 300], extra: [$backups->id => 200]);
        $raz = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null, 'email' => 'raz@example.test', 'currency' => 'IQD']);
        $yearly = $this->service($vps4, ['cpu' => 60000, 'disk' => 90000], client: $raz, cycle: BillingCycle::Annually, currency: 'IQD', status: ServiceStatus::Suspended);
        $twoRam = $this->service($vps2, ['ram' => 700], status: ServiceStatus::Pending);
        $twoRam->addons()->create(['product_addon_id' => 6, 'name' => 'Extra 2 GB RAM', 'recurring_amount' => 700, 'status' => ServiceAddon::STATUS_ACTIVE]);
        $ended = $this->service($vps2, ['ram' => 700], status: ServiceStatus::Terminated);
        $services = [$monthly, $yearly, $twoRam, $ended];
        $totals = array_map(fn (Service $service): int => $service->fresh()->renewalTotal(), $services);
        $invoices = $this->renewalInvoicesWithoutKeeping();
        $this->assertSame([[$this->client->id, 'USD', 3700], [$raz->id, 'IQD', 3400000]], $invoices);

        $this->migrate();

        // Four sliders per product, each with its add-on's prices in every currency and cycle.
        foreach ([$vps2, $vps4] as $product) {
            $options = ResourceOption::query()->where('product_id', $product->id)->orderBy('sort_order')->get();

            $this->assertSame(['cpu', 'ram', 'disk', 'ipv4'], $options->pluck('kind')->all());
            $this->assertSame([1, 2048, 40, 1], $options->pluck('step_size')->all());
            $this->assertSame(['core', 'MB', 'GB', 'IP'], $options->pluck('unit')->all());
            $this->assertSame([1, 1, 1, 1], $options->pluck('max_steps')->all());
            $this->assertSame([0, 1, 2, 3], $options->pluck('sort_order')->all());
            $this->assertSame([true, true, true, true], $options->pluck('is_active')->all());
            $this->assertSame([true, true, false, true], $options->pluck('can_shrink')->all());
            $this->assertSame([true, true, false, true], $options->pluck('client_can_remove')->all());
            $this->assertSame([true, true, false, false], $options->pluck('restarts')->all());
            $this->assertSame([64, 262144, 16384, 16], $options->pluck('max_extra')->all());
            $this->assertSame('Grow the partition inside your server to use the new space.', $options[2]->note);

            foreach ($options as $option) {
                $this->assertEqualsCanonicalizing(
                    $this->addonPrices($catalog[$option->kind]),
                    $option->prices->map(fn (ResourceOptionPrice $price): array => [$price->currency, $price->billing_cycle->value, $price->price, $price->setup_fee])->all(),
                );
            }

            $config = $product->fresh()->module_config;
            $this->assertArrayNotHasKey('resource_addons', $config);
            $this->assertSame($json, $config['resource_addons_converted']);
        }

        // Without a pool in the JSON, the product's own pool setting stays as it was.
        $this->assertArrayNotHasKey('ipv4_pool_id', $vps2->fresh()->module_config);
        $this->assertSame('3', $vps4->fresh()->module_config['ipv4_pool_id']);

        // Each service gets a row per kind with the add-ons' price, and the add-ons are marked moved.
        $this->assertSame(['ipv4' => [1, 300], 'ram' => [1, 700]], $this->rows($monthly));
        $this->assertSame(['cpu' => [1, 60000], 'disk' => [1, 90000]], $this->rows($yearly));
        $this->assertSame(['ram' => [2, 1400]], $this->rows($twoRam));
        $this->assertSame([], $this->rows($ended));
        $this->assertSame([ServiceAddon::STATUS_CONVERTED, ServiceAddon::STATUS_CONVERTED, ServiceAddon::STATUS_ACTIVE], $monthly->addons()->orderBy('id')->pluck('status')->all());
        $this->assertNotNull($monthly->addons()->where('status', ServiceAddon::STATUS_CONVERTED)->value('cancelled_at'));
        $this->assertSame([ServiceAddon::STATUS_ACTIVE], $ended->addons()->pluck('status')->all());
        $this->assertSame(['cpu' => 0, 'ram' => 2048, 'disk' => 0, 'ipv4' => 1], $monthly->fresh()->resourceExtras()->units);

        // The add-ons are no longer sold; others are.
        $this->assertSame([5 => false, 6 => false, 7 => false, 8 => false, 9 => true], ProductAddon::query()->orderBy('id')->pluck('is_visible', 'id')->all());

        // Every renewal costs exactly what it did.
        $this->assertSame($totals, array_map(fn (Service $service): int => $service->fresh()->renewalTotal(), $services));
        $this->assertSame($invoices, $this->renewalInvoicesWithoutKeeping());
        $this->assertSame(2, ActivityLog::query()->where('action', 'resource_options.converted')->count());
        $this->assertSame('Product VPS 2: 4 add-ons became sliders for 2 services. Check the prices and raise the maximum.', ActivityLog::query()->where('action', 'resource_options.converted')->where('subject_id', $vps2->id)->value('description'));
    }

    public function test_add_ons_of_different_sizes_get_a_common_step_and_an_unchecked_price_is_hidden(): void
    {
        $ram2 = $this->addon('Extra 2 GB RAM', ['USD' => [400, 0]]);
        $ram4 = $this->addon('Extra 4 GB RAM', ['USD' => [700, 0]]);
        $disk20 = $this->addon('Extra 20 GB disk', ['USD' => [333, 101], 'IQD' => [4333, 0]]);
        $disk30 = $this->addon('Extra 30 GB disk', ['USD' => [450, 0]]);
        $hiddenCpu = $this->addon('Extra CPU core', ['USD' => [500, 0]], visible: false);
        $iqdOnly = $this->addon('Extra IPv4 address', ['IQD' => [3900, 0]]);
        $product = $this->vpsProduct('VPS 8', ['resource_addons' => json_encode([
            'version' => 1,
            'ids' => [
                'ram' => [$ram2->id => 2048, $ram4->id => 4096],
                'disk' => [$disk20->id => 20, $disk30->id => 30],
                'cpu' => $hiddenCpu->id,
                'ipv4' => [$iqdOnly->id],
            ],
            'base' => ['ram' => 4096, 'disk' => 80],
            'public_pool_id' => 7,
        ])]);
        $big = $this->service($product, []);
        $big->addons()->create(['product_addon_id' => $ram4->id, 'name' => $ram4->name, 'recurring_amount' => 700, 'status' => ServiceAddon::STATUS_ACTIVE]);
        $big->addons()->create(['product_addon_id' => $disk30->id, 'name' => $disk30->name, 'recurring_amount' => 450, 'status' => ServiceAddon::STATUS_ACTIVE]);
        $before = $big->fresh()->renewalTotal();

        $this->migrate();

        $options = ResourceOption::query()->where('product_id', $product->id)->with('prices')->get()->keyBy('kind');

        // RAM: one add-on is exactly one step, so its prices are used as they are.
        $this->assertSame([2048, 3, 4096, true], [$options['ram']->step_size, $options['ram']->max_steps, $options['ram']->included, $options['ram']->is_active]);
        $this->assertSame([[400, 0]], $options['ram']->prices->map(fn (ResourceOptionPrice $price): array => [$price->price, $price->setup_fee])->all());

        // Disk: no add-on is one 10 GB step, so the smallest one's prices are scaled and staff check them first.
        $this->assertSame([10, 5, 80, false], [$options['disk']->step_size, $options['disk']->max_steps, $options['disk']->included, $options['disk']->is_active]);
        $this->assertEqualsCanonicalizing([['USD', 167, 51], ['IQD', 2167, 0]], $options['disk']->prices->map(fn (ResourceOptionPrice $price): array => [$price->currency, $price->price, $price->setup_fee])->all());

        // A hidden add-on, or one without a price in the default currency, gives a hidden option.
        $this->assertFalse($options['cpu']->is_active);
        $this->assertFalse($options['ipv4']->is_active);

        // The service keeps what it had, in steps of the common size, at the same price.
        $this->assertSame(['disk' => [3, 450], 'ram' => [2, 700]], $this->rows($big));
        $this->assertSame(['cpu' => 0, 'ram' => 4096, 'disk' => 30, 'ipv4' => 0], $big->fresh()->resourceExtras()->units);
        $this->assertSame($before, $big->fresh()->renewalTotal());

        // The JSON's IP pool becomes the product's pool for extra IPv4.
        $this->assertSame('7', $product->fresh()->module_config['ipv4_pool_id']);
    }

    public function test_an_invalid_policy_is_left_alone_and_logged(): void
    {
        $addon = $this->addon('Extra CPU core', ['USD' => [500, 0]]);
        $unknownKind = $this->vpsProduct('VPS 2', ['resource_addons' => '{"version":1,"ids":{"gpu":'.$addon->id.'}}']);
        $notJson = $this->vpsProduct('VPS 4', ['resource_addons' => 'not json']);
        $service = $this->service($unknownKind, []);
        $service->addons()->create(['product_addon_id' => $addon->id, 'name' => $addon->name, 'recurring_amount' => 500, 'status' => ServiceAddon::STATUS_ACTIVE]);

        $this->migrate();

        $this->assertSame(0, ResourceOption::query()->count());
        $this->assertSame('{"version":1,"ids":{"gpu":'.$addon->id.'}}', $unknownKind->fresh()->module_config['resource_addons']);
        $this->assertSame('not json', $notJson->fresh()->module_config['resource_addons']);
        $this->assertSame([ServiceAddon::STATUS_ACTIVE], $service->addons()->pluck('status')->all());
        $this->assertTrue($addon->fresh()->is_visible);
        $this->assertSame([
            [$unknownKind->id, 'Product VPS 2: its resource add-ons were not moved to sliders, so nothing was changed for it: the add-on JSON is not valid: "ids" only takes cpu, ram, disk and ipv4. Set up its resource options by hand.'],
            [$notJson->id, 'Product VPS 4: its resource add-ons were not moved to sliders, so nothing was changed for it: the add-on JSON is not valid: it must be a JSON object with "version":1. Set up its resource options by hand.'],
        ], ActivityLog::query()->where('action', 'resource_options.convert_failed')->orderBy('subject_id')->get()->map(fn (ActivityLog $log): array => [$log->subject_id, $log->description])->all());
    }

    public function test_running_the_conversion_twice_changes_nothing(): void
    {
        $this->rapidnetAddons();
        $product = $this->vpsProduct('VPS 2', ['resource_addons' => '{"version":1,"ids":{"cpu":5,"ram":6,"disk":7,"ipv4":8}}']);
        $this->vpsProduct('VPS 4', ['resource_addons' => '{"version":2}']);
        $service = $this->service($product, ['ram' => 700, 'cpu' => 500]);

        $this->migrate();
        $first = $this->snapshot();
        $this->migrate();

        $this->assertSame($first, $this->snapshot());
        $this->assertSame(['cpu' => [1, 500], 'ram' => [1, 700]], $this->rows($service));
        $this->assertSame(1, ActivityLog::query()->where('action', 'resource_options.convert_failed')->count());

        // Pretend mode only shows what would run, and runs nothing.
        $this->assertSame([], DB::pretend(fn () => $this->migration()->up()));
        $this->assertSame($first, $this->snapshot());
    }

    public function test_a_total_mismatch_rolls_back_only_that_product(): void
    {
        $this->rapidnetAddons();
        $json = '{"version":1,"ids":{"cpu":5,"ram":6,"disk":7,"ipv4":8}}';
        $handMade = $this->vpsProduct('VPS 2', ['resource_addons' => $json]);
        $fine = $this->vpsProduct('VPS 4', ['resource_addons' => $json]);
        // Staff already gave this service extra RAM by hand, next to its RAM add-on.
        $mixed = $this->service($handMade, ['ram' => 700, 'cpu' => 500]);
        $ram = ResourceOption::factory()->kind('ram', 1024)->create(['product_id' => $handMade->id]);
        ServiceResourceOption::factory()->create(['service_id' => $mixed->id, 'resource_option_id' => $ram->id, 'steps' => 1, 'recurring_amount' => 300]);
        $moved = $this->service($fine, ['ram' => 700]);
        $before = $mixed->fresh()->renewalTotal();

        $this->migrate();

        // The product with the mismatch is exactly as it was.
        $this->assertSame([$ram->id], ResourceOption::query()->where('product_id', $handMade->id)->pluck('id')->all());
        $this->assertSame($json, $handMade->fresh()->module_config['resource_addons']);
        $this->assertSame([ServiceAddon::STATUS_ACTIVE, ServiceAddon::STATUS_ACTIVE], $mixed->addons()->pluck('status')->all());
        $this->assertSame(['ram' => [1, 300]], $this->rows($mixed));
        $this->assertSame($before, $mixed->fresh()->renewalTotal());
        $this->assertStringContainsString("totals differ for service #{$mixed->id}", (string) ActivityLog::query()->where('action', 'resource_options.convert_failed')->where('subject_id', $handMade->id)->value('description'));

        // The other product moved; the add-ons it shares are hidden for both.
        $this->assertSame(4, ResourceOption::query()->where('product_id', $fine->id)->count());
        $this->assertSame(['ram' => [1, 700]], $this->rows($moved));
        $this->assertFalse(ProductAddon::query()->findOrFail(6)->is_visible);
    }

    /**
     * Run the conversion migration on the data the test made.
     */
    private function migrate(): void
    {
        $this->migration()->up();
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    /**
     * The resource add-ons of client.rapidnet.network: IDs 5 to 8, priced in USD and IQD, monthly and
     * yearly, by kind.
     *
     * @return array<string, ProductAddon>
     */
    private function rapidnetAddons(): array
    {
        $catalog = [];

        foreach ([5 => ['cpu', 'Extra CPU core', 500], 6 => ['ram', 'Extra 2 GB RAM', 700], 7 => ['disk', 'Extra 40 GB disk', 900], 8 => ['ipv4', 'Extra IPv4 address', 300]] as $id => [$kind, $name, $price]) {
            $catalog[$kind] = ProductAddon::factory()->create(['id' => $id, 'name' => $name]);

            foreach (['USD' => 1, 'IQD' => 130] as $currency => $rate) {
                $catalog[$kind]->prices()->create(['currency' => $currency, 'billing_cycle' => BillingCycle::Monthly, 'price' => $price * $rate, 'setup_fee' => $id === 8 ? 100 * $rate : 0]);
                $catalog[$kind]->prices()->create(['currency' => $currency, 'billing_cycle' => BillingCycle::Annually, 'price' => $price * $rate * 10, 'setup_fee' => 0]);
            }
        }

        return $catalog;
    }

    /**
     * An add-on with a monthly price per currency: currency => [price, setup fee].
     *
     * @param  array<string, array{0: int, 1: int}>  $prices
     */
    private function addon(string $name, array $prices, bool $visible = true): ProductAddon
    {
        $addon = ProductAddon::factory()->create(['name' => $name, 'is_visible' => $visible]);

        foreach ($prices as $currency => [$price, $setup]) {
            $addon->prices()->create(['currency' => $currency, 'billing_cycle' => BillingCycle::Monthly, 'price' => $price, 'setup_fee' => $setup]);
        }

        return $addon;
    }

    /**
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function addonPrices(ProductAddon $addon): array
    {
        return $addon->prices()->get()->map(fn ($price): array => [$price->currency, $price->billing_cycle->value, $price->price, $price->setup_fee])->all();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function vpsProduct(string $name, array $config): Product
    {
        return Product::factory()->priced(2500)->create([
            'name' => $name,
            'server_module' => 'virtualizor',
            'server_id' => $this->server->id,
            'module_config' => $config + ['virt' => 'kvm', 'os_id' => '100', 'plan_id' => '3'],
        ]);
    }

    /**
     * A service due in three days with the rapidnet add-ons of these kinds (kind => price), and
     * other add-ons (add-on ID => price).
     *
     * @param  array<string, int>  $addons
     * @param  array<int, int>  $extra
     */
    private function service(Product $product, array $addons, ?Client $client = null, BillingCycle $cycle = BillingCycle::Monthly, string $currency = 'USD', ServiceStatus $status = ServiceStatus::Active, array $extra = []): Service
    {
        $service = Service::factory()->create([
            'client_id' => ($client ?? $this->client)->id,
            'product_id' => $product->id,
            'server_id' => $this->server->id,
            'status' => $status,
            'billing_cycle' => $cycle,
            'currency' => $currency,
            'recurring_amount' => $currency === 'USD' ? 2500 : 3250000,
            'next_due_date' => today()->addDays(3),
            'domain' => 'vps'.$product->id.'.example.test',
        ]);
        $ids = ['cpu' => 5, 'ram' => 6, 'disk' => 7, 'ipv4' => 8];

        foreach ($addons as $kind => $price) {
            $service->addons()->create(['product_addon_id' => $ids[$kind], 'name' => $kind, 'recurring_amount' => $price, 'status' => ServiceAddon::STATUS_ACTIVE]);
        }

        foreach ($extra as $id => $price) {
            $service->addons()->create(['product_addon_id' => $id, 'name' => 'Other add-on', 'recurring_amount' => $price, 'status' => ServiceAddon::STATUS_ACTIVE]);
        }

        return $service;
    }

    /**
     * The service's active resource rows: kind => [steps, renewal price], by kind.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function rows(Service $service): array
    {
        return ServiceResourceOption::query()->where('service_id', $service->id)->where('status', ServiceResourceOption::STATUS_ACTIVE)->orderBy('kind')->get()
            ->mapWithKeys(fn (ServiceResourceOption $row): array => [$row->kind => [$row->steps, $row->recurring_amount]])
            ->all();
    }

    /**
     * The renewal invoices the nightly run would make now, as client, currency and total. Nothing
     * is kept: the run is rolled back.
     *
     * @return list<array{0: int, 1: string, 2: int}>
     */
    private function renewalInvoicesWithoutKeeping(): array
    {
        DB::beginTransaction();

        try {
            app(RenewalGenerator::class)->generate();

            return Invoice::query()->orderBy('client_id')->orderBy('currency')->get()
                ->map(fn (Invoice $invoice): array => [$invoice->client_id, $invoice->currency, $invoice->total])
                ->all();
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Everything the conversion touches, to compare two runs.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'options' => DB::table('resource_options')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'prices' => DB::table('resource_option_prices')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'rows' => DB::table('service_resource_options')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'addons' => DB::table('service_addons')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'products' => DB::table('products')->orderBy('id')->pluck('module_config', 'id')->all(),
            'visible' => DB::table('product_addons')->orderBy('id')->pluck('is_visible', 'id')->all(),
            'logs' => DB::table('activity_logs')->orderBy('id')->get(['action', 'subject_id', 'description'])->map(fn ($row): array => (array) $row)->all(),
        ];
    }
}
