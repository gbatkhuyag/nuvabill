@use('App\Enums\MachineStatus')
@use('App\Models\MachineIp')
<x-layouts.admin :title="$machine->name">
    @php
        $status = $machine->status;
        $kinds = [MachineIp::KIND_PRIMARY => __('Main IP'), MachineIp::KIND_INCLUDED => __('Included'), MachineIp::KIND_EXTRA => __('Extra')];
        $service = $machine->service;
    @endphp

    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.machines.index') }}">{{ __('Hardware') }}</a></p>
            <h1 style="margin-top:.2rem">{{ $machine->name }} <x-status :value="$status" style="vertical-align:middle" /></h1>
            <p>
                {{ collect([$machine->location, $machine->rack ? __('Rack :rack', ['rack' => trim($machine->rack.' '.$machine->rack_unit)]) : null])->filter()->implode(' · ') ?: __('No location set') }}
            </p>
        </div>
        @if ($mayManage)
            <a class="btn" href="{{ route('admin.machines.edit', $machine) }}"><x-icon name="settings" />{{ __('Edit machine') }}</a>
        @endif
    </div>

    @if ($status === MachineStatus::Wiping)
        <div class="flash" data-tone="warn" role="alert">
            <span>{{ __('This machine waits for a disk wipe. It is not sold again until staff confirm that all its disks were erased.') }}</span>
        </div>
    @endif

    <div class="grid-2">
        <div style="display:grid;gap:14px;align-content:start">
            <section class="card" aria-labelledby="details-title">
                <div class="card-header"><h2 id="details-title">{{ __('Details') }}</h2></div>
                <dl class="dl">
                    <dt>{{ __('CPU') }}</dt><dd>{{ $machine->cpu ?: '—' }}@if ($machine->cpu_cores) · {{ trans_choice(':count core|:count cores', $machine->cpu_cores, ['count' => $machine->cpu_cores]) }}@endif</dd>
                    <dt>{{ __('RAM') }}</dt><dd>{{ $machine->ram_gb ? __(':count GB', ['count' => $machine->ram_gb]) : '—' }}</dd>
                    <dt>{{ __('Storage') }}</dt><dd>{{ $machine->storage ?: '—' }}</dd>
                    <dt>{{ __('Port speed') }}</dt><dd>{{ $machine->port_speed ?: '—' }}</dd>
                    <dt>{{ __('Sold as') }}</dt>
                    <dd>
                        @forelse ($machine->products as $product)
                            @if ($mayProducts)<a href="{{ route('admin.products.edit', $product) }}">{{ $product->name }}</a>@else{{ $product->name }}@endif{{ $loop->last ? '' : ', ' }}
                        @empty
                            <span class="muted">{{ __('No product yet, so it is not for sale.') }}</span>
                        @endforelse
                    </dd>
                    @if ($machine->wiped_at)
                        <dt>{{ __('Last wipe') }}</dt><dd>{{ $machine->wiped_at->translatedFormat('d M Y H:i') }}</dd>
                    @endif
                    @if (filled($machine->notes))
                        <dt>{{ __('Staff notes') }}</dt><dd class="message-body">{{ $machine->notes }}</dd>
                    @endif
                </dl>
            </section>

            <section class="card card-flush" aria-labelledby="ips-title">
                <div class="card-header" style="padding:1rem 1.1rem 0"><h2 id="ips-title">{{ __('IP addresses') }}</h2></div>
                @if ($machine->ips->isEmpty())
                    <div class="empty"><strong>{{ __('No IP addresses yet') }}</strong>{{ __('Add them on the machine form.') }}</div>
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Address') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Gateway') }}</th><th>{{ __('Rented by') }}</th></tr></thead>
                        <tbody>
                        @foreach ($machine->ips as $ip)
                            <tr>
                                <td class="mono" dir="ltr">{{ $ip->address }}@if ($ip->prefix !== null)/{{ $ip->prefix }}@endif</td>
                                <td>{{ $kinds[$ip->kind] ?? $ip->kind }}</td>
                                <td class="mono" dir="ltr">{{ $ip->gateway ?? '—' }}</td>
                                <td>
                                    @if ($ip->service_id !== null)
                                        @if ($mayServices)<a href="{{ route('admin.services.show', $ip->service_id) }}">{{ __('Service #:id', ['id' => $ip->service_id]) }}</a>@else{{ __('Service #:id', ['id' => $ip->service_id]) }}@endif
                                    @elseif ($ip->kind === MachineIp::KIND_EXTRA)
                                        <span class="muted">{{ __('Free') }}</span>
                                    @else
                                        <span class="faint">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>

            @include('admin.machines.partials.bmc')

            <section class="card" aria-labelledby="history-title">
                <div class="card-header"><h2 id="history-title">{{ __('History') }}</h2></div>
                <ul class="list-plain">
                    @forelse ($history as $entry)
                        <li class="feed-item">
                            <span>{{ $entry->description }} <span class="faint">· {{ $entry->actorName() }}</span></span>
                            <time datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $entry->created_at->translatedFormat('d M Y H:i') }}">{{ $entry->created_at->diffForHumans(short: true) }}</time>
                        </li>
                    @empty
                        <li class="muted">{{ __('Nothing yet.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>

        <div style="display:grid;gap:14px;align-content:start">
            <section class="card" aria-labelledby="use-title">
                <div class="card-header"><h2 id="use-title">{{ __('In use by') }}</h2></div>
                @if ($service)
                    <dl class="dl">
                        <dt>{{ __('Service') }}</dt>
                        <dd>@if ($mayServices)<a href="{{ route('admin.services.show', $service) }}">{{ __('Service #:id', ['id' => $service->id]) }}</a>@else{{ __('Service #:id', ['id' => $service->id]) }}@endif · <x-status :value="$service->status" /></dd>
                        <dt>{{ __('Product') }}</dt><dd>{{ $service->product?->name ?? '—' }}</dd>
                        @if ($service->client)
                            <dt>{{ __('Client') }}</dt>
                            <dd>@if ($mayClients)<a href="{{ route('admin.clients.show', $service->client) }}">{{ $service->client->name }}</a>@else{{ $service->client->name }}@endif</dd>
                        @endif
                        @if ($machine->provisioned_at)
                            <dt>{{ __('Ready since') }}</dt><dd>{{ $machine->provisioned_at->translatedFormat('d M Y') }}</dd>
                        @endif
                    </dl>
                @else
                    <p class="muted" style="margin:0">{{ $status === MachineStatus::Available ? __('Free. The next order of a linked product takes it.') : __('No service holds this machine.') }}</p>
                    @if ($lastService)
                        <p class="faint" style="margin:.5rem 0 0;font-size:.85rem">
                            {{ __('Last used by') }}
                            @if ($mayServices)<a href="{{ route('admin.services.show', $lastService) }}">{{ __('Service #:id', ['id' => $lastService->id]) }}</a>@else{{ __('Service #:id', ['id' => $lastService->id]) }}@endif
                            @if ($machine->released_at) · {{ $machine->released_at->translatedFormat('d M Y') }}@endif
                        </p>
                    @endif
                @endif
            </section>

            <section class="card" aria-labelledby="power-title">
                <div class="card-header"><h2 id="power-title">{{ __('Power') }}</h2></div>
                @include('admin.machines.partials.power')
            </section>

            @if ($readsHealth)
                @php($healthTones = ['ok' => 'good', 'warning' => 'warn', 'critical' => 'crit'])
                <section class="card" style="display:grid;gap:.8rem" aria-labelledby="health-title">
                    <div class="card-header" style="margin:0">
                        <h2 id="health-title">{{ __('Health') }}</h2>
                        <form method="POST" action="{{ route('admin.machines.health', $machine) }}">
                            @csrf
                            <button class="btn btn-sm btn-ghost" type="submit"><x-icon name="refresh" />{{ __('Read now') }}</button>
                        </form>
                    </div>
                    @if ($health === null)
                        <p class="muted" style="margin:0">{{ __('Not read yet. Press Read now to ask the BMC.') }}</p>
                    @else
                        @if ($health->error)
                            <p style="margin:0;color:var(--nb-crit)">{{ $health->error }}</p>
                        @endif
                        <dl class="dl">
                            @if ($health->watts !== null)
                                <dt>{{ __('Power use') }}</dt><dd>{{ __(':watts W', ['watts' => $health->watts]) }}</dd>
                            @endif
                            @foreach ($health->readings as $reading)
                                <dt>{{ $reading->name }}</dt>
                                <dd>@isset($healthTones[$reading->status])<x-pill :tone="$healthTones[$reading->status]">{{ $reading->value }}</x-pill>@else{{ $reading->value }}@endisset</dd>
                            @endforeach
                        </dl>
                        <p class="faint" style="margin:0;font-size:.8rem">{{ __('Read :time', ['time' => $health->readAt->diffForHumans()]) }}</p>
                    @endif
                </section>
            @endif

            @if ($powerEvents->isNotEmpty())
                <section class="card" aria-labelledby="power-history-title">
                    <div class="card-header"><h2 id="power-history-title">{{ __('Power history') }}</h2></div>
                    <ul class="list-plain">
                        @foreach ($powerEvents as $event)
                            <li class="feed-item">
                                <span>
                                    {{ $event->actionLabel() }}
                                    @if ($event->result === \App\Models\MachinePowerEvent::RESULT_FAILED)
                                        <x-pill tone="crit">{{ __('Failed') }}</x-pill>
                                    @elseif ($event->result === \App\Models\MachinePowerEvent::RESULT_ALREADY)
                                        <span class="faint">({{ __('already done') }})</span>
                                    @endif
                                    <span class="faint">· {{ $event->actorLabel() }}</span>
                                    @if ($event->message)<div class="faint" style="font-size:.8rem">{{ $event->message }}</div>@endif
                                </span>
                                <time datetime="{{ $event->created_at->toIso8601String() }}" title="{{ $event->created_at->translatedFormat('d M Y H:i') }}">{{ $event->created_at->diffForHumans(short: true) }}</time>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @foreach ($addonCards as $card)
                {!! $card !!}
            @endforeach

            @if ($mayManage)
                @if ($status === MachineStatus::Wiping)
                    <form method="POST" action="{{ route('admin.machines.wiped', $machine) }}" class="card" style="display:grid;gap:.9rem" aria-labelledby="wipe-title"
                          data-confirm="{{ __('Confirm that every disk of :machine was erased?', ['machine' => $machine->name]) }}">
                        @csrf
                        <div class="card-header" style="margin:0"><h2 id="wipe-title">{{ __('Confirm wipe') }}</h2></div>
                        <p class="muted" style="margin:0">{{ __('Erase all disks first, for example with a secure erase from the BMC or a wipe tool. The last client\'s data must not reach the next client.') }}</p>
                        <x-checkbox name="confirm_wiped" :label="__('I erased all disks of this machine')" />
                        <x-select name="next" :label="__('Then set it to')" :options="[MachineStatus::Available->value => __('Available (back on sale)'), MachineStatus::Maintenance->value => MachineStatus::Maintenance->label()]" value="available" />
                        <div><button class="btn btn-primary" type="submit"><x-icon name="check" />{{ __('Confirm wipe') }}</button></div>
                    </form>
                @elseif (in_array($status, [MachineStatus::Available, MachineStatus::Maintenance, MachineStatus::Decommissioned], true))
                    <section class="card" style="display:grid;gap:.8rem" aria-labelledby="status-title">
                        <div class="card-header" style="margin:0"><h2 id="status-title">{{ __('Status') }}</h2></div>
                        <div class="form-actions">
                            @if ($status !== MachineStatus::Available && $status !== MachineStatus::Decommissioned)
                                <form method="POST" action="{{ route('admin.machines.status', $machine) }}">@csrf<input type="hidden" name="to" value="available"><button class="btn btn-sm btn-primary" type="submit">{{ __('Set available') }}</button></form>
                            @endif
                            @if ($status !== MachineStatus::Maintenance)
                                <form method="POST" action="{{ route('admin.machines.status', $machine) }}">@csrf<input type="hidden" name="to" value="maintenance"><button class="btn btn-sm" type="submit">{{ __('Set to maintenance') }}</button></form>
                            @endif
                            @if ($status !== MachineStatus::Decommissioned)
                                <form method="POST" action="{{ route('admin.machines.status', $machine) }}" data-confirm="{{ __('Retire :machine? It is no longer sold.', ['machine' => $machine->name]) }}">@csrf<input type="hidden" name="to" value="decommissioned"><button class="btn btn-sm btn-danger" type="submit">{{ __('Retire') }}</button></form>
                            @endif
                        </div>
                        <p class="help" style="margin:0">{{ __('Machines in use change status with their service. Maintenance and retired machines are not sold.') }}</p>
                    </section>
                @endif

                @if ($deletable)
                    <form method="POST" action="{{ route('admin.machines.destroy', $machine) }}" data-confirm="{{ __('Delete :machine? This cannot be undone.', ['machine' => $machine->name]) }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-danger btn-sm" type="submit"><x-icon name="trash" />{{ __('Delete machine') }}</button>
                    </form>
                @endif
            @endif
        </div>
    </div>
</x-layouts.admin>
