<?php

namespace App\Events;

use App\Models\Machine;
use App\Models\Service;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A machine left its dedicated server: the service was terminated, cancelled or marked as fraud,
 * its order was cancelled, or it moved to another machine. When $needsWipe is true the machine was
 * set up or used and now waits for staff to wipe its disks; an add-on may close its network port
 * now. When it is false the machine was only reserved and is on sale again.
 *
 * $service is null when the service is gone. Sent after the change is saved. A listener that fails
 * is reported and never stops the change.
 */
class DedicatedServerReleased
{
    use Dispatchable;

    /**
     * @param  string  $reason  Plain English for logs, such as "terminated".
     */
    public function __construct(public Machine $machine, public ?Service $service, public bool $needsWipe, public string $reason) {}
}
