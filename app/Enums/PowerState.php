<?php

namespace App\Enums;

/**
 * The last known power state of a dedicated server machine, as its BMC reports it.
 */
enum PowerState: string
{
    case On = 'on';
    case Off = 'off';
    case Changing = 'changing';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::On => __('On'),
            self::Off => __('Off'),
            self::Changing => __('Turning on or off'),
            self::Unknown => __('Unknown'),
        };
    }

    /**
     * The state the client server panel shows: running, stopped or unknown (HasPanelState).
     */
    public function panelState(): string
    {
        return match ($this) {
            self::On => 'running',
            self::Off => 'stopped',
            self::Changing, self::Unknown => 'unknown',
        };
    }

    /**
     * Read a Redfish PowerState value: On, Off, PoweringOn or PoweringOff. Anything else is unknown.
     */
    public static function fromRedfish(?string $value): self
    {
        return match ($value) {
            'On' => self::On,
            'Off' => self::Off,
            'PoweringOn', 'PoweringOff' => self::Changing,
            default => self::Unknown,
        };
    }
}
