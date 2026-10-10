<?php

namespace App\Enums;

/**
 * A power action on a dedicated server machine, sent to its BMC.
 */
enum PowerAction: string
{
    case On = 'on';
    case Shutdown = 'shutdown';
    case Restart = 'restart';
    case Reset = 'reset';
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::On => __('Power on'),
            self::Shutdown => __('Shut down'),
            self::Restart => __('Restart'),
            self::Reset => __('Force restart'),
            self::Off => __('Force off'),
        };
    }

    /**
     * Whether the action cuts the power without asking the system first, so unsaved data is lost.
     */
    public function isForced(): bool
    {
        return in_array($this, [self::Reset, self::Off], true);
    }

    /**
     * The Redfish ComputerSystem.Reset type. A shut down may fall back to PushPowerButton when the
     * BMC lacks GracefulShutdown; the driver decides that.
     */
    public function redfishResetType(): string
    {
        return match ($this) {
            self::On => 'On',
            self::Shutdown => 'GracefulShutdown',
            self::Restart => 'GracefulRestart',
            self::Reset => 'ForceRestart',
            self::Off => 'ForceOff',
        };
    }

    /**
     * The "ipmitool chassis power" verb, or null when IPMI has no such action (a graceful restart).
     */
    public function ipmiVerb(): ?string
    {
        return match ($this) {
            self::On => 'on',
            self::Shutdown => 'soft',
            self::Restart => null,
            self::Reset => 'reset',
            self::Off => 'off',
        };
    }
}
