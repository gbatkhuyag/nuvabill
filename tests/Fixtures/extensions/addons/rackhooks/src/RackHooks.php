<?php

namespace Tests\Fixtures\RackHooks;

use App\Events\DedicatedServerActivated;
use App\Events\DedicatedServerReleased;
use App\Events\DedicatedServerSuspended;
use App\Events\DedicatedServerUnsuspended;
use App\Extensions\Addons\Addon;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelAction;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Panel\PanelSection;
use App\Models\Admin;
use App\Models\Machine;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\Hardware\DedicatedPanelExtension;
use App\Provisioning\Hardware\DedicatedPanelExtensions;
use App\Provisioning\Hardware\MachineInstaller;
use App\Provisioning\Hardware\MachineInstallers;
use App\Provisioning\Hardware\MachineSections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Uses every dedicated server hook, for the tests: a card on the machine page, a tab in the client
 * panel, an installer, the machine events and a Hardware page.
 */
class RackHooks extends Addon implements DedicatedPanelExtension, MachineInstaller
{
    /** Whether the installer takes machines. */
    public static bool $installs = false;

    /** Whether an event listener throws. */
    public static bool $listenerFails = false;

    /**
     * @var list<int> Machine ids the installer started on.
     */
    public static array $started = [];

    /**
     * @var list<string> "event:machine id" of each event heard.
     */
    public static array $heard = [];

    /**
     * @var array<int, string> The switch port saved per machine id.
     */
    public static array $ports = [];

    public static function reset(): void
    {
        self::$installs = false;
        self::$listenerFails = false;
        self::$started = [];
        self::$heard = [];
        self::$ports = [];
    }

    public function boot(): void
    {
        app(MachineSections::class)->add(fn (Machine $machine, Admin $admin): ?array => [
            'view' => 'ext-rackhooks::machine',
            'data' => ['port' => self::$ports[$machine->id] ?? 'not set', 'canEdit' => $admin->hasPermission('hardware.manage')],
        ]);
        app(DedicatedPanelExtensions::class)->add($this);
        app(MachineInstallers::class)->register($this);

        foreach ([
            'activated' => DedicatedServerActivated::class,
            'suspended' => DedicatedServerSuspended::class,
            'unsuspended' => DedicatedServerUnsuspended::class,
            'released' => DedicatedServerReleased::class,
        ] as $name => $event) {
            Event::listen($event, function (object $heard) use ($name): void {
                if (self::$listenerFails) {
                    throw new RuntimeException('The switch did not answer.');
                }

                self::$heard[] = $name.':'.$heard->machine->id;
            });
        }
    }

    public function hardwareRoutes(): void
    {
        Route::post('port/{machine}', function (Request $request, Machine $machine) {
            self::$ports[$machine->id] = (string) $request->input('port');

            return back()->with('status', 'Port saved.');
        })->whereNumber('machine')->name('port');
    }

    public function sections(Product $product): array
    {
        return [
            new PanelSection('install', 'Install', view: 'ext-rackhooks::panel', actions: [
                'start' => new PanelAction(key: 'start', label: 'Start', rules: ['confirm' => ['accepted']]),
            ]),
            // A key of the dedicated module's own tabs is never given to an add-on.
            new PanelSection('power', 'Not mine', view: 'ext-rackhooks::panel'),
        ];
    }

    public function availableSections(Service $service, Machine $machine): array
    {
        return ['install', 'power'];
    }

    public function readSection(Service $service, Machine $machine, string $section, array $input, ClientContext $context): PanelResult
    {
        return PanelResult::ok('', ['note' => 'Ready to install on service '.$service->id]);
    }

    public function runAction(Service $service, Machine $machine, string $section, string $action, array $input, ClientContext $context): PanelResult
    {
        return PanelResult::ok('Install started.');
    }

    public function key(): string
    {
        return 'rackhooks';
    }

    public function label(): string
    {
        return 'Rack hooks installer';
    }

    public function takes(Machine $machine, Service $service): bool
    {
        return self::$installs;
    }

    public function start(Machine $machine, Service $service): void
    {
        self::$started[] = $machine->id;
    }
}
