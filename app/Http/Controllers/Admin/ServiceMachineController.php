<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Admin → a service → Dedicated server: give the service a machine, mark the installed server as
 * ready (which sets the service up), or move the service to another machine.
 *
 * Skeleton: the routes and public signatures are final; the bodies come with the hardware admin.
 */
class ServiceMachineController extends Controller
{
    /**
     * Give the service a free machine.
     */
    public function assign(Request $request, Service $service): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Staff installed the system: save the login details and set the service up.
     */
    public function ready(Request $request, Service $service): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Move the service to another free machine; the old one must be wiped before it is sold again.
     */
    public function replace(Request $request, Service $service): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }
}
