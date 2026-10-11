<?php

namespace App\Http\Controllers\Admin;

use App\Billing\ResourceChanges;
use App\Billing\ResourceOptions;
use App\Http\Controllers\Controller;
use App\Models\ResourceChange;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Admin → a service → Resources: staff change a service's extras (with or without an invoice),
 * stop a waiting change, and set, check or retry the extras on the server.
 *
 * The routes need services.manage; every one that changes something or calls the server is locked
 * in the demo. Server messages shown here were cleaned by ResourceChanges and never reach clients.
 */
class ServiceResourceController extends Controller
{
    /**
     * The most a staff renewal price may be, in major units.
     */
    private const MAX_PRICE = 1000000;

    public function __construct(private ResourceChanges $changes, private Provisioner $provisioner) {}

    /**
     * The price of a staff change for the days left, as JSON. Maths only: nothing is saved.
     */
    public function quote(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate($this->rules());
        $service->load('product', 'client', 'addons', 'resources.option');
        $blocked = $this->changes->blockedReason($service, staff: true);

        if ($blocked !== null) {
            return response()->json(['message' => $blocked], 422)->header('Cache-Control', 'no-store, private');
        }

        $quote = $this->changes->quote($service, $data['steps'], staff: true, amounts: $this->amounts($data));

        return response()->json($this->changes->present($service, $quote, free: ! (bool) ($data['charge'] ?? true)))
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Start a staff change: invoice the difference, or change now without charging or crediting.
     */
    public function change(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'charge' => ['required', 'boolean'],
            'sets_server' => ['sometimes', 'boolean'],
        ]);

        try {
            $change = $this->changes->start(
                $service->load('product', 'client'),
                $data['steps'],
                $request->user('admin'),
                (bool) $data['charge'],
                $this->amounts($data),
                (bool) ($data['sets_server'] ?? true),
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $this->refusalMessage($exception));
        }

        if ($change->status === ResourceChange::STATUS_CANCELLED) {
            return back()->with('error', __('The change stopped. The activity log says why.'));
        }

        // Applied first: the client's wallet credit may have paid the whole invoice already.
        return back()->with('status', match (true) {
            $change->status === ResourceChange::STATUS_APPLIED && in_array($change->server_state, [ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_APPLYING, ResourceChange::SERVER_CHECKING], true) => __('The extras were changed. The server is set in the background.'),
            $change->status === ResourceChange::STATUS_APPLIED && $change->server_state === ResourceChange::SERVER_MANUAL => __('The extras were changed. Set them on the server by hand, then press Mark done.'),
            $change->status === ResourceChange::STATUS_APPLIED => __('The extras were changed.'),
            $change->mode === ResourceChange::MODE_INVOICE => __('The extras change once the client pays invoice :number.', ['number' => $change->invoice?->displayNumber()]),
            $change->mode === ResourceChange::MODE_RENEWAL => __('The extras change on the next renewal date.'),
            default => __('The extras were changed.'),
        });
    }

    /**
     * Stop the waiting change.
     */
    public function cancel(Service $service): RedirectResponse
    {
        $pending = $this->changes->pendingFor($service);

        try {
            if ($pending !== null) {
                $this->changes->cancel($pending);
            }
        } catch (RuntimeException $exception) {
            return back()->with('error', $this->refusalMessage($exception));
        }

        return back()->with('status', __('The resource change was stopped.'));
    }

    /**
     * Set the service's extras on its server now.
     */
    public function apply(Service $service): RedirectResponse
    {
        $result = $this->changes->applyNow($service->load('product', 'client', 'server'));

        return match (true) {
            ! $result->success => back()->with('error', ($result->data['busy'] ?? false) === true ? $result->message : __('The server did not take the extras: :message', ['message' => $result->message])),
            ($result->data['manual'] ?? false) === true => back()->with('warning', __('No server module sets these extras. Set them on the server by hand, then press Mark done.')),
            ($result->data['pending'] ?? false) === true => back()->with('status', __('The server is applying the extras. It is checked again in a few minutes.')),
            default => back()->with('status', __('The extras were set on the server.')),
        };
    }

    /**
     * Compare the server with the plan plus the extras, without changing anything.
     */
    public function check(Service $service): RedirectResponse
    {
        $check = $this->provisioner->checkResources($service->load('product', 'client', 'server'));

        if ($check === null) {
            return back()->with('error', __('The server could not be checked. Its module does not sell extras, or the server did not answer.'));
        }

        if (! $check->matches()) {
            Activity::log('service.resources_unverified', "Staff checked the extras of service #{$service->id}: the server does not match (".implode('; ', array_slice($check->problems, 0, 5)).')', $service);
        }

        return back()->with('resource_check', [
            'expected' => $check->expected,
            'live' => $check->live,
            'problems' => array_slice($check->problems, 0, 20),
            'matches' => $check->matches(),
        ]);
    }

    /**
     * Try a failed server change again.
     */
    public function retry(Service $service): RedirectResponse
    {
        $change = $this->openChange($service, [ResourceChange::SERVER_FAILED, ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_CHECKING]);

        if ($change === null) {
            return back()->with('error', __('There is no server change to try again.'));
        }

        $this->changes->retryNow($change, auth('admin')->user());

        return back()->with('status', __('The extras are sent to the server again.'));
    }

    /**
     * Mark a change staff finished by hand on the server as done.
     */
    public function done(Request $request, Service $service): RedirectResponse
    {
        $change = $this->openChange($service, [ResourceChange::SERVER_MANUAL, ResourceChange::SERVER_FAILED, ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_APPLYING, ResourceChange::SERVER_CHECKING]);

        if ($change === null) {
            return back()->with('error', __('There is no server change to mark as done.'));
        }

        $this->changes->markDone($change, $request->user('admin'));

        return back()->with('status', __('The change is marked as done. The client got an email.'));
    }

    /**
     * Steps per kind, an optional renewal price per kind, and the quote's charge switch.
     *
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'steps' => ['required', 'array', 'max:'.ResourceOptions::MAX_KINDS],
            'steps.*' => ['required', 'integer', 'between:0,'.ResourceOptions::MAX_STEPS],
            'amounts' => ['sometimes', 'array', 'max:'.ResourceOptions::MAX_KINDS],
            'amounts.*' => ['nullable', 'numeric', 'min:0', 'max:'.self::MAX_PRICE],
            'charge' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The renewal prices staff typed, in minor units; empty fields keep the worked-out price.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    private function amounts(array $data): array
    {
        $amounts = [];

        foreach ((array) ($data['amounts'] ?? []) as $kind => $amount) {
            if (is_string($kind) && $amount !== null && $amount !== '') {
                $amounts[$kind] = Money::toMinor($amount);
            }
        }

        return $amounts;
    }

    /**
     * The newest applied change of the service whose server side is in one of these states.
     *
     * @param  list<string>  $states
     */
    private function openChange(Service $service, array $states): ?ResourceChange
    {
        return ResourceChange::query()
            ->where('service_id', $service->id)
            ->where('status', ResourceChange::STATUS_APPLIED)
            ->whereIn('server_state', $states)
            ->latest('id')
            ->first();
    }
}
