<?php

namespace App\Events;

use App\Models\Machine;
use App\Models\Service;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The machine of a dedicated server was suspended with its service, for example for an unpaid
 * invoice. An add-on may block its network port now. Core switches the power as the product says
 * when it has power control.
 *
 * Sent after the change is saved. A listener that fails is reported and never stops the change.
 */
class DedicatedServerSuspended
{
    use Dispatchable;

    public function __construct(public Machine $machine, public Service $service) {}
}
