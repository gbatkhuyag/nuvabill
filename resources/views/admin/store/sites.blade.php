<x-layouts.admin :title="__('Connected sites')">
    <div class="page-head"><div><h1>{{ __('Marketplace store') }}</h1></div></div>
    @include('admin.store.nav')

    @unless ($cloudflareReady)
        <div class="flash" data-tone="warn"><span>{{ __('Cloudflare is not set up, so connected sites get a key but their servers are not let through Bot Fight Mode. Add the zone ID and API token below.') }}</span></div>
    @endunless

    <form class="filters" method="GET" role="search">
        <a class="chip" href="{{ route('admin.store.sites.index', array_filter(['q' => $term ?: null])) }}" @if ($status === '') aria-current="true" @endif>{{ __('All') }}</a>
        @foreach (['pending' => __('Waiting'), 'active' => __('Active'), 'revoked' => __('Removed'), 'expired' => __('Expired')] as $key => $label)
            <a class="chip" href="{{ route('admin.store.sites.index', array_filter(['status' => $key, 'q' => $term ?: null])) }}" @if ($status === $key) aria-current="true" @endif>{{ $label }}@if ($key === 'pending' && $pendingCount) ({{ $pendingCount }})@endif</a>
        @endforeach
        <span style="flex:1"></span>
        @if ($status !== '')<input type="hidden" name="status" value="{{ $status }}">@endif
        <input class="input" type="search" name="q" value="{{ $term }}" placeholder="{{ __('Site or IP address') }}" aria-label="{{ __('Find a site') }}" style="max-width:260px">
    </form>

    <section class="card card-flush">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>{{ __('Site') }}</th><th>{{ __('Status') }}</th><th>{{ __('IP') }}</th><th>{{ __('Address check') }}</th><th>{{ __('Last seen') }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($sites as $site)
                <tr>
                    <td><a href="https://{{ $site->host }}" target="_blank" rel="noopener noreferrer">{{ $site->host }}</a>@if ($site->notes)<div class="faint" style="font-size:.78rem">{{ $site->notes }}</div>@endif</td>
                    <td>
                        @switch ($site->status)
                            @case ('active')<x-pill tone="good">{{ __('Active') }}</x-pill>@break
                            @case ('pending')<x-pill tone="warn">{{ __('Waiting') }}</x-pill>@break
                            @case ('revoked')<x-pill tone="crit">{{ __('Removed') }}</x-pill>@break
                            @default<x-pill tone="info">{{ __('Expired') }}</x-pill>
                        @endswitch
                        @if ($site->isActive() && $site->cloudflare_rule_id === null && $cloudflareReady)<div class="faint" style="font-size:.78rem">{{ __('No Cloudflare rule yet') }}</div>@endif
                    </td>
                    <td class="mono">{{ $site->ip }}</td>
                    <td>
                        @if ($site->ip_matches_dns)<x-pill tone="good">{{ __('In its DNS') }}</x-pill>@endif
                        @if ($site->ip_reached_directly)<x-pill tone="good">{{ __('Reached directly') }}</x-pill>@endif
                        @if (! $site->ip_matches_dns && ! $site->ip_reached_directly)<x-pill tone="warn">{{ __('Not checked') }}</x-pill>@endif
                    </td>
                    <td>{{ $site->last_seen_at?->diffForHumans(short: true) ?? '—' }}@if ($site->last_ip && $site->last_ip !== $site->ip)<div class="faint mono" style="font-size:.78rem">{{ $site->last_ip }}</div>@endif</td>
                    <td style="text-align:end;white-space:nowrap">
                        @if ($site->status === 'pending')
                            <form method="POST" action="{{ route('admin.store.sites.approve', $site) }}" style="display:inline">@csrf<button class="btn btn-sm" type="submit">{{ __('Approve') }}</button></form>
                        @endif
                        @if ($site->isUsable())
                            <form method="POST" action="{{ route('admin.store.sites.revoke', $site) }}" style="display:inline" onsubmit="return confirm(@js(__('Remove this site? Its key stops working and its server is no longer let through.')))">@csrf<button class="btn btn-sm" type="submit" style="color:var(--nb-crit)">{{ __('Remove') }}</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty">{{ __('No connected sites yet.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $sites->links() }}
    </section>

    <section class="card" style="display:grid;gap:.8rem;max-width:720px">
        <h2 style="margin:0;font-size:1.05rem">{{ __('Cloudflare') }}</h2>
        <p class="muted" style="margin:0;font-size:.88rem">{{ __('The store adds an IP Access rule ("Allow") for the server of each active site, so Bot Fight Mode lets it through. Make an API token with only the permission Zone > Firewall Services > Edit, for this zone only.') }}</p>
        <form method="POST" action="{{ route('admin.store.sites.cloudflare') }}" style="display:grid;gap:.8rem">
            @csrf
            @method('PUT')
            <x-input name="zone_id" :label="__('Zone ID')" :value="old('zone_id', $zoneId)" autocomplete="off" spellcheck="false" />
            <x-input name="api_token" type="password" :label="__('API token')" :help="$hasToken ? __('Saved. Leave empty to keep it.') : null" autocomplete="new-password" />
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn btn-primary" type="submit" name="action" value="save">{{ __('Save') }}</button>
                <button class="btn" type="submit" name="action" value="test">{{ __('Test connection') }}</button>
            </div>
        </form>
    </section>
</x-layouts.admin>
