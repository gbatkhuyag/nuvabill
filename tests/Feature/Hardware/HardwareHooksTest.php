<?php

namespace Tests\Feature\Hardware;

use App\Billing\CartLine;
use App\Billing\OrderPlacer;
use App\Billing\PaymentRecorder;
use App\Enums\BillingCycle;
use App\Enums\BootDevice;
use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Machine;
use App\Models\MachinePowerEvent;
use App\Models\Order;
use App\Models\Service;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use App\Provisioning\Hardware\HealthReading;
use App\Provisioning\Hardware\HealthReport;
use App\Provisioning\Hardware\InventoryException;
use App\Provisioning\Hardware\PowerControl;
use App\Provisioning\Hardware\ServerSetup;
use App\Provisioning\Provisioner;
use App\Support\AttentionItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Fakes\FakePowerDriver;
use Tests\Fixtures\RackHooks\RackHooks;
use Tests\TestCase;

/**
 * Dedicated servers since power control became an add-on: without a power driver every flow asks
 * staff to switch machines by hand, and the extension points add-ons use (power history, next boot
 * device, health, machine page cards, client panel tabs, Hardware pages, machine events and
 * automatic installs that finish through "Server is ready").
 */
class HardwareHooksTest extends TestCase
{
    use RefreshDatabase, UsesDedicatedServers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDedicated();

        // The fixture add-on is switched on and booted as the app does it. The built-in "dedicated"
        // module must stay findable, so the fixture folder is used only to read the add-on.
        $path = config('nuvabill.extensions_path');
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->app->forgetInstance(ExtensionManager::class);
        $extensions = app(ExtensionManager::class);
        $extensions->registerViews();
        RackHooks::reset();
        $extensions->saveSettings('rackhooks', [], true);
        $extensions->activeAddons()->get('rackhooks')->boot();

        // The app registered its routes before this test switched the add-on on.
        Route::middleware(['web', 'auth:admin', 'admin.can:hardware.manage'])
            ->prefix(config('nuvabill.admin_path').'/hardware/addons')->name('admin.hardware-addons.')
            ->group(fn () => $extensions->registerHardwareRoutes());
        app('router')->getRoutes()->refreshNameLookups();

        config(['nuvabill.extensions_path' => $path]);
        $this->app->forgetInstance(ExtensionManager::class);
    }

    public function test_without_a_power_addon_staff_switch_machines_by_hand(): void
    {
        $this->app->forgetInstance(PowerDrivers::class);
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.machines.show', $machine))->assertOk()
            ->assertSee([PowerControl::needsAddonMessage(), 'Get Dedicated Server Power']);
        $this->get(route('admin.machines.create'))->assertOk()->assertDontSee('value="redfish"', false)->assertSee(PowerControl::needsAddonMessage());
        $this->post(route('admin.machines.test', $machine))->assertSessionHas('error', PowerControl::needsAddonMessage());
        $this->assertNotNull($machine->fresh()->bmc_password, 'The machine keeps its BMC login.');

        $suspended = app(Provisioner::class)->suspend($service, 'Overdue on payment');
        $this->assertSame('Marked suspended. This server has no power control, so switch it off by hand.', $suspended->message);
        $this->assertCount(1, $this->mailsTo('staff@example.test', 'DS-101: Switch it off by hand'));

        // The scheduler does not send the same reminder again.
        $this->travel(11)->minutes();
        $this->artisan('nuvabill:hardware')->assertSuccessful();
        $this->assertCount(1, $this->mailsTo('staff@example.test', 'DS-101: Switch it off by hand'));

        $unsuspended = app(Provisioner::class)->unsuspend($service->fresh());
        $this->assertSame('Active again. This server has no power control, so switch it on by hand if it is off.', $unsuspended->message);
        $this->assertCount(1, $this->mailsTo('staff@example.test', 'DS-101: Switch it on by hand'));

        // Clients get no power buttons.
        $this->actingAs($this->client, 'web');
        $this->getJson(route('client.services.sections.show', [$service, 'overview']))->assertOk()
            ->assertJsonPath('data.state', 'unknown')
            ->assertJsonPath('data.notices.0.text', 'The power buttons are not available for this server. Open a ticket if it needs a restart.');
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'start']))->assertStatus(403);

        $terminated = app(Provisioner::class)->terminate($service->fresh());
        $this->assertSame('The machine waits for a wipe. This server has no power control, so switch it off by hand.', $terminated->message);
        $this->assertSame(MachineStatus::Wiping, $machine->fresh()->status);
        $this->assertCount(2, $this->mailsTo('staff@example.test', 'DS-101: Switch it off by hand'));
        $this->assertCount(1, $this->mailsTo('staff@example.test', 'Wipe DS-101 before you sell it again'));

        $this->artisan('nuvabill:hardware')->assertSuccessful();
        $this->assertCount(2, $this->mailsTo('staff@example.test', 'DS-101: Switch it off by hand'));
        $this->assertSame(0, MachinePowerEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_every_power_action_is_in_the_power_history(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');
        $down = $this->machineFor(null, null, '10.20.0.12', [], 'DS-102', '203.0.113.30', []);
        $admin = $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->postJson(route('admin.machines.power', [$machine, 'off']))->assertOk();
        $this->postJson(route('admin.machines.power', [$down, 'on']))->assertStatus(422);

        $this->travel(30)->seconds();
        $this->actingAs($this->client, 'web');
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'start']))->assertOk();

        $events = MachinePowerEvent::query()->orderBy('id')->get();
        $this->assertSame([
            [$machine->id, 'off', 'ok', 'staff', $admin->id],
            [$down->id, 'on', 'failed', 'staff', $admin->id],
            [$machine->id, 'on', 'ok', 'client', $this->client->id],
        ], $events->map(fn (MachinePowerEvent $event): array => [$event->machine_id, $event->action, $event->result, $event->actor_kind, $event->actor_id])->all());
        $this->assertSame('redfish', $events[0]->driver);
        $this->assertSame($service->id, $events[2]->service_id);
        $this->assertNotNull($events[1]->message);
        $this->assertStringNotContainsString('bmc-test-password', (string) $events->pluck('message')->implode(' '));

        $this->signInAdmin($admin);
        $this->get(route('admin.machines.show', $machine))->assertOk()->assertSee(['Power history', 'Force off', 'Power on', 'Mer Las', 'Raz']);
    }

    public function test_staff_choose_the_next_boot_device_and_read_the_health(): void
    {
        $driver = FakePowerDriver::install();
        $driver->readings = [new HealthReading('CPU 1 temperature', '54 °C', HealthReading::OK), new HealthReading('Power supply 2', '<b>Failed</b>', HealthReading::CRITICAL)];
        $driver->watts = 310;
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');
        $other = $this->dedicatedService();
        $this->signInAdmin(Admin::factory()->withPermissions(['hardware.power'])->create(['name' => 'Raz']));

        $this->get(route('admin.machines.show', $machine))->assertOk()->assertSee(['Next boot from', 'Network (PXE)', 'Not read yet.']);
        $this->post(route('admin.machines.boot', $machine), ['device' => 'usb'])->assertSessionHasErrors('device');
        $this->post(route('admin.machines.boot', $machine), ['device' => 'pxe'])->assertSessionHas('status', 'The server starts from Network (PXE) on its next boot.');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->data() === ['Boot' => ['BootSourceOverrideEnabled' => 'Once', 'BootSourceOverrideTarget' => 'Pxe']]);
        $this->assertSame('boot:pxe', MachinePowerEvent::query()->sole()->action);

        // A machine can only be set for the service that holds it.
        $refused = app(PowerControl::class)->setBootDevice($machine, BootDevice::Disk, $this->client, $other);
        $this->assertFalse($refused->ok);
        $this->assertSame(1, MachinePowerEvent::query()->count());

        $this->post(route('admin.machines.health', $machine))->assertSessionHas('status', 'Health read just now.');
        $this->get(route('admin.machines.show', $machine))->assertOk()
            ->assertSee(['CPU 1 temperature', '54 °C', 'Power supply 2', '310 W', 'Next boot: Network (PXE)'])
            ->assertDontSee('<b>Failed</b>', false);
    }

    public function test_addons_add_machine_cards_hardware_pages_and_client_panel_tabs(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.machines.show', $machine))->assertOk()->assertSee(['data-rackhooks-card', 'Port: not set'], false);
        $this->post(route('admin.hardware-addons.rackhooks.port', $machine), ['port' => 'sw1 Gi1/0/7'])->assertSessionHas('status', 'Port saved.');
        $this->get(route('admin.machines.show', $machine))->assertSee('Port: sw1 Gi1/0/7');

        // Hardware pages of add-ons are for staff who manage hardware.
        $this->signInAdmin(Admin::factory()->withPermissions(['hardware.power'])->create(['name' => 'Las']));
        $this->post(route('admin.hardware-addons.rackhooks.port', $machine), ['port' => 'sw9'])->assertForbidden();
        $this->get(route('admin.machines.show', $machine))->assertOk()->assertDontSee('name="port"', false);

        $this->actingAs($this->client, 'web');
        $this->getJson(route('client.services.sections.show', [$service, 'install']))->assertOk()->assertJsonPath('data.note', 'Ready to install on service '.$service->id);
        $this->postJson(route('client.services.sections.action', [$service, 'install', 'start']))->assertStatus(422);
        $this->postJson(route('client.services.sections.action', [$service, 'install', 'start']), ['confirm' => '1'])->assertOk()->assertJsonPath('message', 'Install started.');

        // The module's own power tab stays its own.
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'restart']))->assertOk()->assertJsonPath('message', 'The server is restarting.');

        // No add-on tab while the service has no machine in use.
        $pending = $this->dedicatedService(ServiceStatus::Pending);
        $this->getJson(route('client.services.sections.show', [$pending, 'install']))->assertNotFound();
    }

    public function test_addons_add_checked_items_to_the_dashboard_attention_list(): void
    {
        app(AttentionItems::class)->add(fn (Admin $admin): array => $admin->hasPermission('hardware.manage') ? [
            ['key' => 'ext.rackhooks.faults', 'tone' => 'crit', 'text' => '2 machines report a <b>hardware</b> fault.', 'url' => '/admin/hardware', 'action' => 'Review'],
            ['key' => 'orders.pending', 'tone' => 'crit', 'text' => 'Not an add-on key', 'url' => null, 'action' => null],
            ['key' => 'ext.rackhooks.away', 'tone' => 'warn', 'text' => 'Leaves the site', 'url' => 'https://example.org/x', 'action' => null],
            ['key' => 'ext.rackhooks.tone', 'tone' => 'good', 'text' => 'Wrong tone', 'url' => null, 'action' => null],
        ] : []);
        app(AttentionItems::class)->add(fn (Admin $admin): array => throw new RuntimeException('broken add-on'));
        Exceptions::fake();

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->get(route('admin.dashboard'))->assertOk()
            ->assertSee('2 machines report a hardware fault.')
            ->assertDontSee(['Not an add-on key', 'Leaves the site', 'Wrong tone', '<b>hardware</b>'], false);
        Exceptions::assertReported(RuntimeException::class);

        $this->signInAdmin(Admin::factory()->withPermissions(['billing.view'])->create(['name' => 'Las']));
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('2 machines report a hardware fault.');
    }

    public function test_health_readings_keep_their_key_and_say_which_parts_were_not_read(): void
    {
        $report = new HealthReport([
            new HealthReading('Prozessoren', 'OK', HealthReading::OK, 'system:processors'),
            new HealthReading('Power', 'Not read this time', HealthReading::UNKNOWN, HealthReading::SKIPPED.'power'),
            new HealthReading('Old driver', 'OK', HealthReading::OK),
        ], 120, now());
        $copy = HealthReport::fromArray($report->toArray());

        $this->assertSame(['system:processors', 'skipped:power', ''], array_map(fn (HealthReading $reading): string => $reading->key, $copy->readings));
        $this->assertSame(['power'], $copy->skippedParts());
        $this->assertSame(HealthReading::OK, $copy->status());
    }

    public function test_addons_hear_about_suspend_unsuspend_and_the_end_and_a_failing_listener_stops_nothing(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');

        app(Provisioner::class)->suspend($service, 'Overdue on payment');
        app(Provisioner::class)->unsuspend($service->fresh());
        $this->assertSame(['suspended:'.$machine->id, 'unsuspended:'.$machine->id], RackHooks::$heard);

        Exceptions::fake();
        RackHooks::$listenerFails = true;
        $result = app(Provisioner::class)->terminate($service->fresh());

        $this->assertTrue($result->success);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
        $this->assertSame(MachineStatus::Wiping, $machine->fresh()->status);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'A listener of DedicatedServerReleased failed: RuntimeException');

        RackHooks::$listenerFails = false;
        $machine->forceFill(['status' => MachineStatus::Available, 'service_id' => null])->save();
        $next = $this->dedicatedService(ServiceStatus::Active);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->post(route('admin.services.machine.assign', $next), ['machine_id' => $machine->id])->assertSessionHas('status');
        $this->assertContains('activated:'.$machine->id, RackHooks::$heard);
    }

    public function test_an_installer_takes_over_the_setup_and_finishes_like_server_is_ready(): void
    {
        RackHooks::$installs = true;
        $machine = $this->machineFor(null, 'Off');
        $order = $this->order();
        $service = $order->services->sole();
        $this->pay($order);

        $machine->refresh();
        $this->assertSame([$machine->id], RackHooks::$started);
        $this->assertSame([MachineStatus::Provisioning, 'rackhooks'], [$machine->status, $machine->setup_by]);
        $this->assertSame([], $this->mailsTo('staff@example.test', 'Install the OS on DS-101 for Mer Las'), 'Staff do not install it by hand.');

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->get(route('admin.services.show', $service))->assertOk()
            ->assertSee(['The system is being installed automatically by Rack hooks installer', 'Server is ready']);

        try {
            app(ServerSetup::class)->ready($service, 'ds1.example.test', 'root; rm -rf /', 'Root-Pass-For-Mer-Las-1', by: 'rackhooks');
            $this->fail('A bad login name is refused.');
        } catch (InventoryException) {
            $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        }

        $result = app(ServerSetup::class)->ready($service, 'ds1.example.test', 'root', 'Root-Pass-For-Mer-Las-1', by: 'rackhooks');

        $this->assertTrue($result->success);
        $service->refresh();
        $this->assertSame([ServiceStatus::Active, 'ds1.example.test', 'root', 'Root-Pass-For-Mer-Las-1'], [$service->status, $service->domain, $service->username, $service->password]);
        $this->assertStringNotContainsString('Root-Pass-For-Mer-Las-1', (string) DB::table('services')->where('id', $service->id)->value('password'));
        $this->assertSame(MachineStatus::Active, $machine->fresh()->status);
        $ready = $this->mailsTo('mer.las@example.test', 'Your server Dedicated E-2388G is ready');
        $this->assertCount(1, $ready);
        $this->assertStringNotContainsString('Root-Pass-For-Mer-Las-1', $ready[0]->bodyHtml);
        $this->assertStringContainsString('marked ready for service #'.$service->id.' by rackhooks', ActivityLog::query()->where('action', 'machine.install_done')->sole()->description);
        $this->assertSame(0, ActivityLog::query()->where('description', 'like', '%Root-Pass%')->count());
        $this->assertContains('activated:'.$machine->id, RackHooks::$heard);
    }

    public function test_a_failed_install_goes_back_to_staff(): void
    {
        RackHooks::$installs = true;
        $machine = $this->machineFor(null, 'Off');
        $order = $this->order();
        $service = $order->services->sole();
        $this->pay($order);

        app(ServerSetup::class)->failed($service, "<b>PXE</b> boot\ntimed out.");

        $this->assertSame('PXE boot timed out.', $machine->fresh()->setup_error);
        $alert = $this->mailsTo('staff@example.test', 'DS-101: The automatic install failed');
        $this->assertCount(1, $alert);
        $this->assertStringContainsString('PXE boot timed out.', $alert[0]->bodyHtml);
        $this->assertStringContainsString('Install the system by hand, then press Server is ready.', $alert[0]->bodyHtml);

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->get(route('admin.services.show', $service))->assertOk()->assertSee('The automatic install by Rack hooks installer did not finish: PXE boot timed out.');

        // Staff install it by hand and press Server is ready.
        $this->post(route('admin.services.machine.ready', $service), ['hostname' => 'ds1.example.test', 'login_name' => 'root'])->assertSessionHas('status', 'The server is ready.');
        $this->assertNull($machine->fresh()->setup_error);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('mer.las@example.test') && $mail->subjectLine === 'Your server Dedicated E-2388G is ready');
    }

    private function order(): Order
    {
        return app(OrderPlacer::class)->place($this->client, collect([
            new CartLine(index: 0, product: $this->product, cycle: BillingCycle::Monthly, domain: null, price: 9900),
        ]));
    }

    private function pay(Order $order): void
    {
        $invoice = $order->invoice->fresh();
        app(PaymentRecorder::class)->record($invoice, $invoice->total, 'banktransfer', 'BANK-'.$invoice->id);
    }
}
