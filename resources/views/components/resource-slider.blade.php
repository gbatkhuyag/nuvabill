@props([
    'option',
    'currency',
    'name' => null,
    'cycle' => null,
    'id' => null,
    'live' => false,
])
{{--
    One resource slider (or an on/off switch for a one-step option), as clients see it on the
    store page, their service page and the admin previews. $option is one entry of
    ResourceOptions::forStore() or forService().

    It works without JavaScript: the native range (or checkbox) posts "resources[kind]" and the help
    prints the price per step for every cycle. With JavaScript (resourceSlider in resource-sliders.js)
    it follows the surrounding form's "cycle", shows the price of the chosen steps, hides itself when
    the option is not offered with that cycle, and reports each change with a "resource-change"
    event. Pass :name="false" for a preview that must not post; "live" lets the admin editor redraw it.
--}}
@php
    $kind = (string) $option['kind'];
    $id ??= 'rs-'.$kind;
    $name = $name === false ? null : ($name ?? 'resources['.$kind.']');
    $unit = (string) ($option['unit'] ?? '');
    $step = max(1, (int) ($option['step'] ?? 1));
    $ladder = $option['ladder'] ?? null;
    $toggle = (bool) ($option['toggle'] ?? false);
    $included = $option['included'] ?? null;
    $value = (int) ($option['value'] ?? 0);
    $min = (int) ($option['min'] ?? 0);
    $max = max($min, (int) ($option['max'] ?? 0));
    $prices = (array) ($option['prices'] ?? []);
    $suffixes = collect(\App\Enums\BillingCycle::cases())->mapWithKeys(fn ($case) => [$case->value => $case->suffix()])->all();
    $format = fn (int $units): string => \App\Billing\ResourceKinds::format($unit, $units, $ladder, $ladder !== null ? $units : null);
    $priceList = collect($prices)->map(fn ($price, $priceCycle) => money((int) $price['step'], $currency).($suffixes[$priceCycle] ?? ''))->implode(' · ');
    $help = collect([
        $ladder === null && ! $toggle ? __('Each step adds :amount.', ['amount' => $format($step)]) : null,
        $included !== null && $ladder === null ? __('The plan includes :amount.', ['amount' => $format((int) $included)]) : null,
        $priceList !== '' ? ($toggle ? __('Price: :prices.', ['prices' => $priceList]) : __('Price per step: :prices.', ['prices' => $priceList])) : null,
        $option['note'] ?? null,
    ])->filter()->implode(' ');
    $valueText = match (true) {
        $ladder !== null => $format($value),
        $toggle => $value > 0 ? __('On') : __('Off'),
        default => $value > 0 ? '+'.$format($value * $step) : __('No extra'),
    };
    $config = [
        'currency' => $currency,
        'locale' => str_replace('_', '-', \App\Support\Locales::numberLocale()),
        'cycle' => $cycle,
        'live' => (bool) $live,
        'text' => [
            'none' => __('No extra'),
            'on' => __('On'),
            'off' => __('Off'),
            'eachStep' => __('Each step adds :amount.'),
            'included' => __('The plan includes :amount.'),
            'stepPrices' => __('Price per step: :prices.'),
            'switchPrices' => __('Price: :prices.'),
            'setup' => str_replace(':fee', ':amount', __('+ :fee setup')),
            'ariaExtra' => __(':amount extra'),
            'ariaTotal' => __(':amount in total'),
            'ariaPrice' => __(':amount (:cycle)'),
            'onlyLeft' => __('Only :count left'),
            'soldOut' => __('Sold out right now.'),
            'suffixes' => $suffixes,
            'cycles' => collect(\App\Enums\BillingCycle::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all(),
        ],
    ];
    $switchId = $live ? $id.'-switch' : $id;
@endphp
<div {{ $attributes->class(['range-field']) }}
     x-data="resourceSlider(@js($option), @js($config))"
     @if ($live) x-on:resource-preview.window="setOption($event.detail)" x-bind:data-off="offered ? null : ''" @else x-show="offered" @endif>
    @if ($toggle || $live)
        {{-- The admin preview draws both forms; the option decides which one shows. --}}
        <div class="range-switch" @if ($live) x-show="option.toggle" @unless ($toggle) x-cloak @endunless @endif>
            @if ($name !== null)
                <input type="hidden" name="{{ $name }}" value="0" x-bind:disabled="! offered">
            @endif
            <label class="switch" for="{{ $switchId }}">
                <input id="{{ $switchId }}" class="switch-input" type="checkbox" role="switch" value="1" @if ($name !== null) name="{{ $name }}" @endif
                       @checked($value > 0) @disabled($max < 1)
                       x-bind:checked="value > 0" x-bind:disabled="! offered || option.max < 1"
                       x-on:change="toggleTo($event.target.checked); $event.target.checked = value > 0" aria-describedby="{{ $id }}-help">
                <span class="switch-track" aria-hidden="true"></span>
                <span class="switch-text">
                    <span class="range-label"><bdi x-text="option.label">{{ $option['label'] }}</bdi> <span class="muted" x-show="option.unit !== ''" @if ($unit === '') x-cloak @endif><bdi x-text="'+' + format(option.step)">+{{ $format($step) }}</bdi></span></span>
                    <span class="range-price" x-text="stepPriceText()"></span>
                </span>
            </label>
        </div>
    @endif
    @if (! $toggle || $live)
        <div class="range-control" @if ($live) x-show="! option.toggle" @if ($toggle) x-cloak @endif @endif>
            <div class="range-head">
                <label class="range-label" for="{{ $id }}"><bdi x-text="option.label">{{ $option['label'] }}</bdi></label>
                <output class="range-value" for="{{ $id }}" aria-hidden="true"><bdi x-text="valueText()">{{ $valueText }}</bdi></output>
            </div>
            <input id="{{ $id }}" class="range" type="range" step="1" @if ($name !== null) name="{{ $name }}" @endif
                   min="{{ $min }}" max="{{ $max }}" value="{{ min(max($value, $min), $max) }}" @disabled($max <= $min)
                   x-bind:min="option.min" x-bind:max="option.max" x-bind:value="value" x-bind:disabled="locked"
                   x-bind:aria-valuetext="ariaText()" x-on:input="setValue($event.target.value)"
                   aria-describedby="{{ $id }}-help">
            <div class="range-scale" aria-hidden="true">
                <span x-text="scaleMin()">{{ $ladder !== null ? $format($min) : ($min > 0 ? '+'.$format($min * $step) : '0') }}</span>
                <span x-text="scaleMax()">{{ $ladder !== null ? $format($max) : '+'.$format($max * $step) }}</span>
            </div>
        </div>
    @endif
    @if (filled($option['description'] ?? null) || $live)
        <p class="range-desc" x-show="option.description" x-text="option.description">{{ $option['description'] ?? '' }}</p>
    @endif
    <p class="help" id="{{ $id }}-help" x-text="helpText()">{{ $help }}</p>
    <p class="range-price" x-show="! option.toggle && priceText() !== ''" x-text="priceText()" x-cloak></p>
    <p class="range-stock" x-show="stockText() !== ''" x-text="stockText()" x-cloak></p>
    @if ($live)
        <p class="range-off" x-show="! offered" x-cloak>{{ __('Not offered with this billing cycle.') }}</p>
    @endif
</div>
