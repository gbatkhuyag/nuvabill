<?php

namespace Tests\Feature\Hardware;

use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Product;
use App\Models\Service;
use App\Models\TicketDepartment;
use App\Provisioning\Hardware\BmcAddress;
use App\Provisioning\Hardware\Tls\CertificateProbe;
use App\Provisioning\Hardware\Tls\PinningSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeCertificateProbe;
use Tests\Fakes\FakePowerDriver;
use Tests\TestCase;

/**
 * Admin → Hardware: adding and editing machines, their BMC login (encrypted, never shown again,
 * never kept in the session), safe BMC addresses, IP lists, statuses that can never skip a wipe,
 * trusting a BMC certificate, power from the admin area, and the service page's machine card.
 */
class MachineAdminTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = 'nb-operator-91';

    private const PASSWORD = 'Bmc-Secret-Pass-77';

    private const SYSTEM = '/redfish/v1/Systems/System.Embedded.1';

    private const RESET = '/redfish/v1/Systems/System.Embedded.1/Actions/ComputerSystem.Reset';

    private Product $product;

    private FakeCertificateProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->probe = new FakeCertificateProbe(FakeCertificateProbe::certificate('A'));
        $this->app->instance(CertificateProbe::class, $this->probe);
        $this->app->instance(PinningSupport::class, new PinningSupport(fn (string $function): bool => true, ['version' => '8.5.0', 'ssl_version' => 'OpenSSL/3.0.13']));
        FakePowerDriver::install();
        $this->product = Product::factory()->withoutDomain()->create(['name' => 'Dedicated E-2388G', 'uses_machines' => true]);
    }

    protected function tearDown(): void
    {
        BmcAddress::lookupUsing(null);

        parent::tearDown();
    }

    public function test_staff_can_add_a_machine_and_its_bmc_login_is_encrypted(): void
    {
        $admin = $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.machines.store'), $this->form())->assertSessionHasNoErrors()->assertRedirect();

        $machine = Machine::query()->where('name', 'DS-101')->sole();
        $this->assertSame(MachineStatus::Available, $machine->status);
        $this->assertSame(self::USERNAME, $machine->bmc_username);
        $this->assertSame(self::PASSWORD, $machine->bmc_password);

        $raw = DB::table('machines')->where('id', $machine->id)->first();
        $this->assertNotSame(self::USERNAME, $raw->bmc_username);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $raw->bmc_password);
        $this->assertStringNotContainsString(self::USERNAME, (string) $raw->bmc_username);

        $this->assertSame([$this->product->id], $machine->products()->pluck('products.id')->all());
        $this->assertSame(
            [['203.0.113.10', 'primary', 29, '203.0.113.9'], ['203.0.113.11', 'extra', null, null], ['203.0.113.12', 'extra', null, null]],
            $machine->ips()->orderBy('id')->get()->map(fn (MachineIp $ip): array => [$ip->address, $ip->kind, $ip->prefix, $ip->gateway])->all(),
        );
        $this->assertSame(1, $this->product->fresh()->stockLeft());

        $log = ActivityLog::query()->where('action', 'machine.created')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertStringNotContainsString(self::PASSWORD, $log->description);

        foreach ([route('admin.machines.edit', $machine), route('admin.machines.show', $machine), route('admin.machines.index')] as $page) {
            $this->get($page)->assertOk()->assertDontSee(self::USERNAME)->assertDontSee(self::PASSWORD);
        }

        $this->get(route('admin.machines.edit', $machine))->assertSee('Saved. Leave empty to keep it.');
    }

    public function test_bmc_secrets_are_not_kept_after_a_validation_error(): void
    {
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->from(route('admin.machines.create'))
            ->post(route('admin.machines.store'), $this->form(['name' => '']))
            ->assertRedirect(route('admin.machines.create'))
            ->assertSessionHasErrors('name');

        $old = (array) session('_old_input', []);
        $this->assertSame('10.20.0.11', $old['bmc_host'] ?? null);
        $this->assertArrayNotHasKey('bmc_username', $old);
        $this->assertArrayNotHasKey('bmc_password', $old);

        $this->get(route('admin.machines.create'))->assertOk()->assertDontSee(self::USERNAME)->assertDontSee(self::PASSWORD);
        $this->assertDatabaseCount('machines', 0);
    }

    public function test_staff_without_hardware_manage_cannot_create_machines(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['services.manage', 'hardware.power'])->create(['name' => 'Raz']));

        $this->get(route('admin.machines.create'))->assertForbidden();
        $this->post(route('admin.machines.store'), $this->form())->assertForbidden();
        $this->assertDatabaseCount('machines', 0);

        // Without hardware.power the pages do not open at all.
        $this->signInAdmin(Admin::factory()->withPermissions(['services.manage'])->create(['name' => 'Mer Las']));
        $this->get(route('admin.machines.index'))->assertForbidden();
    }

    public function test_staff_with_only_hardware_power_can_view_but_not_edit(): void
    {
        $machine = Machine::factory()->redfish()->linkedTo($this->product)->withIps()->create(['name' => 'DS-102']);
        $this->signInAdmin(Admin::factory()->withPermissions(['hardware.power'])->create(['name' => 'Raz']));

        $this->get(route('admin.machines.index'))->assertOk()->assertSee('DS-102')->assertDontSee(route('admin.machines.create'));
        $this->get(route('admin.machines.show', $machine))
            ->assertOk()
            ->assertSee('203.0.113.10')
            ->assertDontSee('10.20.0.11')
            ->assertDontSee(route('admin.machines.edit', $machine))
            ->assertDontSee(route('admin.machines.test', $machine));
        $this->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.machines.index'));

        $this->get(route('admin.machines.edit', $machine))->assertForbidden();
        $this->put(route('admin.machines.update', $machine), $this->form(['name' => 'DS-999']))->assertForbidden();
        $this->post(route('admin.machines.test', $machine))->assertForbidden();
        $this->post(route('admin.machines.status', $machine), ['to' => 'maintenance'])->assertForbidden();
        $this->delete(route('admin.machines.destroy', $machine))->assertForbidden();
        $this->assertSame('DS-102', $machine->fresh()->name);
    }

    public function test_changing_the_bmc_address_needs_the_login_again_and_clears_the_pin(): void
    {
        $machine = Machine::factory()->redfish()->linkedTo($this->product)->create(['name' => 'DS-103']);
        $pin = $machine->bmc_tls_pin;
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $blank = ['name' => 'DS-103', 'primary_ip' => '', 'extra_ips' => '', 'bmc_username' => '', 'bmc_password' => ''];

        // The same address: blank fields keep the saved login and the trusted certificate.
        $this->put(route('admin.machines.update', $machine), $this->form($blank + ['location' => 'Rack room 2']))->assertSessionHasNoErrors();
        $machine->refresh();
        $this->assertSame('Rack room 2', $machine->location);
        $this->assertSame('nuvabill-operator', $machine->bmc_username);
        $this->assertSame($pin, $machine->bmc_tls_pin);

        $this->put(route('admin.machines.update', $machine), $this->form(['bmc_host' => '10.20.0.12'] + $blank))
            ->assertSessionHasErrors(['bmc_username' => 'The BMC address or type changed. Enter its user name and password again.']);
        $this->assertSame('10.20.0.11', $machine->fresh()->bmc_host);

        $this->put(route('admin.machines.update', $machine), $this->form(['bmc_host' => '10.20.0.12', 'name' => 'DS-103', 'primary_ip' => '', 'extra_ips' => '']))->assertSessionHasNoErrors();
        $machine->refresh();
        $this->assertSame('10.20.0.12', $machine->bmc_host);
        $this->assertSame(self::USERNAME, $machine->bmc_username);
        $this->assertSame(self::PASSWORD, $machine->bmc_password);
        $this->assertNull($machine->bmc_tls_pin);
        $this->assertNull($machine->bmc_tls_fingerprint);
        $this->assertNull($machine->bmc_system_path);
        $this->assertNull($machine->bmc_reset_target);

        // Without power control no login is kept at all.
        $this->put(route('admin.machines.update', $machine), $this->form(['bmc_type' => 'none', 'bmc_host' => ''] + $blank))->assertSessionHasNoErrors();
        $machine->refresh();
        $this->assertNull($machine->bmc_username);
        $this->assertNull($machine->bmc_password);
        $this->assertFalse($machine->hasBmc());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeHosts(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'cloud metadata' => ['169.254.169.254'],
            'IPv6 loopback' => ['::1'],
            'unspecified' => ['0.0.0.0'],
            'IPv6 link-local' => ['fe80::1'],
            'multicast' => ['224.0.0.1'],
            'mapped loopback' => ['::ffff:127.0.0.1'],
            'a scheme' => ['https://10.0.0.5'],
            'a login' => ['admin@10.0.0.5'],
            'a path' => ['10.0.0.5/redfish'],
            'a port' => ['bmc1.example.test:8443'],
            'localhost' => ['localhost'],
        ];
    }

    #[DataProvider('unsafeHosts')]
    public function test_unsafe_bmc_hosts_are_refused(string $host): void
    {
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.machines.store'), $this->form(['bmc_host' => $host]))->assertSessionHasErrors('bmc_host');

        $this->assertDatabaseCount('machines', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function privateHosts(): array
    {
        return [
            'private IPv4' => ['10.20.0.11'],
            'host name' => ['bmc1.mgmt.example.test'],
            'unique local IPv6' => ['fd12:3456::11'],
        ];
    }

    #[DataProvider('privateHosts')]
    public function test_private_bmc_hosts_are_accepted(string $host): void
    {
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.machines.store'), $this->form(['bmc_host' => $host]))->assertSessionHasNoErrors();

        $this->assertSame($host, Machine::query()->sole()->bmc_host);
    }

    public function test_ip_lines_are_parsed_and_must_be_unique(): void
    {
        Machine::factory()->withIps('198.51.100.50')->create(['name' => 'DS-050']);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.machines.store'), $this->form([
            'included_ips' => "203.0.113.20/29 via 203.0.113.17\n\n2001:DB8:0:0::0010/64 via 2001:db8::1",
            'extra_ips' => "203.0.113.11\n203.0.113.12/32",
        ]))->assertSessionHasNoErrors();

        $machine = Machine::query()->where('name', 'DS-101')->sole();
        $this->assertSame(
            ['203.0.113.10' => 'primary', '203.0.113.20' => 'included', '2001:db8::10' => 'included', '203.0.113.11' => 'extra', '203.0.113.12' => 'extra'],
            $machine->ips()->orderBy('id')->pluck('kind', 'address')->all(),
        );
        $six = $machine->ips()->where('address', '2001:db8::10')->sole();
        $this->assertSame([6, 64, '2001:db8::1'], [$six->version, $six->prefix, $six->gateway]);

        $bad = [
            'not an address' => ['included_ips' => 'not-an-ip'],
            'IPv4 prefix too long' => ['included_ips' => '203.0.113.30/33'],
            'gateway of another version' => ['included_ips' => '203.0.113.30/29 via 2001:db8::1'],
            'listed twice' => ['extra_ips' => "203.0.113.40\n203.0.113.40"],
            'same as the main IP' => ['extra_ips' => '203.0.113.90'],
            'loopback' => ['extra_ips' => '127.0.0.2'],
            'on another machine' => ['extra_ips' => '198.51.100.50'],
            'too many lines' => ['extra_ips' => implode("\n", array_map(fn (int $i): string => '10.1.'.intdiv($i, 250).'.'.($i % 250 + 1), range(0, 512)))],
        ];

        foreach ($bad as $case => $fields) {
            $this->post(route('admin.machines.store'), $this->form($fields + ['name' => 'DS-200', 'primary_ip' => '203.0.113.90', 'extra_ips' => '']))
                ->assertSessionHasErrors(array_key_first(array_diff_key($fields, ['name' => true])) ?? 'extra_ips', "Case: {$case}");
        }

        $this->assertSame(1, Machine::query()->where('name', 'like', 'DS-1%')->count());
        $this->assertSame(0, Machine::query()->where('name', 'DS-200')->count());
    }

    public function test_a_rented_extra_ip_cannot_be_removed(): void
    {
        $service = $this->service(ServiceStatus::Active);
        $machine = Machine::factory()->activeFor($service)->linkedTo($this->product)->withIps('203.0.113.10', ['203.0.113.11', '203.0.113.12'])->create(['name' => 'DS-105']);
        MachineIp::query()->where('address', '203.0.113.11')->update(['service_id' => $service->id, 'assigned_at' => now()]);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $fields = ['name' => 'DS-105', 'bmc_type' => 'none', 'bmc_host' => '', 'primary_ip' => '203.0.113.10', 'primary_prefix' => '', 'primary_gateway' => ''];

        $this->put(route('admin.machines.update', $machine), $this->form($fields + ['extra_ips' => '203.0.113.12', 'location' => 'Moved']))
            ->assertSessionHasErrors(['extra_ips' => "203.0.113.11 is rented by service #{$service->id}. Lower its extra IPs first."]);
        // It cannot become an included address either, and nothing of the form was saved.
        $this->put(route('admin.machines.update', $machine), $this->form($fields + ['extra_ips' => '203.0.113.12', 'included_ips' => '203.0.113.11', 'location' => 'Moved']))
            ->assertSessionHasErrors('extra_ips');
        $this->assertSame('Rack room 1', $machine->fresh()->location);
        $this->assertSame($service->id, MachineIp::query()->where('address', '203.0.113.11')->value('service_id'));

        // A free extra address can go; the rented one stays rented.
        $this->put(route('admin.machines.update', $machine), $this->form($fields + ['extra_ips' => '203.0.113.11']))->assertSessionHasNoErrors();
        $this->assertSame(['203.0.113.10', '203.0.113.11'], $machine->ips()->orderBy('id')->pluck('address')->all());
        $this->assertSame($service->id, MachineIp::query()->where('address', '203.0.113.11')->value('service_id'));
    }

    public function test_a_machine_with_history_cannot_be_deleted(): void
    {
        $used = Machine::factory()->create(['name' => 'DS-106']);
        $used->forceFill(['last_service_id' => 44, 'wiped_at' => now()])->save();
        $new = Machine::factory()->withIps()->create(['name' => 'DS-107']);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.machines.show', $used))->assertOk()->assertDontSee('Delete machine');
        $this->delete(route('admin.machines.destroy', $used))->assertSessionHas('error', 'This machine has history. Retire it instead.');
        $this->assertModelExists($used);

        $this->get(route('admin.machines.show', $new))->assertOk()->assertSee('Delete machine');
        $this->delete(route('admin.machines.destroy', $new))->assertRedirect(route('admin.machines.index'));
        $this->assertModelMissing($new);
        $this->assertDatabaseMissing('machine_ips', ['machine_id' => $new->id]);
        $this->assertSame(1, ActivityLog::query()->where('action', 'machine.deleted')->count());
    }

    public function test_a_wiping_machine_cannot_be_set_available_through_status(): void
    {
        $machine = Machine::factory()->wiping()->linkedTo($this->product)->create(['name' => 'DS-108']);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.machines.status', $machine), ['to' => 'available'])->assertSessionHas('error', 'Not possible from Needs wipe.');
        $this->post(route('admin.machines.status', $machine), ['to' => 'wiping'])->assertSessionHasErrors('to');
        $this->post(route('admin.machines.status', $machine), ['to' => 'active'])->assertSessionHasErrors('to');

        $this->assertSame(MachineStatus::Wiping, $machine->fresh()->status);
        $this->assertSame(0, $this->product->fresh()->stockLeft());

        // A free machine moves between its staff statuses.
        $free = Machine::factory()->linkedTo($this->product)->create(['name' => 'DS-109']);
        $this->post(route('admin.machines.status', $free), ['to' => 'maintenance'])->assertSessionHas('status');
        $this->assertSame(MachineStatus::Maintenance, $free->fresh()->status);
        $this->assertSame(1, ActivityLog::query()->where('action', 'machine.status_changed')->count());
    }

    public function test_confirming_a_wipe_needs_the_checkbox_and_puts_it_back_on_sale(): void
    {
        $machine = Machine::factory()->wiping()->linkedTo($this->product)->create(['name' => 'DS-110']);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.machines.show', $machine))->assertOk()->assertSee('Confirm wipe')->assertSee('I erased all disks of this machine');

        $this->post(route('admin.machines.wiped', $machine), ['confirm_wiped' => '0', 'next' => 'available'])->assertSessionHasErrors('confirm_wiped');
        $this->post(route('admin.machines.wiped', $machine), ['confirm_wiped' => '1', 'next' => 'decommissioned'])->assertSessionHasErrors('next');
        $this->assertSame(MachineStatus::Wiping, $machine->fresh()->status);

        $this->post(route('admin.machines.wiped', $machine), ['confirm_wiped' => '1', 'next' => 'available'])->assertSessionHas('status');

        $machine->refresh();
        $this->assertSame(MachineStatus::Available, $machine->status);
        $this->assertNotNull($machine->wiped_at);
        $this->assertNull($machine->power_check_at);
        $this->assertSame(1, $this->product->fresh()->stockLeft());
        $this->assertSame(1, ActivityLog::query()->where('action', 'machine.wiped')->count());
    }

    public function test_hardware_routes_are_locked_in_the_demo(): void
    {
        config(['nuvabill.demo' => true]);
        $machine = Machine::factory()->redfish()->wiping()->create(['name' => 'DS-111']);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $demo = 'This is turned off in the demo, so it keeps working for every visitor.';

        $this->get(route('admin.machines.index'))->assertOk();
        $this->get(route('admin.machines.show', $machine))->assertOk();
        $this->post(route('admin.machines.store'), $this->form())->assertSessionHas('error', $demo);
        $this->post(route('admin.machines.test', $machine))->assertSessionHas('error', $demo);
        $this->post(route('admin.machines.wiped', $machine), ['confirm_wiped' => '1', 'next' => 'available'])->assertSessionHas('error', $demo);
        $this->postJson(route('admin.machines.power', [$machine, 'off']))->assertForbidden()->assertJson(['message' => $demo]);

        Http::assertNothingSent();
        $this->assertSame(1, Machine::query()->count());
        $this->assertSame(MachineStatus::Wiping, $machine->fresh()->status);
    }

    public function test_the_list_filters_and_shows_stock_per_product(): void
    {
        $service = $this->service(ServiceStatus::Active);
        Machine::factory()->activeFor($service)->linkedTo($this->product)->withIps('203.0.113.10')->create(['name' => 'DS-120', 'location' => 'Erbil']);
        Machine::factory()->linkedTo($this->product)->withIps('203.0.113.20')->create(['name' => 'DS-121', 'location' => 'Frankfurt']);
        Machine::factory()->wiping()->create(['name' => 'DS-122', 'location' => 'Frankfurt']);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.machines.index'))
            ->assertOk()
            ->assertSee(['DS-120', 'DS-121', 'DS-122', 'Dedicated E-2388G', '1 free of 2', 'Mer Las'])
            ->assertSee(route('admin.services.show', $service));

        $this->get(route('admin.machines.index', ['status' => 'wiping']))->assertSee('DS-122')->assertDontSee('DS-121');
        $this->get(route('admin.machines.index', ['location' => 'Frankfurt']))->assertSee(['DS-121', 'DS-122'])->assertDontSee('DS-120');
        $this->get(route('admin.machines.index', ['q' => '203.0.113.20']))->assertSee('DS-121')->assertDontSee('DS-120');
        $this->get(route('admin.machines.index', ['product' => $this->product->id]))->assertSee(['DS-120', 'DS-121'])->assertDontSee('DS-122');
        $this->get(route('admin.machines.index', ['q' => 'nothing-like-this']))->assertSee('No machines match');
    }

    public function test_testing_a_new_bmc_asks_to_trust_its_certificate_first(): void
    {
        Http::fake();
        $machine = Machine::factory()->redfish()->create(['name' => 'DS-130']);
        $machine->forceFill(['bmc_tls_pin' => null, 'bmc_tls_fingerprint' => null])->save();
        $seen = FakeCertificateProbe::certificate('A');
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->post(route('admin.machines.test', $machine))->assertRedirect(route('admin.machines.show', $machine))->assertSessionHas('warning');
        $this->get(route('admin.machines.show', $machine))->assertOk()->assertSee($seen->fingerprint)->assertSee('Trust this certificate');

        // Only the certificate the test showed can be trusted.
        $this->post(route('admin.machines.trust', $machine), ['fingerprint' => FakeCertificateProbe::certificate('B')->fingerprint])
            ->assertSessionHas('error', 'Run Test connection again, then trust the certificate it shows.');
        $this->post(route('admin.machines.trust', $machine), ['fingerprint' => $seen->fingerprint, 'pasted_fingerprint' => FakeCertificateProbe::certificate('C')->fingerprint])
            ->assertSessionHas('error', 'The fingerprint you pasted does not match the BMC certificate.');
        $this->post(route('admin.machines.trust', $machine), ['fingerprint' => $seen->fingerprint, 'pasted_fingerprint' => 'abc'])->assertSessionHasErrors('pasted_fingerprint');
        $this->assertNull($machine->fresh()->bmc_tls_pin);

        $this->post(route('admin.machines.trust', $machine), ['fingerprint' => $seen->fingerprint, 'pasted_fingerprint' => strtolower($seen->fingerprint)])
            ->assertSessionHas('status');
        $this->assertSame($seen->pin, $machine->fresh()->bmc_tls_pin);
        $this->assertNull(session('machine_trust.'.$machine->id));

        // The session value is gone, so a replay cannot trust again; and no login was sent anywhere.
        $this->post(route('admin.machines.trust', $machine), ['fingerprint' => $seen->fingerprint])->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_staff_read_the_power_state_and_switch_the_power(): void
    {
        $state = 'On';
        $this->fakeBmc([
            self::SYSTEM => function () use (&$state): array {
                return ['PowerState' => $state];
            },
            'POST '.self::RESET => null,
        ]);
        $machine = Machine::factory()->redfish()->create(['name' => 'DS-132']);
        $admin = $this->signInAdmin(Admin::factory()->withPermissions(['hardware.power'])->create(['name' => 'Raz']));

        $response = $this->getJson(route('admin.machines.power.show', $machine))->assertOk();
        $response->assertJson(['state' => 'on', 'label' => 'On'])->assertJsonPath('actions', ['on', 'shutdown', 'restart', 'reset', 'off']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('10.20.0.11', $response->getContent());

        $this->postJson(route('admin.machines.power', [$machine, 'off']))
            ->assertOk()
            ->assertJson(['ok' => true, 'pending' => 'off', 'state' => 'changing', 'message' => 'The server is powered off.']);
        $this->assertSame(['ResetType' => 'ForceOff'], $this->sentTo('POST '.self::RESET)[0]->data());
        $log = ActivityLog::query()->where('action', 'machine.power')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertStringContainsString('Staff power action "off" on DS-132', $log->description);

        // One action at a time, with a short pause between actions.
        $this->postJson(route('admin.machines.power', [$machine, 'on']))->assertStatus(422)->assertJson(['ok' => false, 'busy' => true]);

        // Without JavaScript the button posts a normal form and comes back with the message.
        $this->travel(30)->seconds();
        $state = 'Off';
        $this->from(route('admin.machines.show', $machine))->post(route('admin.machines.power', [$machine, 'on']))
            ->assertRedirect(route('admin.machines.show', $machine))
            ->assertSessionHas('status', 'The server is starting.');
    }

    public function test_hardware_settings_are_checked_and_saved(): void
    {
        $department = TicketDepartment::factory()->create(['name' => 'Hardware']);
        $this->signInAdmin(Admin::factory()->withPermissions(['hardware.manage', 'hardware.power'])->create(['name' => 'Raz']));
        $this->get(route('admin.machines.index'))->assertOk()->assertDontSee('Hardware settings');
        $this->put(route('admin.machines.settings'), $this->settingsForm())->assertForbidden();

        $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));
        $this->get(route('admin.machines.index'))->assertOk()->assertSee('Hardware settings');

        $this->put(route('admin.machines.settings'), $this->settingsForm(['os_choices' => "Ubuntu 24.04\n<script>"]))->assertSessionHasErrors('os_choices');
        $this->put(route('admin.machines.settings'), $this->settingsForm(['os_choices' => implode("\n", array_map(fn (int $i): string => "Linux {$i}", range(1, 21)))]))->assertSessionHasErrors('os_choices');
        $this->put(route('admin.machines.settings'), $this->settingsForm(['suspend_grace_minutes' => 0]))->assertSessionHasErrors('suspend_grace_minutes');
        $this->put(route('admin.machines.settings'), $this->settingsForm(['ticket_department' => 99999]))->assertSessionHasErrors('ticket_department');

        $this->put(route('admin.machines.settings'), $this->settingsForm([
            'os_choices' => "Debian 12\n\nAlmaLinux 9\nDebian 12",
            'ticket_department' => $department->id,
            'suspend_grace_minutes' => 30,
            'show_location' => '0',
        ]))->assertSessionHasNoErrors()->assertRedirect(route('admin.machines.index'));

        $this->assertSame(['Debian 12', 'AlmaLinux 9'], setting('hardware.os_choices'));
        $this->assertSame($department->id, setting('hardware.ticket_department'));
        $this->assertSame(30, setting('hardware.suspend_grace_minutes'));
        $this->assertFalse(setting('hardware.show_location'));
    }

    public function test_the_service_card_assigns_a_machine_and_marks_the_server_ready(): void
    {
        $service = $this->service(ServiceStatus::Pending);
        $other = Machine::factory()->linkedTo($this->product)->create(['name' => 'DS-140']);
        $unlinked = Machine::factory()->withIps('203.0.113.60')->create(['name' => 'DS-141', 'rack' => 'B2', 'rack_unit' => 'U9']);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.services.show', $service))
            ->assertOk()
            ->assertSee(['Dedicated server', 'Assign a machine', 'DS-140', 'DS-141'])
            ->assertDontSee('Server is ready');

        // A machine that is not sold as this product may be assigned, with a warning.
        $this->post(route('admin.services.machine.assign', $service), ['machine_id' => $unlinked->id])
            ->assertSessionHas('status')
            ->assertSessionHas('warning');
        $this->assertSame(MachineStatus::Reserved, $unlinked->fresh()->status);
        $this->assertSame($service->id, $unlinked->fresh()->service_id);

        // Staff start the setup, then the card shows the steps and the ready form.
        $unlinked->forceFill(['status' => MachineStatus::Provisioning])->save();
        $this->get(route('admin.services.show', $service))
            ->assertOk()
            ->assertSee(['Server is ready', 'Install the system on DS-141 (Rack B2 U9).', '203.0.113.60'])
            ->assertSee('value="root"', false);

        $this->post(route('admin.services.machine.ready', $service), ['hostname' => 'bad host!', 'login_name' => 'root', 'root_password' => 'Short1'])
            ->assertSessionHasErrors(['hostname', 'root_password']);
        $this->assertArrayNotHasKey('root_password', (array) session('_old_input', []));

        $this->post(route('admin.services.machine.ready', $service), ['hostname' => 'server1.example.test', 'login_name' => 'admin', 'root_password' => 'Root-Pass-For-Mer-Las-1'])
            ->assertSessionHas('status');

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('server1.example.test', $service->domain);
        $this->assertSame('admin', $service->username);
        $this->assertSame('Root-Pass-For-Mer-Las-1', $service->password);
        $this->assertStringNotContainsString('Root-Pass-For-Mer-Las-1', (string) DB::table('services')->where('id', $service->id)->value('password'));
        $this->assertNotNull($unlinked->fresh()->provisioned_at);
        $this->assertSame(0, ActivityLog::query()->where('description', 'like', '%Root-Pass%')->count());
        $this->assertSame(MachineStatus::Available, $other->fresh()->status);
    }

    public function test_the_service_card_replaces_the_machine_of_a_live_service(): void
    {
        $service = $this->service(ServiceStatus::Active);
        $old = Machine::factory()->redfish()->activeFor($service)->linkedTo($this->product)->create(['name' => 'DS-150']);
        $new = Machine::factory()->linkedTo($this->product)->create(['name' => 'DS-151']);

        $this->signInAdmin(Admin::factory()->withPermissions(['services.manage'])->create(['name' => 'Mer Las']));
        $this->get(route('admin.services.show', $service))->assertOk()->assertSee('DS-150')->assertDontSee('Replace machine')->assertDontSee('Force off');
        $this->post(route('admin.services.machine.replace', $service), ['machine_id' => $new->id, 'confirm' => '1'])->assertForbidden();

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->get(route('admin.services.show', $service))->assertOk()->assertSee(['Replace machine', 'Force off', route('admin.machines.power', [$old, 'off'])]);

        $this->post(route('admin.services.machine.replace', $service), ['machine_id' => $new->id])->assertSessionHasErrors('confirm');
        $this->post(route('admin.services.machine.replace', $service), ['machine_id' => $new->id, 'confirm' => '1'])->assertSessionHas('status');

        $this->assertSame(MachineStatus::Wiping, $old->fresh()->status);
        $this->assertSame(MachineStatus::Active, $new->fresh()->status);
        $this->assertSame($service->id, $new->fresh()->service_id);

        // A service of a product that does not use machines has no machine to change.
        $plain = Service::factory()->create(['client_id' => $service->client_id]);
        $this->post(route('admin.services.machine.assign', $plain), ['machine_id' => $new->id])->assertSessionHas('error', 'This service is not a dedicated server from Hardware.');
    }

    public function test_the_product_form_shows_the_machine_stock(): void
    {
        Machine::factory()->linkedTo($this->product)->create(['name' => 'DS-160']);
        Machine::factory()->wiping()->linkedTo($this->product)->create(['name' => 'DS-161']);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.products.edit', $this->product))
            ->assertOk()
            ->assertSee('Stock comes from free machines under Hardware. 1 of 2 linked machines are free.')
            ->assertSee(route('admin.machines.index', ['product' => $this->product->id]));
    }

    public function test_inventory_modules_have_no_server_records_and_link_to_hardware(): void
    {
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->app->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.servers.create'))->assertOk()->assertSee('value="formpanel"', false)->assertDontSee('value="panelfake"', false);
        $this->get(route('admin.servers.create', ['module' => 'panelfake']))->assertOk()->assertDontSee('value="panelfake"', false);
        $this->post(route('admin.servers.store'), ['name' => 'Rack', 'module' => 'panelfake', 'hostname' => 'rack.example.test'])->assertSessionHasErrors('module');
        $this->assertDatabaseCount('servers', 0);

        $product = Product::factory()->create(['server_module' => 'panelfake', 'uses_machines' => true]);
        $service = Service::factory()->create(['product_id' => $product->id, 'client_id' => Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las'])->id]);
        Machine::factory()->activeFor($service)->linkedTo($product)->create(['name' => 'DS-170']);
        Machine::factory()->linkedTo($product)->create(['name' => 'DS-171']);

        $this->get(route('admin.extensions.index', ['tab' => 'servers']))
            ->assertOk()
            ->assertSee('Machines: 2 · In use: 1')
            ->assertSee(route('admin.machines.create'))
            ->assertDontSee(route('admin.servers.create', ['module' => 'panelfake']));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'DS-101',
            'location' => 'Rack room 1',
            'rack' => 'A1',
            'rack_unit' => 'U7',
            'cpu' => 'Intel Xeon E-2388G',
            'cpu_cores' => 8,
            'ram_gb' => 64,
            'storage' => '2 × 960 GB NVMe',
            'port_speed' => '1 Gbps',
            'notes' => '',
            'product_ids' => [$this->product->id],
            'primary_ip' => '203.0.113.10',
            'primary_prefix' => 29,
            'primary_gateway' => '203.0.113.9',
            'included_ips' => '',
            'extra_ips' => "203.0.113.11\n203.0.113.12",
            'bmc_type' => 'redfish',
            'bmc_host' => '10.20.0.11',
            'bmc_port' => '',
            'bmc_username' => self::USERNAME,
            'bmc_password' => self::PASSWORD,
            'bmc_tls_mode' => 'pinned',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settingsForm(array $overrides = []): array
    {
        return array_merge([
            'os_choices' => "Ubuntu 24.04\nDebian 12",
            'ticket_department' => '',
            'suspend_grace_minutes' => 10,
            'show_location' => '1',
        ], $overrides);
    }

    private function service(ServiceStatus $status): Service
    {
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer.las@example.test', 'company_name' => null]);

        return Service::factory()->create(['client_id' => $client->id, 'product_id' => $this->product->id, 'status' => $status, 'domain' => null]);
    }

    /**
     * The fake BMC answers by "METHOD /path" or "/path" (a body, null for 204, or a closure);
     * anything else is a 404.
     *
     * @param  array<string, mixed>  $routes
     */
    private function fakeBmc(array $routes): void
    {
        Http::fake(function (Request $request) use ($routes): mixed {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $key = array_key_exists($request->method().' '.$path, $routes) ? $request->method().' '.$path : (array_key_exists($path, $routes) ? $path : null);

            if ($key === null) {
                return Http::response(['error' => ['message' => 'Not found']], 404);
            }

            $answer = is_callable($routes[$key]) ? $routes[$key]($request) : $routes[$key];

            return $answer === null ? Http::response(null, 204) : Http::response($answer);
        });
    }

    /**
     * @return list<Request>
     */
    private function sentTo(string $route): array
    {
        [$method, $path] = explode(' ', $route, 2);

        return Http::recorded(fn (Request $request): bool => $request->method() === $method && parse_url($request->url(), PHP_URL_PATH) === $path)
            ->map(fn (array $pair): Request => $pair[0])
            ->values()
            ->all();
    }
}
