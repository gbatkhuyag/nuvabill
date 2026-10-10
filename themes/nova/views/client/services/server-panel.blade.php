{{--
    The client server panel ("Manage your server") of modules with panel sections: a header with the
    server state, the power buttons, Console and the sign-in button, then one tab per section.

    $serverPanel comes from Provisioner::serverPanel(): view, data (the module's own panel data for
    the first paint, or []), state, login, console, power, sections, status_url and resources_url.

    Each section view renders inside an element with x-data="panelSection(…)" (the overview:
    liveStats(…)), so it can use that component's state and methods (resources/js/server-panel.js).
    A standard section uses theme::client.services.panel.{key}; a module's own section uses its
    ext-{slug}:: view. Section views get $service, $serverPanel, $section (key, label, icon, view,
    read_url, poll, actions) and $first (the overview's first-paint data in the standard shape, or
    null). Remote data is shown with x-text or {{ }} only, never as HTML.

    Without JavaScript the tabs are shown one under the other: the overview, power and settings forms
    post as normal forms; the other tabs say "Turn on JavaScript to see this."
--}}
@php
    $sp = $serverPanel;
    $sections = array_values(array_filter(
        (array) ($sp['sections'] ?? []),
        fn (mixed $section): bool => is_array($section) && is_string($section['key'] ?? null) && is_string($section['view'] ?? null) && view()->exists($section['view']),
    ));
    $keys = array_column($sections, 'key');
    $legacy = is_array($sp['data'] ?? null) ? $sp['data'] : [];
    $stateLabels = ['running' => __('Running'), 'stopped' => __('Stopped'), 'suspended' => __('Suspended'), 'unknown' => __('Unknown')];
    $tones = ['running' => 'good', 'stopped' => 'crit', 'suspended' => 'warn'];
    $state = array_key_exists((string) ($sp['state'] ?? ''), $stateLabels) ? $sp['state'] : 'unknown';

    // The first paint of the overview, from the module's own panel data, in the standard shape.
    $meter = function (mixed $value): ?array {
        if (! is_array($value) || ! is_numeric($value['used'] ?? null)) {
            return null;
        }

        $unit = is_string($value['unit'] ?? null) && preg_match('/^[A-Za-z\/]{1,5}$/', $value['unit']) === 1 ? $value['unit'] : 'GB';

        return ['used' => (float) $value['used'], 'total' => is_numeric($value['total'] ?? null) && (float) $value['total'] > 0 ? (float) $value['total'] : null, 'unit' => $unit];
    };
    $hostname = is_string($legacy['hostname'] ?? null) && $legacy['hostname'] !== '' ? $legacy['hostname'] : null;
    $os = is_string($legacy['os'] ?? null) && $legacy['os'] !== '' ? $legacy['os'] : null;
    $firstError = is_string($legacy['error'] ?? null) && $legacy['error'] !== '' ? $legacy['error'] : null;
    $first = null;

    if ($legacy !== [] && $firstError === null) {
        $ips = array_values(array_filter((array) ($legacy['ips'] ?? []), fn (mixed $ip): bool => is_string($ip) && $ip !== ''));
        $first = [
            'state' => $state,
            'cpu' => is_numeric($legacy['cpu'] ?? null) ? round((float) $legacy['cpu'], 2) : null,
            'ram' => $meter($legacy['ram'] ?? null),
            'disk' => $meter($legacy['disk'] ?? null),
            'bandwidth' => $meter($legacy['bandwidth'] ?? null),
            'network' => is_array($legacy['network'] ?? null) ? ['in' => (float) ($legacy['network']['in'] ?? 0), 'out' => (float) ($legacy['network']['out'] ?? 0)] : null,
            'facts' => array_values(array_filter([
                $hostname !== null ? ['label' => __('Hostname'), 'value' => $hostname] : null,
                $os !== null ? ['label' => __('Operating system'), 'value' => $os] : null,
            ])),
            'ips' => array_map(fn (string $ip, int $index): array => ['ip' => $ip, 'version' => str_contains($ip, ':') ? 6 : 4, 'primary' => $index === 0], $ips, array_keys($ips)),
            'resources' => [],
            'notices' => [],
            'power_note' => null,
            'live' => true,
        ];
    }

    // The power bar (resources/js/vps-panel.js): the same buttons and status checks as the older panel.
    $power = array_values(array_filter((array) ($sp['power'] ?? []), fn (mixed $row): bool => is_array($row) && is_string($row['key'] ?? null) && is_string($row['url'] ?? null)));
    $pendingLabels = [
        'start' => __('Starting…'),
        'stop' => __('Shutting down…'),
        'restart' => __('Restarting…'),
        'poweroff' => __('Powering off…'),
        'reset' => __('Restarting…'),
        'power-cycle' => __('Restarting…'),
    ];
    $shows = fn (string $action): bool => match ($state) {
        'unknown' => true,
        'suspended' => false,
        default => in_array($action, ['start', 'wol'], true) ? $state === 'stopped' : (array_key_exists($action, $pendingLabels) ? $state === 'running' : true),
    };
    $powerIcon = fn (string $action): string => match ($action) {
        'restart', 'reset', 'power-cycle' => 'refresh',
        'wol' => 'zap',
        default => 'power',
    };
    $powerConfig = [
        'state' => $state,
        'pending' => null,
        'statusUrl' => $sp['status_url'] ?? route('client.services.panel-status', $service),
        'labels' => $stateLabels,
        'pendingLabels' => $pendingLabels,
        'messages' => [
            'failed' => __('That did not work. Reload the page and try again.'),
            'slow' => __('The server has not changed its state yet. Check again in a minute.'),
            'stillRunning' => __('The server is still running. If it does not shut down, use Power off.'),
            'reached' => [
                'running' => __('The server is running.'),
                'stopped' => __('The server is stopped.'),
                'suspended' => __('The server is suspended.'),
                'unknown' => __('The server did not report its state. Try again in a minute.'),
            ],
        ],
    ];
    $hasForced = collect($power)->contains(fn (array $row): bool => (bool) ($row['destructive'] ?? false));

    // Texts every section's script needs.
    $locale = str_replace('_', '-', app()->getLocale());
    $messages = [
        'failed' => __('That did not work. Reload the page and try again.'),
        'session' => __('Your session ended. Reload the page.'),
        'done' => __('Done.'),
        'sure' => __('Are you sure? This cannot be undone.'),
        'unlimited' => __('Unlimited'),
        'empty' => __('No data for this period yet.'),
        'summary' => __(':label: lowest :min, highest :max, average :avg'),
    ];
@endphp

<section class="card server-panel" aria-labelledby="server-panel-title" x-data="serverPanel(@js(['keys' => $keys]))">
    <noscript><style>.server-panel .tabs,.server-panel [data-js-only]{display:none!important}.server-panel .tab-panel{display:grid!important}.server-panel .nojs-only{display:block}</style></noscript>

    <div class="server-panel-head" x-data="vpsPanel(@js($powerConfig))" x-on:server-panel-state.window="pending || busy || (state = $event.detail.state)">
        <div class="server-panel-top">
            <div class="server-panel-name">
                <h2 id="server-panel-title">{{ __('Manage your server') }}</h2>
                <p class="muted server-panel-meta">
                    <span class="mono" dir="ltr">{{ $hostname ?? ($service->domain ?: $service->product->name) }}</span>
                    @if ($os)<span><bdi>{{ $os }}</bdi></span>@endif
                </p>
            </div>
            <div class="server-panel-side">
                <span class="pill" @if (isset($tones[$state])) data-tone="{{ $tones[$state] }}" @endif :data-tone="tone" x-text="label">{{ $stateLabels[$state] }}</span>
                @if (! empty($sp['console']['url']))
                    <form method="POST" action="{{ $sp['console']['url'] }}" target="_blank">
                        @csrf
                        <button class="btn" type="submit"><x-icon name="terminal" />{{ __('Console') }}<span class="sr-only"> {{ __('(opens in a new tab)') }}</span></button>
                    </form>
                @endif
                @if (! empty($sp['login']['url']))
                    <form method="POST" action="{{ $sp['login']['url'] }}" target="_blank" rel="noopener noreferrer">
                        @csrf
                        <button class="btn btn-primary" type="submit"><x-icon name="external" />{{ $sp['login']['label'] }}<span class="sr-only"> {{ __('(opens in a new tab)') }}</span></button>
                    </form>
                @endif
            </div>
        </div>

        @if ($power !== [])
            <div class="vps-power">
                <form method="POST" action="{{ $power[0]['url'] }}" x-on:submit="power($event)" x-show="state !== 'suspended'" @if ($state === 'suspended') style="display: none" @endif>
                    @csrf
                    <div class="vps-power-group" role="group" aria-label="{{ __('Power') }}">
                        @foreach ($power as $button)
                            @php $destructive = (bool) ($button['destructive'] ?? false); @endphp
                            <button
                                type="submit"
                                formaction="{{ $button['url'] }}"
                                data-action="{{ $button['key'] }}"
                                @if (filled($button['confirm'] ?? null)) data-confirm="{{ $button['confirm'] }}" @endif
                                @if ($destructive) data-destructive @endif
                                class="btn btn-sm @if ($button['key'] === 'start') btn-primary @elseif ($destructive) btn-danger @endif"
                                x-show="shows(@js($button['key']))"
                                :disabled="locked(@js($button['key']))"
                                @unless ($shows($button['key'])) style="display: none" @endunless
                            ><x-icon :name="$powerIcon($button['key'])" />{{ $button['label'] }}</button>
                        @endforeach
                        <a class="btn btn-sm btn-ghost" href="{{ route('client.services.show', $service) }}" x-show="state === 'unknown' && ! pending" x-on:click.prevent="refresh()" :aria-disabled="busy ? 'true' : 'false'" @unless ($state === 'unknown') style="display: none" @endunless><x-icon name="refresh" />{{ __('Refresh status') }}</a>
                    </div>
                    @if ($hasForced)
                        {{-- Without JavaScript nobody is asked "Are you sure?", so a forced action needs this box. --}}
                        <noscript>
                            <label class="check server-panel-confirm"><input type="checkbox" name="confirm" value="1"> <span>{{ __('Yes, I want a forced action. Unsaved data can be lost.') }}</span></label>
                        </noscript>
                    @endif
                </form>
                <p class="vps-message" role="status" aria-live="polite"><span class="vps-spinner" x-show="busy" style="display: none" aria-hidden="true"></span><span x-text="message"></span></p>
                <p class="muted vps-note" x-show="state === 'suspended'" @unless ($state === 'suspended') style="display: none" @endunless>{{ __('This server is suspended. Contact support to switch it back on.') }}</p>
            </div>
        @endif
    </div>

    @if ($sections !== [])
        <div class="tabs server-panel-tabs" role="tablist" aria-label="{{ __('Server panel sections') }}">
            @foreach ($sections as $section)
                <button
                    type="button"
                    class="tab"
                    role="tab"
                    id="tab-{{ $section['key'] }}"
                    aria-controls="panel-{{ $section['key'] }}"
                    aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                    tabindex="{{ $loop->first ? '0' : '-1' }}"
                    :aria-selected="active === @js($section['key']) ? 'true' : 'false'"
                    :tabindex="active === @js($section['key']) ? 0 : -1"
                    x-on:click="select(@js($section['key']))"
                    x-on:keydown="onKey($event)"
                ><x-icon :name="(string) ($section['icon'] ?? 'server')" /><span>{{ $section['label'] }}</span></button>
            @endforeach
        </div>

        @foreach ($sections as $section)
            @php
                $config = [
                    'key' => $section['key'],
                    'readUrl' => $section['read_url'],
                    'actions' => (array) ($section['actions'] ?? []),
                    'initial' => $section['key'] === 'overview' ? $first : null,
                    'poll' => $section['poll'] ?? null,
                    'follow' => $section['key'] === 'activity',
                    'locale' => $locale,
                    'messages' => $messages,
                ];
            @endphp
            <div
                class="tab-panel server-panel-section"
                role="tabpanel"
                id="panel-{{ $section['key'] }}"
                aria-labelledby="tab-{{ $section['key'] }}"
                tabindex="0"
                data-section="{{ $section['key'] }}"
                @unless ($loop->first) style="display: none" @endunless
                x-show="active === @js($section['key'])"
                x-data="{{ $section['key'] === 'overview' ? 'liveStats' : 'panelSection' }}(@js($config))"
                x-init="$watch('active', (key) => key === @js($section['key']) ? open() : close()); if (active === @js($section['key'])) open()"
            >
                <h3 class="nojs-only server-panel-section-title">{{ $section['label'] }}</h3>
                @include($section['view'], ['service' => $service, 'serverPanel' => $sp, 'section' => $section, 'first' => $first, 'firstError' => $firstError])
            </div>
        @endforeach
    @endif
</section>
