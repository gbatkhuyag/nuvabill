<?php

namespace App\Contracts;

use App\Models\Service;

/**
 * A server module that sends its own "ready" email instead of "service.welcome". The context is
 * added to TemplateMailer::serviceContext(). It must never hold a password: core removes every key
 * that looks like a secret before sending.
 */
interface ChoosesWelcomeEmail
{
    /**
     * The email template key and the extra context. Core sends "service.welcome" when this throws.
     *
     * @return array{key: string, context: array<string, mixed>}
     */
    public function welcomeEmail(Service $service): array;
}
