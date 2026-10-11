{{--
    SSH keys: the keys of the client's account at the provider, "add" (name and key), "delete" (id)
    and "apply" (keys[]: the ticked keys go onto this server). Some providers add them the next time
    the server starts (applies_on_boot).
--}}
<div class="panel-body">
    <noscript><p class="muted">{{ __('Turn on JavaScript to see this.') }}</p></noscript>
    @include('theme::client.services.panel.partials.feedback')

    <template x-if="data">
        <div class="panel-stack">
            <p class="empty" x-show="list('keys').length === 0">{{ __('No SSH keys yet.') }}</p>

            <template x-if="list('keys').length > 0">
                <form method="POST" action="{{ $section['actions']['apply']['url'] ?? '' }}" class="panel-form" x-on:submit.prevent="submit('apply', $event)">
                    @csrf
                    <ul class="panel-list">
                        <template x-for="item in list('keys')" :key="String(item.id)">
                            <li class="panel-list-row">
                                <label class="check" :for="'sp-key-' + item.id">
                                    <input :id="'sp-key-' + item.id" type="checkbox" name="keys[]" :value="item.id" x-show="can('apply')">
                                    <x-icon name="key" /><bdi x-text="item.name"></bdi>
                                </label>
                                <button type="button" class="btn btn-sm btn-ghost btn-danger" x-show="can('delete')" x-on:click="submit('delete', null, { id: item.id }, @js(__('Delete this SSH key from your account?')))" :disabled="busy !== null || waiting()" :aria-label="@js(__('Delete')) + ' ' + item.name"><x-icon name="trash" /></button>
                            </li>
                        </template>
                    </ul>
                    <p class="error" x-show="fieldError('apply', 'keys')" x-text="fieldError('apply', 'keys')"></p>
                    <p class="muted panel-note" x-show="data.applies_on_boot">{{ __('The keys are added the next time the server starts.') }}</p>
                    <div x-show="can('apply')"><button class="btn btn-primary" type="submit" :disabled="busy !== null || waiting()"><x-icon name="check" />{{ __('Add selected keys to this server') }}</button></div>
                </form>
            </template>

            <template x-if="can('add')">
                <form method="POST" action="{{ $section['actions']['add']['url'] ?? '' }}" class="panel-form panel-block" x-on:submit.prevent="submit('add', $event)">
                    @csrf
                    <h4 class="eyebrow">{{ __('Add an SSH key') }}</h4>
                    <div class="field">
                        <label for="sp-key-name">{{ __('Name') }}</label>
                        <input id="sp-key-name" name="name" class="input" required maxlength="50" autocomplete="off" placeholder="{{ __('My laptop') }}" :aria-invalid="fieldError('add', 'name') ? 'true' : null">
                        <p class="error" x-show="fieldError('add', 'name')" x-text="fieldError('add', 'name')"></p>
                    </div>
                    <div class="field">
                        <label for="sp-key-key">{{ __('Public key') }}</label>
                        <textarea id="sp-key-key" name="key" class="textarea mono" required maxlength="16384" rows="4" spellcheck="false" autocomplete="off" dir="ltr" placeholder="ssh-ed25519 AAAA…" :aria-invalid="fieldError('add', 'key') ? 'true' : null" aria-describedby="sp-key-help"></textarea>
                        <p class="error" x-show="fieldError('add', 'key')" x-text="fieldError('add', 'key')"></p>
                        <p class="help" id="sp-key-help">{{ __('Paste the public key, the file that ends in .pub. Never paste your private key.') }}</p>
                    </div>
                    <div><button class="btn" type="submit" :disabled="busy !== null || waiting()"><x-icon name="plus" /><span x-text="label('add')"></span></button></div>
                </form>
            </template>
        </div>
    </template>
</div>
