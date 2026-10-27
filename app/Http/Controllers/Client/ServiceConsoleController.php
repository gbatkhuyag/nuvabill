<?php

namespace App\Http\Controllers\Client;

use App\Enums\ServiceStatus;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\ConsoleSession;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Service;
use App\Provisioning\ConsoleTickets;
use App\Provisioning\Provisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Client area → a service → Console: the server's screen in the browser. Opening it makes a
 * single-use ticket; the console page shows no secret, and its script redeems the ticket once for
 * the connection details. The console page alone may connect to the console host.
 *
 * The console page view (theme::client.services.console) gets: service, ticket (the handle, or
 * null when it has expired), expired (bool), protocol ("vnc" for the screen with noVNC, or
 * "terminal" for a text terminal with xterm.js, since 0.7.0), redeemUrl (POST {ticket} for the
 * details), openUrl (POST to start the console again) and backUrl (the service page's console
 * tab). It never gets the console address or password.
 */
class ServiceConsoleController extends Controller
{
    /**
     * Headers of every answer: never cached, never indexed, and no Referer to the console host.
     */
    private const PRIVATE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    /**
     * Check the console is allowed, ask the module for a session, and send the browser to the
     * console page with a ticket (303). A JSON request gets {ok, url} instead.
     */
    public function open(Request $request, Service $service, Provisioner $provisioner): RedirectResponse|JsonResponse
    {
        $this->authorizeOwner($request, $service);

        $result = $provisioner->openConsole($service, ClientContext::fromRequest($request, $service));
        $ticket = $result->data['ticket'] ?? null;

        if (! $result->ok || ! is_string($ticket)) {
            if ($request->expectsJson()) {
                $headers = self::PRIVATE_HEADERS + ($result->retryAfter !== null ? ['Retry-After' => (string) $result->retryAfter] : []);

                return response()->json(['ok' => false, 'message' => $result->message], $result->ok ? 503 : $result->status)->withHeaders($headers);
            }

            return redirect()->to(route('client.services.show', $service).'#panel-console')->with('error', $result->message);
        }

        $url = route('client.services.console', [$service, 'ticket' => $ticket]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'url' => $url])->withHeaders(self::PRIVATE_HEADERS);
        }

        return redirect()->to($url, 303)->withHeaders(self::PRIVATE_HEADERS);
    }

    /**
     * The console page for a ticket of this client, service and browser session, or a page that
     * says the link has expired. Only a valid ticket lets the page connect to the console host.
     */
    public function show(Request $request, Service $service, ConsoleTickets $tickets): Response
    {
        $this->authorizeOwner($request, $service);

        $handle = $request->query('ticket');
        $handle = is_string($handle) ? $handle : '';
        $ticket = $handle !== '' && $service->status === ServiceStatus::Active
            ? $tickets->look($handle, (int) $request->user('web')->id, (int) $service->id, (string) $request->session()->getId())
            : null;
        $source = $ticket['source'] ?? null;

        if ($source !== null) {
            $request->attributes->set(SecurityHeaders::PAGE_SOURCES, ['connect-src' => [$source]]);
        }

        return response()
            ->view('theme::client.services.console', [
                'service' => $service->loadMissing('product'),
                'ticket' => $source !== null ? $handle : null,
                'expired' => $source === null,
                'protocol' => $ticket['protocol'] ?? ConsoleSession::VNC,
                'redeemUrl' => route('client.services.console.redeem', $service),
                'openUrl' => route('client.services.console.open', $service),
                'backUrl' => route('client.services.show', $service).'#panel-console',
            ])
            ->withHeaders(self::PRIVATE_HEADERS);
    }

    /**
     * Hand out the connection details of a ticket once, as JSON: {ok, url, credentials,
     * expires_at, protocol}. A second try, another session or an old ticket gets 410.
     */
    public function redeem(Request $request, Service $service, ConsoleTickets $tickets): JsonResponse
    {
        $this->authorizeOwner($request, $service);

        $handle = $request->input('ticket');
        $details = is_string($handle) && $service->status === ServiceStatus::Active
            ? $tickets->redeem($handle, (int) $request->user('web')->id, (int) $service->id, (string) $request->session()->getId())
            : null;

        if ($details === null) {
            return response()->json(['ok' => false, 'message' => __('This console link has expired.')], 410)->withHeaders(self::PRIVATE_HEADERS);
        }

        return response()->json([
            'ok' => true,
            'url' => $details['url'],
            'credentials' => (object) $details['credentials'],
            'expires_at' => $details['expires_at'],
            'protocol' => $details['protocol'],
        ])->withHeaders(self::PRIVATE_HEADERS);
    }

    private function authorizeOwner(Request $request, Service $service): void
    {
        abort_unless($service->client_id === $request->user('web')->id, 404);
    }
}
