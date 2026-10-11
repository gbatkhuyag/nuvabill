{{--
    Overview: live figures (CPU, memory, disk, bandwidth this month and network now, with sparklines of
    the last minutes), the module's notices, facts about the server, its IP addresses and its
    resources. The first paint uses $first (the module's own panel data, when it has some); then the
    tab refreshes itself while it is open (liveStats). Without JavaScript the first paint is shown once.
--}}
@php
    $resourcesUrl = $serverPanel['resources_url'] ?? null;
    $meters = ['ram' => [__('Memory'), 'memory'], 'disk' => [__('Disk'), 'hard-drive'], 'bandwidth' => [__('Bandwidth this month'), 'chart']];
    $amount = function (array $meter): string {
        $used = number_format($meter['used'], $meter['used'] < 10 ? 1 : 0);

        return $meter['total'] !== null ? $used.' / '.number_format($meter['total']).' '.$meter['unit'] : $used.' '.$meter['unit'].' / '.__('Unlimited');
    };
    $rate = function (float $bytes): string {
        $units = ['B/s', 'KB/s', 'MB/s', 'GB/s'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < 3) {
            $bytes /= 1024;
            $unit++;
        }

        return number_format($bytes, $unit === 0 ? 0 : 1).' '.$units[$unit];
    };
@endphp
<div class="panel-body">
    @include('theme::client.services.panel.partials.feedback')

    <div class="flash" data-tone="info" x-show="paused" x-cloak data-js-only>
        <span>{{ __('Live updates paused.') }}</span>
        <button type="button" class="btn btn-sm flash-action" x-on:click="resume()"><x-icon name="refresh" />{{ __('Resume') }}</button>
    </div>
    <p class="muted panel-note" x-show="data && data.live === false" x-cloak data-js-only>{{ __('This is an older copy. Live data is paused for a few minutes.') }}</p>

    <template x-if="data">
        <div class="panel-stack">
            <div class="panel-stats">
                <template x-if="typeof data.cpu === 'number'">
                    <div class="panel-stat">
                        <div class="panel-stat-head"><x-icon name="cpu" /><span>{{ __('CPU') }}</span></div>
                        <strong class="panel-stat-value" dir="ltr" x-text="value(data.cpu, '%')"></strong>
                        <div class="usage-bar" aria-hidden="true" :data-tone="meterTone(data.cpu)"><span :style="'width:' + Math.min(100, Math.max(0, data.cpu)) + '%'"></span></div>
                        <div class="panel-spark" x-effect="drawSpark($el, samples.cpu, 100)"></div>
                    </div>
                </template>
                @foreach ($meters as $key => [$label, $icon])
                    <template x-if="data.{{ $key }}">
                        <div class="panel-stat">
                            <div class="panel-stat-head"><x-icon :name="$icon" /><span>{{ $label }}</span></div>
                            <strong class="panel-stat-value" dir="ltr" x-text="meterText(data.{{ $key }})"></strong>
                            <div class="usage-bar" aria-hidden="true" x-show="meterPercent(data.{{ $key }}) !== null" :data-tone="meterTone(meterPercent(data.{{ $key }}))"><span :style="'width:' + (meterPercent(data.{{ $key }}) ?? 0) + '%'"></span></div>
                        </div>
                    </template>
                @endforeach
                <template x-if="data.network">
                    <div class="panel-stat">
                        <div class="panel-stat-head"><x-icon name="activity" /><span>{{ __('Network now') }}</span></div>
                        <div class="panel-net">
                            <span><span class="chart-swatch" data-color="0" aria-hidden="true"></span>{{ __('Incoming') }} <strong dir="ltr" x-text="rate(data.network.in)"></strong></span>
                            <span><span class="chart-swatch" data-color="1" aria-hidden="true"></span>{{ __('Outgoing') }} <strong dir="ltr" x-text="rate(data.network.out)"></strong></span>
                        </div>
                        <div class="panel-spark" x-effect="drawSpark($el, samples.in, undefined, samples.out)"></div>
                    </div>
                </template>
            </div>

            <p class="muted panel-note" x-show="data.power_note" x-text="data.power_note"></p>

            <div class="panel-columns">
                <template x-if="list('facts').length > 0">
                    <section class="panel-block" aria-label="{{ __('Server') }}">
                        <h4 class="eyebrow">{{ __('Server') }}</h4>
                        <dl class="dl panel-facts">
                            <template x-for="(fact, index) in list('facts')" :key="index">
                                <div><dt x-text="fact.label"></dt><dd><bdi x-text="fact.value"></bdi></dd></div>
                            </template>
                        </dl>
                    </section>
                </template>

                <template x-if="list('ips').length > 0">
                    <section class="panel-block" aria-label="{{ trans_choice('IP address|IP addresses', 2) }}">
                        <h4 class="eyebrow">{{ trans_choice('IP address|IP addresses', 2) }}</h4>
                        <ul class="vps-ips">
                            <template x-for="(row, index) in list('ips')" :key="index">
                                <li class="vps-ip">
                                    <span class="mono" dir="ltr" x-text="row.ip"></span>
                                    <span class="pill" x-show="Number(row.version) === 6">IPv6</span>
                                    <span class="pill" data-tone="accent" x-show="row.label || row.primary" x-text="row.label || @js(__('Main'))"></span>
                                    <button type="button" class="btn btn-sm btn-ghost" x-on:click="copy(row.ip, 'ip' + index)" :aria-label="@js(__('Copy')) + ' ' + row.ip">
                                        <span x-text="copied === 'ip' + index ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </section>
                </template>

                <template x-if="list('resources').length > 0">
                    <section class="panel-block" aria-label="{{ __('Resources') }}">
                        <h4 class="eyebrow">{{ __('Resources') }}</h4>
                        <dl class="dl panel-facts">
                            <template x-for="(row, index) in list('resources')" :key="index">
                                <div><dt x-text="row.label"></dt><dd><bdi x-text="row.value"></bdi></dd></div>
                            </template>
                        </dl>
                    </section>
                </template>
            </div>
        </div>
    </template>

    @if ($resourcesUrl)
        <div><a class="btn btn-sm" href="{{ $resourcesUrl }}"><x-icon name="sliders" />{{ __('Change resources') }}</a></div>
    @endif

    <noscript>
        @if ($first !== null)
            <div class="panel-stack">
                <div class="usage-grid">
                    @if ($first['cpu'] !== null)
                        <div class="usage">
                            <div class="usage-label"><span>{{ __('CPU') }}</span><span dir="ltr">{{ number_format($first['cpu'], 1) }}%</span></div>
                            <div class="usage-bar" aria-hidden="true"><span style="width:{{ min(100, max(0, $first['cpu'])) }}%"></span></div>
                        </div>
                    @endif
                    @foreach ($meters as $key => [$label, $icon])
                        @if ($first[$key] !== null)
                            <div class="usage">
                                <div class="usage-label"><span>{{ $label }}</span><span dir="ltr">{{ $amount($first[$key]) }}</span></div>
                                @if ($first[$key]['total'] !== null)
                                    <div class="usage-bar" aria-hidden="true"><span style="width:{{ min(100, round($first[$key]['used'] / $first[$key]['total'] * 100, 1)) }}%"></span></div>
                                @endif
                            </div>
                        @endif
                    @endforeach
                    @if ($first['network'] !== null)
                        <div class="usage">
                            <div class="usage-label"><span>{{ __('Network now') }}</span></div>
                            <div class="vps-net">
                                <span>{{ __('Incoming') }} <strong class="num" dir="ltr">{{ $rate($first['network']['in']) }}</strong></span>
                                <span>{{ __('Outgoing') }} <strong class="num" dir="ltr">{{ $rate($first['network']['out']) }}</strong></span>
                            </div>
                        </div>
                    @endif
                </div>
                @if ($first['facts'] !== [])
                    <dl class="dl">
                        @foreach ($first['facts'] as $fact)
                            <dt>{{ $fact['label'] }}</dt><dd><bdi>{{ $fact['value'] }}</bdi></dd>
                        @endforeach
                    </dl>
                @endif
                @if ($first['ips'] !== [])
                    <ul class="vps-ips">
                        @foreach ($first['ips'] as $row)
                            <li class="vps-ip"><span class="mono" dir="ltr">{{ $row['ip'] }}</span>@if ($row['version'] === 6)<span class="pill">IPv6</span>@endif</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @elseif ($firstError !== null)
            <div class="flash" data-tone="warn"><span>{{ $firstError }}</span></div>
        @else
            <p class="muted">{{ __('Turn on JavaScript to see this.') }}</p>
        @endif
    </noscript>
</div>
