<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Extensions\Servers\Resources\ResourceLimit;
use App\Http\Controllers\Admin\SettingsController;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductAddonPrice;
use App\Models\ProductPrice;
use App\Models\ResourceOption;
use App\Models\ResourceOptionPrice;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Provisioning\Provisioner;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The resource catalog: which sliders a product offers, what they cost and what a client may
 * choose. Store, cart and client pages read only the database here (the kind snapshot on each
 * option), so they never ask a module or its server. Only kindsFor(), liveLimits() and
 * forService() may reach the module, through Provisioner, and never while database rows are locked.
 *
 * Every slider is described by the same array, used by the <x-resource-slider> component, the
 * order forms and the client's service page:
 *
 *     {kind, label, description, unit, step, included, min, max, value, toggle, ladder, restarts,
 *      note, stock_left, currency, prices: {cycle: {step, setup}}}
 */
final class ResourceOptions
{
    /**
     * The most kinds one order or change may name.
     */
    public const MAX_KINDS = 20;

    /**
     * The most steps one option may have.
     */
    public const MAX_STEPS = 1000;

    public function __construct(private Provisioner $provisioner, private ExchangeRates $rates) {}

    /**
     * Active options with a price in the currency (and the cycle, when given), in display order.
     *
     * @return Collection<int, ResourceOption>
     */
    public function offeredFor(Product $product, string $currency, ?BillingCycle $cycle = null): Collection
    {
        return $this->optionsOf($product)
            ->filter(fn (ResourceOption $option): bool => $option->is_active && $this->pricesOf($option, $currency, $cycle)->isNotEmpty())
            ->values();
    }

    /**
     * The sliders for a store page or an order form: each active option priced in the currency,
     * with its prices for every cycle. The most a client may choose is lowered to the stock left.
     *
     * @return list<array<string, mixed>>
     */
    public function forStore(Product $product, string $currency): array
    {
        $sliders = [];

        foreach ($this->offeredFor($product, $currency) as $option) {
            $left = $option->stockLeft();
            $max = $left === null ? $option->max_steps : max($option->min_steps, min($option->max_steps, $left));

            $sliders[] = $this->slider($option, $currency, $this->pricesOf($option, $currency), [
                'min' => $option->min_steps,
                'max' => $max,
                'value' => min(max($option->default_steps, $option->min_steps), $max),
                'stock_left' => $left,
            ]);
        }

        return $sliders;
    }

    /**
     * Check the steps chosen for a new order. Kinds must be the product's active options priced for
     * the cycle and currency, steps whole numbers between the option's fewest and most, and at
     * most MAX_KINDS kinds. Kinds not sent get their default steps; kinds at 0 are left out.
     *
     * Stock is not counted here: checkout counts it under a lock.
     *
     * @param  array<mixed>  $input
     * @return array<string, int> kind => steps
     *
     * @throws ValidationException With messages under "{$field}.{kind}", or "{$field}" for bad keys.
     */
    public function validateForOrder(Product $product, BillingCycle $cycle, string $currency, array $input, string $field = 'resources'): array
    {
        $options = $this->optionsOf($product)->filter(fn (ResourceOption $option): bool => $option->is_active)->keyBy('kind');
        $errors = [];
        $steps = [];

        if (count($input) > self::MAX_KINDS) {
            throw ValidationException::withMessages([$field => __('Choose at most :count extras.', ['count' => self::MAX_KINDS])]);
        }

        foreach ($input as $kind => $value) {
            $option = is_string($kind) && ResourceKind::isValidKey($kind) ? $options->get($kind) : null;

            if ($option === null) {
                $errors[$field][] = __('We do not know the option :kind.', ['kind' => Str::limit((string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) $kind)), 40)]);

                continue;
            }

            $wanted = self::wholeNumber($value);
            $offered = $this->pricesOf($option, $currency, $cycle)->isNotEmpty();

            if (! $offered) {
                // A hidden switch or slider still posts 0; only a real choice is refused.
                if ($wanted !== 0) {
                    $errors["{$field}.{$kind}"][] = __('This option is not offered with :cycle.', ['cycle' => $cycle->label()]);
                }

                continue;
            }

            if ($wanted === null || $wanted < $option->min_steps || $wanted > $option->max_steps) {
                $errors["{$field}.{$kind}"][] = __('Choose between :min and :max for :resource.', [
                    'min' => $option->min_steps,
                    'max' => $option->max_steps,
                    'resource' => $option->displayLabel(),
                ]);

                continue;
            }

            $steps[$kind] = $wanted;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($this->offeredFor($product, $currency, $cycle) as $option) {
            $steps[$option->kind] ??= min(max($option->default_steps, $option->min_steps), $option->max_steps);
        }

        return array_filter($steps, fn (int $value): bool => $value > 0);
    }

    /**
     * Priced cart lines for the chosen steps, in display order. Options that are gone, hidden or
     * not priced for the cycle and currency are left out, and steps are kept inside the option's
     * current fewest and most, so a cart made before staff changed an option shows what will be
     * billed.
     *
     * @param  array<string, int>  $steps
     * @param  Collection<int, ResourceOption>|null  $options  The product's options with prices, when already loaded.
     * @return list<array{kind: string, option_id: int, label: string, steps: int, step_size: int, unit: string, units: int, text: string, price: int, setup_fee: int}>
     */
    public function cartLines(Product $product, BillingCycle $cycle, string $currency, array $steps, ?Collection $options = null): array
    {
        $lines = [];

        foreach ($options ?? $this->optionsOf($product) as $option) {
            if (! $option->is_active || ! array_key_exists($option->kind, $steps)) {
                continue;
            }

            $price = $option->prices->first(fn (ResourceOptionPrice $price): bool => $price->currency === $currency && $price->billing_cycle === $cycle);
            $count = min(max((int) $steps[$option->kind], $option->min_steps), $option->max_steps);

            if ($price === null || $count <= 0) {
                continue;
            }

            $units = $count * $option->step_size;

            $lines[] = [
                'kind' => $option->kind,
                'option_id' => $option->id,
                'label' => $option->displayLabel(),
                'steps' => $count,
                'step_size' => $option->step_size,
                'unit' => $option->unit,
                'units' => $units,
                'text' => ResourceKinds::describe($option->displayLabel(), $option->unit, $units, $option->ladder, $count, ResourceKinds::isToggle($option->max_steps, $option->ladder)),
                'price' => $count * $price->price,
                'setup_fee' => $count * $price->setup_fee,
            ];
        }

        return $lines;
    }

    /**
     * The sliders for an existing service: what it has now (current) and the range a client, or
     * staff, may choose, with prices in the service's currency and cycle.
     *
     * A client may add only to active options priced for the service, up to the option's most and
     * its stock; may remove only when the option allows it and the server can shrink the kind. Staff
     * may choose 0 up to what the module allows. Both ranges are narrowed by the live limits the
     * module reports (Provisioner::resourceLimits(), which may ask the server and is cached), and
     * always include what the service has now. Rows whose option is gone or hidden stay listed, as
     * "retired", so they can still go down.
     *
     * A caller that holds database locks passes the limits it read before taking them, so a slow
     * or unreachable server never keeps those rows locked.
     *
     * @param  array<string, ResourceLimit>|null  $limits  The live limits, already read; null reads them here.
     * @return list<array<string, mixed>>
     */
    public function forService(Service $service, bool $staff = false, ?array $limits = null): array
    {
        $service->loadMissing(['product', 'resources.option']);
        $currency = (string) $service->currency;
        $cycle = $service->billing_cycle;
        $rows = $service->resources->keyBy('kind');
        $limits ??= $this->liveLimits($service);
        $sliders = [];
        $seen = [];

        foreach ($this->optionsOf($service->product) as $option) {
            $row = $rows->get($option->kind);

            if (! $option->is_active && ($row === null || ! $row->isActive())) {
                continue;
            }

            $seen[$option->kind] = true;
            $sliders[] = $this->serviceSlider($option->kind, $option, $row, $currency, $cycle, $staff, $limits[$option->kind] ?? null);
        }

        foreach ($rows as $kind => $row) {
            if (! isset($seen[$kind]) && $row->isActive()) {
                $sliders[] = $this->serviceSlider((string) $kind, null, $row, $currency, $cycle, $staff, $limits[$kind] ?? null);
            }
        }

        return $sliders;
    }

    /**
     * The live limits the module reports for the service's kinds (Provisioner::resourceLimits(),
     * which may ask the server and is cached for a minute), or none for a service that does not
     * renew.
     *
     * @return array<string, ResourceLimit>
     */
    public function liveLimits(Service $service): array
    {
        return $service->billing_cycle->isRecurring() ? $this->provisioner->resourceLimits($service) : [];
    }

    /**
     * A whole number of steps from form or JSON input, or null when it is not one. A switch may send
     * true or false. Every place that reads steps uses this, so they all agree on what was chosen.
     */
    public static function wholeNumber(mixed $value): ?int
    {
        return match (true) {
            is_bool($value) => (int) $value,
            is_int($value) => $value,
            is_string($value) && preg_match('/^\d{1,4}$/D', $value) === 1 => (int) $value,
            default => null,
        };
    }

    /**
     * Currencies staff can price options and add-ons in: the billing currency first, then every
     * currency with an exchange rate or used by product prices, clients, add-ons or options, from
     * the list Settings offers.
     *
     * @return list<string>
     */
    public function currencies(): array
    {
        $base = strtoupper((string) setting('billing.currency'));
        $used = collect($this->rates->currencies())
            ->merge(ProductPrice::query()->distinct()->pluck('currency'))
            ->merge(Client::query()->whereNotNull('currency')->distinct()->pluck('currency'))
            ->merge(ProductAddonPrice::query()->distinct()->pluck('currency'))
            ->merge(ResourceOptionPrice::query()->distinct()->pluck('currency'))
            ->map(fn (mixed $code): string => strtoupper((string) $code))
            ->filter(fn (string $code): bool => $code !== $base && in_array($code, SettingsController::CURRENCIES, true))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [$base, ...$used];
    }

    /**
     * Kinds staff may add to the product: the module's (HasResourceOptions), every built-in kind for
     * a product without a module (applied by hand), or none for a module without the contract.
     *
     * @return array<string, ResourceKind>
     *
     * @throws InvalidArgumentException With a message for staff when the module is missing or its settings are wrong.
     */
    public function kindsFor(Product $product): array
    {
        return $this->provisioner->resourceKinds($product);
    }

    /**
     * The billing cycles an option of this product can be priced for: the product's own, in the
     * usual order, without Free.
     *
     * @return list<BillingCycle>
     */
    public function cycles(Product $product): array
    {
        $priced = $product->prices()->pluck('billing_cycle')
            ->map(fn (mixed $cycle): ?BillingCycle => $cycle instanceof BillingCycle ? $cycle : BillingCycle::tryFrom((string) $cycle))
            ->filter()
            ->map(fn (BillingCycle $cycle): string => $cycle->value)
            ->all();

        return array_values(array_filter(
            BillingCycle::cases(),
            fn (BillingCycle $cycle): bool => $cycle !== BillingCycle::Free && in_array($cycle->value, $priced, true),
        ));
    }

    /**
     * The product's options with their prices, from the loaded relation when present.
     *
     * @return Collection<int, ResourceOption>
     */
    private function optionsOf(Product $product): Collection
    {
        if (! $product->exists) {
            return collect();
        }

        $product->loadMissing('resourceOptions.prices');

        return $product->resourceOptions;
    }

    /**
     * The option's prices in one currency (and cycle), in cycle order, as cycle => [step, setup].
     *
     * @return Collection<string, array{step: int, setup: int}>
     */
    private function pricesOf(ResourceOption $option, string $currency, ?BillingCycle $cycle = null): Collection
    {
        $order = array_flip(array_map(fn (BillingCycle $case): string => $case->value, BillingCycle::cases()));

        return $option->prices
            ->filter(fn (ResourceOptionPrice $price): bool => $price->currency === $currency
                && $price->billing_cycle !== BillingCycle::Free
                && ($cycle === null || $price->billing_cycle === $cycle))
            ->sortBy(fn (ResourceOptionPrice $price): int => $order[$price->billing_cycle->value])
            ->mapWithKeys(fn (ResourceOptionPrice $price): array => [$price->billing_cycle->value => ['step' => $price->price, 'setup' => $price->setup_fee]]);
    }

    /**
     * One slider array from an option and the range to offer.
     *
     * @param  Collection<string, array{step: int, setup: int}>  $prices
     * @param  array<string, mixed>  $range  min, max, value and stock_left, plus anything a caller adds.
     * @return array<string, mixed>
     */
    private function slider(ResourceOption $option, string $currency, Collection $prices, array $range): array
    {
        return [
            'kind' => $option->kind,
            'label' => $option->displayLabel(),
            'description' => $option->description,
            'unit' => $option->unit,
            'step' => $option->step_size,
            'included' => $option->included,
            'toggle' => ResourceKinds::isToggle($option->max_steps, $option->ladder),
            'ladder' => $option->ladder,
            'restarts' => $option->restarts,
            'note' => $option->note ?? ($option->restarts ? __('Takes effect after a restart.') : null),
            'currency' => $currency,
            'prices' => $prices->all(),
            ...$range,
        ];
    }

    /**
     * One slider for a service's kind, from its option (null when gone) and its row (null when the
     * service has none yet).
     *
     * @return array<string, mixed>
     */
    private function serviceSlider(string $kind, ?ResourceOption $option, ?ServiceResourceOption $row, string $currency, BillingCycle $cycle, bool $staff, ?ResourceLimit $limit): array
    {
        $current = $row !== null && $row->isActive() ? $row->steps : 0;
        $step = max(1, $option?->step_size ?? $row?->step_size ?? 1);
        $retired = $option === null || ! $option->is_active;
        $prices = $option !== null && ! $retired ? $this->pricesOf($option, $currency, $cycle) : collect();
        $canShrink = $option?->can_shrink ?? ResourceKinds::canShrink($kind);

        if ($staff) {
            $maxExtra = $option?->max_extra;
            $ladder = $option?->ladder;
            $min = 0;
            $max = $ladder !== null ? count($ladder) - 1 : ($maxExtra !== null ? intdiv($maxExtra, $step) : self::MAX_STEPS);
        } else {
            $canAdd = $option !== null && ! $retired && $option->client_can_add && $prices->isNotEmpty();
            $max = $canAdd ? $option->max_steps : $current;
            $left = $canAdd ? $option->stockLeft() : null;

            if ($left !== null) {
                $max = min($max, $current + $left);
            }

            $min = ($option?->client_can_remove ?? true) && $canShrink ? 0 : $current;
        }

        $reason = null;

        if ($limit !== null) {
            if ($limit->maxUnits !== null && intdiv(max(0, $limit->maxUnits), $step) < $max) {
                $max = intdiv(max(0, $limit->maxUnits), $step);
                $reason = $limit->reason;
            }

            if ($limit->minUnits !== null && (int) ceil($limit->minUnits / $step) > $min) {
                $min = (int) ceil($limit->minUnits / $step);
                $reason = $limit->reason;
            }
        }

        // What the service has now is always a valid choice.
        $max = min(self::MAX_STEPS, max($max, $current));
        $min = max(0, min($min, $current));

        return [
            'kind' => $kind,
            'label' => $option?->displayLabel() ?? ResourceKinds::label($kind),
            'description' => $option?->description,
            'unit' => $option?->unit ?? $row?->unit ?? '',
            'step' => $step,
            'included' => $option?->included,
            'min' => $min,
            'max' => $max,
            'value' => $current,
            'toggle' => ResourceKinds::isToggle($option?->max_steps ?? 0, $option?->ladder),
            'ladder' => $option?->ladder,
            'restarts' => (bool) $option?->restarts,
            'note' => $option?->note ?? ($option?->restarts ? __('Takes effect after a restart.') : null),
            'stock_left' => null,
            'currency' => $currency,
            'prices' => $prices->all(),
            'current' => $current,
            'can_add' => $max > $current,
            'can_remove' => $min < $current,
            'retired' => $retired,
            'limit_reason' => $reason,
        ];
    }
}
