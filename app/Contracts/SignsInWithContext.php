<?php

namespace App\Contracts;

use App\Extensions\Servers\Panel\ClientContext;
use App\Models\Service;

/**
 * A server module whose panel sign-in needs to know who asks, for example to tie the session to
 * the client's IP. Core uses it instead of ServerModule::loginUrl() for the client's button.
 */
interface SignsInWithContext
{
    /**
     * A one-time sign-in link to the client's own panel, or null when there is none. Core never
     * stores or logs it.
     */
    public function signInUrl(Service $service, ClientContext $context): ?string;
}
