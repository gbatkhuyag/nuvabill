<?php

namespace App\Http\Controllers\Client;

use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelResult;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Client area → a service → server panel tabs: reads of one section (JSON) and actions in a section,
 * such as setting reverse DNS. Every call goes through Provisioner and the panel gate, so the owner,
 * status, product switches, input rules, limits and the service lock are checked before a module
 * sees it. Answers are never cached and never pass this page's address on.
 */
class ServicePanelController extends Controller
{
    /**
     * Headers of every answer: it holds live server details, and sometimes a secret.
     */
    public const PRIVATE_HEADERS = ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'];

    /**
     * The largest action request body, in bytes. Panel forms are small; anything bigger is refused
     * before it is read further.
     */
    public const MAX_BODY_BYTES = 65536;

    /**
     * One section's data as JSON: {ok, section, data, fetched_at, live}, or {ok: false, message}
     * with the gate's status code. live is false when the module says the data is an old copy.
     */
    public function show(Request $request, Service $service, string $section, Provisioner $provisioner): JsonResponse
    {
        $this->authorizeOwner($request, $service);

        $result = $provisioner->panelRead($service, $section, $request->query(), ClientContext::fromRequest($request, $service));

        if (! $result->ok) {
            return $this->failure($result);
        }

        $data = $result->data;
        $fetchedAt = is_int($data['fetched_at'] ?? null) ? $data['fetched_at'] : Date::now()->getTimestamp();
        unset($data['fetched_at']);

        return response()->json([
            'ok' => true,
            'section' => $section,
            'data' => (object) $data,
            'fetched_at' => Date::createFromTimestamp($fetchedAt)->toIso8601ZuluString(),
            'live' => ($data['live'] ?? true) !== false,
        ])->withHeaders(self::PRIVATE_HEADERS);
    }

    /**
     * Run one action of a section. A JSON request gets {ok, message, data, pending, state, secret};
     * a plain form post goes back to the section on the service page with a message, and never with
     * a secret or the posted values.
     */
    public function action(Request $request, Service $service, string $section, string $action, Provisioner $provisioner): JsonResponse|RedirectResponse
    {
        $this->authorizeOwner($request, $service);

        if ($this->tooLarge($request)) {
            return $this->answer($request, $service, $section, PanelResult::fail(__('The request is too large.'), null, 413));
        }

        $result = $provisioner->panelAction(
            $service,
            $section,
            $action,
            $request->except('_token'),
            ClientContext::fromRequest($request, $service),
            $request->expectsJson(),
        );

        return $this->answer($request, $service, $section, $result);
    }

    private function answer(Request $request, Service $service, string $section, PanelResult $result): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            if (! $result->ok) {
                return $this->failure($result);
            }

            return response()->json([
                'ok' => true,
                'message' => $result->message,
                'data' => (object) $result->data,
                'pending' => $result->pending,
                'state' => $result->state,
                'secret' => $result->secret,
            ])->withHeaders(self::PRIVATE_HEADERS);
        }

        // Without JavaScript: back to the tab. A secret is never flashed, and neither is the input.
        $redirect = redirect()->to(route('client.services.show', $service).'#panel-'.$section)
            ->with($result->ok ? 'status' : 'error', $result->message !== '' ? $result->message : __('Done.'));

        return $result->errors !== [] ? $redirect->withErrors($result->errors) : $redirect;
    }

    /**
     * {ok: false, message, errors?, retry_after?} with the result's status, and Retry-After for 429.
     */
    private function failure(PanelResult $result): JsonResponse
    {
        $body = ['ok' => false, 'message' => $result->message];

        if ($result->errors !== []) {
            $body['errors'] = $result->errors;
        }

        $headers = self::PRIVATE_HEADERS;

        if ($result->retryAfter !== null) {
            $body['retry_after'] = $result->retryAfter;
            $headers['Retry-After'] = (string) $result->retryAfter;
        }

        return response()->json($body, $result->status)->withHeaders($headers);
    }

    /**
     * Whether the body is over the limit, by its declared length or by what was read from it.
     */
    private function tooLarge(Request $request): bool
    {
        $declared = (int) $request->server('CONTENT_LENGTH', 0);
        $read = strlen((string) json_encode($request->except('_token'), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        return max($declared, $read) > self::MAX_BODY_BYTES;
    }

    private function authorizeOwner(Request $request, Service $service): void
    {
        abort_unless($service->client_id === $request->user('web')->id, 404);
    }
}
