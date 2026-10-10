<?php

namespace App\Extensions\Servers\Panel;

use RuntimeException;

/**
 * A panel read, action or console that cannot be done, thrown by a module. The message is shown
 * to the client, so it must be plain and safe; details for staff go in $staffDetail, which is
 * logged but never sent to the browser.
 */
final class PanelException extends RuntimeException
{
    /**
     * @param  string  $clientMessage  Plain text for the client, already translated.
     * @param  string|null  $staffDetail  What went wrong, for the staff log. Never a password, key or token.
     * @param  int  $status  The HTTP status of the answer: 403, 404, 409, 410, 413, 422, 429 or 503.
     */
    public function __construct(string $clientMessage, public readonly ?string $staffDetail = null, public readonly int $status = 422)
    {
        parent::__construct($clientMessage);
    }

    /**
     * The failed result to answer the client with.
     */
    public function toResult(): PanelResult
    {
        return PanelResult::fail($this->getMessage(), $this->staffDetail, $this->status);
    }
}
