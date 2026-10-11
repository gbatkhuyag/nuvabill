{{--
    The state of the section around it (panelSection): a loading skeleton, a read that failed with
    "Try again", the module's notices, and the message of the last action. Messages are read out by
    screen readers through one live region. Only for pages with JavaScript.
--}}
<div class="panel-feedback" data-js-only>
    <div class="skeleton-block" x-show="status === 'loading' || status === 'idle'" aria-hidden="true">
        <span class="skeleton"></span>
        <span class="skeleton"></span>
        <span class="skeleton" data-size="short"></span>
    </div>

    <div class="flash" data-tone="crit" x-show="loadError !== ''" x-cloak>
        <x-icon name="alert" class="flash-icon" />
        <span x-text="loadError"></span>
        <button type="button" class="btn btn-sm flash-action" x-on:click="reload()" :disabled="loading">{{ __('Try again') }}</button>
    </div>

    <template x-for="(notice, index) in notices()" :key="index">
        <div class="flash" :data-tone="notice.tone"><span x-text="notice.text"></span></div>
    </template>

    <div class="flash" :data-tone="tone === 'good' ? null : tone" x-show="message !== ''" x-cloak aria-hidden="true">
        <x-icon name="check" class="flash-icon" x-show="tone === 'good'" />
        <x-icon name="alert" class="flash-icon" x-show="tone !== 'good'" />
        <span x-text="message"></span>
    </div>
    <p class="sr-only" role="status" aria-live="polite"><span x-text="status === 'loading' ? @js(__('Loading…')) : message"></span></p>
</div>
