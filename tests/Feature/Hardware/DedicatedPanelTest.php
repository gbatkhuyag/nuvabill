<?php

namespace Tests\Feature\Hardware;

use App\Enums\InvoiceStatus;
use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelException;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nuvabill\Extensions\Dedicated\DedicatedModule;
use Tests\TestCase;

/**
 * The client server panel of a dedicated server, through the core panel routes: facts, addresses
 * and the power state with no BMC details, power buttons of the product's level with a shared
 * limit, and a reinstall request that opens one ticket at a time. Also the "being prepared" card
 * while staff install a paid server.
 */
class DedicatedPanelTest extends TestCase
{
    use RefreshDatabase, UsesDedicatedServers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDedicated();
    }

    public function test_owner_sees_power_state_and_no_bmc_details(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On', extras: ['203.0.113.11', '203.0.113.12']);
        $machine->ips()->where('address', '203.0.113.11')->update(['service_id' => $service->id, 'assigned_at' => now()]);
        $this->actingAs($this->client, 'web');

        $overview = $this->getJson(route('client.services.sections.show', [$service, 'overview']))
            ->assertOk()
            ->assertJsonPath('data.state', 'running')
            ->assertJsonPath('data.ips.0.ip', '203.0.113.10')
            ->assertJsonPath('data.ips.0.label', 'Main IP')
            ->assertJsonPath('data.ips.1.label', 'Included')
            ->assertJsonPath('data.ips.2.ip', '203.0.113.11')
            ->assertJsonPath('data.ips.2.label', 'Extra')
            ->assertJsonCount(3, 'data.ips');

        $facts = collect($overview->json('data.facts'))->pluck('value', 'label')->all();
        $this->assertSame('Dedicated E-2388G', $facts['Plan']);
        $this->assertSame('ds1.example.test', $facts['Hostname']);
        $this->assertSame('Rack room 1', $facts['Location']);
        $this->assertSame('Intel Xeon E-2388G · 8 cores', $facts['CPU']);
        $this->assertSame('64 GB', $facts['RAM']);
        $this->assertSame('root', $facts['Login name']);
        $this->assertSame('203.0.113.9', $facts['Gateway']);

        $network = $this->getJson(route('client.services.sections.show', [$service, 'network']))
            ->assertOk()
            ->assertJsonPath('data.rdns', false)
            ->assertJsonPath('data.ips.0.prefix', 29)
            ->assertJsonCount(3, 'data.ips');

        foreach ([$overview->getContent(), $network->getContent()] as $json) {
            foreach (['DS-101', '10.20.0.11', 'nuvabill-operator', 'bmc-test-password', 'A1', 'U7', 'Spare PSU', 'Dell', 'PowerEdge', '203.0.113.12'] as $hidden) {
                $this->assertStringNotContainsString('"'.$hidden, $json, "The panel must not show {$hidden}.");
            }

            $this->assertStringNotContainsStringIgnoringCase('machine', $json, 'No machine id or name.');
        }

        $this->getJson(route('client.services.panel-status', $service))->assertOk()->assertJsonPath('state', 'running');
    }

    public function test_other_clients_get_404(): void
    {
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On');
        $this->actingAs(Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null]), 'web');

        $this->getJson(route('client.services.sections.show', [$service, 'overview']))->assertNotFound();
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'restart']))->assertNotFound();
        $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'request']), ['template' => 'Debian 12', 'confirm' => '1'])->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_pending_or_suspended_services_have_no_power_section(): void
    {
        $pending = $this->dedicatedService(ServiceStatus::Pending);
        $this->machineFor($pending, 'On')->forceFill(['status' => MachineStatus::Provisioning, 'provisioned_at' => null])->save();
        $suspended = $this->dedicatedService(ServiceStatus::Suspended);
        $this->machineFor($suspended, 'Off', '10.20.0.12', [], 'DS-102', '203.0.113.30', [])->forceFill(['status' => MachineStatus::Suspended])->save();
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$pending, 'power', 'start']))->assertNotFound();
        $this->postJson(route('client.services.sections.action', [$suspended, 'power', 'start']))->assertForbidden()->assertJsonPath('message', 'This service is suspended.');
        $this->getJson(route('client.services.sections.show', [$suspended, 'overview']))->assertForbidden();
        $this->assertNull(app(Provisioner::class)->serverPanel($pending, $this->context($pending)));
        $this->assertNull(app(Provisioner::class)->serverPanel($suspended, $this->context($suspended)));

        Http::assertNothingSent();
    }

    public function test_restart_sends_graceful_restart(): void
    {
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On');
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$service, 'power', 'restart']))
            ->assertOk()
            ->assertJsonPath('message', 'The server is restarting.')
            ->assertJsonPath('pending', 'restart');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://10.20.0.11'.self::RESET
            && $request->data() === ['ResetType' => 'GracefulRestart']);
        $this->assertStringContainsString('Client power action "restart" on DS-101', ActivityLog::query()->where('action', 'machine.power')->sole()->description);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.client_action')->exists());

        $this->getJson(route('client.services.sections.show', [$service, 'overview']))
            ->assertOk()
            ->assertJsonPath('data.power_note', fn (string $note): bool => str_starts_with($note, 'Last power action: Restart,') && str_ends_with($note, 'by you.'));
    }

    public function test_seventh_power_action_in_ten_minutes_is_throttled(): void
    {
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On');
        $this->actingAs($this->client, 'web');

        foreach (range(1, 6) as $try) {
            $this->postJson(route('client.services.sections.action', [$service, 'power', $try % 2 === 0 ? 'start' : 'restart']))->assertOk();
            // The machine's own pause between two power actions.
            $this->travel(21)->seconds();
        }

        $this->postJson(route('client.services.sections.action', [$service, 'power', 'stop']))
            ->assertStatus(429)
            ->assertHeader('Retry-After');
        Http::assertSentCount(6);
    }

    public function test_a_second_action_while_one_runs_is_refused(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');
        $this->actingAs($this->client, 'web');
        $lock = Cache::lock('nuvabill:machine:power-lock:'.$machine->id, 60);
        $this->assertTrue($lock->get());

        $this->postJson(route('client.services.sections.action', [$service, 'power', 'restart']))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Another power action is running. Try again in a minute.');

        $lock->release();
        $serviceLock = app(Provisioner::class)->lockFor($service, 120);
        $this->assertTrue($serviceLock->get());

        $this->postJson(route('client.services.sections.action', [$service, 'power', 'restart']))->assertStatus(409);
        $serviceLock->release();
        Http::assertNothingSent();
    }

    public function test_basic_power_plan_refuses_force_actions_server_side(): void
    {
        $this->product->update(['module_config' => ['client_power' => 'basic']]);
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On');
        $this->actingAs($this->client, 'web');

        $panel = app(Provisioner::class)->serverPanel($service, $this->context($service));
        $this->assertSame(['start', 'stop', 'restart'], array_column($panel['power'], 'key'));

        $this->postJson(route('client.services.sections.action', [$service, 'power', 'reset']), ['confirm' => '1'])->assertNotFound();
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'poweroff']), ['confirm' => '1'])->assertNotFound();

        // The module refuses them too, should anything reach it.
        try {
            app(ExtensionManager::class)->serverModule('dedicated')->runPanelAction($service, 'power', 'poweroff', ['confirm' => true], $this->context($service));
            $this->fail('A force off on a basic plan must be refused.');
        } catch (PanelException $refused) {
            $this->assertSame(403, $refused->status);
        }

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_no_power_section_when_the_product_turns_it_off(): void
    {
        $this->product->update(['module_config' => ['client_power' => 'none']]);
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On');
        $this->actingAs($this->client, 'web');

        $panel = app(Provisioner::class)->serverPanel($service, $this->context($service));
        $this->assertSame([], $panel['power']);
        $this->assertSame(['overview', 'network', 'reinstall'], array_column($panel['sections'], 'key'));
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'start']))->assertNotFound();

        // Staff can also switch the buttons off with the product's panel switch.
        $this->product->update(['module_config' => ['client_power' => 'full'], 'panel_sections' => ['power' => false]]);
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'start']))->assertForbidden();
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_a_machine_without_power_control_hides_the_buttons(): void
    {
        $service = $this->dedicatedService();
        $machine = $this->machineFor($service, 'On');
        $machine->forceFill(['bmc_type' => 'none', 'bmc_host' => null])->save();
        $this->actingAs($this->client, 'web');

        $panel = app(Provisioner::class)->serverPanel($service, $this->context($service));
        $this->assertSame([], $panel['power']);
        $this->getJson(route('client.services.sections.show', [$service, 'overview']))
            ->assertOk()
            ->assertJsonPath('data.state', 'unknown')
            ->assertJsonPath('data.notices.0.text', 'The power buttons are not available for this server. Open a ticket if it needs a restart.');
        Http::assertNothingSent();
    }

    public function test_reinstall_request_opens_a_ticket_in_the_set_department(): void
    {
        $hardware = TicketDepartment::factory()->create(['name' => 'Hardware', 'is_visible' => false]);
        $this->setSettings(['hardware.ticket_department' => $hardware->id, 'hardware.os_choices' => ['Debian 12', 'AlmaLinux 9']]);
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On');
        $this->actingAs($this->client, 'web');

        $this->getJson(route('client.services.sections.show', [$service, 'reinstall']))
            ->assertOk()
            ->assertJsonPath('data.mode', 'request')
            ->assertJsonPath('data.credential_mode', 'none')
            ->assertJsonPath('data.templates.1.id', 'AlmaLinux 9')
            ->assertJsonPath('data.open_request', null);

        $answer = $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'request']), [
            'template' => 'AlmaLinux 9',
            'note' => 'Please use RAID 1.',
            'confirm' => '1',
        ])->assertOk();

        $ticket = Ticket::query()->sole();
        $this->assertSame([$hardware->id, $service->id, $this->client->id, 'Reinstall request: AlmaLinux 9'], [$ticket->ticket_department_id, $ticket->service_id, $ticket->client_id, $ticket->subject]);
        $this->assertStringContainsString('Please reinstall my server with AlmaLinux 9.', $ticket->replies()->sole()->message);
        $this->assertStringContainsString('Please use RAID 1.', $ticket->replies()->sole()->message);
        $answer->assertJsonPath('message', "We opened ticket #{$ticket->number}. We reply there when your server is ready.")
            ->assertJsonPath('data.open_request.number', (string) $ticket->number);
        $this->assertSame($ticket->id, $service->fresh()->module_data[DedicatedModule::REINSTALL_TICKET]);
        $this->assertStringContainsString("ticket #{$ticket->number} (AlmaLinux 9)", ActivityLog::query()->where('action', 'machine.reinstall_requested')->sole()->description);
        Http::assertNothingSent();
    }

    public function test_second_reinstall_request_is_refused_while_the_ticket_is_open(): void
    {
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On');
        $this->actingAs($this->client, 'web');
        $form = ['template' => 'Debian 12', 'confirm' => '1'];

        $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'request']), $form)->assertOk();
        $ticket = Ticket::query()->sole();
        $this->assertSame('Technical support', $ticket->department->name, 'Without a set department, the first one clients see.');

        $this->getJson(route('client.services.sections.show', [$service, 'reinstall']))->assertOk()->assertJsonPath('data.open_request.number', (string) $ticket->number);
        $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'request']), $form)
            ->assertStatus(409)
            ->assertJsonPath('message', "You already asked for a reinstall. See ticket #{$ticket->number}.");
        $this->assertSame(1, Ticket::query()->count());

        // Once the ticket is closed, a new request may be made.
        $ticket->update(['status' => TicketStatus::Closed]);
        $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'request']), $form)->assertOk();
        $this->assertSame(2, Ticket::query()->count());
    }

    public function test_reinstall_needs_an_allowed_os_and_the_confirmation(): void
    {
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On');
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'request']), ['template' => 'Windows 98', 'confirm' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('template');
        $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'request']), ['template' => 'Debian 12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');
        $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'request']), ['template' => 'Debian 12', 'confirm' => '1', 'note' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->assertSame(0, Ticket::query()->count());

        // A product that takes no reinstall requests has no such tab.
        $this->product->update(['module_config' => ['reinstall_requests' => '0']]);
        $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'request']), ['template' => 'Debian 12', 'confirm' => '1'])->assertNotFound();
    }

    public function test_power_actions_are_locked_in_the_demo(): void
    {
        config(['nuvabill.demo' => true]);
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On');
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$service, 'power', 'restart']))
            ->assertForbidden()
            ->assertJsonPath('message', 'This is turned off in the demo, so it keeps working for every visitor.');
        $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'request']), ['template' => 'Debian 12', 'confirm' => '1'])->assertForbidden();

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_the_service_page_draws_the_panel(): void
    {
        $service = $this->dedicatedService();
        $this->machineFor($service, 'On');

        $this->actingAs($this->client, 'web')
            ->get(route('client.services.show', $service))
            ->assertOk()
            ->assertSee(['Overview', 'IPs and rDNS', 'Reinstall', 'Power on', 'Force off'])
            ->assertSee(route('client.services.sections.action', [$service, 'power', 'restart']))
            ->assertDontSee('Open control panel')
            ->assertDontSee('DS-101')
            ->assertDontSee('10.20.0.11');
    }

    public function test_pending_card_shows_specs_but_no_ip(): void
    {
        $service = $this->dedicatedService(ServiceStatus::Pending, ['domain' => null]);
        $machine = $this->machineFor($service, 'On');
        $machine->forceFill(['status' => MachineStatus::Provisioning, 'provisioned_at' => null])->save();
        $this->actingAs($this->client, 'web');

        // Not paid yet: no card.
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => InvoiceStatus::Unpaid]);
        $order = Order::factory()->create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id]);
        $service->update(['order_id' => $order->id]);
        $this->get(route('client.services.show', $service))->assertOk()->assertDontSee('Your server is being prepared');

        $invoice->update(['status' => InvoiceStatus::Paid]);
        $this->get(route('client.services.show', $service))
            ->assertOk()
            ->assertSee(['Your server is being prepared', 'Our team is installing your server. We email you as soon as it is ready.', 'Intel Xeon E-2388G', '64 GB', '2 × 960 GB NVMe', 'Rack room 1'])
            ->assertDontSee(['203.0.113.10', 'DS-101', '10.20.0.11', 'Spare PSU']);
    }

    private function context(Service $service): ClientContext
    {
        return new ClientContext($this->client->id, '203.0.113.9', true, route('client.services.show', $service), 'en');
    }
}
