<?php

namespace App\Provisioning\Hardware\Drivers;

use App\Enums\BmcType;
use App\Models\Machine;

/**
 * The power drivers this site knows, by machines.bmc_type. Core registers none: an add-on adds its
 * drivers in its boot(), for example app(PowerDrivers::class)->register(new RedfishDriver(...)).
 * A machine whose type no driver knows has no power control, and staff switch it by hand.
 */
final class PowerDrivers
{
    /**
     * @var array<string, PowerDriver>
     */
    private array $drivers = [];

    public function register(PowerDriver $driver): void
    {
        if ($driver->type() === BmcType::None->value || preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $driver->type()) !== 1) {
            return;
        }

        $this->drivers[$driver->type()] = $driver;
    }

    public function get(string $type): ?PowerDriver
    {
        return $this->drivers[$type] ?? null;
    }

    /**
     * The driver for the machine's BMC, or null when it has none.
     */
    public function for(Machine $machine): ?PowerDriver
    {
        return $machine->hasBmc() ? $this->get((string) $machine->bmc_type) : null;
    }

    /**
     * Whether no driver is installed, so no machine can have its power switched by Nuvabill.
     */
    public function isEmpty(): bool
    {
        return $this->drivers === [];
    }

    /**
     * The types staff may pick in the machine form, as type => label. "No power control" is
     * always allowed and not listed here.
     *
     * @return array<string, string>
     */
    public function available(): array
    {
        $types = [];

        foreach ($this->drivers as $type => $driver) {
            if ($driver->available()) {
                $types[$type] = $driver->label();
            }
        }

        return $types;
    }
}
