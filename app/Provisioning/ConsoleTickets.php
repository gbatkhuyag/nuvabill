<?php

namespace App\Provisioning;

use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\ConsoleSession;
use App\Extensions\Servers\Panel\PanelException;
use App\Models\Service;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Single-use tickets that carry a console address and password from the server module to the
 * client's browser without putting them in the page. A ticket is stored encrypted in the cache
 * under the hash of its handle, works only for the same client, service and signed-in session, and
 * lives TTL seconds (less when the module's session ends sooner).
 *
 * The handle in the console page's address is not a secret on its own: it works once, for one
 * minute, only in the session that opened it, and only with that session's CSRF token.
 */
final class ConsoleTickets
{
    /**
     * The longest time a ticket lives, in seconds.
     */
    public const TTL = 60;

    /**
     * Cache key of a ticket, followed by the SHA-256 of its handle.
     */
    private const PREFIX = 'nuvabill:console:';

    /**
     * A handle: 40 letters and digits.
     */
    private const HANDLE_PATTERN = '/^[A-Za-z0-9]{40}\z/';

    /**
     * Credential keys a console page may get. Anything else a module adds is dropped.
     */
    private const CREDENTIALS = ['password', 'username', 'target'];

    /**
     * Store a ticket for the console session and give its handle (40 characters).
     *
     * @param  string  $sessionId  The client's session id; only its hash is kept.
     *
     * @throws PanelException When the console address is not allowed, the session id is empty or the
     *                        module's session has already ended.
     */
    public function issue(Service $service, ClientContext $context, ConsoleSession $session, string $sessionId): string
    {
        $source = $session->connectSource();
        $now = Date::now()->getTimestamp();
        $moduleExpiry = $session->expiresAt?->getTimestamp();
        $ttl = $moduleExpiry === null ? self::TTL : min(self::TTL, $moduleExpiry - $now);

        if ($ttl < 1 || $sessionId === '') {
            throw new PanelException(
                __('The console is not available for this server right now.'),
                $sessionId === '' ? 'No signed-in session to bind the console ticket to.' : 'The console session of the module ended before it could be used.',
                503,
            );
        }

        $handle = Str::random(40);
        $ticket = [
            'client' => $context->clientId,
            'service' => (int) $service->id,
            'session' => hash('sha256', $sessionId),
            'source' => $source,
            'url' => $session->url,
            'credentials' => array_filter(Arr::only($session->credentials, self::CREDENTIALS), 'is_string'),
            'expires' => $now + $ttl,
            'module_expires' => $moduleExpiry,
        ];

        Cache::put(self::key($handle), Crypt::encryptString((string) json_encode($ticket)), $ttl);

        return $handle;
    }

    /**
     * The connect source (wss://host:port) of a ticket that is still valid for this client, service
     * and session, without using it up, or null.
     */
    public function peek(string $handle, int $clientId, int $serviceId, string $sessionId): ?string
    {
        $ticket = $this->read($handle);

        return $ticket !== null && $this->belongsTo($ticket, $clientId, $serviceId, $sessionId) ? $ticket['source'] : null;
    }

    /**
     * Use up a ticket that is valid for this client, service and session, once. A ticket asked for
     * by another client, service or session stays for its owner.
     *
     * @return array{url: string, credentials: array<string, string>, expires_at: string}|null
     */
    public function redeem(string $handle, int $clientId, int $serviceId, string $sessionId): ?array
    {
        if (preg_match(self::HANDLE_PATTERN, $handle) !== 1) {
            return null;
        }

        $lock = Cache::lock(self::key($handle).':lock', 5);

        // Another redeem of the same ticket is running: only one of them may have it.
        if (! $lock->get()) {
            return null;
        }

        try {
            $ticket = $this->read($handle);

            if ($ticket === null || ! $this->belongsTo($ticket, $clientId, $serviceId, $sessionId)) {
                return null;
            }

            Cache::forget(self::key($handle));

            return [
                'url' => $ticket['url'],
                'credentials' => $ticket['credentials'],
                'expires_at' => Date::createFromTimestamp($ticket['module_expires'] ?? $ticket['expires'])->toIso8601ZuluString(),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * The stored ticket, or null when there is none, it cannot be read, or it has the wrong shape.
     *
     * @return array{client: int, service: int, session: string, source: string, url: string, credentials: array<string, string>, expires: int, module_expires: int|null}|null
     */
    private function read(string $handle): ?array
    {
        if (preg_match(self::HANDLE_PATTERN, $handle) !== 1) {
            return null;
        }

        $stored = Cache::get(self::key($handle));

        if (! is_string($stored)) {
            return null;
        }

        try {
            $ticket = json_decode(Crypt::decryptString($stored), true);
        } catch (DecryptException) {
            return null;
        }

        $valid = is_array($ticket)
            && is_int($ticket['client'] ?? null)
            && is_int($ticket['service'] ?? null)
            && is_string($ticket['session'] ?? null)
            && is_string($ticket['source'] ?? null)
            && is_string($ticket['url'] ?? null)
            && is_array($ticket['credentials'] ?? null)
            && is_int($ticket['expires'] ?? null)
            && (($ticket['module_expires'] ?? null) === null || is_int($ticket['module_expires']));

        return $valid ? $ticket : null;
    }

    /**
     * @param  array{client: int, service: int, session: string, expires: int}  $ticket
     */
    private function belongsTo(array $ticket, int $clientId, int $serviceId, string $sessionId): bool
    {
        return $sessionId !== ''
            && $ticket['client'] === $clientId
            && $ticket['service'] === $serviceId
            && hash_equals($ticket['session'], hash('sha256', $sessionId))
            && $ticket['expires'] > Date::now()->getTimestamp();
    }

    private static function key(string $handle): string
    {
        return self::PREFIX.hash('sha256', $handle);
    }
}
