<?php

namespace Nuvabill\Extensions\Dedicated;

use App\Enums\MachineStatus;
use App\Enums\PowerAction;
use App\Enums\PowerState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Extensions\Servers\Panel\PanelAction;
use App\Extensions\Servers\Panel\PanelException;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Panel\PanelSection;
use App\Extensions\Servers\Panel\StandardSections;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Provisioning\Hardware\MachineInventory;
use App\Provisioning\Hardware\PowerControl;
use App\Support\Activity;
use App\Support\TicketDesk;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * The client server panel of a dedicated server, in the standard section shapes: the overview
 * (facts, addresses and the power state), the power buttons, the IP addresses and a reinstall
 * request that opens a ticket for staff.
 *
 * Core has already checked the owner, the active service, the product's switches, the input and
 * the throttles. Each read and action checks again that the service holds an active machine, and
 * no answer ever holds the machine's name, rack, notes or anything about its BMC.
 */
final class Sections
{
    /**
     * Power actions per service: [max, per seconds], shared by every power button.
     *
     * @var array{0: int, 1: int}
     */
    public const POWER_THROTTLE = [6, 600];

    /**
     * Reinstall requests per service: [max, per seconds].
     *
     * @var array{0: int, 1: int}
     */
    public const REINSTALL_THROTTLE = [3, 86400];

    /**
     * Standard power keys and the machine power action each one sends.
     *
     * @var array<string, PowerAction>
     */
    public const POWER_ACTIONS = [
        'start' => PowerAction::On,
        'stop' => PowerAction::Shutdown,
        'restart' => PowerAction::Restart,
        'reset' => PowerAction::Reset,
        'poweroff' => PowerAction::Off,
    ];

    /**
     * Seconds one reinstall request holds its lock, so a double click opens one ticket.
     */
    private const REQUEST_LOCK_SECONDS = 30;

    public function __construct(
        private DedicatedModule $module,
        private MachineInventory $inventory,
        private PowerControl $power,
    ) {}

    /**
     * The product's sections, from its settings only: overview and IP addresses always, the
     * reinstall request when clients may ask for one, and the power buttons of its power level.
     *
     * @return list<PanelSection>
     */
    public function declare(Product $product): array
    {
        $sections = [
            PanelSection::standard('overview', cacheSeconds: 15, readThrottle: [30, 60]),
            PanelSection::standard('network', cacheSeconds: 30, readThrottle: [30, 60]),
        ];

        if (DedicatedModule::takesReinstallRequests($product)) {
            $sections[] = PanelSection::standard('reinstall', actions: [
                'request' => new PanelAction(
                    key: 'request',
                    label: __('Ask for a reinstall'),
                    rules: [
                        'template' => ['required', 'string', 'max:60', Rule::in(self::osChoices())],
                        'note' => ['nullable', 'string', 'max:2000'],
                        'confirm' => ['accepted'],
                    ],
                    changesServer: false,
                    throttle: self::REINSTALL_THROTTLE,
                ),
            ], readThrottle: [30, 60], staffHelp: __('A request opens a ticket for your team. Set the systems and the department under Hardware.'));
        }

        $actions = [];

        foreach (DedicatedModule::POWER_LEVELS[DedicatedModule::clientPower($product)] as $key) {
            $actions[$key] = StandardSections::powerAction($key, self::POWER_ACTIONS[$key]->label())->withThrottle(self::POWER_THROTTLE);
        }

        if ($actions !== []) {
            $sections[] = PanelSection::standard('power', actions: $actions);
        }

        return $sections;
    }

    /**
     * Only the overview while the service has no machine in use; the power buttons only when the
     * machine's BMC can do at least one of the product's power actions. Never asks the BMC.
     *
     * @return list<string>
     */
    public function available(Service $service): array
    {
        $machine = $this->activeMachine($service);

        if ($machine === null) {
            return ['overview'];
        }

        $keys = ['overview', 'network', 'reinstall'];
        $allowed = DedicatedModule::POWER_LEVELS[DedicatedModule::clientPower($service->product)];

        foreach ($this->power->actions($machine) as $action) {
            if (in_array(array_search($action, self::POWER_ACTIONS, true), $allowed, true)) {
                $keys[] = 'power';

                break;
            }
        }

        return $keys;
    }

    public function read(Service $service, string $section): PanelResult
    {
        $machine = $this->machineOf($service);

        return match ($section) {
            'overview' => $this->overview($service, $machine),
            'network' => $this->network($service, $machine),
            'reinstall' => $this->reinstall($service),
            default => throw new PanelException(__('This feature is not available for this service.'), null, 404),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function run(Service $service, string $section, string $action, array $input): PanelResult
    {
        $machine = $this->machineOf($service);

        return match (true) {
            $section === 'power' => $this->switchPower($service, $machine, $action),
            $section === 'reinstall' && $action === 'request' => $this->requestReinstall($service, (string) ($input['template'] ?? ''), $input['note'] ?? null),
            default => throw new PanelException(__('This action is not available.'), null, 404),
        };
    }

    private function overview(Service $service, Machine $machine): PanelResult
    {
        $state = $this->module->powerState($machine);
        $addresses = DedicatedModule::addresses($machine, $service);
        $primary = null;

        foreach ($addresses as $address) {
            $primary ??= $address->kind === MachineIp::KIND_PRIMARY ? $address : null;
        }

        $facts = [
            ['label' => __('Plan'), 'value' => (string) $service->product->name],
            ['label' => __('Hostname'), 'value' => (string) $service->domain],
            ['label' => __('Location'), 'value' => (bool) setting('hardware.show_location') ? (string) $machine->location : ''],
            ...DedicatedModule::specs($machine),
            ['label' => __('Login name'), 'value' => (string) $service->username],
            ['label' => __('Gateway'), 'value' => (string) $primary?->gateway],
        ];

        $notices = [];

        if (DedicatedModule::clientPower($service->product) !== 'none' && $this->power->actions($machine) === []) {
            $notices[] = ['tone' => 'info', 'text' => __('The power buttons are not available for this server. Open a ticket if it needs a restart.')];
        }

        return PanelResult::ok('', [
            'state' => match ($state) {
                PowerState::On => 'running',
                PowerState::Off => 'stopped',
                PowerState::Changing => 'busy',
                PowerState::Unknown => 'unknown',
            },
            'state_text' => $state->label(),
            'cpu' => null,
            'ram' => null,
            'disk' => null,
            'bandwidth' => null,
            'network' => null,
            'facts' => array_values(array_filter($facts, fn (array $fact): bool => trim($fact['value']) !== '')),
            'ips' => array_map(fn (MachineIp $address): array => self::addressRow($address), $addresses),
            'resources' => [],
            'notices' => $notices,
            'power_note' => $this->lastPowerAction($service, $machine),
            'live' => true,
        ]);
    }

    private function network(Service $service, Machine $machine): PanelResult
    {
        return PanelResult::ok('', [
            'ips' => array_map(fn (MachineIp $address): array => self::addressRow($address) + [
                'rdns' => null,
                'prefix' => $address->prefix,
                'gateway' => $address->gateway,
            ], DedicatedModule::addresses($machine, $service)),
            'subnets' => [],
            'rdns' => false,
        ]);
    }

    private function reinstall(Service $service): PanelResult
    {
        $choices = self::osChoices();
        $open = $this->openRequest($service);

        return PanelResult::ok('', [
            'mode' => 'request',
            'templates' => array_map(fn (string $os): array => ['id' => $os, 'name' => $os, 'group' => __('Operating system')], $choices),
            'credential_mode' => 'none',
            'recipe' => false,
            'left_this_month' => null,
            'note' => true,
            'open_request' => $open === null ? null : self::requestLink($open),
            'warning' => __('Our team reinstalls your server and replies in a ticket. All data on the server is erased.'),
            'can' => ['request' => $choices !== []],
        ]);
    }

    /**
     * Send a power action of the product's power level to the machine, for the client.
     */
    private function switchPower(Service $service, Machine $machine, string $key): PanelResult
    {
        $action = self::POWER_ACTIONS[$key] ?? null;
        $allowed = DedicatedModule::POWER_LEVELS[DedicatedModule::clientPower($service->product)];
        $client = $service->client;

        if ($action === null || ! in_array($key, $allowed, true) || ! $client instanceof Client) {
            throw new PanelException(__('This action is not available.'), null, 403);
        }

        $result = $this->power->run($machine, $action, $client, $service);

        if (! $result->ok) {
            return PanelResult::fail($result->message, null, $result->busy ? 409 : 422);
        }

        $state = match ($result->state) {
            PowerState::On => 'running',
            PowerState::Off => 'stopped',
            default => null,
        };

        return PanelResult::ok($result->message, [], $key, $state);
    }

    /**
     * Open a ticket that asks staff to reinstall the server, one at a time per service.
     */
    private function requestReinstall(Service $service, string $os, mixed $note): PanelResult
    {
        $client = $service->client;

        if (! DedicatedModule::takesReinstallRequests($service->product) || ! $client instanceof Client || ! in_array($os, self::osChoices(), true)) {
            throw new PanelException(__('This action is not available.'), null, 403);
        }

        $lock = Cache::lock('nuvabill:dedicated:reinstall:'.$service->getKey(), self::REQUEST_LOCK_SECONDS);

        if (! $lock->get()) {
            throw new PanelException(__('Another change is running on this server. Try again in a moment.'), null, 409);
        }

        try {
            $service->refresh();
            $open = $this->openRequest($service);

            if ($open !== null) {
                throw new PanelException(__('You already asked for a reinstall. See ticket #:number.', ['number' => $open->number]), null, 409);
            }

            $department = $this->department()
                ?? throw new PanelException(__('We cannot take reinstall requests right now. Please open a ticket.'), 'No ticket department exists for reinstall requests.', 503);

            $message = __('Please reinstall my server with :os. I understand that all data on it is erased.', ['os' => $os]);
            $note = is_string($note) ? trim($note) : '';

            if ($note !== '') {
                $message .= "\n\n".__('Notes: :note', ['note' => $note]);
            }

            $ticket = app(TicketDesk::class)->open($client, $department, __('Reinstall request: :os', ['os' => $os]), $message, TicketPriority::Medium, $service);
            DedicatedModule::remember($service, [DedicatedModule::REINSTALL_TICKET => $ticket->getKey()]);

            Activity::log('machine.reinstall_requested', "Reinstall request for service #{$service->getKey()}: ticket #{$ticket->number} ({$os})", $service, $client, $client);
        } finally {
            $lock->release();
        }

        return PanelResult::ok(
            __('We opened ticket #:number. We reply there when your server is ready.', ['number' => $ticket->number]),
            ['open_request' => self::requestLink($ticket)],
            logDetail: "ticket #{$ticket->number}",
        );
    }

    /**
     * "Last power action: Restart, 5 minutes ago, by you." from the activity log of this machine
     * since this service got it, and only the client's own entries: never a previous holder's.
     */
    private function lastPowerAction(Service $service, Machine $machine): ?string
    {
        $entry = ActivityLog::query()
            ->where('action', 'machine.power')
            ->where('subject_type', $machine->getMorphClass())
            ->where('subject_id', $machine->getKey())
            ->where('client_id', $service->client_id)
            ->when($machine->provisioned_at !== null, fn ($query) => $query->where('created_at', '>=', $machine->provisioned_at))
            ->latest('id')
            ->first();

        if ($entry === null || preg_match('/power action "([a-z]+)"/', (string) $entry->description, $match) !== 1) {
            return null;
        }

        $action = PowerAction::tryFrom($match[1]);

        if ($action === null) {
            return null;
        }

        $values = ['action' => $action->label(), 'time' => $entry->created_at?->diffForHumans() ?? ''];

        return match (true) {
            $entry->actor_type === (new Client)->getMorphClass() && (int) $entry->actor_id === (int) $service->client_id => __('Last power action: :action, :time, by you.', $values),
            $entry->actor_type === (new Admin)->getMorphClass() => __('Last power action: :action, :time, by our team.', $values),
            default => __('Last power action: :action, :time, done automatically.', $values),
        };
    }

    /**
     * The client's open reinstall request for the service, if any.
     */
    private function openRequest(Service $service): ?Ticket
    {
        $id = $service->module_data[DedicatedModule::REINSTALL_TICKET] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        return Ticket::query()
            ->whereKey((int) $id)
            ->where('client_id', $service->client_id)
            ->where('service_id', $service->getKey())
            ->where('status', '!=', TicketStatus::Closed->value)
            ->first();
    }

    /**
     * The department set under Hardware, else the first one clients can see.
     */
    private function department(): ?TicketDepartment
    {
        $id = setting('hardware.ticket_department');
        $chosen = is_numeric($id) ? TicketDepartment::query()->find((int) $id) : null;

        return $chosen ?? TicketDepartment::query()->visible()->orderBy('sort_order')->orderBy('id')->first();
    }

    /**
     * The service's machine, when it is in use by the service.
     */
    private function activeMachine(Service $service): ?Machine
    {
        $machine = $this->inventory->machineFor($service);

        return $machine !== null && $machine->status === MachineStatus::Active ? $machine : null;
    }

    /**
     * @throws PanelException When the service has no machine in use.
     */
    private function machineOf(Service $service): Machine
    {
        return $this->activeMachine($service) ?? throw new PanelException(__('This server is not ready yet.'), null, 403);
    }

    /**
     * The systems staff offer for reinstalls (Hardware settings).
     *
     * @return list<string>
     */
    private static function osChoices(): array
    {
        $choices = array_filter((array) setting('hardware.os_choices'), fn (mixed $os): bool => is_string($os) && trim($os) !== '');

        return array_slice(array_values(array_unique(array_map('trim', $choices))), 0, 20);
    }

    /**
     * @return array{ip: string, version: int, primary: bool, label: string}
     */
    private static function addressRow(MachineIp $address): array
    {
        return [
            'ip' => $address->address,
            'version' => (int) $address->version,
            'primary' => $address->kind === MachineIp::KIND_PRIMARY,
            'label' => match ($address->kind) {
                MachineIp::KIND_PRIMARY => __('Main IP'),
                MachineIp::KIND_INCLUDED => __('Included'),
                default => __('Extra'),
            },
        ];
    }

    /**
     * @return array{number: string, url: string}
     */
    private static function requestLink(Ticket $ticket): array
    {
        return ['number' => (string) $ticket->number, 'url' => route('client.tickets.show', $ticket)];
    }
}
