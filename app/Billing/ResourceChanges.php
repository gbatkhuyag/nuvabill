<?php

namespace App\Billing;

use App\Contracts\HasResourceOptions;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Extensions\Servers\Resources\ResourceLimit;
use App\Jobs\ApplyServiceResources;
use App\Jobs\CheckServiceResources;
use App\Mail\MarkdownValue;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResourceChange;
use App\Models\ResourceChangeItem;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Changes of a service's extras (more RAM, IP addresses and so on) in the middle of a billing
 * period, the same way PlanChanges moves a plan: a fair price for the days left, an invoice for an
 * upgrade that is applied once paid, removals that follow the downgrade setting (wallet credit now,
 * or on the renewal date), and only one waiting change per service, plan or extras.
 *
 * Billing and the server are kept apart. Once the billing side is applied the service's rows say
 * what the client pays for, and a queued job (ApplyServiceResources) sets the server to the plan
 * plus those extras through Provisioner. It tries 3 times, re-checks slow modules
 * (CheckServiceResources), and asks staff when it cannot finish; the nightly run sends lost jobs
 * again.
 *
 * Lock order (see Product::lockedStockLeft): the change, then the client, then resource options in
 * id order, then the service, then the service's resource rows. No module or server is asked
 * while these locks are held.
 */
final class ResourceChanges
{
    /**
     * Tries of the server job before staff are asked.
     */
    public const MAX_TRIES = 3;

    /**
     * Seconds before the second and the third try.
     *
     * @var list<int>
     */
    public const RETRY_DELAYS = [60, 300];

    /**
     * Seconds to wait when other work holds the service, and how often, before the nightly run
     * takes over.
     */
    public const BUSY_DELAY = 120;

    public const BUSY_TRIES = 15;

    /**
     * Seconds before each check of a module that is still applying the change (4 checks).
     *
     * @var list<int>
     */
    public const CHECK_DELAYS = [120, 300, 600, 600];

    /**
     * Minutes after which the nightly run sends a server job again: one that stopped while setting
     * the server, and one that waited or checked too long.
     */
    public const LOST_APPLYING_MINUTES = 20;

    public const LOST_WAITING_MINUTES = 60;

    /**
     * Hours the card on the client's service page says the new extras are ready.
     */
    private const DONE_NOTICE_HOURS = 24;

    /**
     * Kinds whose removal takes addresses off the server, so clients confirm it first.
     */
    private const ADDRESS_KINDS = ['ipv4', 'ipv6'];

    public function __construct(
        private InvoiceManager $invoices,
        private Wallet $wallet,
        private Provisioner $provisioner,
        private TemplateMailer $mailer,
        private ResourceOptions $options,
        private PlanChanges $planChanges,
    ) {}

    /**
     * Whether clients may change their extras themselves (Settings → General → Billing). Staff
     * tools work either way.
     */
    public function enabled(): bool
    {
        return (bool) setting('billing.resource_changes');
    }

    /**
     * Why the service's extras cannot change right now, or null when they can. Only one change
     * waits at a time, plan or extras. Staff may start a change while the server still takes the
     * last one; clients wait for it.
     */
    public function blockedReason(Service $service, bool $staff = false): ?string
    {
        return match (true) {
            $service->status !== ServiceStatus::Active => __('Only active services can change resources.'),
            ! $service->billing_cycle->isRecurring() || $service->next_due_date === null => __('This service does not renew, so its resources cannot change.'),
            $this->pendingFor($service) !== null => __('A resource change for this service is already waiting.'),
            $this->planChanges->pendingFor($service) !== null => __('A plan change for this service is already waiting.'),
            $this->hasUnpaidInvoice($service) => __('Pay the open invoice for this service first.'),
            ! $staff && $this->serverBusy($service) => __('We are still setting the last change on your server.'),
            default => null,
        };
    }

    /**
     * The fair price of the chosen steps for the days left of the current period, in minor units
     * of the service's currency. Kinds left out stay as they are.
     *
     * Added steps cost today's price of the option for the days left, plus its setup fee per step
     * (never given back). Removed steps take off the average price paid per step, and give back
     * the days left of it, never more than was paid for them (see creditCap()). Staff may also set
     * a new renewal price per kind ($amounts); the cost and credit still come from the steps.
     *
     * @param  array<mixed>  $steps  kind => wanted steps
     * @param  array<string, int>  $amounts  Staff only: kind => new renewal price of the kind, in minor units.
     * @param  array<string, ResourceLimit>|null  $limits  The module's live limits, already read (see ResourceOptions::forService()); null reads them.
     * @return array{items: list<array{kind: string, label: string, option_id: int|null, row_id: int|null, unit: string, step_size: int, from: int, to: int, old_amount: int, new_amount: int, cost: int, credit: int, setup_fee: int, restarts: bool, ladder: list<string>|null, toggle: bool, included: int|null}>, old: int, new: int, cost: int, setup: int, credit: int, difference: int, mode: string, apply_on: CarbonImmutable|null, start: CarbonImmutable, end: CarbonImmutable, days_left: int, period_days: int, restarts: bool, removes_addresses: bool}
     *
     * @throws ValidationException With messages under "steps.{kind}", or "steps".
     */
    public function quote(Service $service, array $steps, bool $staff = false, ?CarbonInterface $today = null, array $amounts = [], ?array $limits = null): array
    {
        $service->loadMissing(['product', 'client', 'resources.option']);
        $period = Proration::period($service, $today);
        $sliders = collect($this->options->forService($service, $staff, $limits))->keyBy('kind');
        $options = $service->product->resourceOptions->keyBy('kind');
        $rows = $service->resources->keyBy('kind');
        $cycle = $service->billing_cycle->value;
        $wanted = $this->wanted($steps, $sliders);
        $items = [];

        foreach ($wanted as $kind => $to) {
            $slider = $sliders->get($kind);
            $option = $options->get($kind);
            $row = $rows->get($kind);
            $active = $row !== null && $row->isActive();
            $from = (int) $slider['current'];
            $old = $active ? (int) $row->recurring_amount : 0;
            $override = $staff && array_key_exists($kind, $amounts) ? max(0, (int) $amounts[$kind]) : null;

            if ($to === $from && ($override === null || $override === $old)) {
                continue;
            }

            // A row kept from another plan may count its steps in another size than this plan's
            // option. It can only go to 0, or keep its steps with a new price.
            $stepSize = (int) $slider['step'];

            if ($active && $row->step_size !== $stepSize) {
                if ($to !== 0 && $to !== $from) {
                    throw ValidationException::withMessages(["steps.{$kind}" => __(':resource cannot change here. Contact us to change it.', ['resource' => $slider['label']])]);
                }

                $stepSize = $row->step_size;
            }

            $cost = 0;
            $credit = 0;
            $setup = 0;
            $new = $old;

            if ($to > $from) {
                $added = $to - $from;
                $price = $slider['prices'][$cycle] ?? null;
                $recurring = $added * (int) ($price['step'] ?? 0);
                $cost = Proration::forDays($recurring, $period['days_left'], $period['period_days']);
                $setup = $added * (int) ($price['setup'] ?? 0);
                $new = $old + $recurring;
            } elseif ($to < $from) {
                $removed = $from - $to;
                $recurring = (int) round($old * $removed / $from);
                $new = $old - $recurring;
                $credit = Proration::forDays($recurring, $period['days_left'], $period['period_days']);
                $cap = $row !== null ? $this->creditCap($row, $removed, $from, $period) : null;

                if ($cap !== null) {
                    $credit = min($credit, $cap);
                }
            }

            if ($override !== null) {
                $new = $override;
            }

            $items[] = [
                'kind' => (string) $kind,
                'label' => (string) $slider['label'],
                'option_id' => $option?->id ?? $row?->resource_option_id,
                'row_id' => $row?->id,
                'unit' => (string) $slider['unit'],
                'step_size' => $stepSize,
                'from' => $from,
                'to' => $to,
                'old_amount' => $old,
                'new_amount' => $to > 0 ? $new : 0,
                'cost' => $cost,
                'credit' => $credit,
                'setup_fee' => $setup,
                'restarts' => (bool) $slider['restarts'],
                'ladder' => $slider['ladder'],
                'toggle' => (bool) $slider['toggle'],
                'included' => $slider['included'] === null ? null : (int) $slider['included'],
            ];
        }

        $old = (int) $service->resources->filter(fn (ServiceResourceOption $row): bool => $row->isActive())->sum('recurring_amount');
        $new = $old + array_sum(array_column($items, 'new_amount')) - array_sum(array_column($items, 'old_amount'));
        $cost = array_sum(array_column($items, 'cost'));
        $setup = array_sum(array_column($items, 'setup_fee'));
        $credit = array_sum(array_column($items, 'credit'));
        $difference = $cost + $setup - $credit;
        $mode = $this->planChanges->modeFor($difference, $service);

        return [
            'items' => $items,
            'old' => $old,
            'new' => $new,
            'cost' => $cost,
            'setup' => $setup,
            'credit' => $credit,
            'difference' => $difference,
            'mode' => $mode,
            'apply_on' => $mode === ResourceChange::MODE_RENEWAL ? $service->next_due_date : null,
            'start' => $period['start'],
            'end' => $period['end'],
            'days_left' => $period['days_left'],
            'period_days' => $period['period_days'],
            'restarts' => collect($items)->contains(fn (array $item): bool => $item['restarts'] && $item['to'] !== $item['from']),
            'removes_addresses' => collect($items)->contains(fn (array $item): bool => in_array($item['kind'], self::ADDRESS_KINDS, true) && $item['to'] < $item['from']),
        ];
    }

    /**
     * Start changing the service's extras. A client's change, or a staff change that is charged,
     * costs the difference: an invoice that applies the change once paid, a change now (with the
     * unused days of removed extras added to the wallet), or a change on the next renewal date.
     * Staff may also change the extras now without charging or crediting anything ($charge
     * false), and change only the billing without the server ($setsServer false).
     *
     * @param  array<mixed>  $steps  kind => wanted steps
     * @param  array<string, int>  $amounts  Staff only: kind => new renewal price, in minor units.
     * @param  int|null  $expected  The difference the client saw; another one refuses the change.
     *
     * @throws RuntimeException When the extras cannot change now (ResourceSoldOut when stock ran out).
     * @throws ValidationException When the steps are not allowed, nothing changes, or the price changed.
     */
    public function start(Service $service, array $steps, ?Admin $admin = null, bool $charge = true, array $amounts = [], bool $setsServer = true, ?int $expected = null): ResourceChange
    {
        $staff = $admin !== null;
        $free = $staff && ! $charge;
        // The options that may need a stock count, read before any lock and locked first, in id
        // order. The steps are read again under the locks.
        $counted = $staff ? [] : $this->stockOptionsFor($service, $steps);
        $reason = $this->blockedReason($service, $staff);

        // Refused before the server is asked; checked again under the locks.
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        // The module's live limits may ask the server (up to its timeout), so they are read before
        // any lock: nothing inside the transaction below reaches a module.
        $limits = $this->options->liveLimits($service);

        // Up to three tries, like a checkout: on MySQL and MariaDB two sales can still lock each
        // other out. Only rows are saved in here: the log, the invoice email and a change that
        // happens now run after it.
        $change = DB::transaction(function () use ($service, $steps, $admin, $staff, $free, $amounts, $setsServer, $expected, $counted, $limits): ResourceChange {
            if ($counted !== []) {
                ResourceOption::query()->whereKey($counted)->orderBy('id')->lockForUpdate()->get(['id']);
            }

            $locked = Service::query()->lockForUpdate()->findOrFail($service->id);
            $locked->load(['product', 'client', 'resources.option']);
            $reason = $this->blockedReason($locked, $staff);

            if ($reason !== null) {
                throw new RuntimeException($reason);
            }

            $quote = $this->quote($locked, $steps, $staff, amounts: $staff ? $amounts : [], limits: $limits);

            if ($quote['items'] === []) {
                throw ValidationException::withMessages(['steps' => __('Choose at least one change.')]);
            }

            $mode = $free ? ResourceChange::MODE_NOW : $quote['mode'];
            $difference = $free ? 0 : $quote['difference'];

            if ($expected !== null && $expected !== $difference) {
                throw ValidationException::withMessages(['expected' => __('Prices changed. Check the new price and try again.')]);
            }

            // A client's change that happens now or on the renewal date takes its steps now; one
            // that waits for its invoice counts them again when it is paid.
            if (! $staff && $mode !== ResourceChange::MODE_INVOICE) {
                $this->checkStock($quote['items']);
            }

            $change = ResourceChange::create([
                'service_id' => $locked->id,
                'client_id' => $locked->client_id,
                'admin_id' => $admin?->id,
                'billing_cycle' => $locked->billing_cycle,
                'currency' => $locked->currency,
                'old_amount' => $quote['old'],
                'new_amount' => $quote['new'],
                'difference' => $difference,
                'mode' => $mode,
                'status' => ResourceChange::STATUS_PENDING,
                'apply_on' => $mode === ResourceChange::MODE_RENEWAL ? $locked->next_due_date : null,
                'sets_server' => $setsServer,
                'server_state' => ResourceChange::SERVER_NONE,
            ]);

            foreach ($quote['items'] as $item) {
                $change->items()->create([
                    'resource_option_id' => $item['option_id'],
                    'kind' => $item['kind'],
                    'unit' => $item['unit'],
                    'step_size' => $item['step_size'],
                    'from_steps' => $item['from'],
                    'to_steps' => $item['to'],
                    'old_amount' => $item['old_amount'],
                    'new_amount' => $item['new_amount'],
                    'cost' => $free ? 0 : $item['cost'],
                    'credit' => $free ? 0 : $item['credit'],
                    'setup_fee' => $free ? 0 : $item['setup_fee'],
                ]);
            }

            if ($mode === ResourceChange::MODE_INVOICE) {
                $invoice = $this->invoices->create($locked->client, $this->invoiceLines($locked, $change), dueAt: today(), currency: $locked->currency);
                $change->update(['invoice_id' => $invoice->id]);
            } elseif ($mode === ResourceChange::MODE_RENEWAL) {
                // The renewal bills the kinds this change adds: their lines point at these rows.
                $this->addEmptyRows($locked, $change);
            }

            return $change;
        }, 3);

        $change->load('items.option');
        $what = $this->summary($change);

        if ($staff && (! $charge || ! $setsServer)) {
            Activity::log('service.resources_edited', "Staff changed the extras of service #{$service->id}: {$what} (".($charge ? $change->mode : 'without charge').($setsServer ? '' : ', billing only').')', $service, actor: $admin);
        } else {
            Activity::log('service.resource_change', "Resource change for service #{$service->id}: {$what} ({$change->mode})", $service, actor: $admin);
        }

        match ($change->mode) {
            ResourceChange::MODE_INVOICE => $this->sendInvoice($change),
            ResourceChange::MODE_NOW => $this->apply($change),
            default => null,
        };

        return $change->refresh();
    }

    /**
     * Apply the billing side of a waiting change, once: the service's rows get their new steps and
     * renewal prices, a credit goes into the wallet, and the server job is queued (or staff are
     * asked to set it by hand). Returns false when the change was not waiting, or can no longer
     * happen; it is then stopped and a paid invoice goes back to the wallet.
     */
    public function apply(ResourceChange $change): bool
    {
        $credit = 0;
        $notCredited = 0;
        $stopped = null;

        $applied = DB::transaction(function () use ($change, &$credit, &$notCredited, &$stopped): bool {
            [$credit, $notCredited, $stopped] = [0, 0, null];
            $locked = ResourceChange::query()->with('items')->lockForUpdate()->find($change->id);

            if ($locked === null || ! $locked->isPending()) {
                return false;
            }

            $refund = $locked->mode === ResourceChange::MODE_NOW && $locked->difference < 0;

            if ($refund) {
                Client::query()->whereKey($locked->client_id)->lockForUpdate()->first();
            }

            // A client's change that did not hold its steps while it waited (see
            // ResourceOption::lockedStockLeft) counts them now, under the options' locks.
            $countsStock = $locked->admin_id === null && $locked->mode !== ResourceChange::MODE_RENEWAL;
            $growing = $countsStock ? $this->growingOptionIds($locked->items) : [];
            $options = $growing === [] ? collect() : ResourceOption::query()->whereKey($growing)->whereNotNull('stock')->orderBy('id')->lockForUpdate()->get();
            $service = Service::query()->with('product')->lockForUpdate()->find($locked->service_id);
            $stopped = $this->stopReason($locked, $service, $options);

            if ($stopped !== null) {
                $locked->update(['status' => ResourceChange::STATUS_CANCELLED]);

                return false;
            }

            $rows = ServiceResourceOption::query()->where('service_id', $service->id)->orderBy('id')->lockForUpdate()->get()->keyBy('kind');
            $recurring = $service->billing_cycle->isRecurring();

            foreach ($locked->items as $item) {
                $row = $rows->get($item->kind) ?? new ServiceResourceOption(['service_id' => $service->id, 'kind' => $item->kind]);
                $row->fill([
                    'resource_option_id' => $item->resource_option_id ?? $row->resource_option_id,
                    'unit' => $item->unit,
                    'step_size' => $item->step_size,
                    'steps' => $item->to_steps,
                    'recurring_amount' => $recurring && $item->to_steps > 0 ? $item->new_amount : 0,
                    'status' => $item->to_steps > 0 ? ServiceResourceOption::STATUS_ACTIVE : ServiceResourceOption::STATUS_CANCELLED,
                ])->save();
            }

            $locked->update([
                'status' => ResourceChange::STATUS_APPLIED,
                'applied_at' => now(),
                'server_state' => $this->serverStateFor($locked, $service),
                'server_attempts' => 0,
                'server_checks' => 0,
                'server_message' => null,
            ]);

            if ($refund) {
                $service->loadMissing('client');

                if ($this->wallet->enabled() && $service->client->currency === $locked->currency) {
                    $credit = -$locked->difference;
                    $this->wallet->change($service->client, $credit, __('Unused days of removed extras for :service', ['service' => $service->domain ?: '#'.$service->id]));
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

        $change->refresh()->load('service.client', 'service.product', 'items.option');
        $service = $change->service;

        if ($notCredited > 0) {
            Activity::log('service.resource_change_credit', 'The '.money($notCredited, $change->currency)." for the unused days of the removed extras of service #{$service->id} could not go into the client's wallet (wallet off or another currency). Settle it by hand.", $service);
        }

        $how = match ($change->server_state) {
            ResourceChange::SERVER_QUEUED => ' The server is set next.',
            ResourceChange::SERVER_MANUAL => ' No module sets them: staff set them by hand.',
            default => '',
        };

        Activity::log('service.resources_changed', "Extras of service #{$service->id} changed: {$this->summary($change)}.{$how}", $service);

        match ($change->server_state) {
            ResourceChange::SERVER_QUEUED => ApplyServiceResources::dispatch($change->id)->afterCommit(),
            ResourceChange::SERVER_MANUAL => $this->askStaff($change),
            ResourceChange::SERVER_DONE => $this->tellClient($change),
            default => null,
        };

        return true;
    }

    /**
     * Apply the changes waiting for this invoice, now that it is paid. One that fails is reported
     * and does not stop the rest of the payment.
     */
    public function applyForInvoice(Invoice $invoice): void
    {
        ResourceChange::query()->where('invoice_id', $invoice->id)->where('status', ResourceChange::STATUS_PENDING)->get()
            ->each(fn (ResourceChange $change): bool => (bool) rescue(fn (): bool => $this->apply($change), false));
    }

    /**
     * The invoice for these changes was cancelled, so the changes are too.
     */
    public function cancelForInvoice(Invoice $invoice): void
    {
        ResourceChange::query()->where('invoice_id', $invoice->id)->where('status', ResourceChange::STATUS_PENDING)
            ->update(['status' => ResourceChange::STATUS_CANCELLED]);
    }

    /**
     * Stop a waiting change. A change for the next renewal can only stop before that renewal is
     * invoiced; an unpaid invoice is cancelled with it, and money paid on it goes back to the wallet.
     *
     * @throws RuntimeException When it can no longer stop.
     */
    public function cancel(ResourceChange $change): void
    {
        if (! $change->isPending()) {
            return;
        }

        if ($change->mode === ResourceChange::MODE_RENEWAL && $this->renewalInvoiced($change)) {
            throw new RuntimeException(__('The renewal with the new extras is already invoiced, so this change can no longer stop.'));
        }

        if ($change->invoice !== null && $this->invoices->cancel($change->invoice)->status === InvoiceStatus::Paid) {
            throw new RuntimeException(__('The invoice for this change is already paid, so the change can no longer stop.'));
        }

        // Only a change that is still waiting stops; one applied in the meantime stays applied.
        ResourceChange::query()->whereKey($change->id)->where('status', ResourceChange::STATUS_PENDING)->update(['status' => ResourceChange::STATUS_CANCELLED]);
        $change->refresh();

        Activity::log('service.resource_change_cancelled', "Resource change for service #{$change->service_id} stopped", $change->service);
    }

    /**
     * Before the service's next period is billed. An upgrade still waiting for payment was priced
     * for the period that ends now, so it stops: its invoice is cancelled, which puts what was paid
     * on it back into the wallet. One whose invoice was just paid is applied first, and one with a
     * payment still going through stays open (see PlanChanges::settleBeforeRenewal()).
     * Returns true when the extras changed, so the renewal bills the new ones.
     */
    public function settleBeforeRenewal(Service $service): bool
    {
        $change = ResourceChange::query()
            ->where('service_id', $service->id)
            ->where('status', ResourceChange::STATUS_PENDING)
            ->where('mode', ResourceChange::MODE_INVOICE)
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
            $locked = ResourceChange::query()->lockForUpdate()->find($change->id);

            if ($locked === null || ! $locked->isPending()) {
                return $locked?->status === ResourceChange::STATUS_APPLIED ? 'applied' : 'gone';
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
                    $this->invoices->cancel($invoice);
                    $returned = $paid;
                } catch (NoExchangeRate) {
                    // The wallet is in another currency with no exchange rate: the invoice still
                    // closes, and what was paid stays on it for staff.
                    $this->invoices->cancelKeepingPayments($invoice);
                    $kept = $invoice->amount_paid;
                }
            }

            $locked->update(['status' => ResourceChange::STATUS_CANCELLED]);

            return 'stopped';
        });

        return match ($outcome) {
            'paid' => $this->apply($change),
            'applied' => true,
            'stopped' => $this->expired($change, $service, $returned, $kept),
            default => false,
        };
    }

    /**
     * Stop the upgrades whose period has passed but which are still waiting, because a payment was
     * going through the night their renewal was billed. The nightly run calls this. One that fails,
     * or still waits, is skipped and never stops the others.
     */
    public function settlePassed(): void
    {
        $changes = ResourceChange::query()
            ->with('service')
            ->where('status', ResourceChange::STATUS_PENDING)
            ->where('mode', ResourceChange::MODE_INVOICE)
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
     * Apply the changes planned for renewal dates up to today. Returns how many were applied. One
     * that fails is reported and skipped, so it never stops the nightly run.
     */
    public function applyScheduled(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::instance($today ?? today());

        return ResourceChange::query()
            ->where('status', ResourceChange::STATUS_PENDING)
            ->where('mode', ResourceChange::MODE_RENEWAL)
            ->whereDate('apply_on', '<=', $today)
            ->orderBy('id')
            ->get()
            ->filter(fn (ResourceChange $change): bool => (bool) rescue(fn (): bool => $this->apply($change), false))
            ->count();
    }

    /**
     * The change planned for the period that starts on this date, so the renewal bills the new steps.
     */
    public function scheduledFor(Service $service, CarbonInterface $periodStart): ?ResourceChange
    {
        return ResourceChange::query()->with('items.option')
            ->where('service_id', $service->id)
            ->where('status', ResourceChange::STATUS_PENDING)
            ->where('mode', ResourceChange::MODE_RENEWAL)
            ->whereDate('apply_on', $periodStart)
            ->latest('id')
            ->first();
    }

    /**
     * The change that waits for its invoice or its renewal date.
     */
    public function pendingFor(Service $service): ?ResourceChange
    {
        return ResourceChange::query()->with('items.option', 'invoice')
            ->where('service_id', $service->id)
            ->where('status', ResourceChange::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * The newest change whose billing side is applied, with its server state.
     */
    public function latestApplied(Service $service): ?ResourceChange
    {
        return ResourceChange::query()->with('items.option')
            ->where('service_id', $service->id)
            ->where('status', ResourceChange::STATUS_APPLIED)
            ->latest('id')
            ->first();
    }

    /**
     * The body of ApplyServiceResources: set the server to the plan plus the service's extras. It
     * always sends what the service has now, so running it again (or for an older change) changes
     * nothing, and a later change covers the earlier ones.
     */
    public function applyToServer(int $changeId, int $busyTry = 0): void
    {
        $change = DB::transaction(function () use ($changeId): ?ResourceChange {
            $locked = ResourceChange::query()->lockForUpdate()->find($changeId);

            if ($locked === null || $locked->status !== ResourceChange::STATUS_APPLIED || $locked->server_state !== ResourceChange::SERVER_QUEUED) {
                return null;
            }

            $locked->update(['server_state' => ResourceChange::SERVER_APPLYING, 'server_attempts' => $locked->server_attempts + 1]);

            return $locked;
        });

        if ($change === null) {
            return;
        }

        $service = Service::query()->with('product', 'client')->find($change->service_id);

        if ($service === null || ! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            $this->moveServerState($change, [ResourceChange::SERVER_APPLYING], ResourceChange::SERVER_NONE, ['server_message' => __('The service has ended, so nothing was set on the server.')]);

            return;
        }

        $this->afterApply($change, $this->provisioner->applyResources($service), $busyTry);
    }

    /**
     * The body of CheckServiceResources: the module started the change and said it is still
     * running, so the live server is compared with the plan plus the extras. A match is done; after
     * the last check staff are asked.
     */
    public function checkServer(int $changeId): void
    {
        $change = DB::transaction(function () use ($changeId): ?ResourceChange {
            $locked = ResourceChange::query()->lockForUpdate()->find($changeId);

            if ($locked === null || $locked->status !== ResourceChange::STATUS_APPLIED || $locked->server_state !== ResourceChange::SERVER_CHECKING) {
                return null;
            }

            $locked->update(['server_checks' => $locked->server_checks + 1]);

            return $locked;
        });

        if ($change === null) {
            return;
        }

        $service = Service::query()->with('product', 'client')->find($change->service_id);

        if ($service === null || ! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            $this->moveServerState($change, [ResourceChange::SERVER_CHECKING], ResourceChange::SERVER_NONE, ['server_message' => __('The service has ended, so nothing was set on the server.')]);

            return;
        }

        $check = $this->provisioner->checkResources($service);

        if ($check?->matches() === true) {
            $this->serverDone($change);

            return;
        }

        if ($change->server_checks < count(self::CHECK_DELAYS)) {
            CheckServiceResources::dispatch($change->id)->delay(self::CHECK_DELAYS[$change->server_checks]);

            return;
        }

        $message = $check === null
            ? __('The server did not answer the check.')
            : $this->clean(__('The server does not match yet: :problems', ['problems' => implode('; ', $check->problems)]));

        $this->serverFailed($change, $message);
    }

    /**
     * Send the server jobs again that were lost: one that stopped while setting the server (20
     * minutes), and one that waited or checked for an hour. The nightly run calls this. Returns how
     * many were sent.
     */
    public function retryServer(): int
    {
        $applying = now()->subMinutes(self::LOST_APPLYING_MINUTES);
        $waiting = now()->subMinutes(self::LOST_WAITING_MINUTES);
        $sent = 0;

        $lost = ResourceChange::query()
            ->where('status', ResourceChange::STATUS_APPLIED)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('server_state', ResourceChange::SERVER_APPLYING)->where('updated_at', '<=', $applying))
                ->orWhere(fn ($query) => $query->whereIn('server_state', [ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_CHECKING])->where('updated_at', '<=', $waiting)))
            ->orderBy('id')
            ->get();

        foreach ($lost as $change) {
            $sent += (int) rescue(fn (): bool => $this->sendAgain($change), false);
        }

        return $sent;
    }

    /**
     * Staff: send a failed (or stuck) server change again, with all its tries.
     */
    public function retryNow(ResourceChange $change, Admin $admin): void
    {
        $moved = ResourceChange::query()->whereKey($change->id)
            ->where('status', ResourceChange::STATUS_APPLIED)
            ->whereIn('server_state', [ResourceChange::SERVER_FAILED, ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_CHECKING])
            ->update(['server_state' => ResourceChange::SERVER_QUEUED, 'server_attempts' => 0, 'server_checks' => 0]);

        if ($moved === 0) {
            return;
        }

        $change->refresh();
        Activity::log('service.resource_change', "Staff sent the extras of service #{$change->service_id} to the server again.", $change->service, actor: $admin);
        ApplyServiceResources::dispatch($change->id)->afterCommit();
    }

    /**
     * Staff set the extras on the server by hand (or checked them): the change is done, older ones
     * with it, and the client gets the usual email.
     */
    public function markDone(ResourceChange $change, Admin $admin): void
    {
        $moved = ResourceChange::query()->whereKey($change->id)
            ->where('status', ResourceChange::STATUS_APPLIED)
            ->whereIn('server_state', [ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_APPLYING, ResourceChange::SERVER_CHECKING, ResourceChange::SERVER_FAILED, ResourceChange::SERVER_MANUAL])
            ->update(['server_state' => ResourceChange::SERVER_DONE, 'server_done_at' => now()]);

        if ($moved === 0) {
            return;
        }

        $change->refresh()->load('service.client', 'service.product', 'items.option');
        $this->supersede($change);
        Activity::log('service.resources_applied', "Staff marked the extras of service #{$change->service_id} as set on the server.", $change->service, actor: $admin);
        $this->tellClient($change);
    }

    /**
     * Staff: set the service's extras on its server now, without the queue. When it worked, every
     * open server change of the service is done; a module that is still applying it is checked
     * again later.
     */
    public function applyNow(Service $service): ModuleResult
    {
        $result = $this->provisioner->applyResources($service);

        if (! $result->success || ($result->data['manual'] ?? false) === true) {
            return $result;
        }

        $open = ResourceChange::query()->with('items.option')
            ->where('service_id', $service->id)
            ->where('status', ResourceChange::STATUS_APPLIED)
            ->whereIn('server_state', [ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_CHECKING, ResourceChange::SERVER_FAILED, ResourceChange::SERVER_MANUAL])
            ->orderByDesc('id')
            ->get();

        $latest = $open->first();

        if ($latest === null) {
            return $result;
        }

        if (($result->data['pending'] ?? false) === true) {
            ResourceChange::query()->whereKey($latest->id)->update(['server_state' => ResourceChange::SERVER_CHECKING, 'server_checks' => 0]);
            CheckServiceResources::dispatch($latest->id)->delay(self::CHECK_DELAYS[0])->afterCommit();

            return $result;
        }

        ResourceChange::query()->whereKey($open->modelKeys())->update(['server_state' => ResourceChange::SERVER_DONE, 'server_done_at' => now()]);
        $latest->refresh()->load('service.client', 'service.product', 'items.option');
        $this->tellClient($latest, (bool) ($result->data['restart'] ?? false));

        return $result;
    }

    /**
     * What the resources card on the client's service page shows about the server: working on it,
     * ready, waiting for staff, or nothing.
     *
     * @return array{state: string, text: string, busy: bool}
     */
    public function serverStatus(Service $service): array
    {
        $latest = $this->latestApplied($service);

        [$state, $text] = match ($latest?->server_state) {
            ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_APPLYING, ResourceChange::SERVER_CHECKING => ['working', __('We are adding your resources. This can take a few minutes.')],
            ResourceChange::SERVER_FAILED => ['failed', __('We could not add your resources yet. Our team is on it.')],
            ResourceChange::SERVER_MANUAL => ['manual', __('Our team is setting up your resources.')],
            ResourceChange::SERVER_DONE => $latest->server_done_at !== null && $latest->server_done_at->gt(now()->subHours(self::DONE_NOTICE_HOURS))
                ? ['done', $this->restarts($latest) ? __('Your new resources are ready. Restart your server if you do not see them yet.') : __('Your new resources are ready.')]
                : ['none', ''],
            default => ['none', ''],
        };

        return ['state' => $state, 'text' => $text, 'busy' => $state === 'working'];
    }

    /**
     * The resources card for the client's service page (ServicePanels), or null when it would have
     * nothing to show: no extras, no waiting change, no server news and nothing the client may add
     * now (client changes off, every option only at order, or the service blocked). It lists the
     * extras, shows a waiting change or the server state, and has the sliders when the client may
     * change them.
     *
     * @return array{view: string, data: array<string, mixed>}|null
     */
    public function panelFor(Service $service): ?array
    {
        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            return null;
        }

        $service->loadMissing(['product', 'client', 'resources.option']);
        // In the order the store shows the options; rows whose option is gone come last.
        $rows = $service->resources->filter(fn (ServiceResourceOption $row): bool => $row->isActive())->sortBy([
            fn (ServiceResourceOption $a, ServiceResourceOption $b): int => ($a->option?->sort_order ?? PHP_INT_MAX) <=> ($b->option?->sort_order ?? PHP_INT_MAX),
            fn (ServiceResourceOption $a, ServiceResourceOption $b): int => $a->id <=> $b->id,
        ])->values();
        $offered = $service->billing_cycle->isRecurring()
            && $this->options->offeredFor($service->product, (string) $service->currency, $service->billing_cycle)->isNotEmpty();
        $pending = $this->pendingFor($service);
        $status = $this->serverStatus($service);
        $empty = $rows->isEmpty() && $pending === null && $status['state'] === 'none';

        // Checked before the sliders, which may ask the module.
        if ($empty && ! $offered) {
            return null;
        }

        $blocked = $this->blockedReason($service);
        $sliders = $this->enabled() && $blocked === null ? $this->options->forService($service) : [];
        $changeable = collect($sliders)->contains(fn (array $slider): bool => $slider['can_add'] || $slider['can_remove']);

        if ($empty && ! $changeable) {
            return null;
        }

        return [
            'view' => 'theme::client.services.resources-card',
            'data' => [
                'resourceRows' => $rows,
                'resourcePending' => $pending,
                'resourceStatus' => $status,
                'resourceBlocked' => $this->enabled() ? $blocked : null,
                'resourceSliders' => $changeable ? $sliders : [],
                'resourceEnabled' => $this->enabled(),
            ],
        ];
    }

    /**
     * A quote in words for clients and staff: the lines of the price box, the button and the
     * texts to confirm. Amounts are formatted in the service's currency.
     *
     * @param  array<string, mixed>  $quote  From quote().
     * @return array<string, mixed>
     */
    public function present(Service $service, array $quote, bool $free = false): array
    {
        $service->loadMissing(['product', 'client', 'addons', 'resources']);
        $currency = (string) $service->currency;
        $money = fn (int $amount): string => money($amount, $currency);
        $suffix = $service->billing_cycle->suffix();
        $difference = $free ? 0 : (int) $quote['difference'];
        $mode = $free ? ResourceChange::MODE_NOW : (string) $quote['mode'];
        $items = collect($quote['items']);
        $date = $service->next_due_date?->translatedFormat('d M Y');
        $now = $service->renewalTotal();
        $after = $now - (int) $quote['old'] + (int) $quote['new'];
        $onlyRemovals = $items->isNotEmpty() && $items->every(fn (array $item): bool => $item['to'] <= $item['from']);

        if ($items->isEmpty()) {
            return [
                'changes' => [],
                'lines' => [],
                'message' => __('Move a slider to see the new price.'),
                'button' => null,
                'confirm' => null,
                'note' => null,
                'mode' => null,
                'tax' => null,
                'difference' => 0,
                'expected' => null,
            ];
        }

        $tax = $this->taxFor($service, $difference);

        $lines = [
            ['label' => __('Price now'), 'value' => $money($now).$suffix],
            ['label' => __('New price'), 'value' => $money($after).$suffix, 'strong' => true],
        ];

        if ($difference > 0) {
            $lines[] = ['label' => trans_choice('Pay today for :count day left|Pay today for :count days left', (int) $quote['days_left'], ['count' => (int) $quote['days_left']]), 'value' => $money($difference), 'strong' => true];
        } elseif ($difference < 0 && $mode === ResourceChange::MODE_NOW) {
            $lines[] = ['label' => __('Back to your wallet'), 'value' => $money(-$difference), 'strong' => true];
        }

        if ($tax !== null) {
            $lines[] = ['label' => $tax['label'], 'value' => $tax['amount']];
        }

        // What the invoice will ask for: tax added on top is part of it.
        $payable = $difference + ($tax !== null && ! $tax['inclusive'] ? $tax['minor'] : 0);

        $message = match (true) {
            $free => __('The change happens now, with nothing to pay or credit.'),
            $mode === ResourceChange::MODE_INVOICE => __('You pay :amount today. Your new resources are added once the invoice is paid.', ['amount' => $money($payable)]),
            $mode === ResourceChange::MODE_RENEWAL => __('Your extras change on :date. Nothing to pay now.', ['date' => $date]),
            $difference < 0 => __('The change happens now, and :amount goes back to your wallet.', ['amount' => $money(-$difference)]),
            default => __('The change happens now. Nothing to pay.'),
        };

        return [
            'changes' => $items->map(fn (array $item): array => [
                'kind' => $item['kind'],
                'label' => $item['label'],
                'from' => $item['from'],
                'to' => $item['to'],
                'from_text' => $this->stepText($item, $item['from']),
                'to_text' => $this->stepText($item, $item['to']),
            ])->all(),
            'lines' => $lines,
            'message' => $message,
            'renewal' => __('From :date you pay :amount.', ['date' => $date, 'amount' => $money($after).$suffix]),
            'button' => match (true) {
                $difference > 0 && ! $free => __('Pay :amount and upgrade', ['amount' => $money($payable)]),
                $onlyRemovals => __('Lower my extras'),
                default => __('Save changes'),
            },
            'confirm' => $quote['removes_addresses'] ? __('Removing addresses takes them off your server. Sites that use them stop working.') : null,
            'note' => $quote['restarts'] ? __('This change may need a restart of your server.') : null,
            'mode' => $mode,
            'tax' => $tax === null ? null : ['label' => $tax['label'], 'amount' => $tax['amount']],
            'difference' => $difference,
            'expected' => $difference,
        ];
    }

    /**
     * Each kind of a change as its name and its old and new value, such as RAM, "+2 GB" and
     * "+4 GB", or Backups, "Off" and "On". The same keys as the changes of present(), so pages draw
     * both lists the same way, with an arrow that turns round on right-to-left pages.
     *
     * @return list<array{kind: string, label: string, from_text: string, to_text: string}>
     */
    public function describe(ResourceChange $change): array
    {
        $change->loadMissing('items.option');

        return $change->items->map(function (ResourceChangeItem $item): array {
            $option = $item->option;
            $info = [
                'unit' => $item->unit,
                'step_size' => $item->step_size,
                'ladder' => $option?->ladder,
                'toggle' => $option !== null ? ResourceKinds::isToggle($option->max_steps, $option->ladder) : $item->kind === 'backups',
            ];

            return [
                'kind' => $item->kind,
                'label' => $option?->displayLabel() ?? ResourceOption::kindLabel($item->kind),
                'from_text' => $this->stepText($info, $item->from_steps),
                'to_text' => $this->stepText($info, $item->to_steps),
            ];
        })->values()->all();
    }

    /**
     * The validated steps: kinds the service has a slider for, whole numbers inside its range.
     *
     * @param  array<mixed>  $steps
     * @param  Collection<string, array<string, mixed>>  $sliders
     * @return array<string, int>
     *
     * @throws ValidationException
     */
    private function wanted(array $steps, Collection $sliders): array
    {
        if (count($steps) > ResourceOptions::MAX_KINDS) {
            throw ValidationException::withMessages(['steps' => __('Choose at most :count extras.', ['count' => ResourceOptions::MAX_KINDS])]);
        }

        $errors = [];
        $wanted = [];

        foreach ($steps as $kind => $value) {
            $slider = is_string($kind) && ResourceKind::isValidKey($kind) ? $sliders->get($kind) : null;

            if ($slider === null) {
                $errors['steps'][] = __('We do not know the option :kind.', ['kind' => Str::limit((string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) $kind)), 40)]);

                continue;
            }

            $number = ResourceOptions::wholeNumber($value) ?? -1;
            [$min, $max, $current] = [(int) $slider['min'], (int) $slider['max'], (int) $slider['current']];

            if ($number >= $min && $number <= $max) {
                $wanted[$kind] = $number;

                continue;
            }

            $errors["steps.{$kind}"][] = match (true) {
                $number >= 0 && $number < $current && $min >= $current => __(':resource cannot go below what you have now.', ['resource' => $slider['label']]),
                $number > $max && filled($slider['limit_reason'] ?? null) => (string) $slider['limit_reason'],
                default => __('Choose between :min and :max for :resource.', ['min' => $min, 'max' => $max, 'resource' => $slider['label']]),
            };
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $wanted;
    }

    /**
     * The most that removing these steps may give back for the days left, or null for no cap. It
     * is never more than was paid for them:
     *
     * - with a paid line for the period (order, renewal or upgrade): its share for these steps,
     *   nothing after a refund and less after a credit note;
     * - without one, when a change of this period grew the row: only what those changes were paid
     *   with (see paidByChanges()), so steps staff gave without charging give nothing back;
     * - without one, for a row no change of this period grew (imported or converted, paid outside
     *   Nuvabill): no cap.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, days_left: int}  $period
     */
    private function creditCap(ServiceResourceOption $row, int $removed, int $from, array $period): ?int
    {
        $paidLeft = $this->paidForDaysLeft($row, $period['end'], $period['days_left']);

        if ($paidLeft !== null) {
            return (int) round($paidLeft * $removed / max(1, $from));
        }

        return $this->paidByChanges($row, $period['start']);
    }

    /**
     * What this period's applied changes of the row's kind were paid with, when one of them grew
     * it, or null when none did. Only growth that happened at once counts, at its cost (taken off
     * the credit it was netted with, or 0 for a staff change without charging), less what removals
     * of the kind already gave back this period. Growth that waited for an invoice or a renewal
     * counts through its paid line, so here it counts as nothing.
     */
    private function paidByChanges(ServiceResourceOption $row, CarbonImmutable $periodStart): ?int
    {
        $items = ResourceChangeItem::query()
            ->with('change:id,mode')
            ->where('kind', $row->kind)
            ->whereHas('change', fn ($query) => $query
                ->where('service_id', $row->service_id)
                ->where('status', ResourceChange::STATUS_APPLIED)
                ->where('applied_at', '>=', $periodStart))
            ->get();

        if (! $items->contains(fn (ResourceChangeItem $item): bool => $item->to_steps > $item->from_steps)) {
            return null;
        }

        $now = $items->filter(fn (ResourceChangeItem $item): bool => $item->change?->mode === ResourceChange::MODE_NOW);
        $paid = (int) $now->sum(fn (ResourceChangeItem $item): int => $item->to_steps > $item->from_steps ? $item->cost : 0);
        $given = (int) $now->sum(fn (ResourceChangeItem $item): int => $item->to_steps < $item->from_steps ? $item->credit : 0);

        return max(0, $paid - $given);
    }

    /**
     * What the client paid for the days left of the current period for one row: its period line
     * and its upgrades, each for its own days, less credit notes. Null when no paid line covers the
     * period (see creditCap()).
     */
    private function paidForDaysLeft(ServiceResourceOption $row, CarbonImmutable $end, int $daysLeft): ?int
    {
        $lastDay = $end->subDay();
        $lines = InvoiceItem::query()
            ->with('invoice.creditNotes')
            ->where('service_resource_option_id', $row->id)
            ->whereIn('type', [InvoiceItem::TYPE_RESOURCE, InvoiceItem::TYPE_RESOURCE_CHANGE])
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->whereDate('period_start', '<=', $lastDay)
            ->whereDate('period_end', '>=', $lastDay)
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Refunded]))
            ->get();

        if ($lines->isEmpty()) {
            return null;
        }

        $paid = 0.0;

        foreach ($lines as $line) {
            $days = max(1, (int) round($line->period_start->diffInDays($line->period_end)) + 1);
            $paid += max(0, $line->amount) * Proration::keptShare($line->invoice) * min($daysLeft, $days) / $days;
        }

        return (int) round($paid);
    }

    /**
     * The ids of the product's options with a stock limit that the wanted steps grow, read without
     * a lock (start() reads the steps again under the locks). The steps are read the same way as
     * wanted() reads them, so every option a change may count is locked before the service.
     *
     * @param  array<mixed>  $steps
     * @return list<int>
     */
    private function stockOptionsFor(Service $service, array $steps): array
    {
        $current = ServiceResourceOption::query()->where('service_id', $service->id)->where('status', ServiceResourceOption::STATUS_ACTIVE)->pluck('steps', 'kind');
        $kinds = [];

        foreach ($steps as $kind => $value) {
            $number = is_string($kind) ? ResourceOptions::wholeNumber($value) : null;

            if ($number !== null && $number > (int) ($current[$kind] ?? 0)) {
                $kinds[] = $kind;
            }
        }

        if ($kinds === []) {
            return [];
        }

        return ResourceOption::query()->where('product_id', $service->product_id)->whereIn('kind', $kinds)->whereNotNull('stock')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Refuse growth that the options' stock left cannot take, counted under the locks.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @throws ResourceSoldOut
     */
    private function checkStock(array $items): void
    {
        foreach ($items as $item) {
            if ($item['to'] <= $item['from'] || $item['option_id'] === null) {
                continue;
            }

            // Only options with a stock limit are counted (and so locked); start() locked them first.
            $option = ResourceOption::query()->find($item['option_id']);

            if ($option === null || $option->stock === null) {
                continue;
            }

            $left = $option->lockedStockLeft();

            if ($left !== null && $left < $item['to'] - $item['from']) {
                throw new ResourceSoldOut($option, $left);
            }
        }
    }

    /**
     * @param  Collection<int, ResourceChangeItem>  $items
     * @return list<int>
     */
    private function growingOptionIds(Collection $items): array
    {
        return $items->filter(fn (ResourceChangeItem $item): bool => $item->to_steps > $item->from_steps && $item->resource_option_id !== null)
            ->pluck('resource_option_id')->unique()->sort()->map(fn (mixed $id): int => (int) $id)->values()->all();
    }

    /**
     * Give each kind the change adds, that the service has no row of yet, an empty row now (0
     * steps, cancelled until the change is applied). The upgrade invoice or the renewal then bills
     * it with a line that points at the row, so a later removal knows what was paid for it (see
     * paidForDaysLeft()). Call it under the service's lock.
     */
    private function addEmptyRows(Service $service, ResourceChange $change): void
    {
        $change->loadMissing('items');
        $service->loadMissing('resources');

        foreach ($change->items as $item) {
            if ($item->to_steps > $item->from_steps && $service->resources->firstWhere('kind', $item->kind) === null) {
                $service->resources->push($service->resources()->create([
                    'resource_option_id' => $item->resource_option_id,
                    'kind' => $item->kind,
                    'unit' => $item->unit,
                    'step_size' => $item->step_size,
                    'steps' => 0,
                    'recurring_amount' => 0,
                    'status' => ServiceResourceOption::STATUS_CANCELLED,
                ]));
            }
        }
    }

    /**
     * The invoice lines of an upgrade: one line per kind that grows (for the days left), its setup
     * fee, and one negative line for the unused days of extras that go down. A kind the service has
     * no row of yet gets an empty row first (see addEmptyRows()).
     *
     * @return list<array<string, mixed>>
     */
    private function invoiceLines(Service $service, ResourceChange $change): array
    {
        $start = CarbonImmutable::today();
        $end = $service->next_due_date->subDay();
        $taxed = (bool) $service->product->taxable;
        $change->load('items.option');
        $this->addEmptyRows($service, $change);

        $lines = [];

        foreach ($change->items as $item) {
            if ($item->to_steps <= $item->from_steps) {
                continue;
            }

            if ($item->cost > 0) {
                $lines[] = LineItems::resourceChange($service, $item, $start, $end, $item->cost) + ['taxed' => $taxed];
            }

            if ($item->setup_fee > 0) {
                $row = clone $service->resources->firstWhere('kind', $item->kind);
                $row->forceFill(['steps' => $item->to_steps - $item->from_steps, 'step_size' => $item->step_size, 'unit' => $item->unit, 'status' => ServiceResourceOption::STATUS_ACTIVE]);
                $row->setRelation('option', $item->option);

                $lines[] = ['type' => InvoiceItem::TYPE_RESOURCE_CHANGE] + LineItems::resourceSetupFee($service, $row, $item->setup_fee) + ['taxed' => $taxed];
            }
        }

        $credit = (int) $change->items->sum('credit');

        if ($credit > 0) {
            $lines[] = [
                'type' => InvoiceItem::TYPE_RESOURCE_CHANGE,
                'description' => __('Unused days of removed extras').($service->domain ? ' - '.Str::limit((string) $service->domain, 120) : '').' ('.$start->format('d M Y').' - '.$end->format('d M Y').')',
                'amount' => -$credit,
                'service_id' => $service->id,
                'taxed' => $taxed,
                'period_start' => $start,
                'period_end' => $end,
            ];
        }

        return $lines;
    }

    /**
     * Why a waiting change can no longer happen, in words for the client, or null when it can.
     *
     * @param  Collection<int, ResourceOption>  $options  The locked options whose stock is counted.
     */
    private function stopReason(ResourceChange $change, ?Service $service, Collection $options): ?string
    {
        if ($service === null || in_array($service->status, [ServiceStatus::Terminated, ServiceStatus::Cancelled], true)) {
            return __('The service has ended.');
        }

        if ($change->mode === ResourceChange::MODE_INVOICE && $this->periodPassed($change, $service)) {
            return __('The service renewed before the change was paid.');
        }

        foreach ($options as $option) {
            $grow = (int) $change->items->where('resource_option_id', $option->id)->sum(fn (ResourceChangeItem $item): int => max(0, $item->to_steps - $item->from_steps));
            $left = $option->lockedStockLeft();

            if ($left !== null && $grow > $left) {
                return __('There is no more :resource left right now.', ['resource' => $option->displayLabel()]);
            }
        }

        return null;
    }

    /**
     * Whether the period an upgrade was priced for has passed: the service renewed since, or its
     * next period is already invoiced, paid or not.
     */
    private function periodPassed(ResourceChange $change, Service $service): bool
    {
        if ($change->invoice_id === null) {
            return false;
        }

        $dueDate = InvoiceItem::query()
            ->where('invoice_id', $change->invoice_id)
            ->where('type', InvoiceItem::TYPE_RESOURCE_CHANGE)
            ->whereNotNull('period_end')
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
                fn ($query) => $query->where('invoice_id', '>', $change->invoice_id),
            )
            ->exists();
    }

    /**
     * Where the server side of an applied change starts: the job for a module that sells extras,
     * staff by hand for a product without one, or nothing for a billing-only change.
     */
    private function serverStateFor(ResourceChange $change, Service $service): string
    {
        if (! $change->sets_server) {
            return ResourceChange::SERVER_DONE;
        }

        return $this->provisioner->productSupports($service->product, HasResourceOptions::class)
            ? ResourceChange::SERVER_QUEUED
            : ResourceChange::SERVER_MANUAL;
    }

    /**
     * What happens after the module answered: done, retried, checked later, or left to staff.
     */
    private function afterApply(ResourceChange $change, ModuleResult $result, int $busyTry): void
    {
        if (! $result->success && ($result->data['busy'] ?? false) === true) {
            // Other work held the service: try again in two minutes without using a try.
            $moved = $this->moveServerState($change, [ResourceChange::SERVER_APPLYING], ResourceChange::SERVER_QUEUED, ['server_attempts' => max(0, $change->server_attempts - 1)]);

            if ($moved && $busyTry + 1 < self::BUSY_TRIES) {
                ApplyServiceResources::dispatch($change->id, $busyTry + 1)->delay(self::BUSY_DELAY);
            }

            return;
        }

        if (! $result->success) {
            $message = $this->clean($result->message);

            if ($change->server_attempts < self::MAX_TRIES) {
                if ($this->moveServerState($change, [ResourceChange::SERVER_APPLYING], ResourceChange::SERVER_QUEUED, ['server_message' => $message])) {
                    ApplyServiceResources::dispatch($change->id)->delay(self::RETRY_DELAYS[$change->server_attempts - 1] ?? self::RETRY_DELAYS[1]);
                }

                return;
            }

            $this->serverFailed($change, $message);

            return;
        }

        if (($result->data['manual'] ?? false) === true) {
            if ($this->moveServerState($change, [ResourceChange::SERVER_APPLYING], ResourceChange::SERVER_MANUAL, ['server_message' => $this->clean($result->message)])) {
                $this->askStaff($change->refresh()->load('service.client', 'service.product', 'items.option'));
            }

            return;
        }

        if (($result->data['pending'] ?? false) === true) {
            if ($this->moveServerState($change, [ResourceChange::SERVER_APPLYING], ResourceChange::SERVER_CHECKING, ['server_checks' => 0, 'server_message' => $this->clean($result->message)])) {
                CheckServiceResources::dispatch($change->id)->delay(self::CHECK_DELAYS[0]);
            }

            return;
        }

        $this->serverDone($change, (bool) ($result->data['restart'] ?? false));
    }

    /**
     * The server has the extras: the change is done, older open ones with it, and the client is told.
     */
    private function serverDone(ResourceChange $change, bool $restart = false): void
    {
        if (! $this->moveServerState($change, [ResourceChange::SERVER_APPLYING, ResourceChange::SERVER_CHECKING], ResourceChange::SERVER_DONE, ['server_done_at' => now(), 'server_message' => null])) {
            return;
        }

        $change->refresh()->load('service.client', 'service.product', 'items.option');
        $this->supersede($change);
        $this->tellClient($change, $restart);
    }

    /**
     * The server did not take the change after every try: staff are told, the client sees a
     * general text.
     */
    private function serverFailed(ResourceChange $change, string $message): void
    {
        if (! $this->moveServerState($change, [ResourceChange::SERVER_APPLYING, ResourceChange::SERVER_CHECKING], ResourceChange::SERVER_FAILED, ['server_message' => $message])) {
            return;
        }

        $change->refresh()->load('service.client', 'service.product');
        $service = $change->service;

        Activity::log('service.resources_failed', "Extras of service #{$service->id} ({$service->label()}) were not set on the server: {$message}", $service);

        $this->mailer->sendToStaff('admin.resources_failed', TemplateMailer::serviceContext($service) + [
            'error' => $message,
            'admin_url' => route('admin.services.show', $service),
        ]);
    }

    /**
     * Move the server state only from the states given, so a staff "Mark done" or another run in
     * the meantime is never overwritten. Returns whether it moved.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $values
     */
    private function moveServerState(ResourceChange $change, array $from, string $to, array $values = []): bool
    {
        $moved = ResourceChange::query()->whereKey($change->id)
            ->where('status', ResourceChange::STATUS_APPLIED)
            ->whereIn('server_state', $from)
            ->update(['server_state' => $to] + $values) === 1;

        if ($moved) {
            $change->forceFill(['server_state' => $to] + $values);
        }

        return $moved;
    }

    /**
     * Older changes of the service that still wait for the server are covered by this one: the job
     * always sets everything the service has.
     */
    private function supersede(ResourceChange $change): void
    {
        ResourceChange::query()
            ->where('service_id', $change->service_id)
            ->where('id', '<', $change->id)
            ->where('status', ResourceChange::STATUS_APPLIED)
            ->whereIn('server_state', [ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_APPLYING, ResourceChange::SERVER_CHECKING, ResourceChange::SERVER_FAILED])
            ->update(['server_state' => ResourceChange::SERVER_DONE, 'server_done_at' => now()]);
    }

    /**
     * Send a lost server job again. A job that stopped while setting the server used its try.
     */
    private function sendAgain(ResourceChange $change): bool
    {
        if ($change->server_state === ResourceChange::SERVER_CHECKING) {
            if (! $this->moveServerState($change, [ResourceChange::SERVER_CHECKING], ResourceChange::SERVER_CHECKING)) {
                return false;
            }

            CheckServiceResources::dispatch($change->id);

            return true;
        }

        if ($change->server_state === ResourceChange::SERVER_APPLYING && $change->server_attempts >= self::MAX_TRIES) {
            $this->serverFailed($change, __('The server job stopped before the server answered. Check the server.'));

            return true;
        }

        if (! $this->moveServerState($change, [ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_APPLYING], ResourceChange::SERVER_QUEUED)) {
            return false;
        }

        ApplyServiceResources::dispatch($change->id);

        return true;
    }

    /**
     * Ask staff to set the extras by hand: the product has no module that can.
     */
    private function askStaff(ResourceChange $change): void
    {
        $service = $change->service;

        $this->mailer->sendToStaff('admin.resources_by_hand', TemplateMailer::serviceContext($service) + [
            'resources' => ['list' => new MarkdownValue(Locales::in(Locales::default(), fn (): string => $this->listFor($change)))],
            'admin_url' => route('admin.services.show', $service),
        ]);
    }

    /**
     * Tell the client their extras changed: the new list, what renewals cost for them, and a note
     * about a wallet credit or a restart.
     */
    private function tellClient(ResourceChange $change, bool $restart = false): void
    {
        $service = $change->service;

        if ($service === null || $service->client === null) {
            return;
        }

        $service->unsetRelation('resources');
        $locale = Locales::forClient($service->client);
        $extras = (int) $service->resources()->where('status', ServiceResourceOption::STATUS_ACTIVE)->where('steps', '>', 0)->sum('recurring_amount');
        $credited = $change->mode === ResourceChange::MODE_NOW && $change->difference < 0
            && $this->wallet->enabled() && $service->client->currency === $change->currency;

        $resources = Locales::in($locale, fn (): array => [
            'list' => new MarkdownValue($this->listFor($change)),
            'amount' => money($extras, (string) $service->currency),
            'cycle' => $service->billing_cycle->label(),
            'note' => match (true) {
                $credited => __('We added :amount to your wallet for the unused days of the removed extras.', ['amount' => money(-$change->difference, $change->currency)]),
                $restart || ($change->sets_server && $this->restarts($change)) => __('Restart your server to use the new resources.'),
                default => '',
            },
        ]);

        $this->mailer->send('service.resources_changed', $service->client, TemplateMailer::serviceContext($service) + ['resources' => $resources]);
    }

    /**
     * The change as a Markdown list for emails: "- RAM: +4 GB (12 GB in total)".
     */
    private function listFor(ResourceChange $change): string
    {
        return $change->items->map(function (ResourceChangeItem $item): string {
            $option = $item->option;
            $label = $option?->displayLabel() ?? ResourceOption::kindLabel($item->kind);
            $toggle = $option !== null ? ResourceKinds::isToggle($option->max_steps, $option->ladder) : ($item->kind === 'backups');
            $info = ['unit' => $item->unit, 'step_size' => $item->step_size, 'ladder' => $option?->ladder, 'toggle' => $toggle, 'included' => $option?->included];
            $text = $this->stepText($info, $item->to_steps);

            if ($item->to_steps > 0 && $info['included'] !== null && $info['ladder'] === null && ! $toggle) {
                $text .= ' ('.__(':amount in total', ['amount' => ResourceKinds::format($item->unit, $info['included'] + $item->to_steps * $item->step_size)]).')';
            }

            return '- '.$this->markdownText($label.': '.$text);
        })->implode("\n");
    }

    /**
     * Steps of a kind as people read them: "+4 GB", a ladder step's name, "On" or "Off", or "No extra".
     *
     * @param  array<string, mixed>  $item  unit, step_size, ladder and toggle.
     */
    private function stepText(array $item, int $steps): string
    {
        $ladder = $item['ladder'] ?? null;

        return match (true) {
            $ladder !== null => ResourceKinds::format((string) $item['unit'], $steps, $ladder, $steps),
            (bool) ($item['toggle'] ?? false) => $steps > 0 ? __('On') : __('Off'),
            $steps > 0 => '+'.ResourceKinds::format((string) $item['unit'], $steps * (int) $item['step_size']),
            default => __('No extra'),
        };
    }

    /**
     * A short summary for the activity log: "ram 2→5, cpu 0→1".
     */
    private function summary(ResourceChange $change): string
    {
        return $change->items->map(fn (ResourceChangeItem $item): string => "{$item->kind} {$item->from_steps}→{$item->to_steps}")->implode(', ');
    }

    /**
     * Whether one of the change's kinds restarts the server, or needs a restart.
     */
    private function restarts(ResourceChange $change): bool
    {
        $change->loadMissing('items.option');

        return $change->items->contains(fn (ResourceChangeItem $item): bool => (bool) $item->option?->restarts);
    }

    /**
     * The tax on what the client pays now, for the price box, or null when there is none.
     *
     * @return array{label: string, amount: string, minor: int, inclusive: bool}|null
     */
    private function taxFor(Service $service, int $difference): ?array
    {
        if ($difference <= 0 || ! $service->product->taxable) {
            return null;
        }

        $taxes = app(Taxes::class);
        $rule = $taxes->ruleFor($service->client);

        if ($rule === null) {
            return null;
        }

        $inclusive = $taxes->inclusive();
        $minor = Taxes::amount($difference, $rule->rate, $inclusive);
        $amount = money($minor, (string) $service->currency);

        return [
            'label' => $rule->name.' ('.$rule->percentLabel().')',
            'amount' => $inclusive ? __(':tax included', ['tax' => $amount]) : __('Plus :tax', ['tax' => $amount]),
            'minor' => $minor,
            'inclusive' => $inclusive,
        ];
    }

    /**
     * A module message for staff screens and emails: one line, no markup, nothing that looks like
     * a key or password, and short enough for the column.
     */
    private function clean(string $message): string
    {
        $message = strip_tags($message);
        $message = (string) preg_replace('/([?&;\s](?:[a-z_]*key|api[a-z_]*|pass(?:word|wd)?|token|secret|sid)=)[^&\s]+/i', '$1***', ' '.$message);
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        return Str::limit($message === '' ? __('No reason given.') : $message, 250);
    }

    /**
     * Text placed in a Markdown list, so a staff-written name cannot add links or formatting.
     */
    private function markdownText(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~])/', '\\\\$1', $text);
    }

    /**
     * Whether the server still takes the service's last change: waiting for its job, being set, or
     * being checked.
     */
    private function serverBusy(Service $service): bool
    {
        return ResourceChange::query()->where('service_id', $service->id)->where('status', ResourceChange::STATUS_APPLIED)->latest('id')->first()?->serverBusy() === true;
    }

    private function hasUnpaidInvoice(Service $service): bool
    {
        return Invoice::query()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Draft])
            ->whereHas('items', fn ($query) => $query->where('service_id', $service->id))
            ->exists();
    }

    private function renewalInvoiced(ResourceChange $change): bool
    {
        return InvoiceItem::query()
            ->where('billing_key', RenewalGenerator::billingKey('service', $change->service_id, $change->apply_on))
            ->exists();
    }

    /**
     * Log a change that stopped, and give back the invoice paid for it: a credit note puts the money
     * in the client's wallet, or staff are asked to settle it when it cannot go there.
     */
    private function stopped(ResourceChange $change, string $reason): void
    {
        $change->loadMissing('service', 'invoice.client');
        Activity::log('service.resource_change_stopped', "Resource change for service #{$change->service_id} stopped: {$reason}", $change->service);

        $invoice = $change->invoice;

        if ($invoice === null || $invoice->status !== InvoiceStatus::Paid || $invoice->creditableAmount() <= 0) {
            return;
        }

        $credited = $this->wallet->enabled() && $invoice->currency === $invoice->client->currency
            && rescue(fn (): bool => app(CreditNotes::class)->issue($invoice, $invoice->creditableAmount(), CreditNote::METHOD_WALLET, $reason) instanceof CreditNote, false);

        if (! $credited) {
            Activity::log('service.resource_change_refund', "Invoice {$invoice->displayNumber()} paid for a resource change that stopped. Give the money back to the client by hand.", $invoice);
        }
    }

    /**
     * Log an upgrade that stopped because it was not paid before the renewal.
     */
    private function expired(ResourceChange $change, Service $service, int $returned, int $kept): bool
    {
        $invoice = $change->invoice()->with('client')->first();
        $number = $invoice?->displayNumber() ?? '';

        Activity::log('service.resource_change_expired', "Resource change for service #{$service->id} stopped: its invoice {$number} was not paid before the renewal"
            .($returned > 0 ? '; '.money($returned, $change->currency).' paid on it went back to the wallet' : ''), $service);

        if ($kept > 0 && $invoice !== null) {
            Activity::log('service.resource_change_refund', 'Give '.money($kept, $invoice->currency)." back to the client by hand. It was paid on invoice {$number} for a resource change that stopped,"
                ." and the client's wallet is in {$invoice->client->currency} with no exchange rate from {$invoice->currency}.", $invoice);
        }

        return false;
    }

    private function sendInvoice(ResourceChange $change): void
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
