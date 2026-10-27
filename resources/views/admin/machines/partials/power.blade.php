{{--
    A machine's power state and power buttons (staff with hardware.power), on the machine page and on
    the "Dedicated server" card of a service. The page shows the last saved state; machinePower()
    then asks the BMC through Nuvabill. Without JavaScript each button posts as a normal form.
    Without a power driver for the machine (the Dedicated Server Power add-on), staff see a note.
    Values: $machine.
--}}
@php
    $power = app(\App\Provisioning\Hardware\PowerControl::class);
    $actions = $power->actions($machine);
    $bootDevices = $power->bootDevices($machine);
    $drivers = app(\App\Provisioning\Hardware\Drivers\PowerDrivers::class);
    $noDriver = $drivers->for($machine) === null;
    $mayMarketplace = (bool) auth('admin')->user()?->hasPermission('marketplace.manage');
    $state = $machine->powerState();
    $tones = ['on' => 'good', 'off' => 'crit', 'changing' => 'info'];
    $confirm = [
        'shutdown' => __('Shut down the server? Websites and apps on it stop.'),
        'restart' => __('Restart the server now?'),
        'reset' => __('Force a restart? This is like pressing the reset button. Unsaved data is lost.'),
        'off' => __('Force the power off? This is like pulling the plug. Use it only if the server does not respond.'),
    ];
    $config = [
        'state' => $state->value,
        'label' => $state->label(),
        'checked' => $machine->power_checked_at ? __('Checked :time', ['time' => $machine->power_checked_at->diffForHumans()]) : null,
        'actions' => array_map(fn (\App\Enums\PowerAction $action): string => $action->value, $actions),
        'stateUrl' => route('admin.machines.power.show', $machine),
        // Only a machine with power buttons is asked: a BMC that is not trusted yet would count a failure.
        'load' => $machine->hasBmc() && $actions !== [],
        'labels' => ['pending' => __('Waiting for the server…'), 'unknown' => \App\Enums\PowerState::Unknown->label()],
        'messages' => [
            'failed' => __('That did not work. Reload the page and try again.'),
            'unread' => __('We could not read the power state right now. Try Check now in a minute.'),
            'slow' => __('The server has not changed its state yet. Check again in a minute.'),
        ],
    ];
    $id = 'power-'.$machine->id;
@endphp
<div class="machine-power" style="display:grid;gap:.7rem" x-data="machinePower(@js($config))">
    @if ($machine->hasBmc() && ! $noDriver)
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
        <span class="muted" id="{{ $id }}-label">{{ __('Power state') }}</span>
        <span class="pill" aria-describedby="{{ $id }}-label" @isset($tones[$state->value]) data-tone="{{ $tones[$state->value] }}" @endisset :data-tone="tone" x-text="label">{{ $state->label() }}</span>
        <span class="faint" style="font-size:.8rem{{ $config['checked'] ? '' : ';display:none' }}" x-show="checked" x-text="checked">{{ $config['checked'] }}</span>
        @if ($config['load'])
            <button type="button" class="btn btn-sm btn-ghost" x-cloak x-on:click="refresh()" :disabled="busy || loading" :aria-busy="loading ? 'true' : 'false'"><x-icon name="refresh" />{{ __('Check now') }}</button>
        @endif
    </div>
    @endif

    @if (! $machine->hasBmc())
        <p class="muted" style="margin:0">{{ __('This machine has no power control. Switch it on and off by hand.') }}</p>
        @if ($drivers->isEmpty())@include('admin.machines.partials.power-addon', ['mayMarketplace' => $mayMarketplace])@endif
    @elseif ($noDriver)
        @include('admin.machines.partials.power-addon', ['mayMarketplace' => $mayMarketplace])
    @elseif ($actions === [])
        <p class="muted" style="margin:0">{{ __('No power buttons yet. Run Test connection on the machine, and trust its certificate when asked.') }}</p>
    @else
        <form method="POST" action="{{ route('admin.machines.power', [$machine, $actions[0]->value]) }}" x-on:submit="power($event)">
            @csrf
            <div style="display:flex;flex-wrap:wrap;gap:6px" role="group" aria-label="{{ __('Power buttons for :machine', ['machine' => $machine->name]) }}">
                @foreach ($actions as $action)
                    <button
                        type="submit"
                        formaction="{{ route('admin.machines.power', [$machine, $action->value]) }}"
                        data-action="{{ $action->value }}"
                        @isset($confirm[$action->value]) data-confirm="{{ $confirm[$action->value] }}" @endisset
                        class="btn btn-sm @if ($action->isForced()) btn-danger @elseif ($action === \App\Enums\PowerAction::On) btn-primary @endif"
                        x-show="has('{{ $action->value }}')"
                        :disabled="busy"
                    ><x-icon :name="in_array($action->value, ['restart', 'reset'], true) ? 'refresh' : 'power'" />{{ $action->label() }}</button>
                @endforeach
            </div>
        </form>
        <p class="muted" style="margin:0;font-size:.85rem" x-show="has('shutdown') && has('off')">{{ __('Shut down asks the system to stop cleanly. Force off cuts the power at once: use it only when Shut down does not work.') }}</p>
    @endif
    @if ($bootDevices !== [])
        <form method="POST" action="{{ route('admin.machines.boot', $machine) }}" style="display:flex;flex-wrap:wrap;align-items:end;gap:8px">
            @csrf
            <div class="field" style="margin:0">
                <label for="{{ $id }}-boot">{{ __('Next boot from') }}</label>
                <select id="{{ $id }}-boot" name="device" class="select">
                    @foreach ($bootDevices as $device)<option value="{{ $device->value }}">{{ $device->label() }}</option>@endforeach
                </select>
            </div>
            <button class="btn btn-sm" type="submit">{{ __('Set') }}</button>
            <p class="help" style="margin:0;flex-basis:100%">{{ __('Only for the next start. Restart the server to use it.') }}</p>
        </form>
    @endif
    <p class="muted" style="margin:0;font-size:.9rem" role="status" aria-live="polite"><span x-text="message"></span></p>
</div>
