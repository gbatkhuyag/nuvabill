<?php

namespace Tests\Feature\Billing;

use App\Automation\DailyAutomation;
use App\Billing\CreditNotes;
use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\PlanChanges;
use App\Billing\RenewalGenerator;
use App\Billing\ResourceChanges;
use App\Billing\Wallet;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Jobs\ApplyServiceResources;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Models\TaxRule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * Mid-cycle changes of a service's extras: the fair price for the days left, an upgrade invoice
 * applied once paid, removals that follow the downgrade setting, credits capped by what was paid,
 * stock, and the one-change-at-a-time rule shared with plan changes.
 *
 * The worked example of the spec: a monthly service due 1 Nov 2026 (registered on the 1st), today
 * 15 Oct, so the period has 31 days and 17 are left. RAM is 1 GB a step at $2.00.
 */
class ResourceChangeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Product $vps;

    private ResourceOption $ram;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        PanelFakeModule::reset();
        Mail::fake();

        $this->travelTo('2026-10-15 10:00');
        $this->setSettings([
            'billing.currency' => 'USD',
            'billing.downgrade' => 'credit',
            'billing.resource_changes' => true,
            'wallet.enabled' => true,
            'company.email' => 'billing@example.test',
        ]);

        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null, 'email' => 'mer.las@example.test', 'currency' => 'USD']);
        $this->vps = Product::factory()->priced(1000)->withoutDomain()->create(['name' => 'VPS 2']);
        $this->ram = ResourceOption::factory()->priced(200)->create(['product_id' => $this->vps->id]);
        $this->service = Service::factory()->create([
            'client_id' => $this->client->id,
            'product_id' => $this->vps->id,
            'status' => ServiceStatus::Active,
            'recurring_amount' => 1000,
            'registration_date' => '2026-07-01',
            'next_due_date' => '2026-11-01',
            'domain' => 'vps1.example.test',
        ]);
    }

    public function test_quote_charges_added_steps_for_the_days_left_plus_setup(): void
    {
        $this->ram->prices()->update(['setup_fee' => 50]);
        $this->row($this->ram, 2, 400);

        $quote = app(ResourceChanges::class)->quote($this->service->fresh(), ['ram' => 5]);

        $this->assertSame([31, 17], [$quote['period_days'], $quote['days_left']]);
        $this->assertSame(['from' => 2, 'to' => 5, 'old_amount' => 400, 'new_amount' => 1000, 'cost' => 329, 'credit' => 0, 'setup_fee' => 150], array_intersect_key($quote['items'][0], array_flip(['from', 'to', 'old_amount', 'new_amount', 'cost', 'credit', 'setup_fee'])));
        $this->assertSame([329 + 150, 'invoice', 400, 1000], [$quote['difference'], $quote['mode'], $quote['old'], $quote['new']]);

        $present = app(ResourceChanges::class)->present($this->service->fresh(), $quote);
        $this->assertSame('Pay $4.79 and upgrade', $present['button']);
        $this->assertSame(479, $present['expected']);
        $this->assertContains(['label' => 'New price', 'value' => '$20.00/mo', 'strong' => true], $present['lines'], 'Plan $10 plus RAM $10.');
    }

    public function test_the_price_to_pay_includes_tax_added_on_top(): void
    {
        $this->setSettings(['tax.enabled' => true, 'tax.inclusive' => false]);
        TaxRule::query()->create(['name' => 'VAT', 'rate' => 1000, 'country' => null]);
        $this->row($this->ram, 2, 400);

        $present = app(ResourceChanges::class)->present($this->service->fresh(), app(ResourceChanges::class)->quote($this->service->fresh(), ['ram' => 5]));

        $this->assertSame('Pay $3.62 and upgrade', $present['button'], '$3.29 plus 10% VAT.');
        $this->assertSame(['label' => 'VAT (10%)', 'amount' => 'Plus $0.33'], $present['tax']);
        $this->assertSame(329, $present['expected'], 'The price check compares the amount before tax.');

        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 5], expected: 329);
        $this->assertSame(362, $change->invoice->total);
    }

    public function test_an_upgrade_is_invoiced_and_applied_once_paid(): void
    {
        Queue::fake();
        $this->withModule();
        $this->ram->prices()->update(['setup_fee' => 50]);
        $row = $this->row($this->ram, 2, 400);

        $this->actingAs($this->client, 'web')
            ->post(route('client.services.resources.store', $this->service), ['steps' => ['ram' => 5], 'expected' => 479])
            ->assertRedirect()
            ->assertSessionHas('status', 'Pay this invoice and your new resources are added straight away.');

        $change = ResourceChange::query()->sole();
        $invoice = $change->invoice;
        $this->assertSame([ResourceChange::MODE_INVOICE, ResourceChange::STATUS_PENDING, 479], [$change->mode, $change->status, $invoice->total]);
        $this->assertSame(
            [['resource_change', 'RAM +2 GB → +5 GB - vps1.example.test (15 Oct 2026 - 31 Oct 2026)', 329, $row->id], ['resource_change', 'Setup fee - RAM +3 GB', 150, $row->id]],
            $invoice->items()->orderBy('id')->get()->map(fn (InvoiceItem $item): array => [$item->type, $item->description, $item->amount, (int) $item->service_resource_option_id])->all(),
        );
        $this->assertSame(2, $row->fresh()->steps, 'Nothing changes before the invoice is paid.');
        Queue::assertNothingPushed();

        app(PaymentRecorder::class)->record($invoice, 479, 'banktransfer');

        $this->assertSame([5, 1000, ServiceResourceOption::STATUS_ACTIVE], [$row->fresh()->steps, $row->fresh()->recurring_amount, $row->fresh()->status]);
        $change->refresh();
        $this->assertSame([ResourceChange::STATUS_APPLIED, ResourceChange::SERVER_QUEUED], [$change->status, $change->server_state]);
        Queue::assertPushed(ApplyServiceResources::class, fn (ApplyServiceResources $job): bool => $job->changeId === $change->id);
        $this->assertSame(2000, $this->service->fresh()->renewalTotal());

        // The job sets the server to the plan plus the new extras, and tells the client.
        app(ResourceChanges::class)->applyToServer($change->id);

        $this->assertSame(['ram' => 5 * 1024], PanelFakeModule::$applied[$this->service->id]);
        $this->assertSame(ResourceChange::SERVER_DONE, $change->fresh()->server_state);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('mer.las@example.test')
            && $mail->subjectLine === 'Your server resources changed'
            && str_contains($mail->bodyHtml, 'RAM: +5 GB'));
    }

    public function test_paying_the_upgrade_twice_applies_it_once(): void
    {
        Queue::fake();
        $this->withModule();
        $row = $this->row($this->ram, 2, 400);
        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 3]);

        app(PaymentRecorder::class)->record($change->invoice, $change->invoice->total, 'banktransfer', 'TX-1');
        app(PaymentRecorder::class)->record($change->invoice, $change->invoice->total, 'banktransfer', 'TX-1');
        app(ResourceChanges::class)->applyForInvoice($change->invoice);

        $this->assertFalse(app(ResourceChanges::class)->apply($change->fresh()));
        $this->assertSame([3, 600], [$row->fresh()->steps, $row->fresh()->recurring_amount]);
        Queue::assertPushed(ApplyServiceResources::class, 1);
    }

    public function test_an_upgrade_not_paid_before_renewal_stops_and_money_goes_back_to_the_wallet(): void
    {
        $this->setSettings(['billing.renewal_days_before' => 7]);
        $row = $this->row($this->ram, 2, 400);
        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 5]);
        app(PaymentRecorder::class)->record($change->invoice, 100, 'banktransfer');

        $this->travelTo('2026-10-26 09:00');
        app(RenewalGenerator::class)->generate();

        $this->assertSame(ResourceChange::STATUS_CANCELLED, $change->fresh()->status);
        $this->assertSame(InvoiceStatus::Cancelled, $change->invoice->fresh()->status);
        $this->assertSame(100, (int) CreditTransaction::query()->where('client_id', $this->client->id)->where('amount', '>', 0)->sum('amount'), 'What was paid went back to the wallet.');
        $this->assertSame(2, $row->fresh()->steps);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.resource_change_expired')->exists());

        $renewal = Invoice::query()->whereHas('items', fn ($query) => $query->where('type', InvoiceItem::TYPE_SERVICE))->sole();
        $this->assertSame(1400, $renewal->total, 'The renewal bills the extras the service still has.');
        $this->assertSame(100, $renewal->amount_paid, 'The wallet money paid part of the renewal.');
    }

    public function test_removing_extras_with_downgrade_credit_adds_the_unused_days_to_the_wallet(): void
    {
        $row = $this->row($this->ram, 5, 1000);

        $this->actingAs($this->client, 'web')
            ->post(route('client.services.resources.store', $this->service), ['steps' => ['ram' => 1], 'expected' => -439])
            ->assertRedirect(route('client.services.show', $this->service).'#resources');

        $this->assertSame([1, 200], [$row->fresh()->steps, $row->fresh()->recurring_amount]);
        $this->assertSame(439, $this->client->fresh()->credit);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(ResourceChange::SERVER_MANUAL, ResourceChange::query()->sole()->server_state, 'A product without a module is set by hand.');
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('billing@example.test') && $mail->subjectLine === "Set resources by hand on service #{$this->service->id}");
    }

    public function test_removing_extras_with_downgrade_renewal_waits_for_the_renewal_date(): void
    {
        $this->setSettings(['billing.downgrade' => 'renewal', 'billing.renewal_days_before' => 7]);
        $row = $this->row($this->ram, 5, 1000);

        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 1]);

        $this->assertSame([ResourceChange::MODE_RENEWAL, '2026-11-01'], [$change->mode, $change->apply_on->toDateString()]);
        $this->assertSame(5, $row->fresh()->steps);
        $this->assertSame(0, $this->client->fresh()->credit);

        // The night of the renewal applies it first, so the renewal bills the new extras.
        app(DailyAutomation::class)->run(CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(ResourceChange::STATUS_APPLIED, $change->fresh()->status);
        $this->assertSame([1, 200], [$row->fresh()->steps, $row->fresh()->recurring_amount]);
        $this->assertSame(1200, Invoice::query()->whereHas('items', fn ($query) => $query->where('type', InvoiceItem::TYPE_SERVICE))->sole()->total);
    }

    public function test_removal_waits_for_renewal_when_the_wallet_is_off_or_in_another_currency(): void
    {
        $this->row($this->ram, 5, 1000);

        $this->setSettings(['wallet.enabled' => false]);
        $this->assertSame(ResourceChange::MODE_RENEWAL, app(ResourceChanges::class)->quote($this->service->fresh(), ['ram' => 1])['mode']);

        $this->setSettings(['wallet.enabled' => true]);
        $this->client->update(['currency' => 'EUR']);
        $this->assertSame(ResourceChange::MODE_RENEWAL, app(ResourceChanges::class)->quote($this->service->fresh(), ['ram' => 1])['mode']);
    }

    public function test_credit_never_exceeds_what_was_paid(): void
    {
        $row = $this->row($this->ram, 5, 1000);
        $quote = fn (): int => app(ResourceChanges::class)->quote($this->service->fresh(), ['ram' => 1])['credit'];

        $this->assertSame(439, $quote(), 'A row without a paid line that no change grew (imported) has no cap.');

        $invoice = $this->paidLine($row, 1000);
        // 1000 × 17/31 paid for the days left, 4 of 5 steps of it.
        $this->assertSame(438, $quote());

        app(CreditNotes::class)->issue($invoice, 500, CreditNote::METHOD_NONE);
        $this->assertSame(219, $quote(), 'Half the invoice was given back.');

        $invoice->refresh()->update(['status' => InvoiceStatus::Refunded]);
        $this->assertSame(0, $quote(), 'A refunded period gives nothing back.');
    }

    public function test_extras_staff_gave_without_charging_give_nothing_back(): void
    {
        app(ResourceChanges::class)->start($this->service, ['ram' => 5], Admin::factory()->create(['name' => 'Raz']), charge: false);
        $row = $this->service->resources()->where('kind', 'ram')->sole();
        $this->assertSame([5, 1000], [$row->steps, $row->recurring_amount]);

        $quote = app(ResourceChanges::class)->quote($this->service->fresh(), ['ram' => 0]);
        $this->assertSame([0, 0, ResourceChange::MODE_NOW], [$quote['items'][0]['credit'], $quote['difference'], $quote['mode']], 'Without the cap this was 1000 × 17/31 = 548.');

        $this->actingAs($this->client, 'web')
            ->post(route('client.services.resources.store', $this->service), ['steps' => ['ram' => 0], 'expected' => 0])
            ->assertRedirect(route('client.services.show', $this->service).'#resources');

        $this->assertSame([0, ServiceResourceOption::STATUS_CANCELLED], [$row->fresh()->steps, $row->fresh()->status]);
        $this->assertSame(0, $this->client->fresh()->credit);
    }

    public function test_growth_paid_by_a_netted_credit_gives_back_at_most_its_cost(): void
    {
        ResourceOption::factory()->kind('cpu', 1)->priced(500)->create(['product_id' => $this->vps->id, 'sort_order' => 1]);
        $this->paidLine($this->row($this->ram, 5, 1000), 1000);

        // RAM 5 → 1 gives back 438 (capped by its paid line); one core costs 500 × 17/31 = 274.
        $first = app(ResourceChanges::class)->start($this->service, ['ram' => 1, 'cpu' => 1]);
        $this->assertSame([ResourceChange::MODE_NOW, -164], [$first->mode, $first->difference]);
        $this->assertSame(164, $this->client->fresh()->credit);

        // The core had no paid line of its own: it gives back what it cost, no more.
        $quote = app(ResourceChanges::class)->quote($this->service->fresh(), ['cpu' => 0]);
        $this->assertSame(274, $quote['credit']);

        app(ResourceChanges::class)->start($this->service, ['cpu' => 0]);
        $this->assertSame(438, $this->client->fresh()->credit, 'All of it is what the removed RAM was paid.');
        $this->assertSame(110, app(ResourceChanges::class)->quote($this->service->fresh(), ['ram' => 0])['credit'], 'The last RAM step keeps its paid line: 200 × 17/31.');
    }

    public function test_a_kind_added_on_the_renewal_date_is_billed_on_its_own_row(): void
    {
        $this->setSettings(['billing.downgrade' => 'renewal', 'billing.renewal_days_before' => 7]);
        ResourceOption::factory()->kind('cpu', 1)->priced(500)->create(['product_id' => $this->vps->id, 'sort_order' => 1]);
        $this->row($this->ram, 5, 1000);

        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 1, 'cpu' => 1]);

        $this->assertSame(ResourceChange::MODE_RENEWAL, $change->mode);
        $cpu = $this->service->resources()->where('kind', 'cpu')->sole();
        $this->assertSame([0, ServiceResourceOption::STATUS_CANCELLED], [$cpu->steps, $cpu->status], 'An empty row waits for the renewal date.');

        $this->travelTo('2026-10-26 09:00');
        app(RenewalGenerator::class)->generate();
        $renewal = Invoice::query()->whereHas('items', fn ($query) => $query->where('type', InvoiceItem::TYPE_SERVICE))->sole();
        $line = $renewal->items()->where('type', InvoiceItem::TYPE_RESOURCE)->where('description', 'like', 'CPU cores%')->sole();
        $this->assertSame([500, $cpu->id], [$line->amount, (int) $line->service_resource_option_id]);
        app(PaymentRecorder::class)->record($renewal, $renewal->total, 'banktransfer');

        $this->travelTo('2026-11-01 01:00');
        app(ResourceChanges::class)->applyScheduled();
        $this->assertSame([1, 500, ServiceResourceOption::STATUS_ACTIVE], [$cpu->fresh()->steps, $cpu->fresh()->recurring_amount, $cpu->fresh()->status]);

        // Half way through November: the core's paid renewal line caps what removing it gives back.
        $this->travelTo('2026-11-15 10:00');
        $credit = fn (): int => app(ResourceChanges::class)->quote($this->service->fresh(), ['cpu' => 0])['credit'];
        $this->assertSame(267, $credit(), '500 × 16/30, all of it paid.');

        $renewal->refresh()->update(['status' => InvoiceStatus::Refunded]);
        $this->assertSame(0, $credit(), 'A refunded renewal gives nothing back.');
    }

    public function test_a_switch_value_locks_a_stock_option_before_the_service(): void
    {
        $ipv4 = ResourceOption::factory()->kind('ipv4', 1)->priced(0)->create(['product_id' => $this->vps->id, 'stock' => 5]);
        DB::enableQueryLog();

        $change = app(ResourceChanges::class)->start($this->service, ['ipv4' => true]);

        $queries = array_column(DB::getQueryLog(), 'query');
        $optionLock = array_key_first(array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select "id" from "resource_options" where "resource_options"."id" in')));
        $this->assertNotNull($optionLock, 'The steps true and 1 read the same, so the option was locked first.');
        $this->assertSame([ResourceChange::MODE_NOW, ResourceChange::STATUS_APPLIED], [$change->mode, $change->status]);
        $this->assertSame([1, 4], [(int) $this->service->resources()->where('kind', 'ipv4')->value('steps'), $ipv4->stockLeft()]);
    }

    public function test_an_upgrade_the_wallet_pays_at_once_goes_back_to_the_service_page(): void
    {
        Queue::fake();
        $this->withModule();
        $this->setSettings(['wallet.auto_apply' => true]);
        app(Wallet::class)->change($this->client, 5000, 'Top up');
        $row = $this->row($this->ram, 2, 400);

        $this->actingAs($this->client, 'web')
            ->post(route('client.services.resources.store', $this->service), ['steps' => ['ram' => 5], 'expected' => 329])
            ->assertRedirect(route('client.services.show', $this->service).'#resources')
            ->assertSessionHas('status', 'Your resources are changing now. This can take a few minutes.');

        $change = ResourceChange::query()->sole();
        $this->assertSame([ResourceChange::STATUS_APPLIED, InvoiceStatus::Paid], [$change->status, $change->invoice->status]);
        $this->assertSame(5, $row->fresh()->steps);
        $this->assertSame(5000 - 329, $this->client->fresh()->credit);
    }

    public function test_change_lines_take_their_arrow_from_the_translation(): void
    {
        // A right-to-left language turns the arrow round, so it points from the old to the new value.
        app('translator')->addLines(['*.:from → :to' => ':from ← :to'], 'ar');
        $this->row($this->ram, 2, 400);
        app()->setLocale('ar');

        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 5]);

        app()->setLocale('en');
        $this->assertStringContainsString('+2 GB ← +5 GB', $change->invoice->items()->orderBy('id')->firstOrFail()->description);
    }

    public function test_a_mixed_change_nets_the_credit_against_the_cost(): void
    {
        $cpu = ResourceOption::factory()->kind('cpu', 1)->priced(500)->create(['product_id' => $this->vps->id, 'sort_order' => 1]);
        $this->row($this->ram, 5, 1000);

        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 1, 'cpu' => 2]);

        // CPU: 1000 × 17/31 = 548. RAM: 800 × 17/31 = 439 back.
        $this->assertSame([ResourceChange::MODE_INVOICE, 109], [$change->mode, $change->difference]);
        $lines = $change->invoice->items()->orderBy('id')->get();
        $this->assertSame([548, -439], $lines->pluck('amount')->all());
        $this->assertStringStartsWith('CPU cores 0 → +2', $lines[0]->description);
        $this->assertStringStartsWith('Unused days of removed extras - vps1.example.test', $lines[1]->description);
        $this->assertSame(109, $change->invoice->total);

        app(PaymentRecorder::class)->record($change->invoice, 109, 'banktransfer');

        $this->assertSame(['cpu' => [2, 1000], 'ram' => [1, 200]], $this->service->resources()->orderBy('kind')->get()->mapWithKeys(fn (ServiceResourceOption $row): array => [$row->kind => [$row->steps, $row->recurring_amount]])->all());
        $this->assertSame($cpu->id, (int) $this->service->resources()->where('kind', 'cpu')->value('resource_option_id'));
    }

    public function test_disk_cannot_go_below_what_the_service_has(): void
    {
        $disk = ResourceOption::factory()->kind('disk', 10)->priced(100)->create(['product_id' => $this->vps->id]);
        $this->row($disk, 3, 300);

        try {
            app(ResourceChanges::class)->quote($this->service->fresh(), ['disk' => 1]);
            $this->fail('Disk went down.');
        } catch (ValidationException $exception) {
            $this->assertSame(['steps.disk' => ['Disk space cannot go below what you have now.']], $exception->errors());
        }

        $this->assertSame(4, app(ResourceChanges::class)->quote($this->service->fresh(), ['disk' => 4])['items'][0]['to'], 'It can grow.');
    }

    public function test_staff_cannot_lower_disk_either_so_nothing_is_credited_for_a_disk_that_stays(): void
    {
        $disk = ResourceOption::factory()->kind('disk', 10)->priced(100)->create(['product_id' => $this->vps->id]);
        $diskRow = $this->row($disk, 3, 300);
        $ramRow = $this->row($this->ram, 2, 400);
        $admin = Admin::factory()->create(['name' => 'Raz']);
        $changes = app(ResourceChanges::class);

        try {
            $changes->quote($this->service->fresh(), ['disk' => 1], staff: true);
            $this->fail('Staff lowered the disk.');
        } catch (ValidationException $exception) {
            $this->assertSame(['steps.disk' => ['Disk space cannot go below what you have now.']], $exception->errors());
        }

        // Neither with an invoice (the removal would credit the wallet) nor without charging.
        foreach ([true, false] as $charge) {
            try {
                $changes->start($this->service->fresh(), ['disk' => 0], $admin, charge: $charge);
                $this->fail('Staff removed the disk.');
            } catch (ValidationException $exception) {
                $this->assertSame(['steps.disk' => ['Disk space cannot go below what you have now.']], $exception->errors());
            }
        }

        $this->assertSame([3, 300], [$diskRow->fresh()->steps, $diskRow->fresh()->recurring_amount]);
        $this->assertSame(0, $this->client->fresh()->credit);
        $this->assertSame(0, ResourceChange::query()->count());

        // Staff still lower what can shrink, below what clients may, and grow the disk.
        $this->ram->update(['client_can_remove' => false]);
        $this->assertSame(0, $changes->quote($this->service->fresh(), ['ram' => 0], staff: true)['items'][0]['to']);
        $this->assertSame(5, $changes->quote($this->service->fresh(), ['disk' => 5], staff: true)['items'][0]['to']);
        $this->assertSame(2, $ramRow->fresh()->steps);
    }

    public function test_changes_are_blocked_while_a_plan_change_or_open_invoice_waits_and_the_other_way_round(): void
    {
        $changes = app(ResourceChanges::class);
        $plan = PlanChange::query()->create(['service_id' => $this->service->id, 'client_id' => $this->client->id, 'from_product_id' => $this->vps->id, 'to_product_id' => $this->vps->id, 'billing_cycle' => 'monthly', 'currency' => 'USD', 'old_amount' => 1000, 'new_amount' => 1000, 'difference' => 0, 'mode' => PlanChange::MODE_RENEWAL, 'status' => PlanChange::STATUS_PENDING, 'apply_on' => '2026-11-01']);
        $this->assertSame('A plan change for this service is already waiting.', $changes->blockedReason($this->service));
        $plan->update(['status' => PlanChange::STATUS_CANCELLED]);

        $open = app(InvoiceManager::class)->create($this->client, [['description' => 'Extra work', 'amount' => 500, 'service_id' => $this->service->id]]);
        $this->assertSame('Pay the open invoice for this service first.', $changes->blockedReason($this->service));
        app(InvoiceManager::class)->cancel($open);
        $this->assertNull($changes->blockedReason($this->service));

        $change = $changes->start($this->service, ['ram' => 1]);
        $this->assertTrue($change->isPending());
        $this->assertSame('A resource change for this service is already waiting.', $changes->blockedReason($this->service));
        $this->assertSame('A resource change for this service is waiting.', app(PlanChanges::class)->blockedReason($this->service->fresh()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A resource change for this service is already waiting.');
        $changes->start($this->service, ['ram' => 2], Admin::factory()->create(['name' => 'Raz']));
    }

    public function test_a_hidden_or_add_locked_option_is_read_only_for_clients(): void
    {
        $this->ram->update(['client_can_add' => false]);
        $this->row($this->ram, 2, 400);

        $this->assertQuoteRefused(['ram' => 3], 'Choose between 0 and 2 for RAM.');
        $this->assertSame(1, app(ResourceChanges::class)->quote($this->service->fresh(), ['ram' => 1])['items'][0]['to'], 'Removing still works.');

        $this->ram->update(['client_can_add' => true, 'is_active' => false]);
        $this->assertQuoteRefused(['ram' => 3], 'Choose between 0 and 2 for RAM.');

        // Staff may still add.
        $this->assertSame(3, app(ResourceChanges::class)->quote($this->service->fresh(), ['ram' => 3], staff: true)['items'][0]['to']);
    }

    public function test_unknown_kinds_and_bad_steps_are_refused_with_plain_words(): void
    {
        $this->assertQuoteRefused(['hacked' => 1], 'We do not know the option hacked.');
        $this->assertQuoteRefused(['ram' => 9], 'Choose between 0 and 8 for RAM.');
        $this->assertQuoteRefused(['ram' => 'abc'], 'Choose between 0 and 8 for RAM.');
        $this->assertQuoteRefused(array_fill_keys(array_map(fn (int $i): string => 'kind'.$i, range(1, 21)), 1), 'Choose at most 20 extras.');
    }

    public function test_a_double_submit_makes_one_change(): void
    {
        $this->actingAs($this->client, 'web');
        $this->post(route('client.services.resources.store', $this->service), ['steps' => ['ram' => 2]])->assertRedirect();
        $this->from(route('client.services.show', $this->service))
            ->post(route('client.services.resources.store', $this->service), ['steps' => ['ram' => 2]])
            ->assertRedirect(route('client.services.show', $this->service))
            ->assertSessionHas('error', 'A resource change for this service is already waiting.');

        $this->assertSame(1, ResourceChange::query()->count());
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_a_changed_price_asks_the_client_to_check_again(): void
    {
        $this->actingAs($this->client, 'web')
            ->from(route('client.services.show', $this->service))
            ->post(route('client.services.resources.store', $this->service), ['steps' => ['ram' => 2], 'expected' => 100])
            ->assertSessionHasErrors(['expected' => 'Prices changed. Check the new price and try again.']);

        $this->assertSame(0, ResourceChange::query()->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_stopping_a_waiting_upgrade_cancels_its_invoice(): void
    {
        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 2]);

        $this->actingAs($this->client, 'web')
            ->delete(route('client.services.resources.destroy', $this->service))
            ->assertSessionHas('status', 'The resource change was stopped.');

        $this->assertSame(ResourceChange::STATUS_CANCELLED, $change->fresh()->status);
        $this->assertSame(InvoiceStatus::Cancelled, $change->invoice->fresh()->status);
        $this->assertNull(app(ResourceChanges::class)->blockedReason($this->service->fresh()));
    }

    public function test_cancelling_the_invoice_cancels_the_change(): void
    {
        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 2]);

        app(InvoiceManager::class)->cancel($change->invoice);

        $this->assertSame(ResourceChange::STATUS_CANCELLED, $change->fresh()->status);
    }

    public function test_staff_can_change_resources_now_without_charging_and_set_a_renewal_price(): void
    {
        $row = $this->row($this->ram, 2, 400);
        $admin = Admin::factory()->create(['name' => 'Raz']);

        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 5], $admin, charge: false, amounts: ['ram' => 1200]);

        $this->assertSame([ResourceChange::MODE_NOW, 0, ResourceChange::STATUS_APPLIED], [$change->mode, $change->difference, $change->status]);
        $this->assertSame([5, 1200], [$row->fresh()->steps, $row->fresh()->recurring_amount]);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, $this->client->fresh()->credit);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.resources_edited')->exists());

        // Only the price may change, too.
        $price = app(ResourceChanges::class)->start($this->service, ['ram' => 5], $admin, charge: false, amounts: ['ram' => 900]);
        $this->assertSame(ResourceChange::STATUS_APPLIED, $price->status);
        $this->assertSame([5, 900], [$row->fresh()->steps, $row->fresh()->recurring_amount]);
    }

    public function test_staff_can_invoice_a_change_to_the_client(): void
    {
        $this->row($this->ram, 2, 400);

        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 5], Admin::factory()->create(['name' => 'Raz']));

        $this->assertSame([ResourceChange::MODE_INVOICE, 329], [$change->mode, $change->invoice->total]);
        $this->assertSame($this->client->id, $change->invoice->client_id);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('mer.las@example.test') && str_contains($mail->subjectLine, $change->invoice->displayNumber()));
    }

    public function test_resource_stock_is_counted_at_apply_and_a_sold_out_upgrade_is_refunded(): void
    {
        $ipv4 = ResourceOption::factory()->kind('ipv4', 1)->priced(300)->create(['product_id' => $this->vps->id, 'stock' => 4]);
        $row = $this->row($ipv4, 1, 300);
        $change = app(ResourceChanges::class)->start($this->service, ['ipv4' => 3]);
        $paid = $change->invoice->total;

        // Another client takes the last addresses while the invoice waits.
        $other = Service::factory()->create(['product_id' => $this->vps->id]);
        ServiceResourceOption::factory()->create(['service_id' => $other->id, 'resource_option_id' => $ipv4->id, 'steps' => 3]);

        app(PaymentRecorder::class)->record($change->invoice, $paid, 'banktransfer');

        $this->assertSame(ResourceChange::STATUS_CANCELLED, $change->fresh()->status);
        $this->assertSame(1, $row->fresh()->steps);
        $this->assertSame($paid, $this->client->fresh()->credit, 'The paid invoice went back to the wallet with a credit note.');
        $this->assertSame(1, CreditNote::query()->count());
        $this->assertTrue(ActivityLog::query()->where('action', 'service.resource_change_stopped')->where('description', 'like', '%There is no more IPv4 addresses left right now.%')->exists());
    }

    public function test_a_client_change_that_happens_now_counts_the_stock(): void
    {
        $ipv4 = ResourceOption::factory()->kind('ipv4', 1)->priced(0)->create(['product_id' => $this->vps->id, 'stock' => 2]);
        $other = Service::factory()->create(['product_id' => $this->vps->id]);
        ServiceResourceOption::factory()->create(['service_id' => $other->id, 'resource_option_id' => $ipv4->id, 'steps' => 1]);

        // Free addresses change now; only one is left, and the slider already says so.
        $this->assertQuoteRefused(['ipv4' => 2], 'Choose between 0 and 1 for IPv4 addresses.');
        $change = app(ResourceChanges::class)->start($this->service, ['ipv4' => 1]);

        $this->assertSame([ResourceChange::MODE_NOW, ResourceChange::STATUS_APPLIED], [$change->mode, $change->status]);
        $this->assertSame(0, $ipv4->stockLeft());
    }

    public function test_a_plan_change_keeps_extras_and_relinks_them_to_the_new_plans_options(): void
    {
        $large = Product::factory()->priced(2000)->withoutDomain()->create(['name' => 'VPS 4']);
        $largeRam = ResourceOption::factory()->priced(250)->create(['product_id' => $large->id]);
        $this->vps->update(['upgrade_product_ids' => [$large->id]]);
        $row = $this->row($this->ram, 2, 400);

        $this->actingAs($this->client, 'web')
            ->get(route('client.services.change-plan', $this->service))
            ->assertOk()
            ->assertSee('Your extras stay: RAM +2 GB ($4.00/mo). You can lower them after the move.');

        app(PlanChanges::class)->start($this->service->fresh(), $large, Admin::factory()->create(['name' => 'Raz']), charge: false);

        $this->assertSame([$largeRam->id, 2, 400], [(int) $row->fresh()->resource_option_id, $row->fresh()->steps, $row->fresh()->recurring_amount]);
        $this->assertSame(3, app(ResourceChanges::class)->quote($this->service->fresh(), ['ram' => 3])['items'][0]['to']);
    }

    public function test_the_server_busy_rule_waits_for_the_last_change_for_clients_only(): void
    {
        ResourceChange::factory()->applied()->create(['service_id' => $this->service->id, 'server_state' => ResourceChange::SERVER_CHECKING]);

        $this->assertSame('We are still setting the last change on your server.', app(ResourceChanges::class)->blockedReason($this->service));
        $this->assertNull(app(ResourceChanges::class)->blockedReason($this->service, staff: true));
        $this->assertSame('We are still setting the last change on your server.', app(PlanChanges::class)->blockedReason($this->service));
    }

    /**
     * @param  array<mixed>  $steps
     */
    private function assertQuoteRefused(array $steps, string $message): void
    {
        try {
            app(ResourceChanges::class)->quote($this->service->fresh(), $steps);
            $this->fail("The quote was not refused: {$message}");
        } catch (ValidationException $exception) {
            $this->assertContains($message, collect($exception->errors())->flatten()->all());
        }
    }

    private function row(ResourceOption $option, int $steps, int $amount): ServiceResourceOption
    {
        return ServiceResourceOption::factory()->create(['service_id' => $this->service->id, 'resource_option_id' => $option->id, 'steps' => $steps, 'recurring_amount' => $amount]);
    }

    /**
     * A paid order or renewal line for the row's October period.
     */
    private function paidLine(ServiceResourceOption $row, int $amount): Invoice
    {
        $invoice = app(InvoiceManager::class)->create($this->client, [[
            'type' => InvoiceItem::TYPE_RESOURCE,
            'description' => 'RAM',
            'amount' => $amount,
            'service_id' => $this->service->id,
            'service_resource_option_id' => $row->id,
            'period_start' => CarbonImmutable::parse('2026-10-01'),
            'period_end' => CarbonImmutable::parse('2026-10-31'),
        ]], currency: 'USD');

        app(PaymentRecorder::class)->record($invoice, $amount, 'banktransfer');

        return $invoice->refresh();
    }

    private function withModule(): void
    {
        $this->vps->update(['server_module' => 'panelfake', 'uses_machines' => true]);
        $this->service->unsetRelation('product');
    }
}
