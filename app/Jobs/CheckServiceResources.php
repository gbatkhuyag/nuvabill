<?php

namespace App\Jobs;

use App\Billing\ResourceChanges;
use App\Models\ResourceChange;
use App\Support\Activity;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Checks a server again after its module started a resource change that takes a while (a cloud
 * server that changes its type, say): 2, 5, 10 and 10 minutes after the change. A match marks the
 * change done; after the last check staff are asked (see ResourceChanges::checkServer()).
 */
final class CheckServiceResources implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Not retried by the worker: the next check is sent on purpose.
     */
    public int $tries = 1;

    /**
     * Seconds before the worker stops the job; a check only reads the server.
     */
    public int $timeout = 120;

    /**
     * Seconds the queue keeps one waiting check per change.
     */
    public int $uniqueFor = 900;

    /**
     * @param  int  $changeId  The applied resource change whose server is checked.
     */
    public function __construct(public int $changeId) {}

    public function uniqueId(): string
    {
        return 'resource-check:'.$this->changeId;
    }

    public function handle(ResourceChanges $changes): void
    {
        $changes->checkServer($this->changeId);
    }

    /**
     * Only the kind of error is logged: a raw message may hold a server address with a key. The
     * nightly run sends the check again.
     */
    public function failed(?Throwable $exception): void
    {
        $change = ResourceChange::query()->with('service')->find($this->changeId);

        if ($change?->service !== null) {
            $reason = $exception === null ? 'stopped' : class_basename($exception);
            Activity::log('service.resources_failed', "Checking the extras of service #{$change->service_id} on the server was interrupted ({$reason}). It is checked again tonight.", $change->service);
        }
    }
}
