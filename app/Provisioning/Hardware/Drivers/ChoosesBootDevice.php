<?php

namespace App\Provisioning\Hardware\Drivers;

use App\Enums\BootDevice;
use App\Models\Machine;
use App\Provisioning\Hardware\BmcException;

/**
 * A power driver that can choose where the machine starts from on its next boot only, for example
 * the network (PXE) for an automatic install. Called through PowerControl::setBootDevice(), which
 * takes the machine's power lock and writes the power history.
 */
interface ChoosesBootDevice
{
    /**
     * The boot devices this machine's BMC can set.
     *
     * @return list<BootDevice>
     */
    public function bootDevices(Machine $machine): array;

    /**
     * Start from this device on the next boot only. It does not restart the machine.
     *
     * @throws BmcException
     */
    public function setBootDevice(Machine $machine, BootDevice $device): void;
}
