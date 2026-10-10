<?php

namespace Tests\Feature\Hardware;

use App\Billing\OrderCanceller;
use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\Hardware\InventoryException;
use App\Provisioning\Hardware\MachineInventory;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * The life of a dedicated server machine: stock, reservation and release, the setup steps, and the
 * rules that a machine which held a service is never sold again before staff confirm its wipe,
 * also when its service ends through a mass update or is deleted.
 */
class MachineStockTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Client $client;

    private Admin $admin;

    private MachineInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->product = Product::factory()->priced()->withoutDomain()->create(['name' => 'Dedicated E-2388G', 'uses_machines' => true, 'module_config' => ['default_os' => 'Debian 12']]);
        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);
        $this->admin = Admin::factory()->create(['name' => 'Raz']);
        $this->inventory = app(MachineInventory::class);
    }

    public function test_unpaid_order_cancel_releases_the_reserved_machine(): void
    {
        $machine = Machine::factory()->linkedTo($this->product)->create(['name' => 'DS-101']);
        $this->actingAs($this->client, 'web');
        $this->post(route('cart.store'), ['product_id' => $this->product->id, 'billing_cycle' => 'monthly']);
        $this->post(route('checkout.store'))->assertRedirect();
        $order = Order::query()->latest('id')->firstOrFail();
        DB::transaction(fn (): Machine => $this->inventory->reserveFor($order->services()->sole()));
        $this->assertSame(0, $this->product->stockLeft());

        $this->assertSame(1, app(OrderCanceller::class)->cancelUnpaid(today()->addDays(7)));

        $machine->refresh();
        $this->assertSame(MachineStatus::Available, $machine->status);
        $this->assertNull($machine->service_id);
        $this->assertSame(1, $this->product->stockLeft());
        $this->assertSame(ServiceStatus::Cancelled, $order->services()->sole()->status);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_starts_with($mail->subjectLine, 'Wipe'));
    }

    public function test_manual_cancel_releases_a_reserved_machine_and_wipes_a_set_up_one(): void
    {
        $waiting = $this->service();
        $settingUp = $this->service();
        $running = $this->service(ServiceStatus::Active);
        $reserved = Machine::factory()->reservedFor($waiting)->linkedTo($this->product)->create(['name' => 'DS-101']);
        $provisioning = Machine::factory()->linkedTo($this->product)->create(['name' => 'DS-102']);
        $provisioning->forceFill(['status' => MachineStatus::Provisioning, 'service_id' => $settingUp->id])->save();
        $active = Machine::factory()->activeFor($running)->withIps('203.0.113.10', ['203.0.113.11'])->linkedTo($this->product)->create(['name' => 'DS-103']);
        MachineIp::query()->where('address', '203.0.113.11')->update(['service_id' => $running->id, 'assigned_at' => now()]);

        // A change that is rolled back frees nothing.
        try {
            DB::transaction(function () use ($running): void {
                $running->update(['status' => ServiceStatus::Terminated]);

                throw new RuntimeException('Rolled back.');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(MachineStatus::Active, $active->fresh()->status);

        $waiting->update(['status' => ServiceStatus::Cancelled]);
        $settingUp->update(['status' => ServiceStatus::Fraud]);
        $running->refresh()->update(['status' => ServiceStatus::Terminated]);

        $this->assertSame(MachineStatus::Available, $reserved->fresh()->status);
        $this->assertSame(MachineStatus::Wiping, $provisioning->fresh()->status);
        $this->assertSame($settingUp->id, $provisioning->fresh()->last_service_id);
        $this->assertNotNull($provisioning->fresh()->power_check_at, 'The scheduler switches it off once.');
        $this->assertSame(MachineStatus::Wiping, $active->fresh()->status);
        $this->assertNull(MachineIp::query()->where('address', '203.0.113.11')->value('service_id'), 'Its extra IP is free again.');
        $this->assertSame(1, $this->product->stockLeft(), 'Only the reserved machine is back on sale.');
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Wipe DS-102 before you sell it again');
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Wipe DS-103 before you sell it again');
    }

    public function test_the_sweep_fixes_machines_of_deleted_or_mass_updated_services(): void
    {
        $cancelled = $this->service();
        $deleted = $this->service(ServiceStatus::Active);
        $editedToSuspended = $this->service(ServiceStatus::Active);
        $editedToActive = $this->service(ServiceStatus::Suspended);
        $fine = $this->service(ServiceStatus::Active);

        $reserved = Machine::factory()->reservedFor($cancelled)->create(['name' => 'DS-101']);
        $orphan = Machine::factory()->activeFor($deleted)->create(['name' => 'DS-102']);
        $toSuspend = Machine::factory()->activeFor($editedToSuspended)->create(['name' => 'DS-103']);
        $toActivate = Machine::factory()->activeFor($editedToActive)->create(['name' => 'DS-104']);
        $toActivate->forceFill(['status' => MachineStatus::Suspended, 'power_check_at' => now()->addHour()])->save();
        $untouched = Machine::factory()->activeFor($fine)->create(['name' => 'DS-105']);

        // Mass updates and deletes skip model events.
        Service::query()->whereKey($cancelled->id)->update(['status' => ServiceStatus::Cancelled]);
        Service::query()->whereKey($editedToSuspended->id)->update(['status' => ServiceStatus::Suspended]);
        Service::query()->whereKey($editedToActive->id)->update(['status' => ServiceStatus::Active]);
        Service::query()->whereKey($deleted->id)->delete();

        $this->assertNull($orphan->fresh()->service_id, 'The foreign key lets go of a deleted service.');
        $this->assertSame(4, $this->inventory->sweep());

        $this->assertSame(MachineStatus::Available, $reserved->fresh()->status);
        $this->assertSame(MachineStatus::Wiping, $orphan->fresh()->status);
        $this->assertNotNull($orphan->fresh()->power_check_at);
        $this->assertSame(MachineStatus::Suspended, $toSuspend->fresh()->status);
        $this->assertNull($toSuspend->fresh()->power_check_at, 'A status staff set by hand never touches the power.');
        $this->assertSame(MachineStatus::Active, $toActivate->fresh()->status);
        $this->assertNull($toActivate->fresh()->power_check_at);
        $this->assertSame(MachineStatus::Active, $untouched->fresh()->status);
        $this->assertSame(2, ActivityLog::query()->where('action', 'machine.status_synced')->count());

        $this->assertSame(0, $this->inventory->sweep(), 'Nothing is left to fix.');
    }

    public function test_a_service_goes_through_reserve_setup_ready_and_activate(): void
    {
        $service = $this->service();
        Machine::factory()->linkedTo($this->product)->create(['name' => 'DS-101', 'rack' => 'A1', 'rack_unit' => 'U7']);
        Machine::factory()->linkedTo($this->product)->create(['name' => 'DS-102']);

        $machine = $this->inventory->assign($service);
        $this->assertSame(MachineStatus::Reserved, $machine->status);
        $this->assertSame('DS-101', $machine->name, 'The free linked machine with the lowest id.');

        $this->refused(fn () => $this->inventory->activate($machine), 'Not possible from Reserved.');

        $this->inventory->startProvisioning($machine, $service);
        $this->inventory->startProvisioning($machine, $service);
        $this->assertSame(MachineStatus::Provisioning, $machine->fresh()->status);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Install the OS on DS-101 for Mer Las' && str_contains($mail->bodyHtml, 'Debian 12'));
        Mail::assertSentCount(1);

        $this->refused(fn () => $this->inventory->activate($machine), 'Press Server is ready first.');

        $this->inventory->markInstalled($service, 'ds101.example.test', 'root', 'Long-Root-Password-1', $this->admin);
        $service->refresh();
        $this->assertSame('ds101.example.test', $service->domain);
        $this->assertSame('root', $service->username);
        $this->assertSame('Long-Root-Password-1', $service->password);
        $this->assertNotSame('Long-Root-Password-1', DB::table('services')->where('id', $service->id)->value('password'), 'Saved encrypted.');
        $this->assertNotNull($machine->fresh()->provisioned_at);

        $this->inventory->activate($machine);
        $this->assertSame(MachineStatus::Active, $machine->status);

        $this->inventory->suspend($machine, now()->addMinutes(10));
        $this->assertSame(MachineStatus::Suspended, $machine->fresh()->status);
        $this->assertNotNull($machine->fresh()->power_check_at);

        $this->inventory->unsuspend($machine);
        $this->assertSame(MachineStatus::Active, $machine->fresh()->status);
        $this->assertNull($machine->fresh()->power_check_at);

        foreach (['machine.assigned', 'machine.provisioning', 'machine.install_done', 'machine.activated', 'machine.suspended', 'machine.unsuspended'] as $action) {
            $this->assertSame(1, ActivityLog::query()->where('action', $action)->count(), $action);
        }

        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%Long-Root-Password-1%')->exists());
    }

    public function test_assigning_a_machine_follows_the_service_status(): void
    {
        $imported = $this->service(ServiceStatus::Active);
        $free = Machine::factory()->create(['name' => 'DS-101']);

        // An imported running service: straight to in use, no setup steps and no email.
        $this->inventory->assign($imported, $free, $this->admin);
        $this->assertSame(MachineStatus::Active, $free->fresh()->status);
        $this->assertNotNull($free->fresh()->provisioned_at);
        $this->refused(fn () => $this->inventory->assign($imported, Machine::factory()->create(['name' => 'DS-102']), $this->admin), 'Use Replace machine');

        // A waiting service that gets another machine gives its first one back.
        $waiting = $this->service();
        $old = Machine::factory()->reservedFor($waiting)->create(['name' => 'DS-103']);
        $new = Machine::factory()->create(['name' => 'DS-104']);
        $this->inventory->assign($waiting, $new, $this->admin);
        $this->assertSame(MachineStatus::Reserved, $new->fresh()->status);
        $this->assertSame(MachineStatus::Available, $old->fresh()->status);

        $this->refused(fn () => $this->inventory->assign($this->service(), $free, $this->admin), 'DS-101 is not free.');
        $this->refused(fn () => $this->inventory->assign($this->service(ServiceStatus::Cancelled), Machine::factory()->create(['name' => 'DS-105']), $this->admin), 'Only a pending, active or suspended service');
        $this->refused(fn () => $this->inventory->assign($this->service()), 'No free machine for this product.');
        Mail::assertNothingSent();
    }

    public function test_status_changes_never_skip_a_wipe(): void
    {
        $machine = Machine::factory()->wiping()->create(['name' => 'DS-101']);

        $this->refused(fn () => $this->inventory->setStatus($machine, MachineStatus::Available, $this->admin), 'Not possible from Needs wipe.');
        $this->refused(fn () => $this->inventory->setStatus($machine, MachineStatus::Wiping, $this->admin), 'Choose Available, Maintenance or Retired.');
        $this->refused(fn () => $this->inventory->confirmWiped($machine, Admin::factory()->withPermissions(['hardware.power'])->create(['name' => 'Raz']), MachineStatus::Available), 'You may not confirm wipes.');
        $this->assertSame(MachineStatus::Wiping, $machine->fresh()->status);

        $this->inventory->confirmWiped($machine, $this->admin, MachineStatus::Maintenance);
        $this->assertSame(MachineStatus::Maintenance, $machine->fresh()->status);
        $this->assertNotNull($machine->fresh()->wiped_at);
        $this->assertNull($machine->fresh()->power_check_at);
        $this->refused(fn () => $this->inventory->confirmWiped($machine, $this->admin, MachineStatus::Available), 'Not possible from Maintenance.');

        $this->inventory->setStatus($machine, MachineStatus::Available, $this->admin);
        $this->inventory->setStatus($machine, MachineStatus::Decommissioned, $this->admin);
        $this->assertSame(MachineStatus::Decommissioned, $machine->fresh()->status);
        $this->refused(fn () => $this->inventory->setStatus($machine, MachineStatus::Available, $this->admin), 'Not possible from Retired.');

        $held = Machine::factory()->activeFor($this->service(ServiceStatus::Active))->create(['name' => 'DS-102']);
        $this->refused(fn () => $this->inventory->setStatus($held, MachineStatus::Maintenance, $this->admin), 'Not possible from In use.');

        $log = ActivityLog::query()->where('action', 'machine.status_changed')->latest('id')->first();
        $this->assertSame('Machine DS-101: Available → Retired', $log?->description);
        $this->assertSame(1, ActivityLog::query()->where('action', 'machine.wiped')->count());
    }

    public function test_extra_ips_are_rented_lowest_first_and_freed_newest_first(): void
    {
        $service = $this->service(ServiceStatus::Active);
        $machine = Machine::factory()->activeFor($service)->withIps('203.0.113.10', ['203.0.113.11', '203.0.113.12', '203.0.113.13'])->create(['name' => 'DS-101']);

        $this->assertSame(['203.0.113.11', '203.0.113.12'], $this->inventory->syncExtraIps($service, 2)->pluck('address')->all());

        $this->travel(1)->minutes();
        $this->assertSame(['203.0.113.11', '203.0.113.12', '203.0.113.13'], $this->inventory->syncExtraIps($service, 3)->pluck('address')->all());

        $this->assertSame(['203.0.113.11', '203.0.113.12'], $this->inventory->syncExtraIps($service, 2)->pluck('address')->all(), 'The newest one is freed first.');

        try {
            $this->inventory->syncExtraIps($service, 5);
            $this->fail('Too few extra IPs are free.');
        } catch (InventoryException $exception) {
            $this->assertSame(2, $exception->missing);
            $this->assertSame('Not enough free extra IPs on DS-101. Add 2 more.', $exception->getMessage());
        }

        $this->assertSame(2, MachineIp::query()->where('service_id', $service->id)->count(), 'A refused change keeps what was rented.');
        $this->assertSame([], $this->inventory->syncExtraIps($service, 0)->all());
        $this->assertNull(MachineIp::query()->where('kind', MachineIp::KIND_PRIMARY)->value('service_id'));
        $this->assertSame(3, ActivityLog::query()->where('action', 'machine.ip_assigned')->count());
        $this->assertSame(3, ActivityLog::query()->where('action', 'machine.ip_released')->count());
        $this->assertSame(MachineStatus::Active, $machine->fresh()->status);
    }

    public function test_replace_moves_the_service_and_its_extra_ips(): void
    {
        $service = $this->service(ServiceStatus::Suspended);
        $old = Machine::factory()->activeFor($service)->withIps('203.0.113.10', ['203.0.113.11'])->create(['name' => 'DS-101']);
        $old->forceFill(['status' => MachineStatus::Suspended])->save();
        MachineIp::query()->where('address', '203.0.113.11')->update(['service_id' => $service->id, 'assigned_at' => now()]);
        $short = Machine::factory()->create(['name' => 'DS-102']);
        $new = Machine::factory()->withIps('198.51.100.10', ['198.51.100.11'])->create(['name' => 'DS-103']);

        $this->refused(fn () => $this->inventory->replace($service, $short, $this->admin), 'Not enough free extra IPs on DS-102. Add 1 more.');
        $this->assertSame(MachineStatus::Suspended, $old->fresh()->status);

        $this->inventory->replace($service, $new, $this->admin);

        $this->assertSame(MachineStatus::Suspended, $new->fresh()->status);
        $this->assertSame($service->id, $new->fresh()->service_id);
        $this->assertSame(MachineStatus::Wiping, $old->fresh()->status);
        $this->assertSame($service->id, $old->fresh()->last_service_id);
        $this->assertSame(['198.51.100.11'], MachineIp::query()->where('service_id', $service->id)->pluck('address')->all());
        $this->assertSame('Service #'.$service->id.' moved from DS-101 to DS-103', ActivityLog::query()->where('action', 'machine.replaced')->sole()->description);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Wipe DS-101 before you sell it again');
    }

    private function service(ServiceStatus $status = ServiceStatus::Pending): Service
    {
        return Service::factory()->create([
            'client_id' => $this->client->id,
            'product_id' => $this->product->id,
            'status' => $status,
        ]);
    }

    private function refused(Closure $change, string $message): void
    {
        try {
            $change();
            $this->fail("Should be refused: {$message}");
        } catch (InventoryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
