@use('App\Enums\MachineStatus')
<x-layouts.admin :title="__('Hardware')">
    @php
        $count = fn (MachineStatus ...$statuses): int => collect($statuses)->sum(fn (MachineStatus $status): int => (int) ($counts[$status->value] ?? 0));
        $all = (int) $counts->sum();
        $filtered = $filters['status'] !== null || $filters['product'] !== null || $filters['location'] !== '' || $filters['q'] !== '';
        $powerTones = ['on' => 'good', 'off' => 'crit', 'changing' => 'info'];
        $query = fn (array $change): array => array_filter(array_merge([
            'status' => $filters['status']?->value,
            'product' => $filters['product'],
            'location' => $filters['location'] !== '' ? $filters['location'] : null,
            'q' => $filters['q'] !== '' ? $filters['q'] : null,
        ], $change), fn ($value): bool => $value !== null && $value !== '');
    @endphp

    <div class="page-head">
        <div>
            <h1>{{ __('Hardware') }}</h1>
            <p>{{ __('Your own dedicated servers. Free machines are the stock of your dedicated products.') }}</p>
        </div>
        @if ($mayManage)
            <a class="btn btn-primary" href="{{ route('admin.machines.create') }}"><x-icon name="plus" />{{ __('Add machine') }}</a>
        @endif
    </div>

    @if ($all > 0)
        <div class="kpis">
            <a class="kpi" href="{{ route('admin.machines.index', ['status' => MachineStatus::Available->value]) }}"><small>{{ __('Available') }}</small><b>{{ $count(MachineStatus::Available) }}</b><span>{{ __('Free to sell') }}</span></a>
            <a class="kpi" href="{{ route('admin.machines.index', ['status' => MachineStatus::Provisioning->value]) }}"><small>{{ __('Setting up') }}</small><b>{{ $count(MachineStatus::Reserved, MachineStatus::Provisioning) }}</b><span>{{ trans_choice(':count waits for an OS install|:count wait for an OS install', $count(MachineStatus::Provisioning), ['count' => $count(MachineStatus::Provisioning)]) }}</span></a>
            <a class="kpi" href="{{ route('admin.machines.index', ['status' => MachineStatus::Active->value]) }}"><small>{{ __('In use') }}</small><b>{{ $count(MachineStatus::Active, MachineStatus::Suspended) }}</b><span>{{ __('Suspended: :count', ['count' => $count(MachineStatus::Suspended)]) }}</span></a>
            <a class="kpi" href="{{ route('admin.machines.index', ['status' => MachineStatus::Wiping->value]) }}"><small>{{ __('Needs wipe') }}</small><b @if ($count(MachineStatus::Wiping)) style="color:var(--nb-warn)" @endif>{{ $count(MachineStatus::Wiping) }}</b><span>{{ __('Not sold until wiped') }}</span></a>
        </div>
    @endif

    @if ($stock->isNotEmpty())
        <section class="card" aria-labelledby="stock-title">
            <div class="card-header"><h2 id="stock-title">{{ __('Stock per product') }}</h2></div>
            <ul class="list-plain">
                @foreach ($stock as $product)
                    <li class="feed-item">
                        <a class="row-link" href="{{ route('admin.machines.index', ['product' => $product->id]) }}">{{ $product->name }}</a>
                        <span @class(['num', 'muted' => $product->free_count > 0])>
                            @if ($product->machines_count === 0)
                                <x-pill tone="warn">{{ __('No machines linked') }}</x-pill>
                            @elseif ($product->free_count === 0)
                                <x-pill tone="warn">{{ __('Sold out: 0 free of :total', ['total' => $product->machines_count]) }}</x-pill>
                            @else
                                {{ __(':free free of :total', ['free' => $product->free_count, 'total' => $product->machines_count]) }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="filters" role="navigation" aria-label="{{ __('Filter by status') }}">
        <a class="chip" href="{{ route('admin.machines.index', $query(['status' => null])) }}" @if (! $filters['status']) aria-current="true" @endif>{{ __('All') }} <span class="num">{{ $all }}</span></a>
        @foreach (MachineStatus::cases() as $case)
            <a class="chip" href="{{ route('admin.machines.index', $query(['status' => $case->value])) }}" @if ($filters['status'] === $case) aria-current="true" @endif>{{ $case->label() }} <span class="num">{{ $count($case) }}</span></a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.machines.index') }}" class="filters" role="search" aria-label="{{ __('Search machines') }}">
        @if ($filters['status'])<input type="hidden" name="status" value="{{ $filters['status']->value }}">@endif
        @if ($stock->isNotEmpty())
            <label class="sr-only" for="filter-product">{{ __('Product') }}</label>
            <select id="filter-product" name="product" class="select" style="width:auto;max-width:100%" data-autosubmit>
                <option value="">{{ __('Every product') }}</option>
                @foreach ($stock as $product)
                    <option value="{{ $product->id }}" @selected($filters['product'] === $product->id)>{{ $product->name }}</option>
                @endforeach
            </select>
        @endif
        @if ($locations->isNotEmpty())
            <label class="sr-only" for="filter-location">{{ __('Location') }}</label>
            <select id="filter-location" name="location" class="select" style="width:auto;max-width:100%" data-autosubmit>
                <option value="">{{ __('Every location') }}</option>
                @foreach ($locations as $place)
                    <option value="{{ $place }}" @selected($filters['location'] === $place)>{{ $place }}</option>
                @endforeach
            </select>
        @endif
        <label class="sr-only" for="filter-q">{{ __('Name or IP address') }}</label>
        <input id="filter-q" class="input" type="search" name="q" value="{{ $filters['q'] }}" maxlength="100" placeholder="{{ __('Name or IP address') }}" style="width:220px;max-width:100%">
        <button class="btn btn-sm" type="submit"><x-icon name="search" />{{ __('Search') }}</button>
        @if ($filtered)
            <a class="btn btn-sm btn-ghost" href="{{ route('admin.machines.index') }}">{{ __('Clear filters') }}</a>
        @endif
    </form>

    <section class="card card-flush">
        @if ($machines->isEmpty())
            @if ($filtered)
                <div class="empty"><strong>{{ __('No machines match') }}</strong>{{ __('Try another filter or search.') }}</div>
            @else
                <div class="empty">
                    <strong>{{ __('No machines yet') }}</strong>{{ __('Add the dedicated servers you rent out, with their BMC login for power control.') }}
                    @if ($mayManage)
                        <div style="margin-top:.8rem"><a class="btn btn-primary btn-sm" href="{{ route('admin.machines.create') }}"><x-icon name="plus" />{{ __('Add machine') }}</a></div>
                    @endif
                </div>
            @endif
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr>
                    <th>{{ __('Machine') }}</th>
                    <th>{{ __('Specs') }}</th>
                    <th>{{ __('Main IP') }}</th>
                    <th>{{ __('Sold as') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Client') }}</th>
                    <th>{{ __('Power') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($machines as $machine)
                    @php $state = $machine->powerState(); @endphp
                    <tr>
                        <td>
                            <a class="row-link" href="{{ route('admin.machines.show', $machine) }}">{{ $machine->name }}</a>
                            @if (filled($machine->location) || filled($machine->rack))
                                <div class="faint" style="font-size:.8rem">{{ collect([$machine->location, $machine->rack ? __('Rack :rack', ['rack' => trim($machine->rack.' '.$machine->rack_unit)]) : null])->filter()->implode(' · ') }}</div>
                            @endif
                        </td>
                        <td style="white-space:normal;min-width:180px;font-size:.85rem">
                            {{ collect([$machine->cpu, $machine->ram_gb ? __(':count GB RAM', ['count' => $machine->ram_gb]) : null, $machine->storage])->filter()->implode(' · ') ?: '—' }}
                        </td>
                        <td class="mono" dir="ltr">{{ $machine->primaryIp?->address ?? '—' }}</td>
                        <td style="white-space:normal;font-size:.85rem">{{ $machine->products->pluck('name')->implode(', ') ?: '—' }}</td>
                        <td><x-status :value="$machine->status" /></td>
                        <td>
                            @if ($machine->service)
                                <a href="{{ route('admin.services.show', $machine->service) }}" style="white-space:nowrap">{{ __('Service #:id', ['id' => $machine->service->id]) }}</a>
                                @if ($machine->service->client)<div class="faint" style="font-size:.8rem">{{ $machine->service->client->name }}</div>@endif
                            @else
                                <span class="faint">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($machine->hasBmc())
                                <x-pill :tone="$powerTones[$state->value] ?? null">{{ $state->label() }}</x-pill>
                                @if ($machine->power_checked_at)<div class="faint" style="font-size:.75rem">{{ __('Checked :time', ['time' => $machine->power_checked_at->diffForHumans()]) }}</div>@endif
                            @else
                                <span class="faint" style="font-size:.8rem">{{ __('By hand') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $machines->links() }}
        @endif
    </section>

    @if ($settings)
        <form method="POST" action="{{ route('admin.machines.settings') }}" class="card" style="display:grid;gap:1rem" aria-labelledby="hardware-settings-title">
            @csrf
            @method('PUT')
            <div class="card-header" style="margin:0"><h2 id="hardware-settings-title">{{ __('Hardware settings') }}</h2></div>
            <div class="form-grid">
                <x-textarea name="os_choices" :label="__('Systems clients can ask for')" :value="$settings['os_choices']" rows="5" required
                    :help="__('One per line, for example Ubuntu 24.04. Clients pick one when they ask for a reinstall.')" />
                <div style="display:grid;gap:1rem;align-content:start">
                    <x-select name="ticket_department" :label="__('Department for reinstall requests')" :options="$settings['departments']" :value="$settings['ticket_department']" :placeholder="__('The first visible department')" />
                    <x-input name="suspend_grace_minutes" type="number" min="1" max="120" :label="__('Minutes to wait before forcing the power off')" :value="$settings['suspend_grace_minutes']" required
                        :help="__('After a suspension, the server gets a shutdown signal first. It is forced off when it is still on after this wait.')" />
                </div>
                <x-checkbox name="show_location" :label="__('Show the location to the client')" :checked="$settings['show_location']" />
                <x-checkbox name="allow_ipmi" :label="__('Offer IPMI for power control')" :checked="$settings['allow_ipmi']"
                    :help="$settings['ipmi_found'] ? __('IPMI is older and less safe than Redfish. Use it only on a private network.') : __('ipmitool was not found on this server, so IPMI cannot be used.')" />
            </div>
            <div><button class="btn btn-primary" type="submit">{{ __('Save settings') }}</button></div>
        </form>
    @endif
</x-layouts.admin>
