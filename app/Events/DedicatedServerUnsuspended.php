<?php

namespace App\Events;

use App\Models\Machine;
use App\Models\Service;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The machine of a dedicated server is in use again after its service was suspended. An add-on may
 * open its network port again now.
 *
 * Sent after the change is saved. A listener that fails is reported and never stops the change.
 */
class DedicatedServerUnsuspended
{
    use Dispatchable;

    public function __construct(public Machine $machine, public Service $service) {}
}
