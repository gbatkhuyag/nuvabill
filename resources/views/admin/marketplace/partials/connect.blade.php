{{-- Connect to marketplace: lets this server past Cloudflare's bot checks on the marketplace store. --}}
@php
    $status = $connection['status'];
    $needsAction = ! $connection['connected'] || in_array($status, [\App\Marketplace\SiteConnection::RECONNECT, \App\Marketplace\SiteConnection::IP_CHANGED], true);
    $demo = \App\Support\Demo::isEnabled();
@endphp
<section class="card" style="display:flex;gap:12px 20px;align-items:center;flex-wrap:wrap" aria-labelledby="marketplace-connect">
    <div style="flex:1;min-width:240px;display:grid;gap:.3rem">
        <h2 id="marketplace-connect" style="margin:0;font-size:1rem;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            {{ __('Connect to marketplace') }}
            @if (! $connection['connected'])
                <x-pill tone="info">{{ __('Not connected') }}</x-pill>
            @elseif ($status === \App\Marketplace\SiteConnection::ACTIVE)
                <x-pill tone="good">{{ __('Connected') }}</x-pill>
            @elseif ($status === \App\Marketplace\SiteConnection::RECONNECT)
                <x-pill tone="crit">{{ __('Connect again') }}</x-pill>
            @elseif ($status === \App\Marketplace\SiteConnection::IP_CHANGED)
                <x-pill tone="warn">{{ __('Address changed') }}</x-pill>
            @else
                <x-pill tone="warn">{{ __('Waiting for the store') }}</x-pill>
            @endif
        </h2>
        <p class="muted" style="margin:0;font-size:.88rem">
            @if (! $connection['connected'])
                {{ __('If Cloudflare stops this server from reaching the marketplace, connect this site once. The store checks that you own this web address and lets this server through. It is free.') }}
            @elseif ($status === \App\Marketplace\SiteConnection::ACTIVE)
                {{ __('Connected since :date. The store lets this server through from :ip.', ['date' => $connection['since']?->translatedFormat('d M Y') ?? '—', 'ip' => $connection['ip']]) }}
            @elseif ($status === \App\Marketplace\SiteConnection::RECONNECT)
                {{ __('The store no longer accepts this site\'s key, for example because the connection was removed or not used for a long time. Connect again.') }}
            @elseif ($status === \App\Marketplace\SiteConnection::IP_CHANGED)
                {{ __('This server now reaches the internet from another address than :ip. Connect again so the store lets the new address through.', ['ip' => $connection['ip']]) }}
            @else
                {{ __('Connected from :ip. The store checks this address and lets it through, usually within a minute. If its staff need to look first, this can take a day.', ['ip' => $connection['ip']]) }}
            @endif
        </p>
        @if ($connection['blocked'] && $needsAction)
            <p style="margin:0;font-size:.88rem;color:var(--nb-crit);font-weight:600">{{ __('Cloudflare stopped this server from reaching the marketplace.') }}</p>
        @endif
    </div>
    @if ($needsAction || $status === \App\Marketplace\SiteConnection::PENDING || $status === \App\Marketplace\SiteConnection::WAITING)
        <form method="POST" action="{{ route('admin.marketplace.connect') }}">
            @csrf
            <button class="btn {{ $needsAction ? 'btn-primary' : '' }}" type="submit" @disabled($demo) @if ($demo) title="{{ __('This is turned off in the demo, so it keeps working for every visitor.') }}" @endif>
                {{ $connection['connected'] ? __('Connect again') : __('Connect to marketplace') }}
            </button>
        </form>
    @endif
</section>
