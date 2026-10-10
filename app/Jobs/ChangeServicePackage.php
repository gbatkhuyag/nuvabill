<?php

namespace App\Jobs;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Moves a service to its current plan on the server in the background, for modules whose plan
 * change is slow (QueuesPackageChanges). Provisioner dispatches it instead of calling the module
 * inside the request that applied the plan change, which may be a payment webhook. The job always
 * sets the plan the service has when it runs, so a second dispatch for the same service while one
 * waits in the queue is dropped.
 */
final class ChangeServicePackage implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Seconds to wait before another try when other work held the service.
     */
    public const BUSY_DELAY = 120;

    /**
     * Tries while other work holds the service, before staff are asked to check it.
     */
    public const BUSY_TRIES = 5;

    /**
     * Not retried by the worker: a busy service is sent again on purpose, a failure is logged for
     * staff (who can press "Change package" again).
     */
    public int $tries = 1;

    /**
     * Seconds before the worker stops the job. A cloud server may need minutes to change its type.
     */
    public int $timeout = 900;

    /**
     * Seconds the queue keeps one waiting job per service.
     */
    public int $uniqueFor = 900;

    /**
     * @param  int  $serviceId  The service to move to its current plan.
     * @param  int  $busyTry  How many times the service was busy so far.
     */
    public function __construct(public int $serviceId, public int $busyTry = 0) {}

    public function uniqueId(): string
    {
        return 'service:'.$this->serviceId;
    }

    public function handle(Provisioner $provisioner): void
    {
        // A service removed, cancelled or terminated while this waited has nothing to change.
        $service = Service::query()->find($this->serviceId);

        if ($service === null || ! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            return;
        }

        $result = $provisioner->changePackageNow($service);

        if (! $result->success && ($result->data['busy'] ?? false) === true) {
            if ($this->busyTry + 1 < self::BUSY_TRIES) {
                self::dispatch($this->serviceId, $this->busyTry + 1)->delay(self::BUSY_DELAY);

                return;
            }

            Activity::log('service.module_failed', "Could not change the package of service #{$service->id} ({$service->label()}) on the server: other work kept the service busy. Press \"Change package\" to try again.", $service);
        }
    }

    /**
     * The job took too long or the worker stopped before the server answered, so the usual failure
     * log never ran. Only the kind of error is logged: a raw message may hold a server address with
     * a key.
     */
    public function failed(?Throwable $exception): void
    {
        $service = Service::query()->find($this->serviceId);

        if ($service !== null) {
            $reason = $exception === null ? 'stopped' : class_basename($exception);
            Activity::log('service.module_failed', "Could not change the package of service #{$service->id} ({$service->label()}) on the server: it was interrupted before it finished ({$reason}), so check the server.", $service);
        }
    }
}
