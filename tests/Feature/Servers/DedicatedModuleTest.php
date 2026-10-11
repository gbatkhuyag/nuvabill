<?php

namespace Tests\Feature\Servers;

use App\Billing\CartLine;
use App\Billing\OrderPlacer;
use App\Billing\PaymentRecorder;
use App\Billing\PlanChanges;
use App\Billing\SoldOut;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Hardware\MachineInventory;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Nuvabill\Extensions\Dedicated\DedicatedModule;
use Tests\Feature\Hardware\UsesDedicatedServers;
use Tests\TestCase;

/**
 * The built-in dedicated server module: an order reserves a machine, payment asks staff to install
 * it, "Server is ready" activates the service with an email that never holds the password, and
 * suspend, unsuspend and terminate switch the power through the machine's BMC. A BMC that does not
 * answer never blocks billing: the scheduler keeps trying.
 */
class DedicatedModuleTest extends TestCase
{
    use RefreshDatabase, UsesDedicatedServers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDedicated();
    }

    public function test_paid_order_assigns_the_machine_and_waits_for_staff(): void
    {
        $machine = $this->machineFor(null, 'Off');
        $order = $this->order();
        $service = $order->services->sole();

        $this->assertSame([MachineStatus::Reserved, $service->id], [$machine->fresh()->status, $machine->fresh()->service_id]);
        $this->assertSame([], $this->mailsTo('staff@example.test', 'Install the OS on DS-101 for Mer Las'), 'Nothing to install before the payment.');

        $this->pay($order);

        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        $this->assertSame(MachineStatus::Provisioning, $machine->fresh()->status);
        $this->assertNull($machine->fresh()->provisioned_at);
        $this->assertCount(1, $this->mailsTo('staff@example.test', 'Install the OS on DS-101 for Mer Las'));
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('mer.las@example.test') && str_contains($mail->subjectLine, 'is ready'));
        $this->assertStringContainsString('waits for staff to finish the setup', ActivityLog::query()->where('action', 'service.setup_waiting')->sole()->description);
        $this->assertFalse(app(ExtensionManager::class)->serverModule('dedicated')->waitsForPayment($service->fresh()));
        Http::assertNothingSent();
    }

    public function test_server_is_ready_activates_the_service_and_emails_the_client(): void
    {
        $machine = $this->machineFor(null, 'On');
        $order = $this->order();
        $service = $order->services->sole();
        $this->pay($order);

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->post(route('admin.services.machine.ready', $service), ['hostname' => 'ds1.example.test', 'login_name' => 'admin', 'root_password' => 'Root-Pass-For-Mer-Las-1'])
            ->assertSessionHas('status', 'The server is ready.');

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(MachineStatus::Active, $machine->fresh()->status);
        $this->assertSame(['admin', 'Root-Pass-For-Mer-Las-1'], [$service->username, $service->password]);
        $this->assertStringNotContainsString('Root-Pass-For-Mer-Las-1', (string) DB::table('services')->where('id', $service->id)->value('password'));

        $ready = $this->mailsTo('mer.las@example.test', 'Your server Dedicated E-2388G is ready');
        $this->assertCount(1, $ready);
        $this->assertStringContainsString('203.0.113.10, 203.0.113.20', $ready[0]->bodyHtml);
        $this->assertStringContainsString('Login name: admin', strip_tags($ready[0]->bodyHtml));
        $this->assertStringContainsString('ds1.example.test', $ready[0]->bodyHtml);
        $this->assertStringContainsString('Rack room 1', $ready[0]->bodyHtml);
        $this->assertStringNotContainsString('Root-Pass-For-Mer-Las-1', $ready[0]->bodyHtml);
        $this->assertStringNotContainsString('DS-101', $ready[0]->bodyHtml);
        $this->assertStringNotContainsString('10.20.0.11', $ready[0]->bodyHtml);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Your Dedicated E-2388G is ready');
        $this->assertSame(0, ActivityLog::query()->where('description', 'like', '%Root-Pass%')->count());
    }

    public function test_unpaid_order_setup_waits_for_payment_then_runs_on_payment(): void
    {
        $this->product->update(['auto_setup' => AutoSetup::OnOrder]);
        $machine = $this->machineFor(null, 'Off');
        $order = $this->order();
        $service = $order->services->sole()->fresh();

        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame(MachineStatus::Reserved, $machine->fresh()->status, 'Nothing changes before the payment.');
        $this->assertTrue($service->module_data[DedicatedModule::WAITS_FOR_PAYMENT]);
        $this->assertTrue(app(Provisioner::class)->waitsForPayment($service));
        $this->assertStringContainsString('The order is not paid yet.', ActivityLog::query()->where('action', 'service.module_failed')->sole()->description);
        $this->assertSame([], $this->mailsTo('staff@example.test', 'Install the OS on DS-101 for Mer Las'));

        $this->pay($order);

        $this->assertSame(MachineStatus::Provisioning, $machine->fresh()->status);
        $this->assertFalse($service->fresh()->module_data[DedicatedModule::WAITS_FOR_PAYMENT]);
        $this->assertCount(1, $this->mailsTo('staff@example.test', 'Install the OS on DS-101 for Mer Las'));
    }

    public function test_setup_before_payment_asks_staff_at_once(): void
    {
        $this->product->update(['auto_setup' => AutoSetup::OnOrder, 'module_config' => ['setup_before_payment' => '1', 'login_name' => 'root']]);
        $machine = $this->machineFor(null, 'Off');

        $this->order();

        $this->assertSame(MachineStatus::Provisioning, $machine->fresh()->status);
        $this->assertCount(1, $this->mailsTo('staff@example.test', 'Install the OS on DS-101 for Mer Las'));
    }

    public function test_create_fails_clearly_without_a_free_machine(): void
    {
        $service = $this->dedicatedService(ServiceStatus::Pending);
        Machine::factory()->wiping()->linkedTo($this->product)->create(['name' => 'DS-109']);

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertSame('No free machine for this product. Add one under Hardware, or assign one on the service page.', $result->message);
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.module_failed')->exists());
    }

    public function test_a_machine_sold_again_after_a_wipe_still_waits_for_staff(): void
    {
        $old = $this->dedicatedService(ServiceStatus::Active);
        $machine = $this->machineFor($old, 'Off');
        $inventory = app(MachineInventory::class);
        $inventory->release($machine, 'terminated');
        $inventory->confirmWiped($machine, Admin::factory()->create(['name' => 'Raz']), MachineStatus::Available);
        $this->assertNotNull($machine->fresh()->provisioned_at, 'The last service was ready once.');

        $order = $this->order();
        $this->pay($order);

        $this->assertSame(ServiceStatus::Pending, $order->services->sole()->fresh()->status);
        $this->assertSame(MachineStatus::Provisioning, $machine->fresh()->status);
        $this->assertNull($machine->fresh()->provisioned_at);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Your server Dedicated E-2388G is ready');
    }

    public function test_suspend_sends_a_shutdown_and_the_scheduler_forces_off_after_the_wait(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');

        $result = app(Provisioner::class)->suspend($service, 'Overdue on payment');

        $this->assertTrue($result->success);
        $this->assertStringStartsWith('Suspended. A shutdown signal was sent.', $result->message);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
        $this->assertSame(MachineStatus::Suspended, $machine->fresh()->status);
        $this->assertTrue($machine->fresh()->power_check_at->between(now()->addMinutes(9), now()->addMinutes(11)));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'GracefulShutdown']);

        // The system ignored the signal and stays on.
        $this->bmcStates['10.20.0.11'] = 'On';
        $this->travel(5)->minutes();
        $this->artisan('nuvabill:hardware')->assertSuccessful();
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'ForceOff']);

        $this->travel(6)->minutes();
        $this->artisan('nuvabill:hardware')->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'ForceOff']);
        $this->assertSame('Off', $this->bmcStates['10.20.0.11']);
    }

    public function test_suspend_without_a_shutdown_signal_forces_off_after_the_wait(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');
        // This BMC can only switch the power on and force it off.
        $machine->forceFill(['bmc_reset_types' => ['On', 'ForceOff']])->save();

        $result = app(Provisioner::class)->suspend($service, 'Overdue on payment');

        $this->assertTrue($result->success);
        $this->assertSame('Suspended. The server is forced off after the wait time.', $result->message);
        $this->assertTrue($machine->fresh()->power_check_at->between(now()->addMinutes(9), now()->addMinutes(11)));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

        $this->travel(11)->minutes();
        $this->artisan('nuvabill:hardware')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'ForceOff']);
        $this->assertSame('Off', $this->bmcStates['10.20.0.11']);
        $this->assertSame([], $this->mailsTo('staff@example.test', 'DS-101: Switch it off by hand'));
    }

    public function test_suspend_without_power_control_asks_staff_to_switch_it_off(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');
        $machine->forceFill(['bmc_type' => 'none'])->save();

        $result = app(Provisioner::class)->suspend($service, 'Overdue on payment');

        $this->assertTrue($result->success);
        $this->assertSame('Marked suspended. This server has no power control, so switch it off by hand.', $result->message);
        $this->assertSame(MachineStatus::Suspended, $machine->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_suspend_works_while_the_bmc_is_down_and_power_is_retried(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, null);

        $result = app(Provisioner::class)->suspend($service, 'Overdue on payment');

        $this->assertTrue($result->success);
        $this->assertSame('Marked suspended. The server is turned off as soon as its BMC answers.', $result->message);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
        $this->assertSame(MachineStatus::Suspended, $machine->fresh()->status);
        $this->assertNotNull($machine->fresh()->power_check_at);
        $this->assertStringNotContainsString('bmc-test-password', (string) ActivityLog::query()->pluck('description')->implode(' '));

        // The BMC answers again: the scheduler switches the server off after the wait.
        $this->bmcStates['10.20.0.11'] = 'On';
        $this->travel(11)->minutes();
        $this->artisan('nuvabill:hardware')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'ForceOff']);
    }

    public function test_force_suspend_mode_powers_off_at_once(): void
    {
        $this->product->update(['module_config' => ['suspend_mode' => 'force', 'suspend_notify_staff' => '1']]);
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');

        $result = app(Provisioner::class)->suspend($service, 'Overdue on payment');

        $this->assertSame('Suspended. The server is powered off.', $result->message);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'ForceOff']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'GracefulShutdown']);
        $this->assertTrue($machine->fresh()->power_check_at->lte(now()), 'The scheduler checks that it is really off.');
        $this->assertCount(1, $this->mailsTo('staff@example.test', 'DS-101: Block the network port'));
    }

    public function test_unsuspend_powers_on_when_the_product_says_so(): void
    {
        $service = $this->dedicatedService(ServiceStatus::Suspended, ['suspended_at' => now(), 'suspension_reason' => 'Overdue on payment']);
        $machine = $this->machineFor($service, 'Off');
        $machine->forceFill(['status' => MachineStatus::Suspended, 'power_check_at' => now()->addHour()])->save();

        $result = app(Provisioner::class)->unsuspend($service);

        $this->assertSame('Active again. The server is starting.', $result->message);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame(MachineStatus::Active, $machine->fresh()->status);
        $this->assertNull($machine->fresh()->power_check_at);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'On']);

        // A product that keeps the power off when unsuspended.
        $manual = $this->dedicatedProduct(['unsuspend_power_on' => '0'], 'Dedicated E-2388G manual');
        $other = $this->dedicatedService(ServiceStatus::Suspended, ['suspended_at' => now(), 'suspension_reason' => 'Overdue on payment'], $manual);
        $second = $this->machineFor($other, 'Off', '10.20.0.12', [], 'DS-102', '203.0.113.30', []);
        $second->forceFill(['status' => MachineStatus::Suspended])->save();

        $this->assertSame('Active again.', app(Provisioner::class)->unsuspend($other)->message);
        $this->assertSame(MachineStatus::Active, $second->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '10.20.0.12') && $request->method() === 'POST');
    }

    public function test_terminate_powers_off_and_the_machine_needs_a_wipe(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On', extras: ['203.0.113.11']);
        app(MachineInventory::class)->syncExtraIps($service, 1);

        $result = app(Provisioner::class)->terminate($service);

        $this->assertSame('The machine is powered off and waits for a wipe.', $result->message);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
        $machine->refresh();
        $this->assertSame([MachineStatus::Wiping, null, $service->id], [$machine->status, $machine->service_id, $machine->last_service_id]);
        $this->assertNull(MachineIp::query()->where('address', '203.0.113.11')->value('service_id'), 'The extra IP is free again.');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'ForceOff']);
        $this->assertCount(1, $this->mailsTo('staff@example.test', 'Wipe DS-101 before you sell it again'));

        // Never sold again before the wipe is confirmed.
        $this->expectException(SoldOut::class);
        $this->order();
    }

    public function test_terminate_with_bmc_down_still_needs_a_wipe_and_retries_power(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, null);

        $result = app(Provisioner::class)->terminate($service);

        $this->assertTrue($result->success);
        $this->assertSame('The machine waits for a wipe. It is powered off as soon as its BMC answers.', $result->message);
        $this->assertSame(MachineStatus::Wiping, $machine->fresh()->status);
        $this->assertNotNull($machine->fresh()->power_check_at);

        $this->bmcStates['10.20.0.11'] = 'On';
        $this->travel(1)->minutes();
        $this->artisan('nuvabill:hardware')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'ForceOff']);
        $this->assertNull($machine->fresh()->power_check_at);
    }

    public function test_replace_machine_moves_the_service_and_wipes_the_old_one(): void
    {
        $service = $this->dedicatedService();
        $old = $this->machineFor($service, 'On');
        $new = $this->machineFor(null, 'Off', '10.20.0.12', [], 'DS-102', '203.0.113.30', []);
        $module = app(ExtensionManager::class)->serverModule('dedicated');
        $this->assertSame('running', $module->clientPanelState($service));

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->post(route('admin.services.machine.replace', $service), ['machine_id' => $new->id, 'confirm' => '1'])->assertSessionHas('status');

        $this->assertSame(MachineStatus::Wiping, $old->fresh()->status);
        $this->assertSame([MachineStatus::Active, $service->id], [$new->fresh()->status, $new->fresh()->service_id]);
        $this->assertSame('stopped', $module->clientPanelState($service->fresh()), 'The panel now reads the new machine.');
    }

    public function test_assigning_a_machine_to_an_imported_active_service(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor(null, 'On');

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->post(route('admin.services.machine.assign', $service), ['machine_id' => $machine->id])->assertSessionHas('status');

        $this->assertSame([MachineStatus::Active, $service->id], [$machine->fresh()->status, $machine->fresh()->service_id]);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame('running', app(ExtensionManager::class)->serverModule('dedicated')->clientPanelState($service));
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('mer.las@example.test'));
    }

    public function test_change_package_refuses_a_machine_not_linked_to_the_product(): void
    {
        $this->setSettings(['billing.plan_changes' => true]);
        $bigger = $this->dedicatedProduct([], 'Dedicated E-2388G + 2 TB');
        $this->product->update(['upgrade_product_ids' => [$bigger->id]]);
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');
        $module = app(ExtensionManager::class)->serverModule('dedicated');

        $this->assertFalse($module->allowsPlanChange($service, $bigger));
        $this->assertSame([], app(PlanChanges::class)->targets($service)->pluck('id')->all());

        $service->update(['product_id' => $bigger->id]);
        $result = app(Provisioner::class)->changePackage($service->fresh());
        $this->assertFalse($result->success);
        $this->assertSame('This machine is not linked to the new product. Link it under Hardware or replace the machine.', $result->message);

        $machine->products()->attach($bigger->id);
        $this->assertTrue($module->allowsPlanChange($service->fresh(), $bigger));
        $this->assertTrue(app(Provisioner::class)->changePackage($service->fresh())->success);

        $service->update(['product_id' => $this->product->id]);
        $this->assertSame([$bigger->id], app(PlanChanges::class)->targets($service->fresh())->pluck('id')->all());
    }

    public function test_the_module_is_not_offered_on_the_server_form(): void
    {
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.servers.create'))->assertOk()->assertSee('value="proxmox"', false)->assertDontSee('value="dedicated"', false);
        $this->post(route('admin.servers.store'), ['name' => 'Rack', 'module' => 'dedicated', 'hostname' => 'rack.example.test'])->assertSessionHasErrors('module');
        $this->assertDatabaseCount('servers', 0);

        $this->get(route('admin.products.create'))->assertOk()
            ->assertSee('value="dedicated"', false)
            ->assertSee(['Power buttons for clients', 'Clients can ask for a reinstall', 'Start the setup before the order is paid', 'Login name shown to the client']);

        $this->assertFalse(app(Provisioner::class)->testConnection(new Server(['module' => 'dedicated']))->success);
    }

    private function order(?Product $product = null): Order
    {
        return app(OrderPlacer::class)->place($this->client, collect([
            new CartLine(index: 0, product: $product ?? $this->product, cycle: BillingCycle::Monthly, domain: null, price: 9900),
        ]));
    }

    private function pay(Order $order): void
    {
        $invoice = $order->invoice->fresh();
        app(PaymentRecorder::class)->record($invoice, $invoice->total, 'banktransfer', 'BANK-'.$invoice->id);
    }
}
