<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Admin → a service → Resources: staff change a service's extras (with or without an invoice),
 * stop a waiting change, and set, check or retry the extras on the server.
 *
 * Skeleton: the routes and public signatures are final; the bodies come with the mid-cycle changes.
 * A body may swap Request for its own form request class.
 */
class ServiceResourceController extends Controller
{
    /**
     * The price of a staff change for the days left, as JSON. Maths only: nothing is saved.
     */
    public function quote(Request $request, Service $service): JsonResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Start a staff change: invoice the difference, or change now without charging or crediting.
     */
    public function change(Request $request, Service $service): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Stop the waiting change.
     */
    public function cancel(Service $service): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Set the service's extras on its server now.
     */
    public function apply(Service $service): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Compare the server with the plan plus the extras, without changing anything.
     */
    public function check(Service $service): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Try a failed server change again.
     */
    public function retry(Service $service): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Mark a change staff finished by hand on the server as done.
     */
    public function done(Request $request, Service $service): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }
}
