{{--
    Service page card "Dedicated server" for products sold from Admin → Hardware: the machine, its
    IPs, the steps while staff install it, and the assign, ready, power and replace forms.
    Values: $service, and $machineCard from ServiceMachineComposer.
--}}
@php
    $card = $machineCard ?? null;
    $machine = $card['machine'] ?? null;
    $kinds = [\App\Models\MachineIp::KIND_PRIMARY => __('Main IP'), \App\Models\MachineIp::KIND_INCLUDED => __('Included'), \App\Models\MachineIp::KIND_EXTRA => __('Extra')];
    $hasChoices = $card && ($card['free']['linked']->isNotEmpty() || $card['free']['other']->isNotEmpty());
    $option = function (\App\Models\Machine $free): string {
        $specs = collect([$free->cpu, $free->ram_gb ? __(':count GB RAM', ['count' => $free->ram_gb]) : null, $free->location])->filter()->implode(' · ');

        return $specs === '' ? $free->name : $free->name.' · '.$specs;
    };
@endphp
@if ($card)
<section class="card" style="display:grid;gap:1rem" aria-labelledby="machine-card-title">
    <div class="card-header" style="margin:0">
        <h2 id="machine-card-title">{{ __('Dedicated server') }}</h2>
        @if ($machine)<x-status :value="$machine->status" />@endif
    </div>

    @if ($machine)
        <dl class="dl">
            <dt>{{ __('Machine') }}</dt>
            <dd>
                @if ($card['mayPower'])<a href="{{ route('admin.machines.show', $machine) }}">{{ $machine->name }}</a>@else{{ $machine->name }}@endif
                @if (filled($machine->location) || filled($machine->rack))
                    <span class="muted">· {{ collect([$machine->location, $machine->rack ? __('Rack :rack', ['rack' => trim($machine->rack.' '.$machine->rack_unit)]) : null])->filter()->implode(' · ') }}</span>
                @endif
            </dd>
            <dt>{{ __('Specs') }}</dt>
            <dd>{{ collect([$machine->cpu, $machine->ram_gb ? __(':count GB RAM', ['count' => $machine->ram_gb]) : null, $machine->storage, $machine->port_speed])->filter()->implode(' · ') ?: '—' }}</dd>
            <dt>{{ trans_choice('IP address|IP addresses', max(1, $card['ips']->count())) }}</dt>
            <dd>
                @forelse ($card['ips'] as $ip)
                    <div><span class="mono" dir="ltr">{{ $ip->address }}@if ($ip->prefix !== null)/{{ $ip->prefix }}@endif</span> <span class="faint">· {{ $kinds[$ip->kind] ?? $ip->kind }}</span></div>
                @empty
                    <span class="muted">{{ __('No IP addresses on this machine yet.') }}</span>
                @endforelse
            </dd>
            @if (! $machine->products->contains('id', $service->product_id))
                <dt>{{ __('Note') }}</dt><dd><x-pill tone="warn">{{ __('Not linked to this product') }}</x-pill></dd>
            @endif
        </dl>
    @else
        <p class="muted" style="margin:0">{{ $card['pending'] ? __('No machine yet. Assign one, or press Create account to take the first free machine sold as this product.') : __('This service has no machine. Assign the machine it runs on.') }}</p>
    @endif

    @if ($card['pending'] && $card['waitsForInstall'])
        <ol style="margin:0;padding-inline-start:1.3rem;display:grid;gap:.25rem;list-style:decimal">
            <li>{{ __('Install the system on :machine.', ['machine' => $machine->name.($machine->rack ? ' ('.__('Rack :rack', ['rack' => trim($machine->rack.' '.$machine->rack_unit)]).')' : '')]) }}@if (filled($service->product->module_config['default_os'] ?? null)) <span class="muted">({{ $service->product->module_config['default_os'] }})</span>@endif</li>
            <li>{{ __('Fill in the details below.') }}</li>
            <li>{{ __('Press Server is ready. The client gets an email with the IP and login name, never the password.') }}</li>
        </ol>

        <form method="POST" action="{{ route('admin.services.machine.ready', $service) }}" style="display:grid;gap:.9rem" aria-labelledby="ready-title">
            @csrf
            <h3 id="ready-title" class="sr-only">{{ __('Server is ready') }}</h3>
            <div class="form-grid">
                <x-input name="hostname" :label="__('Host name')" :value="$service->domain" maxlength="190" dir="ltr" spellcheck="false" autocomplete="off" placeholder="server1.example.com" />
                <x-input name="login_name" :label="__('Login name')" :value="$card['loginName']" required maxlength="32" dir="ltr" spellcheck="false" autocomplete="off" />
                <x-input name="root_password" type="password" :label="__('Root password')" minlength="12" maxlength="190" autocomplete="new-password" class="span-2"
                    :help="__('Optional. Saved encrypted. The client sees it in the client area. It is never emailed.')" />
            </div>
            <div><button class="btn btn-primary" type="submit"><x-icon name="check" />{{ __('Server is ready') }}</button></div>
        </form>
    @elseif ($card['pending'] && $machine)
        <p class="help" style="margin:0">{{ __('The machine is reserved. Press Create account to start the setup: your team gets an email to install the system.') }}</p>
    @endif

    @if ($card['setUp'] && $machine && $card['mayPower'])
        @include('admin.machines.partials.power', ['machine' => $machine])
    @endif

    @if (($card['pending'] || ($card['setUp'] && ! $machine)) && $hasChoices)
        {{-- Swapping the machine of a pending service is rare, so it stays folded away. --}}
        @if ($machine)<details @error('machine_id') open @enderror><summary class="row-link" style="cursor:pointer">{{ __('Use another machine') }}</summary>@endif
        <form method="POST" action="{{ route('admin.services.machine.assign', $service) }}" style="display:grid;gap:.7rem{{ $machine ? ';margin-top:.7rem' : '' }}"
              @if ($machine) data-confirm="{{ __('Give this service another machine? The current one goes back on sale, or waits for a wipe when its setup started.') }}" @endif>
            @csrf
            <div class="field">
                <label for="f-assign-machine">{{ $machine ? __('New machine') : __('Assign a machine') }}</label>
                <select id="f-assign-machine" name="machine_id" class="select" required @error('machine_id') aria-invalid="true" @enderror>
                    <option value="">{{ __('Choose a free machine') }}</option>
                    @if ($card['free']['linked']->isNotEmpty())
                        <optgroup label="{{ __('Sold as :product', ['product' => $service->product->name]) }}">
                            @foreach ($card['free']['linked'] as $free)<option value="{{ $free->id }}">{{ $option($free) }}</option>@endforeach
                        </optgroup>
                    @endif
                    @if ($card['free']['other']->isNotEmpty())
                        <optgroup label="{{ __('Other free machines') }}">
                            @foreach ($card['free']['other'] as $free)<option value="{{ $free->id }}">{{ $option($free) }}</option>@endforeach
                        </optgroup>
                    @endif
                </select>
                @error('machine_id')<p class="error">{{ $message }}</p>@else<p class="help">{{ $card['setUp'] ? __('For a service set up before. The machine goes straight into use, without any power action.') : __('The service keeps it until it is set up or cancelled.') }}</p>@enderror
            </div>
            <div><button class="btn btn-sm" type="submit">{{ $machine ? __('Use this machine') : __('Assign machine') }}</button></div>
        </form>
        @if ($machine)</details>@endif
    @elseif (($card['pending'] || ($card['setUp'] && ! $machine)) && ! $hasChoices)
        <p class="muted" style="margin:0">{{ __('No machine is free.') }}@if ($card['mayManage']) <a href="{{ route('admin.machines.create') }}">{{ __('Add a machine') }}</a>@endif</p>
    @endif

    @if ($card['setUp'] && $machine && $card['mayManage'] && $hasChoices)
        <details>
            <summary class="row-link" style="cursor:pointer">{{ __('Replace machine') }}</summary>
            <form method="POST" action="{{ route('admin.services.machine.replace', $service) }}" style="display:grid;gap:.7rem;margin-top:.7rem"
                  data-confirm="{{ __('Move this service to another machine? The old machine must be wiped before it is sold again.') }}">
                @csrf
                <div class="field">
                    <label for="f-replace-machine">{{ __('New machine') }}</label>
                    <select id="f-replace-machine" name="machine_id" class="select" required>
                        <option value="">{{ __('Choose a free machine') }}</option>
                        @foreach ($card['free']['linked']->concat($card['free']['other']) as $free)<option value="{{ $free->id }}">{{ $option($free) }}</option>@endforeach
                    </select>
                    <p class="help">{{ __('Move the data first. The service and its extra IPs move to the new machine; the old one waits for a wipe.') }}</p>
                </div>
                <x-checkbox name="confirm" :label="__('I moved the data, or the client agreed to start fresh')" />
                <div><button class="btn btn-sm btn-danger" type="submit">{{ __('Replace machine') }}</button></div>
            </form>
        </details>
    @endif
</section>
@endif
