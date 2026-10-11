<?php

namespace App\Http\Controllers\Admin;

use App\Billing\ExchangeRates;
use App\Billing\ResourceKinds;
use App\Billing\ResourceOptions;
use App\Contracts\HasResourceOptions;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResourceOptionRequest;
use App\Models\Product;
use App\Models\ResourceChangeItem;
use App\Models\ResourceOption;
use App\Models\ResourceOptionPrice;
use App\Models\ServiceResourceOption;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Admin → Products → a product → Resource options: the sliders clients can use to add RAM, disk,
 * IP addresses and other extras, with their step size, limits, stock and prices.
 *
 * Options are bound inside their product, so an option of another product gives 404. Every change
 * is logged as product.resource_options_updated. Only base() may reach a server, through
 * Provisioner; it is throttled and locked in the demo.
 */
class ResourceOptionController extends Controller
{
    /**
     * The product setting with the old Virtualizor resource add-on JSON (module 1.x).
     */
    private const OLD_ADDONS = 'resource_addons';

    public function __construct(private ResourceOptions $catalog) {}

    /**
     * The product's options in display order, with a preview of what clients see.
     */
    public function index(Product $product): View
    {
        $product->load(['resourceOptions.prices', 'prices']);
        $options = $product->resourceOptions;
        [$kinds, $kindsProblem] = $this->kinds($product);
        $currencies = $this->catalog->currencies();
        $cycles = $this->catalog->cycles($product);
        $ids = $options->modelKeys();

        $usedBy = ServiceResourceOption::query()
            ->whereIn('resource_option_id', $ids)
            ->where('status', ServiceResourceOption::STATUS_ACTIVE)
            ->where('steps', '>', 0)
            ->whereHas('service', fn (Builder $query): Builder => $query->whereNotIn('status', [ServiceStatus::Terminated, ServiceStatus::Cancelled]))
            ->selectRaw('resource_option_id, count(*) as aggregate')
            ->groupBy('resource_option_id')
            ->pluck('aggregate', 'resource_option_id');

        $hasHistory = ServiceResourceOption::query()->whereIn('resource_option_id', $ids)->distinct()->pluck('resource_option_id')
            ->merge(ResourceChangeItem::query()->whereIn('resource_option_id', $ids)->distinct()->pluck('resource_option_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return view('admin.products.resources.index', [
            'product' => $product,
            'cards' => $options->map(fn (ResourceOption $option): array => $this->card($option, $kinds, $cycles, $currencies[0], (int) ($usedBy[$option->id] ?? 0), in_array($option->id, $hasHistory, true)))->all(),
            'addable' => $kinds === null ? [] : array_diff_key($kinds, $options->keyBy('kind')->all()),
            'kindsProblem' => $kindsProblem,
            'notice' => $this->notice($product, $kinds),
            'unconverted' => $this->unconverted($product),
            'converted' => filled(($product->module_config ?? [])['resource_addons_converted'] ?? null),
            'cycles' => $cycles,
            'currencies' => $currencies,
            'preview' => collect($currencies)
                ->mapWithKeys(fn (string $currency): array => [$currency => $this->catalog->forStore($product, $currency)])
                ->filter(fn (array $sliders, string $currency): bool => $sliders !== [] || $currency === $currencies[0])
                ->all(),
        ]);
    }

    /**
     * The form for a new option (?kind= picks the resource kind).
     */
    public function create(Request $request, Product $product): View
    {
        [$kinds, $problem] = $this->kinds($product);
        $key = (string) $request->query('kind', '');
        $kind = $kinds[$key] ?? null;

        if ($kind === null || $product->resourceOptions()->where('kind', $key)->exists()) {
            abort(redirect()->route('admin.products.resources.index', $product)
                ->with('error', $problem ?? __('Choose a kind this product can sell.')));
        }

        $option = new ResourceOption([
            'kind' => $kind->key,
            'unit' => $kind->unit,
            'step_size' => $kind->ladder !== null ? 1 : max($kind->minStep, $kind->key === 'ram' ? 1024 : $kind->minStep),
            'min_steps' => 0,
            'max_steps' => $this->defaultMaxSteps($kind),
            'default_steps' => 0,
            'client_can_add' => true,
            'client_can_remove' => $kind->canShrink,
            'is_active' => true,
            'can_shrink' => $kind->canShrink,
            'restarts' => $kind->restarts,
            'note' => $kind->note,
            'ladder' => $kind->ladder,
            'max_extra' => $kind->maxExtra,
        ]);

        return $this->form($product, $option, $kind, true);
    }

    public function store(ResourceOptionRequest $request, Product $product): RedirectResponse
    {
        try {
            $option = DB::transaction(function () use ($request, $product): ResourceOption {
                $option = $product->resourceOptions()->create($request->optionAttributes() + [
                    'sort_order' => (int) ResourceOption::query()->where('product_id', $product->id)->max('sort_order') + 1,
                ]);
                $this->syncPrices($option, $request->pricesInMinor());

                return $option;
            });
        } catch (UniqueConstraintViolationException) {
            // Saved twice at the same moment: the first one won.
            return back()->withInput()->withErrors(['kind' => __('This product already has this option. Edit it instead.')]);
        }

        $this->log($product, "Product {$product->name}: resource option {$option->displayLabel()} added");

        return redirect()->route('admin.products.resources.index', $product)->with('status', __('Option added. Check the preview to see what clients see.'));
    }

    public function edit(Product $product, ResourceOption $resourceOption): View
    {
        [$kinds] = $this->kinds($product);
        $resourceOption->load('prices');

        return $this->form($product, $resourceOption, $kinds[$resourceOption->kind] ?? null, isset($kinds[$resourceOption->kind]));
    }

    public function update(ResourceOptionRequest $request, Product $product, ResourceOption $resourceOption): RedirectResponse
    {
        DB::transaction(function () use ($request, $resourceOption): void {
            $resourceOption->update($request->optionAttributes());
            $this->syncPrices($resourceOption, $request->pricesInMinor());
        });

        $this->log($product, "Product {$product->name}: resource option {$resourceOption->displayLabel()} saved");

        return redirect()->route('admin.products.resources.index', $product)->with('status', __('Option saved. Clients who have it keep the price they pay.'));
    }

    /**
     * Delete an option no service uses; a used option can only be hidden.
     */
    public function destroy(Product $product, ResourceOption $resourceOption): RedirectResponse
    {
        $deleted = DB::transaction(function () use ($resourceOption): bool {
            // Locked, so a checkout cannot start using the option while it goes.
            $locked = ResourceOption::query()->whereKey($resourceOption->id)->lockForUpdate()->first();

            if ($locked === null || $locked->isUsed()) {
                return false;
            }

            $locked->delete();

            return true;
        });

        if (! $deleted) {
            return back()->with('error', __('Clients have this option, so it cannot be deleted. Hide it instead.'));
        }

        $this->log($product, "Product {$product->name}: resource option {$resourceOption->displayLabel()} deleted");

        return redirect()->route('admin.products.resources.index', $product)->with('status', __('Option deleted.'));
    }

    /**
     * Save a new display order of the product's options: either the full list of ids in their new
     * order, or one option moved up or down, hidden or shown again.
     */
    public function order(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required_without:option', 'array', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'option' => ['required_without:ids', 'integer'],
            'action' => ['required_with:option', Rule::in(['up', 'down', 'hide', 'show'])],
        ]);

        $message = DB::transaction(function () use ($data, $product): ?string {
            $options = ResourceOption::query()->where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();

            if (isset($data['ids'])) {
                $ids = array_map('intval', $data['ids']);

                if (count($ids) !== $options->count() || array_diff($options->modelKeys(), $ids) !== []) {
                    return null;
                }

                $this->renumber($options->sortBy(fn (ResourceOption $option): int => (int) array_search($option->id, $ids, true))->values());

                return 'reordered';
            }

            $option = $options->firstWhere('id', (int) $data['option']);

            if ($option === null) {
                return null;
            }

            if (in_array($data['action'], ['hide', 'show'], true)) {
                $option->update(['is_active' => $data['action'] === 'show']);

                return ($data['action'] === 'show' ? 'shown' : 'hidden').' '.$option->displayLabel();
            }

            $list = $options->values()->all();
            $from = array_search($option, $list, true);
            $to = $data['action'] === 'up' ? $from - 1 : $from + 1;

            if ($to >= 0 && $to < count($list)) {
                [$list[$from], $list[$to]] = [$list[$to], $list[$from]];
            }

            $this->renumber(collect($list));

            return 'moved '.$option->displayLabel();
        });

        if ($message === null) {
            abort_if($request->expectsJson(), 422, __('These options are not all on this product.'));

            return back()->with('error', __('These options are not all on this product.'));
        }

        $this->log($product, "Product {$product->name}: resource options {$message}");

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('admin.products.resources.index', $product)->with('status', __('Options saved.'));
    }

    /**
     * Remove the old Virtualizor resource add-on JSON that could not be moved to options. Until
     * then, services that still have those add-ons get no new server or plan change; afterwards
     * their servers are made without the old extras, so staff move those services first. The
     * removed JSON is kept in the activity log for the record.
     */
    public function removeOldAddons(Product $product): RedirectResponse
    {
        $removed = DB::transaction(function () use ($product): ?string {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $config = $locked?->module_config ?? [];

            if ($locked === null || ! array_key_exists(self::OLD_ADDONS, $config)) {
                return null;
            }

            $json = (string) $config[self::OLD_ADDONS];
            unset($config[self::OLD_ADDONS]);
            $locked->update(['module_config' => $config]);

            return $json;
        });

        if ($removed === null) {
            return redirect()->route('admin.products.resources.index', $product)->with('error', __('This product has no old add-on settings.'));
        }

        $this->log($product, "Product {$product->name}: old resource add-on settings removed: ".Str::limit($removed, 120));

        return redirect()->route('admin.products.resources.index', $product)->with('status', __('The old add-on settings were removed.'));
    }

    /**
     * What the plan itself includes per kind, read from the server through Provisioner, as JSON
     * {base: {kind: int|null}} or a message for staff.
     */
    public function base(Product $product, Provisioner $provisioner): JsonResponse
    {
        $result = $provisioner->baseResources($product);
        $headers = ['Cache-Control' => 'no-store, private'];

        if (! $result->success) {
            return response()->json(['message' => $result->message !== '' ? $result->message : __('The server did not answer. Check the server and try again.')], 422, $headers);
        }

        return response()->json(['base' => (object) ($result->data['base'] ?? [])], 200, $headers);
    }

    /**
     * @param  array<string, array<string, array{price: int, setup_fee: int}|null>>  $prices
     */
    private function syncPrices(ResourceOption $option, array $prices): void
    {
        foreach ($prices as $currency => $cycles) {
            foreach ($cycles as $cycle => $amounts) {
                if ($amounts === null) {
                    $option->prices()->where('currency', $currency)->where('billing_cycle', $cycle)->delete();

                    continue;
                }

                $option->prices()->updateOrCreate(['currency' => $currency, 'billing_cycle' => $cycle], $amounts);
            }
        }
    }

    /**
     * @param  Collection<int, ResourceOption>  $options
     */
    private function renumber(Collection $options): void
    {
        foreach ($options->values() as $position => $option) {
            if ($option->sort_order !== $position) {
                $option->update(['sort_order' => $position]);
            }
        }
    }

    private function form(Product $product, ResourceOption $option, ?ResourceKind $kind, bool $supported): View
    {
        $currencies = $this->catalog->currencies();
        $used = $option->exists && $option->isUsed();
        $kind ??= new ResourceKind(
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

        return view('admin.products.resources.form', [
            'product' => $product,
            'option' => $option,
            'kind' => $kind,
            'supported' => $supported,
            'used' => $used,
            'usedSteps' => $option->exists ? (int) $option->serviceRows()->where('status', ServiceResourceOption::STATUS_ACTIVE)->sum('steps') : 0,
            'currencies' => $currencies,
            'cycles' => $this->catalog->cycles($product),
            'priceValues' => $option->prices
                ->groupBy('currency')
                ->map(fn (Collection $prices): array => $prices->mapWithKeys(fn (ResourceOptionPrice $price): array => [$price->billing_cycle->value => ['price' => $price->price, 'setup_fee' => $price->setup_fee]])->all())
                ->all(),
            'rates' => app(ExchangeRates::class)->all(),
            'canReadBase' => $product->server_module !== null && app(Provisioner::class)->productSupports($product, HasResourceOptions::class),
            'locale' => str_replace('_', '-', Locales::numberLocale()),
        ]);
    }

    /**
     * The kinds the product can sell, or null with a message for staff when the module cannot say.
     *
     * @return array{0: array<string, ResourceKind>|null, 1: string|null}
     */
    private function kinds(Product $product): array
    {
        try {
            return [$this->catalog->kindsFor($product), null];
        } catch (InvalidArgumentException $exception) {
            return [null, $exception->getMessage()];
        }
    }

    /**
     * One option as a card on the list.
     *
     * @param  array<string, ResourceKind>|null  $kinds
     * @param  list<BillingCycle>  $cycles
     * @return array<string, mixed>
     */
    private function card(ResourceOption $option, ?array $kinds, array $cycles, string $base, int $usedBy, bool $hasHistory): array
    {
        $toggle = ResourceKinds::isToggle($option->max_steps, $option->ladder);
        $first = collect($cycles)->map(fn (BillingCycle $cycle): ?ResourceOptionPrice => $option->priceFor($base, $cycle))->filter()->first();
        $missing = collect($cycles)->reject(fn (BillingCycle $cycle): bool => $option->priceFor($base, $cycle) !== null)->map(fn (BillingCycle $cycle): string => $cycle->label());

        $size = match (true) {
            $option->ladder !== null => __('Sizes up to :size', ['size' => ResourceKinds::format($option->unit, $option->max_steps, $option->ladder, $option->max_steps)]),
            $toggle => $option->unit === '' ? __('On or off') : __('On or off · :amount', ['amount' => '+'.ResourceKinds::format($option->unit, $option->step_size)]),
            in_array($option->unit, ['', 'core', 'IP'], true) => __('Steps of :step · up to :max more', [
                'step' => ResourceKinds::format($option->unit, $option->step_size),
                'max' => ResourceKinds::format($option->unit, $option->max_steps * $option->step_size),
            ]),
            default => __(':step steps · up to :max', [
                'step' => ResourceKinds::format($option->unit, $option->step_size),
                'max' => '+'.ResourceKinds::format($option->unit, $option->max_steps * $option->step_size),
            ]),
        };

        return [
            'option' => $option,
            'icon' => ResourceKinds::icon($option->kind),
            'summary' => $size,
            'price' => $first !== null
                ? ($toggle ? money($first->price, $base).$first->billing_cycle->suffix() : __(':amount a step', ['amount' => money($first->price, $base).$first->billing_cycle->suffix()]))
                : __('No price yet'),
            'usedBy' => $usedBy,
            'deletable' => ! $hasHistory,
            'missing' => $missing->values()->all(),
            'unsupported' => $kinds !== null && ! isset($kinds[$option->kind]),
            'stockLeft' => $option->stockLeft(),
        ];
    }

    /**
     * A line about who sets the extras on the server, or null when the module does it.
     *
     * @param  array<string, ResourceKind>|null  $kinds
     */
    private function notice(Product $product, ?array $kinds): ?string
    {
        if ($product->server_module === null) {
            return __('Built-in kinds. Changes are set by staff by hand.');
        }

        if ($kinds === []) {
            return __('This server module cannot change resources yet.');
        }

        return null;
    }

    /**
     * Whether the product still has Virtualizor resource add-on settings that were not moved to
     * options (the conversion refused them).
     */
    private function unconverted(Product $product): bool
    {
        $json = ($product->module_config ?? [])[self::OLD_ADDONS] ?? null;

        return is_string($json) && trim($json) !== '';
    }

    /**
     * How many steps a new option offers at first.
     */
    private function defaultMaxSteps(ResourceKind $kind): int
    {
        if ($kind->ladder !== null) {
            return count($kind->ladder) - 1;
        }

        if ($kind->maxExtra !== null) {
            $step = $kind->key === 'ram' ? max($kind->minStep, 1024) : $kind->minStep;

            return max(1, min(8, intdiv($kind->maxExtra, max(1, $step))));
        }

        return 8;
    }

    private function log(Product $product, string $description): void
    {
        Activity::log('product.resource_options_updated', $description, $product);
    }
}
