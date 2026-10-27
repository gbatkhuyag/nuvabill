<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BootDevice;
use App\Enums\PowerAction;
use App\Enums\PowerState;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Machine;
use App\Provisioning\Hardware\PowerControl;
use App\Provisioning\Hardware\PowerResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin → Hardware → a machine → Power: the power state from the BMC, power actions, the next boot
 * device and the hardware health. Every call goes through PowerControl, so the machine's power
 * lock, cool-down, state cache, activity log and power history apply to staff exactly as they do to
 * clients.
 */
class MachinePowerController extends Controller
{
    /**
     * The power state as JSON {state, label, checked_at, checked, actions}. Never cached.
     */
    public function show(Machine $machine): JsonResponse
    {
        $power = app(PowerControl::class);
        $state = $power->state($machine);
        $checkedAt = $machine->power_checked_at;

        return $this->json([
            'state' => $state->value,
            'label' => $state->label(),
            'checked_at' => $checkedAt?->toIso8601String(),
            'checked' => $checkedAt !== null ? __('Checked :time', ['time' => $checkedAt->diffForHumans()]) : null,
            'actions' => array_map(fn (PowerAction $action): string => $action->value, $power->actions($machine)),
        ]);
    }

    /**
     * Send one power action to the machine's BMC.
     */
    public function run(Request $request, Machine $machine, PowerAction $action): JsonResponse|RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $result = app(PowerControl::class)->run($machine, $action, $admin);

        if ($request->expectsJson()) {
            return $this->json(self::answer($result), $result->ok ? 200 : 422);
        }

        return back()->with($result->ok ? 'status' : 'error', $result->message);
    }

    /**
     * Choose where the machine starts from on its next boot only.
     */
    public function boot(Request $request, Machine $machine): RedirectResponse
    {
        $data = $request->validate([
            'device' => ['required', 'string', Rule::in(array_column(BootDevice::cases(), 'value'))],
        ]);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $result = app(PowerControl::class)->setBootDevice($machine, BootDevice::from($data['device']), $admin);

        return back()->with($result->ok ? 'status' : 'error', $result->message);
    }

    /**
     * Read the hardware health and power use from the BMC again.
     */
    public function health(Machine $machine): RedirectResponse
    {
        $report = app(PowerControl::class)->health($machine, fresh: true);

        if ($report === null) {
            return back()->with('error', __('Not supported by this server.'));
        }

        return back()->with($report->error === null ? 'status' : 'error', $report->error ?? __('Health read just now.'));
    }

    /**
     * What the power buttons need to know after an action.
     *
     * @return array{ok: bool, message: string, pending: ?string, state: string, label: string, busy: bool}
     */
    public static function answer(PowerResult $result): array
    {
        $state = $result->pending !== null ? PowerState::Changing : $result->state;

        return [
            'ok' => $result->ok,
            'message' => $result->message,
            'pending' => $result->pending?->value,
            'state' => $state->value,
            'label' => $state->label(),
            'busy' => $result->busy,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
