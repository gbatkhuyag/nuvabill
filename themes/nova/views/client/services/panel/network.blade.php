{{--
    IPs and rDNS: the server's addresses with their reverse DNS names, a form to set the name of one of
    them ("rdns-set": ip and ptr; an empty name removes it where the provider supports that), and
    "rdns-delete" by address. IPv6 subnets are listed below.
--}}
<div class="panel-body">
    <noscript><p class="muted">{{ __('Turn on JavaScript to see this.') }}</p></noscript>
    @include('theme::client.services.panel.partials.feedback')

    <template x-if="data">
        <div class="panel-stack">
            <p class="empty" x-show="list('ips').length === 0">{{ __('No IP addresses yet.') }}</p>

            <template x-if="list('ips').length > 0">
                <div class="table-wrap panel-table">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('IP address') }}</th>
                                <th scope="col">{{ __('Type') }}</th>
                                <th scope="col" x-show="data.rdns">{{ __('Reverse DNS') }}</th>
                                <th scope="col" class="end"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, index) in list('ips')" :key="row.ip">
                                <tr>
                                    <td><span class="mono" dir="ltr" x-text="row.ip"></span></td>
                                    <td>
                                        <span x-text="Number(row.version) === 6 ? 'IPv6' : 'IPv4'"></span>
                                        <span class="pill" data-tone="accent" x-show="row.label || row.primary" x-text="row.label || @js(__('Main'))"></span>
                                    </td>
                                    <td x-show="data.rdns"><span class="mono" dir="ltr" x-text="row.rdns || '–'"></span></td>
                                    <td class="end">
                                        <div class="row-actions">
                                            <button type="button" class="btn btn-sm btn-ghost" x-on:click="copy(row.ip, 'ip' + index)" :aria-label="@js(__('Copy')) + ' ' + row.ip">
                                                <span x-text="copied === 'ip' + index ? @js(__('Copied')) : @js(__('Copy'))"></span>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-ghost btn-danger" x-show="data.rdns && row.rdns && can('rdns-delete')" x-on:click="submit('rdns-delete', null, { ip: row.ip })" :disabled="busy !== null || waiting()" :aria-label="@js(__('Remove the reverse DNS of :ip')).replace(':ip', row.ip)">{{ __('Remove') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>

            <template x-if="data.rdns && can('rdns-set') && list('ips').length > 0">
                <form method="POST" action="{{ $section['actions']['rdns-set']['url'] ?? '' }}" class="panel-form panel-block" x-on:submit.prevent="submit('rdns-set', $event)">
                    @csrf
                    <h4 class="eyebrow">{{ __('Set reverse DNS') }}</h4>
                    <div class="form-grid">
                        <div class="field">
                            <label for="sp-rdns-ip">{{ __('IP address') }}</label>
                            <select id="sp-rdns-ip" name="ip" class="select" required dir="ltr" :aria-invalid="fieldError('rdns-set', 'ip') ? 'true' : null">
                                <template x-for="row in list('ips')" :key="row.ip">
                                    <option :value="row.ip" x-text="row.ip"></option>
                                </template>
                            </select>
                            <p class="error" x-show="fieldError('rdns-set', 'ip')" x-text="fieldError('rdns-set', 'ip')"></p>
                        </div>
                        <div class="field">
                            <label for="sp-rdns-ptr">{{ __('Name') }}</label>
                            <input id="sp-rdns-ptr" name="ptr" class="input" maxlength="253" autocomplete="off" spellcheck="false" dir="ltr" placeholder="server.example.com" :aria-invalid="fieldError('rdns-set', 'ptr') ? 'true' : null" aria-describedby="sp-rdns-ptr-help">
                            <p class="error" x-show="fieldError('rdns-set', 'ptr')" x-text="fieldError('rdns-set', 'ptr')"></p>
                            <p class="help" id="sp-rdns-ptr-help">{{ __('The name the address points back to, such as server.example.com.') }}</p>
                        </div>
                    </div>
                    <div><button class="btn btn-primary" type="submit" :disabled="busy !== null || waiting()"><x-icon name="globe" /><span x-text="label('rdns-set')"></span></button></div>
                </form>
            </template>

            <template x-if="list('subnets').length > 0">
                <section class="panel-block" aria-label="{{ __('IPv6 subnets') }}">
                    <h4 class="eyebrow">{{ __('IPv6 subnets') }}</h4>
                    <ul class="vps-ips">
                        <template x-for="(subnet, index) in list('subnets')" :key="index">
                            <li class="vps-ip"><span class="mono" dir="ltr" x-text="subnet.cidr"></span></li>
                        </template>
                    </ul>
                </section>
            </template>
        </div>
    </template>
</div>
