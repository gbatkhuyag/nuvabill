@props([
    'name' => 'prices',
    'currencies',
    'cycles',
    'values' => [],
    'base',
    'rates' => [],
    'perStep' => false,
    'id' => 'price-grid',
])
{{--
    Prices per currency and billing cycle, for resource options and product add-ons. Posts
    "{name}[CUR][cycle][price]" and "[setup_fee]" as typed amounts; an empty price means that cycle
    is not offered in that currency, and 0 means free.

    $currencies: currency codes, the billing currency first. $cycles: BillingCycle cases.
    $values: [currency][cycle] => ['price' => minor, 'setup_fee' => minor] as saved.
    $rates: ExchangeRates::all(), units of each currency for 1 of the billing currency.

    Without JavaScript every currency shows as its own table. With it (priceGrid in
    resource-option-editor.js) the currencies become tabs, with "Fill other cycles" and
    "Fill from the billing currency at today's rate" helpers.
--}}
@php
    $errorKey = fn (string $currency, string $cycle, string $field): string => "{$name}.{$currency}.{$cycle}.{$field}";
    $reposted = old('_token') !== null;
    $typed = [];

    foreach ($currencies as $currency) {
        foreach ($cycles as $cycle) {
            $saved = $values[$currency][$cycle->value] ?? null;
            $typed[$currency][$cycle->value] = [
                'price' => (string) ($reposted ? old($errorKey($currency, $cycle->value, 'price')) : ($saved !== null ? \App\Support\Money::toDecimal((int) $saved['price']) : '')),
                'setup_fee' => (string) ($reposted ? old($errorKey($currency, $cycle->value, 'setup_fee')) : ($saved !== null ? \App\Support\Money::toDecimal((int) $saved['setup_fee']) : '')),
            ];
        }
    }

    $withErrors = collect($currencies)->filter(fn (string $currency): bool => $errors->has("{$name}.{$currency}.*"))->values();
    $recurring = collect($cycles)->filter(fn ($cycle) => $cycle->isRecurring())->values();
    $fillFrom = $recurring->count() > 1 ? ($recurring->first(fn ($cycle) => $cycle === \App\Enums\BillingCycle::Monthly) ?? $recurring->first()) : null;
    $config = [
        'id' => $id,
        'base' => $base,
        'tab' => $withErrors->first() ?? $base,
        'currencies' => array_values($currencies),
        'cycles' => collect($cycles)->map(fn ($cycle) => $cycle->value)->values()->all(),
        'fillFrom' => $fillFrom?->value,
        'rates' => (object) $rates,
        'values' => $typed,
    ];
@endphp
<div {{ $attributes->class(['price-grid']) }} x-data="priceGrid(@js($config))">
    @error($name)
        <p class="error" role="alert">{{ $message }}</p>
    @enderror

    @if (count($currencies) > 1)
        <div class="price-grid-tabs" role="tablist" aria-label="{{ __('Currency') }}" x-cloak x-on:keydown="moveTab($event)">
            @foreach ($currencies as $currency)
                <button type="button" role="tab" id="{{ $id }}-tab-{{ $currency }}" aria-controls="{{ $id }}-panel-{{ $currency }}"
                        x-bind:aria-selected="tab === @js($currency) ? 'true' : 'false'" x-bind:tabindex="tab === @js($currency) ? 0 : -1"
                        x-on:click="select(@js($currency))" @if ($withErrors->contains($currency)) data-error @endif>
                    <span>{{ $currency }}</span>
                    <span class="price-grid-count" x-text="priced(@js($currency)) + '/' + {{ count($cycles) }}"></span>
                    @if ($currency === $base)<span class="sr-only">{{ __('(billing currency)') }}</span>@endif
                </button>
            @endforeach
        </div>
    @endif

    @foreach ($currencies as $currency)
        <div class="price-grid-panel" id="{{ $id }}-panel-{{ $currency }}"
             @if (count($currencies) > 1) role="tabpanel" aria-labelledby="{{ $id }}-tab-{{ $currency }}" x-show="tab === @js($currency)" @endif>
            <div class="table-wrap"><table class="table price-grid-table">
                <caption>{{ $perStep ? __('Price for each step in :currency', ['currency' => $currency]) : __('Prices in :currency', ['currency' => $currency]) }}</caption>
                <thead><tr><th scope="col">{{ __('Billing cycle') }}</th><th scope="col">{{ __('Price') }}</th><th scope="col">{{ __('Setup fee') }}</th></tr></thead>
                <tbody>
                @foreach ($cycles as $cycle)
                    @php
                        $priceKey = $errorKey($currency, $cycle->value, 'price');
                        $setupKey = $errorKey($currency, $cycle->value, 'setup_fee');
                        $field = "{$id}-{$currency}-{$cycle->value}";
                    @endphp
                    <tr>
                        <th scope="row">{{ $cycle->label() }}</th>
                        <td>
                            <div class="price-grid-money">
                                <input id="{{ $field }}-price" class="input num" type="number" step="0.01" min="0" inputmode="decimal"
                                       name="{{ $name }}[{{ $currency }}][{{ $cycle->value }}][price]" value="{{ $typed[$currency][$cycle->value]['price'] }}"
                                       x-model="values[@js($currency)][@js($cycle->value)].price" x-on:input="emit()"
                                       placeholder="{{ __('Not offered') }}" aria-label="{{ __('Price for :cycle', ['cycle' => $cycle->label()]) }} ({{ $currency }})"
                                       @error($priceKey) aria-invalid="true" aria-describedby="{{ $field }}-price-error" @enderror>
                                <span class="faint" aria-hidden="true">{{ $currency }}</span>
                            </div>
                            @error($priceKey)<p class="error" id="{{ $field }}-price-error">{{ $message }}</p>@enderror
                        </td>
                        <td>
                            <div class="price-grid-money">
                                <input id="{{ $field }}-setup" class="input num" type="number" step="0.01" min="0" inputmode="decimal"
                                       name="{{ $name }}[{{ $currency }}][{{ $cycle->value }}][setup_fee]" value="{{ $typed[$currency][$cycle->value]['setup_fee'] }}"
                                       x-model="values[@js($currency)][@js($cycle->value)].setup_fee" x-on:input="emit()"
                                       placeholder="0.00" aria-label="{{ __('Setup fee for :cycle', ['cycle' => $cycle->label()]) }} ({{ $currency }})"
                                       @error($setupKey) aria-invalid="true" aria-describedby="{{ $field }}-setup-error" @enderror>
                                <span class="faint" aria-hidden="true">{{ $currency }}</span>
                            </div>
                            @error($setupKey)<p class="error" id="{{ $field }}-setup-error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>

            @if ($fillFrom !== null || ($currency !== $base && isset($rates[$currency])))
                <div class="price-grid-tools" x-cloak>
                    @if ($fillFrom !== null)
                        <button type="button" class="btn btn-sm" x-on:click="fillCycles(@js($currency))" x-bind:disabled="! canScale(@js($currency))">
                            <x-icon name="refresh" />{{ __('Fill other cycles from :cycle', ['cycle' => $fillFrom->label()]) }}
                        </button>
                    @endif
                    @if ($currency !== $base && isset($rates[$currency]))
                        <button type="button" class="btn btn-sm" x-on:click="fillFromBase(@js($currency))" x-bind:disabled="! canConvert(@js($currency))">
                            <x-icon name="coins" />{{ __('Fill from :base at today\'s rate', ['base' => $base]) }}
                        </button>
                        <span class="faint price-grid-rate">1 {{ $base }} = {{ \Illuminate\Support\Number::format((float) $rates[$currency], maxPrecision: 6, locale: \App\Support\Locales::numberLocale()) }} {{ $currency }}</span>
                    @endif
                </div>
            @endif
        </div>
    @endforeach

    <p class="help">{{ $perStep
        ? __('Leave a price empty when that cycle is not offered. 0 means free. The setup fee is charged once for each step a client adds.')
        : __('Leave a price empty when that cycle is not offered. 0 means free.') }}</p>
</div>
