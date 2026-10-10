<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Admin → a service → Client access: test whether the client can sign in to their panel and use
 * the server panel, and link a service to an account that already exists on the server.
 *
 * Skeleton: the routes and public signatures are final; the bodies come with the panel gate.
 */
class ServiceAccessController extends Controller
{
    /**
     * Run the module's sign-in checks and show each result.
     */
    public function check(Service $service, Provisioner $provisioner): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Link the service to an existing account on the server, with the fields the module asks for.
     */
    public function link(Request $request, Service $service, Provisioner $provisioner): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }
}
