<?php

namespace App\Billing;

use App\Contracts\LimitsPlanChanges;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Moves a service to a bigger or smaller product. The price is fair for the rest of the billing
 * period: the unused part of the old plan is set against the cost of the new plan for the same
 * days. An upgrade is invoiced and happens once paid; a downgrade happens now with the unused
 * amount added to the client's wallet, or on the next renewal date (Settings → General → Billing).
 */
class PlanChanges
{
    public function __construct(
        private InvoiceManager $invoices,
        private Wallet $wallet,
        private Provisioner $provisioner,
        private TemplateMailer $mailer,
    ) {}

    public function enabled(): bool
    {
        return (bool) setting('billing.plan_changes');
    }

    /**
     * Why the service cannot change plan right now, or null when it can. Only one change waits at a
     * time, plan or resources, and a plan never changes while the server still takes the last
     * resource change.
     */
    public function blockedReason(Service $service): ?string
    {
        return match (true) {
            $service->status !== ServiceStatus::Active => __('Only active services can change plan.'),
            ! $service->billing_cycle->isRecurring() || $service->next_due_date === null => __('This service does not renew, so its plan cannot change.'),
            $this->pendingFor($service) !== null => __('A plan change for this service is already waiting.'),
            $this->hasPendingResourceChange($service) => __('A resource change for this service is waiting.'),
            $this->hasUnpaidInvoice($service) => __('Pay the open invoice for this service first.'),
            $this->serverBusyWithResources($service) => __('We are still setting the last change on your server.'),
            default => null,
        };
    }

    /**
     * The products the service may move to, with a price in its currency and billing cycle. Clients
     * get the products chosen on the product that are in stock; staff may pick any product with the
     * same server module. A module that allows only some moves (LimitsPlanChanges) is asked for
     * each product. A product sold from the hardware inventory counts no stock here: the service
     * keeps its own machine. Clients do not get products that cannot take the service's size
     * extras (see ladderConflict).
     *
     * @return Collection<int, Product>
     */
    public function targets(Service $service, bool $staff = false): Collection
    {
        $service->loadMissing('product');
        $query = Product::query()->with('prices', 'group')->whereKeyNot($service->product_id)->orderBy('sort_order')->orderBy('name');

        if (! $staff) {
            $query->whereIn('id', array_map('intval', (array) ($service->product->upgrade_product_ids ?? [])));
        }

        $limits = $this->planLimits($service);
        $ladders = $staff ? collect() : $this->ladderRows($service);

        return $query->get()
            ->filter(fn (Product $product): bool => $product->server_module === $service->product->server_module
                && $product->priceFor($service->currency, $service->billing_cycle) !== null
                && ($staff || $product->uses_machines || $product->isInStock())
                && $this->moduleAllows($limits, $service, $product)
                && $this->ladderConflict($ladders, $product) === null)
            ->values();
    }

    /**
     * The fair price of moving to the product today, in minor units.
     *
     * @return array{old: int, new: int, days_left: int, period_days: int, credit: int, cost: int, difference: int}
     */
    public function quote(Service $service, Product $product, ?CarbonInterface $today = null): array
    {
        $period = Proration::period($service, $today);
        $end = $period['end'];
        $periodDays = $period['period_days'];
        $daysLeft = $period['days_left'];

        $old = (int) $service->recurring_amount;
        $new = (int) $product->priceFor($service->currency, $service->billing_cycle)?->price;
        $share = $this->paidShare($service);
        // Unused time is worth what the client really paid for it: nothing after a free coupon or trial,
        // and never more than was paid for these days, even when the plan changed without a payment.
        $credit = (int) round($old * $share * $daysLeft / $periodDays);
        $paidLeft = $this->paidForDaysLeft($service, $end, $daysLeft, $periodDays);

        if ($paidLeft !== null) {
            $credit = min($credit, $paidLeft);
        }

        // A bigger plan costs its full price for the days left. A smaller one gets the same discount as
        // the period, so moving down never costs money and never pays out more than was paid.
        $cost = (int) round($new * ($new >= $old ? 1 : $share) * $daysLeft / $periodDays);

        if ($new < $old) {
            $cost = min($cost, $credit);
        }

        return [
            'old' => $old,
            'new' => $new,
            'days_left' => $daysLeft,
            'period_days' => $periodDays,
            'credit' => $credit,
            'cost' => $cost,
            'difference' => $cost - $credit,
        ];
    }

    /**
     * The share of the plan's price the client paid for the current period, from 0 to 1: the latest
     * paid line for the service, less the coupon on the same invoice and less what credit notes gave
     * back. A free trial, a 100% coupon or a refunded period is 0. A service with no paid invoice here
     * (imported, or made by staff) keeps its full price.
     */
    private function paidShare(Service $service): float
    {
        $line = InvoiceItem::query()
            ->with('invoice.creditNotes')
            ->where('service_id', $service->id)
            ->where('type', InvoiceItem::TYPE_SERVICE)
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Refunded]))
            ->latest('id')
            ->first();

        if ($line === null) {
            return 1.0;
        }

        if ($line->amount <= 0) {
            return 0.0;
        }

        $share = ($line->amount - $this->discountFor($line)) / $line->amount;

        return max(0.0, min(1.0, $share * $this->keptShare($line->invoice)));
    }

    /**
     * What the client paid for the days left of the current period, in minor units: the period's
     * paid service line less its coupon, plus upgrades paid for these days, less credit notes. Null
     * when no paid service line covers the period (an imported service, or one made by staff).
     */
    private function paidForDaysLeft(Service $service, CarbonImmutable $end, int $daysLeft, int $periodDays): ?int
    {
        $lastDay = $end->subDay();
        $lines = InvoiceItem::query()
            ->with('invoice.creditNotes')
            ->where('service_id', $service->id)
            ->whereIn('type', [InvoiceItem::TYPE_SERVICE, InvoiceItem::TYPE_PLAN_CHANGE])
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->whereDate('period_start', '<=', $lastDay)
            ->whereDate('period_end', '>=', $lastDay)
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Refunded]))
            ->latest('id')
            ->get();

        $period = $lines->firstWhere('type', InvoiceItem::TYPE_SERVICE);

        if ($period === null) {
            return null;
        }

        $paid = max(0, $period->amount - $this->discountFor($period)) * $this->keptShare($period->invoice) * $daysLeft / $periodDays;

        foreach ($lines->where('type', InvoiceItem::TYPE_PLAN_CHANGE) as $line) {
            // An upgrade paid for the days from its start to the end of the period.
            $days = max(1, (int) round($line->period_start->diffInDays($line->period_end)) + 1);
            $paid += max(0, $line->amount) * $this->keptShare($line->invoice) * min($daysLeft, $days) / $days;
        }

        return (int) round($paid);
    }

    /**
     * The coupon taken off a service line on the same invoice, as a positive amount.
     */
    private function discountFor(InvoiceItem $line): int
    {
        return -(int) InvoiceItem::query()
            ->where('invoice_id', $line->invoice_id)
            ->where('service_id', $line->service_id)
            ->where('type', InvoiceItem::TYPE_DISCOUNT)
            ->sum('amount');
    }

    /**
     * The part of a paid invoice the client still pays for, from 0 to 1: less after a partial credit
     * note, and 0 once it was refunded.
     */
    private function keptShare(Invoice $invoice): float
    {
        return Proration::keptShare($invoice);
    }

    /**
     * How a change with this price difference happens. A downgrade waits for the renewal when its
     * money back cannot go into the client's wallet (wallet off, or in another currency).
     */
    public function modeFor(int $difference, ?Service $service = null): string
    {
        return match (true) {
            $difference > 0 => PlanChange::MODE_INVOICE,
            $difference < 0 && (setting('billing.downgrade') === 'renewal' || ($service !== null && ! $this->canCredit($service))) => PlanChange::MODE_RENEWAL,
            default => PlanChange::MODE_NOW,
        };
    }

    /**
     * Whether money back for the service can go into its client's wallet.
     */
    private function canCredit(Service $service): bool
    {
        return $this->wallet->enabled() && $service->client?->currency === $service->currency;
    }

    /**
     * Start moving the service to the product. Staff may change it now without charging anything.
     *
     * @throws RuntimeException When the service cannot move to that product.
     */
    public function start(Service $service, Product $product, ?Admin $admin = null, bool $charge = true): PlanChange
    {
        // Up to three tries, like a checkout: on MySQL and MariaDB the gaps between saved services
        // are locked too, so two sales can still lock each other out. The database then stops one of
        // them; it is rolled back in full and tried again. Only rows are saved in here: the log, the
        // invoice mail and a change that happens now run after it.
        $change = DB::transaction(function () use ($service, $product, $admin, $charge): PlanChange {
            // Locked before anything is read, in the order used everywhere (see lockPlans): both plans,
            // then the service, then the new plan's services as its stock is counted. Two clients
            // cannot both take the last one of a plan with a stock limit. Staff may.
            if ($admin === null) {
                $this->lockPlans($service->product_id, $product->id);
            }

            $locked = Service::query()->lockForUpdate()->findOrFail($service->id);
            $locked->setRelation('product', $service->product);
            // A product sold from the hardware inventory is not counted: the service keeps its machine.
            $soldOut = $admin === null && ! $product->uses_machines && $product->lockedStockLeft() === 0;

            $reason = $this->blockedReason($locked);

            if ($reason !== null) {
                throw new RuntimeException($reason);
            }

            $ladder = $this->ladderConflict($this->ladderRows($locked), $product);

            if ($ladder !== null) {
                throw new RuntimeException(__('Set :resource to 0 first.', ['resource' => $ladder]));
            }

            if (! $this->moduleAllows($this->planLimits($locked), $locked, $product)) {
                throw new RuntimeException(__('This plan needs a different server. Contact support.'));
            }

            if (! $this->targets($locked, $admin !== null)->contains('id', $product->id)) {
                throw new RuntimeException(__('This service cannot move to that plan.'));
            }

            if ($soldOut) {
                throw new RuntimeException(__('This plan is sold out.'));
            }

            $quote = $this->quote($locked, $product);
            $mode = $admin !== null && ! $charge ? PlanChange::MODE_NOW : $this->modeFor($quote['difference'], $locked);

            $change = PlanChange::create([
                'service_id' => $locked->id,
                'client_id' => $locked->client_id,
                'admin_id' => $admin?->id,
                'from_product_id' => $locked->product_id,
                'to_product_id' => $product->id,
                'billing_cycle' => $locked->billing_cycle,
                'currency' => $locked->currency,
                'old_amount' => $quote['old'],
                'new_amount' => $quote['new'],
                'difference' => $admin !== null && ! $charge ? 0 : $quote['difference'],
                'mode' => $mode,
                'status' => PlanChange::STATUS_PENDING,
                'apply_on' => $mode === PlanChange::MODE_RENEWAL ? $locked->next_due_date : null,
            ]);

            if ($mode === PlanChange::MODE_INVOICE) {
                $end = $locked->next_due_date->subDay();
                $invoice = $this->invoices->create($locked->client, [[
                    'type' => InvoiceItem::TYPE_PLAN_CHANGE,
                    'description' => $service->product->name.' → '.$product->name.($locked->domain ? ' - '.$locked->domain : '')
                        .' ('.today()->format('d M Y').' - '.$end->format('d M Y').')',
                    'amount' => $quote['difference'],
                    'service_id' => $locked->id,
                    'taxed' => $product->taxable,
                    // The days the price is for; paying it later than this period changes nothing.
                    'period_start' => CarbonImmutable::today(),
                    'period_end' => $end,
                ]], dueAt: today(), currency: $locked->currency);

                $change->update(['invoice_id' => $invoice->id]);
            }

            return $change;
        }, 3);

        Activity::log('service.plan_change', "Plan change for service #{$service->id}: {$service->product->name} → {$product->name} ({$change->mode})", $service, actor: $admin);

        match ($change->mode) {
            PlanChange::MODE_INVOICE => $this->sendInvoice($change),
            PlanChange::MODE_NOW => $this->apply($change),
            default => null,
        };

        return $change->refresh();
    }

    /**
     * Move the service to its new product, once. Returns false when the change was not waiting, or
     * when it can no longer happen; it is then stopped and a paid invoice goes back to the wallet.
     */
    public function apply(PlanChange $change): bool
    {
        $credit = 0;
        $notCredited = 0;
        $stopped = null;

        // Up to three tries: on MySQL and MariaDB moving the service can lock out a checkout of
        // another product and the other way round. The database then stops one of them; this
        // change is rolled back in full and tried again.
        $applied = DB::transaction(function () use ($change, &$credit, &$notCredited, &$stopped): bool {
            [$credit, $notCredited, $stopped] = [0, 0, null];
            $locked = PlanChange::query()->lockForUpdate()->find($change->id);

            if ($locked === null || ! $locked->isPending()) {
                return false;
            }

            // Locked before anything is read, in the order used everywhere (see lockPlans): the
            // client whose wallet gets the unused time, both plans, the service, then the new plan's
            // services as its stock is counted.
            if ($locked->mode === PlanChange::MODE_NOW && $locked->difference < 0) {
                Client::query()->whereKey($locked->client_id)->lockForUpdate()->first();
            }

            $product = $this->lockPlans($locked->from_product_id, $locked->to_product_id)->firstWhere('id', $locked->to_product_id);
            $service = Service::query()->lockForUpdate()->findOrFail($locked->service_id);
            // The last one of a plan in stock may have gone while the client's change waited. A change
            // for the renewal date, or one staff made, held its unit while it waited (see
            // Product::lockedStockLeft), so it is not counted again, which would count it against itself.
            // A product sold from the hardware inventory is not counted: the service keeps its machine.
            $countsStock = $product !== null && $locked->admin_id === null && $locked->mode !== PlanChange::MODE_RENEWAL && ! $product->uses_machines;
            $stopped = $this->stopReason($locked, $service, $product, $countsStock ? $product->lockedStockLeft() : null);

            if ($stopped !== null) {
                $locked->update(['status' => PlanChange::STATUS_CANCELLED]);

                return false;
            }

            $service->update(['product_id' => $locked->to_product_id, 'recurring_amount' => $locked->new_amount]);
            // The extras stay, with their steps and price; they now belong to the new plan's options.
            $service->relinkResources($product);
            $locked->update(['status' => PlanChange::STATUS_APPLIED, 'applied_at' => now()]);

            $service->loadMissing('client');

            if ($locked->mode === PlanChange::MODE_NOW && $locked->difference < 0) {
                if ($this->wallet->enabled() && $service->client->currency === $locked->currency) {
                    $credit = -$locked->difference;
                    $this->wallet->change($service->client, $credit, __('Unused time of your old plan for :service', ['service' => $service->domain ?: '#'.$service->id]));
                } else {
                    $notCredited = -$locked->difference;
                }
            }

            return true;
        }, 3);

        if ($stopped !== null) {
            $this->stopped($change->refresh(), $stopped);

            return false;
        }

        if (! $applied) {
            return false;
        }

        $change->refresh()->load('service.client', 'service.product', 'fromProduct', 'toProduct');
        $service = $change->service;

        if ($notCredited > 0) {
            Activity::log('service.plan_change_credit', 'The '.money($notCredited, $change->currency)." for the unused time of the old plan of service #{$service->id} could not go into the client's wallet (wallet off or another currency). Settle it by hand.", $service);
        }

        // A service on a machine from the hardware inventory has no server record, but its module
        // still moves it to the new plan.
        if ($service->product->server_module !== null && ($service->server_id !== null || $service->product->uses_machines)) {
            // A failed server update is logged for staff; the new plan and price stand.
            $this->provisioner->changePackage($service);
        }

        Activity::log('service.plan_changed', "Service #{$service->id} moved from {$change->fromProduct?->name} to {$change->toProduct?->name}", $service);

        $this->mailer->send('service.plan_changed', $service->client, TemplateMailer::serviceContext($service) + [
            'plan' => [
                'old' => $change->fromProduct?->name,
                'new' => $change->toProduct?->name,
                'amount' => money($change->new_amount, $change->currency),
                'cycle' => Locales::in(Locales::forClient($service->client), fn (): string => $change->billing_cycle->label()),
                'note' => $credit > 0 ? Locales::in(Locales::forClient($service->client), fn (): string => __('We added :amount to your wallet for the unused time of your old plan.', ['amount' => money($credit, $change->currency)])) : '',
            ],
        ]);

        return true;
    }

    /**
     * Lock the rows of the plans a service moves between, in id order. Checkouts, plan changes and
     * resource changes lock rows in one order, so two of them never wait for each other in a circle:
     * a plan change or resource change, then a client, then a coupon, then products in id order,
     * then machines, then resource options, then services, then the services' resource rows (see
     * Product::lockedStockLeft). Two changes in opposite directions (Small to Large and Large to
     * Small) so queue on the first plan, instead of each holding its own service while it counts
     * the other's plan.
     *
     * @return Collection<int, Product>
     */
    private function lockPlans(int ...$ids): Collection
    {
        return Product::query()->whereKey(array_values(array_unique($ids)))->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Why a waiting change can no longer happen, in words for the client, or null when it can.
     *
     * @param  Product|null  $product  The new plan, locked; null when it was deleted.
     * @param  int|null  $stockLeft  What is left of it, counted under its lock; null when not counted.
     */
    private function stopReason(PlanChange $change, Service $service, ?Product $product, ?int $stockLeft): ?string
    {
        if ($product === null) {
            return __('The new plan is no longer sold.');
        }

        if ($change->mode === PlanChange::MODE_INVOICE && $this->periodPassed($change, $service)) {
            return __('The service renewed before the plan change was paid.');
        }

        if ($stockLeft === 0) {
            return __('The new plan is sold out.');
        }

        $ladder = $this->ladderConflict($this->ladderRows($service), $product);

        if ($ladder !== null) {
            return __('Set :resource to 0 first.', ['resource' => $ladder]);
        }

        if (! $this->moduleAllows($this->planLimits($service), $service, $product)) {
            return __('This plan needs a different server. Contact support.');
        }

        return null;
    }

    /**
     * The service's module when it allows only some plan changes, or null when it allows any. Read
     * from the service's current product.
     */
    private function planLimits(Service $service): ?LimitsPlanChanges
    {
        $slug = $service->loadMissing('product')->product?->server_module;

        if (blank($slug)) {
            return null;
        }

        try {
            $module = app(ExtensionManager::class)->serverModule($slug);
        } catch (Throwable) {
            // A module that is not installed cannot apply a change either; that is logged when it runs.
            return null;
        }

        return $module instanceof LimitsPlanChanges ? $module : null;
    }

    /**
     * Whether the module lets the service move to the product. A module that fails to answer
     * refuses, so nobody pays for a change that cannot be made.
     */
    private function moduleAllows(?LimitsPlanChanges $limits, Service $service, Product $product): bool
    {
        if ($limits === null) {
            return true;
        }

        try {
            return $limits->allowsPlanChange($service, $product);
        } catch (Throwable $exception) {
            // Only the kind of error: a raw message may hold a server address with a key.
            report(new RuntimeException("Plan change check for service #{$service->id}: ".class_basename($exception)));

            return false;
        }
    }

    /**
     * The service's active extras whose steps are sizes (a ladder, such as a bigger server type),
     * with the ladder of each.
     *
     * @return Collection<int, array{kind: string, label: string, ladder: list<string>}>
     */
    private function ladderRows(Service $service): Collection
    {
        return ServiceResourceOption::query()
            ->with('option')
            ->where('service_id', $service->id)
            ->where('status', ServiceResourceOption::STATUS_ACTIVE)
            ->where('steps', '>', 0)
            ->orderBy('id')
            ->get()
            ->filter(fn (ServiceResourceOption $row): bool => is_array($row->option?->ladder))
            ->map(fn (ServiceResourceOption $row): array => [
                'kind' => $row->kind,
                'label' => $row->option->displayLabel(),
                'ladder' => array_values($row->option->ladder),
            ])
            ->values();
    }

    /**
     * The name of the first size extra the product cannot take, or null when it takes them all. A
     * step of a ladder is a size of that product only (step 2 of one plan is another server type
     * than step 2 of the next), so the service may move only to a product with an active option of
     * the same kind and the very same ladder. Otherwise the extra must go to 0 first.
     *
     * @param  Collection<int, array{kind: string, label: string, ladder: list<string>}>  $rows
     */
    private function ladderConflict(Collection $rows, Product $product): ?string
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $options = ResourceOption::query()->where('product_id', $product->id)->where('is_active', true)->get(['id', 'kind', 'ladder']);

        foreach ($rows as $row) {
            $fits = $options->contains(fn (ResourceOption $option): bool => $option->kind === $row['kind']
                && is_array($option->ladder)
                && array_values($option->ladder) === $row['ladder']);

            if (! $fits) {
                return $row['label'];
            }
        }

        return null;
    }

    /**
     * Whether a resource change for the service waits for its invoice or its renewal date.
     */
    private function hasPendingResourceChange(Service $service): bool
    {
        return ResourceChange::query()->where('service_id', $service->id)->where('status', ResourceChange::STATUS_PENDING)->exists();
    }

    /**
     * Whether the server is still taking the service's last resource change: waiting for its job,
     * being set, or being checked.
     */
    private function serverBusyWithResources(Service $service): bool
    {
        return ResourceChange::query()->where('service_id', $service->id)->latest('id')->first()?->serverBusy() === true;
    }

    /**
     * Whether the period an upgrade was priced for has passed: the service renewed since, or its
     * next period is already invoiced at the old price, paid or not.
     */
    private function periodPassed(PlanChange $change, Service $service): bool
    {
        if ($change->invoice_id === null) {
            return false;
        }

        // The upgrade's line runs to the day before the next due date it was priced for.
        $dueDate = InvoiceItem::query()
            ->where('invoice_id', $change->invoice_id)
            ->where('type', InvoiceItem::TYPE_PLAN_CHANGE)
            ->first()?->period_end?->addDay();

        if ($dueDate !== null && $service->next_due_date?->isSameDay($dueDate) !== true) {
            return true;
        }

        return InvoiceItem::query()
            ->where('service_id', $service->id)
            ->where('type', InvoiceItem::TYPE_SERVICE)
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', InvoiceStatus::Cancelled))
            ->when(
                $dueDate !== null,
                fn ($query) => $query->whereDate('period_start', '>=', $dueDate),
                // Older upgrades do not keep their period: any renewal invoiced after the upgrade.
                fn ($query) => $query->where('invoice_id', '>', $change->invoice_id),
            )
            ->exists();
    }

    /**
     * Log a change that stopped, and give back the invoice paid for it: a credit note puts the money
     * in the client's wallet, or staff are asked to settle it when it cannot go there.
     */
    private function stopped(PlanChange $change, string $reason): void
    {
        $change->loadMissing('service', 'invoice.client');
        Activity::log('service.plan_change_stopped', "Plan change for service #{$change->service_id} stopped: {$reason}", $change->service);

        $invoice = $change->invoice;

        if ($invoice === null || $invoice->status !== InvoiceStatus::Paid || $invoice->creditableAmount() <= 0) {
            return;
        }

        $credited = $this->wallet->enabled() && $invoice->currency === $invoice->client->currency
            && rescue(fn (): bool => app(CreditNotes::class)->issue($invoice, $invoice->creditableAmount(), CreditNote::METHOD_WALLET, $reason) instanceof CreditNote, false);

        if (! $credited) {
            Activity::log('service.plan_change_refund', "Invoice {$invoice->displayNumber()} paid for a plan change that stopped. Give the money back to the client by hand.", $invoice);
        }
    }

    /**
     * Before the service's next period is billed. An upgrade still waiting for payment was priced for
     * the period that ends now, so it stops: its invoice is cancelled, which puts what was paid on it
     * back into the wallet. One whose invoice was just paid is applied first. One with a card or
     * gateway payment still going through stays open; if that payment lands after the renewal was
     * billed, the change stops then and the money goes back to the wallet. A part payment that cannot
     * go back (the wallet is in another currency with no exchange rate) stays on the cancelled
     * invoice, and staff are asked once to give it back by hand.
     * Returns true when the service moved to its new plan, so the renewal bills the new plan.
     */
    public function settleBeforeRenewal(Service $service): bool
    {
        $change = PlanChange::query()
            ->where('service_id', $service->id)
            ->where('status', PlanChange::STATUS_PENDING)
            ->where('mode', PlanChange::MODE_INVOICE)
            ->latest('id')
            ->first();

        if ($change === null) {
            return false;
        }

        $returned = 0;
        $kept = 0;

        // The change and then its invoice are locked (the invoice lock a payment takes), so a payment
        // arriving now either lands first and applies the change, or finds the invoice cancelled.
        $outcome = DB::transaction(function () use ($change, &$returned, &$kept): string {
            $locked = PlanChange::query()->lockForUpdate()->find($change->id);

            if ($locked === null || ! $locked->isPending()) {
                return $locked?->status === PlanChange::STATUS_APPLIED ? 'applied' : 'gone';
            }

            $invoice = $locked->invoice_id === null ? null : Invoice::query()->lockForUpdate()->find($locked->invoice_id);

            if ($invoice?->status === InvoiceStatus::Paid) {
                return 'paid';
            }

            if ($invoice?->status === InvoiceStatus::Unpaid && PaymentUnderway::on($invoice)) {
                return 'waiting';
            }

            if ($invoice !== null) {
                $paid = $invoice->status === InvoiceStatus::Unpaid ? $invoice->amount_paid : 0;

                try {
                    // The one place that gives the money back: what was paid goes into the wallet with
                    // a refund line for each payment. Cancelling the invoice cancels the change with it.
                    $this->invoices->cancel($invoice);
                    $returned = $paid;
                } catch (NoExchangeRate) {
                    // The wallet is in another currency with no exchange rate. Only that cancel was
                    // rolled back (it runs in its own savepoint). The invoice still closes, so it never
                    // holds the service, gets charged or chased; what was paid stays on it for staff.
                    $this->invoices->cancelKeepingPayments($invoice);
                    $kept = $invoice->amount_paid;
                }
            }

            $locked->update(['status' => PlanChange::STATUS_CANCELLED]);

            return 'stopped';
        });

        return match ($outcome) {
            'paid' => $this->apply($change),
            // A payment got there first and moved the service already.
            'applied' => true,
            'stopped' => $this->expired($change, $service, $returned, $kept),
            default => false,
        };
    }

    /**
     * Stop the upgrades whose period has passed but which are still waiting, because a payment was
     * going through the night their renewal was billed. The nightly run calls this, so a stale
     * upgrade invoice does not suspend the service. One that fails, or still waits, is skipped and
     * never stops the others.
     */
    public function settlePassed(): void
    {
        $changes = PlanChange::query()
            ->with('service')
            ->where('status', PlanChange::STATUS_PENDING)
            ->where('mode', PlanChange::MODE_INVOICE)
            ->whereNotNull('invoice_id')
            ->orderBy('id')
            ->get();

        foreach ($changes as $change) {
            if ($change->service !== null && $this->periodPassed($change, $change->service)) {
                rescue(fn (): bool => $this->settleBeforeRenewal($change->service), false);
            }
        }
    }

    /**
     * Log an upgrade that stopped because it was not paid before the renewal. A part payment that
     * could not go back into the wallet is left to staff, with one log line that says what to do.
     */
    private function expired(PlanChange $change, Service $service, int $returned, int $kept): bool
    {
        $invoice = $change->invoice()->with('client')->first();
        $number = $invoice?->displayNumber() ?? '';

        Activity::log('service.plan_change_expired', "Plan change for service #{$service->id} stopped: its invoice {$number} was not paid before the renewal"
            .($returned > 0 ? '; '.money($returned, $change->currency).' paid on it went back to the wallet' : ''), $service);

        if ($kept > 0 && $invoice !== null) {
            Activity::log('service.plan_change_refund', 'Give '.money($kept, $invoice->currency)." back to the client by hand. It was paid on invoice {$number} for a plan change that stopped,"
                ." and the client's wallet is in {$invoice->client->currency} with no exchange rate from {$invoice->currency}.", $invoice);
        }

        return false;
    }

    /**
     * Apply the changes waiting for this invoice, now that it is paid. One that fails is reported
     * and does not stop the rest of the payment.
     */
    public function applyForInvoice(Invoice $invoice): void
    {
        PlanChange::query()->where('invoice_id', $invoice->id)->where('status', PlanChange::STATUS_PENDING)->get()
            ->each(fn (PlanChange $change): bool => (bool) rescue(fn (): bool => $this->apply($change), false));
    }

    /**
     * The invoice for these changes was cancelled, so the changes are too.
     */
    public function cancelForInvoice(Invoice $invoice): void
    {
        PlanChange::query()->where('invoice_id', $invoice->id)->where('status', PlanChange::STATUS_PENDING)
            ->update(['status' => PlanChange::STATUS_CANCELLED]);
    }

    /**
     * Stop a waiting change. A change for the next renewal can only stop before that renewal is
     * invoiced; an unpaid upgrade invoice is cancelled with it.
     *
     * @throws RuntimeException When it can no longer stop.
     */
    public function cancel(PlanChange $change): void
    {
        if (! $change->isPending()) {
            return;
        }

        if ($change->mode === PlanChange::MODE_RENEWAL && $this->renewalInvoiced($change)) {
            throw new RuntimeException(__('The renewal with the new plan is already invoiced, so this change can no longer stop.'));
        }

        // The invoice goes first and is read again under a lock: money paid on it goes back to the
        // wallet. When that cannot happen, or a payment settled it meanwhile, the change stays.
        if ($change->invoice !== null && $this->invoices->cancel($change->invoice)->status === InvoiceStatus::Paid) {
            throw new RuntimeException(__('The invoice for this change is already paid, so the change can no longer stop.'));
        }

        // Only a change that is still waiting stops; one applied in the meantime stays applied.
        PlanChange::query()->whereKey($change->id)->where('status', PlanChange::STATUS_PENDING)->update(['status' => PlanChange::STATUS_CANCELLED]);
        $change->refresh();

        Activity::log('service.plan_change_cancelled', "Plan change for service #{$change->service_id} stopped", $change->service);
    }

    /**
     * Apply the downgrades planned for renewal dates up to today. Returns how many were applied. One
     * that fails is reported and skipped, so it never stops the nightly run.
     */
    public function applyScheduled(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::instance($today ?? today());

        return PlanChange::query()
            ->where('status', PlanChange::STATUS_PENDING)
            ->where('mode', PlanChange::MODE_RENEWAL)
            ->whereDate('apply_on', '<=', $today)
            ->get()
            ->filter(fn (PlanChange $change): bool => (bool) rescue(fn (): bool => $this->apply($change), false))
            ->count();
    }

    public function pendingFor(Service $service): ?PlanChange
    {
        return PlanChange::query()->with('toProduct', 'invoice')->where('service_id', $service->id)->where('status', PlanChange::STATUS_PENDING)->latest('id')->first();
    }

    /**
     * The downgrade planned for the period that starts on this date, so the renewal bills the new plan.
     */
    public function scheduledFor(Service $service, CarbonInterface $periodStart): ?PlanChange
    {
        return PlanChange::query()->with('toProduct')
            ->where('service_id', $service->id)
            ->where('status', PlanChange::STATUS_PENDING)
            ->where('mode', PlanChange::MODE_RENEWAL)
            ->whereDate('apply_on', $periodStart)
            ->first();
    }

    private function hasUnpaidInvoice(Service $service): bool
    {
        return Invoice::query()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Draft])
            ->whereHas('items', fn ($query) => $query->where('service_id', $service->id))
            ->exists();
    }

    private function renewalInvoiced(PlanChange $change): bool
    {
        return InvoiceItem::query()
            ->where('billing_key', RenewalGenerator::billingKey('service', $change->service_id, $change->apply_on))
            ->exists();
    }

    private function sendInvoice(PlanChange $change): void
    {
        $invoice = $change->invoice()->with('client')->first();

        if ($invoice === null) {
            return;
        }

        $this->mailer->send('invoice.created', $invoice->client, TemplateMailer::invoiceContext($invoice));
        // Wallet money pays it straight away when the client has some; paying applies the change.
        $this->wallet->applyAutomatically($invoice);
    }
}
