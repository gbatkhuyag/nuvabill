{{--
    Service page card "Resources": the service's extras, a waiting change, the server state of the
    last change (with Try again and Mark done), the server tools and the staff change form with a
    live quote. Data from AdminServiceResourcesComposer ($resourceCard, null to leave the card out).
    Server messages here are cleaned by ResourceChanges and are only ever shown to staff.
--}}
@if ($resourceCard)
    @php
        $card = $resourceCard;
        $currency = (string) $service->currency;
        $suffix = $service->billing_cycle->suffix();
        $latest = $card['latest'];
        $pending = $card['pending'];
        $states = [
            \App\Models\ResourceChange::SERVER_QUEUED => [__('Waiting for the server job'), 'info'],
            \App\Models\ResourceChange::SERVER_APPLYING => [__('Being set on the server'), 'info'],
            \App\Models\ResourceChange::SERVER_CHECKING => [__('Being checked on the server'), 'info'],
            \App\Models\ResourceChange::SERVER_DONE => [__('Set on the server'), 'good'],
            \App\Models\ResourceChange::SERVER_FAILED => [__('Not set on the server'), 'crit'],
            \App\Models\ResourceChange::SERVER_MANUAL => [__('Set by hand'), 'warn'],
            \App\Models\ResourceChange::SERVER_NONE => [__('Nothing to set on the server'), null],
        ];
        $modes = [
            \App\Models\ResourceChange::MODE_INVOICE => __('Waits for its invoice'),
            \App\Models\ResourceChange::MODE_RENEWAL => __('On the renewal date'),
            \App\Models\ResourceChange::MODE_NOW => __('Now'),
        ];
        $config = [
            'url' => route('admin.services.resources.quote', $service),
            'csrf' => csrf_token(),
            'cycle' => $service->billing_cycle->value,
            'currency' => $currency,
            'locale' => str_replace('_', '-', \App\Support\Locales::numberLocale()),
            'fields' => ['amounts' => (object) [], 'charge' => '1'],
            'text' => ['failed' => __('We could not work out the price. Try again.')],
        ];
        $check = is_array($card['check'] ?? null) ? $card['check'] : null;
    @endphp
    <section class="card resource-panel" id="resources" aria-labelledby="admin-resources-title"
             x-data="resourceQuote(@js($config))" x-on:resource-change="track($event.detail)">
        <div class="card-header">
            <h2 id="admin-resources-title">{{ __('Resources') }}</h2>
            @if ($card['supportsModule'])
                <x-pill tone="info">{{ __('The module sets them') }}</x-pill>
            @elseif (filled($service->product->server_module))
                <x-pill>{{ __('Set by hand') }}</x-pill>
            @endif
        </div>

        @if ($card['rows']->isEmpty())
            <p class="muted" style="margin:0">{{ __('This service has no extras.') }}</p>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Resource') }}</th>
                            <th scope="col">{{ __('Extra') }}</th>
                            <th scope="col">{{ __('Total') }}</th>
                            <th scope="col" class="end">{{ __('Renewal price') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($card['rows'] as $row)
                            @php
                                $option = $row->option;
                                $label = $option?->displayLabel() ?? \App\Billing\ResourceKinds::label($row->kind);
                                $extra = $option?->ladder !== null
                                    ? \App\Billing\ResourceKinds::format($row->unit, $row->steps, $option->ladder, $row->steps)
                                    : '+'.\App\Billing\ResourceKinds::format($row->unit, $row->units());
                                $total = $option?->included !== null && $option->ladder === null ? \App\Billing\ResourceKinds::format($row->unit, $option->included + $row->units()) : '—';
                            @endphp
                            <tr>
                                <td><bdi>{{ $label }}</bdi>@if ($option === null || ! $option->is_active) <x-pill>{{ __('No longer sold') }}</x-pill>@endif</td>
                                <td style="white-space:nowrap"><bdi>{{ $extra }}</bdi></td>
                                <td style="white-space:nowrap"><bdi>{{ $total }}</bdi></td>
                                <td class="end num" style="white-space:nowrap">{{ money($row->recurring_amount, $currency) }}{{ $suffix }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($pending)
            <div class="flash" data-tone="info">
                <div style="display:grid;gap:.45rem;min-width:0">
                    <strong>{{ __('Waiting change') }} · {{ $modes[$pending->mode] ?? $pending->mode }}</strong>
                    <ul class="resource-list" style="font-size:.86rem">
                        @foreach (app(\App\Billing\ResourceChanges::class)->describe($pending) as $line)
                            <li><bdi>{{ $line['label'] }}</bdi> <span><bdi>{{ $line['from_text'] }}</bdi> <span class="resource-arrow" aria-hidden="true">→</span><span class="sr-only">{{ __('changes to') }}</span> <b><bdi>{{ $line['to_text'] }}</bdi></b></span></li>
                        @endforeach
                    </ul>
                    <span class="faint" style="font-size:.85rem">
                        @if ($pending->invoice)
                            {{ __('Invoice') }} <a class="mono" href="{{ route('admin.invoices.show', $pending->invoice) }}">{{ $pending->invoice->displayNumber() }}</a> · <x-status :value="$pending->invoice->status" />
                        @elseif ($pending->apply_on)
                            {{ __('Changes on :date.', ['date' => $pending->apply_on->translatedFormat('d M Y')]) }}
                        @endif
                    </span>
                    <form method="POST" action="{{ route('admin.services.resources.cancel', $service) }}" data-confirm="{{ __('Stop this change?') }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-ghost" type="submit">{{ __('Stop this change') }}</button>
                    </form>
                </div>
            </div>
        @endif

        @if ($latest && $latest->server_state !== \App\Models\ResourceChange::SERVER_NONE)
            @php [$stateLabel, $stateTone] = $states[$latest->server_state] ?? [$latest->server_state, null]; @endphp
            <div class="resource-section">
                <h3 class="resource-subtitle">{{ __('Last change on the server') }}</h3>
                <dl class="dl">
                    <dt>{{ __('State') }}</dt><dd><x-pill :tone="$stateTone">{{ $stateLabel }}</x-pill></dd>
                    <dt>{{ __('Changed') }}</dt><dd>{{ $latest->applied_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd>
                    @if ($latest->server_attempts > 0)
                        <dt>{{ __('Tries') }}</dt><dd class="num">{{ $latest->server_attempts }} / {{ \App\Billing\ResourceChanges::MAX_TRIES }}</dd>
                    @endif
                    @if (filled($latest->server_message))
                        <dt>{{ __('Server said') }}</dt><dd style="overflow-wrap:anywhere">{{ $latest->server_message }}</dd>
                    @endif
                </dl>
                @if ($card['serverOpen'])
                    <div class="form-actions" style="margin:0">
                        @if (in_array($latest->server_state, [\App\Models\ResourceChange::SERVER_FAILED, \App\Models\ResourceChange::SERVER_QUEUED, \App\Models\ResourceChange::SERVER_CHECKING], true) && $card['supportsModule'])
                            <form method="POST" action="{{ route('admin.services.resources.retry', $service) }}">@csrf<button class="btn btn-sm" type="submit"><x-icon name="refresh" />{{ __('Try again') }}</button></form>
                        @endif
                        <form method="POST" action="{{ route('admin.services.resources.done', $service) }}" data-confirm="{{ __('Mark the extras as set on the server? The client gets an email.') }}">@csrf<button class="btn btn-sm" type="submit"><x-icon name="check" />{{ __('Mark done') }}</button></form>
                    </div>
                @endif
            </div>
        @endif

        @if ($card['supportsModule'])
            <div class="form-actions" style="margin:0">
                <form method="POST" action="{{ route('admin.services.resources.apply', $service) }}" data-confirm="{{ __('Set the plan plus these extras on the server now?') }}">@csrf<button class="btn btn-sm" type="submit"><x-icon name="server" />{{ __('Apply to server now') }}</button></form>
                <form method="POST" action="{{ route('admin.services.resources.check', $service) }}">@csrf<button class="btn btn-sm" type="submit"><x-icon name="activity" />{{ __('Check server') }}</button></form>
            </div>
        @endif

        @if ($check)
            <div class="resource-section">
                <h3 class="resource-subtitle">{{ $check['matches'] ? __('The server matches the plan plus the extras.') : __('The server does not match the plan plus the extras.') }}</h3>
                <div class="table-wrap">
                    <table class="table resource-check-table">
                        <thead><tr><th scope="col">{{ __('Resource') }}</th><th scope="col" class="end">{{ __('Expected') }}</th><th scope="col" class="end">{{ __('On the server') }}</th></tr></thead>
                        <tbody>
                            @foreach ((array) $check['expected'] as $kind => $expected)
                                @php $live = $check['live'][$kind] ?? null; @endphp
                                <tr>
                                    <td><bdi>{{ \App\Billing\ResourceKinds::label((string) $kind) }}</bdi></td>
                                    <td class="end num">{{ $expected }}</td>
                                    <td class="end num @if ($live !== $expected) is-wrong @endif">{{ $live ?? __('Unknown') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @foreach ((array) $check['problems'] as $problem)
                    <p class="faint" style="margin:0;font-size:.82rem">{{ $problem }}</p>
                @endforeach
            </div>
        @endif

        @if ($card['blocked'])
            <p class="muted" style="margin:0">{{ $card['blocked'] }}</p>
        @elseif ($card['sliders'] !== [])
            <details>
                <summary class="btn btn-sm" style="list-style:none;display:inline-flex">{{ __('Change resources') }}</summary>
                <form method="POST" action="{{ route('admin.services.resources.change', $service) }}" style="display:grid;gap:.9rem;margin-top:.9rem" data-confirm="{{ __('Change the extras of this service?') }}">
                    @csrf
                    <div class="resource-staff-grid">
                        @foreach ($card['sliders'] as $slider)
                            @php
                                $kind = (string) $slider['kind'];
                                $ladder = $slider['ladder'];
                                $current = (int) $slider['current'];
                                $help = $ladder !== null
                                    ? collect($ladder)->map(fn ($name, $step) => $step.': '.$name)->implode(' · ')
                                    : __('Each step adds :amount.', ['amount' => \App\Billing\ResourceKinds::format((string) $slider['unit'], (int) $slider['step'])]);
                                $currentRow = $card['rows']->firstWhere('kind', $kind);
                            @endphp
                            <fieldset style="border:0;padding:0;margin:0;display:grid;gap:.5rem;min-width:0">
                                <legend class="label"><bdi>{{ $slider['label'] }}</bdi></legend>
                                <x-input :name="'steps['.$kind.']'" :id="'staff-steps-'.$kind" type="number" min="{{ $slider['min'] }}" max="{{ $slider['max'] }}" step="1" :value="$current" required
                                    :label="__('Steps (now :count)', ['count' => $current])" :help="$help"
                                    x-on:input="$dispatch('resource-change', { kind: {{ \Illuminate\Support\Js::from($kind) }}, steps: Number($event.target.value) || 0, prices: {} })" />
                                <x-input :name="'amounts['.$kind.']'" :id="'staff-amount-'.$kind" type="number" step="0.01" min="0"
                                    :label="__('Renewal price (:currency)', ['currency' => $currency])"
                                    :placeholder="\App\Support\Money::toDecimal((int) ($currentRow?->recurring_amount ?? 0))"
                                    :help="__('Empty: worked out from the steps.')"
                                    x-on:input="fields.amounts = { ...fields.amounts, [{{ \Illuminate\Support\Js::from($kind) }}]: $event.target.value }; refresh()" />
                            </fieldset>
                        @endforeach
                    </div>
                    <div style="display:grid;gap:.35rem">
                        <label class="check"><input type="radio" name="charge" value="1" checked x-on:change="fields.charge = '1'; refresh()"> <span>{{ __('Invoice the difference (removing follows the downgrade setting)') }}</span></label>
                        <label class="check"><input type="radio" name="charge" value="0" x-on:change="fields.charge = '0'; refresh()"> <span>{{ __('Change now without charging or crediting') }}</span></label>
                    </div>
                    <x-checkbox name="sets_server" :label="__('Also set it on the server')" checked />
                    <div class="quote-box" aria-live="polite">
                        <p class="muted" style="margin:0" x-show="! quote && ! loading && ! error">{{ __('Change a number to see the price for the days left.') }}</p>
                        <p class="muted" style="margin:0" x-show="loading" x-cloak>{{ __('Working out the price…') }}</p>
                        <p class="error" role="alert" style="margin:0" x-show="error !== '' && ! loading" x-text="error" x-cloak></p>
                        <template x-if="quote && quote.changes && quote.changes.length > 0 && ! loading">
                            <div style="display:grid;gap:.4rem">
                                <template x-for="line in quote.lines" :key="line.label">
                                    <div class="summary-row" :class="line.strong ? 'is-strong' : ''"><span x-text="line.label"></span><b x-text="line.value"></b></div>
                                </template>
                                <p style="margin:0" x-text="quote.message"></p>
                            </div>
                        </template>
                    </div>
                    <div><button class="btn btn-primary btn-sm" type="submit">{{ __('Save the change') }}</button></div>
                </form>
            </details>
        @endif
    </section>
@endif
