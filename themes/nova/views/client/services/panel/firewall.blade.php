{{--
    Firewall: the plan in use, a list to choose and apply another one ("apply": plan_id, 0 for none),
    the rules of the chosen plan, deleting a plan of the client's own ("delete": plan_id) and a form
    for a new plan ("create": name, default_policy and up to 30 rules).
--}}
@php
    $directions = ['in' => __('Incoming'), 'out' => __('Outgoing')];
    $protocols = ['tcp' => 'TCP', 'udp' => 'UDP', 'icmp' => 'ICMP', 'all' => __('All')];
    $policies = ['accept' => __('Allow'), 'drop' => __('Block')];
@endphp
<div class="panel-body">
    <noscript><p class="muted">{{ __('Turn on JavaScript to see this.') }}</p></noscript>
    @include('theme::client.services.panel.partials.feedback')

    <template x-if="data">
        <div class="panel-stack" x-data="{ chosen: String(data.current ?? '0') }">
            <p>
                <span class="muted">{{ __('In use:') }}</span>
                <span class="pill" data-tone="accent" x-text="(list('plans').find((plan) => String(plan.id) === String(data.current)) || {}).name || (data.current && String(data.current) !== '0' ? String(data.current) : @js(__('No firewall')))"></span>
            </p>

            <form method="POST" action="{{ $section['actions']['apply']['url'] ?? '' }}" class="panel-form" x-show="can('apply')" x-on:submit.prevent="submit('apply', $event)">
                @csrf
                <div class="panel-toolbar">
                    <div class="field panel-inline-field">
                        <label for="sp-firewall-plan">{{ __('Firewall plan') }}</label>
                        <select id="sp-firewall-plan" name="plan_id" class="select" x-model="chosen">
                            <option value="0" :selected="chosen === '0'">{{ __('No firewall') }}</option>
                            <template x-for="plan in list('plans')" :key="plan.id">
                                <option :value="String(plan.id)" :selected="String(plan.id) === chosen" x-text="plan.name"></option>
                            </template>
                        </select>
                    </div>
                    <button class="btn btn-primary" type="submit" :disabled="busy !== null || waiting()"><x-icon name="shield" /><span x-text="label('apply')"></span></button>
                </div>
                <p class="error" x-show="fieldError('apply', 'plan_id')" x-text="fieldError('apply', 'plan_id')"></p>
            </form>

            <template x-for="plan in list('plans').filter((item) => String(item.id) === chosen)" :key="plan.id">
                <section class="panel-block" :aria-label="plan.name">
                    <div class="panel-block-head">
                        <h4 class="eyebrow" x-text="plan.name"></h4>
                        <button type="button" class="btn btn-sm btn-ghost btn-danger" x-show="plan.own && can('delete')" x-on:click="submit('delete', null, { plan_id: plan.id })" :disabled="busy !== null || waiting()"><x-icon name="trash" />{{ __('Delete this plan') }}</button>
                    </div>
                    <p class="muted panel-note" x-text="@js(__('Anything else: :action')).replace(':action', plan.default_policy === 'drop' ? @js($policies['drop']) : @js($policies['accept']))"></p>
                    <div class="table-wrap panel-table" x-show="Array.isArray(plan.rules) && plan.rules.length > 0">
                        <table class="table">
                            <thead><tr><th scope="col">{{ __('Direction') }}</th><th scope="col">{{ __('Protocol') }}</th><th scope="col">{{ __('Port') }}</th><th scope="col">{{ __('Source') }}</th><th scope="col">{{ __('Action') }}</th></tr></thead>
                            <tbody>
                                <template x-for="(rule, index) in (Array.isArray(plan.rules) ? plan.rules : [])" :key="index">
                                    <tr>
                                        <td x-text="@js($directions)[rule.direction] || rule.direction"></td>
                                        <td x-text="@js($protocols)[rule.protocol] || rule.protocol"></td>
                                        <td class="mono" dir="ltr" x-text="rule.port || '–'"></td>
                                        <td class="mono" dir="ltr" x-text="rule.source || @js(__('Any'))"></td>
                                        <td><span class="pill" :data-tone="rule.action === 'drop' ? 'crit' : 'good'" x-text="@js($policies)[rule.action] || rule.action"></span></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>

            <template x-if="can('create')">
                <details class="panel-block panel-details">
                    <summary>{{ __('New firewall plan') }}</summary>
                    <form method="POST" action="{{ $section['actions']['create']['url'] ?? '' }}" class="panel-form" x-on:submit.prevent="submit('create', $event)"
                        x-data="{ rows: [{ direction: 'in', protocol: 'tcp', port: '', source: '', action: 'accept' }] }">
                        @csrf
                        <div class="form-grid">
                            <div class="field">
                                <label for="sp-firewall-name">{{ __('Name') }}</label>
                                <input id="sp-firewall-name" name="name" class="input" required maxlength="50" autocomplete="off" :aria-invalid="fieldError('create', 'name') ? 'true' : null">
                                <p class="error" x-show="fieldError('create', 'name')" x-text="fieldError('create', 'name')"></p>
                            </div>
                            <div class="field">
                                <label for="sp-firewall-policy">{{ __('Anything not in a rule') }}</label>
                                <select id="sp-firewall-policy" name="default_policy" class="select">
                                    @foreach ($policies as $value => $text)
                                        <option value="{{ $value }}">{{ $text }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <fieldset class="panel-rules">
                            <legend class="label">{{ __('Rules') }}</legend>
                            <template x-for="(row, index) in rows" :key="index">
                                <div class="panel-rule" role="group" :aria-label="@js(__('Rule :number')).replace(':number', index + 1)">
                                    <select class="select" :name="'rules[' + index + '][direction]'" x-model="row.direction" aria-label="{{ __('Direction') }}">
                                        @foreach ($directions as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                                    </select>
                                    <select class="select" :name="'rules[' + index + '][protocol]'" x-model="row.protocol" aria-label="{{ __('Protocol') }}">
                                        @foreach ($protocols as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                                    </select>
                                    <input class="input" :name="'rules[' + index + '][port]'" x-model="row.port" maxlength="11" dir="ltr" placeholder="22" aria-label="{{ __('Port') }}" :aria-invalid="fieldError('create', 'rules.' + index + '.port') ? 'true' : null">
                                    <input class="input" :name="'rules[' + index + '][source]'" x-model="row.source" maxlength="64" dir="ltr" placeholder="0.0.0.0/0" aria-label="{{ __('Source') }}" :aria-invalid="fieldError('create', 'rules.' + index + '.source') ? 'true' : null">
                                    <select class="select" :name="'rules[' + index + '][action]'" x-model="row.action" aria-label="{{ __('Action') }}">
                                        @foreach ($policies as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                                    </select>
                                    <button type="button" class="btn btn-sm btn-ghost" x-on:click="rows.splice(index, 1)" x-show="rows.length > 1" aria-label="{{ __('Remove this rule') }}"><x-icon name="x" /></button>
                                    <p class="error panel-rule-error" x-show="fieldError('create', 'rules.' + index + '.port') || fieldError('create', 'rules.' + index + '.source')" x-text="fieldError('create', 'rules.' + index + '.port') || fieldError('create', 'rules.' + index + '.source')"></p>
                                </div>
                            </template>
                            <p class="error" x-show="fieldError('create', 'rules')" x-text="fieldError('create', 'rules')"></p>
                            <div><button type="button" class="btn btn-sm" x-on:click="rows.push({ direction: 'in', protocol: 'tcp', port: '', source: '', action: 'accept' })" x-show="rows.length < 30"><x-icon name="plus" />{{ __('Add a rule') }}</button></div>
                            <p class="help">{{ __('Port: one port such as 22, or a range such as 8000:8100. Source: an IP address or a network such as 203.0.113.0/24; leave it empty for any.') }}</p>
                        </fieldset>

                        <div><button class="btn btn-primary" type="submit" :disabled="busy !== null || waiting()"><x-icon name="plus" /><span x-text="label('create')"></span></button></div>
                    </form>
                </details>
            </template>
        </div>
    </template>
</div>
