{{--
    The machine page's "Power control (BMC)" card: what Nuvabill knows about the BMC, Test connection,
    the certificate to trust after a test, and the system to pick on a BMC with several. Never the
    BMC login. The address is shown only to staff who may manage hardware.
    Values: $machine, $mayManage, $trust (the certificate a test showed, or null), $systems (paths),
    $pinning (whether this site can check pinned certificates), $driverLabel.
--}}
@php
    $hasBmc = $machine->hasBmc();
    $pinned = $machine->bmc_tls_mode !== 'verified';
    $ipmi = $machine->bmc_type === 'ipmi';
@endphp
<section class="card" style="display:grid;gap:1rem" aria-labelledby="bmc-title">
    <div class="card-header" style="margin:0">
        <h2 id="bmc-title">{{ __('Power control (BMC)') }}</h2>
        @if ($hasBmc && $mayManage)
            <form method="POST" action="{{ route('admin.machines.test', $machine) }}">
                @csrf
                <button class="btn btn-sm" type="submit"><x-icon name="activity" />{{ __('Test connection') }}</button>
            </form>
        @endif
    </div>

    @if (! $hasBmc)
        <p class="muted" style="margin:0">{{ __('No power control is set up. Staff switch this machine on and off by hand.') }}
            @if ($mayManage)<a href="{{ route('admin.machines.edit', $machine) }}">{{ __('Add its BMC') }}</a>@endif
        </p>
    @else
        @if ($pinned && ! $ipmi && ! $pinning)
            <div class="flash" data-tone="warn" role="alert"><span>{{ \App\Provisioning\Hardware\Tls\PinningSupport::unavailableMessage() }}</span></div>
        @endif

        <dl class="dl">
            <dt>{{ __('Type') }}</dt><dd>{{ $driverLabel }}</dd>
            @if ($mayManage)
                <dt>{{ __('Address') }}</dt><dd class="mono" dir="ltr">{{ $machine->bmc_host }}@if ($machine->bmc_port):{{ $machine->bmc_port }}@endif</dd>
            @endif
            @unless ($ipmi)
                <dt>{{ __('Certificate') }}</dt>
                <dd>
                    @if (! $pinned)
                        <x-pill tone="good">{{ __('Checked by a trusted CA') }}</x-pill>
                    @elseif (filled($machine->bmc_tls_fingerprint))
                        <x-pill tone="good">{{ __('Trusted') }}</x-pill>
                        <div class="mono faint" dir="ltr" style="font-size:.75rem;margin-top:.3rem;overflow-wrap:anywhere">SHA-256 {{ $machine->bmc_tls_fingerprint }}</div>
                    @else
                        <x-pill tone="warn">{{ __('Not trusted yet') }}</x-pill>
                    @endif
                </dd>
            @endunless
            <dt>{{ __('System') }}</dt>
            <dd>
                @if (filled($machine->bmc_vendor) || filled($machine->bmc_model))
                    {{ trim($machine->bmc_vendor.' '.$machine->bmc_model) }}
                @else
                    <span class="muted">—</span>
                @endif
                @if (filled($machine->bmc_system_path))
                    <div class="mono faint" dir="ltr" style="font-size:.75rem;overflow-wrap:anywhere">{{ $machine->bmc_system_path }}</div>
                @endif
            </dd>
            <dt>{{ __('Last test') }}</dt>
            <dd>{{ $machine->bmc_checked_at ? $machine->bmc_checked_at->diffForHumans() : __('Never') }}</dd>
            @if (filled($machine->bmc_last_error))
                <dt>{{ __('Last error') }}</dt>
                <dd>
                    <span style="color:var(--nb-crit)">{{ $machine->bmc_last_error }}</span>
                    @if ($machine->bmc_failures > 0)
                        <div class="faint" style="font-size:.8rem">{{ trans_choice(':count failure in a row|:count failures in a row', $machine->bmc_failures, ['count' => $machine->bmc_failures]) }}</div>
                    @endif
                </dd>
            @endif
        </dl>

        @if ($trust && $mayManage)
            <div class="flash" data-tone="warn" style="display:grid;gap:.7rem" role="region" aria-labelledby="trust-title">
                <div style="display:grid;gap:.4rem;min-width:0">
                    <strong id="trust-title">{{ __('Trust this BMC certificate?') }}</strong>
                    <span>{{ __('Open the BMC in your browser and compare its certificate fingerprint (SHA-256) with the one below. Trust it only when they match. Until then, Nuvabill sends no login to this BMC.') }}</span>
                    <dl class="dl">
                        <dt>{{ __('Fingerprint') }}</dt><dd class="mono" dir="ltr" style="font-size:.8rem;overflow-wrap:anywhere">{{ $trust['fingerprint'] }}</dd>
                        @if (filled($trust['subject'] ?? null))<dt>{{ __('Name in certificate') }}</dt><dd class="mono" dir="ltr">{{ $trust['subject'] }}</dd>@endif
                        @if (filled($trust['valid_to'] ?? null))<dt>{{ __('Valid until') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($trust['valid_to'])->translatedFormat('d M Y') }}</dd>@endif
                    </dl>
                </div>
                <form method="POST" action="{{ route('admin.machines.trust', $machine) }}" style="display:grid;gap:.7rem">
                    @csrf
                    <input type="hidden" name="fingerprint" value="{{ $trust['fingerprint'] }}">
                    <x-input name="pasted_fingerprint" :label="__('Fingerprint from the BMC page (optional)')" autocomplete="off" spellcheck="false" dir="ltr"
                        :help="__('Paste it to let Nuvabill compare both for you.')" />
                    <div><button class="btn btn-primary btn-sm" type="submit"><x-icon name="lock" />{{ __('Trust this certificate') }}</button></div>
                </form>
            </div>
        @endif

        @if ($systems !== [] && $mayManage)
            <form method="POST" action="{{ route('admin.machines.test', $machine) }}" class="flash" data-tone="info" style="display:grid;gap:.7rem">
                @csrf
                <fieldset style="border:0;padding:0;margin:0;display:grid;gap:.4rem;min-width:0">
                    <legend style="font-weight:600;margin-bottom:.3rem">{{ __('Which system is this machine?') }}</legend>
                    @foreach ($systems as $path)
                        <label class="check" for="system-{{ $loop->index }}">
                            <input id="system-{{ $loop->index }}" type="radio" name="system" value="{{ $path }}" @checked($loop->first) required>
                            <span class="mono" dir="ltr" style="overflow-wrap:anywhere">{{ $path }}</span>
                        </label>
                    @endforeach
                </fieldset>
                <div><button class="btn btn-primary btn-sm" type="submit">{{ __('Use this system and test again') }}</button></div>
            </form>
        @endif

        @if ($mayManage)
            <p class="muted" style="margin:0;font-size:.85rem">{{ __('Use a BMC user with the Operator role, not Administrator. Keep the BMC on a private network that only this site can reach.') }}</p>
        @endif
    @endif
</section>
