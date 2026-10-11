{{--
    "Upgrade resources" on the client's service page (ResourceChanges::panelFor(), shown after the
    server panel). It lists the service's extras, a change that waits, and what the server is
    doing with the last change, which it checks every 10 seconds while the server works on it.

    When the client may change the extras: one slider per extra, and a price box with the new price
    and what to pay today for the days left. Prices always come from the server (resourceQuote in
    resource-sliders.js asks it 250 ms after the last move). Without JavaScript the sliders post to
    the review page, which works out the same price.

    Data: $service, $resourceRows, $resourcePending, $resourceStatus, $resourceBlocked,
    $resourceSliders, $resourceEnabled.
--}}
@php
    $currency = (string) $service->currency;
    $suffix = $service->billing_cycle->suffix();
    $sliders = collect($resourceSliders);
    $pending = $resourcePending;
    $state = $resourceStatus['state'];
    $tones = ['working' => 'info', 'failed' => 'warn', 'manual' => 'info'];
    $grows = $sliders->contains(fn (array $slider): bool => (bool) $slider['can_add']);
    $noShrink = $sliders->contains(fn (array $slider): bool => (bool) $slider['can_add'] && ! $slider['can_remove'] && empty($slider['toggle']));
    $config = [
        'url' => route('client.services.resources.quote', $service),
        'csrf' => csrf_token(),
        'cycle' => $service->billing_cycle->value,
        'currency' => $currency,
        'locale' => str_replace('_', '-', \App\Support\Locales::numberLocale()),
        'initial' => $sliders->mapWithKeys(fn (array $slider): array => [$slider['kind'] => (int) $slider['current']])->all(),
        'status' => [
            'url' => route('client.services.resources', $service),
            'state' => $state,
            'text' => $resourceStatus['text'],
        ],
        'text' => ['failed' => __('We could not work out the price. Try again.')],
    ];
    $changeErrors = collect($errors->get('steps'))->merge($errors->get('expected'))
        ->merge(collect($errors->get('steps.*'))->reject(fn (array $messages, string $key): bool => $sliders->contains('kind', \Illuminate\Support\Str::after($key, 'steps.')))->flatten());
@endphp
<section class="card resource-panel" id="resources" aria-labelledby="resources-title"
         x-data="resourceQuote(@js($config))" x-on:resource-change="track($event.detail)">
    <div class="card-header">
        <h2 id="resources-title">{{ $sliders->isNotEmpty() && $grows ? __('Upgrade resources') : __('Resources') }}</h2>
        @if ($resourceRows->isNotEmpty())
            <x-pill>{{ trans_choice(':count extra|:count extras', $resourceRows->count(), ['count' => $resourceRows->count()]) }}</x-pill>
        @endif
    </div>

    <div class="flash resource-state" role="status" aria-live="polite"
         @if (isset($tones[$state])) data-tone="{{ $tones[$state] }}" @endif
         x-bind:data-tone="@js($tones)[serverState] ?? null"
         @if ($resourceStatus['text'] === '') style="display:none" @endif x-show="serverText !== ''">
        <x-icon name="clock" style="width:18px;height:18px;flex:none" x-show="serverState === 'working'" />
        <span x-text="serverText">{{ $resourceStatus['text'] }}</span>
        <button type="button" class="btn btn-sm" x-show="serverState === 'done' && polls > 0" x-cloak x-on:click="window.location.reload()">{{ __('Show my new resources') }}</button>
    </div>

    @if ($changeErrors->isNotEmpty())
        <div class="flash" data-tone="crit" role="alert">
            <span>{{ $changeErrors->implode(' ') }}</span>
        </div>
    @endif

    @if ($resourceRows->isNotEmpty())
        <div class="resource-section">
            <h3 class="resource-subtitle">{{ __('Your extras') }}</h3>
            <ul class="resource-list">
                @foreach ($resourceRows as $row)
                    <li>
                        <span><bdi>{{ $row->describe() }}</bdi>@if ($row->option === null || ! $row->option->is_active) <x-pill>{{ __('No longer sold') }}</x-pill>@endif</span>
                        <span class="num">{{ $row->recurring_amount > 0 ? money($row->recurring_amount, $currency).$suffix : __('Included') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($pending)
        <div class="flash" data-tone="info">
            <div style="display:grid;gap:.5rem;min-width:0">
                <span>
                    @if ($pending->mode === \App\Models\ResourceChange::MODE_RENEWAL)
                        {{ __('Your extras change on :date.', ['date' => $pending->apply_on->translatedFormat('d M Y')]) }}
                    @elseif ($pending->invoice)
                        {{ __('Waiting for payment of invoice :number.', ['number' => $pending->invoice->displayNumber()]) }}
                    @endif
                </span>
                <ul class="resource-list" style="font-size:.86rem">
                    @foreach (app(\App\Billing\ResourceChanges::class)->describe($pending) as $line)
                        <li><bdi>{{ $line }}</bdi></li>
                    @endforeach
                </ul>
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
        </div>
    @elseif ($resourceBlocked && $state !== 'working')
        <p class="muted" style="margin:0">{{ $resourceBlocked }}</p>
    @elseif ($sliders->isNotEmpty())
        <div class="resource-upgrade">
            <form method="GET" action="{{ route('client.services.resources', $service) }}" class="resource-upgrade-main" aria-label="{{ __('Choose your extras') }}">
                <p class="muted" style="margin:0">{{ __('Move a slider to add or remove extras. You pay only for the days left in this billing period.') }}</p>
                @include('theme::client.services.resources-controls', ['service' => $service, 'sliders' => $sliders->all()])
                {{-- Without JavaScript this opens the review page; with it the price box below does the same. --}}
                <div x-show="false"><button class="btn" type="submit">{{ __('Review the price') }}</button></div>
            </form>

            <aside class="quote-box resource-upgrade-side" aria-labelledby="resources-price-title" x-cloak>
                <h3 id="resources-price-title" class="resource-subtitle">{{ __('Your price') }}</h3>
                <div aria-live="polite" style="display:grid;gap:.45rem">
                    <div style="display:grid;gap:.45rem" x-show="! quote && ! loading && ! error">
                        <div class="summary-row"><span>{{ __('Price now') }}</span><b>{{ money($service->renewalTotal(), $currency) }}{{ $suffix }}</b></div>
                        <p class="muted" style="margin:0">{{ __('Move a slider to see the new price.') }}</p>
                    </div>
                    <p class="muted" style="margin:0" x-show="loading">{{ __('Working out the price…') }}</p>
                    <p class="error" role="alert" style="margin:0" x-show="error !== '' && ! loading" x-text="error"></p>
                    <template x-if="quote && quote.changes && quote.changes.length > 0 && ! loading">
                        <div style="display:grid;gap:.45rem">
                            <ul class="resource-list quote-changes">
                                <template x-for="change in quote.changes" :key="change.kind">
                                    <li><bdi x-text="change.label"></bdi> <span><bdi x-text="change.from_text"></bdi> → <b><bdi x-text="change.to_text"></bdi></b></span></li>
                                </template>
                            </ul>
                            <template x-for="line in quote.lines" :key="line.label">
                                <div class="summary-row" :class="line.strong ? 'is-strong' : ''"><span x-text="line.label"></span><b x-text="line.value"></b></div>
                            </template>
                            <p style="margin:0" x-text="quote.message"></p>
                            <p class="faint" style="margin:0;font-size:.82rem" x-show="quote.note" x-text="quote.note"></p>
                            <p class="resource-warning" role="note" x-show="quote.confirm" x-text="quote.confirm"></p>
                        </div>
                    </template>
                </div>
                <form method="POST" action="{{ route('client.services.resources.store', $service) }}" x-show="quote && quote.button && ! loading" x-bind:data-confirm="quote?.confirm ?? null">
                    @csrf
                    <template x-for="(count, kind) in steps()" :key="kind">
                        <input type="hidden" :name="'steps[' + kind + ']'" :value="count">
                    </template>
                    <input type="hidden" name="expected" :value="expected ?? ''">
                    <button class="btn btn-primary btn-block" type="submit" x-text="quote?.button" x-bind:disabled="loading || ! quote">{{ __('Save changes') }}</button>
                </form>
                <p class="faint" style="margin:0;font-size:.8rem">
                    {{ __('Upgrades are added once paid.') }}@if ($noShrink) {{ __('Disk can grow but not shrink.') }}@endif
                </p>
            </aside>
        </div>
    @elseif (! $resourceEnabled && $resourceRows->isNotEmpty())
        <p class="faint" style="margin:0;font-size:.85rem">{{ __('Contact us to change your extras.') }}</p>
    @endif
</section>
