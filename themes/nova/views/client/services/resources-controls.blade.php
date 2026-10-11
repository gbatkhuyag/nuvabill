{{--
    The controls of the resources card and the review page: one slider (or switch) per extra the
    client may change, from ResourceOptions::forService(). Each posts "steps[kind]", so the form
    works without JavaScript; with it, each move tells the price box around it.

    An extra that is no longer sold, or has no price for this service's cycle, cannot grow. When it
    may go down it gets a "Remove this extra" checkbox instead of a slider, so nothing is removed
    unless the client asks.
    Needs $service and $sliders.
--}}
@php
    $currency = (string) $service->currency;
    $cycle = $service->billing_cycle->value;
@endphp
@foreach ($sliders as $slider)
    @php
        $kind = (string) $slider['kind'];
        $priced = ! empty($slider['prices']);
    @endphp
    <div class="resource-control">
        @if ($priced)
            <x-resource-slider :option="$slider" :currency="$currency" :cycle="$cycle" :name="'steps['.$kind.']'" :id="'svc-rs-'.$kind" />
            @if ($slider['limit_reason'] ?? null)
                <p class="faint resource-control-note">{{ $slider['limit_reason'] }}</p>
            @elseif (! $slider['can_remove'] && (int) $slider['current'] > 0 && ! ($slider['toggle'] ?? false))
                <p class="faint resource-control-note">{{ __('This extra can grow but not shrink.') }}</p>
            @endif
        @else
            @php
                $current = (int) $slider['current'];
                $valueText = match (true) {
                    $slider['ladder'] !== null => \App\Billing\ResourceKinds::format((string) $slider['unit'], $current, $slider['ladder'], $current),
                    (bool) $slider['toggle'] => __('On'),
                    default => '+'.\App\Billing\ResourceKinds::format((string) $slider['unit'], $current * (int) $slider['step']),
                };
            @endphp
            <div class="resource-remove">
                <div class="range-head">
                    <span class="range-label"><bdi>{{ $slider['label'] }}</bdi></span>
                    <span class="range-value"><bdi>{{ $valueText }}</bdi></span>
                </div>
                <p class="faint resource-control-note">{{ $slider['retired'] ? __('No longer sold. You keep it as long as you like.') : __('This extra cannot grow on your billing cycle.') }}</p>
                @if ($slider['can_remove'])
                    <label class="check" for="svc-rm-{{ $kind }}">
                        <input id="svc-rm-{{ $kind }}" type="checkbox" name="steps[{{ $kind }}]" value="0"
                               x-on:change="$dispatch('resource-change', { kind: @js($kind), steps: $event.target.checked ? 0 : {{ $current }}, prices: {}, currency: @js($currency) })">
                        <span>{{ __('Remove this extra') }}</span>
                    </label>
                @endif
            </div>
        @endif
        @error('steps.'.$kind)<p class="error" role="alert">{{ $message }}</p>@enderror
    </div>
@endforeach
