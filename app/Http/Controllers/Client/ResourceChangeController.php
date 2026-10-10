<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LogicException;

/**
 * Client area → a service → Resources: add or remove extras (more RAM, IP addresses and so on) in
 * the middle of a billing period, see the price for the days left, and stop a change that waits.
 *
 * Every action checks that the service belongs to the signed-in client and that resource changes
 * are switched on, before anything else.
 *
 * Skeleton: the routes and public signatures are final; the bodies come with the mid-cycle changes.
 * A body may swap Request for its own form request class.
 */
class ResourceChangeController extends Controller
{
    /**
     * The review page (it works without JavaScript), or with a JSON request the state of the latest
     * change on the server as {state, text}, which the resources card checks while it runs.
     */
    public function show(Request $request, Service $service): View|JsonResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * The price of the chosen steps for the days left, as JSON. Maths only: nothing is saved and no
     * server is called.
     */
    public function quote(Request $request, Service $service): JsonResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Start the change: an invoice for the difference, a change now, or a change on the renewal date.
     */
    public function store(Request $request, Service $service): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Stop the change that waits for payment or for the renewal date.
     */
    public function destroy(Request $request, Service $service): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }
}
