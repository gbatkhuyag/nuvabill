<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Admin → Hardware → a machine → BMC: test the connection to the machine's BMC, and trust the
 * certificate it showed, so its login is only ever sent to that BMC.
 *
 * Skeleton: the routes and public signatures are final; the bodies come with the hardware admin.
 */
class MachineBmcController extends Controller
{
    public function test(Machine $machine): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Trust the certificate the last test saw. It must still match when this runs.
     */
    public function trust(Request $request, Machine $machine): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }
}
