<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Extensions\Servers\Panel\ClientContext;
use App\Models\Server;
use App\Models\Service;
use App\Support\Activity;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * Virtualizor's end-user panel (port 4083 by default), for the client server panel, the console and
 * sign-in. Calls are signed with the admin API key and a salted hash of the API password (never the
 * password itself), go only to an allowed act with its allowed keys, and are pinned to the
 * service's own VPS: no client input ever picks the VPS, the act or a key.
 */
final class EnduserApi
{
    /**
     * Virtualizor's end-user panel port with SSL.
     */
    public const PORT = 4083;

    /**
     * Virtualizor's other panel ports: the admin panel (4084, 4085) and plain HTTP (4081, 4082).
     * Clients never reach them: a sign-in there could open the admin panel.
     */
    public const NOT_PANEL_PORTS = [4081, 4082, 4084, 4085];

    /**
     * The end-user acts the panel may call, by key: the act, the query and POST keys it may send,
     * and its scope ("vps": the service's VPS, pinned; "account": the Virtualizor user, which must
     * hold only this client's VPS; "owner": the ownership check). Any other act is never sent.
     *
     * @var array<string, array{act: string, query: list<string>, post: list<string>, scope: 'vps'|'account'|'owner'}>
     */
    public const ACTS = [];

    public function __construct(private AdminApi $admin) {}

    /**
     * The end-user panel port for the service's product (Virtualizor's 4083 unless the product sets
     * another), or null when the product sets an invalid port, or a port of the admin panel or the
     * Admin API: a sign-in there could open Virtualizor's admin panel. Staff see why once a day.
     */
    public function port(Service $service): ?int
    {
        $value = $service->product?->module_config['panel_port'] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            $port = self::PORT;
        } else {
            $port = is_int($value) || is_string($value) ? filter_var(trim((string) $value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) : false;

            if ($port === false) {
                return null;
            }
        }

        if (in_array($port, self::NOT_PANEL_PORTS, true) || $port === (int) ($service->server?->port ?: AdminApi::DEFAULT_PORT)) {
            if (Cache::add('nuvabill.virtualizor.admin-panel-port.'.$service->product_id.'.'.$port, true, 86400)) {
                Activity::log('service.module_failed', "Client panel sign-in is off for the product of service #{$service->id} ({$service->label()}): its client panel port {$port} is an admin or API port of Virtualizor. Set Virtualizor's end-user port (4083) as the client panel port.", $service);
            }

            return null;
        }

        return $port;
    }

    /**
     * One signed call to the end-user panel for one act of ACTS. Only that act's query and POST
     * keys are sent; svs (the service's VPS), api, the signature and SET_REMOTE_IP (only for a
     * trusted client IP) are added last, so they always win. The Admin API password is never sent.
     * A failed connection names only the host; an error from Virtualizor is cleaned of secrets.
     *
     * @param  string  $key  A key of ACTS.
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $post
     * @return array<string, mixed> The answer, projected to the fields the act may give.
     *
     * @throws \RuntimeException When the panel does not answer or refuses.
     */
    public function call(Server $server, int $port, string $vpsId, ?ClientContext $context, string $key, array $query = [], array $post = [], int $timeout = 8): array
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * A line for the server's "Test connection" about the end-user port (reachable, certificate,
     * signature), or null when there is nothing to add.
     */
    public function connectionNote(Server $server): ?string
    {
        return null;
    }
}
