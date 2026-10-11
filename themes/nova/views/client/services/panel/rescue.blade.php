{{--
    Rescue: whether the rescue system is on, why it cannot be used now (blocked_reason), "enable"
    (password when credential_mode is "set", reboot, confirm) and "disable" (reboot). A generated
    rescue password is shown once.
--}}
<div class="panel-body">
    <noscript><p class="muted">{{ __('Turn on JavaScript to see this.') }}</p></noscript>
    @include('theme::client.services.panel.partials.feedback')

    <template x-if="data">
        <div class="panel-stack">
            <p>
                <span class="pill" :data-tone="data.enabled ? 'warn' : null" x-text="data.enabled ? @js(__('Rescue mode is on')) : @js(__('Rescue mode is off'))"></span>
            </p>
            <p class="muted panel-note">{{ __('The rescue system is a small system that starts from the network instead of your disk. Use it to repair a server that does not start. Restart the server to start the rescue system.') }}</p>
            <div class="flash" data-tone="warn" x-show="data.blocked_reason"><span x-text="data.blocked_reason"></span></div>

            <template x-if="! data.enabled && can('enable') && ! data.blocked_reason">
                <form method="POST" action="{{ $section['actions']['enable']['url'] ?? '' }}" class="panel-form" x-on:submit.prevent="submit('enable', $event)">
                    @csrf
                    <template x-if="data.credential_mode === 'set'">
                        <div class="field">
                            <label for="sp-rescue-password">{{ __('Password for the rescue system') }}</label>
                            <div class="input-row">
                                <input id="sp-rescue-password" name="password" type="password" class="input" data-generate required minlength="10" maxlength="128" autocomplete="new-password" spellcheck="false" dir="ltr" :aria-invalid="fieldError('enable', 'password') ? 'true' : null">
                                <button type="button" class="btn" x-on:click="generate($event)"><x-icon name="key" />{{ __('Generate') }}</button>
                            </div>
                            <p class="error" x-show="fieldError('enable', 'password')" x-text="fieldError('enable', 'password')"></p>
                            <p class="help">{{ __('At least 10 characters, with letters and numbers.') }}</p>
                        </div>
                    </template>
                    <p class="muted panel-note" x-show="data.credential_mode === 'generate'">{{ __('A password for the rescue system is made for you and shown here once.') }}</p>
                    <label class="check" for="sp-rescue-reboot">
                        <input id="sp-rescue-reboot" type="checkbox" name="reboot" value="1" checked>
                        <span>{{ __('Restart the server now') }}</span>
                    </label>
                    <label class="check" for="sp-rescue-confirm">
                        <input id="sp-rescue-confirm" type="checkbox" name="confirm" value="1" required>
                        <span>{{ __('I understand that the server starts the rescue system instead of its own system.') }}</span>
                    </label>
                    <p class="error" x-show="fieldError('enable', 'confirm')" x-text="fieldError('enable', 'confirm')"></p>
                    <div><button class="btn btn-primary" type="submit" :disabled="busy !== null || waiting()"><x-icon name="life-buoy" /><span x-text="label('enable')"></span></button></div>
                </form>
            </template>

            <template x-if="data.enabled && can('disable')">
                <form method="POST" action="{{ $section['actions']['disable']['url'] ?? '' }}" class="panel-form" x-on:submit.prevent="submit('disable', $event)">
                    @csrf
                    <label class="check" for="sp-rescue-off-reboot">
                        <input id="sp-rescue-off-reboot" type="checkbox" name="reboot" value="1" checked>
                        <span>{{ __('Restart the server now') }}</span>
                    </label>
                    <div><button class="btn" type="submit" :disabled="busy !== null || waiting()"><x-icon name="power" /><span x-text="label('disable')"></span></button></div>
                </form>
            </template>

            @include('theme::client.services.panel.partials.secret')
        </div>
    </template>
</div>
