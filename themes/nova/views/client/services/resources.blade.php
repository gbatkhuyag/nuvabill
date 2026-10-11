{{--
    Review a change of extras. It works fully without JavaScript: the sliders are a normal GET
    form, the price below is worked out by the server, and the confirm form posts the chosen steps
    with the price the client saw ("expected"), so a price that changed meanwhile is never charged.
    Data: $service, $blocked, $pending, $sliders, $chosen, $quote (ResourceChanges::present() or null),
    $problems.
--}}
@extends('theme::layouts.app')

@section('title', __('Upgrade resources'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow"><a href="{{ route('client.services.show', $service) }}"><bdi>{{ $service->domain ?: $service->product->name }}</bdi></a></p>
            <h1 style="margin-top:.3rem">{{ __('Upgrade resources') }}</h1>
        </div>
    </div>

    @if ($pending)
        <div class="card" style="display:grid;gap:.8rem">
            <p style="margin:0">
                @if ($pending->mode === \App\Models\ResourceChange::MODE_RENEWAL)
                    {{ __('Your extras change on :date.', ['date' => $pending->apply_on->translatedFormat('d M Y')]) }}
                @elseif ($pending->invoice)
                    {{ __('Waiting for payment of invoice :number.', ['number' => $pending->invoice->displayNumber()]) }}
                @endif
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @if ($pending->invoice && $pending->mode === \App\Models\ResourceChange::MODE_INVOICE)
                    <a class="btn btn-primary btn-sm" href="{{ route('client.invoices.show', $pending->invoice) }}">{{ __('Pay now') }}</a>
                @endif
                <form method="POST" action="{{ route('client.services.resources.destroy', $service) }}" data-confirm="{{ __('Stop this change?') }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-ghost" type="submit">{{ __('Stop this change') }}</button>
                </form>
            </div>
        </div>
    @elseif ($blocked)
        <div class="flash" data-tone="warn"><span>{{ $blocked }}</span></div>
        <p><a href="{{ route('client.services.show', $service) }}">{{ __('Back to the service') }}</a></p>
    @elseif ($sliders === [])
        <div class="card empty"><strong>{{ __('Nothing to change') }}</strong>{{ __('This service has no extras you can change right now. Open a ticket if you need a change.') }}</div>
    @else
        <div class="resource-upgrade">
            <form method="GET" action="{{ route('client.services.resources', $service) }}" class="card resource-upgrade-main" aria-label="{{ __('Choose your extras') }}"
                  x-data="{ cycle: @js($service->billing_cycle->value) }">
                <p class="muted" style="margin:0">{{ __('Move a slider to add or remove extras. You pay only for the days left in this billing period.') }}</p>
                @include('theme::client.services.resources-controls', ['service' => $service, 'sliders' => $sliders])
                <div><button class="btn" type="submit">{{ __('Review the price') }}</button></div>
            </form>

            <aside class="card quote-box resource-upgrade-side" aria-labelledby="review-price-title">
                <h2 id="review-price-title" class="resource-subtitle">{{ __('Your price') }}</h2>
                @if ($problems !== [])
                    <div class="flash" data-tone="crit" role="alert"><span>{{ implode(' ', $problems) }}</span></div>
                @elseif ($quote === null || $quote['changes'] === [])
                    <p class="muted" style="margin:0">{{ __('Move a slider to see the new price.') }}</p>
                @else
                    <ul class="resource-list quote-changes">
                        @foreach ($quote['changes'] as $change)
                            <li><bdi>{{ $change['label'] }}</bdi> <span><bdi>{{ $change['from_text'] }}</bdi> → <b><bdi>{{ $change['to_text'] }}</bdi></b></span></li>
                        @endforeach
                    </ul>
                    @foreach ($quote['lines'] as $line)
                        <div class="summary-row @if (! empty($line['strong'])) is-strong @endif"><span>{{ $line['label'] }}</span><b>{{ $line['value'] }}</b></div>
                    @endforeach
                    <p style="margin:0">{{ $quote['message'] }}</p>
                    @if ($quote['note'])
                        <p class="faint" style="margin:0;font-size:.82rem">{{ $quote['note'] }}</p>
                    @endif
                    @if ($quote['confirm'])
                        <p class="resource-warning" role="note">{{ $quote['confirm'] }}</p>
                    @endif
                    <form method="POST" action="{{ route('client.services.resources.store', $service) }}" @if ($quote['confirm']) data-confirm="{{ $quote['confirm'] }}" @endif>
                        @csrf
                        @foreach ($quote['changes'] as $change)
                            <input type="hidden" name="steps[{{ $change['kind'] }}]" value="{{ $change['to'] }}">
                        @endforeach
                        <input type="hidden" name="expected" value="{{ $quote['expected'] }}">
                        <button class="btn btn-primary btn-block" type="submit">{{ $quote['button'] }}</button>
                    </form>
                @endif
                <p class="faint" style="margin:0;font-size:.8rem">{{ __('Upgrades are added once paid.') }}</p>
            </aside>
        </div>
    @endif
@endsection
