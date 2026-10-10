<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BmcType;
use App\Enums\MachineStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MachineRequest;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Product;
use App\Models\Service;
use App\Models\TicketDepartment;
use App\Provisioning\Hardware\Drivers\IpmiTool;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use App\Provisioning\Hardware\InventoryException;
use App\Provisioning\Hardware\MachineInventory;
use App\Provisioning\Hardware\Tls\PinningSupport;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Hardware: the dedicated servers in your own rack. Free machines are the stock of the
 * products they are linked to. A machine's status only moves through MachineInventory, so a
 * machine is never sold again before its disks are wiped.
 *
 * The BMC login is write-only: it is never put back into a page, and blank fields keep the saved
 * one. When the BMC type, address or port changes, staff must enter the login again and trust the
 * new certificate, so a saved login never goes to a new address.
 */
class MachineController extends Controller
{
    /** Session key prefix of the certificate a BMC test showed, waiting for staff to trust it. */
    public const TRUST_SESSION = 'machine_trust.';

    /** Session key prefix of the systems a BMC test listed, waiting for staff to pick one. */
    public const SYSTEMS_SESSION = 'machine_systems.';

    /** Seconds a test result in the session may be used for trusting or picking a system. */
    public const SESSION_SECONDS = 300;

    /** Statuses staff may pick on a free machine. */
    private const STAFF_STATUSES = [MachineStatus::Available, MachineStatus::Maintenance, MachineStatus::Decommissioned];

    /**
     * The machine list with filters (status, product, location, search) and stock per product.
     */
    public function index(Request $request): View
    {
        $status = MachineStatus::tryFrom((string) $request->query('status'));
        $productId = filter_var($request->query('product'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $location = mb_substr(trim((string) $request->query('location')), 0, 100);
        $term = mb_substr(trim((string) $request->query('q')), 0, 100);
        $admin = $this->admin($request);

        $machines = Machine::query()
            ->with(['service.client', 'service.product', 'products:id,name', 'primaryIp'])
            ->when($status, fn ($query, MachineStatus $status) => $query->where('status', $status->value))
            ->when($productId, fn ($query, int $id) => $query->whereHas('products', fn ($query) => $query->whereKey($id)))
            ->when($location !== '', fn ($query) => $query->where('location', $location))
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhereHas('ips', fn ($query) => $query->where('address', 'like', "%{$term}%"))))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $counts = Machine::query()->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn (mixed $total): int => (int) $total);
        $maySettings = $admin->hasPermission('hardware.manage') && $admin->hasPermission('settings.manage');

        return view('admin.machines.index', [
            'machines' => $machines,
            'counts' => $counts,
            'filters' => ['status' => $status, 'product' => $productId, 'location' => $location, 'q' => $term],
            'stock' => Product::query()
                ->where('uses_machines', true)
                ->withCount(['machines', 'machines as free_count' => fn ($query) => $query->where('machines.status', MachineStatus::Available->value)])
                ->orderBy('name')
                ->get(['id', 'name']),
            'locations' => Machine::query()->whereNotNull('location')->where('location', '!=', '')->distinct()->orderBy('location')->pluck('location'),
            'mayManage' => $admin->hasPermission('hardware.manage'),
            'settings' => $maySettings ? [
                'os_choices' => implode(PHP_EOL, (array) setting('hardware.os_choices')),
                'ticket_department' => setting('hardware.ticket_department'),
                'suspend_grace_minutes' => (int) setting('hardware.suspend_grace_minutes'),
                'show_location' => (bool) setting('hardware.show_location'),
                'allow_ipmi' => (bool) setting('hardware.allow_ipmi'),
                'departments' => TicketDepartment::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
                'ipmi_found' => app(IpmiTool::class)->path() !== null,
            ] : null,
        ]);
    }

    /**
     * One machine: details, IP addresses, the service on it, BMC status, power and history.
     */
    public function show(Machine $machine): View
    {
        $machine->load(['service.client', 'service.product', 'products:id,name,slug', 'ips' => fn ($query) => $query->orderBy('id'), 'ips.service']);
        $admin = $this->admin(request());

        $history = ActivityLog::query()
            ->where('subject_type', $machine->getMorphClass())
            ->where('subject_id', $machine->getKey())
            ->unless($admin->hasPermission('billing.view'), fn ($query) => $query->withoutBilling())
            ->with('actor')
            ->latest('id')
            ->limit(20)
            ->get();

        return view('admin.machines.show', [
            'machine' => $machine,
            'history' => $history,
            'lastService' => $machine->last_service_id !== null ? Service::query()->with('client')->find($machine->last_service_id) : null,
            'mayManage' => $admin->hasPermission('hardware.manage'),
            'mayServices' => $admin->hasPermission('services.manage'),
            'mayClients' => $admin->hasPermission('clients.view'),
            'mayProducts' => $admin->hasPermission('products.manage'),
            'trust' => self::sessionValue(self::TRUST_SESSION, $machine),
            'systems' => self::sessionValue(self::SYSTEMS_SESSION, $machine)['paths'] ?? [],
            'pinning' => app(PinningSupport::class)->available(),
            'driverLabel' => self::driverLabel($machine),
            'deletable' => self::deletable($machine),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Machine(['bmc_type' => BmcType::None->value, 'bmc_tls_mode' => 'pinned']));
    }

    public function store(MachineRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $machine = $this->saving(function () use ($data, $request): Machine {
            $machine = new Machine;
            $machine->fill($this->details($data));
            $this->fillBmc($machine, $data, addressChanged: true);
            $machine->forceFill(['status' => MachineStatus::tryFrom((string) ($data['status'] ?? '')) ?? MachineStatus::Available]);
            $machine->save();

            $machine->products()->sync(array_map('intval', $data['product_ids'] ?? []));
            $this->syncIps($machine, $request->ipLines());
            Activity::log('machine.created', "Machine {$machine->name} added", $machine);

            return $machine;
        });

        return redirect($this->pageOf($machine, $request))->with('status', $machine->hasBmc()
            ? __('Machine added. Press Test connection to check its BMC.')
            : __('Machine added.'));
    }

    public function edit(Machine $machine): View
    {
        return $this->form($machine->load(['products:id', 'ips' => fn ($query) => $query->orderBy('id')]));
    }

    public function update(MachineRequest $request, Machine $machine): RedirectResponse
    {
        $data = $request->validated();
        $addressChanged = $request->bmcAddressChanged();

        $this->saving(function () use ($machine, $data, $request, $addressChanged): void {
            $machine->fill($this->details($data));
            $this->fillBmc($machine, $data, $addressChanged);
            $machine->save();

            $machine->products()->sync(array_map('intval', $data['product_ids'] ?? []));
            $this->syncIps($machine, $request->ipLines());
            Activity::log('machine.updated', "Machine {$machine->name} updated".($addressChanged ? ' (BMC address or type changed)' : ''), $machine);
        });

        if ($addressChanged) {
            $request->session()->forget([self::TRUST_SESSION.$machine->getKey(), self::SYSTEMS_SESSION.$machine->getKey()]);
        }

        return redirect($this->pageOf($machine, $request))->with('status', $addressChanged && $machine->hasBmc()
            ? __('Machine saved. Press Test connection to check its BMC.')
            : __('Machine saved.'));
    }

    /**
     * Delete a machine that never held a service; any other machine can only be retired.
     */
    public function destroy(Machine $machine): RedirectResponse
    {
        $deleted = DB::transaction(function () use ($machine): bool {
            $locked = Machine::query()->whereKey($machine->getKey())->lockForUpdate()->first();

            if ($locked === null || ! self::deletable($locked)) {
                return false;
            }

            Activity::log('machine.deleted', "Machine {$locked->name} deleted", $locked);
            $locked->delete();

            return true;
        }, 3);

        if (! $deleted) {
            return back()->with('error', __('This machine has history. Retire it instead.'));
        }

        return redirect()->route('admin.machines.index')->with('status', __('Machine deleted.'));
    }

    /**
     * Move a machine to available, maintenance or retired, when its status allows it.
     */
    public function status(Request $request, Machine $machine): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in(array_map(fn (MachineStatus $status): string => $status->value, self::STAFF_STATUSES))],
        ]);
        $to = MachineStatus::from($data['to']);

        try {
            app(MachineInventory::class)->setStatus($machine, $to, $this->admin($request));
        } catch (InventoryException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __(':machine is now :status.', ['machine' => $machine->name, 'status' => $to->label()]));
    }

    /**
     * Staff confirm the disks of a machine were wiped, so it can be sold again.
     */
    public function wiped(Request $request, Machine $machine): RedirectResponse
    {
        $data = $request->validate([
            'confirm_wiped' => ['accepted'],
            'next' => ['required', Rule::in([MachineStatus::Available->value, MachineStatus::Maintenance->value])],
        ], [
            'confirm_wiped.accepted' => __('Tick the box to confirm that all disks were erased.'),
        ]);
        $next = MachineStatus::from($data['next']);

        try {
            app(MachineInventory::class)->confirmWiped($machine, $this->admin($request), $next);
        } catch (InventoryException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', $next === MachineStatus::Available
            ? __('Wipe confirmed. :machine is on sale again.', ['machine' => $machine->name])
            : __('Wipe confirmed. :machine is in maintenance.', ['machine' => $machine->name]));
    }

    /**
     * Save the hardware settings (the systems clients may ask for, ticket department, wait times).
     */
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'os_choices' => ['required', 'string', 'max:2000'],
            'ticket_department' => ['nullable', 'integer', Rule::exists('ticket_departments', 'id')],
            'suspend_grace_minutes' => ['required', 'integer', 'between:1,120'],
            'show_location' => ['boolean'],
            'allow_ipmi' => ['boolean'],
        ], [], [
            'os_choices' => __('Systems clients can ask for'),
            'ticket_department' => __('Department for reinstall requests'),
            'suspend_grace_minutes' => __('Minutes to wait before forcing the power off'),
        ]);

        $choices = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', $data['os_choices']) ?: []), fn (string $line): bool => $line !== '')));
        $problems = [];

        if ($choices === [] || count($choices) > 20) {
            $problems['os_choices'] = __('Enter 1 to 20 systems, one per line.');
        } elseif (($bad = collect($choices)->first(fn (string $choice): bool => mb_strlen($choice) > 60 || preg_match('/^[\pL\pN .()+-]+$/u', $choice) !== 1)) !== null) {
            $problems['os_choices'] = __('":name" is too long or has signs that are not allowed. Use letters, numbers, spaces and . ( ) + - only, at most 60 characters.', ['name' => mb_substr($bad, 0, 60)]);
        }

        $allowIpmi = $request->boolean('allow_ipmi');

        if ($allowIpmi && ! (bool) setting('hardware.allow_ipmi') && app(IpmiTool::class)->path() === null) {
            $problems['allow_ipmi'] = __('ipmitool was not found on this server, so IPMI cannot be switched on.');
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        app(Settings::class)->setMany([
            'hardware.os_choices' => $choices,
            'hardware.ticket_department' => isset($data['ticket_department']) ? (int) $data['ticket_department'] : null,
            'hardware.suspend_grace_minutes' => (int) $data['suspend_grace_minutes'],
            'hardware.show_location' => $request->boolean('show_location'),
            'hardware.allow_ipmi' => $allowIpmi,
        ]);
        app()->forgetInstance(PowerDrivers::class);
        Activity::log('settings.hardware', 'Hardware settings changed'.($allowIpmi ? ' (IPMI on)' : ''));

        return redirect()->route('admin.machines.index')->with('status', __('Hardware settings saved.'));
    }

    /**
     * Whether a machine never held a service, so deleting it loses no history.
     */
    public static function deletable(Machine $machine): bool
    {
        return $machine->service_id === null
            && $machine->last_service_id === null
            && in_array($machine->status, self::STAFF_STATUSES, true);
    }

    /**
     * A BMC test result saved in the session for this machine, while it is fresh.
     *
     * @return array<string, mixed>|null
     */
    public static function sessionValue(string $prefix, Machine $machine): ?array
    {
        $value = session($prefix.$machine->getKey());

        if (! is_array($value) || ! is_int($value['at'] ?? null) || $value['at'] < now()->getTimestamp() - self::SESSION_SECONDS) {
            return null;
        }

        return $value;
    }

    /**
     * The name of the machine's power control type, also for a type no installed driver knows.
     */
    public static function driverLabel(Machine $machine): string
    {
        if (! $machine->hasBmc()) {
            return BmcType::None->label();
        }

        return app(PowerDrivers::class)->get((string) $machine->bmc_type)?->label()
            ?? $machine->bmcType()?->label()
            ?? __('Unknown type ":type"', ['type' => mb_substr((string) $machine->bmc_type, 0, 40)]);
    }

    private function form(Machine $machine): View
    {
        $ips = $machine->exists ? $machine->ips : collect();
        $line = fn (MachineIp $ip): string => $ip->address.($ip->prefix !== null ? '/'.$ip->prefix : '').($ip->gateway ? ' via '.$ip->gateway : '');
        $primary = $ips->firstWhere('kind', MachineIp::KIND_PRIMARY);
        $types = [BmcType::None->value => BmcType::None->label()] + app(PowerDrivers::class)->available();

        if ($machine->exists && ! array_key_exists((string) $machine->bmc_type, $types)) {
            $types[(string) $machine->bmc_type] = self::driverLabel($machine);
        }

        return view('admin.machines.form', [
            'machine' => $machine,
            'products' => Product::query()->where('uses_machines', true)->with('group:id,name')->orderBy('name')->get(['id', 'name', 'product_group_id']),
            'linked' => $machine->exists ? $machine->products->modelKeys() : [],
            'types' => $types,
            'ips' => [
                'primary' => $primary?->address,
                'prefix' => $primary?->prefix,
                'gateway' => $primary?->gateway,
                'included' => $ips->where('kind', MachineIp::KIND_INCLUDED)->map($line)->implode(PHP_EOL),
                'extra' => $ips->where('kind', MachineIp::KIND_EXTRA)->map($line)->implode(PHP_EOL),
                'rented' => $ips->where('kind', MachineIp::KIND_EXTRA)->whereNotNull('service_id')->count(),
            ],
            'pinning' => app(PinningSupport::class)->available(),
            'savedLogin' => $machine->exists && filled($machine->bmc_username) && filled($machine->bmc_password),
        ]);
    }

    /**
     * The descriptive fields of the form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(array $data): array
    {
        return Arr::only($data, ['name', 'location', 'rack', 'rack_unit', 'cpu', 'cpu_cores', 'ram_gb', 'storage', 'port_speed', 'notes']);
    }

    /**
     * Fill the BMC fields. Without power control no login is kept. A changed address keeps nothing
     * that was learned from or trusted for the old one; the login was entered again (MachineRequest).
     *
     * @param  array<string, mixed>  $data
     */
    private function fillBmc(Machine $machine, array $data, bool $addressChanged): void
    {
        $type = (string) $data['bmc_type'];
        $modeChanged = $machine->exists && $machine->bmc_tls_mode !== $data['bmc_tls_mode'];

        $machine->fill([
            'bmc_type' => $type,
            'bmc_host' => filled($data['bmc_host'] ?? null) ? trim((string) $data['bmc_host']) : null,
            'bmc_port' => $data['bmc_port'] ?? null,
            'bmc_tls_mode' => $data['bmc_tls_mode'],
            'bmc_ipmi_cipher' => $type === BmcType::Ipmi->value ? (int) ($data['bmc_ipmi_cipher'] ?? 17) : null,
        ]);

        if ($type === BmcType::None->value) {
            $machine->fill(['bmc_username' => null, 'bmc_password' => null]);
        } else {
            foreach (['bmc_username', 'bmc_password'] as $secret) {
                if (filled($data[$secret] ?? null)) {
                    $machine->{$secret} = (string) $data[$secret];
                }
            }
        }

        if ($addressChanged || $type === BmcType::None->value) {
            $machine->forceFill([
                'bmc_tls_pin' => null,
                'bmc_tls_fingerprint' => null,
                'bmc_system_path' => null,
                'bmc_reset_target' => null,
                'bmc_reset_types' => null,
                'bmc_vendor' => null,
                'bmc_model' => null,
                'bmc_checked_at' => null,
                'bmc_failures' => 0,
                'bmc_last_error' => null,
                'power_state' => null,
                'power_checked_at' => null,
            ]);
        } elseif ($modeChanged) {
            // A pin is only trusted for the check mode it was made for.
            $machine->forceFill(['bmc_tls_pin' => null, 'bmc_tls_fingerprint' => null]);
        }
    }

    /**
     * Make the machine's IP rows match the form, in the caller's transaction. An extra IP a
     * service rents cannot be removed or turned into another kind.
     *
     * @param  list<array{address: string, version: int, prefix: ?int, gateway: ?string, kind: string}>  $lines
     *
     * @throws ValidationException
     */
    private function syncIps(Machine $machine, array $lines): void
    {
        // The machine first, then its IP rows: the lock order MachineInventory uses.
        Machine::query()->whereKey($machine->getKey())->lockForUpdate()->first();
        $existing = MachineIp::query()->where('machine_id', $machine->getKey())->orderBy('id')->lockForUpdate()->get()->keyBy('address');
        $wanted = collect($lines)->keyBy('address');

        foreach ($existing as $address => $ip) {
            $line = $wanted->get($address);

            if ($ip->service_id !== null && ($line === null || $line['kind'] !== MachineIp::KIND_EXTRA)) {
                throw ValidationException::withMessages(['extra_ips' => __(':ip is rented by service #:service. Lower its extra IPs first.', [
                    'ip' => $address,
                    'service' => $ip->service_id,
                ])]);
            }
        }

        foreach ($existing as $address => $ip) {
            if (! $wanted->has($address)) {
                $ip->delete();
            }
        }

        foreach ($lines as $line) {
            $ip = $existing->get($line['address']) ?? new MachineIp(['machine_id' => $machine->getKey(), 'address' => $line['address']]);
            $ip->fill(['version' => $line['version'], 'prefix' => $line['prefix'], 'gateway' => $line['gateway'], 'kind' => $line['kind']]);

            if ($ip->isDirty() || ! $ip->exists) {
                $ip->save();
            }
        }
    }

    /**
     * Run a machine save in one transaction. Another machine that took one of the IP addresses a
     * moment ago gives a form error instead of a crash.
     *
     * @template T
     *
     * @param  callable(): T  $save
     * @return T
     *
     * @throws ValidationException
     */
    private function saving(callable $save): mixed
    {
        try {
            return DB::transaction($save, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('Another machine was saved with the same name or IP address just now. Check the form and try again.')]);
        }
    }

    /**
     * Where to go after saving: the machine's page, or its form for staff who may not see pages.
     */
    private function pageOf(Machine $machine, Request $request): string
    {
        return $this->admin($request)->hasPermission('hardware.power')
            ? route('admin.machines.show', $machine)
            : route('admin.machines.edit', $machine);
    }

    private function admin(Request $request): Admin
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
