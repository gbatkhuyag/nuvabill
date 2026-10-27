<?php

namespace App\Provisioning\Hardware\Drivers;

use App\Models\Machine;
use App\Provisioning\Hardware\BmcException;
use App\Provisioning\Hardware\HealthReading;

/**
 * A power driver that can read the machine's hardware health from its BMC, such as the overall
 * status, temperatures, fans and power supplies. Called through PowerControl::health(), which
 * caches the answer, so pages never flood the BMC.
 */
interface ReadsHealth
{
    /**
     * At most 50 readings; texts must be cleaned (no tags, no login, short). Each reading should
     * have a key in no language ("part:id"), and a part that could not be read this time a
     * "skipped:part" reading, so health checks never take an unread fault as fixed.
     *
     * @return list<HealthReading>
     *
     * @throws BmcException
     */
    public function health(Machine $machine): array;
}
