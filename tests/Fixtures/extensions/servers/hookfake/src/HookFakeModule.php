<?php

namespace Tests\Fixtures\HookFake;

use App\Contracts\HasAdminPages;
use App\Contracts\HasOrderFields;
use App\Contracts\HasStaffPanel;
use App\Contracts\RunsOnSchedule;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\ScheduledTask;
use App\Extensions\Servers\Staff\StaffAction;
use App\Extensions\Servers\Staff\StaffContext;
use App\Extensions\Servers\Staff\StaffPanel;
use App\Models\Admin;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * A server module for tests only that uses every hook server modules get in Nuvabill 0.7.0: admin
 * pages and dashboard items (HasAdminPages), a staff card (HasStaffPanel), scheduled work
 * (RunsOnSchedule), order form fields (HasOrderFields), and long and secret product settings. It makes no network calls and records what core asks it.
 *
 * Point the extension manager at the fixtures, then HookFakeModule::reset(). Change it with
 * HookFakeModule::$behaviour: "permission" (the admin permission key), "throw" (list of method
 * names that throw an exception whose message holds SECRET), "tasks" (list of ScheduledTask
 * instead of the defaults).
 */
class HookFakeModule extends Module implements HasAdminPages, HasOrderFields, HasStaffPanel, RunsOnSchedule
{
    /**
     * A value that must never show up in logs, pages or activity entries.
     */
    public const SECRET = 'hookfake-api-key-5e2d71';

    /**
     * @var array<string, mixed>
     */
    public static array $behaviour = [];

    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$behaviour = [];
        self::$calls = [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function callsTo(string $method): array
    {
        return array_values(array_map(fn (array $call): array => $call[1], array_filter(self::$calls, fn (array $call): bool => $call[0] === $method)));
    }

    public function productFields(): array
    {
        return [
            'plan' => ['label' => 'Plan', 'type' => 'text'],
            'user_data' => ['label' => 'User data', 'type' => 'textarea', 'max' => 16384],
            'notes' => ['label' => 'Notes', 'type' => 'textarea'],
            'api_token' => ['label' => 'Provisioning token', 'type' => 'text', 'secret' => true],
            'private_key' => ['label' => 'Private key', 'type' => 'textarea', 'max' => 16384, 'secret' => true],
        ];
    }

    public function defaultPort(): int
    {
        return 443;
    }

    public function testConnection(Server $server): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function create(Service $service): ModuleResult
    {
        self::$calls[] = ['create', [
            'service' => $service->id,
            'hostname' => $this->orderField($service, 'hostname'),
            'ssh_keys' => $this->orderField($service, 'ssh_keys'),
            'root_token' => $this->orderField($service, 'root_token'),
            'user_data' => $this->productSetting($service, 'user_data'),
            'api_token' => $this->productSetting($service, 'api_token'),
        ]];

        return ModuleResult::ok('Created.');
    }

    public function suspend(Service $service, string $reason): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function unsuspend(Service $service): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function terminate(Service $service): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function hasLoginLink(): bool
    {
        return false;
    }

    public function adminPermission(): string
    {
        $this->throwIfAsked(__FUNCTION__);

        return (string) (self::$behaviour['permission'] ?? 'hookfake.manage');
    }

    public function adminPermissionLabel(): string
    {
        return 'Manage Hook fake nodes';
    }

    public function adminRoutes(): void
    {
        Route::get('/', fn () => view('ext-hookfake::index', ['note' => 'Node status for staff']))->name('index');
        Route::post('maintenance', function () {
            self::$calls[] = ['maintenance', ['admin' => auth('admin')->id()]];

            return back()->with('status', 'Maintenance on.');
        })->name('maintenance');
    }

    public function attentionItems(Admin $admin): array
    {
        $this->throwIfAsked(__FUNCTION__);

        return [
            ['key' => 'ext.hookfake.nodes', 'tone' => 'warn', 'text' => 'Node pve2 is in maintenance', 'url' => '/admin/server-modules/hookfake', 'action' => 'Review'],
            ['key' => 'bad key', 'tone' => 'warn', 'text' => 'Left out'],
            ['key' => 'ext.hookfake.away', 'tone' => 'crit', 'text' => 'Leaves the site', 'url' => 'https://evil.example.test/'],
        ];
    }

    public function scheduledTasks(): array
    {
        $this->throwIfAsked(__FUNCTION__);

        return self::$behaviour['tasks'] ?? [
            new ScheduledTask('traffic', function (): void {
                self::$calls[] = ['traffic', []];
            }, 'every-five-minutes', 10),
            new ScheduledTask('cleanup', function (): void {
                throw new RuntimeException('cURL error: '.self::SECRET);
            }, 'daily'),
            // Too often: left out.
            new ScheduledTask('every-second', fn () => null, 'every-second'),
        ];
    }

    public function orderFields(Product $product): array
    {
        $this->throwIfAsked(__FUNCTION__);

        return [
            'hostname' => ['label' => 'Hostname', 'type' => 'text', 'required' => true, 'rules' => ['regex:/^[a-z0-9.-]+$/']],
            'ssh_keys' => ['label' => 'SSH keys', 'type' => 'ssh-key'],
            'region' => ['label' => 'Region', 'type' => 'select', 'options' => ['fra1' => 'Frankfurt', 'ams3' => 'Amsterdam']],
            'root_token' => ['label' => 'Setup token', 'type' => 'text', 'secret' => true],
            'Bad Key' => ['label' => 'Left out', 'type' => 'text'],
            'colour' => ['label' => 'Left out too', 'type' => 'colour'],
        ];
    }

    public function staffPanel(Service $service): StaffPanel
    {
        self::$calls[] = ['staffPanel', ['service' => $service->id]];
        $this->throwIfAsked(__FUNCTION__);

        return new StaffPanel(
            title: 'Hook fake guest',
            rows: [
                ['label' => 'Node', 'value' => 'pve1'],
                ['label' => 'Mark', 'value' => '<b>nuvabill:s'.$service->id.'</b>'],
                ['label' => 'Bad row'],
            ],
            actions: [
                new StaffAction('migrate', 'Move to another node', [
                    'node' => ['label' => 'Node', 'type' => 'select', 'options' => ['pve1' => 'pve1', 'pve2' => 'pve2'], 'required' => true],
                    'live' => ['label' => 'Live', 'type' => 'checkbox'],
                    'note' => ['label' => 'Note', 'type' => 'text', 'max' => 20],
                ], confirm: 'Move this guest?'),
                new StaffAction('set-password', 'Set root password', [
                    'new_secret' => ['label' => 'New root password', 'type' => 'password', 'required' => true, 'max' => 64],
                    'reason' => ['label' => 'Reason', 'type' => 'text', 'required' => true],
                ]),
                new StaffAction('Bad Key', 'Left out'),
            ],
            notice: 'Node pve1 is healthy.',
        );
    }

    public function runStaffAction(Service $service, string $action, array $input, StaffContext $staff): ModuleResult
    {
        self::$calls[] = ['runStaffAction', ['service' => $service->id, 'action' => $action, 'input' => $input, 'staff' => $staff->adminName]];
        $this->throwIfAsked(__FUNCTION__);

        return ModuleResult::ok('Moved to '.($input['node'] ?? '?').'.');
    }

    private function throwIfAsked(string $method): void
    {
        if (in_array($method, (array) (self::$behaviour['throw'] ?? []), true)) {
            throw new RuntimeException('cURL error 7: https://api.example.test/?key='.self::SECRET);
        }
    }
}
