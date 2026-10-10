<?php

namespace Tests\Feature\Billing;

use App\Billing\PlanChanges;
use App\Extensions\ExtensionManager;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * Plan changes next to resource options and the new module contracts: one waiting change at a
 * time, extras that move with the service, size steps that only fit the same ladder, modules that
 * allow only some moves, and services on the hardware inventory.
 */
class PlanChangeResourcesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        PanelFakeModule::reset();
        Mail::fake();

        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer.las@example.test', 'company_name' => null, 'currency' => 'USD']);
        $this->admin = Admin::factory()->create(['name' => 'Raz', 'email' => 'raz@example.test']);
    }

    public function test_a_waiting_resource_change_or_one_still_on_the_server_blocks_a_plan_change(): void
    {
        [$from, $to] = $this->plans();
        $service = $this->service($from);
        $change = ResourceChange::factory()->create(['service_id' => $service->id]);

        $this->assertSame('A resource change for this service is waiting.', app(PlanChanges::class)->blockedReason($service));
        $this->assertRefused('A resource change for this service is waiting.', fn () => app(PlanChanges::class)->start($service, $to));

        $change->update(['status' => ResourceChange::STATUS_APPLIED, 'applied_at' => now(), 'server_state' => ResourceChange::SERVER_QUEUED]);
        $this->assertSame('We are still setting the last change on your server.', app(PlanChanges::class)->blockedReason($service));

        foreach ([ResourceChange::SERVER_APPLYING, ResourceChange::SERVER_CHECKING] as $state) {
            $change->update(['server_state' => $state]);
            $this->assertSame('We are still setting the last change on your server.', app(PlanChanges::class)->blockedReason($service), $state);
        }

        // Staff can see a failed one and set it by hand; it does not hold the plan.
        $change->update(['server_state' => ResourceChange::SERVER_FAILED]);
        $this->assertNull(app(PlanChanges::class)->blockedReason($service));
        $this->assertSame(PlanChange::STATUS_PENDING, app(PlanChanges::class)->start($service, $to)->status);
        $this->assertSame(0, PlanChange::query()->where('status', PlanChange::STATUS_APPLIED)->count());
    }

    public function test_extras_move_with_the_service_to_the_new_plans_options(): void
    {
        [$from, $to] = $this->plans();
        $service = $this->service($from);
        $fromRam = ResourceOption::factory()->create(['product_id' => $from->id]);
        $fromIp = ResourceOption::factory()->kind('ipv4', 1)->create(['product_id' => $from->id]);
        $toRam = ResourceOption::factory()->create(['product_id' => $to->id]);
        ResourceOption::factory()->kind('ipv4', 2)->create(['product_id' => $to->id]);
        $ram = ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $fromRam->id, 'steps' => 2, 'recurring_amount' => 400]);
        $ip = ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $fromIp->id, 'steps' => 1, 'recurring_amount' => 300]);

        $change = app(PlanChanges::class)->start($service, $to, $this->admin, charge: false);

        $this->assertSame(PlanChange::STATUS_APPLIED, $change->status);
        $this->assertSame($to->id, $service->fresh()->product_id);
        $this->assertSame($toRam->id, $ram->fresh()->resource_option_id);
        $this->assertSame([2, 400], [$ram->fresh()->steps, $ram->fresh()->recurring_amount]);
        // No option of the same kind and step size: the extra stays, with no option.
        $this->assertNull($ip->fresh()->resource_option_id);
        $this->assertSame([1, 300, ServiceResourceOption::STATUS_ACTIVE], [$ip->fresh()->steps, $ip->fresh()->recurring_amount, $ip->fresh()->status]);
        $this->assertSame(['ram' => 2048, 'ipv4' => 1], $service->fresh()->resourceExtras()->units);
    }

    public function test_size_steps_move_only_to_a_plan_with_the_same_ladder(): void
    {
        [$from, $same] = $this->plans('panelfake');
        $other = Product::factory()->priced(3000)->create(['server_module' => 'panelfake', 'name' => 'Other sizes']);
        $from->update(['upgrade_product_ids' => [$same->id, $other->id]]);
        $size = ResourceOption::factory()->ladder()->create(['product_id' => $from->id, 'label' => 'Server size']);
        ResourceOption::factory()->ladder()->create(['product_id' => $same->id]);
        ResourceOption::factory()->ladder(['CPX11', 'CPX21', 'CPX31'])->create(['product_id' => $other->id]);
        $service = $this->service($from);
        $row = ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $size->id, 'steps' => 1]);

        $this->assertSame([$same->id], app(PlanChanges::class)->targets($service)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$same->id, $other->id], app(PlanChanges::class)->targets($service, staff: true)->pluck('id')->all());
        $this->assertRefused('Set Server size to 0 first.', fn () => app(PlanChanges::class)->start($service, $other, $this->admin, charge: false));
        $this->assertSame($from->id, $service->fresh()->product_id);

        // A retired option on the target does not count.
        ResourceOption::query()->where('product_id', $same->id)->update(['is_active' => false]);
        $this->assertSame([], app(PlanChanges::class)->targets($service)->pluck('id')->all());

        // Once the size extra is back to 0, any plan fits.
        $row->update(['steps' => 0, 'status' => ServiceResourceOption::STATUS_CANCELLED]);
        $this->assertEqualsCanonicalizing([$same->id, $other->id], app(PlanChanges::class)->targets($service)->pluck('id')->all());
        $this->assertSame(PlanChange::STATUS_APPLIED, app(PlanChanges::class)->start($service, $other, $this->admin, charge: false)->status);
    }

    public function test_a_module_that_limits_plan_changes_decides_the_targets(): void
    {
        [$from, $allowed] = $this->plans('panelfake');
        $refused = Product::factory()->priced(3000)->create(['server_module' => 'panelfake']);
        $from->update(['upgrade_product_ids' => [$allowed->id, $refused->id]]);
        $service = $this->service($from);
        PanelFakeModule::$behaviour['plan_changes'] = [$allowed->id];

        $this->assertSame([$allowed->id], app(PlanChanges::class)->targets($service)->pluck('id')->all());
        $this->assertSame([$allowed->id], app(PlanChanges::class)->targets($service, staff: true)->pluck('id')->all());
        $this->assertRefused('This plan needs a different server. Contact support.', fn () => app(PlanChanges::class)->start($service, $refused, $this->admin, charge: false));

        // A waiting upgrade stops when the module no longer allows it, and the plan stays.
        $change = app(PlanChanges::class)->start($service, $allowed);
        $this->assertSame(PlanChange::MODE_INVOICE, $change->mode);
        PanelFakeModule::$behaviour['plan_changes'] = [];

        $this->assertFalse(app(PlanChanges::class)->apply($change));
        $this->assertSame(PlanChange::STATUS_CANCELLED, $change->fresh()->status);
        $this->assertSame($from->id, $service->fresh()->product_id);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.plan_change_stopped')->where('description', 'like', '%needs a different server%')->exists());

        // A module that fails to answer refuses too.
        PanelFakeModule::$behaviour = ['throw' => ['allowsPlanChange']];
        $this->assertSame([], app(PlanChanges::class)->targets($service)->pluck('id')->all());
    }

    public function test_a_service_on_the_hardware_inventory_moves_on_the_server_without_a_server_record(): void
    {
        [$from, $to] = $this->plans('panelfake', usesMachines: true);
        $from->update(['upgrade_product_ids' => [$to->id]]);
        $service = $this->service($from);

        // The target has no free machine, but the service keeps its own, so no stock is counted.
        $this->assertSame(0, $to->stockLeft());
        $this->assertSame([$to->id], app(PlanChanges::class)->targets($service)->pluck('id')->all());
        $this->assertSame(PlanChange::MODE_INVOICE, app(PlanChanges::class)->start($service, $to)->mode);
        PlanChange::query()->update(['status' => PlanChange::STATUS_CANCELLED]);
        $service->invoiceItems()->delete();

        $change = app(PlanChanges::class)->start($service->fresh(), $to, $this->admin, charge: false);

        $this->assertSame(PlanChange::STATUS_APPLIED, $change->status);
        $this->assertNull($service->fresh()->server_id);
        $calls = PanelFakeModule::calls('changePackage');
        $this->assertCount(1, $calls, 'The module moved the service to the new plan.');
        $this->assertSame($to->id, $calls[0]['args']['product']);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.package_queued')->exists());
        $this->assertTrue(ActivityLog::query()->where('action', 'service.package_changed')->exists());
    }

    /**
     * Two plans of 10.00 and 20.00 a month, the first allowing a move to the second.
     *
     * @return array{0: Product, 1: Product}
     */
    private function plans(?string $module = null, bool $usesMachines = false): array
    {
        $from = Product::factory()->priced(1000)->create(['name' => 'Starter', 'server_module' => $module, 'uses_machines' => $usesMachines]);
        $to = Product::factory()->priced(2000)->create(['name' => 'Business', 'server_module' => $module, 'uses_machines' => $usesMachines]);
        $from->update(['upgrade_product_ids' => [$to->id]]);

        return [$from, $to];
    }

    private function service(Product $product): Service
    {
        return Service::factory()->create([
            'client_id' => $this->client->id,
            'product_id' => $product->id,
            'server_id' => null,
            'domain' => 'shop.example.net',
            'recurring_amount' => 1000,
            'next_due_date' => '2026-10-16',
        ]);
    }

    private function assertRefused(string $message, callable $start): void
    {
        try {
            $start();
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail("The change was not refused with: {$message}");
    }
}
