{{--
    ISO and boot: the mounted ISO image, "mount" (iso; empty ejects it) and the boot order ("boot":
    order disk or cdrom, when the server allows it). Changes need a restart (restart_needed).
--}}
<div class="panel-body">
    <noscript><p class="muted">{{ __('Turn on JavaScript to see this.') }}</p></noscript>
    @include('theme::client.services.panel.partials.feedback')

    <template x-if="data">
        <div class="panel-stack">
            <p>
                <span class="muted">{{ __('Mounted ISO:') }}</span>
                <bdi class="pill" :data-tone="data.mounted ? 'info' : null" x-text="data.mounted ? ((list('isos').find((iso) => String(iso.id) === String(data.mounted)) || {}).name || data.mounted) : @js(__('None'))"></bdi>
            </p>
            <div class="flash" data-tone="warn" x-show="data.restart_needed"><span>{{ __('Restart the server for this to work.') }}</span></div>

            <template x-if="can('mount')">
                <form method="POST" action="{{ $section['actions']['mount']['url'] ?? '' }}" class="panel-toolbar" x-on:submit.prevent="submit('mount', $event)">
                    @csrf
                    <div class="field panel-inline-field">
                        <label for="sp-media-iso">{{ __('ISO image') }}</label>
                        <select id="sp-media-iso" name="iso" class="select" :aria-invalid="fieldError('mount', 'iso') ? 'true' : null">
                            <template x-for="iso in list('isos')" :key="String(iso.id)">
                                <option :value="iso.id" :selected="String(iso.id) === String(data.mounted)" x-text="iso.name"></option>
                            </template>
                        </select>
                    </div>
                    <button class="btn btn-primary" type="submit" :disabled="busy !== null || waiting() || list('isos').length === 0"><x-icon name="disc" />{{ __('Mount') }}</button>
                    <button class="btn" type="button" x-show="data.mounted" x-on:click="submit('mount', null, { iso: '' })" :disabled="busy !== null || waiting()">{{ __('Eject') }}</button>
                    <p class="error" x-show="fieldError('mount', 'iso')" x-text="fieldError('mount', 'iso')"></p>
                </form>
            </template>

            <template x-if="can('boot') && (data.boot === 'disk' || data.boot === 'cdrom')">
                <form method="POST" action="{{ $section['actions']['boot']['url'] ?? '' }}" class="panel-form" x-on:submit.prevent="submit('boot', $event)">
                    @csrf
                    <fieldset class="panel-choices">
                        <legend class="label">{{ __('Start from') }}</legend>
                        <label class="check"><input type="radio" name="order" value="disk" :checked="data.boot === 'disk'"> <span>{{ __('Disk first') }}</span></label>
                        <label class="check"><input type="radio" name="order" value="cdrom" :checked="data.boot === 'cdrom'"> <span>{{ __('CD first (the ISO image)') }}</span></label>
                    </fieldset>
                    <div><button class="btn" type="submit" :disabled="busy !== null || waiting()"><span x-text="label('boot')"></span></button></div>
                </form>
            </template>
        </div>
    </template>
</div>
