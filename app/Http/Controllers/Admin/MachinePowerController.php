<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PowerAction;
use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Admin → Hardware → a machine → Power: the power state from the BMC, and power actions.
 *
 * Skeleton: the routes and public signatures are final; the bodies come with the hardware admin.
 */
class MachinePowerController extends Controller
{
    /**
     * The power state as JSON {state, label, checked_at, actions}. Never cached.
     */
    public function show(Machine $machine): JsonResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Send one power action to the machine's BMC.
     */
    public function run(Request $request, Machine $machine, PowerAction $action): JsonResponse|RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }
}
