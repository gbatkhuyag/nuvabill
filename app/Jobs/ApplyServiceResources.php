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
 * Sets a service's server to its plan plus its extras after a resource change was applied (paid,
 * or made by staff). The tries are counted on the change, so the job itself runs once: a failure
 * or a busy service sends it again on purpose (see ResourceChanges::applyToServer()), and the
 * nightly run sends a lost one again. One waiting job per change.
 */
final class ApplyServiceResources implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Not retried by the worker: ResourceChanges counts the tries on the change.
     */
    public int $tries = 1;

    /**
     * Seconds before the worker stops the job. A cloud server may need minutes to change.
     */
    public int $timeout = 900;

    /**
     * Seconds the queue keeps one waiting job per change.
     */
    public int $uniqueFor = 900;

    /**
     * @param  int  $changeId  The applied resource change whose extras the server must get.
     * @param  int  $busyTry  How many times other work held the service so far.
     */
    public function __construct(public int $changeId, public int $busyTry = 0) {}

    public function uniqueId(): string
    {
        return 'resource-change:'.$this->changeId;
    }

    public function handle(ResourceChanges $changes): void
    {
        $changes->applyToServer($this->changeId, $this->busyTry);
    }

    /**
     * The job took too long or the worker stopped before the server answered. The change stays
     * "being set"; the nightly run sends it again after 20 minutes. Only the kind of error is
     * logged: a raw message may hold a server address with a key.
     */
    public function failed(?Throwable $exception): void
    {
        $change = ResourceChange::query()->with('service')->find($this->changeId);

        if ($change?->service !== null) {
            $reason = $exception === null ? 'stopped' : class_basename($exception);
            Activity::log('service.resources_failed', "Setting the extras of service #{$change->service_id} on the server was interrupted ({$reason}). It is tried again tonight.", $change->service);
        }
    }
}
