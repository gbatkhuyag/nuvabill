<?php

namespace App\Extensions\Servers\Resources;

/**
 * A live limit for one kind on one existing service, in extra units, such as the free IPs left on
 * a machine or a disk that cannot shrink below what the server already has.
 */
final readonly class ResourceLimit
{
    /**
     * @param  int|null  $minUnits  The fewest extra units the service may go down to, or null for no limit.
     * @param  int|null  $maxUnits  The most extra units the service may go up to, or null for no limit.
     * @param  string|null  $reason  Plain text for the client, already translated, when the limit stops a change.
     */
    public function __construct(public ?int $minUnits = null, public ?int $maxUnits = null, public ?string $reason = null) {}
}
