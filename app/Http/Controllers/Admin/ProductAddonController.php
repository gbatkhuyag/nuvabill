<?php

namespace App\Http\Controllers\Admin;

use App\Billing\ExchangeRates;
use App\Billing\ResourceOptions;
use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductAddonPrice;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;
use Illuminate\View\View;

/**
 * Product add-ons: extras such as daily backups that clients tick when they order a service.
 *
 * Prices are entered per currency and billing cycle in the shared price grid
 * ("prices[CUR][cycle][price]"). The older one-currency shape ("prices[cycle][enabled]", in the
 * billing currency) is still accepted.
 */
class ProductAddonController extends Controller
{
    public function __construct(private ResourceOptions $catalog) {}

    public function index(): View
    {
        return view('admin.products.addons', [
            'addons' => ProductAddon::query()->with('prices')->orderBy('sort_order')->orderBy('name')->get(),
            'products' => Product::query()->pluck('name', 'id'),
            'currency' => setting('billing.currency'),
        ]);
    }

    public function create(): View
    {
        return $this->form(new ProductAddon(['is_visible' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $addon = DB::transaction(function () use ($request): ProductAddon {
            $addon = ProductAddon::create($this->attributes($request));
            $this->syncPrices($addon, (array) $request->input('prices', []), $this->postsGrid($request));

            return $addon;
        });

        Activity::log('addon.created', "Product add-on {$addon->name} created");

        return redirect()->route('admin.product-addons.index')->with('status', __('Add-on created.'));
    }

    public function edit(ProductAddon $productAddon): View
    {
        return $this->form($productAddon->load('prices'));
    }

    public function update(Request $request, ProductAddon $productAddon): RedirectResponse
    {
        DB::transaction(function () use ($request, $productAddon): void {
            $productAddon->update($this->attributes($request));
            $this->syncPrices($productAddon, (array) $request->input('prices', []), $this->postsGrid($request));
        });

        Activity::log('addon.updated', "Product add-on {$productAddon->name} updated");

        return redirect()->route('admin.product-addons.index')->with('status', __('Add-on saved. Clients who have it keep their current price.'));
    }

    public function destroy(ProductAddon $productAddon): RedirectResponse
    {
        $productAddon->delete();
        Activity::log('addon.deleted', "Product add-on {$productAddon->name} deleted");

        return redirect()->route('admin.product-addons.index')->with('status', __('Add-on deleted. Clients who have it keep it on their services.'));
    }

    private function form(ProductAddon $addon): View
    {
        return view('admin.products.addon-form', [
            'addon' => $addon,
            'products' => Product::query()->orderBy('name')->pluck('name', 'id')->all(),
            'cycles' => collect(BillingCycle::cases())->reject(fn (BillingCycle $cycle): bool => $cycle === BillingCycle::Free)->values()->all(),
            'currency' => setting('billing.currency'),
            'currencies' => $this->catalog->currencies(),
            'rates' => app(ExchangeRates::class)->all(),
            'priceValues' => $addon->prices
                ->groupBy('currency')
                ->map(fn (Collection $prices): array => $prices->mapWithKeys(fn (ProductAddonPrice $price): array => [$price->billing_cycle->value => ['price' => $price->price, 'setup_fee' => $price->setup_fee]])->all())
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Request $request): array
    {
        $grid = $this->postsGrid($request);
        $prices = $grid
            ? [
                'prices' => ['array', 'max:60'],
                'prices.*' => ['array', 'max:10'],
                'prices.*.*' => ['array'],
                'prices.*.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
                'prices.*.*.setup_fee' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            ]
            : [
                'prices' => ['array'],
                'prices.*.enabled' => ['boolean'],
                'prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
                'prices.*.setup_fee' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            ];

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'is_visible' => ['boolean'],
            'is_popular' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
            ...$prices,
            'icon' => ['nullable', Rule::in(['shield', 'server', 'globe', 'lock', 'mail', 'zap', 'star', 'download', 'refresh'])],
        ], [], ['prices.*.*.price' => __('price'), 'prices.*.*.setup_fee' => __('setup fee')]);

        $validator->after(function () use ($validator, $request, $grid): void {
            if ($grid) {
                $this->checkGrid($validator, (array) $request->input('prices', []));

                return;
            }

            // A cycle turned on without a price would be saved as free. A typed 0 is a free add-on.
            foreach ((array) $request->input('prices', []) as $cycle => $price) {
                $cycle = BillingCycle::tryFrom((string) $cycle);

                if ($cycle !== null && $cycle !== BillingCycle::Free && is_array($price) && ! empty($price['enabled']) && ! is_numeric($price['price'] ?? null)) {
                    $validator->errors()->add("prices.{$cycle->value}.price", __('Enter a price for :cycle.', ['cycle' => $cycle->label()]));
                }
            }
        });

        $data = $validator->validate();

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'product_ids' => array_values(array_map('intval', $data['product_ids'] ?? [])) ?: null,
            'is_visible' => $request->boolean('is_visible'),
            'is_popular' => $request->boolean('is_popular'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * Whether the form posted the price grid (currency codes as keys) rather than the older
     * one-currency shape (billing cycles as keys).
     */
    private function postsGrid(Request $request): bool
    {
        $prices = $request->input('prices');

        if (! is_array($prices) || $prices === []) {
            return false;
        }

        foreach (array_keys($prices) as $key) {
            if (! is_string($key) || preg_match('/^[A-Z]{3}$/D', $key) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Grid prices only in the currencies staff can price in, and only for cycles that renew or are
     * paid once (never Free).
     *
     * @param  array<mixed>  $prices
     */
    private function checkGrid(ValidationValidator $validator, array $prices): void
    {
        $currencies = $this->catalog->currencies();

        foreach ($prices as $currency => $cycles) {
            if (! in_array($currency, $currencies, true)) {
                $validator->errors()->add('prices', __('Prices can only be in these currencies: :list.', ['list' => implode(', ', $currencies)]));

                continue;
            }

            foreach (array_keys((array) $cycles) as $cycle) {
                $known = BillingCycle::tryFrom((string) $cycle);

                if ($known === null || $known === BillingCycle::Free) {
                    $validator->errors()->add('prices', __('Choose a billing cycle from the list.'));
                }
            }
        }
    }

    /**
     * Save the posted prices. From the grid, every posted currency and cycle is saved or, when its
     * price is empty, removed; currencies that were not posted are left as they are. From the older
     * shape, the billing currency's cycles are saved or removed as their "enabled" box says.
     *
     * @param  array<string, mixed>  $prices
     */
    private function syncPrices(ProductAddon $addon, array $prices, bool $grid): void
    {
        if ($grid) {
            foreach ($prices as $currency => $cycles) {
                foreach ((array) $cycles as $cycle => $amounts) {
                    $price = is_array($amounts) ? ($amounts['price'] ?? null) : null;

                    if ($price === null || $price === '') {
                        $addon->prices()->where('currency', $currency)->where('billing_cycle', $cycle)->delete();

                        continue;
                    }

                    $addon->prices()->updateOrCreate(
                        ['currency' => $currency, 'billing_cycle' => $cycle],
                        ['price' => Money::toMinor($price), 'setup_fee' => Money::toMinor($amounts['setup_fee'] ?? 0)],
                    );
                }
            }

            return;
        }

        $currency = (string) setting('billing.currency');

        foreach (BillingCycle::cases() as $cycle) {
            $input = $prices[$cycle->value] ?? [];

            if ($cycle === BillingCycle::Free || empty($input['enabled'])) {
                $addon->prices()->where('currency', $currency)->where('billing_cycle', $cycle)->delete();

                continue;
            }

            $addon->prices()->updateOrCreate(
                ['currency' => $currency, 'billing_cycle' => $cycle],
                ['price' => Money::toMinor($input['price'] ?? 0), 'setup_fee' => Money::toMinor($input['setup_fee'] ?? 0)],
            );
        }
    }
}
