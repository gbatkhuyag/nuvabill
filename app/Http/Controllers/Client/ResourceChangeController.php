<?php

namespace App\Http\Controllers\Client;

use App\Billing\ResourceChanges;
use App\Billing\ResourceOptions;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\ResourceChange;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Client area → a service → Resources: add or remove extras (more RAM, IP addresses and so on) in
 * the middle of a billing period, see the price for the days left, and stop a change that waits.
 *
 * Every action checks that the service belongs to the signed-in client before anything else.
 * Clients only send step counts: prices always come from the database, and "expected" refuses a
 * change whose price moved after the client saw it.
 */
class ResourceChangeController extends Controller
{
    public function __construct(private ResourceChanges $changes, private ResourceOptions $options) {}

    /**
     * The review page (it works without JavaScript), or with a JSON request the state of the latest
     * change on the server as {state, text}, which the resources card checks while it runs.
     */
    public function show(Request $request, Service $service): View|JsonResponse
    {
        $this->authorizeOwner($request, $service);

        // The server state stays readable when client changes are turned off: staff may change extras.
        if ($request->expectsJson()) {
            return $this->private(response()->json($this->changes->serverStatus($service)));
        }

        abort_unless($this->changes->enabled(), 404);

        $service->load('product', 'client', 'addons', 'resources.option');
        $blocked = $this->changes->blockedReason($service);
        $sliders = $blocked === null ? $this->options->forService($service) : [];
        $chosen = $request->query('steps');
        $quote = null;
        $problems = [];

        if ($blocked === null && is_array($chosen) && $chosen !== []) {
            try {
                $quote = $this->changes->present($service, $this->changes->quote($service, $chosen));
            } catch (ValidationException $exception) {
                $problems = collect($exception->errors())->flatten()->all();
            }

            // The sliders show what the client chose, inside each range.
            $sliders = array_map(function (array $slider) use ($chosen): array {
                $value = $chosen[$slider['kind']] ?? null;

                if (is_numeric($value)) {
                    $slider['value'] = min(max((int) $value, (int) $slider['min']), (int) $slider['max']);
                }

                return $slider;
            }, $sliders);
        }

        return view('theme::client.services.resources', [
            'service' => $service,
            'blocked' => $blocked,
            'pending' => $this->changes->pendingFor($service),
            'sliders' => $sliders,
            'chosen' => is_array($chosen) ? $chosen : [],
            'quote' => $quote,
            'problems' => $problems,
        ]);
    }

    /**
     * The price of the chosen steps for the days left, as JSON. Maths only: nothing is saved and no
     * server is called.
     */
    public function quote(Request $request, Service $service): JsonResponse
    {
        $this->authorizeOwner($request, $service);
        abort_unless($this->changes->enabled(), 404);

        $data = $request->validate($this->rules());
        $service->load('product', 'client', 'addons', 'resources.option');
        $blocked = $this->changes->blockedReason($service);

        if ($blocked !== null) {
            return $this->private(response()->json(['message' => $blocked], 422));
        }

        return $this->private(response()->json($this->changes->present($service, $this->changes->quote($service, $data['steps']))));
    }

    /**
     * Start the change: an invoice for the difference, a change now, or a change on the renewal date.
     */
    public function store(Request $request, Service $service): RedirectResponse
    {
        $this->authorizeOwner($request, $service);
        abort_unless($this->changes->enabled(), 404);

        $data = $request->validate($this->rules() + ['expected' => ['nullable', 'integer', 'between:-100000000000,100000000000']]);
        $expected = isset($data['expected']) ? (int) $data['expected'] : null;

        try {
            $change = $this->changes->start($service->load('product', 'client'), $data['steps'], expected: $expected);
        } catch (RuntimeException $exception) {
            return back()->with('error', $this->refusalMessage($exception));
        }

        $page = redirect()->to(route('client.services.show', $service).'#resources');

        // Applied first: wallet credit may have paid the whole upgrade invoice already.
        return match (true) {
            $change->status === ResourceChange::STATUS_APPLIED => $page->with('status', match ($change->server_state) {
                ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_APPLYING, ResourceChange::SERVER_CHECKING => __('Your resources are changing now. This can take a few minutes.'),
                ResourceChange::SERVER_MANUAL => __('Our team is setting up your resources.'),
                default => __('Your extras were changed.'),
            }),
            ! $change->isPending() => $page->with('error', __('The change could not be made. Please try again.')),
            $change->mode === ResourceChange::MODE_INVOICE && $change->invoice !== null => redirect()->route('client.invoices.show', $change->invoice)
                ->with('status', __('Pay this invoice and your new resources are added straight away.')),
            $change->mode === ResourceChange::MODE_RENEWAL => $page->with('status', __('Your extras change on :date.', ['date' => $change->apply_on->translatedFormat('d M Y')])),
            default => $page->with('error', __('The change could not be made. Please try again.')),
        };
    }

    /**
     * Stop the change that waits for payment or for the renewal date. This works even when client
     * changes are turned off, so a waiting change can always be stopped.
     */
    public function destroy(Request $request, Service $service): RedirectResponse
    {
        $this->authorizeOwner($request, $service);

        $pending = $this->changes->pendingFor($service);

        if ($pending !== null) {
            try {
                $this->changes->cancel($pending);
            } catch (RuntimeException $exception) {
                $message = $this->refusalMessage($exception);
                // Money on the invoice that cannot go back to the wallet by itself needs staff.
                $stuck = $pending->invoice?->refresh()->status === InvoiceStatus::Unpaid && $pending->invoice->amount_paid > 0;

                return back()->with('error', $stuck ? __('Part of the invoice for this change is already paid. Contact us to stop the change.') : $message);
            }
        }

        return redirect()->to(route('client.services.show', $service).'#resources')->with('status', __('The resource change was stopped.'));
    }

    /**
     * Steps per kind: at most 20 kinds, whole numbers. Kinds and ranges are checked by
     * ResourceChanges against the service's options.
     *
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'steps' => ['required', 'array', 'max:'.ResourceOptions::MAX_KINDS],
            'steps.*' => ['required', 'integer', 'between:0,'.ResourceOptions::MAX_STEPS],
        ];
    }

    private function private(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store, private');
    }

    private function authorizeOwner(Request $request, Service $service): void
    {
        abort_unless($service->client_id === $request->user('web')->id, 404);
    }
}
