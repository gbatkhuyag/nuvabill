<?php

namespace Nuvabill\Extensions\Dedicated;

use App\Contracts\ChoosesWelcomeEmail;
use App\Contracts\FinishesSetupLater;
use App\Contracts\HasPanelSections;
use App\Contracts\HasPanelState;
use App\Contracts\HasResourceOptions;
use App\Contracts\LimitsPlanChanges;
use App\Contracts\UsesMachineInventory;
use App\Contracts\WaitsForPayment;
use App\Enums\InvoiceStatus;
use App\Enums\MachineStatus;
use App\Enums\PowerAction;
use App\Enums\PowerState;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Resources\ResourceCheck;
use App\Extensions\Servers\Resources\ResourceExtras;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Extensions\Servers\Resources\ResourceLimit;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Product;
use App\Models\ResourceOption;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Hardware\InventoryException;
use App\Provisioning\Hardware\MachineInventory;
use App\Provisioning\Hardware\PowerControl;
use App\Provisioning\Hardware\PowerResult;
use App\Support\Locales;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Dedicated servers in the host's own rack. Services run on machines from Admin → Hardware, not on
 * a server record: an order reserves a free machine, staff install the OS by hand and press
 * "Server is ready", and only then is the service active and the client emailed (never with a
 * password). Suspend and terminate switch the power through the machine's BMC, and a released
 * machine waits for a wipe before it is sold again.
 *
 * Clients get a server panel (Sections): facts and power state, power buttons, the IP addresses and
 * a reinstall request that opens a ticket. They never see the machine's name, rack or anything
 * about its BMC. Extra IPv4 addresses and monthly traffic are sold as resource options: extra IPs
 * come from the machine's own free extra IPs, and a traffic change waits for staff to set the limit.
 *
 * Every machine status change goes through MachineInventory and every power action through
 * PowerControl, which hold the locks, pauses and the activity log.
 */
class DedicatedModule extends Module implements ChoosesWelcomeEmail, FinishesSetupLater, HasPanelSections, HasPanelState, HasResourceOptions, LimitsPlanChanges, UsesMachineInventory, WaitsForPayment
{
    /**
     * Module data key: the last setup was refused only because the order invoice was not paid.
     */
    public const WAITS_FOR_PAYMENT = 'dedicated_waits_for_payment';

    /**
     * Module data key: the ticket of the client's last reinstall request.
     */
    public const REINSTALL_TICKET = 'reinstall_ticket_id';

    /**
     * Module data key: the extra monthly traffic (GB) staff were last asked to set.
     */
    public const TRAFFIC = 'dedicated_traffic_extra';

    /**
     * The power buttons each "Power buttons for clients" level offers, as standard power keys.
     *
     * @var array<string, list<string>>
     */
    public const POWER_LEVELS = [
        'full' => ['start', 'stop', 'restart', 'reset', 'poweroff'],
        'basic' => ['start', 'stop', 'restart'],
        'none' => [],
    ];

    private ?Sections $sections = null;

    public function productFields(): array
    {
        return [
            'client_power' => [
                'label' => __('Power buttons for clients'),
                'type' => 'select',
                'options' => [
                    'full' => __('All: power on, shut down, restart, force restart and force off'),
                    'basic' => __('Basic: power on, shut down and restart'),
                    'none' => __('None'),
                ],
            ],
            'reinstall_requests' => [
                'label' => __('Clients can ask for a reinstall'),
                'type' => 'select',
                'options' => ['1' => __('Yes, it opens a ticket'), '0' => __('No')],
            ],
            'suspend_mode' => [
                'label' => __('When the service is suspended'),
                'type' => 'select',
                'options' => [
                    'graceful' => __('Shut down, then force off after the wait time'),
                    'force' => __('Force off at once'),
                    'none' => __('Do not touch the power'),
                ],
                'help' => __('Set the wait time under Hardware.'),
            ],
            'suspend_notify_staff' => [
                'label' => __('Email staff to block the network port when suspended'),
                'type' => 'select',
                'options' => ['0' => __('No'), '1' => __('Yes')],
            ],
            'unsuspend_power_on' => [
                'label' => __('Power on again when unsuspended'),
                'type' => 'select',
                'options' => ['1' => __('Yes'), '0' => __('No')],
            ],
            'setup_before_payment' => [
                'label' => __('Start the setup before the order is paid'),
                'type' => 'select',
                'options' => ['0' => __('No'), '1' => __('Yes')],
                'help' => __('With No, your team is asked to install the server only once the order invoice is paid.'),
            ],
            'default_os' => [
                'label' => __('System to install by default'),
                'type' => 'text',
                'help' => __('For your team. It is shown in the install email.'),
            ],
            'login_name' => [
                'label' => __('Login name shown to the client'),
                'type' => 'text',
                'help' => __('Default "root". Your team can change it when they mark the server ready.'),
            ],
        ];
    }

    public function serverHelp(): string
    {
        return __('Dedicated servers do not use a server record. Add your machines under Hardware.');
    }

    public function defaultPort(): int
    {
        return 443;
    }

    public function hasLoginLink(): bool
    {
        return false;
    }

    public function testConnection(Server $server): ModuleResult
    {
        return ModuleResult::fail(__('Dedicated servers have no server record. Test each machine under Hardware.'));
    }

    /**
     * Set up a dedicated server. The first run (after payment, or at order when the product allows
     * it) moves the reserved machine to "setting up" and asks staff to install the OS; the service
     * stays Pending (FinishesSetupLater). After staff press "Server is ready", the next run rents
     * the paid extra IPs and puts the machine in use, so core activates the service. The login
     * details are already on the service then ("Server is ready" saves the password encrypted), so
     * the result never carries a password.
     */
    public function create(Service $service): ModuleResult
    {
        $service->loadMissing('product', 'client');

        if (! self::setsUpBeforePayment($service->product) && $this->orderUnpaid($service)) {
            self::remember($service, [self::WAITS_FOR_PAYMENT => true]);

            return ModuleResult::fail(__('The order is not paid yet.'));
        }

        $inventory = $this->inventory();

        try {
            $machine = $inventory->machineFor($service) ?? $inventory->assign($service);

            if ($machine->status === MachineStatus::Reserved) {
                $machine = $inventory->startProvisioning($machine, $service);
            }
        } catch (InventoryException $exception) {
            return ModuleResult::fail($exception->getMessage());
        }

        $data = ['module_data' => [self::WAITS_FOR_PAYMENT => false]];

        if ($machine->provisioned_at === null) {
            return ModuleResult::ok(__('Waiting for staff to install the OS on :machine.', ['machine' => $machine->name]), $data);
        }

        $applied = $this->applyExtras($service, $machine, $service->resourceExtras());

        if (! $applied->success) {
            return $applied;
        }

        try {
            $inventory->activate($machine);
        } catch (InventoryException $exception) {
            return ModuleResult::fail($exception->getMessage());
        }

        return ModuleResult::ok(__('The server is ready.'), $data);
    }

    /**
     * Still waiting for staff: no machine yet, the OS is not marked installed, or the machine is
     * not in use.
     */
    public function setupPending(Service $service): bool
    {
        $machine = $this->inventory()->machineFor($service);

        return $machine === null || $machine->provisioned_at === null || $machine->status !== MachineStatus::Active;
    }

    public function waitsForPayment(Service $service): bool
    {
        return ($service->module_data[self::WAITS_FOR_PAYMENT] ?? false) === true;
    }

    /**
     * "service.dedicated_ready" with the addresses, login name, host name, location and specs. It
     * never holds a password: the client reads that in the client area.
     */
    public function welcomeEmail(Service $service): array
    {
        $service->loadMissing('client', 'product');
        $machine = $this->inventory()->machineFor($service);
        $addresses = $machine === null ? [] : self::addresses($machine, $service);
        $primary = null;

        foreach ($addresses as $address) {
            $primary ??= $address->kind === MachineIp::KIND_PRIMARY ? $address : null;
        }

        $primary ??= $addresses[0] ?? null;

        $context = Locales::in(Locales::forClient($service->client), fn (): array => [
            'hostname' => (string) $service->domain,
            'ip' => (string) $primary?->address,
            'ips' => implode(', ', array_map(fn (MachineIp $address): string => $address->address, $addresses)),
            'username' => (string) $service->username,
            'location' => $machine !== null && (bool) setting('hardware.show_location') ? (string) $machine->location : '',
            'specs' => $machine === null ? '' : implode(' · ', array_column(self::specs($machine), 'value')),
        ]);

        return ['key' => 'service.dedicated_ready', 'context' => ['machine' => $context]];
    }

    /**
     * A plan change keeps the machine, so it may only go to a product the machine is sold as.
     */
    public function allowsPlanChange(Service $service, Product $target): bool
    {
        $machine = $this->inventory()->machineFor($service);

        return $machine !== null && $machine->products()->whereKey($target->getKey())->exists();
    }

    /**
     * Mark the machine suspended, then switch its power as the product says: a shutdown signal and
     * a forced power off after the wait time, a forced power off at once, or nothing. A BMC that
     * does not answer never stops the suspend: the scheduler keeps trying to switch it off.
     */
    public function suspend(Service $service, string $reason): ModuleResult
    {
        $service->loadMissing('product', 'client');
        $machine = $this->inventory()->machineFor($service);

        if ($machine === null) {
            return ModuleResult::ok(__('No machine was assigned.'));
        }

        $mode = self::suspendMode($service->product);
        $grace = max(1, min(120, (int) setting('hardware.suspend_grace_minutes')));

        try {
            $this->inventory()->suspend($machine, match ($mode) {
                'none' => null,
                'force' => now(),
                default => now()->addMinutes($grace),
            });
        } catch (InventoryException $exception) {
            return ModuleResult::fail($exception->getMessage());
        }

        $message = match ($mode) {
            'none' => __('Marked suspended. The power was not touched.'),
            'force' => $this->suspendPower($machine, $service, PowerAction::Off),
            default => $this->powerState($machine) === PowerState::Off
                ? __('Suspended.').' '.__('The server is already off.')
                : $this->suspendPower($machine, $service, PowerAction::Shutdown),
        };

        if (self::productOption($service->product, 'suspend_notify_staff', '0') === '1') {
            $this->alertStaff($machine, fn (): array => [
                __('Block the network port'),
                __('Service #:service on :machine is suspended. Block its network port until the service is active again.', ['service' => $service->getKey(), 'machine' => $machine->name]),
            ]);
        }

        return ModuleResult::ok($message);
    }

    /**
     * Mark the machine in use again and, when the product says so, switch it on. A BMC that does
     * not answer never stops the unsuspend: the client can switch it on from the client area.
     */
    public function unsuspend(Service $service): ModuleResult
    {
        $service->loadMissing('product', 'client');
        $machine = $this->inventory()->machineFor($service);

        if ($machine === null) {
            return ModuleResult::ok(__('No machine was assigned.'));
        }

        try {
            $this->inventory()->unsuspend($machine);
        } catch (InventoryException $exception) {
            return ModuleResult::fail($exception->getMessage());
        }

        if (self::productOption($service->product, 'unsuspend_power_on', '1') === '0') {
            return ModuleResult::ok(__('Active again.'));
        }

        $result = $this->switchPower($machine, $service, PowerAction::On);

        return ModuleResult::ok($result !== null && $result->ok
            ? __('Active again.').' '.$result->message
            : __('Active again. Power it on from the client area if it stays off.'));
    }

    /**
     * Force the power off (best effort), then release the machine: it waits for a wipe and is never
     * sold again before staff confirm it. When the power off fails, the scheduler retries it.
     */
    public function terminate(Service $service): ModuleResult
    {
        $machine = $this->inventory()->machineFor($service);

        if ($machine === null) {
            return ModuleResult::ok(__('No machine was assigned.'));
        }

        $off = in_array($machine->status, [MachineStatus::Active, MachineStatus::Suspended], true)
            ? $this->switchPower($machine, $service, PowerAction::Off)
            : null;

        $this->inventory()->release($machine, 'terminated');

        return ModuleResult::ok($off !== null && $off->ok
            ? __('The machine is powered off and waits for a wipe.')
            : __('The machine waits for a wipe. It is powered off as soon as its BMC answers.'));
    }

    /**
     * The service keeps its machine on the new plan, so the machine must be sold as the new
     * product. The extras of the new plan are then set.
     */
    public function changePackage(Service $service): ModuleResult
    {
        $service->loadMissing('product', 'client');
        $machine = $this->inventory()->machineFor($service);

        if ($machine === null || ! in_array($machine->status, [MachineStatus::Active, MachineStatus::Suspended], true)) {
            return ModuleResult::fail(__('This service has no machine in use. Assign one on the service page.'));
        }

        if (! $machine->products()->whereKey($service->product_id)->exists()) {
            return ModuleResult::fail(__('This machine is not linked to the new product. Link it under Hardware or replace the machine.'));
        }

        return $this->applyExtras($service, $machine, $service->resourceExtras());
    }

    /**
     * The power state for the panel's status checks: running, stopped or unknown. A machine
     * without usable power control is never asked.
     */
    public function clientPanelState(Service $service): string
    {
        $machine = $this->inventory()->machineFor($service);

        if ($machine === null || $machine->status !== MachineStatus::Active) {
            return 'unknown';
        }

        return $this->powerState($machine)->panelState();
    }

    public function panelSections(Product $product): array
    {
        return $this->sections()->declare($product);
    }

    public function availablePanelSections(Service $service, ClientContext $context): ?array
    {
        return $this->sections()->available($service);
    }

    public function readPanelSection(Service $service, string $section, array $input, ClientContext $context): PanelResult
    {
        return $this->sections()->read($service, $section);
    }

    public function runPanelAction(Service $service, string $section, string $action, array $input, ClientContext $context): PanelResult
    {
        return $this->sections()->run($service, $section, $action, $input);
    }

    /**
     * Extra IPv4 addresses (from the machine's free extra IPs) and extra monthly traffic, sold in
     * steps of 1 TB. Both are built-in kinds with their built-in units.
     */
    public function resourceKinds(Product $product): array
    {
        return [
            'ipv4' => new ResourceKind(
                key: 'ipv4',
                label: ResourceOption::kindLabel('ipv4'),
                unit: 'IP',
                note: __('Add the new addresses to the network settings of your server.'),
            ),
            'bandwidth' => new ResourceKind(
                key: 'bandwidth',
                label: ResourceOption::kindLabel('bandwidth'),
                unit: 'GB',
                minStep: 1000,
                stepMultiple: 1000,
            ),
        ];
    }

    /**
     * The IPv4 addresses that come with the plan (the main and included ones), when every machine
     * sold as the product has the same number. Traffic has no plan value here. No server is asked.
     */
    public function baseResources(Product $product, ?Server $server): array
    {
        $counts = $product->exists
            ? $product->machines()
                ->withCount(['ips as plan_ipv4' => fn ($query) => $query->where('version', 4)->whereIn('kind', [MachineIp::KIND_PRIMARY, MachineIp::KIND_INCLUDED])])
                ->get()
                ->pluck('plan_ipv4')
                ->map(fn (mixed $count): int => (int) $count)
                ->unique()
            : collect();

        return ['ipv4' => $counts->count() === 1 ? (int) $counts->first() : null, 'bandwidth' => null];
    }

    /**
     * A service can rent at most the extra IPs it has plus the free extra IPs of its machine.
     * Without a machine in use (an imported service before "Assign machine", or a machine staff
     * took back) nothing can be set on the server, so additions wait and removals stay open.
     */
    public function resourceLimits(Service $service): array
    {
        $machine = $this->inventory()->machineFor($service);

        if ($machine === null || ! in_array($machine->status, [MachineStatus::Active, MachineStatus::Suspended], true)) {
            $extras = $service->resourceExtras();

            return [
                'ipv4' => new ResourceLimit(maxUnits: $extras->get('ipv4'), reason: __('Your server is not ready for more IP addresses yet. Contact us.')),
                'bandwidth' => new ResourceLimit(maxUnits: $extras->get('bandwidth'), reason: __('Your server is not ready for more traffic yet. Contact us.')),
            ];
        }

        $extras = MachineIp::query()->where('machine_id', $machine->getKey())->where('kind', MachineIp::KIND_EXTRA)->where('version', 4);
        $rented = (clone $extras)->where('service_id', $service->getKey())->count();
        $free = (clone $extras)->whereNull('service_id')->count();

        return ['ipv4' => new ResourceLimit(maxUnits: $rented + $free, reason: __('No more extra IPs are free on your server. Contact us for more.'))];
    }

    /**
     * Rent the paid extra IPs and remember the paid extra traffic. Only staff can set a traffic
     * limit, so a new traffic value comes back as 'manual': the change waits until staff press
     * "Mark done", and only then is the client told. The same traffic again is not a change.
     */
    public function applyResources(Service $service, ResourceExtras $extras): ModuleResult
    {
        $machine = $this->inventory()->machineFor($service);

        if ($machine === null || ! in_array($machine->status, [MachineStatus::Active, MachineStatus::Suspended], true)) {
            return ModuleResult::fail(__('This service has no machine in use. Assign one on the service page.'));
        }

        $trafficChanged = false;
        $applied = $this->applyExtras($service, $machine, $extras, $trafficChanged);

        if (! $applied->success || ! $trafficChanged) {
            return $applied;
        }

        return ModuleResult::ok(__('Set the new traffic limit of :machine by hand, then press Mark done.', ['machine' => $machine->name]), ['manual' => true]);
    }

    /**
     * IPv4: the main and included addresses plus the rented extras, against the same plus the
     * extras paid for. Traffic: the extra traffic staff were last asked to set, against the extra
     * paid for.
     */
    public function checkResources(Service $service, ResourceExtras $extras): ResourceCheck
    {
        $machine = $this->inventory()->machineFor($service);
        $expected = [];
        $live = [];
        $problems = [];

        if ($extras->has('ipv4')) {
            $plan = $machine === null ? 0 : MachineIp::query()->where('machine_id', $machine->getKey())->where('version', 4)->whereIn('kind', [MachineIp::KIND_PRIMARY, MachineIp::KIND_INCLUDED])->count();
            $expected['ipv4'] = $plan + $extras->get('ipv4');
            $live['ipv4'] = $machine === null ? null : $plan + MachineIp::query()->where('machine_id', $machine->getKey())->where('version', 4)->where('kind', MachineIp::KIND_EXTRA)->where('service_id', $service->getKey())->count();
        }

        if ($extras->has('bandwidth')) {
            $expected['bandwidth'] = $extras->get('bandwidth');
            $live['bandwidth'] = (int) ($service->module_data[self::TRAFFIC] ?? 0);
        }

        if ($machine === null) {
            $problems[] = __('This service has no machine in use. Assign one on the service page.');
        }

        foreach ($expected as $kind => $units) {
            if ($live[$kind] !== null && $live[$kind] !== $units) {
                $unit = $kind === 'bandwidth' ? 'GB' : 'IP';
                $problems[] = __(':kind: :live, expected :expected', [
                    'kind' => ResourceOption::kindLabel($kind),
                    'live' => ResourceOption::formatUnits($unit, $live[$kind]),
                    'expected' => ResourceOption::formatUnits($unit, $units),
                ]);
            }
        }

        return new ResourceCheck($expected, $live, $problems);
    }

    /**
     * The machine's addresses the service may use and see: the main and included ones, and the
     * extras this service rents. Main first, then included, then extras, each by id.
     *
     * @return list<MachineIp>
     */
    public static function addresses(Machine $machine, Service $service): array
    {
        $order = [MachineIp::KIND_PRIMARY => 0, MachineIp::KIND_INCLUDED => 1, MachineIp::KIND_EXTRA => 2];

        return MachineIp::query()
            ->where('machine_id', $machine->getKey())
            ->where(fn ($query) => $query
                ->whereIn('kind', [MachineIp::KIND_PRIMARY, MachineIp::KIND_INCLUDED])
                ->orWhere(fn ($query) => $query->where('kind', MachineIp::KIND_EXTRA)->where('service_id', $service->getKey())))
            ->orderBy('id')
            ->get()
            ->sortBy(fn (MachineIp $address): array => [$order[$address->kind] ?? 3, $address->getKey()])
            ->values()
            ->all();
    }

    /**
     * The machine's hardware as label and value rows, translated: CPU (with cores), RAM, storage
     * and port speed. Rows without a value are left out.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function specs(Machine $machine): array
    {
        $rows = [
            ['label' => __('CPU'), 'value' => trim((string) $machine->cpu.($machine->cpu_cores ? ' · '.trans_choice(':count core|:count cores', $machine->cpu_cores) : ''), ' ·')],
            ['label' => __('RAM'), 'value' => $machine->ram_gb ? __(':count GB', ['count' => $machine->ram_gb]) : ''],
            ['label' => __('Storage'), 'value' => (string) $machine->storage],
            ['label' => __('Port speed'), 'value' => (string) $machine->port_speed],
        ];

        return array_values(array_filter($rows, fn (array $row): bool => $row['value'] !== ''));
    }

    /**
     * The client's power level for the product: full, basic or none (full when not set).
     */
    public static function clientPower(Product $product): string
    {
        $level = self::productOption($product, 'client_power', 'full');

        return array_key_exists($level, self::POWER_LEVELS) ? $level : 'full';
    }

    /**
     * Whether clients of the product may ask for a reinstall (on when not set).
     */
    public static function takesReinstallRequests(Product $product): bool
    {
        return self::productOption($product, 'reinstall_requests', '1') !== '0';
    }

    /**
     * A product setting as a trimmed string, or the default when it is empty.
     */
    public static function productOption(Product $product, string $key, string $default): string
    {
        $value = trim((string) (($product->module_config ?? [])[$key] ?? ''));

        return $value === '' ? $default : $value;
    }

    /**
     * Merge values into the service's module data, from the newest saved row, so a save never
     * drops a key another request wrote meanwhile.
     *
     * @param  array<string, mixed>  $values
     */
    public static function remember(Service $service, array $values): void
    {
        $data = DB::transaction(function () use ($service, $values): ?array {
            $current = Service::query()->whereKey($service->getKey())->lockForUpdate()->first();

            if ($current === null) {
                return null;
            }

            $data = array_merge((array) $current->module_data, $values);
            $current->forceFill(['module_data' => $data])->save();

            return $data;
        }, 3);

        if ($data !== null) {
            $service->module_data = $data;
            $service->syncOriginalAttribute('module_data');
        }
    }

    /**
     * The machine's power state, without asking a BMC the site cannot use.
     */
    public function powerState(Machine $machine): PowerState
    {
        return $this->power()->actions($machine) === [] ? PowerState::Unknown : $this->power()->state($machine);
    }

    /**
     * Rent the paid extra IPv4 addresses on the machine, and ask staff to set a new traffic limit.
     *
     * @param  bool  $trafficChanged  Set to whether staff were asked to set a new traffic limit.
     *
     * @param-out bool $trafficChanged
     */
    private function applyExtras(Service $service, Machine $machine, ResourceExtras $extras, bool &$trafficChanged = false): ModuleResult
    {
        $trafficChanged = false;

        if ($extras->has('ipv4')) {
            try {
                $this->inventory()->syncExtraIps($service, max(0, $extras->get('ipv4')));
            } catch (InventoryException $exception) {
                return ModuleResult::fail($exception->missing > 0
                    ? __('Add :count more extra IPs to :machine under Hardware, then try again.', ['count' => $exception->missing, 'machine' => $machine->name])
                    : $exception->getMessage());
            }
        }

        if ($extras->has('bandwidth')) {
            $trafficChanged = $this->askForTrafficLimit($service, $machine, max(0, $extras->get('bandwidth')));
        }

        return ModuleResult::ok(__('The extras are set on :machine.', ['machine' => $machine->name]));
    }

    /**
     * Traffic is a limit staff set on their own network: they get an alert when the extra traffic
     * changed, and the new value is remembered for checkResources(). Returns whether it changed.
     */
    private function askForTrafficLimit(Service $service, Machine $machine, int $units): bool
    {
        if ((int) ($service->module_data[self::TRAFFIC] ?? 0) === $units) {
            return false;
        }

        self::remember($service, [self::TRAFFIC => $units]);

        $this->alertStaff($machine, fn (): array => [
            __('Set the traffic limit'),
            __('Service #:service on :machine now has :traffic of extra monthly traffic. Set its traffic limit to match.', [
                'service' => $service->getKey(),
                'machine' => $machine->name,
                'traffic' => ResourceOption::formatUnits('GB', $units),
            ]),
        ]);

        return true;
    }

    /**
     * The power part of a suspend, as the message for staff. A BMC without a shutdown signal but
     * with a forced power off is switched off by the scheduler after the wait time.
     */
    private function suspendPower(Machine $machine, Service $service, PowerAction $action): string
    {
        $result = $this->switchPower($machine, $service, $action);

        if ($result === null) {
            return $action !== PowerAction::Off && in_array(PowerAction::Off, $this->power()->actions($machine), true)
                ? __('Suspended. The server is forced off after the wait time.')
                : __('Marked suspended. This server has no power control, so switch it off by hand.');
        }

        return $result->ok
            ? __('Suspended.').' '.$result->message
            : __('Marked suspended. The server is turned off as soon as its BMC answers.');
    }

    /**
     * A power action for suspend, unsuspend or terminate, or null when the machine cannot do it.
     * Staff who pressed the button are logged as its actor; otherwise it is automatic.
     */
    private function switchPower(Machine $machine, Service $service, PowerAction $action): ?PowerResult
    {
        if (! in_array($action, $this->power()->actions($machine), true)) {
            return null;
        }

        return $this->power()->run($machine, $action, $this->actor($machine), $service);
    }

    private function actor(Machine $machine): Model
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin ? $admin : $machine;
    }

    /**
     * Whether the service's order invoice is missing, another client's, or not paid. A service
     * without an order (made by staff, or imported) never waits.
     */
    private function orderUnpaid(Service $service): bool
    {
        $order = $service->order()->with('invoice')->first();

        if ($order === null) {
            return false;
        }

        $invoice = $order->invoice;

        return $invoice === null || (int) $invoice->client_id !== (int) $service->client_id || $invoice->status !== InvoiceStatus::Paid;
    }

    /**
     * Email staff an "admin.machine_alert", written in the site's language.
     *
     * @param  Closure(): array{0: string, 1: string}  $texts  The title and the message.
     */
    private function alertStaff(Machine $machine, Closure $texts): void
    {
        [$title, $message] = Locales::in(Locales::default(), $texts);

        rescue(fn () => app(TemplateMailer::class)->sendToStaff('admin.machine_alert', [
            'machine' => ['name' => $machine->name, 'location' => (string) $machine->location],
            'alert' => ['title' => $title, 'message' => $message],
            'admin_url' => MachineInventory::adminUrl($machine),
        ]));
    }

    private static function suspendMode(Product $product): string
    {
        $mode = self::productOption($product, 'suspend_mode', 'graceful');

        return in_array($mode, ['graceful', 'force', 'none'], true) ? $mode : 'graceful';
    }

    private static function setsUpBeforePayment(Product $product): bool
    {
        return self::productOption($product, 'setup_before_payment', '0') === '1';
    }

    private function sections(): Sections
    {
        return $this->sections ??= new Sections($this, $this->inventory(), $this->power());
    }

    private function inventory(): MachineInventory
    {
        return app(MachineInventory::class);
    }

    private function power(): PowerControl
    {
        return app(PowerControl::class);
    }
}
