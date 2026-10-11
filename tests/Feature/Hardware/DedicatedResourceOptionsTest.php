<?php

namespace Tests\Feature\Hardware;

use App\Billing\PaymentRecorder;
use App\Billing\ResourceChanges;
use App\Billing\ResourceOptions;
use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Provisioning\Hardware\MachineInventory;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Nuvabill\Extensions\Dedicated\DedicatedModule;
use Tests\TestCase;

/**
 * Extra IPv4 addresses and monthly traffic of dedicated servers sold as resource options: a service
 * can never rent more extra IPs than its machine has free, rented IPs follow the extras paid for
 * (newest freed first), "Server is ready" refuses when the paid IPs do not fit, and a traffic change
 * asks staff to set the new limit.
 */
class DedicatedResourceOptionsTest extends TestCase
{
    use RefreshDatabase, UsesDedicatedServers;

    private ResourceOption $ipv4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDedicated();
        $this->setSettings(['billing.currency' => 'USD', 'billing.resource_changes' => true]);
        $this->ipv4 = ResourceOption::factory()->kind('ipv4', 1)->priced(300)->create(['product_id' => $this->product->id, 'max_steps' => 8]);
    }

    public function test_kinds_and_the_plan_come_without_a_server(): void
    {
        $kinds = app(Provisioner::class)->resourceKinds($this->product);

        $this->assertSame(['ipv4', 'bandwidth'], array_keys($kinds));
        $this->assertSame(['IP', 1], [$kinds['ipv4']->unit, $kinds['ipv4']->minStep]);
        $this->assertSame(['GB', 1000, 1000], [$kinds['bandwidth']->unit, $kinds['bandwidth']->minStep, $kinds['bandwidth']->stepMultiple]);

        // Every machine sold as the product has a main and an included IPv4 address.
        $this->machineFor(null, 'Off');
        $this->machineFor(null, 'Off', '10.20.0.12', [], 'DS-102', '203.0.113.30', ['203.0.113.31']);
        $this->assertSame(['ipv4' => 2, 'bandwidth' => null], app(Provisioner::class)->baseResources($this->product)->data['base']);

        // Machines that differ give no plan value.
        $this->machineFor(null, 'Off', '10.20.0.13', [], 'DS-103', '203.0.113.40', []);
        $this->assertSame(['ipv4' => null, 'bandwidth' => null], app(Provisioner::class)->baseResources($this->product)->data['base']);
        Http::assertNothingSent();
    }

    public function test_ipv4_limit_is_rented_plus_free_extra_ips(): void
    {
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On', extras: ['203.0.113.11', '203.0.113.12', '203.0.113.13']);
        // An extra IPv6 line is not sold as an IPv4 address, and another machine's IPs do not count.
        MachineIp::factory()->create(['machine_id' => Machine::query()->value('id'), 'address' => '2001:db8::11', 'version' => 6, 'kind' => MachineIp::KIND_EXTRA]);
        $this->machineFor(null, 'Off', '10.20.0.12', ['203.0.113.51', '203.0.113.52'], 'DS-102', '203.0.113.50', []);
        $this->rent($service, 1);

        $limits = app(Provisioner::class)->resourceLimits($service);
        $this->assertSame(3, $limits['ipv4']->maxUnits);

        $slider = collect(app(ResourceOptions::class)->forService($service->fresh()))->firstWhere('kind', 'ipv4');
        $this->assertSame([1, 3, 'No more extra IPs are free on your server. Contact us for more.'], [$slider['current'], $slider['max'], $slider['limit_reason']]);
    }

    public function test_adding_and_removing_extra_ips_assigns_and_frees_them(): void
    {
        $service = $this->dedicatedService();
        // An extra IPv6 line comes first, but extra IPs are sold as IPv4 addresses only.
        $machine = $this->machineFor($service, 'On', extras: ['2001:db8::11', '203.0.113.11', '203.0.113.12', '203.0.113.13']);

        $change = $this->appliedChange($service, 0, 2);
        app(ResourceChanges::class)->applyToServer($change->id);

        $this->assertSame(ResourceChange::SERVER_DONE, $change->fresh()->server_state);
        $this->assertSame(['203.0.113.11', '203.0.113.12'], $this->rentedBy($service));
        $this->assertSame(2, ActivityLog::query()->where('action', 'machine.ip_assigned')->count());
        $this->assertTrue(app(Provisioner::class)->checkResources($service)->matches());

        // The client sees them in the panel's addresses.
        $this->actingAs($this->client, 'web')
            ->getJson(route('client.services.sections.show', [$service, 'network']))
            ->assertOk()
            ->assertJsonCount(4, 'data.ips')
            ->assertJsonPath('data.ips.3.ip', '203.0.113.12');

        // One fewer: the newest goes back to the machine's free pool.
        $this->travel(1)->minutes();
        $change = $this->appliedChange($service, 2, 1);
        app(ResourceChanges::class)->applyToServer($change->id);

        $this->assertSame(['203.0.113.11'], $this->rentedBy($service));
        $this->assertSame(MachineStatus::Active, $machine->fresh()->status);
        $this->assertTrue(app(Provisioner::class)->checkResources($service)->matches());
        Http::assertNothingSent();
    }

    public function test_server_is_ready_refuses_when_paid_extras_do_not_fit(): void
    {
        $service = $this->dedicatedService(ServiceStatus::Pending);
        $machine = $this->machineFor($service, 'On', extras: ['203.0.113.11']);
        $machine->forceFill(['status' => MachineStatus::Provisioning, 'provisioned_at' => null])->save();
        $this->row($service, 2);

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->post(route('admin.services.machine.ready', $service), ['hostname' => 'ds1.example.test', 'login_name' => 'root'])
            ->assertSessionHas('error', 'Add 1 more extra IPs to DS-101 under Hardware, then try again.');

        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        $this->assertSame(MachineStatus::Provisioning, $machine->fresh()->status);
        $this->assertSame([], $this->rentedBy($service), 'Nothing is rented while the setup waits.');

        // Staff add an IP to the machine and press the button again.
        MachineIp::factory()->create(['machine_id' => $machine->id, 'address' => '203.0.113.12', 'kind' => MachineIp::KIND_EXTRA]);
        $this->post(route('admin.services.machine.ready', $service), ['hostname' => 'ds1.example.test', 'login_name' => 'root'])
            ->assertSessionHas('status', 'The server is ready.');

        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame(['203.0.113.11', '203.0.113.12'], $this->rentedBy($service));
        $ready = $this->mailsTo('mer.las@example.test', 'Your server Dedicated E-2388G is ready');
        $this->assertStringContainsString('203.0.113.10, 203.0.113.20, 203.0.113.11, 203.0.113.12', $ready[0]->bodyHtml);
    }

    public function test_paid_extra_ips_of_an_order_wait_for_the_payment_and_come_with_the_server(): void
    {
        $machine = $this->machineFor(null, 'On', extras: ['203.0.113.11', '203.0.113.12']);

        $this->actingAs($this->client, 'web');
        $this->post(route('cart.store'), ['product_id' => $this->product->id, 'billing_cycle' => 'monthly', 'resources' => ['ipv4' => 1]])->assertRedirect(route('cart.show'));
        $this->post(route('checkout.store'))->assertRedirect();

        $service = Service::query()->sole();
        $this->assertSame([MachineStatus::Reserved, 1], [$machine->fresh()->status, $service->resourceExtras()->get('ipv4')]);

        $invoice = $service->order->invoice;
        app(PaymentRecorder::class)->record($invoice, $invoice->total, 'banktransfer', 'BANK-1');
        $this->assertSame(MachineStatus::Provisioning, $machine->fresh()->status);

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->post(route('admin.services.machine.ready', $service), ['login_name' => 'root'])->assertSessionHas('status', 'The server is ready.');

        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame(['203.0.113.11'], $this->rentedBy($service));
    }

    public function test_bandwidth_change_alerts_staff_and_verifies(): void
    {
        $traffic = ResourceOption::factory()->kind('bandwidth', 1000)->priced(500)->create(['product_id' => $this->product->id]);
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On');
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $traffic->id, 'steps' => 2, 'recurring_amount' => 1000]);

        $result = app(Provisioner::class)->applyResources($service);

        $this->assertTrue($result->success);
        $alerts = $this->mailsTo('staff@example.test', 'DS-101: Set the traffic limit');
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString("Service #{$service->id} on DS-101 now has 2 TB of extra monthly traffic.", $alerts[0]->bodyHtml);
        $this->assertSame(2000, $service->fresh()->module_data[DedicatedModule::TRAFFIC]);
        $this->assertTrue(app(Provisioner::class)->checkResources($service)->matches());

        // The same extras again change nothing and send nothing.
        app(Provisioner::class)->applyResources($service);
        $this->assertCount(1, $this->mailsTo('staff@example.test', 'DS-101: Set the traffic limit'));

        // Back to the plan: staff are told again.
        ServiceResourceOption::query()->where('service_id', $service->id)->where('kind', 'bandwidth')->update(['steps' => 0, 'status' => ServiceResourceOption::STATUS_CANCELLED]);
        $check = app(Provisioner::class)->checkResources($service->fresh());
        $this->assertFalse($check->matches());
        $this->assertSame(['Monthly traffic: 2 TB, expected 0 GB'], $check->problems);

        app(Provisioner::class)->applyResources($service->fresh());
        $this->assertCount(2, $this->mailsTo('staff@example.test', 'DS-101: Set the traffic limit'));
        $this->assertTrue(app(Provisioner::class)->checkResources($service->fresh())->matches());
        Http::assertNothingSent();
    }

    /**
     * A billing change of the service's extra IPv4 steps that is applied and waits for the server.
     */
    private function appliedChange(Service $service, int $from, int $to): ResourceChange
    {
        $this->row($service, $to);

        return ResourceChange::factory()->applied()->withItem($this->ipv4, $from, $to, 300 * $from, 300 * $to)->create([
            'service_id' => $service->id,
            'mode' => ResourceChange::MODE_NOW,
            'server_state' => ResourceChange::SERVER_QUEUED,
        ]);
    }

    private function row(Service $service, int $steps): void
    {
        ServiceResourceOption::query()->updateOrCreate(
            ['service_id' => $service->id, 'kind' => 'ipv4'],
            ['resource_option_id' => $this->ipv4->id, 'unit' => 'IP', 'step_size' => 1, 'steps' => $steps, 'recurring_amount' => 300 * $steps, 'status' => ServiceResourceOption::STATUS_ACTIVE],
        );
    }

    private function rent(Service $service, int $total): void
    {
        $this->row($service, $total);
        app(MachineInventory::class)->syncExtraIps($service, $total);
    }

    /**
     * @return list<string>
     */
    private function rentedBy(Service $service): array
    {
        return MachineIp::query()->where('service_id', $service->id)->orderBy('id')->pluck('address')->all();
    }
}
