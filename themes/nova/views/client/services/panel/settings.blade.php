{{--
    Settings: the hostname ("hostname"), the root password ("root-password": typed twice when
    credential_mode is "set", or made for the client and shown once when it is "generate") and the
    console password ("vnc-password", 6 to 8 letters or numbers). data.can turns each one off.

    These forms also work without JavaScript, as normal posts that come back to this tab with a
    message; a password that is made for the client needs JavaScript to be shown.
--}}
@php
    $hostnameAction = $section['actions']['hostname'] ?? null;
    $rootAction = $section['actions']['root-password'] ?? null;
    $vncAction = $section['actions']['vnc-password'] ?? null;
@endphp
<div class="panel-body">
    @include('theme::client.services.panel.partials.feedback')

    @if ($hostnameAction)
        <form method="POST" action="{{ $hostnameAction['url'] }}" class="panel-form panel-block" x-show="can('hostname')" x-on:submit.prevent="submit('hostname', $event)">
            @csrf
            <h4 class="eyebrow">{{ __('Hostname') }}</h4>
            <div class="field">
                <label for="sp-settings-hostname">{{ __('New hostname') }}</label>
                <input id="sp-settings-hostname" name="hostname" class="input" required maxlength="253" autocomplete="off" spellcheck="false" dir="ltr" placeholder="server.example.com"
                    value="{{ old('hostname') }}" :value="data?.hostname ?? ''"
                    @error('hostname') aria-invalid="true" @enderror :aria-invalid="fieldError('hostname', 'hostname') ? 'true' : null">
                @error('hostname')<p class="error" data-server-error>{{ $message }}</p>@enderror
                <p class="error" x-show="fieldError('hostname', 'hostname')" x-text="fieldError('hostname', 'hostname')" x-cloak></p>
            </div>
            <div><button class="btn btn-primary" type="submit" :disabled="busy !== null || waiting()">{{ $hostnameAction['label'] }}</button></div>
        </form>
    @endif

    @if ($rootAction)
        <form method="POST" action="{{ $rootAction['url'] }}" class="panel-form panel-block" x-show="can('root-password') && (! data || data.credential_mode !== 'none')" x-on:submit.prevent="submit('root-password', $event)">
            @csrf
            <h4 class="eyebrow">{{ __('Root password') }}</h4>
            <div class="form-grid" x-show="! data || data.credential_mode !== 'generate'">
                <div class="field">
                    <label for="sp-settings-password">{{ __('New root password') }}</label>
                    <div class="input-row">
                        <input id="sp-settings-password" name="password" type="password" class="input" data-generate required minlength="10" maxlength="128" autocomplete="new-password" spellcheck="false" dir="ltr"
                            :disabled="data && data.credential_mode === 'generate'"
                            @error('password') aria-invalid="true" @enderror :aria-invalid="fieldError('root-password', 'password') ? 'true' : null" aria-describedby="sp-settings-password-help">
                        <button type="button" class="btn" x-on:click="generate($event)" data-js-only><x-icon name="key" />{{ __('Generate') }}</button>
                    </div>
                    @error('password')<p class="error" data-server-error>{{ $message }}</p>@enderror
                    <p class="error" x-show="fieldError('root-password', 'password')" x-text="fieldError('root-password', 'password')" x-cloak></p>
                    <p class="help" id="sp-settings-password-help">{{ __('At least 10 characters, with letters and numbers.') }}</p>
                </div>
                <div class="field">
                    <label for="sp-settings-password-confirmation">{{ __('Type the password again') }}</label>
                    <input id="sp-settings-password-confirmation" name="password_confirmation" type="password" class="input" data-generate required minlength="10" maxlength="128" autocomplete="new-password" spellcheck="false" dir="ltr"
                        :disabled="data && data.credential_mode === 'generate'">
                </div>
            </div>
            <template x-if="data && data.credential_mode === 'generate'">
                <div class="panel-stack">
                    <p class="muted panel-note">{{ __('A new root password is made for you and shown here once.') }}</p>
                    <label class="check" for="sp-settings-root-confirm">
                        <input id="sp-settings-root-confirm" type="checkbox" name="confirm" value="1" required>
                        <span>{{ __('I want a new root password. The old one stops working.') }}</span>
                    </label>
                    <p class="error" x-show="fieldError('root-password', 'confirm')" x-text="fieldError('root-password', 'confirm')"></p>
                </div>
            </template>
            <div><button class="btn btn-primary" type="submit" :disabled="busy !== null || waiting()"><x-icon name="lock" />{{ $rootAction['label'] }}</button></div>
        </form>
    @endif

    @if ($vncAction)
        <form method="POST" action="{{ $vncAction['url'] }}" class="panel-form panel-block" x-show="can('vnc-password')" x-on:submit.prevent="submit('vnc-password', $event)">
            @csrf
            <h4 class="eyebrow">{{ __('Console password') }}</h4>
            <div class="field">
                <label for="sp-settings-vnc">{{ __('New console password') }}</label>
                <div class="input-row">
                    <input id="sp-settings-vnc" name="password" type="password" class="input" data-generate required minlength="6" maxlength="8" pattern="[A-Za-z0-9]{6,8}" autocomplete="new-password" spellcheck="false" dir="ltr"
                        :aria-invalid="fieldError('vnc-password', 'password') ? 'true' : null" aria-describedby="sp-settings-vnc-help">
                    <button type="button" class="btn" x-on:click="generate($event, 8)" data-js-only><x-icon name="key" />{{ __('Generate') }}</button>
                </div>
                <p class="error" x-show="fieldError('vnc-password', 'password')" x-text="fieldError('vnc-password', 'password')" x-cloak></p>
                <p class="help" id="sp-settings-vnc-help">{{ __('6 to 8 letters or numbers. The console asks for it.') }}</p>
            </div>
            <div><button class="btn" type="submit" :disabled="busy !== null || waiting()">{{ $vncAction['label'] }}</button></div>
        </form>
    @endif

    @include('theme::client.services.panel.partials.secret')
</div>
