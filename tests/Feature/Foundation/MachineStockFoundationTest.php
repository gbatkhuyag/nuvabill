<?php

namespace Tests\Feature\Foundation;

use App\Billing\SoldOut;
use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\Hardware\MachineInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Products sold from the hardware inventory count their stock from free linked machines, and an
 * order reserves the free machine with the lowest id under a lock, or fails as sold out.
 */
class MachineStockFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->withoutDomain()->create(['name' => 'Dedicated E-2388G', 'uses_machines' => true, 'stock' => 99]);
        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);
    }

    public function test_stock_is_the_number_of_free_linked_machines(): void
    {
        Machine::factory()->count(2)->linkedTo($this->product)->create();
        Machine::factory()->create();
        Machine::factory()->reservedFor($this->service())->linkedTo($this->product)->create();
        Machine::factory()->wiping()->linkedTo($this->product)->create();
        Machine::factory()->linkedTo($this->product)->create(['name' => 'DS-900'])->forceFill(['status' => MachineStatus::Maintenance])->save();

        $this->assertSame(2, $this->product->stockLeft());
        $this->assertSame(2, DB::transaction(fn (): ?int => $this->product->lockedStockLeft()));
        $this->assertTrue($this->product->isInStock());

        Machine::query()->update(['status' => MachineStatus::Maintenance->value]);

        $this->assertSame(0, $this->product->stockLeft());
        $this->assertFalse($this->product->isInStock());
    }

    public function test_an_order_reserves_the_free_machine_with_the_lowest_id_and_then_is_sold_out(): void
    {
        Machine::factory()->linkedTo($this->product)->create()->forceFill(['status' => MachineStatus::Maintenance])->save();
        $first = Machine::factory()->linkedTo($this->product)->create();
        $second = Machine::factory()->linkedTo($this->product)->create();
        $inventory = app(MachineInventory::class);

        $service = $this->service();
        $machine = DB::transaction(fn (): Machine => $inventory->reserveFor($service));

        $this->assertSame($first->id, $machine->id);
        $this->assertSame(MachineStatus::Reserved, $machine->fresh()->status);
        $this->assertSame($service->id, $machine->fresh()->service_id);
        $this->assertSame($first->id, $inventory->machineFor($service)?->id);
        $this->assertSame($first->id, $inventory->reserveFor($service)->id, 'A service keeps the machine it holds.');

        $log = ActivityLog::query()->where('action', 'machine.reserved')->sole();
        $this->assertSame('machine', $log->subject_type);
        $this->assertSame($this->client->id, $log->client_id);

        $this->assertSame($second->id, $inventory->reserveFor($this->service())->id);
        $this->assertSame(0, $this->product->stockLeft());

        $third = $this->service();

        try {
            DB::transaction(fn (): Machine => $inventory->reserveFor($third));
            $this->fail('The third order should be sold out.');
        } catch (SoldOut $soldOut) {
            $this->assertSame(0, $soldOut->left);
            $this->assertTrue($soldOut->product->is($this->product));
        }

        $this->assertNull($inventory->machineFor($third));
        $this->assertSame(2, Machine::query()->where('status', MachineStatus::Reserved->value)->count());
    }

    public function test_a_machine_shared_by_two_products_is_not_sold_twice(): void
    {
        $other = Product::factory()->withoutDomain()->create(['uses_machines' => true]);
        Machine::factory()->linkedTo($this->product, $other)->create();
        $inventory = app(MachineInventory::class);

        $inventory->reserveFor($this->service());

        $this->assertSame(0, $other->stockLeft());
        $this->expectException(SoldOut::class);
        $inventory->reserveFor($this->service($other));
    }

    public function test_cancelling_an_order_frees_a_reserved_machine_and_sends_a_set_up_one_to_wipe(): void
    {
        Mail::fake();
        $order = Order::factory()->create(['client_id' => $this->client->id]);
        $waiting = $this->service(order: $order);
        $settingUp = $this->service(order: $order);
        $running = $this->service(order: $order, status: ServiceStatus::Active);

        $reserved = Machine::factory()->reservedFor($waiting)->linkedTo($this->product)->create();
        $provisioning = Machine::factory()->withIps('203.0.113.10', ['203.0.113.11'])->linkedTo($this->product)->create(['name' => 'DS-101']);
        $provisioning->forceFill(['status' => MachineStatus::Provisioning, 'service_id' => $settingUp->id])->save();
        MachineIp::query()->where('address', '203.0.113.11')->update(['service_id' => $settingUp->id, 'assigned_at' => now()]);
        $active = Machine::factory()->activeFor($running)->linkedTo($this->product)->create();

        DB::transaction(fn () => app(MachineInventory::class)->releaseForOrder($order));

        $reserved->refresh();
        $this->assertSame(MachineStatus::Available, $reserved->status);
        $this->assertNull($reserved->service_id);
        $this->assertNull($reserved->last_service_id);

        $provisioning->refresh();
        $this->assertSame(MachineStatus::Wiping, $provisioning->status);
        $this->assertNull($provisioning->service_id);
        $this->assertSame($settingUp->id, $provisioning->last_service_id);
        $this->assertNotNull($provisioning->released_at);
        $this->assertNotNull($provisioning->power_check_at);
        $this->assertNull(MachineIp::query()->where('address', '203.0.113.11')->value('service_id'));

        $this->assertSame(MachineStatus::Active, $active->fresh()->status);
        $this->assertSame($running->id, $active->fresh()->service_id);

        // Back on sale: only the reserved one, as the other needs a wipe first.
        $this->assertSame(1, $this->product->stockLeft());
        $this->assertSame(2, ActivityLog::query()->where('action', 'machine.released')->count());
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Wipe DS-101 before you sell it again');
        Mail::assertSentCount(1);
    }

    public function test_products_without_machines_keep_their_stock_rules(): void
    {
        $plain = Product::factory()->create(['stock' => 3]);
        Machine::factory()->linkedTo($plain)->create();
        $this->service($plain);

        $this->assertSame(2, $plain->stockLeft());
        $this->assertSame(2, DB::transaction(fn (): ?int => $plain->lockedStockLeft()));
        $this->assertNull(Product::factory()->create()->stockLeft());
    }

    private function service(?Product $product = null, ?Order $order = null, ServiceStatus $status = ServiceStatus::Pending): Service
    {
        return Service::factory()->create([
            'client_id' => $this->client->id,
            'product_id' => ($product ?? $this->product)->id,
            'order_id' => $order?->id,
            'status' => $status,
        ]);
    }
}
