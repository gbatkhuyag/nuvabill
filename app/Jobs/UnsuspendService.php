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
 * Unsuspends a service on its server once other work on it ends. Provisioner::unsuspend() sends
 * it when the service lock was busy (a resource change or plan change job, say), so a payment
 * webhook never waits and a paid service never stays suspended until staff notice. Only the
 * suspension that was there when it was sent is lifted: one that staff started later stays.
 */
final class UnsuspendService implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Seconds to wait before each try.
     */
    public const BUSY_DELAY = 60;

    /**
     * Tries while other work holds the service, before staff are asked to check it. Together they
     * outlast the longest service lock (900 seconds).
     */
    public const BUSY_TRIES = 15;

    /**
     * Not retried by the worker: a busy service is sent again on purpose, a failure is logged for
     * staff (who can press "Unsuspend" again).
     */
    public int $tries = 1;

    /**
     * Seconds before the worker stops the job: as long as unsuspend() holds the service lock.
     */
    public int $timeout = 120;

    /**
     * Seconds the queue keeps one waiting job per service.
     */
    public int $uniqueFor = 900;

    /**
     * @param  int  $serviceId  The service to unsuspend.
     * @param  int  $suspendedAt  When the suspension to lift started (Unix seconds), or 0 when it has no date.
     * @param  int  $busyTry  How many times the service was busy so far.
     */
    public function __construct(public int $serviceId, public int $suspendedAt, public int $busyTry = 0) {}

    public function uniqueId(): string
    {
        return 'service:'.$this->serviceId;
    }

    public function handle(Provisioner $provisioner): void
    {
        // A service unsuspended, terminated or removed while this waited has nothing to lift.
        $service = Service::query()->find($this->serviceId);

        if ($service === null || $service->status !== ServiceStatus::Suspended) {
            return;
        }

        $result = $provisioner->unsuspendNow($service, $this->suspendedAt);

        if ($result->success || ($result->data['busy'] ?? false) !== true) {
            return;
        }

        if ($this->busyTry + 1 < self::BUSY_TRIES) {
            self::dispatch($this->serviceId, $this->suspendedAt, $this->busyTry + 1)->delay(self::BUSY_DELAY);

            return;
        }

        Activity::log('service.module_failed', "Could not unsuspend service #{$service->id} ({$service->label()}) on the server: other work kept the service busy. Press \"Unsuspend\" to try again.", $service);
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
            Activity::log('service.module_failed', "Could not unsuspend service #{$service->id} ({$service->label()}) on the server: it was interrupted before it finished ({$reason}), so check the server.", $service);
        }
    }
}
