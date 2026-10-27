{{--
    The console page: the server's screen in the browser, with noVNC (resources/js/console.js), or
    since 0.7.0 a text terminal with xterm.js (resources/js/terminal.js), each loaded on this page only,
    without Alpine. ServiceConsoleController gives service, ticket (null when the link has expired),
    expired, protocol ("vnc" or "terminal"), redeemUrl, openUrl and backUrl.

    The page holds no console address or password. Its script trades the single-use ticket for them
    once (POST redeemUrl) and connects. Only this page may connect to the console host.
--}}
@php
    $name = $service->domain ?: $service->product->name;
    $terminal = ($protocol ?? 'vnc') === 'terminal';
    $messages = [
        'connecting' => __('Connecting…'),
        'connected' => __('Connected'),
        'disconnected' => __('Disconnected'),
        'failed' => __('Could not connect to the console. The server\'s panel may need a valid SSL certificate, or a firewall blocks it.'),
        'password' => __('The console password was not accepted. Change the console password in Settings and try again.'),
        'expired' => __('This console link has expired.'),
        'screen' => $terminal ? __('Server terminal. Keys you type here go to the server.') : __('Server screen. Keys you type here go to the server.'),
        'pasted' => __('The text was typed on the server.'),
    ];
    $credit = $terminal
        ? str_replace(':link', '<a href="https://xtermjs.org" target="_blank" rel="noopener noreferrer">xterm.js</a>', e(__('Terminal by :link (MIT)')))
        : str_replace(':link', '<a href="https://github.com/novnc/noVNC" target="_blank" rel="noopener noreferrer">noVNC</a>', e(__('Console by :link (MPL-2.0)')));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locales::direction() }}">
<head>
    <title>{{ __('Console') }} · {{ $name }}</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    @include('partials.head', ['assets' => ['themes/nova/assets/theme.css', $terminal ? 'resources/js/terminal.js' : 'resources/js/console.js']])
    @include('theme::partials.brand-style')
</head>
<body class="console-body">
<main class="console-page" id="main">
    <header class="console-bar">
        <a class="console-name" href="{{ $backUrl }}"><x-icon name="chevron-left" /><span class="mono" dir="ltr">{{ $name }}</span></a>

        @if ($expired)
            <p class="console-status" role="status"><span class="console-dot" data-state="failed" aria-hidden="true"></span><span>{{ __('This console link has expired.') }}</span></p>
        @else
            <p class="console-status" role="status" aria-live="polite"><span class="console-dot" data-state="connecting" data-console-dot aria-hidden="true"></span><span data-console-status>{{ __('Connecting…') }}</span></p>

            <div class="console-actions" role="toolbar" aria-label="{{ __('Console') }}">
                @if ($terminal)
                <button type="button" class="btn btn-sm" data-console-action="fullscreen"><x-icon name="maximize" />{{ __('Full screen') }}</button>
                <button type="button" class="btn btn-sm btn-ghost" data-console-action="disconnect" disabled><x-icon name="x" />{{ __('Disconnect') }}</button>
                @else
                <button type="button" class="btn btn-sm" data-console-action="keys" disabled><x-icon name="terminal" />Ctrl+Alt+Del</button>
                <button type="button" class="btn btn-sm" data-console-action="paste" disabled><x-icon name="clipboard" />{{ __('Paste text') }}</button>
                <button type="button" class="btn btn-sm" data-console-action="fit" aria-pressed="true" disabled><x-icon name="layout" />{{ __('Fit to window') }}</button>
                <button type="button" class="btn btn-sm" data-console-action="fullscreen"><x-icon name="maximize" />{{ __('Full screen') }}</button>
                <button type="button" class="btn btn-sm btn-ghost" data-console-action="disconnect" disabled><x-icon name="x" />{{ __('Disconnect') }}</button>
                @endif
            </div>
        @endif

        <form method="POST" action="{{ $openUrl }}" class="console-again" @unless ($expired) hidden data-console-again @endunless>
            @csrf
            <button type="submit" class="btn btn-sm btn-primary"><x-icon name="refresh" />{{ __('Start the console again') }}</button>
        </form>
    </header>

    @if ($expired)
        <section class="console-expired card">
            <h1>{{ __('This console link has expired.') }}</h1>
            <p class="muted">{{ __('A console link works once, for one minute, in the browser that opened it. Start the console again to get a new one.') }}</p>
            <div class="form-actions">
                <form method="POST" action="{{ $openUrl }}">
                    @csrf
                    <button type="submit" class="btn btn-primary"><x-icon name="terminal" />{{ __('Start the console again') }}</button>
                </form>
                <a class="btn" href="{{ $backUrl }}">{{ __('Back to the service') }}</a>
            </div>
        </section>
    @else
        <div class="console-screen" id="console-screen" @if ($terminal) data-terminal dir="ltr" @else data-console @endif
            data-ticket="{{ $ticket }}"
            data-redeem="{{ $redeemUrl }}"
            data-messages="{{ json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
            role="application" aria-label="{{ $messages['screen'] }}"></div>
        <noscript><p class="console-noscript">{{ __('Turn on JavaScript to use the console.') }}</p></noscript>

        @unless ($terminal)
        <dialog class="console-dialog" data-console-paste aria-labelledby="console-paste-title">
            <form method="dialog" class="panel-form">
                <h2 id="console-paste-title">{{ __('Paste text') }}</h2>
                <div class="field">
                    <label for="console-paste-text">{{ __('Text to type on the server') }}</label>
                    <textarea id="console-paste-text" class="textarea mono" maxlength="500" rows="4" spellcheck="false" autocomplete="off" dir="ltr"></textarea>
                    <p class="help">{{ __('Up to 500 characters. Each one is typed as a key press, so this also works on a login screen.') }}</p>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" value="type">{{ __('Type it') }}</button>
                    <button type="submit" class="btn" value="cancel">{{ __('Cancel') }}</button>
                </div>
            </form>
        </dialog>
        @endunless
    @endif

    <footer class="console-foot">{!! $credit !!}</footer>
</main>
</body>
</html>
