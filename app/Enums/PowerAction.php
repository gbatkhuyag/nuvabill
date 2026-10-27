<?php

namespace App\Enums;

/**
 * A power action on a dedicated server machine, sent to its BMC by a power driver. How an action
 * is sent (a Redfish reset type, an ipmitool verb) is up to the driver.
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
}
