<?php

namespace App\Enums;

/**
 * How Nuvabill switches a machine's power through its BMC (iDRAC, iLO, IPMI and so on). A package
 * may add its own type, so machines.bmc_type is a plain string: read it with Machine::bmcType().
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
