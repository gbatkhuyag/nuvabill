{{--
    One chart of the Graphs tab, for the series group "group" in the Alpine scope (from chartGroups()):
    the line chart drawn by resources/js/panel-chart.js, and the same numbers as a table that opens on
    request, for screen readers and for reading exact values.
--}}
<figure class="chart">
    <figcaption class="chart-title" x-text="group.label"></figcaption>
    <div class="chart-box" x-effect="drawLine($el, group)"></div>
    <details class="chart-table" x-data="{ open: false }" x-on:toggle="open = $el.open">
        <summary>{{ __('Show as table') }}</summary>
        <template x-if="open">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Time') }}</th>
                            <template x-for="item in group.series" :key="item.key">
                                <th scope="col" class="end" x-text="item.label"></th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in chartRows(group)" :key="row.time">
                            <tr>
                                <td class="num" x-text="time(row.time)"></td>
                                <template x-for="(cell, index) in row.values" :key="index">
                                    <td class="num end" dir="ltr" x-text="value(cell, group.unit)"></td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </details>
</figure>
