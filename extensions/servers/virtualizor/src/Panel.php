<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\ConsoleSession;
use App\Extensions\Servers\Panel\PanelAction;
use App\Extensions\Servers\Panel\PanelException;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Panel\PanelSection;
use App\Extensions\Servers\Panel\StandardSections;
use App\Models\Product;
use App\Models\Service;
use App\Support\Activity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * The client server panel of a Virtualizor VPS: the sections it offers, what the live server
 * allows, and every read and action. Core checks the owner, status, product switches, input,
 * limits and the service lock first, so this class only gets input that passed the rules.
 *
 * The sections here run on the Admin API, so they work even when Virtualizor's end-user port
 * cannot be reached: overview, power, console, reinstall and settings (hostname and root password).
 */
final class Panel
{
    /**
     * A hostname: labels of letters, digits and dashes, at least two of them.
     */
    private const HOSTNAME = '/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+\z/';

    /**
     * Seconds the VPS details of the overview (hostname, OS, addresses, plan) are kept, so a live
     * refresh asks Virtualizor only for the figures.
     */
    private const FACTS_SECONDS = 60;

    public function __construct(private AdminApi $admin, private EnduserApi $enduser, private SignIn $signIn) {}

    /**
     * The sections of the product's panel, in display order. Never calls a server.
     *
     * @return list<PanelSection>
     */
    public function sections(Product $product): array
    {
        $password = ['required', 'string', 'min:10', 'max:128', 'regex:/[A-Za-z]/', 'regex:/\d/', 'not_regex:/[\x00-\x1F\x7F]/', 'confirmed'];

        return [
            PanelSection::standard('overview', cacheSeconds: 5, readThrottle: [30, 60]),
            PanelSection::standard('console', actions: [
                'details' => new PanelAction(key: 'details', label: __('Show VNC details'), changesServer: false, reveals: true),
            ]),
            PanelSection::standard('reinstall', actions: [
                'run' => new PanelAction(
                    key: 'run',
                    label: __('Reinstall OS'),
                    rules: [
                        'template' => ['required', 'string', 'regex:/^\d{1,10}\z/'],
                        'password' => $password,
                        'run_recipe' => ['nullable', 'boolean'],
                        'confirm' => ['accepted'],
                    ],
                    destructive: true,
                    notify: 'The operating system of your server was reinstalled. All data on it was erased.',
                    secretFields: ['password', 'password_confirmation'],
                ),
            ], cacheSeconds: 300),
            PanelSection::standard('settings', actions: [
                'hostname' => new PanelAction(key: 'hostname', label: __('Change hostname'), rules: ['hostname' => ['required', 'string', 'max:253', 'regex:'.self::HOSTNAME]]),
                'root-password' => new PanelAction(
                    key: 'root-password',
                    label: __('Change root password'),
                    rules: ['password' => $password],
                    notify: 'The root password of your server was changed.',
                    secretFields: ['password', 'password_confirmation'],
                ),
            ]),
            PanelSection::standard('power', actions: [
                'start' => StandardSections::powerAction('start'),
                'stop' => StandardSections::powerAction('stop'),
                'restart' => StandardSections::powerAction('restart'),
                'poweroff' => StandardSections::powerAction('poweroff'),
            ]),
        ];
    }

    /**
     * The section keys the live server allows for this service now, or null for every declared one.
     *
     * @return list<string>|null
     */
    public function available(Service $service, ClientContext $context): ?array
    {
        return null;
    }

    /**
     * Read one section in its standard data shape.
     *
     * @param  array<string, mixed>  $input  Only the keys of the section's read rules.
     *
     * @throws RuntimeException When Virtualizor does not answer.
     */
    public function read(Service $service, string $section, array $input, ClientContext $context): PanelResult
    {
        return match ($section) {
            'overview' => $this->overview($service),
            'console' => PanelResult::ok('', ['available' => $this->enduser->port($service) !== null, 'details' => true]),
            'reinstall' => $this->reinstallOptions($service),
            'settings' => $this->settings($service),
            default => PanelResult::fail(__('This feature is not available for this service.'), null, 404),
        };
    }

    /**
     * Run one action of a section.
     *
     * @param  array<string, mixed>  $input  Only the keys of the action's rules.
     */
    public function run(Service $service, string $section, string $action, array $input, ClientContext $context): PanelResult
    {
        return match ($section.'.'.$action) {
            'power.start', 'power.stop', 'power.restart', 'power.poweroff' => $this->result($this->power($service, $action)),
            'console.details' => $this->vncDetails($service),
            'reinstall.run' => $this->result($this->reinstall($service, (string) ($input['template'] ?? ''), (string) ($input['password'] ?? ''), filter_var($input['run_recipe'] ?? false, FILTER_VALIDATE_BOOLEAN))),
            'settings.hostname' => $this->result($this->changeHostname($service, (string) ($input['hostname'] ?? ''))),
            'settings.root-password' => $this->result($this->changePassword($service, (string) ($input['password'] ?? ''))),
            default => PanelResult::fail(__('This action is not available.'), null, 404),
        };
    }

    /**
     * The browser console: Virtualizor's noVNC proxy on the master's end-user port, with the VNC
     * password from the Admin API. The master forwards the screen of a VPS on any of its servers.
     *
     * @throws PanelException When the console cannot be opened.
     */
    public function consoleSession(Service $service, ClientContext $context): ConsoleSession
    {
        $server = $service->server;
        $port = $this->enduser->port($service);

        if ($server === null || $port === null) {
            throw new PanelException(__('The console is not available for this server right now.'), 'The product has no valid end-user panel port, so the console cannot connect.', 503);
        }

        $vpsId = AdminApi::vpsId($service);

        try {
            $info = (array) ($this->admin->call($server, ['act' => 'vnc'], ['novnc' => $vpsId])['info'] ?? []);
        } catch (RuntimeException $exception) {
            throw new PanelException(__('The server did not answer. Please try again in a minute.'), $exception->getMessage(), 503);
        }

        $password = $info['password'] ?? null;

        if (! is_string($password) || $password === '') {
            throw new PanelException(__('The console is off for this server. Contact support.'), "Virtualizor gave no VNC password for VPS #{$vpsId}.");
        }

        $host = rtrim(strtolower(trim((string) $server->hostname)), '.');

        return new ConsoleSession(url: "wss://{$host}:{$port}/novnc/?virttoken={$vpsId}", credentials: ['password' => $password]);
    }

    /**
     * The old client panel actions (start, stop, restart, poweroff, hostname, password and
     * reinstall) for direct calls. They never give a secret back.
     *
     * @param  array<string, mixed>  $input
     */
    public function clientAction(Service $service, string $action, array $input): ModuleResult
    {
        return match ($action) {
            'start', 'stop', 'restart', 'poweroff' => $this->power($service, $action),
            'hostname' => $this->changeHostname($service, (string) ($input['hostname'] ?? '')),
            'password' => $this->changePassword($service, (string) ($input['password'] ?? '')),
            'reinstall' => $this->reinstall($service, (string) ($input['template'] ?? $input['os_id'] ?? ''), (string) ($input['password'] ?? ''), filter_var($input['run_recipe'] ?? false, FILTER_VALIDATE_BOOLEAN)),
            default => ModuleResult::fail(__('This action is not available.')),
        };
    }

    /**
     * The overview: live figures from the status call, and the VPS details (kept a minute). Either
     * may fail on its own; when both fail, the read fails.
     */
    private function overview(Service $service): PanelResult
    {
        $vpsId = AdminApi::vpsId($service);
        $failed = null;

        try {
            $facts = $this->facts($service, $vpsId);
        } catch (RuntimeException $exception) {
            $facts = null;
            $failed = $exception;
        }

        try {
            $status = $this->admin->vpsStatus($service->server, $vpsId);
        } catch (RuntimeException $exception) {
            if ($facts === null) {
                throw $failed ?? $exception;
            }

            $status = [];
        }

        $facts ??= ['hostname' => null, 'os' => null, 'suspended' => false, 'ips' => AdminApi::addresses(['ips' => (array) ($service->module_data['ips'] ?? [])]), 'cores' => null, 'ram' => null, 'space' => null, 'bandwidth' => null];
        $state = AdminApi::state($status['status'] ?? null);

        if ($state === 'unknown' && $facts['suspended']) {
            $state = 'suspended';
        }

        $live = $status !== [];
        $hostname = $facts['hostname'] ?? ((string) $service->domain !== '' ? (string) $service->domain : null);

        return PanelResult::ok('', [
            'state' => $state,
            'cpu' => is_numeric($status['used_cpu'] ?? null) ? round((float) $status['used_cpu'], 2) : null,
            'ram' => $live ? $this->meter($status['used_ram'] ?? null, $status['ram'] ?? $facts['ram'], 'MB') : null,
            'disk' => $live ? $this->meter($status['used_disk'] ?? null, $status['disk'] ?? $facts['space'], 'GB') : null,
            'bandwidth' => $live ? $this->meter($status['used_bandwidth'] ?? null, $status['bandwidth'] ?? $facts['bandwidth'], 'GB') : null,
            'network' => isset($status['net_in']) || isset($status['net_out'])
                ? ['in' => max(0, (float) ($status['net_in'] ?? 0)), 'out' => max(0, (float) ($status['net_out'] ?? 0))]
                : null,
            'facts' => array_values(array_filter([
                $hostname !== null ? ['label' => __('Hostname'), 'value' => $hostname] : null,
                $facts['os'] !== null ? ['label' => __('Operating system'), 'value' => $facts['os']] : null,
            ])),
            'ips' => array_map(fn (string $ip, int $index): array => ['ip' => $ip, 'version' => str_contains($ip, ':') ? 6 : 4, 'primary' => $index === 0], $facts['ips'], array_keys($facts['ips'])),
            'resources' => array_values(array_filter([
                $facts['cores'] !== null ? ['label' => __('CPU cores'), 'value' => (string) $facts['cores']] : null,
                $facts['ram'] !== null ? ['label' => __('RAM'), 'value' => $facts['ram'].' MB'] : null,
                $facts['space'] !== null ? ['label' => __('Disk'), 'value' => $facts['space'].' GB'] : null,
            ])),
            'power_note' => null,
            'live' => true,
        ]);
    }

    /**
     * Used and total of one figure; a total of 0 or none is unlimited (null).
     *
     * @return array{used: float, total: float|null, unit: string}|null
     */
    private function meter(mixed $used, mixed $total, string $unit): ?array
    {
        if (! is_numeric($used)) {
            return null;
        }

        return ['used' => max(0, (float) $used), 'total' => is_numeric($total) && (float) $total > 0 ? (float) $total : null, 'unit' => $unit];
    }

    /**
     * The VPS details the panel shows, kept a minute. Nothing secret is kept.
     *
     * @return array{hostname: string|null, os: string|null, suspended: bool, ips: list<string>, cores: int|null, ram: int|null, space: int|null, bandwidth: int|null}
     *
     * @throws RuntimeException When Virtualizor does not answer.
     */
    private function facts(Service $service, string $vpsId): array
    {
        return Cache::remember($this->factsKey($service, $vpsId), self::FACTS_SECONDS, function () use ($service, $vpsId): array {
            $info = $this->admin->vpsInfo($service->server, $vpsId);
            $number = fn (string $key): ?int => is_numeric($info[$key] ?? null) && (int) $info[$key] >= 0 ? (int) $info[$key] : null;

            return [
                'hostname' => is_string($info['hostname'] ?? null) && $info['hostname'] !== '' ? $info['hostname'] : null,
                'os' => is_string($info['os_name'] ?? null) && $info['os_name'] !== '' ? $info['os_name'] : null,
                'suspended' => (int) ($info['suspended'] ?? 0) === 1,
                'ips' => $info !== [] ? AdminApi::addresses($info) : AdminApi::addresses(['ips' => (array) ($service->module_data['ips'] ?? [])]),
                'cores' => $number('cores'),
                'ram' => $number('ram'),
                'space' => $number('space'),
                'bandwidth' => $number('bandwidth'),
            ];
        });
    }

    private function factsKey(Service $service, string $vpsId): string
    {
        return "nuvabill:vz:facts:{$service->id}:{$vpsId}";
    }

    /**
     * The reinstall tab: the OS templates the client may choose, the reinstalls left this month and
     * whether the plan's setup script can run again.
     */
    private function reinstallOptions(Service $service): PanelResult
    {
        $templates = $this->allowedTemplates($service);
        $left = $this->reinstallsLeft($service);

        return PanelResult::ok('', [
            'mode' => 'run',
            'templates' => array_values(array_map(
                fn (string $id, string $name): array => ['id' => $id, 'name' => $name, 'group' => (string) strtok($name, ' ')],
                array_map('strval', array_keys($templates)),
                $templates,
            )),
            'credential_mode' => 'set',
            'recipe' => $this->recipe($service) !== null,
            'left_this_month' => $left,
            'note' => false,
            'open_request' => null,
            'warning' => __('A reinstall erases all files, websites and databases on the server.'),
            'can' => ['run' => $templates !== [] && ($left === null || $left > 0)],
        ]);
    }

    /**
     * The settings tab: the hostname now, and which changes the client may make.
     */
    private function settings(Service $service): PanelResult
    {
        $facts = $this->facts($service, AdminApi::vpsId($service));

        return PanelResult::ok('', [
            'hostname' => $facts['hostname'] ?? (string) $service->domain,
            'credential_mode' => 'set',
            'can' => ['hostname' => true, 'root-password' => true, 'vnc-password' => false],
        ]);
    }

    /**
     * Start, stop (a shutdown signal), restart or power off (a hard stop). The new state comes from
     * Virtualizor's answer, so the panel can show it at once or wait for it.
     */
    private function power(Service $service, string $action): ModuleResult
    {
        return $this->attempt(function () use ($service, $action): ModuleResult {
            $vpsId = AdminApi::vpsId($service);
            $response = $this->admin->call($service->server, ['act' => 'vs', 'action' => $action, 'vpsid' => $vpsId]);

            if (empty($response['done']) && empty($response['vsop'])) {
                return ModuleResult::fail(__('Virtualizor did not confirm the action.'));
            }

            return ModuleResult::ok(match ($action) {
                'start' => __('The VPS is starting.'),
                'stop' => __('A shutdown signal was sent. The VPS stops once its system has shut down. If it does not stop, use Power off.'),
                'restart' => __('The VPS is restarting.'),
                default => __('The VPS is powered off.'),
            }, ['pending' => $action, 'state' => AdminApi::state($response['vsop']['status'][$vpsId] ?? null)]);
        });
    }

    /**
     * The details for a VNC app, shown once.
     */
    private function vncDetails(Service $service): PanelResult
    {
        try {
            $info = (array) ($this->admin->call($service->server, ['act' => 'vnc'], ['novnc' => AdminApi::vpsId($service)])['info'] ?? []);
        } catch (RuntimeException $exception) {
            return PanelResult::fail($exception->getMessage(), $exception->getMessage());
        }

        if (! filled($info['port'] ?? null)) {
            return PanelResult::fail(__('VNC is not turned on for this VPS.'));
        }

        return PanelResult::secret(__('VNC details are shown below.'), array_values(array_filter([
            ['label' => __('IP address'), 'value' => is_scalar($info['ip'] ?? null) ? (string) $info['ip'] : ''],
            ['label' => __('Port'), 'value' => (string) $info['port']],
            ['label' => __('Password'), 'value' => is_scalar($info['password'] ?? null) ? (string) $info['password'] : ''],
        ], fn (array $row): bool => $row['value'] !== '')));
    }

    private function changeHostname(Service $service, string $hostname): ModuleResult
    {
        $hostname = strtolower(trim($hostname));

        if (! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $hostname)) {
            return ModuleResult::fail(__('Enter a hostname like server.example.com.'));
        }

        return $this->attempt(function () use ($service, $hostname): ModuleResult {
            $vpsId = AdminApi::vpsId($service);
            $result = $this->simple($service, ['act' => 'managevps', 'vpsid' => $vpsId], ['editvps' => 1, 'hostname' => $hostname], __('Hostname changed. It takes effect after a restart.'));

            if ($result->success) {
                Cache::forget($this->factsKey($service, $vpsId));
            }

            return $result;
        });
    }

    private function changePassword(Service $service, string $password): ModuleResult
    {
        if (! $this->strongPassword($password)) {
            return ModuleResult::fail(__('Use at least 10 characters with letters and numbers.'));
        }

        return $this->attempt(function () use ($service, $password): ModuleResult {
            $result = $this->simple($service, ['act' => 'managevps', 'vpsid' => AdminApi::vpsId($service)], ['editvps' => 1, 'rootpass' => $password], __('Root password changed. It takes effect after a restart.'));

            if ($result->success) {
                $service->update(['password' => $password]);
            }

            return $result;
        });
    }

    /**
     * A new OS from the templates the product allows, with a new root password. The product's
     * monthly limit is counted here, as Virtualizor's Admin API does not count it.
     */
    private function reinstall(Service $service, string $template, string $password, bool $runRecipe): ModuleResult
    {
        return $this->attempt(function () use ($service, $template, $password, $runRecipe): ModuleResult {
            if (! array_key_exists($template, $this->allowedTemplates($service))) {
                return ModuleResult::fail(__('Choose an operating system from the list.'));
            }

            if (! $this->strongPassword($password)) {
                return ModuleResult::fail(__('Use at least 10 characters with letters and numbers.'));
            }

            if ($this->reinstallsLeft($service) === 0) {
                return ModuleResult::fail(__('You have used all OS reinstalls for this month. Contact support if you need another one.'));
            }

            $vpsId = AdminApi::vpsId($service);
            // Only the product's own recipe, set by staff, can run again.
            $recipe = $runRecipe ? $this->recipe($service) : null;

            $result = $this->simple($service, ['act' => 'rebuild'], array_filter([
                'vpsid' => $vpsId,
                'osid' => $template,
                'newpass' => $password,
                'conf' => $password,
                'reos' => 1,
                'recipe' => $recipe,
            ], fn (mixed $value): bool => $value !== null), __('The reinstall has started. All data on the VPS is being erased.'));

            if ($result->success) {
                $data = (array) $service->module_data;
                $month = Date::now()->format('Y-m');
                $used = is_array($data['reinstalls'] ?? null) && ($data['reinstalls']['month'] ?? null) === $month ? (int) ($data['reinstalls']['count'] ?? 0) : 0;
                $data['reinstalls'] = ['month' => $month, 'count' => $used + 1];

                $service->forceFill(['password' => $password, 'module_data' => $data])->save();
                Cache::forget($this->factsKey($service, $vpsId));
            }

            return $result;
        });
    }

    /**
     * The OS templates of the product's virtualization type that clients may choose, as ID =>
     * name: every template, or only those in the product's "OS templates clients may choose".
     *
     * @return array<string, string>
     */
    private function allowedTemplates(Service $service): array
    {
        $templates = $this->admin->templates($service->server ?? throw new RuntimeException(__('No server is assigned.')), (string) self::setting($service, 'virt', 'kvm'));
        $allowed = $this->allowedIds($service);

        return $allowed === null ? $templates : array_intersect_key($templates, array_flip($allowed));
    }

    /**
     * The template IDs the product allows, or null for every template. A setting that is not a
     * comma-separated list of IDs allows none, and staff see why once a day.
     *
     * @return list<string>|null
     */
    private function allowedIds(Service $service): ?array
    {
        $value = self::setting($service, 'reinstall_os_ids');
        $value = is_scalar($value) ? (string) preg_replace('/\s+/', '', (string) $value) : '';

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,10}(,\d{1,10})*\z/', $value) !== 1) {
            if (Cache::add('nuvabill.virtualizor.reinstall-ids.'.$service->product_id, true, 86400)) {
                Activity::log('service.module_failed', "Clients of the product of service #{$service->id} ({$service->label()}) cannot reinstall: \"OS templates clients may choose\" is not a comma-separated list of OS IDs. Fix it on the product.", $service);
            }

            return [];
        }

        return array_values(array_unique(explode(',', $value)));
    }

    /**
     * Reinstalls left this month under the product's monthly limit, or null when it has none.
     */
    private function reinstallsLeft(Service $service): ?int
    {
        $limit = filter_var(self::setting($service, 'osreinstall_limit', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if (! is_int($limit) || $limit === 0) {
            return null;
        }

        $used = (array) ($service->module_data['reinstalls'] ?? []);
        $count = ($used['month'] ?? null) === Date::now()->format('Y-m') ? (int) ($used['count'] ?? 0) : 0;

        return max(0, $limit - $count);
    }

    /**
     * The product's recipe, or null when it has none or an invalid one.
     */
    private function recipe(Service $service): ?int
    {
        $value = self::setting($service, 'recipe_id');
        $recipe = is_int($value) || is_string($value) ? filter_var(trim((string) $value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) : false;

        return $recipe === false ? null : $recipe;
    }

    private function strongPassword(string $password): bool
    {
        return strlen($password) >= 10 && preg_match('/[a-z]/i', $password) && preg_match('/\d/', $password);
    }

    /**
     * The panel answer for a module result. A failure is shown as it is (Virtualizor's messages
     * are cleaned of secrets) and kept for staff.
     */
    private function result(ModuleResult $result): PanelResult
    {
        if (! $result->success) {
            return PanelResult::fail($result->message, $result->message);
        }

        $pending = $result->data['pending'] ?? null;
        $state = $result->data['state'] ?? null;

        return PanelResult::ok($result->message, [], is_string($pending) ? $pending : null, is_string($state) ? $state : null);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $post
     */
    private function simple(Service $service, array $query, array $post, string $message): ModuleResult
    {
        $response = $this->admin->call($service->server, $query, $post);

        return empty($response['done']) && empty($response['vsop']) && empty($response['vs_info'])
            ? ModuleResult::fail(__('Virtualizor did not confirm the action.'))
            : ModuleResult::ok($message);
    }

    /**
     * @param  callable(): ModuleResult  $callback
     */
    private function attempt(callable $callback): ModuleResult
    {
        try {
            return $callback();
        } catch (RuntimeException $exception) {
            return ModuleResult::fail($exception->getMessage());
        }
    }

    /**
     * A product setting of the service's product.
     */
    private static function setting(Service $service, string $key, mixed $default = null): mixed
    {
        return $service->product?->module_config[$key] ?? $default;
    }
}
