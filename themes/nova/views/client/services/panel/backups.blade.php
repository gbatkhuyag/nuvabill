{{--
    Backups (and Snapshots, which includes this view with $kind = 'snapshots'): the list with date,
    size and status, how many are used of the plan's limit, "create" (with an optional description),
    "restore" (erases the server's current data) and "delete", both by id. A restore that sets a new
    root password shows it once.
--}}
@php
    $kind = ($kind ?? 'backups') === 'snapshots' ? 'snapshots' : 'backups';
    $texts = $kind === 'snapshots'
        ? ['empty' => __('No snapshots yet.'), 'used' => __(':used of :limit snapshots used'), 'restore' => __('Restore this snapshot? The data on the server now is replaced and cannot be brought back.'), 'delete' => __('Delete this snapshot? This cannot be undone.')]
        : ['empty' => __('No backups yet.'), 'used' => __(':used of :limit backups used'), 'restore' => __('Restore this backup? The data on the server now is replaced and cannot be brought back.'), 'delete' => __('Delete this backup? This cannot be undone.')];
@endphp
<div class="panel-body">
    <noscript><p class="muted">{{ __('Turn on JavaScript to see this.') }}</p></noscript>
    @include('theme::client.services.panel.partials.feedback')

    <template x-if="data">
        <div class="panel-stack">
            <div class="panel-toolbar">
                <p class="muted" x-show="typeof data.limit === 'number'" x-text="@js($texts['used']).replace(':used', data.used ?? list('items').length).replace(':limit', data.limit ?? '')"></p>
                <p class="muted" x-show="typeof data.restores_limit === 'number'" x-text="@js(__('Restores used: :used of :limit')).replace(':used', data.restores_used ?? 0).replace(':limit', data.restores_limit ?? '')"></p>
            </div>

            <template x-if="can('create')">
                <form method="POST" action="{{ $section['actions']['create']['url'] ?? '' }}" class="panel-toolbar" x-on:submit.prevent="submit('create', $event)">
                    @csrf
                    <div class="field panel-inline-field">
                        <label for="sp-{{ $kind }}-description">{{ __('Description (optional)') }}</label>
                        <input id="sp-{{ $kind }}-description" name="description" class="input" maxlength="60" autocomplete="off" :aria-invalid="fieldError('create', 'description') ? 'true' : null">
                    </div>
                    <button class="btn btn-primary" type="submit" :disabled="busy !== null || waiting() || (typeof data.limit === 'number' && (data.used ?? list('items').length) >= data.limit)"><x-icon name="plus" /><span x-text="label('create')"></span></button>
                    <p class="error" x-show="fieldError('create', 'description')" x-text="fieldError('create', 'description')"></p>
                </form>
            </template>

            <p class="empty" x-show="list('items').length === 0">{{ $texts['empty'] }}</p>

            <template x-if="list('items').length > 0">
                <div class="table-wrap panel-table">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Date') }}</th>
                                <th scope="col">{{ __('Description') }}</th>
                                <th scope="col" class="end">{{ __('Size') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col" class="end"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="item in list('items')" :key="String(item.id)">
                                <tr>
                                    <td class="num" x-text="time(item.created_at)"></td>
                                    <td><bdi x-text="item.description || item.kind || '–'"></bdi></td>
                                    <td class="num end" dir="ltr" x-text="typeof item.size_bytes === 'number' ? bytes(item.size_bytes) : '–'"></td>
                                    <td><span class="pill" x-show="item.status" x-text="item.status"></span></td>
                                    <td class="end">
                                        <div class="row-actions">
                                            <button type="button" class="btn btn-sm btn-danger" x-show="can('restore')" x-on:click="submit('restore', null, { id: item.id }, @js($texts['restore']))" :disabled="busy !== null || waiting()">{{ __('Restore') }}</button>
                                            <button type="button" class="btn btn-sm btn-ghost" x-show="can('delete')" x-on:click="submit('delete', null, { id: item.id }, @js($texts['delete']))" :disabled="busy !== null || waiting()" :aria-label="@js(__('Delete')) + ' ' + time(item.created_at)"><x-icon name="trash" /></button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>

            @include('theme::client.services.panel.partials.secret')
        </div>
    </template>
</div>
