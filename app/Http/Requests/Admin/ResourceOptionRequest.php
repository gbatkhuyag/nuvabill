<?php

namespace App\Http\Requests\Admin;

use App\Billing\ResourceKinds;
use App\Billing\ResourceOptions;
use App\Enums\BillingCycle;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Models\Product;
use App\Models\ResourceOption;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Admin → a product → Resource options: one option (slider) with its steps, limits, stock and
 * prices per currency and billing cycle.
 *
 * The kind's limits come from the product's module (Provisioner::resourceKinds()). When an option
 * is edited after the module stopped offering its kind, or the module cannot be read, the copy of
 * the kind saved with the option is used, so staff can still change prices or hide it.
 *
 * Staff may type RAM in GB; it is stored in MB.
 */
class ResourceOptionRequest extends FormRequest
{
    /**
     * Units staff may type a stored unit in, with how many stored units one of them is.
     *
     * @var array<string, array<string, int>>
     */
    public const INPUT_UNITS = ['MB' => ['MB' => 1, 'GB' => 1024]];

    private ?ResourceKind $kind = null;

    private bool $kindFromModule = false;

    private ?string $kindProblem = null;

    public function authorize(): bool
    {
        // The route already needs products.manage.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->resolveKind();

        $unit = $this->kind?->unit;
        $data = [
            'label' => $this->trimmed('label'),
            'description' => $this->trimmed('description'),
            'step_size' => $this->inStoredUnit('step_size', 'step_unit', $unit),
            'included' => $this->inStoredUnit('included', 'included_unit', $unit),
        ];

        // A ladder counts sizes, and a switch is on or off: both have steps of one.
        if ($this->kind?->ladder !== null || $this->kind?->maxExtra === 1) {
            $data['step_size'] = 1;
        }

        if ($this->kind?->maxExtra === 1) {
            $data['max_steps'] = 1;
        }

        $this->merge($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => [$this->creating() ? 'required' : 'nullable', 'string', 'max:40'],
            'label' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:200'],
            'step_size' => ['required', 'integer', 'min:1', 'max:2000000000'],
            'step_unit' => ['nullable', Rule::in(['MB', 'GB'])],
            'included' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'included_unit' => ['nullable', Rule::in(['MB', 'GB'])],
            'min_steps' => ['required', 'integer', 'between:0,'.ResourceOptions::MAX_STEPS],
            'max_steps' => ['required', 'integer', 'between:1,'.ResourceOptions::MAX_STEPS],
            'default_steps' => ['required', 'integer', 'between:0,'.ResourceOptions::MAX_STEPS],
            'stock' => ['nullable', 'integer', 'between:0,1000000'],
            'client_can_add' => ['boolean'],
            'client_can_remove' => ['boolean'],
            'is_active' => ['boolean'],
            'prices' => ['nullable', 'array', 'max:60'],
            'prices.*' => ['array', 'max:10'],
            'prices.*.*' => ['array'],
            'prices.*.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'prices.*.*.setup_fee' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'prices.*.*.price' => __('price'),
            'prices.*.*.setup_fee' => __('setup fee'),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->checkKind($validator)) {
                    return;
                }

                $this->checkSteps($validator);
                $this->checkPrices($validator);
            },
        ];
    }

    /**
     * The kind the option sells, with its limits. Only call it after validation passed.
     */
    public function resourceKind(): ResourceKind
    {
        return $this->kind ?? throw new InvalidArgumentException('The kind was not checked.');
    }

    /**
     * Whether the kind came from the module now (so its copy on the option is refreshed), rather
     * than from the copy saved with the option.
     */
    public function kindFromModule(): bool
    {
        return $this->kindFromModule;
    }

    /**
     * The option's columns to save, with the copy of the kind (unit, shrink, restarts, note,
     * ladder and maximum) that store and client pages read instead of asking the module.
     *
     * @return array<string, mixed>
     */
    public function optionAttributes(): array
    {
        $kind = $this->resourceKind();
        $data = $this->validated();
        $label = $data['label'] ?? null;

        // A module's own kind has no translated name in core, so its name is kept when staff leave it empty.
        if ($label === null && ! ResourceKinds::isBuiltIn($kind->key)) {
            $label = Str::limit($kind->label, 60, '');
        }

        $attributes = [
            'label' => $label,
            'description' => $data['description'] ?? null,
            'step_size' => (int) $data['step_size'],
            'included' => isset($data['included']) ? (int) $data['included'] : null,
            'min_steps' => (int) $data['min_steps'],
            'max_steps' => (int) $data['max_steps'],
            'default_steps' => (int) $data['default_steps'],
            'stock' => isset($data['stock']) ? (int) $data['stock'] : null,
            'client_can_add' => $this->boolean('client_can_add'),
            // A disk cannot shrink on a live server, so clients cannot remove it.
            'client_can_remove' => $this->boolean('client_can_remove') && $kind->canShrink,
            'is_active' => $this->boolean('is_active'),
        ];

        if ($this->creating() || $this->kindFromModule) {
            $attributes += [
                'unit' => $kind->unit,
                'can_shrink' => $kind->canShrink,
                'restarts' => $kind->restarts,
                'note' => $kind->note !== null ? Str::limit($kind->note, 200, '') : null,
                'ladder' => $kind->ladder,
                'max_extra' => $kind->maxExtra,
            ];
        }

        if ($this->creating()) {
            $attributes['kind'] = $kind->key;
        }

        return $attributes;
    }

    /**
     * The posted prices in minor units: currency => cycle => [price, setup_fee], or null where the
     * price was left empty (that cycle is not offered in that currency).
     *
     * @return array<string, array<string, array{price: int, setup_fee: int}|null>>
     */
    public function pricesInMinor(): array
    {
        $prices = [];

        foreach ((array) ($this->validated()['prices'] ?? []) as $currency => $cycles) {
            foreach ((array) $cycles as $cycle => $amounts) {
                $price = $amounts['price'] ?? null;
                $prices[(string) $currency][(string) $cycle] = $price === null || $price === ''
                    ? null
                    : ['price' => Money::toMinor($price), 'setup_fee' => Money::toMinor($amounts['setup_fee'] ?? 0)];
            }
        }

        return $prices;
    }

    private function creating(): bool
    {
        return ! $this->option() instanceof ResourceOption;
    }

    private function product(): Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : throw new InvalidArgumentException('No product.');
    }

    private function option(): ?ResourceOption
    {
        $option = $this->route('resourceOption');

        return $option instanceof ResourceOption ? $option : null;
    }

    /**
     * Find the kind: from the module's kinds, or for a saved option whose kind the module no longer
     * lists (or cannot list), from the copy saved with the option.
     */
    private function resolveKind(): void
    {
        $option = $this->option();
        $key = $option?->kind ?? (string) $this->input('kind');

        try {
            $kinds = app(ResourceOptions::class)->kindsFor($this->product());
        } catch (InvalidArgumentException $exception) {
            $kinds = [];
            $this->kindProblem = $exception->getMessage();
        }

        if (isset($kinds[$key])) {
            $this->kind = $kinds[$key];
            $this->kindFromModule = true;

            return;
        }

        if ($option !== null) {
            $this->kind = new ResourceKind(
                key: $option->kind,
                label: $option->displayLabel(),
                unit: (string) $option->unit,
                minStep: ResourceKinds::BUILT_IN[$option->kind]['min_step'] ?? 1,
                maxExtra: $option->max_extra,
                canShrink: $option->can_shrink,
                restarts: $option->restarts,
                note: $option->note,
                ladder: $option->ladder,
            );
        }
    }

    /**
     * The kind is one this product can sell, and not on the product yet. Returns false when there
     * is nothing more to check.
     */
    private function checkKind(Validator $validator): bool
    {
        $option = $this->option();

        if ($option !== null && $this->filled('kind') && $this->input('kind') !== $option->kind) {
            $validator->errors()->add('kind', __('The kind of an option cannot change. Add a new option instead.'));

            return false;
        }

        if ($this->kind === null) {
            $validator->errors()->add('kind', $this->kindProblem ?? __('Choose a kind this product can sell.'));

            return false;
        }

        if ($option === null && ResourceOption::query()->where('product_id', $this->product()->id)->where('kind', $this->kind->key)->exists()) {
            $validator->errors()->add('kind', __('This product already has this option. Edit it instead.'));

            return false;
        }

        return true;
    }

    private function checkSteps(Validator $validator): void
    {
        $errors = $validator->errors();

        if ($errors->hasAny(['step_size', 'min_steps', 'max_steps', 'default_steps'])) {
            return;
        }

        $kind = $this->kind;
        $option = $this->option();
        $step = (int) $this->input('step_size');
        $min = (int) $this->input('min_steps');
        $max = (int) $this->input('max_steps');
        $default = (int) $this->input('default_steps');

        if ($option !== null && $step !== $option->step_size && $option->isUsed()) {
            $errors->add('step_size', __('Step size and kind cannot change while services use this option. Make a new option instead.'));
        } elseif ($step < $kind->minStep) {
            $errors->add('step_size', __('Each step must add at least :amount.', ['amount' => ResourceKinds::format($kind->unit, $kind->minStep)]));
        } elseif ($step % $kind->stepMultiple !== 0) {
            $errors->add('step_size', __('Each step must be a multiple of :amount.', ['amount' => ResourceKinds::format($kind->unit, $kind->stepMultiple)]));
        }

        if ($kind->ladder !== null && $max > count($kind->ladder) - 1) {
            $errors->add('max_steps', __('This kind has :count sizes above the plan, so choose at most :count.', ['count' => count($kind->ladder) - 1]));
        } elseif ($kind->maxExtra !== null && $max * $step > $kind->maxExtra) {
            $errors->add('max_steps', __('Most extra steps × step size is more than this server type allows (:max).', ['max' => ResourceKinds::format($kind->unit, $kind->maxExtra)]));
        }

        if ($min > $max) {
            $errors->add('min_steps', __('The fewest steps cannot be more than the most steps.'));
        } elseif ($default < $min || $default > $max) {
            $errors->add('default_steps', __('Steps chosen at first must be between the fewest and the most.'));
        }
    }

    /**
     * Prices only in the currencies staff can price in and the cycles the product is sold with,
     * and at least one in the billing currency.
     */
    private function checkPrices(Validator $validator): void
    {
        $catalog = app(ResourceOptions::class);
        $currencies = $catalog->currencies();
        $cycles = array_map(fn (BillingCycle $cycle): string => $cycle->value, $catalog->cycles($this->product()));
        $base = $currencies[0];
        $priced = false;

        if ($cycles === []) {
            $validator->errors()->add('prices', __('Give the product a price first. Options are priced for the billing cycles of the product.'));

            return;
        }

        foreach ((array) $this->input('prices', []) as $currency => $byCycle) {
            if (! in_array($currency, $currencies, true)) {
                $validator->errors()->add('prices', __('Prices can only be in these currencies: :list.', ['list' => implode(', ', $currencies)]));

                continue;
            }

            foreach ((array) $byCycle as $cycle => $amounts) {
                if (! in_array($cycle, $cycles, true)) {
                    $validator->errors()->add('prices', __('Prices can only be for the billing cycles this product is sold with.'));

                    continue;
                }

                if ($currency === $base && is_array($amounts) && ($amounts['price'] ?? null) !== null && ($amounts['price'] ?? '') !== '') {
                    $priced = true;
                }
            }
        }

        if (! $priced && ! $validator->errors()->has('prices')) {
            $validator->errors()->add('prices', __('Give a price in :currency for at least one billing cycle.', ['currency' => $base]));
        }
    }

    private function trimmed(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A number typed in another unit (RAM in GB) as the stored unit (MB). Anything that is not a
     * number is left as it is, for the rules to refuse.
     */
    private function inStoredUnit(string $key, string $unitKey, ?string $unit): mixed
    {
        $value = $this->input($key);
        $factor = self::INPUT_UNITS[(string) $unit][(string) $this->input($unitKey)] ?? 1;

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($factor === 1 || ! is_numeric($value)) {
            return $value === '' ? null : $value;
        }

        return (int) round((float) $value * $factor);
    }
}
