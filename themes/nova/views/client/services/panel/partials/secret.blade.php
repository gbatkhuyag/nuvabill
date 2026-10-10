{{--
    A secret from a "reveal" action of the section around it (panelSection), such as a new root
    password: shown once, kept in the browser's memory only, and closed after a minute or with Hide.
    It is never put in the page address, a cookie or the session.
--}}
<template x-if="secret">
    <div class="secret-box" role="group" aria-label="{{ __('Shown only once') }}" data-js-only>
        <div class="secret-box-head">
            <x-icon name="lock" />
            <strong>{{ __('Save this now. It is shown only once.') }}</strong>
            <span class="muted" x-text="@js(__('It closes in :seconds s.')).replace(':seconds', secretLeft)"></span>
        </div>
        <dl class="secret-list">
            <template x-for="(item, index) in secret" :key="index">
                <div class="secret-row">
                    <dt x-text="item.label"></dt>
                    <dd>
                        <span class="mono" dir="ltr" x-text="item.value"></span>
                        <button type="button" class="btn btn-sm" x-on:click="copy(item.value, index)">
                            <x-icon name="copy" /><span x-text="copied === index ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                        </button>
                    </dd>
                </div>
            </template>
        </dl>
        <div><button type="button" class="btn btn-sm btn-ghost" x-on:click="hideSecret()"><x-icon name="x" />{{ __('Hide') }}</button></div>
    </div>
</template>
