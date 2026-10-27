<?php

namespace Tests\Fixtures\PanelFake;

use App\Contracts\ChecksClientAccess;
use App\Contracts\ChoosesWelcomeEmail;
use App\Contracts\FinishesSetupLater;
use App\Contracts\HasConsole;
use App\Contracts\HasPanelSections;
use App\Contracts\HasPanelState;
use App\Contracts\HasResourceOptions;
use App\Contracts\LimitsPlanChanges;
use App\Contracts\LinksExistingAccount;
use App\Contracts\QueuesPackageChanges;
use App\Contracts\SignsInWithContext;
use App\Contracts\UsesMachineInventory;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\ConsoleSession;
use App\Extensions\Servers\Panel\PanelAction;
use App\Extensions\Servers\Panel\PanelException;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Panel\PanelSection;
use App\Extensions\Servers\Panel\StandardSections;
use App\Extensions\Servers\Resources\ResourceCheck;
use App\Extensions\Servers\Resources\ResourceExtras;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Extensions\Servers\Resources\ResourceLimit;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * A server module for tests only. It implements every optional server contract of this release
 * plus HasPanelState, makes no network calls and records every call with its inputs.
 *
 * Point the extension manager at the fixtures and start each test clean. This class can only be
 * loaded after the manager has read the manifests:
 *
 *     config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
 *     app()->forgetInstance(ExtensionManager::class);
 *     app(ExtensionManager::class)->manifests();
 *     PanelFakeModule::reset();
 *
 * Change what it does with PanelFakeModule::$behaviour. The switches "fail", "throw", "pending"
 * and "busy" take true (every method they apply to) or a list of method names, section keys or
 * "section.action" keys, such as ['fail' => ['applyResources', 'network.rdns-set']]:
 *
 * - fail: return a failed result. For consoleSession, throw a PanelException (403).
 * - throw: throw a RuntimeException whose message holds the fake API key (LEAKY_ERROR), to prove
 *   core never logs or shows a raw exception.
 * - pending: applyResources answers 'pending' => true; setupPending() answers true.
 * - busy: applyResources fails with ['busy' => true]; panel reads and actions throw a 409 PanelException.
 * - reveal: true, or a list of "section.action" keys, that return a secret even when the action is
 *   not declared with reveals (actions declared with reveals always return SECRET).
 * - manual, restart: bool, added to the applyResources data.
 * - limits: array<string, ResourceLimit> for resourceLimits().
 * - sections: list<PanelSection> instead of the defaults. available: list<string>|null.
 * - data: array<string, array<string, mixed>> read data per section, instead of the defaults.
 * - kinds: array<string, ResourceKind>. base: array<string, int|null>. live: array<string, int|null> for checkResources().
 * - state: the power state (default "running"). welcome: array{key, context}. plan_changes: bool or list of allowed product ids.
 * - sign_in_url, login_url: ?string. console_url: string. console_protocol: "vnc" (default) or
 *   "terminal". access: the checkClientAccess() rows.
 */
class PanelFakeModule extends Module implements ChecksClientAccess, ChoosesWelcomeEmail, FinishesSetupLater, HasConsole, HasPanelSections, HasPanelState, HasResourceOptions, LimitsPlanChanges, LinksExistingAccount, QueuesPackageChanges, SignsInWithContext, UsesMachineInventory
{
    /**
     * An API key that must never show up in logs, activity entries, sessions or answers.
     */
    public const API_KEY = 'panelfake-admin-key-7f3c91';

    /**
     * The message of every exception the "throw" switch raises, like a raw HTTP client error.
     */
    public const LEAKY_ERROR = 'cURL error 7: https://panel.example.test:4085/index.php?act=vs&adminapikey='.self::API_KEY;

    /**
     * The secret value that reveal actions return.
     */
    public const SECRET = 'Fake-Root-Pass-4821';

    /**
     * The console password of the fake console session.
     */
    public const CONSOLE_PASSWORD = 'Fake-Vnc-Pass-9314';

    public const CONSOLE_URL = 'wss://console.example.test:4083/?t=abc';

    /**
     * @var array<string, mixed>
     */
    public static array $behaviour = [];

    /**
     * Every call, in order.
     *
     * @var list<array{method: string, args: array<string, mixed>}>
     */
    public static array $calls = [];

    /**
     * The extras applied per service id, as applyResources() left them.
     *
     * @var array<int, array<string, int>>
     */
    public static array $applied = [];

    /**
     * Forget all behaviour and recorded calls. Call it in each test's setUp().
     */
    public static function reset(): void
    {
        self::$behaviour = [];
        self::$calls = [];
        self::$applied = [];
    }

    /**
     * The recorded calls, or only those of one method.
     *
     * @return list<array{method: string, args: array<string, mixed>}>
     */
    public static function calls(?string $method = null): array
    {
        return array_values(array_filter(self::$calls, fn (array $call): bool => $method === null || $call['method'] === $method));
    }

    /**
     * A section with a key that is not standard, so it uses the module's own view.
     */
    public static function customSection(): PanelSection
    {
        return new PanelSection(key: 'notes', label: 'Notes', icon: 'list', view: 'ext-panelfake::notes', cacheSeconds: 30);
    }

    public function defaultPort(): int
    {
        return 4085;
    }

    public function testConnection(Server $server): ModuleResult
    {
        $this->record(__FUNCTION__, ['server' => $server->id]);

        return $this->lifecycle(__FUNCTION__, 'Connected.');
    }

    public function create(Service $service): ModuleResult
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'extras' => $this->extrasOf($service)]);

        return $this->lifecycle(__FUNCTION__, 'Created.', [
            'username' => 'fake'.$service->id,
            'module_data' => ['fake_id' => 'vm-'.$service->id],
        ]);
    }

    public function suspend(Service $service, string $reason): ModuleResult
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'reason' => $reason]);

        return $this->lifecycle(__FUNCTION__, 'Suspended.');
    }

    public function unsuspend(Service $service): ModuleResult
    {
        $this->record(__FUNCTION__, ['service' => $service->id]);

        return $this->lifecycle(__FUNCTION__, 'Unsuspended.');
    }

    public function terminate(Service $service): ModuleResult
    {
        $this->record(__FUNCTION__, ['service' => $service->id]);

        return $this->lifecycle(__FUNCTION__, 'Terminated.');
    }

    public function changePackage(Service $service): ModuleResult
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'product' => $service->product_id, 'extras' => $this->extrasOf($service)]);

        return $this->lifecycle(__FUNCTION__, 'Plan changed.');
    }

    public function loginUrl(Service $service): ?string
    {
        $this->record(__FUNCTION__, ['service' => $service->id]);

        return array_key_exists('login_url', self::$behaviour) ? self::$behaviour['login_url'] : 'https://panel.example.test/login';
    }

    public function signInUrl(Service $service, ClientContext $context): ?string
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'context' => $context]);
        $this->throwIfAsked(__FUNCTION__);

        return array_key_exists('sign_in_url', self::$behaviour) ? self::$behaviour['sign_in_url'] : 'https://panel.example.test/sso/one-time';
    }

    public function clientPanelState(Service $service): string
    {
        $this->record(__FUNCTION__, ['service' => $service->id]);
        $this->throwIfAsked(__FUNCTION__);

        return (string) (self::$behaviour['state'] ?? 'running');
    }

    public function panelSections(Product $product): array
    {
        $this->record(__FUNCTION__, ['product' => $product->id]);
        $this->throwIfAsked(__FUNCTION__);

        return self::$behaviour['sections'] ?? $this->defaultSections();
    }

    public function availablePanelSections(Service $service, ClientContext $context): ?array
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'context' => $context]);
        $this->throwIfAsked(__FUNCTION__);

        return self::$behaviour['available'] ?? null;
    }

    public function readPanelSection(Service $service, string $section, array $input, ClientContext $context): PanelResult
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'section' => $section, 'input' => $input, 'context' => $context]);
        $this->throwIfAsked(__FUNCTION__, $section);
        $this->busyIfAsked(__FUNCTION__, $section);

        if ($this->wants('fail', __FUNCTION__, $section)) {
            return PanelResult::fail('The fake panel could not read this.', 'Fake read refused.');
        }

        return PanelResult::ok('', self::$behaviour['data'][$section] ?? $this->defaultData($section, $input));
    }

    public function runPanelAction(Service $service, string $section, string $action, array $input, ClientContext $context): PanelResult
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'section' => $section, 'action' => $action, 'input' => $input, 'context' => $context]);
        $this->throwIfAsked(__FUNCTION__, $section, "{$section}.{$action}");
        $this->busyIfAsked(__FUNCTION__, $section, "{$section}.{$action}");

        if ($this->wants('fail', __FUNCTION__, $section, "{$section}.{$action}")) {
            return PanelResult::fail('The fake panel refused this.', 'Fake action refused.');
        }

        if ($this->declaredReveal($section, $action) || $this->wants('reveal', "{$section}.{$action}")) {
            return PanelResult::secret('Save this now. It is shown only once.', [['label' => 'Root password', 'value' => self::SECRET]], [], "{$section}.{$action}");
        }

        if ($section === 'power') {
            return PanelResult::ok('Done.', [], $action, (string) (self::$behaviour['state'] ?? 'running'));
        }

        return PanelResult::ok('Done.', ['section' => $section, 'action' => $action], logDetail: "{$section}.{$action}");
    }

    public function consoleSession(Service $service, ClientContext $context): ConsoleSession
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'context' => $context]);
        $this->throwIfAsked(__FUNCTION__);

        if ($this->wants('fail', __FUNCTION__)) {
            throw new PanelException('The console is off for this server.', 'Fake console refused.', 403);
        }

        return new ConsoleSession(
            url: (string) (self::$behaviour['console_url'] ?? self::CONSOLE_URL),
            credentials: ['password' => self::CONSOLE_PASSWORD],
            expiresAt: new DateTimeImmutable('+5 minutes'),
            protocol: (string) (self::$behaviour['console_protocol'] ?? ConsoleSession::VNC),
        );
    }

    public function checkClientAccess(Service $service): array
    {
        $this->record(__FUNCTION__, ['service' => $service->id]);
        $this->throwIfAsked(__FUNCTION__);

        return self::$behaviour['access'] ?? [
            ['label' => 'Panel answers', 'status' => 'ok', 'detail' => 'The fake panel answered.'],
            ['label' => 'Sign-in link', 'status' => 'skip', 'detail' => 'The fake panel does not check this.'],
        ];
    }

    public function linkFields(): array
    {
        return ['vps_id' => ['label' => 'VPS ID', 'type' => 'text', 'required' => true, 'help' => 'The ID in the fake panel.']];
    }

    public function linkExistingAccount(Service $service, array $input): ModuleResult
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'input' => $input]);

        return $this->lifecycle(__FUNCTION__, 'Linked.', ['module_data' => ['fake_id' => (string) ($input['vps_id'] ?? '')]]);
    }

    public function resourceKinds(Product $product): array
    {
        $this->record(__FUNCTION__, ['product' => $product->id]);

        if ($this->wants('throw', __FUNCTION__)) {
            throw new InvalidArgumentException('The fake product settings are wrong.');
        }

        return self::$behaviour['kinds'] ?? [
            'cpu' => new ResourceKind('cpu', 'CPU cores', 'core'),
            'ram' => new ResourceKind('ram', 'RAM', 'MB', minStep: 128, restarts: true, note: 'Takes effect after a restart.'),
            'disk' => new ResourceKind('disk', 'Disk space', 'GB', canShrink: false),
            'ipv4' => new ResourceKind('ipv4', 'IPv4 addresses', 'IP', maxExtra: 4),
            'backups' => new ResourceKind('backups', 'Backups', '', maxExtra: 1),
            'panelfake_speed' => new ResourceKind('panelfake_speed', 'Port speed', 'KB/s', minStep: 1024),
            'panelfake_size' => new ResourceKind('panelfake_size', 'Server size', '', maxExtra: 2, ladder: ['Plan size', 'Bigger', 'Biggest']),
        ];
    }

    public function baseResources(Product $product, ?Server $server): array
    {
        $this->record(__FUNCTION__, ['product' => $product->id, 'server' => $server?->id]);
        $this->throwIfAsked(__FUNCTION__);

        return $this->base();
    }

    public function resourceLimits(Service $service): array
    {
        $this->record(__FUNCTION__, ['service' => $service->id]);
        $this->throwIfAsked(__FUNCTION__);

        return array_filter((array) (self::$behaviour['limits'] ?? []), fn (mixed $limit): bool => $limit instanceof ResourceLimit);
    }

    public function applyResources(Service $service, ResourceExtras $extras): ModuleResult
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'extras' => $extras->units]);
        $this->throwIfAsked(__FUNCTION__);

        if ($this->wants('busy', __FUNCTION__)) {
            return ModuleResult::fail('The fake panel is busy.', ['busy' => true]);
        }

        if ($this->wants('fail', __FUNCTION__)) {
            return ModuleResult::fail('The fake panel could not set the resources.');
        }

        self::$applied[$service->id] = array_merge(self::$applied[$service->id] ?? [], array_map('intval', $extras->units));

        return ModuleResult::ok('Resources set.', array_filter([
            'manual' => (bool) (self::$behaviour['manual'] ?? false),
            'restart' => (bool) (self::$behaviour['restart'] ?? false),
            'pending' => $this->wants('pending', __FUNCTION__),
        ]));
    }

    public function checkResources(Service $service, ResourceExtras $extras): ResourceCheck
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'extras' => $extras->units]);
        $this->throwIfAsked(__FUNCTION__);

        $base = $this->base();
        $expected = [];
        $live = [];
        $problems = [];

        foreach ($extras->kinds() as $kind) {
            $expected[$kind] = (int) ($base[$kind] ?? 0) + $extras->get($kind);
            $live[$kind] = array_key_exists($kind, (array) (self::$behaviour['live'] ?? []))
                ? self::$behaviour['live'][$kind]
                : (int) ($base[$kind] ?? 0) + (self::$applied[$service->id][$kind] ?? 0);

            if ($live[$kind] !== $expected[$kind]) {
                $problems[] = "{$kind}: ".($live[$kind] ?? 'unknown').", expected {$expected[$kind]}";
            }
        }

        return new ResourceCheck($expected, $live, $problems);
    }

    public function setupPending(Service $service): bool
    {
        $this->record(__FUNCTION__, ['service' => $service->id]);
        $this->throwIfAsked(__FUNCTION__);

        return $this->wants('pending', __FUNCTION__);
    }

    public function welcomeEmail(Service $service): array
    {
        $this->record(__FUNCTION__, ['service' => $service->id]);
        $this->throwIfAsked(__FUNCTION__);

        return self::$behaviour['welcome'] ?? [
            'key' => 'service.welcome',
            'context' => ['panel' => ['note' => 'Your fake server is ready.'], 'root_password' => self::SECRET],
        ];
    }

    public function allowsPlanChange(Service $service, Product $target): bool
    {
        $this->record(__FUNCTION__, ['service' => $service->id, 'target' => $target->id]);
        $this->throwIfAsked(__FUNCTION__);

        $allowed = self::$behaviour['plan_changes'] ?? true;

        return is_array($allowed) ? in_array($target->id, $allowed, true) : (bool) $allowed;
    }

    /**
     * @return list<PanelSection>
     */
    private function defaultSections(): array
    {
        return [
            PanelSection::standard('overview', cacheSeconds: 15),
            PanelSection::standard('power', actions: [
                'start' => StandardSections::powerAction('start'),
                'stop' => StandardSections::powerAction('stop'),
                'restart' => StandardSections::powerAction('restart'),
                'poweroff' => StandardSections::powerAction('poweroff'),
            ]),
            PanelSection::standard('console', actions: [
                'details' => new PanelAction('details', 'Show console details', changesServer: false, reveals: true),
            ]),
            PanelSection::standard('graphs', readRules: ['period' => ['nullable', 'string', 'in:day,week,month']], cacheSeconds: 30),
            PanelSection::standard('network', actions: [
                'rdns-set' => new PanelAction('rdns-set', 'Save reverse DNS', rules: ['ip' => ['required', 'ip'], 'ptr' => ['nullable', 'string', 'max:253']], notify: 'Reverse DNS changed'),
            ]),
            PanelSection::standard('reinstall', actions: [
                'run' => new PanelAction('run', 'Reinstall', rules: ['template' => ['required', 'string', 'in:ubuntu-24.04,debian-12'], 'confirm' => ['accepted']], confirm: 'Erase the server and install it again?', destructive: true, reveals: true, notify: 'The server was reinstalled'),
            ]),
            PanelSection::standard('settings', actions: [
                'hostname' => new PanelAction('hostname', 'Save hostname', rules: ['hostname' => ['required', 'string', 'max:253']]),
                'root-password' => new PanelAction('root-password', 'Change password', rules: ['password' => ['required', 'string', 'min:10', 'confirmed']], secretFields: ['password', 'password_confirmation'], notify: 'The root password was changed'),
            ]),
            PanelSection::standard('snapshots', enabledByDefault: false, actions: [
                'create' => new PanelAction('create', 'Take a snapshot', rules: ['description' => ['nullable', 'string', 'max:100']]),
                'delete' => new PanelAction('delete', 'Delete', rules: ['id' => ['required', 'string', 'max:64'], 'confirm' => ['accepted']], destructive: true),
            ]),
        ];
    }

    /**
     * Standard data shapes for the default sections.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function defaultData(string $section, array $input): array
    {
        $state = (string) (self::$behaviour['state'] ?? 'running');

        return match ($section) {
            'overview' => [
                'state' => $state,
                'cpu' => 12.5,
                'ram' => ['used' => 1024, 'total' => 2048, 'unit' => 'MB'],
                'disk' => ['used' => 10, 'total' => 40, 'unit' => 'GB'],
                'bandwidth' => null,
                'network' => ['in' => 1200, 'out' => 3400],
                'facts' => [['label' => 'Location', 'value' => 'Test lab']],
                'ips' => [['ip' => '192.0.2.10', 'version' => 4, 'primary' => true], ['ip' => '2001:db8::10', 'version' => 6, 'primary' => false]],
                'resources' => [],
                'power_note' => null,
                'live' => true,
            ],
            'console' => ['available' => true, 'details' => true],
            'graphs' => [
                'period' => ['kind' => 'range', 'value' => $input['period'] ?? 'day', 'options' => [['value' => 'day', 'label' => '24 hours'], ['value' => 'week', 'label' => '7 days'], ['value' => 'month', 'label' => '30 days']]],
                'series' => [['key' => 'cpu', 'label' => 'CPU', 'unit' => '%', 'points' => [[1791590400, 10.5], [1791590700, null], [1791591000, 12.0]]]],
            ],
            'network' => [
                'ips' => [['ip' => '192.0.2.10', 'version' => 4, 'primary' => true, 'rdns' => 'vps.example.test']],
                'subnets' => [],
                'rdns' => true,
            ],
            'reinstall' => [
                'mode' => 'run',
                'templates' => [['id' => 'ubuntu-24.04', 'name' => 'Ubuntu 24.04', 'group' => 'Ubuntu'], ['id' => 'debian-12', 'name' => 'Debian 12', 'group' => 'Debian']],
                'credential_mode' => 'generate',
                'recipe' => false,
                'left_this_month' => null,
                'note' => false,
                'open_request' => null,
                'warning' => 'Everything on the server is erased.',
            ],
            'settings' => [
                'hostname' => 'vps.example.test',
                'credential_mode' => 'set',
                'can' => ['hostname' => true, 'root-password' => true, 'vnc-password' => false],
            ],
            'snapshots' => ['items' => [], 'used' => 0, 'limit' => 2, 'credential_mode' => 'none'],
            default => [],
        };
    }

    /**
     * @return array<string, int|null>
     */
    private function base(): array
    {
        return self::$behaviour['base'] ?? [
            'cpu' => 2,
            'ram' => 2048,
            'disk' => 40,
            'ipv4' => 1,
            'backups' => 0,
            'panelfake_speed' => null,
            'panelfake_size' => 0,
        ];
    }

    /**
     * The result of a create, suspend or other lifecycle call, after the "throw" and "fail" switches.
     *
     * @param  array<string, mixed>  $data
     */
    private function lifecycle(string $method, string $message, array $data = []): ModuleResult
    {
        $this->throwIfAsked($method);

        if ($this->wants('fail', $method)) {
            return ModuleResult::fail("The fake panel refused {$method}.");
        }

        return ModuleResult::ok($message, $data);
    }

    private function declaredReveal(string $section, string $action): bool
    {
        foreach (self::$behaviour['sections'] ?? $this->defaultSections() as $declared) {
            if ($declared->key === $section) {
                return $declared->action($action)?->reveals ?? false;
            }
        }

        return false;
    }

    /**
     * The service's extras in units, once the Service model can tell them.
     *
     * @return array<string, int>
     */
    private function extrasOf(Service $service): array
    {
        return method_exists($service, 'resourceExtras') ? $service->resourceExtras()->units : [];
    }

    /**
     * Whether a switch is on for any of these subjects (method names, section keys or "section.action").
     */
    private function wants(string $switch, string ...$subjects): bool
    {
        $value = self::$behaviour[$switch] ?? false;

        if ($value === true) {
            return true;
        }

        return is_array($value) && array_intersect($subjects, $value) !== [];
    }

    private function throwIfAsked(string ...$subjects): void
    {
        if ($this->wants('throw', ...$subjects)) {
            throw new RuntimeException(self::LEAKY_ERROR);
        }
    }

    private function busyIfAsked(string ...$subjects): void
    {
        if ($this->wants('busy', ...$subjects)) {
            throw new PanelException('Another change is running on this server. Try again in a moment.', 'Fake busy lock held.', 409);
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function record(string $method, array $args): void
    {
        self::$calls[] = ['method' => $method, 'args' => $args];
    }
}
