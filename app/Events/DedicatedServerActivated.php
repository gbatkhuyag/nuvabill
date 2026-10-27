<?php

namespace App\Events;

use App\Models\Machine;
use App\Models\Service;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A machine is now in use by a dedicated server: the server was marked ready, staff assigned the
 * machine to a live service, or the service moved to it (Replace machine). An add-on may open its
 * network port now. A suspended service that becomes active again sends DedicatedServerUnsuspended
 * instead.
 *
 * Sent after the change is saved. A listener that fails is reported and never stops the change.
 */
class DedicatedServerActivated
{
    use Dispatchable;

    public function __construct(public Machine $machine, public Service $service) {}
}
