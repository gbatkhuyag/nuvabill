<?php

namespace App\Contracts;

use App\Models\Service;

/**
 * A server module whose setup someone finishes after create() returned, for example staff who
 * install the OS by hand. While setupPending() is true the service stays Pending, no welcome email
 * is sent and nothing is activated; the next create() (after the work is done) activates it.
 */
interface FinishesSetupLater
{
    /**
     * Whether the setup of this service still waits for someone. Core treats a throw as true.
     */
    public function setupPending(Service $service): bool;
}
