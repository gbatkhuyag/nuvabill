<?php

namespace Tests\Feature\Hardware;

use App\Billing\CartLine;
use App\Billing\OrderPlacer;
use App\Billing\SoldOut;
use App\Enums\BillingCycle;
use App\Enums\MachineStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Machine;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Checkout of a dedicated server sold from the hardware inventory: each line reserves the free
 * linked machine with the lowest id inside the order's transaction, or the whole order is sold
 * out and nothing is kept.
 */
class MachineCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Product $dedicated;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->dedicated = Product::factory()->priced(9900)->withoutDomain()->create(['name' => 'Dedicated E-2388G', 'uses_machines' => true]);
        $this->client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null, 'currency' => 'USD']);
    }

    public function test_checkout_reserves_the_lowest_free_machine(): void
    {
        Machine::factory()->linkedTo($this->dedicated)->create(['name' => 'DS-100'])->forceFill(['status' => MachineStatus::Maintenance])->save();
        $first = Machine::factory()->linkedTo($this->dedicated)->create(['name' => 'DS-101']);
        $second = Machine::factory()->linkedTo($this->dedicated)->create(['name' => 'DS-102']);

        $this->actingAs($this->client, 'web');
        $this->post(route('cart.store'), ['product_id' => $this->dedicated->id, 'billing_cycle' => 'monthly'])->assertRedirect(route('cart.show'));
        $this->post(route('checkout.store'))->assertRedirect();

        $service = Service::query()->sole();

        $this->assertSame([MachineStatus::Reserved, $service->id], [$first->fresh()->status, $first->fresh()->service_id]);
        $this->assertSame(MachineStatus::Available, $second->fresh()->status);
        $this->assertNull($service->server_id);
        $this->assertSame(1, $this->dedicated->stockLeft());
    }

    public function test_two_lines_take_two_machines_and_a_third_is_sold_out_with_nothing_kept(): void
    {
        $first = Machine::factory()->linkedTo($this->dedicated)->create(['name' => 'DS-101']);
        $second = Machine::factory()->linkedTo($this->dedicated)->create(['name' => 'DS-102']);
        $placer = app(OrderPlacer::class);

        try {
            $placer->place($this->client, collect([$this->line(0), $this->line(1), $this->line(2)]));
            $this->fail('Three servers from two machines should be sold out.');
        } catch (SoldOut $soldOut) {
            $this->assertSame(2, $soldOut->left);
        }

        $this->assertNothingKept();

        $order = $placer->place($this->client, collect([$this->line(0), $this->line(1)]));

        $this->assertSame(
            [$first->id, $second->id],
            Machine::query()->whereIn('service_id', $order->services->pluck('id'))->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(0, $this->dedicated->stockLeft());

        $this->expectException(SoldOut::class);
        $placer->place($this->client, collect([$this->line(0)]));
    }

    public function test_a_machine_shared_by_two_products_is_not_sold_twice(): void
    {
        $other = Product::factory()->priced(12900)->withoutDomain()->create(['name' => 'Dedicated E-2388G + 2 TB', 'uses_machines' => true]);
        $machine = Machine::factory()->linkedTo($this->dedicated, $other)->create(['name' => 'DS-101']);

        $this->actingAs($this->client, 'web');
        $this->post(route('cart.store'), ['product_id' => $this->dedicated->id, 'billing_cycle' => 'monthly'])->assertRedirect(route('cart.show'));
        $this->post(route('cart.store'), ['product_id' => $other->id, 'billing_cycle' => 'monthly'])->assertRedirect(route('cart.show'));

        $this->post(route('checkout.store'))
            ->assertRedirect(route('cart.show'))
            ->assertSessionHas('error', 'Dedicated E-2388G + 2 TB is sold out. Remove it from your cart.');

        $this->assertNothingKept();
        $this->assertSame([MachineStatus::Available, null], [$machine->fresh()->status, $machine->fresh()->service_id]);

        // Without the second server the order goes through.
        $this->delete(route('cart.destroy', 1))->assertRedirect(route('cart.show'));
        $this->post(route('checkout.store'))->assertRedirect();

        $this->assertSame([MachineStatus::Reserved, Service::query()->sole()->id], [$machine->fresh()->status, $machine->fresh()->service_id]);
        $this->assertSame(0, $other->stockLeft());
    }

    private function line(int $index): CartLine
    {
        return new CartLine(index: $index, product: $this->dedicated, cycle: BillingCycle::Monthly, domain: null, price: 9900);
    }

    private function assertNothingKept(): void
    {
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Service::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, Machine::query()->whereNotNull('service_id')->count());
    }
}
