<?php

namespace Tests\Feature\Admin;

use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ResourceChange;
use App\Models\Service;
use App\Support\AttentionList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's "Needs your attention" list for dedicated servers and resource changes: each item
 * only for staff who may act on it, and a dedicated server waiting for its OS install is not also
 * counted as a paid service stuck in setup.
 */
class AttentionListTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Product $dedicated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer.las@example.test']);
        $this->dedicated = Product::factory()->withoutDomain()->create(['name' => 'Dedicated E-2388G', 'uses_machines' => true]);
    }

    public function test_install_and_wipe_items_show_for_staff_with_the_right_permission(): void
    {
        $installing = $this->paidPendingService($this->dedicated);
        Machine::factory()->create(['name' => 'DS-101', 'status' => MachineStatus::Provisioning, 'service_id' => $installing->id]);
        Machine::factory()->wiping()->create(['name' => 'DS-102']);
        $suspended = Service::factory()->suspended()->create(['client_id' => $this->client->id, 'product_id' => $this->dedicated->id]);
        $on = Machine::factory()->create(['name' => 'DS-103', 'status' => MachineStatus::Suspended, 'service_id' => $suspended->id]);
        $on->forceFill(['power_state' => 'on'])->save();

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $items = $this->items();
        $this->assertSame('1 dedicated server waits for an OS install.', $items['hardware.install']['text']);
        $this->assertSame(route('admin.machines.index', ['status' => 'provisioning']), $items['hardware.install']['url']);
        $this->assertSame('1 machine waits for a disk wipe before it can be sold again.', $items['hardware.wipe']['text']);
        $this->assertSame(route('admin.machines.index', ['status' => 'wiping']), $items['hardware.wipe']['url']);
        $this->assertSame('1 suspended machine is still on.', $items['hardware.still_on']['text']);
        $this->assertSame('crit', $items['hardware.still_on']['tone']);
        $this->get(route('admin.dashboard'))->assertOk()->assertSee(['1 dedicated server waits for an OS install.', '1 suspended machine is still on.']);

        // Services staff see the install item, but link to the services they may open.
        $this->signInAdmin(Admin::factory()->withPermissions(['services.manage'])->create(['name' => 'Mer Las']));
        $items = $this->items();
        $this->assertSame(['hardware.install'], array_values(array_filter(array_keys($items), fn (string $key): bool => str_starts_with($key, 'hardware.'))));
        $this->assertSame(route('admin.services.index', ['status' => 'pending']), $items['hardware.install']['url']);

        $this->signInAdmin(Admin::factory()->withPermissions(['hardware.power'])->create(['name' => 'Raz']));
        $this->assertSame(['hardware.still_on'], array_keys($this->items()));

        // Hardware managers who cannot open the machine list still see the wipe count, without a link.
        $this->signInAdmin(Admin::factory()->withPermissions(['hardware.manage'])->create(['name' => 'Raz']));
        $items = $this->items();
        $this->assertSame(['hardware.wipe'], array_keys($items));
        $this->assertNull($items['hardware.wipe']['url']);

        $this->signInAdmin(Admin::factory()->withPermissions(['support.manage'])->create(['name' => 'Raz']));
        $this->assertSame([], $this->items());
    }

    public function test_the_generic_waiting_count_leaves_out_dedicated_servers_waiting_for_their_install(): void
    {
        $this->paidPendingService(Product::factory()->withoutDomain()->create(['name' => 'Starter Hosting']));
        $installing = $this->paidPendingService($this->dedicated);
        Machine::factory()->create(['name' => 'DS-101', 'status' => MachineStatus::Provisioning, 'service_id' => $installing->id]);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->assertSame('1 paid service is still waiting to be set up.', $this->items()['services']['text']);

        // A paid dedicated server with no machine, or one only reserved, still needs staff to act there.
        $this->paidPendingService($this->dedicated);
        $reserved = $this->paidPendingService($this->dedicated);
        Machine::factory()->reservedFor($reserved)->create(['name' => 'DS-104']);

        $this->assertSame('3 paid services are still waiting to be set up.', $this->items()['services']['text']);
        $this->assertSame('1 dedicated server waits for an OS install.', $this->items()['hardware.install']['text']);
    }

    public function test_resource_changes_that_need_staff_are_listed_for_service_staff(): void
    {
        $first = Service::factory()->create(['client_id' => $this->client->id]);
        $second = Service::factory()->suspended()->create(['client_id' => $this->client->id]);
        $ended = Service::factory()->create(['client_id' => $this->client->id, 'status' => ServiceStatus::Terminated]);
        ResourceChange::factory()->applied()->create(['service_id' => $first->id, 'server_state' => ResourceChange::SERVER_FAILED]);
        ResourceChange::factory()->applied()->create(['service_id' => $second->id, 'server_state' => ResourceChange::SERVER_MANUAL]);
        ResourceChange::factory()->applied()->create(['service_id' => $second->id, 'server_state' => ResourceChange::SERVER_DONE]);
        ResourceChange::factory()->applied()->create(['service_id' => $ended->id, 'server_state' => ResourceChange::SERVER_FAILED]);
        ResourceChange::factory()->create(['service_id' => $first->id, 'status' => ResourceChange::STATUS_CANCELLED, 'server_state' => ResourceChange::SERVER_FAILED]);

        $this->signInAdmin(Admin::factory()->withPermissions(['support.manage', 'billing.view'])->create(['name' => 'Raz']));
        $this->assertArrayNotHasKey('resources.staff', $this->items());

        $this->signInAdmin(Admin::factory()->withPermissions(['services.manage'])->create(['name' => 'Mer Las']));
        $item = $this->items()['resources.staff'];
        $this->assertSame('2 resource changes need staff.', $item['text']);
        $this->assertSame('crit', $item['tone']);
        $this->assertSame(route('admin.services.show', $first), $item['url']);
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('2 resource changes need staff.');
    }

    /**
     * @return array<string, array{key: string, tone: string, text: string, url: string|null, action: string|null}>
     */
    private function items(): array
    {
        return collect(AttentionList::items())
            ->filter(fn (array $item): bool => str_starts_with($item['key'], 'hardware.') || in_array($item['key'], ['services', 'resources.staff'], true))
            ->keyBy('key')
            ->all();
    }

    private function paidPendingService(Product $product): Service
    {
        $service = Service::factory()->pending()->create(['client_id' => $this->client->id, 'product_id' => $product->id]);
        $invoice = Invoice::factory()->paid()->create(['client_id' => $this->client->id]);
        $invoice->items()->create(['type' => 'service', 'service_id' => $service->id, 'description' => $product->name, 'amount' => 1000]);

        return $service;
    }
}
