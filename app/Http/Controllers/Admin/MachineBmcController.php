<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Machine;
use App\Provisioning\Hardware\BmcException;
use App\Provisioning\Hardware\PowerControl;
use App\Provisioning\Hardware\Tls\PeerCertificate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin → Hardware → a machine → BMC: test the connection to the machine's BMC, and trust the
 * certificate it showed, so its login is only ever sent to that BMC.
 *
 * What a test found that needs a staff choice is kept in the session for five minutes: the
 * certificate to compare with the BMC's own page, or the systems of a BMC with several. Trusting
 * and choosing take only those values, never free text, and PowerControl checks the certificate
 * once more when it is trusted.
 */
class MachineBmcController extends Controller
{
    /**
     * Test the BMC. With "system" (one of the systems the last test listed), that system is chosen
     * first.
     */
    public function test(Machine $machine): RedirectResponse
    {
        $request = request();
        $chosen = $request->input('system');

        if ($chosen !== null) {
            $listed = MachineController::sessionValue(MachineController::SYSTEMS_SESSION, $machine)['paths'] ?? [];

            if (! is_string($chosen) || ! in_array($chosen, $listed, true)) {
                return $this->back($machine, $request)->with('error', __('Run Test connection again, then choose a system from its list.'));
            }

            $machine->forceFill(['bmc_system_path' => $chosen])->save();
        }

        $result = app(PowerControl::class)->test($machine);
        $session = $request->session();
        $session->forget([MachineController::TRUST_SESSION.$machine->getKey(), MachineController::SYSTEMS_SESSION.$machine->getKey()]);

        if ($result->needsTrust && $result->certificate !== null) {
            $session->put(MachineController::TRUST_SESSION.$machine->getKey(), $result->certificate->toArray() + ['at' => now()->getTimestamp()]);

            return $this->back($machine, $request)->with('warning', $result->message);
        }

        if ($result->needsSystemChoice()) {
            $session->put(MachineController::SYSTEMS_SESSION.$machine->getKey(), ['paths' => $result->systems, 'at' => now()->getTimestamp()]);

            return $this->back($machine, $request)->with('warning', $result->message);
        }

        return $this->back($machine, $request)->with($result->ok ? 'status' : 'error', $result->message);
    }

    /**
     * Trust the certificate the last test saw. It must still match when this runs.
     */
    public function trust(Request $request, Machine $machine): RedirectResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'max:200'],
            'pasted_fingerprint' => ['nullable', 'string', 'max:200', 'regex:/^\s*([0-9A-Fa-f]{2}[:\s]?){31}[0-9A-Fa-f]{2}\s*$/'],
        ], [
            'pasted_fingerprint.regex' => __('Paste the SHA-256 fingerprint as shown on the BMC, for example AB:CD:… with 32 pairs.'),
        ]);

        $seen = MachineController::sessionValue(MachineController::TRUST_SESSION, $machine);
        $fingerprint = is_string($seen['fingerprint'] ?? null) ? $seen['fingerprint'] : '';

        if ($fingerprint === '' || ! PeerCertificate::sameFingerprint($fingerprint, $data['fingerprint'])) {
            return $this->back($machine, $request)->with('error', __('Run Test connection again, then trust the certificate it shows.'));
        }

        /** @var Admin $admin */
        $admin = $request->user('admin');

        try {
            app(PowerControl::class)->trust($machine, $fingerprint, $data['pasted_fingerprint'] ?? null, $admin);
        } catch (BmcException $exception) {
            return $this->back($machine, $request)->with('error', $exception->getMessage());
        }

        $request->session()->forget(MachineController::TRUST_SESSION.$machine->getKey());

        return $this->back($machine, $request)->with('status', __('Certificate trusted. Press Test connection again to read the power control details.'));
    }

    /**
     * Back to the machine's page, or to its form for staff who may not see machine pages.
     */
    private function back(Machine $machine, Request $request): RedirectResponse
    {
        return $request->user('admin')?->hasPermission('hardware.power')
            ? redirect()->route('admin.machines.show', $machine)
            : redirect()->route('admin.machines.edit', $machine);
    }
}
