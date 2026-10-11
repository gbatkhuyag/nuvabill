{{--
    "Your server is being prepared": shown on the client's service page while staff install a paid
    dedicated server (HardwareServiceProvider). It lists the specs only: never an IP address, the
    machine's name or anything about its power control.

    Data: $service, and $specs as a list of {label, value}.
--}}
@php
    $rows = array_values(array_filter((array) ($specs ?? []), fn (mixed $row): bool => is_array($row) && is_string($row['label'] ?? null) && is_string($row['value'] ?? null) && $row['value'] !== ''));
@endphp
<section class="card" aria-labelledby="dedicated-pending-title" style="display:grid;gap:.8rem">
    <div class="card-header" style="margin:0">
        <h2 id="dedicated-pending-title">{{ __('Your server is being prepared') }}</h2>
        <x-pill tone="info"><x-icon name="clock" style="width:14px;height:14px" />{{ __('Setting up') }}</x-pill>
    </div>
    <p class="muted" style="margin:0">{{ __('Our team is installing your server. We email you as soon as it is ready.') }}</p>
    @if ($rows !== [])
        <dl class="dl">
            @foreach ($rows as $row)
                <dt>{{ $row['label'] }}</dt><dd><bdi>{{ $row['value'] }}</bdi></dd>
            @endforeach
        </dl>
    @endif
</section>
