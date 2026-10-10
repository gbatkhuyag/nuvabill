<?php

namespace App\Provisioning\Hardware;

use App\Models\Service;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Takes a machine back when its service ends (cancelled, terminated or fraud), for example when
 * staff change the status on the service page.
 *
 * It runs after the service's transaction is saved, so the machine is locked after the service
 * work is done (machines are always locked before services) and nothing happens when the change is
 * rolled back. It makes no BMC call: a released machine gets a power check, and the scheduler
 * switches it off once. Mass updates skip this observer; OrderCanceller frees machines itself and
 * the five-minute sweep catches the rest.
 */
final class ServiceMachineObserver
{
    public function __construct(private readonly MachineInventory $inventory) {}

    public function updated(Service $service): void
    {
        if (! $service->wasChanged('status') || ! in_array($service->status, MachineInventory::ENDED, true)) {
            return;
        }

        $id = $service->getKey();
        $reason = 'service '.$service->status->value;

        DB::afterCommit(function () use ($service, $id, $reason): void {
            try {
                $this->inventory->releaseFor($service, $reason);
            } catch (Throwable $exception) {
                // The sweep tries again within five minutes.
                report(new RuntimeException("Freeing the machine of service #{$id} failed: ".$exception::class));
            }
        });
    }
}
