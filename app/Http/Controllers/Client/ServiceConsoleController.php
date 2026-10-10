<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Provisioning\ConsoleTickets;
use App\Provisioning\Provisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

/**
 * Client area → a service → Console: the server's screen in the browser. Opening it makes a
 * single-use ticket; the console page shows no secret, and its script redeems the ticket once for
 * the connection details. The console page alone may connect to the console host.
 *
 * Skeleton: the routes and public signatures are final; the bodies come with the console tickets.
 */
class ServiceConsoleController extends Controller
{
    /**
     * Check the console is allowed, ask the module for a session, and send the browser to the
     * console page with a ticket (303).
     */
    public function open(Request $request, Service $service, Provisioner $provisioner): RedirectResponse|JsonResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * The console page for a ticket of this client, service and browser session, or a page that
     * says the link has expired. Never cached or indexed.
     */
    public function show(Request $request, Service $service, ConsoleTickets $tickets): Response
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Hand out the connection details of a ticket once, as JSON. A second try gets 410.
     */
    public function redeem(Request $request, Service $service, ConsoleTickets $tickets): JsonResponse
    {
        throw new LogicException('Not built yet.');
    }
}
