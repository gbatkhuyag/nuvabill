{{--
    Tasks and logs: the server's recent tasks with their progress (the list refreshes every 10 seconds
    while one runs, for 10 minutes at most) and its log lines. No IP addresses are shown.
--}}
<div class="panel-body">
    <noscript><p class="muted">{{ __('Turn on JavaScript to see this.') }}</p></noscript>
    @include('theme::client.services.panel.partials.feedback')

    <template x-if="data">
        <div class="panel-stack">
            <p class="muted panel-note" x-show="data.running"><span class="vps-spinner" aria-hidden="true"></span> {{ __('A task is running. This list updates by itself.') }}</p>

            <section class="panel-block" aria-label="{{ __('Tasks') }}">
                <h4 class="eyebrow">{{ __('Tasks') }}</h4>
                <p class="empty" x-show="list('tasks').length === 0">{{ __('No tasks yet.') }}</p>
                <template x-if="list('tasks').length > 0">
                    <div class="table-wrap panel-table">
                        <table class="table">
                            <thead><tr><th scope="col">{{ __('Task') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Progress') }}</th><th scope="col">{{ __('Started') }}</th><th scope="col">{{ __('Ended') }}</th></tr></thead>
                            <tbody>
                                <template x-for="(task, index) in list('tasks')" :key="String(task.id ?? index)">
                                    <tr>
                                        <td><bdi x-text="task.action || '–'"></bdi></td>
                                        <td><span class="pill" x-text="task.status || '–'"></span></td>
                                        <td>
                                            <template x-if="typeof task.progress === 'number'">
                                                <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round(task.progress)"><span :style="'width:' + Math.min(100, Math.max(0, task.progress)) + '%'"></span></div>
                                            </template>
                                            <span x-show="typeof task.progress !== 'number'">–</span>
                                        </td>
                                        <td class="num" x-text="time(task.started_at)"></td>
                                        <td class="num" x-text="time(task.ended_at)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>
            </section>

            <section class="panel-block" aria-label="{{ __('Logs') }}" x-show="Array.isArray(data.logs)">
                <h4 class="eyebrow">{{ __('Logs') }}</h4>
                <p class="empty" x-show="list('logs').length === 0">{{ __('No log lines yet.') }}</p>
                <template x-if="list('logs').length > 0">
                    <div class="table-wrap panel-table">
                        <table class="table">
                            <thead><tr><th scope="col">{{ __('Time') }}</th><th scope="col">{{ __('Action') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
                            <tbody>
                                <template x-for="(entry, index) in list('logs')" :key="index">
                                    <tr>
                                        <td class="num" x-text="time(entry.at)"></td>
                                        <td><bdi x-text="entry.text || '–'"></bdi></td>
                                        <td><span class="pill" x-show="entry.status" x-text="entry.status"></span></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>
            </section>
        </div>
    </template>
</div>
