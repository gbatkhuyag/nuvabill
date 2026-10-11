{{--
    Graphs: a period picker (months as a list, ranges such as 24 hours or 7 days as buttons), one line
    chart per group of series (CPU, memory, network in and out, disk read and write) and daily traffic
    bars. Each chart can be read as a table. The period goes back to the module as data.period.param
    (else "month" or "range").
--}}
<div class="panel-body">
    <noscript><p class="muted">{{ __('Turn on JavaScript to see this.') }}</p></noscript>
    @include('theme::client.services.panel.partials.feedback')

    <template x-if="data">
        <div class="panel-stack">
            <template x-if="data.period && Array.isArray(data.period.options) && data.period.options.length > 0">
                <div class="panel-toolbar">
                    <template x-if="data.period.kind === 'month'">
                        <div class="field panel-inline-field">
                            <label for="sp-graphs-period">{{ __('Month') }}</label>
                            <select id="sp-graphs-period" class="select" x-on:change="pickPeriod($event.target.value)" :disabled="loading">
                                <template x-for="option in data.period.options" :key="option.value">
                                    <option :value="option.value" :selected="String(option.value) === String(data.period.value)" x-text="option.label"></option>
                                </template>
                            </select>
                        </div>
                    </template>
                    <template x-if="data.period.kind !== 'month'">
                        <div class="segmented" role="group" aria-label="{{ __('Period') }}">
                            <template x-for="option in data.period.options" :key="option.value">
                                <button type="button" class="btn btn-sm" :aria-pressed="String(option.value) === String(data.period.value) ? 'true' : 'false'" x-on:click="pickPeriod(option.value)" :disabled="loading" x-text="option.label"></button>
                            </template>
                        </div>
                    </template>
                    <span class="vps-spinner" x-show="loading" aria-hidden="true"></span>
                </div>
            </template>

            <p class="empty" x-show="! hasChartData()">{{ __('No data for this period yet.') }}</p>

            <div class="chart-list" x-show="hasChartData()">
                <template x-for="group in chartGroups()" :key="group.key">
                    @include('theme::client.services.panel.partials.chart')
                </template>

                <template x-if="data.bars && Array.isArray(data.bars.points) && data.bars.points.length > 0">
                    <figure class="chart">
                        <figcaption class="chart-title" x-text="data.bars.label || @js(__('Traffic per day'))"></figcaption>
                        <div class="chart-box" x-effect="drawBars($el, data.bars, [@js(__('Incoming')), @js(__('Outgoing'))])"></div>
                        <details class="chart-table" x-data="{ open: false }" x-on:toggle="open = $el.open">
                            <summary>{{ __('Show as table') }}</summary>
                            <template x-if="open">
                                <div class="table-wrap">
                                    <table class="table">
                                        <thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col" class="end">{{ __('Incoming') }}</th><th scope="col" class="end">{{ __('Outgoing') }}</th></tr></thead>
                                        <tbody>
                                            <template x-for="row in data.bars.points" :key="row[0]">
                                                <tr><td class="num" x-text="time(row[0])"></td><td class="num end" dir="ltr" x-text="value(row[1], data.bars.unit)"></td><td class="num end" dir="ltr" x-text="value(row[2], data.bars.unit)"></td></tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </template>
                        </details>
                    </figure>
                </template>
            </div>
        </div>
    </template>
</div>
