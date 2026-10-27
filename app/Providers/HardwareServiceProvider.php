<?php

namespace App\Providers;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Machine;
use App\Models\Service;
use App\Provisioning\Hardware\DedicatedPanelExtensions;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use App\Provisioning\Hardware\MachineInstallers;
use App\Provisioning\Hardware\MachineSections;
use App\Provisioning\Hardware\ServiceMachineObserver;
use App\Provisioning\Hardware\Tls\CertificateProbe;
use App\Provisioning\Hardware\Tls\PinningSupport;
use App\Provisioning\Hardware\Tls\StreamCertificateProbe;
use App\Support\ServicePanels;
use App\View\Composers\ProductMachineStockComposer;
use App\View\Composers\ServiceMachineComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Dedicated servers in your own rack (Admin → Hardware): the certificate check of BMCs, freeing a
 * machine when its service ends, and the "being prepared" card on the client's service page. The
 * view composers of the admin pages register here too.
 *
 * Add-ons hook in through these registries in their boot() (see docs/hardware.md):
 * - PowerDrivers: power drivers (the Dedicated Server Power add-on adds Redfish and IPMI);
 * - MachineSections: cards on a machine's admin page;
 * - DedicatedPanelExtensions: tabs in the client server panel;
 * - MachineInstallers: automatic installs that take over the "Setting up" step;
 * and through the DedicatedServer* events and the add-on's hardwareRoutes().
 */
class HardwareServiceProvider extends ServiceProvider
{
    /**
     * The client card shown while staff install a paid dedicated server.
     */
    public const PENDING_VIEW = 'theme::client.services.dedicated-pending';

    public function register(): void
    {
        $this->app->singleton(PinningSupport::class, fn (): PinningSupport => new PinningSupport);
        $this->app->bind(CertificateProbe::class, StreamCertificateProbe::class);

        // Core ships no power driver: an add-on registers its own, and without one staff switch
        // the power by hand.
        $this->app->singleton(PowerDrivers::class);
        $this->app->singleton(MachineSections::class);
        $this->app->singleton(DedicatedPanelExtensions::class);
        $this->app->singleton(MachineInstallers::class);
    }

    public function boot(): void
    {
        Service::observe(ServiceMachineObserver::class);

        $this->app->make(ServicePanels::class)->add(fn (Service $service): ?array => $this->pendingCard($service));

        View::composer('admin.products.partials.machine-stock', ProductMachineStockComposer::class);
        View::composer('admin.services.partials.machine', ServiceMachineComposer::class);
    }

    /**
     * The "being prepared" card for a paid dedicated server that waits for staff, with its specs
     * only: no IP address, machine name or anything about its BMC.
     *
     * @return array{view: string, data: array{specs: list<array{label: string, value: string}>}}|null
     */
    private function pendingCard(Service $service): ?array
    {
        if ($service->status !== ServiceStatus::Pending || ! $service->product?->uses_machines || ! View::exists(self::PENDING_VIEW)) {
            return null;
        }

        if ($service->order?->invoice?->status !== InvoiceStatus::Paid) {
            return null;
        }

        $machine = Machine::query()->where('service_id', $service->getKey())->first();

        if ($machine === null) {
            return null;
        }

        $specs = [
            [__('CPU'), trim($machine->cpu.($machine->cpu_cores ? ' · '.trans_choice(':count core|:count cores', $machine->cpu_cores) : ''))],
            [__('RAM'), $machine->ram_gb ? __(':count GB', ['count' => $machine->ram_gb]) : ''],
            [__('Storage'), (string) $machine->storage],
            [__('Location'), setting('hardware.show_location') ? (string) $machine->location : ''],
        ];

        return ['view' => self::PENDING_VIEW, 'data' => ['specs' => array_values(array_map(
            fn (array $row): array => ['label' => $row[0], 'value' => $row[1]],
            array_filter($specs, fn (array $row): bool => trim($row[1], ' ·') !== ''),
        ))]];
    }
}
