<?php

namespace Tests\Feature\Billing;

use App\Billing\LineItems;
use App\Billing\RenewalGenerator;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ResourceChange;
use App\Models\ResourceChangeItem;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Models\ServiceResourceOption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Renewals bill a service's extras on their own lines, each at the price the service pays for it,
 * and every client page and email shows the full renewal: plan, add-ons and extras.
 */
class ResourceRenewalTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Product $vps;

    private ResourceOption $ram;

    private ResourceOption $cpu;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Mail::fake();
        $this->travelTo('2026-10-10');
        $this->setSettings(['billing.currency' => 'USD', 'billing.renewal_days_before' => 7]);
        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null, 'currency' => 'USD']);
        $this->vps = Product::factory()->priced(1000)->withoutDomain()->create(['name' => 'VPS 2']);
        $this->ram = ResourceOption::factory()->priced(400)->create(['product_id' => $this->vps->id]);
        $this->cpu = ResourceOption::factory()->kind('cpu', 1)->priced(500)->create(['product_id' => $this->vps->id, 'sort_order' => 1]);
    }

    public function test_renewals_bill_each_active_row_at_its_own_price(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'ALWAYS20', 'value' => 20, 'recurring' => Coupon::RECURRING_EVERY]);
        $service = $this->service(['domain' => 'vps1.example.test', 'coupon_id' => $coupon->id]);
        // Bought at 1.50 a step, before the price went up to 4.00.
        $ram = $this->row($service, $this->ram, 2, 300);
        $cpu = $this->row($service, $this->cpu, 1, 500);

        $this->assertSame(1, app(RenewalGenerator::class)->generate());

        $invoice = Invoice::query()->sole();
        $items = $invoice->items()->orderBy('id')->get();

        $this->assertSame([InvoiceItem::TYPE_SERVICE, InvoiceItem::TYPE_RESOURCE, InvoiceItem::TYPE_RESOURCE, InvoiceItem::TYPE_DISCOUNT], $items->pluck('type')->all());
        $this->assertSame(['RAM +2 GB - vps1.example.test (13 Oct 2026 - 12 Nov 2026)', 300, $ram->id], [$items[1]->description, $items[1]->amount, (int) $items[1]->service_resource_option_id]);
        $this->assertSame(['CPU cores +1 - vps1.example.test (13 Oct 2026 - 12 Nov 2026)', 500, $cpu->id], [$items[2]->description, $items[2]->amount, (int) $items[2]->service_resource_option_id]);
        $this->assertSame([$items[0]->period_start->toDateString(), $items[0]->period_end->toDateString()], [$items[1]->period_start->toDateString(), $items[1]->period_end->toDateString()]);
        $this->assertNull($items[1]->billing_key, 'The service line alone makes the renewal unique.');
        $this->assertSame(-200, $items[3]->amount, 'The coupon takes 20% off the plan, not off the extras.');
        $this->assertSame(1000 + 300 + 500 - 200, $invoice->total);

        $this->assertSame(0, app(RenewalGenerator::class)->generate(), 'A second run invoices nothing.');
    }

    public function test_a_free_plan_with_paid_extras_still_renews(): void
    {
        $service = $this->service(['recurring_amount' => 0]);
        $this->row($service, $this->ram, 1, 400);

        $this->assertSame(1, app(RenewalGenerator::class)->generate());

        $invoice = Invoice::query()->sole();
        $this->assertSame(400, $invoice->total);
        $this->assertSame([0, 400], $invoice->items()->orderBy('id')->pluck('amount')->all());
    }

    public function test_a_removal_planned_for_the_renewal_is_billed_with_the_new_steps(): void
    {
        $service = $this->service();
        $this->row($service, $this->ram, 4, 800);
        $ipv4 = ResourceOption::factory()->kind('ipv4', 1)->priced(300)->create(['product_id' => $this->vps->id, 'sort_order' => 2]);
        ResourceChange::factory()
            ->withItem($this->ram, 4, 1, 800, 200)
            ->withItem($ipv4, 0, 1, 0, 300)
            ->create(['service_id' => $service->id, 'mode' => ResourceChange::MODE_RENEWAL, 'apply_on' => $service->next_due_date, 'old_amount' => 800, 'new_amount' => 500, 'difference' => -300]);

        app(RenewalGenerator::class)->generate();

        $lines = InvoiceItem::query()->where('type', InvoiceItem::TYPE_RESOURCE)->orderBy('id')->get();

        $this->assertSame(['RAM +1 GB', 'IPv4 addresses +1'], $lines->map(fn (InvoiceItem $item): string => Str::before($item->description, ' - '))->all());
        $this->assertSame([200, 300], $lines->pluck('amount')->all());
        $this->assertSame(1500, Invoice::query()->sole()->total);
        $this->assertSame(4, $service->resources()->sole()->steps, 'The rows change on the renewal date, not when the invoice is made.');
    }

    public function test_cancelled_rows_and_zero_steps_are_not_billed(): void
    {
        $service = $this->service();
        ServiceResourceOption::factory()->cancelled()->create(['service_id' => $service->id, 'resource_option_id' => $this->ram->id]);
        $this->row($service, $this->cpu, 1, 0);

        // A free plan whose only extras are cancelled or free does not renew.
        $free = $this->service(['recurring_amount' => 0]);
        ServiceResourceOption::factory()->cancelled()->create(['service_id' => $free->id, 'resource_option_id' => $this->ram->id]);
        $this->row($free, $this->cpu, 1, 0);

        $this->assertSame(1, app(RenewalGenerator::class)->generate());

        $invoice = Invoice::query()->sole();
        $this->assertSame([InvoiceItem::TYPE_SERVICE], $invoice->items()->pluck('type')->all());
        $this->assertSame($service->id, (int) $invoice->items()->value('service_id'));
        $this->assertSame(1000, $service->fresh()->renewalTotal());
    }

    public function test_renewal_total_and_dashboard_show_plan_plus_extras(): void
    {
        $service = $this->service(['domain' => 'vps1.example.test']);
        ServiceAddon::query()->create(['service_id' => $service->id, 'name' => 'Daily backups', 'recurring_amount' => 250, 'status' => ServiceAddon::STATUS_ACTIVE]);
        $this->row($service, $this->ram, 1, 400);
        ServiceResourceOption::factory()->cancelled()->create(['service_id' => $service->id, 'resource_option_id' => $this->cpu->id]);

        $this->assertSame(1650, $service->renewalTotal());

        $this->actingAs($this->client, 'web');
        $this->get(route('client.dashboard'))->assertOk()->assertSee('$16.50');
        $this->get(route('client.services.index'))->assertOk()->assertSee('Renews 13 Oct 2026 for $16.50');
        $this->get(route('client.services.show', $service))->assertOk()->assertSee('$16.50')->assertSee('RAM +1 GB');

        $context = TemplateMailer::serviceContext($service->fresh());
        $this->assertSame(['$10.00', '$16.50'], [$context['service']['amount'], $context['service']['renewal_amount']]);
    }

    public function test_extras_lines_describe_what_changes_and_always_fit(): void
    {
        $service = $this->service(['domain' => str_repeat('a', 240).'.test']);
        $row = $this->row($service, $this->ram, 2, 800);
        $start = CarbonImmutable::parse('2026-10-15');
        $end = CarbonImmutable::parse('2026-10-31');

        $period = LineItems::resourcePeriod($service, $row, $start, 800);
        $this->assertSame(LineItems::DESCRIPTION_MAX, mb_strlen($period['description']));
        $this->assertStringStartsWith('RAM +2 GB - aaa', $period['description']);
        $this->assertStringEndsWith('… (15 Oct 2026 - 14 Nov 2026)', $period['description']);

        $setup = LineItems::resourceSetupFee($service, $row, 100);
        $this->assertSame(['Setup fee - RAM +2 GB', InvoiceItem::TYPE_RESOURCE, $row->id], [$setup['description'], $setup['type'], $setup['service_resource_option_id']]);

        $service->update(['domain' => 'vps1.example.test']);
        $change = ResourceChange::factory()->create(['service_id' => $service->id]);
        $item = fn (ResourceOption $option, int $from, int $to): ResourceChangeItem => $change->items()->create(['resource_option_id' => $option->id, 'kind' => $option->kind, 'unit' => $option->unit, 'step_size' => $option->step_size, 'from_steps' => $from, 'to_steps' => $to, 'old_amount' => 0, 'new_amount' => 0]);
        $backups = ResourceOption::factory()->kind('backups', 1)->priced(300)->create(['product_id' => $this->vps->id]);
        $size = ResourceOption::factory()->ladder(['CX23', 'CX33', 'CX43'])->priced(900)->create(['product_id' => $this->vps->id, 'label' => 'Server size']);

        $grow = LineItems::resourceChange($service, $item($this->ram, 2, 5), $start, $end, 329);
        $this->assertSame([InvoiceItem::TYPE_RESOURCE_CHANGE, 'RAM +2 GB → +5 GB - vps1.example.test (15 Oct 2026 - 31 Oct 2026)', 329, $row->id], [$grow['type'], $grow['description'], $grow['amount'], $grow['service_resource_option_id']]);
        $this->assertSame(['CPU cores 0 → +1', null], [Str::before(LineItems::resourceChange($service, $item($this->cpu, 0, 1), $start, $end, 0)['description'], ' - '), LineItems::resourceChange($service, $item($this->cpu, 0, 1), $start, $end, 0)['service_resource_option_id']]);
        $this->assertSame('Backups: Off → On', Str::before(LineItems::resourceChange($service, $item($backups, 0, 1), $start, $end, 0)['description'], ' - '));
        $this->assertSame('Server size: CX23 → CX43', Str::before(LineItems::resourceChange($service, $item($size, 0, 2), $start, $end, 0)['description'], ' - '));
    }

    /**
     * An active monthly VPS of Mer Las that is due in 3 days.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function service(array $attributes = []): Service
    {
        return Service::factory()->create([
            'client_id' => $this->client->id,
            'product_id' => $this->vps->id,
            'status' => ServiceStatus::Active,
            'recurring_amount' => 1000,
            'registration_date' => '2026-07-13',
            'next_due_date' => '2026-10-13',
            ...$attributes,
        ]);
    }

    private function row(Service $service, ResourceOption $option, int $steps, int $amount): ServiceResourceOption
    {
        return ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $option->id, 'steps' => $steps, 'recurring_amount' => $amount]);
    }
}
