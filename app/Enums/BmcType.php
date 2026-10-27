<?php

namespace App\Enums;

/**
 * The power control types Nuvabill knows by name, so a machine keeps a readable type while no
 * driver for it is installed. The drivers themselves come from an add-on (Dedicated Server Power
 * adds Redfish and IPMI) and may add other types, so machines.bmc_type is a plain string: read it
 * with Machine::bmcType().
 */
enum BmcType: string
{
    case Redfish = 'redfish';
    case Ipmi = 'ipmi';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Redfish => __('Redfish (HTTPS)'),
            self::Ipmi => __('IPMI'),
            self::None => __('No power control'),
        };
    }
}
