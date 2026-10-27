<?php

namespace App\Provisioning\Hardware;

use App\Billing\SoldOut;
use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Events\DedicatedServerActivated;
use App\Events\DedicatedServerReleased;
use App\Events\DedicatedServerSuspended;
use App\Events\DedicatedServerUnsuspended;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;

/**
 * The stock and status of dedicated server machines (Admin → Hardware). A machine's status moves
 * only through this class: each change locks the machine's row, reads it again inside the lock and
 * checks MachineStatus::canMoveTo(), so two requests can never sell or free one machine twice, and
 * a machine that held a service is never sold again before staff confirm its wipe.
 *
 * Locks follow the one order every checkout and change uses: products (id order), then machines
 * (id order), then services, then a machine's IP rows (see Product::lockedStockLeft()).
 *
 * Refusals throw InventoryException with a plain message staff can read.
 *
 * After each saved change of a machine that holds a service, add-ons hear about it through the
 * events DedicatedServerActivated, DedicatedServerSuspended, DedicatedServerUnsuspended and
 * DedicatedServerReleased (for example to open or block a network port).
 */
final class MachineInventory
{
    /**
     * Service statuses that end a service: its machine is taken back.
     *
     * @var list<ServiceStatus>
     */
    public const ENDED = [ServiceStatus::Cancelled, ServiceStatus::Terminated, ServiceStatus::Fraud];

    /**
     * The statuses a machine passes on its way from free to in use, in order.
     *
     * @var list<MachineStatus>
     */
    private const SETUP_STEPS = [MachineStatus::Available, MachineStatus::Reserved, MachineStatus::Provisioning, MachineStatus::Active, MachineStatus::Suspended];

    /**
     * Statuses of a machine that holds no service, which staff may set by hand.
     *
     * @var list<MachineStatus>
     */
    private const FREE = [MachineStatus::Available, MachineStatus::Maintenance, MachineStatus::Decommissioned];

    /** Seconds the sweep holds a service's work lock at most: it only changes rows. */
    private const SWEEP_LOCK_SECONDS = 60;

    /** Extra IPs are rented by count as the "ipv4" resource kind, so only IPv4 lines count. */
    private const EXTRA_IP_VERSION = 4;

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
     * transaction that places the order. A service that already holds a machine keeps it. The
     * machine keeps no "ready" time (provisioned_at) of the service it had before.
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
            ->update(['status' => MachineStatus::Reserved->value, 'service_id' => $service->getKey(), 'provisioned_at' => null]) === 1;

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
     * Give the service a machine: the one staff picked, or (for create() of a service that has
     * none, such as one staff made by hand) the free linked machine with the lowest id.
     *
     * A pending service gets it reserved, and a machine it held before is taken back. An active or
     * suspended service (an import) gets it in that status straight away, without any power call
     * here; a suspended one gets a power check, so the scheduler switches it off.
     *
     * @throws InventoryException
     */
    public function assign(Service $service, ?Machine $machine = null, ?Admin $admin = null): Machine
    {
        $assigned = DB::transaction(function () use ($service, $machine, $admin): Machine {
            $product = Product::query()->whereKey($service->product_id)->lockForUpdate()->first();
            $heldId = Machine::query()->where('service_id', $service->getKey())->value('id');
            $candidateId = $machine?->getKey()
                ?? ($product === null ? null : Machine::query()->sellableFor($product)->orderBy('machines.id')->value('machines.id'));

            if ($candidateId === null) {
                throw new InventoryException(__('No free machine for this product. Add one under Hardware, or assign one on the service page.'));
            }

            $locked = Machine::query()
                ->whereIn('id', array_values(array_unique(array_filter([(int) $heldId, (int) $candidateId]))))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $current = Service::query()->whereKey($service->getKey())->lockForUpdate()->first();
            $new = $locked->get((int) $candidateId) ?? throw new InventoryException(__('This machine no longer exists.'));
            $old = $heldId !== null ? $locked->get((int) $heldId) : null;
            $held = $old !== null && (int) $old->service_id === (int) $service->getKey();

            if ($held && $old->is($new)) {
                return $new;
            }

            if ($current === null || ! in_array($current->status, [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended], true)) {
                throw new InventoryException(__('Only a pending, active or suspended service can get a machine.'));
            }

            if ($new->status !== MachineStatus::Available || $new->service_id !== null) {
                throw new InventoryException(__(':machine is not free.', ['machine' => $new->name]));
            }

            if ($held && $current->status !== ServiceStatus::Pending) {
                throw new InventoryException(__('This service already has a machine. Use Replace machine instead.'));
            }

            $to = match ($current->status) {
                ServiceStatus::Active => MachineStatus::Active,
                ServiceStatus::Suspended => MachineStatus::Suspended,
                default => MachineStatus::Reserved,
            };
            $this->assertSetupStep($new, $to);

            if ($held) {
                $this->release($old, 'another machine was assigned');
            }

            $new->forceFill([
                'status' => $to,
                'service_id' => $service->getKey(),
                'provisioned_at' => $to === MachineStatus::Reserved ? null : now(),
                'power_check_at' => self::powerCheckFor($to),
            ])->save();

            $by = $admin !== null ? ' by staff' : '';
            Activity::log('machine.assigned', "Machine {$new->name} assigned to service #{$service->getKey()}{$by}", $new, $current->client, $admin);

            return $new;
        }, 3);

        if ($machine !== null) {
            $machine->setRawAttributes($assigned->getAttributes(), true);
        }

        match ($assigned->status) {
            MachineStatus::Active => $this->announce($assigned, DedicatedServerActivated::class),
            MachineStatus::Suspended => $this->announce($assigned, DedicatedServerSuspended::class),
            default => null,
        };

        return $assigned;
    }

    /**
     * The service's reserved machine waits for its system to be installed: reserved → provisioning.
     * When this move happens, ServerSetup::begin() hands it to an add-on installer, or emails staff
     * "admin.machine_install" once. Its "ready" time (provisioned_at) is cleared, so only "Server is
     * ready" (ServerSetup::ready()) for this service can set it.
     *
     * @throws InventoryException
     */
    public function startProvisioning(Machine $machine, Service $service): Machine
    {
        $started = DB::transaction(function () use ($machine, $service): Machine {
            $locked = $this->lockHeldBy($machine->getKey(), $service);

            if ($locked->status === MachineStatus::Provisioning) {
                return $locked;
            }

            $this->move($locked, MachineStatus::Provisioning);
            $locked->forceFill(['provisioned_at' => null, 'setup_by' => null, 'setup_started_at' => null, 'setup_error' => null])->save();

            Activity::log('machine.provisioning', "Machine {$locked->name} waits for the OS install (service #{$service->getKey()})", $locked, $service->client);
            DB::afterCommit(fn () => rescue(fn () => app(ServerSetup::class)->begin($locked, $service)));

            return $locked;
        }, 3);

        $machine->setRawAttributes($started->getAttributes(), true);

        return $started;
    }

    /**
     * The OS is installed ("Server is ready", by staff or an installer through ServerSetup::ready()).
     * The service gets its host name, login name and password (encrypted on the service; never
     * emailed), and the machine's provisioned_at is set, so the next create() activates the service.
     *
     * @param  string  $by  Who did it when no staff member did, for the activity log.
     *
     * @throws InventoryException
     */
    public function markInstalled(Service $service, ?string $hostname, string $username, ?string $password, ?Admin $admin = null, string $by = ''): Machine
    {
        $username = trim($username);

        if ($username === '') {
            throw new InventoryException(__('Enter the login name.'));
        }

        return DB::transaction(function () use ($service, $hostname, $username, $password, $admin, $by): Machine {
            $id = Machine::query()->where('service_id', $service->getKey())->value('id');

            if ($id === null) {
                throw new InventoryException(__('This service has no machine yet. Assign one first.'));
            }

            $locked = $this->lockHeldBy((int) $id, $service);

            if ($locked->status !== MachineStatus::Provisioning) {
                throw new InventoryException(__('The machine of this service is not being set up.'));
            }

            $current = Service::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status !== ServiceStatus::Pending) {
                throw new InventoryException(__('Only a pending service can be marked ready.'));
            }

            $details = ['username' => $username];

            if (filled($hostname)) {
                $details['domain'] = trim((string) $hostname);
            }

            if (filled($password)) {
                $details['password'] = $password;
            }

            $current->forceFill($details)->save();
            $locked->forceFill(['provisioned_at' => now(), 'setup_error' => null])->save();
            $service->setRawAttributes($current->getAttributes(), true);

            $via = $admin === null && $by !== '' ? ' by '.mb_substr($by, 0, 64) : '';
            Activity::log('machine.install_done', "Machine {$locked->name} marked ready for service #{$service->getKey()}{$via}", $locked, $current->client, $admin);

            return $locked;
        }, 3);
    }

    /**
     * The machine is in use: provisioning (after "Server is ready") or suspended → active.
     *
     * @throws InventoryException
     */
    public function activate(Machine $machine): void
    {
        $from = null;

        $this->change($machine, function (Machine $locked) use (&$from): ?string {
            if ($locked->status === MachineStatus::Active) {
                return null;
            }

            if ($locked->status === MachineStatus::Provisioning && $locked->provisioned_at === null) {
                throw new InventoryException(__('Press Server is ready first.'));
            }

            $from = $locked->status;
            $this->move($locked, MachineStatus::Active);
            $locked->forceFill(['power_check_at' => null]);

            return 'machine.activated';
        }, fn (Machine $locked): string => "Machine {$locked->name} in use by service #{$locked->service_id}");

        if ($from !== null) {
            $this->announce($machine, $from === MachineStatus::Suspended ? DedicatedServerUnsuspended::class : DedicatedServerActivated::class);
        }
    }

    /**
     * The machine's service is suspended: active → suspended. With $powerCheckAt, the scheduler
     * checks the power from then on and switches the machine off when it is still on.
     *
     * @throws InventoryException
     */
    public function suspend(Machine $machine, ?CarbonInterface $powerCheckAt): void
    {
        $moved = false;

        $this->change($machine, function (Machine $locked) use ($powerCheckAt, &$moved): ?string {
            if ($locked->status === MachineStatus::Suspended) {
                if ($powerCheckAt !== null) {
                    $locked->forceFill(['power_check_at' => $powerCheckAt]);
                }

                return null;
            }

            $this->move($locked, MachineStatus::Suspended);
            $locked->forceFill(['power_check_at' => $powerCheckAt]);
            $moved = true;

            return 'machine.suspended';
        }, fn (Machine $locked): string => "Machine {$locked->name} suspended (service #{$locked->service_id})");

        if ($moved) {
            $this->announce($machine, DedicatedServerSuspended::class);
        }
    }

    /**
     * The machine's service is active again: suspended → active, and the power checks stop.
     *
     * @throws InventoryException
     */
    public function unsuspend(Machine $machine): void
    {
        $moved = false;

        $this->change($machine, function (Machine $locked) use (&$moved): ?string {
            if ($locked->status === MachineStatus::Active) {
                $locked->forceFill(['power_check_at' => null]);

                return null;
            }

            $this->move($locked, MachineStatus::Active);
            $locked->forceFill(['power_check_at' => null]);
            $moved = true;

            return 'machine.unsuspended';
        }, fn (Machine $locked): string => "Machine {$locked->name} unsuspended (service #{$locked->service_id})");

        if ($moved) {
            $this->announce($machine, DedicatedServerUnsuspended::class);
        }
    }

    /**
     * Take back the machine the service holds, if any (see release()).
     */
    public function releaseFor(Service $service, string $reason): void
    {
        $machine = $this->machineFor($service);

        if ($machine !== null) {
            $this->release($machine, $reason);
        }
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

            return ['machine' => $locked, 'service' => $service, 'service_id' => $serviceId, 'wipe' => $wipe];
        });

        if ($released === null) {
            return;
        }

        $machine->setRawAttributes($released['machine']->getAttributes(), true);
        DB::afterCommit(fn () => self::dispatchSafely(new DedicatedServerReleased($released['machine'], $released['service'], $released['wipe'], $reason)));

        if ($released['wipe']) {
            // After the transaction, so no email goes out for a release that was rolled back.
            DB::afterCommit(fn () => $this->mailer->sendToStaff('admin.machine_wipe', [
                'machine' => ['name' => $released['machine']->name, 'location' => (string) $released['machine']->location],
                'service' => ['id' => $released['service_id']],
                'admin_url' => self::adminUrl($released['machine']),
            ]));
        }
    }

    /**
     * Staff erased all disks of a released machine: wiping → available, maintenance or retired.
     * This is the only way out of "needs wipe".
     *
     * @throws InventoryException
     */
    public function confirmWiped(Machine $machine, Admin $admin, MachineStatus $next): void
    {
        if (! in_array($next, self::FREE, true)) {
            throw new InventoryException(__('Choose Available, Maintenance or Retired.'));
        }

        if (! $admin->hasPermission('hardware.manage')) {
            throw new InventoryException(__('You may not confirm wipes.'));
        }

        $this->change($machine, function (Machine $locked) use ($next): string {
            if ($locked->status !== MachineStatus::Wiping) {
                throw new InventoryException(__('Not possible from :status.', ['status' => $locked->status->label()]));
            }

            $this->move($locked, $next);
            $locked->forceFill(['wiped_at' => now(), 'power_check_at' => null]);

            return 'machine.wiped';
        }, fn (Machine $locked): string => "Machine {$locked->name} wipe confirmed; it is now ".self::englishStatus($next), $admin);
    }

    /**
     * Staff set a free machine to available, maintenance or retired. Never from or to "needs
     * wipe" or a status that holds a service.
     *
     * @throws InventoryException
     */
    public function setStatus(Machine $machine, MachineStatus $to, Admin $admin): void
    {
        if (! in_array($to, self::FREE, true)) {
            throw new InventoryException(__('Choose Available, Maintenance or Retired.'));
        }

        $from = null;

        $this->change($machine, function (Machine $locked) use ($to, &$from): ?string {
            if ($locked->status === $to) {
                return null;
            }

            if (! in_array($locked->status, self::FREE, true) || $locked->service_id !== null) {
                throw new InventoryException(__('Not possible from :status.', ['status' => $locked->status->label()]));
            }

            $from = $locked->status;
            $this->move($locked, $to);

            return 'machine.status_changed';
        }, function (Machine $locked) use (&$from, $to): string {
            return "Machine {$locked->name}: ".self::englishStatus($from).' → '.self::englishStatus($to);
        }, $admin);
    }

    /**
     * Move an active or suspended service to another free machine. The new machine takes over the
     * service's status and its rented extra IPs (as many free extra IPs as the old one had); the old
     * machine is released and must be wiped before it is sold again. A suspended service's new
     * machine gets a power check, so the scheduler switches it off and checks it every hour.
     *
     * @throws InventoryException
     */
    public function replace(Service $service, Machine $new, Admin $admin): void
    {
        DB::transaction(function () use ($service, $new, $admin): void {
            $oldId = Machine::query()->where('service_id', $service->getKey())->value('id');

            if ($oldId === null) {
                throw new InventoryException(__('This service has no machine. Use Assign machine instead.'));
            }

            if ((int) $oldId === (int) $new->getKey()) {
                throw new InventoryException(__('Choose another machine.'));
            }

            $locked = Machine::query()->whereIn('id', [(int) $oldId, (int) $new->getKey()])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = Service::query()->whereKey($service->getKey())->lockForUpdate()->first();
            $old = $locked->get((int) $oldId);
            $fresh = $locked->get((int) $new->getKey()) ?? throw new InventoryException(__('This machine no longer exists.'));

            if ($current === null || ! in_array($current->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
                throw new InventoryException(__('Only an active or suspended service can move to another machine.'));
            }

            if ($old === null || (int) $old->service_id !== (int) $service->getKey() || ! in_array($old->status, [MachineStatus::Active, MachineStatus::Suspended], true)) {
                throw new InventoryException(__('The machine of this service is not in use.'));
            }

            if ($fresh->status !== MachineStatus::Available || $fresh->service_id !== null) {
                throw new InventoryException(__(':machine is not free.', ['machine' => $fresh->name]));
            }

            $status = $old->status;
            $this->assertSetupStep($fresh, $status);

            $rented = MachineIp::query()->where('machine_id', $old->id)->where('kind', MachineIp::KIND_EXTRA)->where('version', self::EXTRA_IP_VERSION)->where('service_id', $service->getKey())->count();
            $free = MachineIp::query()->where('machine_id', $fresh->id)->where('kind', MachineIp::KIND_EXTRA)->where('version', self::EXTRA_IP_VERSION)->whereNull('service_id')->count();

            if ($free < $rented) {
                throw new InventoryException(__('Not enough free extra IPs on :machine. Add :count more.', [
                    'machine' => $fresh->name,
                    'count' => $rented - $free,
                ]), $rented - $free);
            }

            $this->release($old, "service moved to {$fresh->name}");
            $fresh->forceFill([
                'status' => $status,
                'service_id' => $service->getKey(),
                'provisioned_at' => now(),
                'power_check_at' => self::powerCheckFor($status),
            ])->save();

            if ($rented > 0) {
                $this->syncExtraIps($current, $rented);
            }

            Activity::log('machine.replaced', "Service #{$service->getKey()} moved from {$old->name} to {$fresh->name}", $current, null, $admin);
            $new->setRawAttributes($fresh->getAttributes(), true);
        }, 3);

        $this->announce($new, $new->status === MachineStatus::Suspended ? DedicatedServerSuspended::class : DedicatedServerActivated::class);
    }

    /**
     * Make the number of extra IPv4 addresses the service rents on its machine exactly $total (the
     * "ipv4" resource kind; extra IPv6 lines are never rented by count). New ones are the machine's
     * free extra IPs with the lowest id; when there are fewer, the newest are freed first. Freed IPs
     * stay routed to the same machine, so freeing them is harmless.
     *
     * @return Collection<int, MachineIp> The extra IPs the service rents now.
     *
     * @throws InventoryException when the service has no machine, or too few extra IPs are free
     *                            (InventoryException::$missing says how many more are needed).
     */
    public function syncExtraIps(Service $service, int $total): Collection
    {
        if ($total < 0) {
            throw new InventoryException(__('The number of extra IPs cannot be below zero.'));
        }

        return DB::transaction(function () use ($service, $total): Collection {
            $id = Machine::query()->where('service_id', $service->getKey())->value('id');

            if ($id === null) {
                throw new InventoryException(__('This service has no machine yet. Assign one first.'));
            }

            $machine = $this->lockHeldBy((int) $id, $service);
            $ips = MachineIp::query()->where('machine_id', $machine->id)->where('kind', MachineIp::KIND_EXTRA)->where('version', self::EXTRA_IP_VERSION)->orderBy('id')->lockForUpdate()->get();
            $rented = $ips->filter(fn (MachineIp $ip): bool => (int) $ip->service_id === (int) $service->getKey());
            $free = $ips->filter(fn (MachineIp $ip): bool => $ip->service_id === null);

            if ($total > $rented->count()) {
                $needed = $total - $rented->count();

                if ($free->count() < $needed) {
                    throw new InventoryException(__('Not enough free extra IPs on :machine. Add :count more.', [
                        'machine' => $machine->name,
                        'count' => $needed - $free->count(),
                    ]), $needed - $free->count());
                }

                foreach ($free->take($needed) as $ip) {
                    $ip->forceFill(['service_id' => $service->getKey(), 'assigned_at' => now()])->save();
                    Activity::log('machine.ip_assigned', "{$ip->address} on machine {$machine->name} rented to service #{$service->getKey()}", $machine, $service->client);
                }
            } elseif ($total < $rented->count()) {
                $newestFirst = $rented->sort(fn (MachineIp $a, MachineIp $b): int => [$b->assigned_at?->getTimestamp() ?? 0, $b->id] <=> [$a->assigned_at?->getTimestamp() ?? 0, $a->id]);

                foreach ($newestFirst->take($rented->count() - $total) as $ip) {
                    $ip->forceFill(['service_id' => null, 'assigned_at' => null])->save();
                    Activity::log('machine.ip_released', "{$ip->address} on machine {$machine->name} freed from service #{$service->getKey()}", $machine, $service->client);
                }
            }

            return MachineIp::query()->where('machine_id', $machine->id)->where('kind', MachineIp::KIND_EXTRA)->where('version', self::EXTRA_IP_VERSION)->where('service_id', $service->getKey())->orderBy('id')->get();
        }, 3);
    }

    /**
     * Every five minutes: fix machines whose service changed without them, for example through a
     * mass update or a deleted service. A machine whose service ended or is gone is released (it
     * needs a wipe, unless it was only reserved). A machine whose service staff set to active or
     * suspended by hand gets the same status, without any power call. A machine whose service is
     * busy with other work (a suspend, say) waits for the next run. Returns how many were fixed.
     */
    public function sweep(): int
    {
        $fixed = 0;
        $holding = array_map(fn (MachineStatus $status): string => $status->value, array_filter(MachineStatus::cases(), fn (MachineStatus $status): bool => $status->holdsService()));

        foreach (Machine::query()->whereIn('status', $holding)->lazyById(100) as $machine) {
            if ($this->sweepOne($machine)) {
                $fixed++;
            }
        }

        return $fixed;
    }

    /**
     * Tell add-ons about a machine whose service it holds, once the change is saved.
     *
     * @param  class-string<DedicatedServerActivated|DedicatedServerSuspended|DedicatedServerUnsuspended>  $event
     */
    private function announce(Machine $machine, string $event): void
    {
        $snapshot = (new Machine)->newFromBuilder($machine->getAttributes());

        DB::afterCommit(function () use ($snapshot, $event): void {
            $service = $snapshot->service_id !== null ? Service::query()->find($snapshot->service_id) : null;

            if ($service !== null) {
                self::dispatchSafely(new $event($snapshot, $service));
            }
        });
    }

    /**
     * Send an event to add-ons. One that fails is reported and never undoes the saved change.
     */
    private static function dispatchSafely(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            report(new RuntimeException('A listener of '.class_basename($event).' failed: '.$exception::class));
        }
    }

    /**
     * The machine's page in the admin area.
     */
    public static function adminUrl(Machine $machine): string
    {
        return Route::has('admin.machines.show') ? route('admin.machines.show', $machine) : route('admin.dashboard');
    }

    /**
     * One machine of sweep(). A machine that looks out of step is fixed under its service's work
     * lock (Provisioner::lockFor), because a suspend or unsuspend on the server changes the machine
     * first and the service last: in between the two only look out of step. A service that is busy
     * is left for the next run.
     */
    private function sweepOne(Machine $machine): bool
    {
        $serviceId = $machine->service_id;
        $service = $serviceId !== null ? Service::query()->find($serviceId) : null;

        if (! self::outOfStep($machine, $service)) {
            return false;
        }

        // A service that is gone has no work running on it, so there is nothing to wait for.
        $lock = $service !== null ? app(Provisioner::class)->lockFor($service, self::SWEEP_LOCK_SECONDS) : null;

        if ($lock !== null && ! $lock->get()) {
            return false;
        }

        try {
            // Read both again inside the lock: the work that held it may have changed them.
            $current = Machine::query()->find($machine->getKey());
            $service = $service?->fresh();

            if ($current === null || (int) $current->service_id !== (int) $serviceId || ! self::outOfStep($current, $service)) {
                return false;
            }

            if ($service === null || in_array($service->status, self::ENDED, true)) {
                $before = $current->status;
                $this->release($current, $service === null ? 'its service is gone' : "service {$service->status->value}");

                return $current->status !== $before;
            }

            return $this->syncWithService($current);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Whether the sweep must fix the machine: its service ended or is gone, or staff set the
     * service active or suspended by hand.
     */
    private static function outOfStep(Machine $machine, ?Service $service): bool
    {
        return $service === null
            || in_array($service->status, self::ENDED, true)
            || ($machine->status === MachineStatus::Suspended && $service->status === ServiceStatus::Active)
            || ($machine->status === MachineStatus::Active && $service->status === ServiceStatus::Suspended);
    }

    /**
     * Copy a status staff set on the service by hand to its machine, without touching the power.
     */
    private function syncWithService(Machine $machine): bool
    {
        return DB::transaction(function () use ($machine): bool {
            $locked = Machine::query()->whereKey($machine->getKey())->lockForUpdate()->first();
            $service = $locked?->service_id !== null ? Service::query()->whereKey($locked->service_id)->lockForUpdate()->first() : null;

            $to = match (true) {
                $locked === null || $service === null => null,
                $locked->status === MachineStatus::Suspended && $service->status === ServiceStatus::Active => MachineStatus::Active,
                $locked->status === MachineStatus::Active && $service->status === ServiceStatus::Suspended => MachineStatus::Suspended,
                default => null,
            };

            if ($to === null) {
                return false;
            }

            // A status staff changed by hand is copied without touching the power, as the form says.
            $this->move($locked, $to);
            $locked->forceFill(['power_check_at' => null])->save();
            $machine->setRawAttributes($locked->getAttributes(), true);

            Activity::log('machine.status_synced', "Machine {$locked->name} set to ".self::englishStatus($to)." to match service #{$service->getKey()}", $locked, $service->client);
            $this->announce($locked, $to === MachineStatus::Suspended ? DedicatedServerSuspended::class : DedicatedServerUnsuspended::class);

            return true;
        }, 3);
    }

    /**
     * Lock the machine, run the change on it, and log it when the change returns an action name.
     *
     * @param  callable(Machine): ?string  $change
     * @param  callable(Machine): string  $describe
     */
    private function change(Machine $machine, callable $change, callable $describe, ?Admin $admin = null): void
    {
        $changed = DB::transaction(function () use ($machine, $change, $describe, $admin): Machine {
            $locked = Machine::query()->whereKey($machine->getKey())->lockForUpdate()->first()
                ?? throw new InventoryException(__('This machine no longer exists.'));
            $action = $change($locked);
            $locked->save();

            if ($action !== null) {
                Activity::log($action, $describe($locked), $locked, $locked->service?->client, $admin);
            }

            return $locked;
        }, 3);

        $machine->setRawAttributes($changed->getAttributes(), true);
    }

    /**
     * Lock a machine that must be held by the service.
     *
     * @throws InventoryException
     */
    private function lockHeldBy(int $id, Service $service): Machine
    {
        $locked = Machine::query()->whereKey($id)->lockForUpdate()->first();

        if ($locked === null || (int) $locked->service_id !== (int) $service->getKey()) {
            throw new InventoryException(__('This machine is not held by this service.'));
        }

        return $locked;
    }

    /**
     * @throws InventoryException when the status may not move there.
     */
    private function move(Machine $machine, MachineStatus $to): void
    {
        if (! $machine->status->canMoveTo($to)) {
            throw new InventoryException(__('Not possible from :status.', ['status' => $machine->status->label()]));
        }

        $machine->forceFill(['status' => $to]);
    }

    /**
     * A free machine may go straight to a later setup status (an import, or a replacement) only
     * when each step on the way is an allowed move.
     *
     * @throws InventoryException
     */
    private function assertSetupStep(Machine $machine, MachineStatus $to): void
    {
        $from = array_search($machine->status, self::SETUP_STEPS, true);
        $until = array_search($to, self::SETUP_STEPS, true);

        for ($step = (int) $from; $from !== false && $until !== false && $step < $until; $step++) {
            if (! self::SETUP_STEPS[$step]->canMoveTo(self::SETUP_STEPS[$step + 1])) {
                $from = false;
            }
        }

        if ($from === false || $until === false || $from > $until) {
            throw new InventoryException(__('Not possible from :status.', ['status' => $machine->status->label()]));
        }
    }

    /**
     * The first power check of a machine that takes over a service in this status: a suspended
     * machine is checked at once, so the scheduler switches it off and then checks it every hour.
     */
    private static function powerCheckFor(MachineStatus $status): ?CarbonInterface
    {
        return $status === MachineStatus::Suspended ? now() : null;
    }

    /**
     * Status names for the staff activity log, which stays in English.
     */
    private static function englishStatus(?MachineStatus $status): string
    {
        return match ($status) {
            MachineStatus::Available => 'Available',
            MachineStatus::Reserved => 'Reserved',
            MachineStatus::Provisioning => 'Setting up',
            MachineStatus::Active => 'In use',
            MachineStatus::Suspended => 'Suspended',
            MachineStatus::Wiping => 'Needs wipe',
            MachineStatus::Maintenance => 'Maintenance',
            MachineStatus::Decommissioned => 'Retired',
            null => 'unknown',
        };
    }
}
