<?php

namespace App\View\Composers;

use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Models\Admin;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Service;
use App\Provisioning\Hardware\MachineInstallers;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;

/**
 * The "Dedicated server" card on an admin service page: the service's machine with its IPs, the
 * free machines staff may assign (those sold as the service's product first), and what this staff
 * member may do. Read from the database only; the power state is loaded by the page later.
 */
class ServiceMachineComposer
{
    /** Most free machines listed in one select. */
    private const MAX_CHOICES = 200;

    public function compose(View $view): void
    {
        $service = $view->getData()['service'] ?? null;

        if (! $service instanceof Service) {
            $view->with('machineCard', null);

            return;
        }

        /** @var Admin|null $admin */
        $admin = auth('admin')->user();
        $machine = Machine::query()
            ->with(['products:id,name', 'ips' => fn ($query) => $query->orderBy('id')])
            ->where('service_id', $service->getKey())
            ->first();
        $status = $service->status;
        $setUp = in_array($status, [ServiceStatus::Active, ServiceStatus::Suspended], true);
        $mayManage = (bool) $admin?->hasPermission('hardware.manage');
        $choosing = $status === ServiceStatus::Pending || ($setUp && ($machine === null || $mayManage));

        $view->with('machineCard', [
            'machine' => $machine,
            'ips' => $machine === null ? collect() : $machine->ips->filter(fn (MachineIp $ip): bool => $ip->kind !== MachineIp::KIND_EXTRA || (int) $ip->service_id === (int) $service->getKey())->values(),
            'free' => $choosing ? $this->freeMachines($service) : ['linked' => new Collection, 'other' => new Collection],
            'loginName' => self::loginName($service),
            'pending' => $status === ServiceStatus::Pending,
            'setUp' => $setUp,
            'waitsForInstall' => $machine?->status === MachineStatus::Provisioning,
            // An add-on installs the system (MachineInstaller): its name, or the key of one no longer installed.
            'installer' => $machine?->setup_by === null ? null : (app(MachineInstallers::class)->get((string) $machine->setup_by)?->label() ?? (string) $machine->setup_by),
            'mayPower' => (bool) $admin?->hasPermission('hardware.power'),
            'mayManage' => $mayManage,
        ]);
    }

    /**
     * The login name the product suggests, as the dedicated module's "Login name shown to the
     * client" field sets it.
     */
    public static function loginName(Service $service): string
    {
        $saved = $service->product?->module_config['login_name'] ?? null;

        return is_string($saved) && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/iD', $saved) === 1 ? $saved : 'root';
    }

    /**
     * Free machines: the ones sold as the service's product, then the others.
     *
     * @return array{linked: Collection<int, Machine>, other: Collection<int, Machine>}
     */
    private function freeMachines(Service $service): array
    {
        $columns = ['machines.id', 'machines.name', 'machines.location', 'machines.cpu', 'machines.ram_gb'];
        $linked = $service->product === null ? new Collection : Machine::query()
            ->sellableFor($service->product)
            ->whereNull('service_id')
            ->orderBy('name')
            ->limit(self::MAX_CHOICES)
            ->get($columns);

        $other = Machine::query()
            ->available()
            ->whereNull('service_id')
            ->whereNotIn('machines.id', $linked->modelKeys())
            ->orderBy('name')
            ->limit(self::MAX_CHOICES)
            ->get($columns);

        return ['linked' => $linked, 'other' => $other];
    }
}
