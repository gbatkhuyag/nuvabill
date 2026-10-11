{{--
    "Customize your server" on the product page: one slider (or switch) per extra the product
    offers in this currency, from ResourceOptions::forStore(). Each posts "resources[kind]" with the
    form, so it works without JavaScript and the help under each slider prints its price per step.
    With JavaScript the sliders follow the chosen billing cycle (the form's "cycle"), hide when not
    offered with it, and the line under them adds the chosen extras up.
    Needs $resources and $currency.
--}}
@php
    $chosen = old('resources');
    $sliders = collect($resources)->map(function (array $slider) use ($chosen): array {
        if (is_array($chosen) && array_key_exists($slider['kind'], $chosen) && is_numeric($chosen[$slider['kind']])) {
            $slider['value'] = min(max((int) $chosen[$slider['kind']], (int) $slider['min']), (int) $slider['max']);
        }

        return $slider;
    });
    $optional = $sliders->every(fn (array $slider): bool => (int) $slider['min'] === 0);
    $quote = [
        'currency' => $currency,
        'locale' => str_replace('_', '-', \App\Support\Locales::numberLocale()),
        'text' => [
            'extras' => __('Extras: :amount'),
            'noExtras' => __('No extras chosen.'),
            'setup' => str_replace(':fee', ':amount', __('+ :fee setup')),
            'suffixes' => collect(\App\Enums\BillingCycle::cases())->mapWithKeys(fn ($case) => [$case->value => $case->suffix()])->all(),
        ],
    ];
    // Problems with extras that have no slider here (a hidden option, say) show under the group.
    $otherErrors = collect($errors->get('resources.*'))
        ->reject(fn (array $messages, string $key): bool => $sliders->contains('kind', \Illuminate\Support\Str::after($key, 'resources.')))
        ->flatten()
        ->merge($errors->get('resources'));
@endphp
@if ($sliders->isNotEmpty())
    <fieldset class="resource-sliders" style="border:0;padding:0;margin:0;min-width:0"
              x-data="resourceQuote(@js($quote))" x-on:resource-change="track($event.detail)">
        <legend class="label" style="margin-bottom:.5rem">{{ __('Customize your server') }}@if ($optional) <span class="muted" style="font-weight:400">{{ __('optional') }}</span>@endif</legend>
        @foreach ($sliders as $slider)
            <div>
                <x-resource-slider :option="$slider" :currency="$currency" :id="'rs-'.$slider['kind']" />
                @error('resources.'.$slider['kind'])<p class="error" role="alert" style="color:var(--nb-crit);font-size:.85rem;margin:.3rem 0 0">{{ $message }}</p>@enderror
            </div>
        @endforeach
        <p class="resource-extras" x-text="totalText()" aria-live="polite" x-cloak></p>
        @foreach ($otherErrors as $message)
            <p class="error" role="alert" style="color:var(--nb-crit);font-size:.85rem;margin:0">{{ $message }}</p>
        @endforeach
    </fieldset>
@elseif ($errors->has('resources') || $errors->has('resources.*'))
    <div class="flash" data-tone="crit" role="alert">{{ $errors->first('resources') ?: collect($errors->get('resources.*'))->flatten()->first() }}</div>
@endif
