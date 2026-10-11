{{--
    Console: open the server's screen in a new tab (the console page with noVNC), and, when the
    module offers it, show the details for a VNC app once ("details", a reveal action). The details
    need JavaScript: they come back in the answer and are never put in the page or the session.
--}}
@php
    $openUrl = $serverPanel['console']['url'] ?? null;
    $details = $section['actions']['details'] ?? null;
@endphp
<div class="panel-body">
    @include('theme::client.services.panel.partials.feedback')

    <p class="muted panel-note">{{ __('The console opens in a new tab. If it does not connect, your provider\'s panel may need a valid SSL certificate.') }}</p>
    <p class="flash" data-tone="warn" x-show="data && data.available === false" x-cloak data-js-only><span x-text="data?.reason || @js(__('The console is not available for this server right now.'))"></span></p>

    <div class="form-actions">
        @if ($openUrl)
            <form method="POST" action="{{ $openUrl }}" target="_blank" x-show="! data || data.available !== false">
                @csrf
                <button class="btn btn-primary" type="submit"><x-icon name="terminal" />{{ __('Open console') }}<span class="sr-only"> {{ __('(opens in a new tab)') }}</span></button>
            </form>
        @endif
        @if ($details)
            <form method="POST" action="{{ $details['url'] }}" x-on:submit.prevent="submit('details', $event)" x-show="can('details') && (! data || data.details !== false)" data-js-only>
                @csrf
                <button class="btn" type="submit" :disabled="busy !== null || waiting()"><x-icon name="eye" />{{ $details['label'] }}</button>
            </form>
        @endif
    </div>

    @if ($details)
        <noscript><p class="muted">{{ __('Turn on JavaScript to do this.') }}</p></noscript>
        <p class="muted panel-note" data-js-only>{{ __('Use these details in a VNC app such as TigerVNC or RealVNC.') }}</p>
    @endif

    @include('theme::client.services.panel.partials.secret')
</div>
