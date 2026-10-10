<?php

namespace App\Provisioning\Hardware;

use App\Billing\SoldOut;
use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The stock and status of dedicated server machines (Admin → Hardware). A machine's status moves
 * only through this class: each change locks the machine's row, reads it again inside the lock and
 * checks MachineStatus::canMoveTo(), so two requests can never sell or free one machine twice.
 *
 * Locks follow the one order every checkout and change uses: products (id order), then machines
 * (id order), then services (see Product::lockedStockLeft()).
 */
final class MachineInventory
{
    public function __construct(private TemplateMailer $mailer) {}

    /**
     * How many machines linked to the product are free. Good for showing; where the answer decides
     * a sale, use lockedAvailableCount().
     */
    public function availableCount(Product $product): int
    {
        return Machine::query()->sellableFor($product)->count();
    }

    /**
     * How many machines linked to the product are free, counted from the newest saved rows. Call it
     * inside the caller's transaction, after the product's row is locked: it locks the free linked
     * machines in id order until the transaction ends.
     */
    public function lockedAvailableCount(Product $product): int
    {
        // The ids are counted here because some databases cannot lock the rows of a COUNT.
        return Machine::query()->sellableFor($product)->orderBy('machines.id')->lockForUpdate()->pluck('machines.id')->count();
    }

    /**
     * Reserve the free machine with the lowest id linked to the service's product, inside the
     * transaction that places the order. A service that already holds a machine keeps it.
     *
     * @throws SoldOut when no linked machine is free; the whole order then rolls back.
     */
    public function reserveFor(Service $service): Machine
    {
        $held = $this->machineFor($service);

        if ($held !== null && $held->status->holdsService()) {
            return $held;
        }

        $product = $service->product ?? Product::query()->findOrFail($service->product_id);
        $id = Machine::query()->sellableFor($product)->orderBy('machines.id')->lockForUpdate()->value('machines.id');

        // Only a machine that is still free is taken, even if another order got past the lock.
        $reserved = $id !== null && Machine::query()
            ->whereKey($id)
            ->where('status', MachineStatus::Available->value)
            ->whereNull('service_id')
            ->update(['status' => MachineStatus::Reserved->value, 'service_id' => $service->getKey()]) === 1;

        if (! $reserved) {
            throw new SoldOut($product, 0);
        }

        $machine = Machine::query()->findOrFail($id);
        Activity::log('machine.reserved', "Machine {$machine->name} reserved for service #{$service->getKey()}", $machine, $service->client);

        return $machine;
    }

    /**
     * The machine the service holds now, if any.
     */
    public function machineFor(Service $service): ?Machine
    {
        return Machine::query()->where('service_id', $service->getKey())->first();
    }

    /**
     * Free the machines of the order's services that are cancelled with it (the waiting ones), inside
     * OrderCanceller's transaction and before its mass update, which skips model events. A machine
     * that was only reserved goes back on sale; one staff started to set up needs a wipe first.
     */
    public function releaseForOrder(Order $order): void
    {
        $serviceIds = $order->services()->where('status', ServiceStatus::Pending)->pluck('id');

        if ($serviceIds->isEmpty()) {
            return;
        }

        $machines = Machine::query()->whereIn('service_id', $serviceIds->all())->orderBy('id')->lockForUpdate()->get();

        foreach ($machines as $machine) {
            $this->release($machine, 'order cancelled');
        }
    }

    /**
     * Take the machine back from its service. A reserved machine goes back on sale. A machine that
     * was set up or used goes to "needs wipe": it is never sold again until staff confirm the wipe,
     * the scheduler switches it off once (power_check_at), its rented extra IPs are freed and staff
     * get an email. A machine that holds no service is left as it is.
     *
     * @param  string  $reason  Plain English for the staff log, such as "terminated".
     */
    public function release(Machine $machine, string $reason): void
    {
        $released = DB::transaction(function () use ($machine, $reason): ?array {
            $locked = Machine::query()->whereKey($machine->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->status->holdsService()) {
                return null;
            }

            $serviceId = $locked->service_id;
            $wipe = $locked->status !== MachineStatus::Reserved;
            $to = $wipe ? MachineStatus::Wiping : MachineStatus::Available;

            if (! $locked->status->canMoveTo($to)) {
                return null;
            }

            $locked->forceFill($wipe
                ? ['status' => $to, 'service_id' => null, 'last_service_id' => $serviceId ?? $locked->last_service_id, 'released_at' => now(), 'power_check_at' => now()]
                : ['status' => $to, 'service_id' => null])->save();

            if ($serviceId !== null) {
                MachineIp::query()
                    ->where('machine_id', $locked->id)
                    ->where('kind', MachineIp::KIND_EXTRA)
                    ->where('service_id', $serviceId)
                    ->update(['service_id' => null, 'assigned_at' => null]);
            }

            $service = $serviceId !== null ? Service::query()->find($serviceId) : null;
            $from = $serviceId !== null ? " from service #{$serviceId}" : '';
            Activity::log('machine.released', $wipe
                ? "Machine {$locked->name} released{$from} ({$reason}). It needs a wipe before it is sold again."
                : "Machine {$locked->name} released{$from} ({$reason}). It is on sale again.", $locked, $service?->client);

            return ['machine' => $locked, 'service_id' => $serviceId, 'wipe' => $wipe];
        });

        if ($released === null) {
            return;
        }

        $machine->setRawAttributes($released['machine']->getAttributes(), true);

        if ($released['wipe']) {
            // After the transaction, so no email goes out for a release that was rolled back.
            DB::afterCommit(fn () => $this->mailer->sendToStaff('admin.machine_wipe', [
                'machine' => ['name' => $released['machine']->name, 'location' => (string) $released['machine']->location],
                'service' => ['id' => $released['service_id']],
                'admin_url' => $this->adminUrl($released['machine']),
            ]));
        }
    }

    /**
     * The machine's page in the admin area.
     */
    private function adminUrl(Machine $machine): string
    {
        return Route::has('admin.machines.show') ? route('admin.machines.show', $machine) : route('admin.dashboard');
    }
}
