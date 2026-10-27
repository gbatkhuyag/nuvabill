<?php

namespace App\Provisioning\Hardware\Drivers;

use App\Models\Machine;
use App\Provisioning\Hardware\BmcException;

/**
 * A power driver that can read how much power the machine uses now, in watts. Called through
 * PowerControl::health(), which caches the answer with the health readings.
 */
interface ReadsPowerUsage
{
    /**
     * Watts used now, or null when the BMC does not say.
     *
     * @throws BmcException
     */
    public function powerUsage(Machine $machine): ?int;
}
