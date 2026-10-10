{{--
    Reinstall: a new operating system for the server ("run"), or, for servers our team installs by
    hand, a reinstall request that opens a ticket ("request"). The client picks a template, sets or
    gets a new root password (credential_mode set, generate or none), may run the plan's setup script
    again, and must tick that all data is erased. A generated password is shown once.
--}}
@php
    $url = $section['actions']['run']['url'] ?? $section['actions']['request']['url'] ?? '';
@endphp
<div class="panel-body">
    <noscript><p class="muted">{{ __('Turn on JavaScript to see this.') }}</p></noscript>
    @include('theme::client.services.panel.partials.feedback')

    <template x-if="data">
        <div class="panel-stack" x-data="{ act: data.mode === 'request' ? 'request' : 'run' }">
            <template x-if="act === 'request' && data.open_request">
                <div class="flash" data-tone="info">
                    <span>
                        {{ __('You already asked for a reinstall.') }}
                        <a :href="safeUrl(data.open_request.url)" x-show="safeUrl(data.open_request.url)" x-text="@js(__('See ticket #:number')).replace(':number', data.open_request.number)"></a>
                    </span>
                </div>
            </template>

            <p class="muted" x-show="! can(act)">{{ __('This action is not available.') }}</p>

            <template x-if="can(act) && ! (act === 'request' && data.open_request)">
                <form method="POST" action="{{ $url }}" class="panel-form" x-on:submit.prevent="submit(act, $event)">
                    @csrf
                    <div class="flash" data-tone="crit"><x-icon name="alert" class="flash-icon" /><span x-text="data.warning || @js(__('A reinstall erases all files, websites and databases on the server.'))"></span></div>
                    <p class="muted panel-note" x-show="typeof data.left_this_month === 'number'" x-text="@js(__('Reinstalls left this month: :count')).replace(':count', data.left_this_month)"></p>

                    <div class="form-grid">
                        <div class="field">
                            <label for="sp-reinstall-template">{{ __('Operating system') }}</label>
                            <select id="sp-reinstall-template" name="template" class="select" required :aria-invalid="fieldError(act, 'template') ? 'true' : null" aria-describedby="sp-reinstall-template-error">
                                <option value="">{{ __('Choose…') }}</option>
                                <template x-for="group in groupBy('templates', 'group')" :key="group.name">
                                    <optgroup :label="group.name || @js(__('Other'))">
                                        <template x-for="item in group.items" :key="item.id">
                                            <option :value="item.id" x-text="item.name"></option>
                                        </template>
                                    </optgroup>
                                </template>
                            </select>
                            <p class="error" id="sp-reinstall-template-error" x-show="fieldError(act, 'template')" x-text="fieldError(act, 'template')"></p>
                        </div>
                    </div>

                    <template x-if="act === 'run' && data.credential_mode === 'set'">
                        <div class="form-grid">
                            <div class="field">
                                <label for="sp-reinstall-password">{{ __('New root password') }}</label>
                                <div class="input-row">
                                    <input id="sp-reinstall-password" name="password" type="password" class="input" data-generate required minlength="10" maxlength="128" autocomplete="new-password" spellcheck="false" dir="ltr" :aria-invalid="fieldError(act, 'password') ? 'true' : null" aria-describedby="sp-reinstall-password-help">
                                    <button type="button" class="btn" x-on:click="generate($event)"><x-icon name="key" />{{ __('Generate') }}</button>
                                </div>
                                <p class="error" x-show="fieldError(act, 'password')" x-text="fieldError(act, 'password')"></p>
                                <p class="help" id="sp-reinstall-password-help">{{ __('At least 10 characters, with letters and numbers.') }}</p>
                            </div>
                            <div class="field">
                                <label for="sp-reinstall-password-confirmation">{{ __('Type the password again') }}</label>
                                <input id="sp-reinstall-password-confirmation" name="password_confirmation" type="password" class="input" data-generate required minlength="10" maxlength="128" autocomplete="new-password" spellcheck="false" dir="ltr">
                            </div>
                        </div>
                    </template>
                    <p class="muted panel-note" x-show="act === 'run' && data.credential_mode === 'generate'">{{ __('A new root password is made for you and shown here once.') }}</p>

                    <template x-if="act === 'run' && data.recipe">
                        <label class="check" for="sp-reinstall-recipe">
                            <input id="sp-reinstall-recipe" type="checkbox" name="run_recipe" value="1">
                            <span>{{ __('Run the setup script of your plan again') }}<br><span class="help">{{ __('It installs the same apps as on your first setup.') }}</span></span>
                        </label>
                    </template>

                    <template x-if="act === 'request' && data.note">
                        <div class="field">
                            <label for="sp-reinstall-note">{{ __('Notes for our team (optional)') }}</label>
                            <textarea id="sp-reinstall-note" name="note" class="textarea" maxlength="2000" rows="3"></textarea>
                            <p class="error" x-show="fieldError(act, 'note')" x-text="fieldError(act, 'note')"></p>
                        </div>
                    </template>

                    <label class="check" for="sp-reinstall-confirm">
                        <input id="sp-reinstall-confirm" type="checkbox" name="confirm" value="1" required>
                        <span>{{ __('I understand that all data on the server is erased.') }}</span>
                    </label>
                    <p class="error" x-show="fieldError(act, 'confirm')" x-text="fieldError(act, 'confirm')"></p>

                    <div><button class="btn btn-danger" type="submit" :disabled="busy !== null || waiting()"><x-icon name="disc" /><span x-text="label(act)"></span></button></div>
                </form>
            </template>

            @include('theme::client.services.panel.partials.secret')
        </div>
    </template>
</div>
