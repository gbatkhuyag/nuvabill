<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Machine;
use App\Models\Service;
use App\Provisioning\Hardware\InventoryException;
use App\Provisioning\Hardware\MachineInventory;
use App\Provisioning\Hardware\ServerSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin → a service → Dedicated server: give the service a machine, mark the installed server as
 * ready (which sets the service up), or move the service to another machine.
 *
 * Only services of products sold from Admin → Hardware have a machine. Every change goes through
 * MachineInventory, which locks the machines and checks each status move, and the root password
 * typed for "Server is ready" is saved encrypted on the service only: never in the session, a log
 * or an email.
 */
class ServiceMachineController extends Controller
{
    /**
     * Give the service a free machine.
     */
    public function assign(Request $request, Service $service): RedirectResponse
    {
        if (($refused = $this->refuse($service)) !== null) {
            return $refused;
        }

        $data = $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
        ]);
        $machine = Machine::query()->findOrFail((int) $data['machine_id']);

        try {
            app(MachineInventory::class)->assign($service, $machine, $this->admin($request));
        } catch (InventoryException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $flash = back()->with('status', __(':machine is assigned to this service.', ['machine' => $machine->name]));

        if (! $machine->products()->whereKey($service->product_id)->exists()) {
            $flash->with('warning', __(':machine is not linked to :product. Link it under Hardware, so plan changes and stock stay right.', [
                'machine' => $machine->name,
                'product' => $service->product->name,
            ]));
        }

        return $flash;
    }

    /**
     * Staff installed the system ("Server is ready"): save the login details and set the service up.
     */
    public function ready(Request $request, Service $service): RedirectResponse
    {
        if (($refused = $this->refuse($service)) !== null) {
            return $refused;
        }

        $data = $request->validate([
            'hostname' => ['nullable', 'string', 'max:190', 'regex:'.ServerSetup::HOSTNAME_PATTERN],
            'login_name' => ['required', 'string', 'max:32', 'regex:'.ServerSetup::LOGIN_PATTERN],
            'root_password' => ['nullable', 'string', 'min:'.ServerSetup::PASSWORD_MIN, 'max:'.ServerSetup::PASSWORD_MAX],
        ], [
            'hostname.regex' => __('Enter a host name such as server1.example.com.'),
            'login_name.regex' => __('Use a login name such as root or admin: letters, numbers, _ and -, starting with a letter or _.'),
        ], [
            'hostname' => __('Host name'),
            'login_name' => __('Login name'),
            'root_password' => __('Root password'),
        ]);

        if ($service->status !== ServiceStatus::Pending) {
            return back()->with('error', __('Only a pending service can be marked ready.'));
        }

        // The same code path an automatic install (an add-on) uses when it is done.
        try {
            $result = app(ServerSetup::class)->ready(
                $service,
                $data['hostname'] ?? null,
                (string) $data['login_name'],
                $data['root_password'] ?? null,
                $this->admin($request),
            );
        } catch (InventoryException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with($result->success ? 'status' : 'error', $result->message);
    }

    /**
     * Move the service to another free machine; the old one must be wiped before it is sold again.
     */
    public function replace(Request $request, Service $service): RedirectResponse
    {
        if (($refused = $this->refuse($service)) !== null) {
            return $refused;
        }

        $data = $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => __('Tick the box to confirm the move.'),
        ]);
        $machine = Machine::query()->findOrFail((int) $data['machine_id']);

        try {
            app(MachineInventory::class)->replace($service, $machine, $this->admin($request));
        } catch (InventoryException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('The service now runs on :machine. The old machine waits for a wipe.', ['machine' => $machine->name]));
    }

    /**
     * A service whose product is not sold from Admin → Hardware has no machine.
     */
    private function refuse(Service $service): ?RedirectResponse
    {
        if (! $service->product?->uses_machines) {
            return back()->with('error', __('This service is not a dedicated server from Hardware.'));
        }

        return null;
    }

    private function admin(Request $request): Admin
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
