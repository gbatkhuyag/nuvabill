<?php

namespace Tests\Feature\Hardware;

use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use App\Provisioning\Hardware\MachineInventory;
use App\Provisioning\Hardware\PowerControl;
use App\Provisioning\Hardware\Tls\PinningSupport;
use App\Provisioning\Provisioner;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * nuvabill:hardware, every five minutes: machines whose service changed are fixed, a suspended
 * server that is on is switched off (and checked again every hour), and a released machine is
 * switched off once. A BMC that fails never stops the run.
 */
class HardwareCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SYSTEM = '/redfish/v1/Systems/System.Embedded.1';

    private const RESET = '/redfish/v1/Systems/System.Embedded.1/Actions/ComputerSystem.Reset';

    /**
     * The power state each fake BMC reports, by host.
     *
     * @var array<string, string>
     */
    private array $states = [];

    /**
     * Runs while a fake BMC answers a power state read, as something else happening meanwhile.
     */
    private ?Closure $whileReading = null;

    private Product $product;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->app->instance(PinningSupport::class, new PinningSupport(fn (string $function): bool => true, ['version' => '8.5.0', 'ssl_version' => 'OpenSSL/3.0.13']));
        $this->app->forgetInstance(PowerDrivers::class);

        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($this->whileReading !== null && $request->method() === 'GET') {
                ($this->whileReading)($host);
            }

            return match (true) {
                ! isset($this->states[$host]) => Http::failedConnection()($request),
                $request->method() === 'GET' && $path === self::SYSTEM => Http::response(['PowerState' => $this->states[$host]]),
                $request->method() === 'POST' && $path === self::RESET => Http::response(null, 204),
                default => Http::response(['error' => ['message' => 'Not found']], 404),
            };
        });

        $this->product = Product::factory()->withoutDomain()->create(['name' => 'Dedicated E-2388G', 'uses_machines' => true]);
        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);
    }

    public function test_forces_off_a_suspended_machine_that_is_on_after_its_check_time(): void
    {
        $suspended = $this->service(ServiceStatus::Suspended);
        $machine = $this->machine('10.20.0.11', 'on', $suspended, MachineStatus::Suspended, now()->subMinute());
        $notYet = $this->machine('10.20.0.12', 'on', $this->service(ServiceStatus::Suspended), MachineStatus::Suspended, now()->addMinutes(30));

        $this->artisan('nuvabill:hardware')->expectsOutputToContain('power checks: 1')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'ForceOff'] && str_contains($request->url(), '10.20.0.11'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '10.20.0.12'));
        $this->assertNotNull($machine->fresh()->power_check_at);
        $this->assertTrue($machine->fresh()->power_check_at->lte(now()), 'Checked again on the next run, until it is really off.');
        $this->assertStringContainsString('Automatic power action "off"', ActivityLog::query()->where('action', 'machine.power')->sole()->description);

        // Next run: it is off, so it is checked again in an hour (a power cut may switch it on).
        $this->states['10.20.0.11'] = 'Off';
        $this->travel(5)->minutes();
        $this->artisan('nuvabill:hardware')->assertSuccessful();

        $this->assertTrue($machine->fresh()->power_check_at->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertSame(1, ActivityLog::query()->where('action', 'machine.power')->count());
        $this->assertSame(MachineStatus::Suspended, $notYet->fresh()->status);
    }

    public function test_does_not_power_off_when_the_service_is_active_and_syncs_the_status(): void
    {
        $service = $this->service(ServiceStatus::Suspended);
        $machine = $this->machine('10.20.0.11', 'on', $service, MachineStatus::Suspended, now()->subMinute());

        // Staff set the service active by hand, without the module.
        Service::query()->whereKey($service->id)->update(['status' => ServiceStatus::Active]);

        $this->artisan('nuvabill:hardware')->expectsOutputToContain('Machines fixed: 1')->assertSuccessful();

        $this->assertSame(MachineStatus::Active, $machine->fresh()->status);
        $this->assertNull($machine->fresh()->power_check_at);
        Http::assertNothingSent();
    }

    public function test_released_machine_is_powered_off_once(): void
    {
        $machine = $this->machine('10.20.0.11', 'on', null, MachineStatus::Wiping, now()->subMinute());

        $this->artisan('nuvabill:hardware')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'ForceOff']);
        $this->assertNull($machine->fresh()->power_check_at);

        // Staff work on it freely now: no more power calls.
        $this->travel(10)->minutes();
        $this->artisan('nuvabill:hardware')->assertSuccessful();
        $this->assertSame(1, ActivityLog::query()->where('action', 'machine.power')->count());
        $this->assertSame(MachineStatus::Wiping, $machine->fresh()->status);
    }

    public function test_a_bmc_that_fails_keeps_the_check_and_never_stops_the_run(): void
    {
        $down = $this->machine('10.20.0.13', null, $this->service(ServiceStatus::Suspended), MachineStatus::Suspended, now()->subMinutes(2));
        $up = $this->machine('10.20.0.11', 'on', null, MachineStatus::Wiping, now()->subMinute());

        $this->artisan('nuvabill:hardware')->expectsOutputToContain('power checks: 2')->assertSuccessful();

        $this->assertNotNull($down->fresh()->power_check_at, 'The next run tries again.');
        $this->assertSame(1, $down->fresh()->bmc_failures);
        $this->assertNull($up->fresh()->power_check_at);
    }

    public function test_a_suspended_machine_without_power_control_alerts_staff_once(): void
    {
        $machine = Machine::factory()->activeFor($this->service(ServiceStatus::Suspended))->create(['name' => 'DS-150']);
        $machine->forceFill(['status' => MachineStatus::Suspended, 'power_check_at' => now()->subMinute()])->save();

        $this->artisan('nuvabill:hardware')->assertSuccessful();
        $this->artisan('nuvabill:hardware')->assertSuccessful();

        $this->assertNull($machine->fresh()->power_check_at);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'DS-150: Switch it off by hand');
        Mail::assertSentCount(1);
        Http::assertNothingSent();
    }

    public function test_runs_at_most_twenty_machines(): void
    {
        foreach (range(1, 25) as $number) {
            Machine::factory()->wiping()->create(['name' => 'DS-'.(300 + $number), 'power_check_at' => now()->subMinutes(30 - $number)]);
        }

        $this->artisan('nuvabill:hardware')->expectsOutputToContain('power checks: 20')->assertSuccessful();

        $this->assertSame(5, Machine::query()->whereNotNull('power_check_at')->count());
        $this->assertSame(['DS-321', 'DS-322', 'DS-323', 'DS-324', 'DS-325'], Machine::query()->whereNotNull('power_check_at')->orderBy('id')->pluck('name')->all(), 'The longest waiting go first.');

        $this->artisan('nuvabill:hardware')->expectsOutputToContain('power checks: 5')->assertSuccessful();
        $this->assertSame(0, Machine::query()->whereNotNull('power_check_at')->count());
    }

    public function test_the_sweep_waits_while_a_suspend_or_unsuspend_holds_the_service(): void
    {
        $service = $this->service(ServiceStatus::Active);
        $machine = Machine::factory()->activeFor($service)->create(['name' => 'DS-150']);
        $inventory = app(MachineInventory::class);
        $lock = app(Provisioner::class)->lockFor($service, 120);
        $this->assertTrue($lock->get());

        // A suspend is running: the module suspended the machine and is still switching it off.
        // The service is marked suspended only when the module is done.
        $inventory->suspend($machine, now()->addMinutes(10));
        $checkAt = $machine->fresh()->power_check_at;

        $this->artisan('nuvabill:hardware')->expectsOutputToContain('Machines fixed: 0')->assertSuccessful();

        $this->assertSame(MachineStatus::Suspended, $machine->fresh()->status);
        $this->assertNotNull($checkAt);
        $this->assertEquals($checkAt, $machine->fresh()->power_check_at, 'The forced power off stays planned.');

        $service->forceFill(['status' => ServiceStatus::Suspended])->save();
        $lock->release();
        $this->artisan('nuvabill:hardware')->expectsOutputToContain('Machines fixed: 0')->assertSuccessful();
        $this->assertSame(MachineStatus::Suspended, $machine->fresh()->status);
        $this->assertEquals($checkAt, $machine->fresh()->power_check_at);

        // An unsuspend is running: the machine is in use again before the service is active.
        $this->assertTrue($lock->get());
        $inventory->unsuspend($machine);
        $this->artisan('nuvabill:hardware')->expectsOutputToContain('Machines fixed: 0')->assertSuccessful();
        $this->assertSame(MachineStatus::Active, $machine->fresh()->status);

        $service->forceFill(['status' => ServiceStatus::Active])->save();
        $lock->release();
        $this->artisan('nuvabill:hardware')->expectsOutputToContain('Machines fixed: 0')->assertSuccessful();
        $this->assertSame(MachineStatus::Active, $machine->fresh()->status);
        $this->assertSame(0, ActivityLog::query()->where('action', 'machine.status_synced')->count());
    }

    public function test_the_sweep_fixes_a_busy_service_on_a_later_run(): void
    {
        $service = $this->service(ServiceStatus::Suspended);
        $machine = Machine::factory()->activeFor($service)->create(['name' => 'DS-151']);
        $lock = app(Provisioner::class)->lockFor($service, 120);
        $this->assertTrue($lock->get());

        $this->artisan('nuvabill:hardware')->expectsOutputToContain('Machines fixed: 0')->assertSuccessful();
        $this->assertSame(MachineStatus::Active, $machine->fresh()->status);

        // Staff had set the service suspended by hand: once the other work is done, it is copied.
        $lock->release();
        $this->artisan('nuvabill:hardware')->expectsOutputToContain('Machines fixed: 1')->assertSuccessful();
        $this->assertSame(MachineStatus::Suspended, $machine->fresh()->status);
    }

    public function test_failing_bmcs_never_keep_a_healthy_machine_waiting(): void
    {
        // A dead management switch: twenty BMCs that do not answer, due before the healthy one.
        foreach (range(40, 59) as $number) {
            $this->machine("10.20.1.{$number}", null, null, MachineStatus::Wiping, now()->subMinutes(100 - $number));
        }

        $healthy = $this->machine('10.20.0.11', 'on', $this->service(ServiceStatus::Suspended), MachineStatus::Suspended, now()->subMinute());

        $this->artisan('nuvabill:hardware')->expectsOutputToContain('power checks: 20')->assertSuccessful();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '10.20.0.11'));
        $this->assertSame(20, Machine::query()->where('bmc_failures', 1)->whereNotNull('power_check_at')->count());

        // They wait behind it now, so the next run switches it off first.
        $this->travel(5)->minutes();
        $this->artisan('nuvabill:hardware')->expectsOutputToContain('power checks: 20')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['ResetType' => 'ForceOff'] && str_contains($request->url(), '10.20.0.11'));
        $this->assertNotNull($healthy->fresh()->power_check_at, 'Checked again until it is really off.');

        // A BMC that keeps failing is tried less often, at most an hour apart.
        $failing = Machine::query()->where('name', 'DS-40')->sole();
        $this->assertSame(2, $failing->bmc_failures);
        $this->assertTrue($failing->power_check_at->between(now()->addMinutes(4), now()->addMinutes(6)));

        $failing->forceFill(['bmc_failures' => 40, 'power_check_at' => now()->subHour()])->save();
        app(PowerControl::class)->enforce(1);
        $this->assertTrue($failing->fresh()->power_check_at->between(now()->addMinutes(59), now()->addMinutes(61)));
    }

    public function test_a_service_unsuspended_while_the_power_is_read_is_not_switched_off(): void
    {
        $service = $this->service(ServiceStatus::Suspended);
        $machine = $this->machine('10.20.0.11', 'on', $service, MachineStatus::Suspended, now()->subMinute());

        // The client pays while the BMC answers: the service and its machine are active again.
        $this->whileReading = function (string $host) use ($service, $machine): void {
            $this->whileReading = null;
            app(MachineInventory::class)->unsuspend($machine);
            $service->forceFill(['status' => ServiceStatus::Active])->save();
        };

        $this->assertSame(1, app(PowerControl::class)->enforce());

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertSame(MachineStatus::Active, $machine->fresh()->status);
        $this->assertNull($machine->fresh()->power_check_at);
        $this->assertSame(0, ActivityLog::query()->where('action', 'machine.power')->count());
    }

    private function service(ServiceStatus $status): Service
    {
        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id, 'status' => $status]);
    }

    /**
     * A Redfish machine whose fake BMC reports the given power state (null: it does not answer).
     */
    private function machine(string $host, ?string $state, ?Service $service, MachineStatus $status, mixed $checkAt): Machine
    {
        if ($state !== null) {
            $this->states[$host] = ucfirst($state);
        }

        $machine = Machine::factory()->redfish($host)->create(['name' => 'DS-'.str_replace('.', '', substr($host, -2))]);
        $machine->forceFill(['status' => $status, 'service_id' => $service?->id, 'power_check_at' => $checkAt])->save();

        return $machine;
    }
}
