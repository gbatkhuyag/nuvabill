@php
    $editing = $machine->exists;
    $type = (string) old('bmc_type', $machine->bmc_type ?? 'none');
    $chosen = array_map('intval', (array) old('product_ids', $linked));
@endphp
<x-layouts.admin :title="$editing ? __('Edit :name', ['name' => $machine->name]) : __('Add machine')">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.machines.index') }}">{{ __('Hardware') }}</a></p>
            <h1 style="margin-top:.2rem">{{ $editing ? __('Edit machine') : __('Add machine') }}</h1>
            @if ($editing)<p>{{ $machine->name }} · <x-status :value="$machine->status" /></p>@endif
        </div>
    </div>

    <form method="POST" action="{{ $editing ? route('admin.machines.update', $machine) : route('admin.machines.store') }}" style="display:grid;gap:14px;max-width:960px"
          x-data="{ type: @js($type), mode: @js((string) old('bmc_tls_mode', $machine->bmc_tls_mode ?? 'pinned')) }">
        @csrf
        @if ($editing) @method('PUT') @endif

        <section class="card" style="display:grid;gap:1.1rem" aria-labelledby="machine-title">
            <div class="card-header" style="margin:0"><h2 id="machine-title">{{ __('Machine') }}</h2></div>
            <div class="form-grid">
                <x-input name="name" :label="__('Name')" :value="$machine->name" required maxlength="100" :help="__('Your own label, for example DS-101. Clients never see it.')" />
                @unless ($editing)
                    <x-select name="status" :label="__('Status')" :options="[\App\Enums\MachineStatus::Available->value => \App\Enums\MachineStatus::Available->label(), \App\Enums\MachineStatus::Maintenance->value => \App\Enums\MachineStatus::Maintenance->label()]" value="available"
                        :help="__('Only available machines are sold.')" />
                @endunless
                <x-input name="location" :label="__('Location')" :value="$machine->location" maxlength="100" :help="__('Datacenter or city. Clients see it when Hardware settings allow it.')" />
                <div class="form-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
                    <x-input name="rack" :label="__('Rack')" :value="$machine->rack" maxlength="50" />
                    <x-input name="rack_unit" :label="__('Rack unit')" :value="$machine->rack_unit" maxlength="20" />
                </div>
                <x-input name="cpu" :label="__('CPU')" :value="$machine->cpu" maxlength="190" placeholder="Intel Xeon E-2388G" />
                <x-input name="cpu_cores" type="number" min="1" max="1024" :label="__('CPU cores')" :value="$machine->cpu_cores" />
                <x-input name="ram_gb" type="number" min="1" max="65536" :label="__('RAM (GB)')" :value="$machine->ram_gb" />
                <x-input name="storage" :label="__('Storage')" :value="$machine->storage" maxlength="255" placeholder="2 × 960 GB NVMe" />
                <x-input name="port_speed" :label="__('Port speed')" :value="$machine->port_speed" maxlength="50" placeholder="1 Gbps" />
                <x-textarea name="notes" :label="__('Staff notes')" :value="$machine->notes" rows="3" class="span-2" maxlength="5000" :help="__('Only staff see these notes.')" />
            </div>
        </section>

        <section class="card" style="display:grid;gap:1rem" aria-labelledby="products-title">
            <div class="card-header" style="margin:0"><h2 id="products-title">{{ __('Sold as') }}</h2></div>
            @if ($products->isEmpty())
                <p class="muted" style="margin:0">{{ __('No product uses machines yet. Make a product with the Dedicated servers module first, then link this machine to it.') }}</p>
            @else
                <p class="muted" style="margin:0">{{ __('A free machine is stock for every product you tick. One order takes it, so it is never sold twice.') }}</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px 14px">
                    @foreach ($products as $product)
                        <label class="check" for="f-product-{{ $product->id }}">
                            <input id="f-product-{{ $product->id }}" type="checkbox" name="product_ids[]" value="{{ $product->id }}" @checked(in_array($product->id, $chosen, true))>
                            <span>{{ $product->name }}@if ($product->group) <span class="muted">· {{ $product->group->name }}</span>@endif</span>
                        </label>
                    @endforeach
                </div>
                @error('product_ids')<p class="error" style="margin:0">{{ $message }}</p>@enderror
                @error('product_ids.*')<p class="error" style="margin:0">{{ $message }}</p>@enderror
            @endif
        </section>

        <section class="card" style="display:grid;gap:1.1rem" aria-labelledby="ips-title">
            <div class="card-header" style="margin:0"><h2 id="ips-title">{{ __('IP addresses') }}</h2></div>
            <div class="form-grid">
                <x-input name="primary_ip" :label="__('Main IP address')" :value="$ips['primary']" maxlength="45" dir="ltr" spellcheck="false" placeholder="203.0.113.10" />
                <div class="form-grid" style="grid-template-columns:minmax(0,1fr) minmax(0,2fr)">
                    <x-input name="primary_prefix" type="number" min="0" max="128" :label="__('Prefix')" :value="$ips['prefix']" placeholder="29" />
                    <x-input name="primary_gateway" :label="__('Gateway')" :value="$ips['gateway']" maxlength="45" dir="ltr" spellcheck="false" placeholder="203.0.113.9" />
                </div>
                <x-textarea name="included_ips" :label="__('Included IP addresses')" :value="$ips['included']" rows="4" dir="ltr" spellcheck="false"
                    :help="__('Come with every plan. One per line, for example 203.0.113.10/29 via 203.0.113.9')" />
                <x-textarea name="extra_ips" :label="__('Extra IP addresses')" :value="$ips['extra']" rows="4" dir="ltr" spellcheck="false"
                    :help="$ips['rented'] > 0
                        ? trans_choice('Free ones clients can rent. One per line. :count is rented now and cannot be removed.|Free ones clients can rent. One per line. :count are rented now and cannot be removed.', $ips['rented'], ['count' => $ips['rented']])
                        : __('Free ones clients can rent. One per line, for example 203.0.113.12')" />
            </div>
        </section>

        <section class="card" style="display:grid;gap:1.1rem" aria-labelledby="bmc-form-title">
            <div class="card-header" style="margin:0"><h2 id="bmc-form-title">{{ __('Power control (BMC)') }}</h2></div>
            <p class="muted" style="margin:0">{{ __('Use a BMC user with the Operator role, not Administrator. Keep the BMC on a private network that only this site can reach.') }}</p>
            <div class="form-grid">
                <div class="field">
                    <label for="f-bmc_type">{{ __('Power control') }}</label>
                    <select id="f-bmc_type" name="bmc_type" class="select" x-model="type" @error('bmc_type') aria-invalid="true" aria-describedby="f-bmc_type-error" @enderror>
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @selected($type === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('bmc_type')<p class="error" id="f-bmc_type-error">{{ $message }}</p>@else<p class="help">{{ __('Redfish works with iDRAC, iLO, Supermicro and most new servers.') }}</p>@enderror
                </div>
                <div x-show="type !== 'none'" @if ($type === 'none') style="display:none" @endif>
                    <x-input name="bmc_host" :label="__('BMC address')" :value="$machine->bmc_host" maxlength="190" dir="ltr" spellcheck="false" autocomplete="off" placeholder="10.20.0.11"
                        :help="__('An IP address or host name only, without https:// or a port.')" />
                </div>
                <div x-show="type !== 'none'" @if ($type === 'none') style="display:none" @endif>
                    <x-input name="bmc_port" type="number" min="1" max="65535" :label="__('BMC port')" :value="$machine->bmc_port" :help="__('Leave empty for the usual port: 443 for Redfish, 623 for IPMI.')" />
                </div>
                <div class="field" x-show="type !== 'none'" @if ($type === 'none') style="display:none" @endif>
                    <label for="f-bmc_username">{{ __('BMC user name') }}</label>
                    {{-- Never filled in: a saved login is not sent back to the browser. --}}
                    <input id="f-bmc_username" name="bmc_username" type="text" class="input" autocomplete="off" spellcheck="false" maxlength="190" dir="ltr"
                        @error('bmc_username') aria-invalid="true" aria-describedby="f-bmc_username-error" @enderror>
                    @error('bmc_username')
                        <p class="error" id="f-bmc_username-error">{{ $message }}</p>
                    @else
                        <p class="help">{{ $savedLogin ? __('Saved. Leave empty to keep it.') : __('Needed for power control.') }}</p>
                    @enderror
                </div>
                <div x-show="type !== 'none'" @if ($type === 'none') style="display:none" @endif>
                    <x-input name="bmc_password" type="password" :label="__('BMC password')" maxlength="190" autocomplete="new-password"
                        :help="$savedLogin ? __('Saved. Leave empty to keep it.') : __('Saved encrypted. It is never shown again.')" />
                </div>
                <div class="field" x-show="type === 'redfish'" @if ($type !== 'redfish') style="display:none" @endif>
                    <label for="f-bmc_tls_mode">{{ __('Certificate check') }}</label>
                    <select id="f-bmc_tls_mode" name="bmc_tls_mode" class="select" x-model="mode">
                        <option value="pinned" @selected(old('bmc_tls_mode', $machine->bmc_tls_mode) !== 'verified')>{{ __('Trust the certificate I check (most BMCs)') }}</option>
                        <option value="verified" @selected(old('bmc_tls_mode', $machine->bmc_tls_mode) === 'verified')>{{ __('The certificate is from a trusted CA') }}</option>
                    </select>
                    <p class="help" x-show="mode === 'pinned'">{{ $pinning ? __('After saving, press Test connection and compare the fingerprint with the BMC page. No login is sent before you trust it.') : \App\Provisioning\Hardware\Tls\PinningSupport::unavailableMessage() }}</p>
                </div>
                <div x-show="type === 'ipmi'" @if ($type !== 'ipmi') style="display:none" @endif>
                    <x-select name="bmc_ipmi_cipher" :label="__('IPMI cipher suite')" :options="[17 => '17 (AES-128, SHA-256)', 3 => '3 (AES-128, SHA-1)']" :value="$machine->bmc_ipmi_cipher ?? 17"
                        :help="__('Use 17 when the BMC supports it.')" />
                </div>
            </div>
            @if ($editing && $machine->hasBmc())
                <p class="help" style="margin:0">{{ __('When you change the type, address or port, enter the login again and trust the new certificate.') }}</p>
            @endif
        </section>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ $editing ? __('Save machine') : __('Add machine') }}</button>
            <a class="btn" href="{{ $editing ? route('admin.machines.show', $machine) : route('admin.machines.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.admin>
