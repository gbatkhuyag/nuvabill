<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Client area → a service → server panel tabs: reads of one section (JSON) and actions in a section,
 * such as setting reverse DNS. Every call goes through Provisioner and the panel gate, so the owner,
 * status, product switches, input rules, limits and the service lock are checked before a module
 * sees it. Answers are never cached and never pass this page's address on.
 *
 * Skeleton: the routes and public signatures are final; the bodies come with the panel gate.
 */
class ServicePanelController extends Controller
{
    /**
     * One section's data as JSON: {ok, section, data, fetched_at, live}, or {ok: false, message}
     * with the gate's status code.
     */
    public function show(Request $request, Service $service, string $section, Provisioner $provisioner): JsonResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Run one action of a section. A JSON request gets {ok, message, data, pending, state, secret};
     * a plain form post goes back to the page with a message and never with a secret.
     */
    public function action(Request $request, Service $service, string $section, string $action, Provisioner $provisioner): JsonResponse|RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }
}
