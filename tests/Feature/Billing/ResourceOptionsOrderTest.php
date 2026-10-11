<?php

namespace Tests\Feature\Billing;

use App\Billing\Cart;
use App\Billing\CartLine;
use App\Billing\OrderPlacer;
use App\Billing\RenewalGenerator;
use App\Billing\ResourceSoldOut;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Machine;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResourceOption;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Models\TaxRule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Extras (resource options) chosen with a new order: the sliders on the product page, the cart and
 * quick-order prices, the rows and invoice lines checkout makes, and the stock they hold. Prices
 * always come from the database; the client only sends a number of steps per kind.
 */
class ResourceOptionsOrderTest extends TestCase
{
    use RefreshDatabase;

    private Product $vps;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->setSettings(['billing.currency' => 'USD']);
        $this->vps = Product::factory()->priced(1000)->withoutDomain()->create(['name' => 'VPS 2']);
    }

    public function test_the_product_page_shows_a_labelled_range_for_each_active_priced_option(): void
    {
        $this->vps->prices()->create(['currency' => 'USD', 'billing_cycle' => BillingCycle::Annually, 'price' => 10000, 'setup_fee' => 0]);
        $this->option('ram', 1024, 200)->prices()->create(['currency' => 'USD', 'billing_cycle' => BillingCycle::Annually, 'price' => 2000, 'setup_fee' => 0]);
        $this->option('cpu', 1, 500, ['is_active' => false]);
        ResourceOption::factory()->kind('disk', 10)->priced(100, 'EUR')->create(['product_id' => $this->vps->id]);

        $this->get($this->productUrl())
            ->assertOk()
            ->assertSee('Customize your server')
            ->assertSee('<label class="range-label" for="rs-ram">', false)
            ->assertSee('id="rs-ram" class="range" type="range"', false)
            ->assertSee('name="resources[ram]"', false)
            ->assertSee('min="0" max="8" value="0"', false)
            ->assertSee('aria-describedby="rs-ram-help"', false)
            ->assertSee('Each step adds 1 GB. Price per step: $2.00/mo · $20.00/yr.')
            ->assertDontSee('name="resources[cpu]"', false)
            ->assertDontSee('name="resources[disk]"', false);
    }

    public function test_an_option_with_one_step_is_a_switch(): void
    {
        $this->option('backups', 1, 300, ['max_steps' => 1]);

        $this->get($this->productUrl())
            ->assertOk()
            ->assertSee('<input type="hidden" name="resources[backups]" value="0"', false)
            ->assertSee('id="rs-backups" class="switch-input" type="checkbox" role="switch"', false)
            ->assertSee('Price: $3.00/mo.')
            ->assertDontSee('type="range"', false);
    }

    public function test_the_store_page_calls_no_server(): void
    {
        Http::fake();
        $server = Server::factory()->create(['module' => 'virtualizor', 'hostname' => 'vz.example.test', 'port' => 4085]);
        $this->vps->update(['server_module' => 'virtualizor', 'server_id' => $server->id, 'module_config' => ['plan_id' => 1]]);
        $this->option('ram', 1024, 200);

        $this->get($this->productUrl())->assertOk()->assertSee('name="resources[ram]"', false);
        $this->postJson(route('store.api.quote'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ram' => 2]])->assertOk();
        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ram' => 2]])->assertRedirect(route('cart.show'));
        $this->get(route('cart.show'))->assertOk()->assertSee('RAM +2 GB');

        Http::assertNothingSent();
    }

    public function test_adding_to_the_cart_prices_the_steps_and_setup_fees(): void
    {
        $this->option('ram', 1024, 200, setup: 50);

        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ram' => 3]])
            ->assertRedirect(route('cart.show'));

        $line = app(Cart::class)->lines('USD')->sole();

        $this->assertSame(['ram'], array_column($line->resources, 'kind'));
        $this->assertSame(750, $line->resourcesTotal(), '3 steps at 2.00 plus 3 setup fees of 0.50.');
        $this->assertSame(1750, $line->dueToday());
        $this->assertSame(1600, $line->renewalAmount(), 'Setup fees are paid once.');
        $this->get(route('cart.show'))->assertOk()->assertSee('RAM +3 GB')->assertSee('$7.50')->assertSee('includes $1.50 setup')->assertSee('$17.50');
        $this->get(route('checkout.show'))->assertOk()->assertSee('+ <bdi>RAM +3 GB</bdi>', false)->assertSee('$17.50');
    }

    public function test_steps_outside_the_range_or_unknown_kinds_are_refused(): void
    {
        $this->option('ram', 1024, 200);
        ResourceOption::factory()->kind('disk', 10)->priced(100)->create(['product_id' => Product::factory()->priced(500)->create()->id]);
        $tooMany = array_fill_keys(array_map(fn (int $i): string => "kind_{$i}", range(1, 21)), 1);

        $refused = [
            [['ram' => 9], 'resources.ram', 'Choose between 0 and 8 for RAM.'],
            [['ram' => -1], 'resources.ram', 'Choose between 0 and 8 for RAM.'],
            [['ram' => 'abc'], 'resources.ram', 'Choose between 0 and 8 for RAM.'],
            [['ram' => ['1']], 'resources.ram', 'Choose between 0 and 8 for RAM.'],
            [['abc' => 1], 'resources', 'We do not know the option abc.'],
            [['disk' => 1], 'resources', 'We do not know the option disk.'],
            [$tooMany, 'resources', 'Choose at most 20 extras.'],
        ];

        foreach ($refused as [$resources, $key, $message]) {
            $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => $resources])
                ->assertSessionHasErrors([$key => $message]);

            $this->postJson(route('store.api.quote'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => $resources])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$key => $message]);
        }

        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => 'ram'])->assertSessionHasErrors('resources');
        $this->assertSame(0, app(Cart::class)->count());
    }

    public function test_an_option_without_a_price_for_the_cycle_is_refused(): void
    {
        $this->vps->prices()->create(['currency' => 'USD', 'billing_cycle' => BillingCycle::Annually, 'price' => 10000, 'setup_fee' => 0]);
        $this->option('ram', 1024, 200);

        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'annually', 'resources' => ['ram' => 2]])
            ->assertSessionHasErrors(['resources.ram' => 'This option is not offered with Yearly.']);

        // A slider hidden for that cycle still posts 0, which is fine.
        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'annually', 'resources' => ['ram' => 0]])
            ->assertRedirect(route('cart.show'));

        $this->assertSame([], app(Cart::class)->lines('USD')->sole()->resources);
    }

    public function test_checkout_makes_rows_and_invoice_lines(): void
    {
        $this->travelTo('2026-10-10');
        $this->setSettings(['tax.enabled' => true]);
        TaxRule::factory()->create(['name' => 'Sales tax', 'rate' => 1000]);
        $ram = $this->option('ram', 1024, 200, setup: 50);
        $backups = $this->option('backups', 1, 300, ['max_steps' => 1, 'sort_order' => 1]);
        $storage = Product::factory()->priced(500)->withoutDomain()->create(['name' => 'Backup space', 'taxable' => false]);
        $this->option('disk', 100, 150, product: $storage);
        $client = $this->client();

        $this->actingAs($client, 'web');
        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ram' => 2, 'backups' => 1]]);
        $this->post(route('cart.store'), ['product_id' => $storage->id, 'billing_cycle' => 'monthly', 'resources' => ['disk' => 1]]);
        $this->post(route('checkout.store'))->assertRedirect();

        $order = Order::query()->sole();
        $vps = $order->services()->where('product_id', $this->vps->id)->sole();
        $row = $vps->resources()->where('kind', 'ram')->sole();

        $this->assertSame([$ram->id, 'ram', 'MB', 1024, 2, 400, ServiceResourceOption::STATUS_ACTIVE], [$row->resource_option_id, $row->kind, $row->unit, $row->step_size, $row->steps, $row->recurring_amount, $row->status]);
        $this->assertSame([$backups->id, 1, 300], [$vps->resources()->where('kind', 'backups')->value('resource_option_id'), $vps->resources()->where('kind', 'backups')->value('steps'), (int) $vps->resources()->where('kind', 'backups')->value('recurring_amount')]);
        $this->assertSame(1000, $vps->recurring_amount, 'The plan price stays the plan price.');
        $this->assertSame(1800, $vps->first_payment_amount);
        $this->assertSame(1700, $vps->renewalTotal());

        $items = $order->invoice->items()->where('service_id', $vps->id)->orderBy('id')->get();
        $this->assertSame([InvoiceItem::TYPE_SERVICE, InvoiceItem::TYPE_RESOURCE, InvoiceItem::TYPE_RESOURCE, InvoiceItem::TYPE_RESOURCE], $items->pluck('type')->all());
        $this->assertSame('RAM +2 GB (10 Oct 2026 - 09 Nov 2026)', $items[1]->description);
        $this->assertSame([400, $row->id, '2026-10-10', '2026-11-09'], [$items[1]->amount, (int) $items[1]->service_resource_option_id, $items[1]->period_start->toDateString(), $items[1]->period_end->toDateString()]);
        $this->assertSame(['Setup fee - RAM +2 GB', 100, $row->id, null], [$items[2]->description, $items[2]->amount, (int) $items[2]->service_resource_option_id, $items[2]->period_start]);
        $this->assertSame(['Backups (10 Oct 2026 - 09 Nov 2026)', 300], [$items[3]->description, $items[3]->amount]);
        $this->assertTrue($items->every(fn (InvoiceItem $item): bool => $item->taxed), 'Extras follow their taxable product.');

        $storageLines = $order->invoice->items()->where('service_id', $order->services()->where('product_id', $storage->id)->value('id'))->get();
        $this->assertSame([false, false], $storageLines->pluck('taxed')->all(), 'Extras of an untaxed product are not taxed.');
        $this->assertSame(1800 + 650, $order->invoice->subtotal);
        $this->assertSame(180, $order->invoice->tax);
    }

    public function test_coupons_do_not_discount_resources(): void
    {
        $this->option('ram', 1024, 200);
        Coupon::factory()->create(['code' => 'WELCOME20', 'value' => 20]);
        $this->actingAs($this->client(), 'web');

        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ram' => 3]]);
        $this->post(route('cart.coupon'), ['code' => 'WELCOME20'])->assertSessionHasNoErrors();
        $this->post(route('checkout.store'))->assertRedirect();

        $invoice = Order::query()->sole()->invoice;
        $this->assertSame(200, Order::query()->sole()->discount, '20% of the plan only, not of the extras.');
        $this->assertSame(-200, (int) $invoice->items()->where('type', InvoiceItem::TYPE_DISCOUNT)->value('amount'));
        $this->assertSame(1400, $invoice->total);
    }

    public function test_one_time_resources_are_billed_once_and_never_renew(): void
    {
        $product = Product::factory()->priced(5000, BillingCycle::OneTime)->withoutDomain()->create(['name' => 'Lifetime box']);
        $this->option('ram', 1024, 1500, cycle: BillingCycle::OneTime, product: $product);
        $client = $this->client();

        $this->actingAs($client, 'web');
        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'onetime', 'resources' => ['ram' => 2]]);
        $this->post(route('checkout.store'))->assertRedirect();

        $service = Service::query()->sole();
        $line = InvoiceItem::query()->where('type', InvoiceItem::TYPE_RESOURCE)->sole();

        $this->assertSame([2, 0], [$service->resources()->sole()->steps, $service->resources()->sole()->recurring_amount]);
        $this->assertSame([3000, null], [$line->amount, $line->period_end]);
        $this->assertSame(8000, $service->first_payment_amount);
        $this->assertSame(0, $service->renewalTotal());

        $service->update(['status' => ServiceStatus::Active]);
        $this->assertSame(0, app(RenewalGenerator::class)->generate(now()->addYears(2)));
    }

    public function test_quick_order_quote_lists_resources_and_includes_them_in_the_renewal(): void
    {
        $this->option('ram', 1024, 200, setup: 50);
        $this->option('backups', 1, 300, ['max_steps' => 1, 'sort_order' => 1]);

        $this->postJson(route('store.api.quote'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ram' => 2, 'backups' => 1]])
            ->assertOk()
            ->assertJsonPath('lines.0.resources', ['RAM +2 GB', 'Backups'])
            ->assertJsonPath('lines.0.amount', '$18.00')
            ->assertJsonPath('total', 1800)
            ->assertJsonPath('renewal', '$17.00 monthly');
    }

    public function test_quick_order_places_an_order_with_resources(): void
    {
        $this->option('ram', 1024, 200);
        $client = $this->client();

        $this->actingAs($client, 'web')
            ->post(route('order.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ram' => '4']])
            ->assertRedirect(route('client.invoices.show', Order::query()->sole()->invoice));

        $service = Service::query()->sole();
        $this->assertSame(4, $service->resources()->sole()->steps);
        $this->assertSame(1800, $service->order->invoice->total);
        $this->assertSame(800, (int) $service->order->invoice->items()->where('type', InvoiceItem::TYPE_RESOURCE)->sum('amount'));
    }

    public function test_resource_stock_holds_at_checkout(): void
    {
        $ipv4 = $this->option('ipv4', 1, 300, ['stock' => 4, 'max_steps' => 4]);
        $holder = Service::factory()->create(['client_id' => $this->client('Raz', '')->id, 'product_id' => $this->vps->id]);
        ServiceResourceOption::factory()->create(['service_id' => $holder->id, 'resource_option_id' => $ipv4->id, 'steps' => 3, 'recurring_amount' => 900]);
        $client = $this->client();

        $this->actingAs($client, 'web');
        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ipv4' => 2]])->assertRedirect(route('cart.show'));
        $this->post(route('checkout.store'))
            ->assertRedirect(route('cart.show'))
            ->assertSessionHas('error', 'Only 1 more step of IPv4 addresses is left. Choose fewer.');

        $this->post(route('order.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ipv4' => 2]])
            ->assertSessionHasErrors(['resources.ipv4' => 'Only 1 more step of IPv4 addresses is left. Choose fewer.']);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, Service::query()->count());
        $this->assertSame(1, ServiceResourceOption::query()->count());
        $this->assertSame(0, Invoice::query()->count());

        // The one step that is left can still be bought.
        $this->post(route('order.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ipv4' => 1]])->assertRedirect();
        $this->assertSame(0, $ipv4->stockLeft());

        $this->expectException(ResourceSoldOut::class);
        $cart = app(Cart::class);
        $cart->clear();
        $cart->addProduct($this->vps, BillingCycle::Monthly, null, [], ['ipv4' => 1]);
        app(OrderPlacer::class)->place($client, $cart->lines('USD', $client));
    }

    public function test_a_checkout_locks_products_machines_options_then_services_before_it_writes(): void
    {
        $this->vps->update(['stock' => 5]);
        $ipv4 = $this->option('ipv4', 1, 300, ['stock' => 10]);
        $dedicated = Product::factory()->priced(9900)->withoutDomain()->create(['name' => 'Dedicated E-2388G', 'uses_machines' => true]);
        Machine::factory()->linkedTo($dedicated)->create();
        $client = $this->client();

        $cart = app(Cart::class);
        $cart->addProduct($this->vps, BillingCycle::Monthly, null, [], ['ipv4' => 2]);
        $cart->add($dedicated, BillingCycle::Monthly, null);

        $statements = $this->statementsOfFirstTransaction(fn () => app(OrderPlacer::class)->place($client, $cart->lines('USD', $client)));

        $this->assertSame(
            ['products', 'products', 'machines', 'resource_options', 'products', 'services', 'resource_options', 'service_resource_options'],
            $this->locksBeforeFirstWrite($statements),
        );
        $this->assertSame(8, $ipv4->stockLeft());
    }

    public function test_a_client_paying_in_another_currency_sees_that_price_and_unpriced_options_are_hidden(): void
    {
        $this->vps->prices()->create(['currency' => 'EUR', 'billing_cycle' => BillingCycle::Monthly, 'price' => 900, 'setup_fee' => 0]);
        $ram = $this->option('ram', 1024, 200);
        $ram->prices()->create(['currency' => 'EUR', 'billing_cycle' => BillingCycle::Monthly, 'price' => 180, 'setup_fee' => 0]);
        $this->option('cpu', 1, 500);
        $client = $this->client(currency: 'EUR');

        $this->actingAs($client, 'web')->get($this->productUrl())
            ->assertOk()
            ->assertSee('name="resources[ram]"', false)
            ->assertSee('Price per step: €1.80/mo.')
            ->assertDontSee('name="resources[cpu]"', false);

        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['cpu' => 1]])
            ->assertSessionHasErrors(['resources.cpu' => 'This option is not offered with Monthly.']);
        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ram' => 2]])
            ->assertRedirect(route('cart.show'));

        $line = app(Cart::class)->lines('EUR', $client)->sole();
        $this->assertSame([360, 1260], [$line->resources[0]['price'], $line->dueToday()]);
    }

    public function test_without_javascript_the_plain_form_posts_the_steps(): void
    {
        $this->option('ram', 1024, 200, ['default_steps' => 1]);
        $this->option('backups', 1, 300, ['max_steps' => 1, 'sort_order' => 1]);

        // A switch posts a hidden 0 and, when ticked, a 1 after it: PHP keeps the last one.
        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ram' => '3', 'backups' => '1']])
            ->assertRedirect(route('cart.show'));
        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'resources' => ['ram' => '0', 'backups' => '0']])
            ->assertRedirect(route('cart.show'));
        // A form without the sliders (an older order form) gets the first steps the store shows.
        $this->post(route('cart.store'), ['product_id' => $this->vps->id, 'billing_cycle' => 'monthly'])
            ->assertRedirect(route('cart.show'));

        $lines = app(Cart::class)->lines('USD');

        $this->assertSame(['RAM +3 GB', 'Backups'], array_column($lines[0]->resources, 'text'));
        $this->assertSame([], $lines[1]->resources);
        $this->assertSame(['RAM +1 GB'], array_column($lines[2]->resources, 'text'));
    }

    public function test_free_trial_orders_still_work_without_resources(): void
    {
        $this->option('ram', 1024, 200, ['default_steps' => 2, 'min_steps' => 1]);
        $client = $this->client();

        $line = new CartLine(index: 0, product: $this->vps, cycle: BillingCycle::Monthly, domain: null, price: 0);
        $order = app(OrderPlacer::class)->place($client, collect([$line]));

        $this->assertSame([], $line->resources);
        $this->assertSame(0, ServiceResourceOption::query()->count());
        $this->assertSame(0, $order->invoice->total);
        $this->assertSame(InvoiceStatus::Paid, $order->invoice->fresh()->status);
    }

    /**
     * An option of the product (the VPS by default) priced per step in USD.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function option(string $kind, int $step, int $price, array $attributes = [], int $setup = 0, BillingCycle $cycle = BillingCycle::Monthly, ?Product $product = null): ResourceOption
    {
        return ResourceOption::factory()->kind($kind, $step)->priced($price, 'USD', $cycle, $setup)
            ->create(['product_id' => ($product ?? $this->vps)->id, ...$attributes]);
    }

    private function client(string $first = 'Mer', string $last = 'Las', string $currency = 'USD'): Client
    {
        return Client::factory()->create(['first_name' => $first, 'last_name' => $last, 'company_name' => null, 'currency' => $currency]);
    }

    private function productUrl(): string
    {
        return route('store.product', [$this->vps->group, $this->vps]);
    }

    /**
     * The statements of the first database transaction the callback runs. On SQLite a lock is
     * written as a comment, so the order of locks can be read.
     *
     * @return list<string>
     */
    private function statementsOfFirstTransaction(callable $callback): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
            {
                protected function compileLock(Builder $query, $value)
                {
                    return $value === true ? '/* for update */' : '';
                }
            });
        }

        $statements = [];
        $began = 0;
        Event::listen(TransactionBeginning::class, function () use (&$began): void {
            $began++;
        });
        DB::listen(function (QueryExecuted $query) use (&$statements, &$began): void {
            if ($began === 1) {
                $statements[] = $query->sql;
            }
        });

        try {
            $callback();
        } finally {
            $connection->useDefaultQueryGrammar();
        }

        return $statements;
    }

    /**
     * The tables read under a lock, in order, until the first row is written.
     *
     * @param  list<string>  $statements
     * @return list<string>
     */
    private function locksBeforeFirstWrite(array $statements): array
    {
        $locks = [];

        foreach ($statements as $sql) {
            $verb = strtolower(strtok(ltrim($sql), ' '));

            if (in_array($verb, ['insert', 'update', 'delete'], true)) {
                break;
            }

            if ($verb === 'select' && str_contains(strtolower($sql), 'for update')) {
                preg_match('/\bfrom\s+[`"]?(\w+)/i', $sql, $table);
                $locks[] = $table[1] ?? $sql;
            }
        }

        return $locks;
    }
}
