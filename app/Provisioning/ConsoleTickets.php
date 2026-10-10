<?php

namespace App\Provisioning;

use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\ConsoleSession;
use App\Models\Service;
use LogicException;

/**
 * Single-use tickets that carry a console address and password from the server module to the
 * client's browser without putting them in the page. A ticket is stored encrypted in the cache
 * under the hash of its handle, works only for the same client, service and signed-in session, and
 * lives TTL seconds (less when the module's session ends sooner).
 *
 * Skeleton: the public signatures are final; the bodies come with the panel gate unit.
 */
final class ConsoleTickets
{
    /**
     * The longest time a ticket lives, in seconds.
     */
    public const TTL = 60;

    /**
     * Store a ticket for the console session and give its handle (40 characters).
     *
     * @param  string  $sessionId  The client's session id; only its hash is kept.
     */
    public function issue(Service $service, ClientContext $context, ConsoleSession $session, string $sessionId): string
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * The connect source (wss://host:port) of a ticket that is still valid for this client, service
     * and session, without using it up, or null.
     */
    public function peek(string $handle, int $clientId, int $serviceId, string $sessionId): ?string
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Use up a ticket that is valid for this client, service and session, once.
     *
     * @return array{url: string, credentials: array<string, string>, expires_at: string}|null
     */
    public function redeem(string $handle, int $clientId, int $serviceId, string $sessionId): ?array
    {
        throw new LogicException('Not built yet.');
    }
}
