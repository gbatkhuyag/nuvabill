<?php

namespace App\Contracts;

use App\Models\Service;

/**
 * A server module that can check, for staff, why a client's panel sign-in may fail (the "Test
 * panel sign-in" button on the admin service page).
 */
interface ChecksClientAccess
{
    /**
     * Each check with its result. Never makes a sign-in session, and no detail holds a password,
     * key or token.
     *
     * @return list<array{label: string, status: 'ok'|'warn'|'fail'|'skip', detail: string}>
     */
    public function checkClientAccess(Service $service): array;
}
