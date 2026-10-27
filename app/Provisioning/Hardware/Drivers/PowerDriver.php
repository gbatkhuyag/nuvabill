<?php

namespace App\Provisioning\Hardware\Drivers;

use App\Enums\PowerAction;
use App\Enums\PowerState;
use App\Models\Machine;
use App\Provisioning\Hardware\BmcException;
use App\Provisioning\Hardware\BmcTestResult;

/**
 * One way to switch a machine's power through its BMC, such as Redfish or IPMI. Core ships no
 * driver: an add-on registers its own in PowerDrivers when it boots (the Dedicated Server Power
 * add-on registers Redfish and IPMI). Without one, staff switch the power by hand.
 *
 * Only PowerControl calls drivers, so locks, caching, cool-downs, the activity log and the power
 * history always apply. A driver sends requests only to the machine's checked BMC address
 * (BmcAddress), never follows a redirect, runs programs with an argument list (never a shell), and
 * never puts the BMC login or a raw error into a message or log.
 *
 * A driver may also implement ChoosesBootDevice, ReadsHealth and ReadsPowerUsage.
 */
interface PowerDriver
{
    /**
     * The value of machines.bmc_type this driver serves.
     */
    public function type(): string;

    /**
     * The name staff see in the machine form.
     */
    public function label(): string;

    /**
     * Whether this site can use the driver now; only available drivers are offered in the form.
     */
    public function available(): bool;

    /**
     * Test the connection and read what power control needs. Never throws.
     */
    public function test(Machine $machine): BmcTestResult;

    /**
     * @throws BmcException
     */
    public function powerState(Machine $machine): PowerState;

    /**
     * The power actions the machine's BMC offers.
     *
     * @return list<PowerAction>
     */
    public function supportedActions(Machine $machine): array;

    /**
     * @throws BmcException
     */
    public function run(Machine $machine, PowerAction $action): void;
}
