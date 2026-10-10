<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LogicException;

/**
 * Admin → Hardware: the dedicated servers in your own rack. Free machines are the stock of the
 * products they are linked to. A machine's status only moves through MachineInventory, so a
 * machine is never sold again before its disks are wiped.
 *
 * Skeleton: the routes and public signatures are final; the bodies come with the hardware admin.
 * A body may swap Request for its own form request class.
 */
class MachineController extends Controller
{
    /**
     * The machine list with filters (status, product, location, search) and stock per product.
     */
    public function index(Request $request): View
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * One machine: details, IP addresses, the service on it, BMC status, power and history.
     */
    public function show(Machine $machine): View
    {
        throw new LogicException('Not built yet.');
    }

    public function create(): View
    {
        throw new LogicException('Not built yet.');
    }

    public function store(Request $request): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    public function edit(Machine $machine): View
    {
        throw new LogicException('Not built yet.');
    }

    public function update(Request $request, Machine $machine): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Delete a machine that never held a service; any other machine can only be retired.
     */
    public function destroy(Machine $machine): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Move a machine to available, maintenance or retired, when its status allows it.
     */
    public function status(Request $request, Machine $machine): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Staff confirm the disks of a machine were wiped, so it can be sold again.
     */
    public function wiped(Request $request, Machine $machine): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Save the hardware settings (the systems clients may ask for, ticket department, wait times).
     */
    public function settings(Request $request): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }
}
