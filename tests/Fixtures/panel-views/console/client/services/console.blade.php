{{-- Test stand-in for theme::client.services.console, used only while the theme has no console page. --}}
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ __('Console') }}</title></head>
<body>
@if ($expired)
    <p>{{ __('This console link has expired.') }}</p>
    <form method="POST" action="{{ $openUrl }}">@csrf<button type="submit">{{ __('Console') }}</button></form>
@else
    <div id="console" data-ticket="{{ $ticket }}" data-redeem="{{ $redeemUrl }}"></div>
@endif
<a href="{{ $backUrl }}">{{ $service->domain }}</a>
</body>
</html>
