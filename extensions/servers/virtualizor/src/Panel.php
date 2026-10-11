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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * The client server panel of a Virtualizor VPS: the sections it offers, what the live server
 * allows, and every read and action. Core checks the owner, status, product switches, input,
 * limits and the service lock first, so this class only gets input that passed the rules. It
 * still checks again what only the server can know: an IP address, backup, key or plan must be
 * the VPS's own before anything is changed.
 *
 * Overview, power, console, reinstall and settings (hostname and root password) run on the Admin
 * API, so they work even when Virtualizor's end-user port cannot be reached. Every other section
 * runs on the end-user panel (EnduserApi) and follows Virtualizor's own switches for the VPS
 * (vpsmanage flags). Sections of the whole Virtualizor user (SSH keys, firewall plans, logs) are
 * only shown when that user holds nothing of another client (SignIn::exclusive).
 */
final class Panel
{
    /**
     * Sections a VPS keeps when the end-user port does not answer: they all run on the Admin API.
     *
     * @var list<string>
     */
    public const BASIC_SECTIONS = ['overview', 'power', 'console', 'reinstall', 'settings'];

    /**
     * A hostname: labels of letters, digits and dashes, at least two of them.
     */
    private const HOSTNAME = '/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+\z/';

    /**
     * A reverse DNS name: labels of letters, digits and dashes, ending in a name of letters.
     */
    private const PTR = '/^(?=.{1,253}\z)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]\z/i';

    /**
     * Seconds the VPS details of the overview (hostname, OS, addresses, plan) are kept, so a live
     * refresh asks Virtualizor only for the figures.
     */
    private const FACTS_SECONDS = 60;

    /**
     * Seconds the end-user panel's VPS switches (vpsmanage) are kept, and how long a failed read
     * waits before the end-user port is asked again.
     */
    private const MANAGE_SECONDS = 60;

    private const MANAGE_FAILED_SECONDS = 300;

    /**
     * Virtualization types with an ISO drive, a boot order and a VNC password of their own.
     *
     * @var list<string>
     */
    private const HVM = ['kvm', 'xenhvm', 'proxk'];

    /**
     * Months of graphs a client can look back, this month included.
     */
    private const GRAPH_MONTHS = 12;

    /**
     * The most points of one graph series sent to the browser.
     */
    private const GRAPH_POINTS = 400;

    /**
     * Virtualizor's boot order values: disk first, or the CD (the ISO image) first.
     *
     * @var array<string, string>
     */
    private const BOOT_ORDERS = ['disk' => 'cda', 'cdrom' => 'dca'];

    /**
     * SSH key types clients may add. DSA (ssh-dss) is never allowed.
     *
     * @var list<string>
     */
    private const SSH_KEY_TYPES = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'sk-ssh-ed25519@openssh.com', 'ssh-rsa'];

    /**
     * The most SSH keys an account may hold through the panel.
     */
    private const SSH_KEY_LIMIT = 50;

    public function __construct(private AdminApi $admin, private EnduserApi $enduser, private SignIn $signIn) {}

    /**
     * The sections of the product's panel, in display order. Never calls a server.
     *
     * Sections whose Virtualizor calls are not tested on every version start switched off for new
     * products: firewall plans, ISO and boot, and backups (on by default only for OpenVZ, where
     * Virtualizor documents them). Staff can turn them on per product.
     *
     * @return list<PanelSection>
     */
    public function sections(Product $product): array
    {
        $virt = (string) ($product->module_config['virt'] ?? 'kvm');
        $hvm = in_array($virt, self::HVM, true);
        $rootRule = ['required', 'string', 'min:10', 'max:128', 'regex:/[A-Za-z]/', 'regex:/\d/', 'not_regex:/[\x00-\x1F\x7F]/'];
        $password = [...$rootRule, 'confirmed'];
        $digits = ['required', 'string', 'regex:/^\d{1,10}\z/'];
        $untested = __('Not tested with every Virtualizor version yet. Try it on a test VPS before you turn it on.');
        $userWide = __('Clients only see it when their Virtualizor user has no VPS of other clients.');

        $settings = [
            'hostname' => new PanelAction(key: 'hostname', label: __('Change hostname'), rules: ['hostname' => ['required', 'string', 'max:253', 'regex:'.self::HOSTNAME]]),
            'root-password' => new PanelAction(
                key: 'root-password',
                label: __('Change root password'),
                rules: ['password' => $password],
                notify: 'The root password of your server was changed.',
                secretFields: ['password', 'password_confirmation'],
            ),
        ];

        if ($hvm) {
            $settings['vnc-password'] = new PanelAction(
                key: 'vnc-password',
                label: __('Change console password'),
                rules: ['password' => ['required', 'string', 'min:6', 'max:8', 'regex:/^[A-Za-z0-9]+\z/']],
                notify: 'The console password of your server was changed.',
                secretFields: ['password'],
            );
        }

        return array_values(array_filter([
            PanelSection::standard('overview', cacheSeconds: 5, readThrottle: [30, 60]),
            PanelSection::standard('console', actions: [
                'details' => new PanelAction(key: 'details', label: __('Show VNC details'), changesServer: false, reveals: true),
            ]),
            PanelSection::standard('graphs', readRules: ['month' => ['nullable', 'string', 'regex:/^\d{4}(0[1-9]|1[0-2])\z/']], cacheSeconds: 120),
            PanelSection::standard('reinstall', actions: [
                'run' => new PanelAction(
                    key: 'run',
                    label: __('Reinstall OS'),
                    rules: [
                        'template' => $digits,
                        'password' => $password,
                        'run_recipe' => ['nullable', 'boolean'],
                        'confirm' => ['accepted'],
                    ],
                    destructive: true,
                    notify: 'The operating system of your server was reinstalled. All data on it was erased.',
                    secretFields: ['password', 'password_confirmation'],
                ),
            ], cacheSeconds: 300),
            PanelSection::standard('network', actions: [
                'rdns-set' => new PanelAction(key: 'rdns-set', label: __('Save'), rules: ['ip' => ['required', 'ip'], 'ptr' => ['required', 'string', 'max:253', 'regex:'.self::PTR]]),
                'rdns-delete' => new PanelAction(key: 'rdns-delete', label: __('Remove'), rules: ['ip' => ['required', 'ip']]),
            ], cacheSeconds: 30),
            PanelSection::standard('firewall', actions: [
                'apply' => new PanelAction(key: 'apply', label: __('Use this plan'), rules: ['plan_id' => $digits], notify: 'The firewall of your server was changed.'),
                'create' => new PanelAction(key: 'create', label: __('Save the plan'), rules: [
                    'name' => ['required', 'string', 'max:50', 'regex:/^[\pL\pN _.-]+\z/u'],
                    'default_policy' => ['required', 'in:accept,drop'],
                    'rules' => ['required', 'array', 'min:1', 'max:30'],
                    'rules.*.direction' => ['required', 'in:in,out'],
                    'rules.*.protocol' => ['required', 'in:tcp,udp,icmp,all'],
                    'rules.*.port' => ['nullable', 'string', 'regex:/^\d{1,5}(:\d{1,5})?\z/'],
                    'rules.*.source' => ['nullable', 'string', 'max:64'],
                    'rules.*.action' => ['required', 'in:accept,drop'],
                ]),
                'delete' => new PanelAction(key: 'delete', label: __('Delete this plan'), rules: ['plan_id' => $digits, 'confirm' => ['accepted']], destructive: true),
            ], cacheSeconds: 15, enabledByDefault: false, staffHelp: $untested.' '.$userWide),
            PanelSection::standard('backups', actions: [
                'create' => new PanelAction(key: 'create', label: __('Make a backup')),
                'restore' => new PanelAction(
                    key: 'restore',
                    label: __('Restore'),
                    rules: ['id' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,64}\z/'], 'confirm' => ['accepted']],
                    destructive: true,
                    notify: 'A backup was restored on your server. The data that was on the server was replaced.',
                ),
                'delete' => new PanelAction(key: 'delete', label: __('Delete'), rules: ['id' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,64}\z/'], 'confirm' => ['accepted']], destructive: true),
            ], cacheSeconds: 15, enabledByDefault: $virt === 'openvz', staffHelp: $virt === 'openvz' ? null : $untested),
            PanelSection::standard('rescue', actions: [
                'enable' => new PanelAction(
                    key: 'enable',
                    label: __('Turn on rescue mode'),
                    rules: ['password' => $rootRule, 'reboot' => ['nullable', 'boolean'], 'confirm' => ['accepted']],
                    notify: 'Rescue mode was turned on for your server.',
                    secretFields: ['password'],
                ),
                'disable' => new PanelAction(key: 'disable', label: __('Turn off rescue mode'), rules: ['reboot' => ['nullable', 'boolean']]),
            ], cacheSeconds: 10),
            PanelSection::standard('ssh-keys', actions: [
                'add' => new PanelAction(key: 'add', label: __('Add key'), rules: [
                    'name' => ['required', 'string', 'max:50', 'regex:/^[\pL\pN _.@-]+\z/u'],
                    'key' => ['required', 'string', 'max:16384'],
                ], notify: 'An SSH key was added to your account.'),
                'delete' => new PanelAction(key: 'delete', label: __('Delete'), rules: ['id' => $digits]),
                'apply' => new PanelAction(key: 'apply', label: __('Add selected keys to this server'), rules: [
                    'keys' => ['required', 'array', 'min:1', 'max:10'],
                    'keys.*' => $digits,
                ], notify: 'SSH keys were added to your server.'),
            ], cacheSeconds: 15, staffHelp: $userWide),
            $hvm ? PanelSection::standard('media', actions: [
                'mount' => new PanelAction(key: 'mount', label: __('Mount'), rules: ['iso' => ['nullable', 'string', 'max:255']]),
                'boot' => new PanelAction(key: 'boot', label: __('Save the boot order'), rules: ['order' => ['required', 'in:disk,cdrom']]),
            ], cacheSeconds: 15, enabledByDefault: false, staffHelp: $untested) : null,
            PanelSection::standard('settings', actions: $settings),
            PanelSection::standard('activity', readRules: ['page' => ['nullable', 'integer', 'min:1', 'max:50']], cacheSeconds: 15),
            PanelSection::standard('power', actions: [
                'start' => StandardSections::powerAction('start'),
                'stop' => StandardSections::powerAction('stop'),
                'restart' => StandardSections::powerAction('restart'),
                'poweroff' => StandardSections::powerAction('poweroff'),
            ]),
        ]));
    }

    /**
     * The section keys the live server allows for this service now, from Virtualizor's switches
     * for the VPS. When the end-user port does not answer, only the sections that run on the
     * Admin API stay (staff see why once a day).
     *
     * @return list<string>|null
     */
    public function available(Service $service, ClientContext $context): ?array
    {
        $manage = $this->manage($service, $context);

        if ($manage === null) {
            return self::BASIC_SECTIONS;
        }

        $flag = fn (string $name): bool => ($manage['flags'][$name] ?? 0) > 0;
        $vps = $manage['vps'];

        if ($vps['admin_managed'] || $flag('power_only_option')) {
            return ['overview', 'power'];
        }

        $exclusive = null;
        $userWide = function () use (&$exclusive, $service): bool {
            return $exclusive ??= $this->signIn->exclusive($service);
        };

        $keys = ['overview', 'power', 'network', 'settings'];

        if ($vps['vnc'] !== false || $flag('novnc')) {
            $keys[] = 'console';
        }

        if (! $flag('disable_icons_monitor')) {
            $keys[] = 'graphs';
        }

        if (! $flag('disable_os_reinstall')) {
            $keys[] = 'reinstall';
        }

        if ($flag('bpid') && ! $flag('disable_backup_cp')) {
            $keys[] = 'backups';
        }

        if ($flag('rescue_mode')) {
            $keys[] = 'rescue';
        }

        if ($flag('hvmsettings') && ! $flag('disable_vps_config')) {
            $keys[] = 'media';
        }

        if (! $flag('disable_enduser_sshkeys') && $userWide()) {
            $keys[] = 'ssh-keys';
        }

        if ($userWide()) {
            $keys[] = 'firewall';
        }

        if (! $flag('hide_eu_tasks') || (! $flag('disable_logs') && $userWide())) {
            $keys[] = 'activity';
        }

        return $keys;
    }

    /**
     * Read one section in its standard data shape.
     *
     * @param  array<string, mixed>  $input  Only the keys of the section's read rules.
     *
     * @throws RuntimeException When Virtualizor's Admin API does not answer.
     */
    public function read(Service $service, string $section, array $input, ClientContext $context): PanelResult
    {
        return match ($section) {
            'overview' => $this->overview($service, $context),
            'console' => $this->consoleState($service, $context),
            'graphs' => $this->guarded(fn (): PanelResult => $this->graphs($service, (string) ($input['month'] ?? ''), $context)),
            'reinstall' => $this->reinstallOptions($service),
            'network' => $this->network($service, $context),
            'firewall' => $this->guarded(fn (): PanelResult => $this->firewall($service, $context)),
            'backups' => $this->guarded(fn (): PanelResult => $this->backups($service, $context)),
            'rescue' => $this->guarded(fn (): PanelResult => $this->rescue($service, $context)),
            'ssh-keys' => $this->guarded(fn (): PanelResult => $this->sshKeys($service, $context)),
            'media' => $this->guarded(fn (): PanelResult => $this->media($service, $context)),
            'settings' => $this->settings($service, $context),
            'activity' => $this->guarded(fn (): PanelResult => $this->activity($service, (int) ($input['page'] ?? 1), $context)),
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
        $text = fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $yes = fn (string $key): bool => filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOLEAN);

        return match ($section.'.'.$action) {
            'power.start', 'power.stop', 'power.restart', 'power.poweroff' => $this->powerResult($service, $action),
            'console.details' => $this->vncDetails($service, $context),
            'reinstall.run' => $this->result($this->reinstall($service, $text('template'), (string) ($input['password'] ?? ''), $yes('run_recipe'))),
            'settings.hostname' => $this->guarded(fn (): PanelResult => $this->changeHostnameIfAllowed($service, $text('hostname'), $context)),
            'settings.root-password' => $this->guarded(fn (): PanelResult => $this->changePasswordIfAllowed($service, (string) ($input['password'] ?? ''), $context)),
            'settings.vnc-password' => $this->guarded(fn (): PanelResult => $this->changeVncPassword($service, (string) ($input['password'] ?? ''), $context)),
            'network.rdns-set' => $this->guarded(fn (): PanelResult => $this->setRdns($service, $text('ip'), $text('ptr'), $context)),
            'network.rdns-delete' => $this->guarded(fn (): PanelResult => $this->deleteRdns($service, $text('ip'), $context)),
            'firewall.apply' => $this->guarded(fn (): PanelResult => $this->applyFirewall($service, $text('plan_id'), $context)),
            'firewall.create' => $this->guarded(fn (): PanelResult => $this->createFirewall($service, $text('name'), $text('default_policy'), (array) ($input['rules'] ?? []), $context)),
            'firewall.delete' => $this->guarded(fn (): PanelResult => $this->deleteFirewall($service, $text('plan_id'), $context)),
            'backups.create' => $this->guarded(fn (): PanelResult => $this->createBackup($service, $context)),
            'backups.restore' => $this->guarded(fn (): PanelResult => $this->backupAction($service, $text('id'), 'restore', $context)),
            'backups.delete' => $this->guarded(fn (): PanelResult => $this->backupAction($service, $text('id'), 'delete', $context)),
            'rescue.enable' => $this->guarded(fn (): PanelResult => $this->enableRescue($service, (string) ($input['password'] ?? ''), $yes('reboot'), $context)),
            'rescue.disable' => $this->guarded(fn (): PanelResult => $this->disableRescue($service, $yes('reboot'), $context)),
            'ssh-keys.add' => $this->guarded(fn (): PanelResult => $this->addSshKey($service, $text('name'), (string) ($input['key'] ?? ''), $context)),
            'ssh-keys.delete' => $this->guarded(fn (): PanelResult => $this->deleteSshKey($service, $text('id'), $context)),
            'ssh-keys.apply' => $this->guarded(fn (): PanelResult => $this->applySshKeys($service, (array) ($input['keys'] ?? []), $context)),
            'media.mount' => $this->guarded(fn (): PanelResult => $this->changeMedia($service, ['iso' => $text('iso')], $context)),
            'media.boot' => $this->guarded(fn (): PanelResult => $this->changeMedia($service, ['order' => $text('order')], $context)),
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
     * The overview: live figures from the status call, the VPS details (kept a minute) and notices
     * from Virtualizor's switches. The figures and the details may each fail on their own; when
     * both fail, the read fails.
     */
    private function overview(Service $service, ClientContext $context): PanelResult
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
            'notices' => $this->notices($service, $context),
            'power_note' => null,
            'live' => true,
        ]);
    }

    /**
     * Notices for the overview from the end-user panel's switches for the VPS: setup waiting, the
     * network suspended, rescue mode on. None when the end-user port does not answer.
     *
     * @return list<array{tone: string, text: string}>
     */
    private function notices(Service $service, ClientContext $context): array
    {
        $manage = $this->manage($service, $context);

        if ($manage === null) {
            return [];
        }

        $notices = [];

        if ($manage['vps']['setup_pending']) {
            $notices[] = ['tone' => 'warn', 'text' => __('Your server is waiting for setup. Contact support.')];
        }

        if ($manage['nw_suspended']['on']) {
            $reason = $manage['nw_suspended']['reason'];
            $notices[] = ['tone' => 'crit', 'text' => $reason !== null
                ? __('The network of your server is suspended: :reason', ['reason' => $reason])
                : __('The network of your server is suspended. Contact support.')];
        }

        if ($manage['vps']['rescue']) {
            $notices[] = ['tone' => 'info', 'text' => __('Rescue mode is on. Turn it off in the Rescue tab when you are done.')];
        }

        return $notices;
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
     * The end-user panel's switches for the VPS (vpsmanage, projected by EnduserApi), kept a
     * minute, or null when the end-user port cannot be used. A failed read is not tried again for
     * five minutes, so a closed port never slows every page; staff see why once a day per server.
     *
     * @return array<string, mixed>|null
     *
     * @throws PanelException When the answer is about another VPS than the service's.
     */
    private function manage(Service $service, ?ClientContext $context): ?array
    {
        $port = $this->enduser->port($service);

        if ($port === null || $service->server === null) {
            return null;
        }

        $vpsId = AdminApi::vpsId($service);
        $key = $this->manageKey($service, $vpsId);
        $cached = Cache::get($key);

        if (is_array($cached) && array_key_exists('data', $cached)) {
            return is_array($cached['data']) ? $cached['data'] : null;
        }

        try {
            $data = $this->endUserCall($service, $context, 'vpsmanage');
        } catch (PanelException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Cache::put($key, ['data' => null], self::MANAGE_FAILED_SECONDS);

            if (Cache::add('nuvabill:vz:basic:'.$service->server_id, true, 86400)) {
                Activity::log('service.module_failed', "The client panel of service #{$service->id} shows only basic features: allow Virtualizor's end-user port {$port} from this server's IP and check its SSL certificate. It did not answer: {$exception->getMessage()}", $service);
            }

            return null;
        }

        Cache::put($key, ['data' => $data], self::MANAGE_SECONDS);

        return $data;
    }

    private function manageKey(Service $service, string $vpsId): string
    {
        return "nuvabill:vz:manage:{$service->id}:{$vpsId}";
    }

    /**
     * Whether a Virtualizor switch of the VPS is on. Unknown (the end-user port did not answer)
     * counts as off.
     *
     * @param  array<string, mixed>|null  $manage
     */
    private static function flag(?array $manage, string $name): bool
    {
        return (int) ($manage['flags'][$name] ?? 0) > 0;
    }

    /**
     * One end-user panel call for the service's VPS. The VPS's UUID is kept the first time it is
     * seen; an answer for a VPS with another UUID (the ID was given to a new VPS) is refused.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     *
     * @throws PanelException When the product has no end-user port, or the VPS changed.
     * @throws RuntimeException When the end-user panel does not answer or refuses.
     */
    private function endUserCall(Service $service, ?ClientContext $context, string $key, array $query = [], array $post = [], int $timeout = 8): array
    {
        $server = $service->server ?? throw new RuntimeException(__('No server is assigned.'));
        $port = $this->enduser->port($service) ?? throw new PanelException(__('This feature is not available on your server.'), 'The product has no valid end-user panel port.', 403);
        $vpsId = AdminApi::vpsId($service);

        $answer = $this->enduser->call($server, $port, $vpsId, $context, $key, $query, $post, $timeout);
        $uuid = $answer['vps']['uuid'] ?? null;

        if (is_string($uuid) && $uuid !== '') {
            $stored = $service->module_data['vps_uuid'] ?? null;

            if (is_string($stored) && $stored !== '' && $stored !== $uuid) {
                $detail = "Virtualizor VPS #{$vpsId} now has UUID {$uuid}, not {$stored} as when it was linked. Check the VPS ID of the service.";

                if (Cache::add('nuvabill:vz:uuid-changed:'.$service->id, true, 86400)) {
                    Activity::log('service.module_failed', "The client panel of service #{$service->id} is refused: {$detail}", $service);
                }

                throw new PanelException(__('This server changed. Contact support.'), $detail);
            }

            if (! is_string($stored) || $stored === '') {
                $fresh = Service::query()->find($service->id);
                $service->forceFill(['module_data' => array_merge((array) ($fresh->module_data ?? $service->module_data), ['vps_uuid' => $uuid])])->save();
            }
        }

        return $answer;
    }

    /**
     * Account-wide sections (SSH keys, firewall plans, logs) need a Virtualizor user that holds
     * only this client's VPS.
     *
     * @throws PanelException When the user holds VPS of other clients.
     */
    private function requireExclusive(Service $service): void
    {
        if (! $this->signIn->exclusive($service)) {
            throw new PanelException(__('This feature is not available on your server.'), 'The Virtualizor user of this VPS also has VPS of other clients.', 403);
        }
    }

    /**
     * Run a read or action on the end-user panel: Virtualizor's refusal is shown as it is (cleaned
     * of secrets), a connection problem as a general message.
     *
     * @param  callable(): PanelResult  $callback
     */
    private function guarded(callable $callback): PanelResult
    {
        try {
            return $callback();
        } catch (PanelException $exception) {
            return $exception->toResult();
        } catch (RuntimeException $exception) {
            return $exception->getCode() === AdminApi::REFUSED
                ? PanelResult::fail($exception->getMessage(), $exception->getMessage())
                : PanelResult::fail(__('The server did not answer. Please try again in a minute.'), $exception->getMessage(), 503);
        }
    }

    /**
     * The console tab: whether the console can open, and whether the VNC details may be shown.
     */
    private function consoleState(Service $service, ClientContext $context): PanelResult
    {
        $manage = $this->manage($service, $context);
        $available = $this->enduser->port($service) !== null && ($manage === null || $manage['vps']['vnc'] !== false || self::flag($manage, 'novnc'));

        return PanelResult::ok('', array_filter([
            'available' => $available,
            'details' => ! self::flag($manage, 'hide_enduser_vnc_info'),
            'reason' => $available ? null : __('The console is not available for this server right now.'),
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Graphs of one month: CPU, memory, disk, network and disk use from Virtualizor's monitor,
     * with at most 400 points each, and the traffic per day.
     */
    private function graphs(Service $service, string $month, ClientContext $context): PanelResult
    {
        $now = Date::now();
        $month = $month !== '' ? $month : $now->format('Ym');
        $first = $now->copy()->startOfMonth()->subMonths(self::GRAPH_MONTHS - 1)->format('Ym');
        $invalid = fn (): PanelResult => PanelResult::invalid(['month' => [__('Choose a month from the list.')]]);

        if ($month > $now->format('Ym') || $month < $first) {
            return $invalid();
        }

        $monitor = $this->endUserCall($service, $context, 'monitor', ['show' => $month]);
        $created = is_int($monitor['created'] ?? null) ? Date::createFromTimestamp($monitor['created'])->format('Ym') : null;

        if ($created !== null && $month < $created) {
            return $invalid();
        }

        try {
            $bandwidth = $this->endUserCall($service, $context, 'bandwidth', ['show' => $month]);
        } catch (PanelException $exception) {
            throw $exception;
        } catch (RuntimeException) {
            $bandwidth = null;
        }

        $series = [];
        $names = [
            'cpu' => [__('CPU'), '%'],
            'ram' => [__('Memory'), 'MB'],
            'disk' => [__('Disk'), 'GB'],
            'net_in' => [__('Incoming'), 'B/s'],
            'net_out' => [__('Outgoing'), 'B/s'],
            'io_read' => [__('Disk read'), 'B/s'],
            'io_write' => [__('Disk write'), 'B/s'],
        ];

        foreach ($names as $key => [$label, $unit]) {
            $points = self::downsample((array) ($monitor['series'][$key] ?? []));

            if ($points !== []) {
                $series[] = ['key' => $key, 'label' => $label, 'unit' => $unit, 'points' => $points];
            }
        }

        $start = Carbon::createFromFormat('!Ym', $month, $now->getTimezone());
        $bars = [];

        foreach ((array) ($bandwidth['days'] ?? []) as $day => [$in, $out]) {
            if ($day <= $start->daysInMonth) {
                $bars[] = [$start->copy()->setDay($day)->getTimestamp(), $in, $out];
            }
        }

        $options = [];
        $from = max($first, $created ?? $first);

        for ($option = $now->copy()->startOfMonth(); $option->format('Ym') >= $from; $option = $option->copy()->subMonth()) {
            $options[] = ['value' => $option->format('Ym'), 'label' => $option->translatedFormat('F Y')];
        }

        return PanelResult::ok('', array_filter([
            'period' => ['kind' => 'month', 'param' => 'month', 'value' => $month, 'options' => $options],
            'series' => $series,
            'bars' => $bars !== [] ? ['label' => __('Traffic per day'), 'unit' => 'MB', 'points' => $bars] : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * At most GRAPH_POINTS points: neighbouring points are averaged.
     *
     * @param  list<array{0: int, 1: float|null}>  $points
     * @return list<array{0: int, 1: float|null}>
     */
    private static function downsample(array $points): array
    {
        $points = array_values(array_filter($points, fn (mixed $point): bool => is_array($point) && is_int($point[0] ?? null)));

        if (count($points) <= self::GRAPH_POINTS) {
            return $points;
        }

        $result = [];

        foreach (array_chunk($points, (int) ceil(count($points) / self::GRAPH_POINTS)) as $chunk) {
            $values = array_filter(array_column($chunk, 1), fn (mixed $value): bool => $value !== null);
            $result[] = [$chunk[0][0], $values === [] ? null : round(array_sum($values) / count($values), 3)];
        }

        return $result;
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
     * The IPs and rDNS tab: the VPS's addresses from the Admin API, with their reverse DNS names
     * when Virtualizor allows rDNS for the VPS. Only records of the VPS's own addresses are shown.
     */
    private function network(Service $service, ClientContext $context): PanelResult
    {
        $info = $this->admin->vpsInfo($service->server, AdminApi::vpsId($service));
        $ips = $info !== [] ? AdminApi::addresses($info) : AdminApi::addresses(['ips' => (array) ($service->module_data['ips'] ?? [])]);
        $subnets = self::subnets($info);
        $rdns = self::flag($this->manage($service, $context), 'enable_rdns');
        $records = [];
        $notices = [];

        if ($rdns) {
            try {
                $records = $this->ownRecords($service, $ips, $subnets, $context);
            } catch (PanelException $exception) {
                throw $exception;
            } catch (RuntimeException) {
                $rdns = false;
                $notices[] = ['tone' => 'warn', 'text' => __('Reverse DNS names could not be read right now. Try again in a minute.')];
            }
        }

        return PanelResult::ok('', [
            'ips' => array_map(fn (string $ip, int $index): array => [
                'ip' => $ip,
                'version' => str_contains($ip, ':') ? 6 : 4,
                'primary' => $index === 0,
                'rdns' => $this->recordFor($records, $ip)['content'] ?? null,
            ], $ips, array_keys($ips)),
            'subnets' => array_map(fn (string $cidr): array => ['cidr' => $cidr], $subnets),
            'rdns' => $rdns,
            'notices' => $notices,
            'can' => ['rdns-set' => $rdns && $ips !== [], 'rdns-delete' => $rdns],
        ]);
    }

    /**
     * The reverse DNS records of the account that belong to the VPS's own addresses.
     *
     * @param  list<string>  $ips
     * @param  list<string>  $subnets
     * @return list<array{id: string, ip: string, content: string}>
     */
    private function ownRecords(Service $service, array $ips, array $subnets, ?ClientContext $context): array
    {
        $records = $this->endUserCall($service, $context, 'rdns')['records'] ?? [];

        return array_values(array_filter($records, fn (array $record): bool => self::ownsIp($record['ip'], $ips, $subnets)));
    }

    /**
     * @param  list<array{id: string, ip: string, content: string}>  $records
     * @return array{id: string, ip: string, content: string}|null
     */
    private function recordFor(array $records, string $ip): ?array
    {
        $binary = @inet_pton($ip);

        foreach ($records as $record) {
            if ($binary !== false && @inet_pton($record['ip']) === $binary) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Set the reverse DNS name of one of the VPS's own addresses (an IPv6 address may also be in
     * one of its subnets).
     */
    private function setRdns(Service $service, string $ip, string $ptr, ClientContext $context): PanelResult
    {
        if (! self::flag($this->manage($service, $context), 'enable_rdns')) {
            return PanelResult::unavailable(__('Reverse DNS is not available for this server.'));
        }

        $info = $this->admin->vpsInfo($service->server, AdminApi::vpsId($service));

        if (! self::ownsIp($ip, AdminApi::addresses($info), self::subnets($info))) {
            return PanelResult::invalid(['ip' => [__('Choose an IP address of this server.')]]);
        }

        $ptr = strtolower(rtrim($ptr, '.'));
        $answer = $this->endUserCall($service, $context, 'rdns', [], ['rdns' => 1, 'rdns_ip' => $ip, 'rdns_domain' => $ptr], 20);

        return $answer['done']
            ? PanelResult::ok(__('Reverse DNS saved.'), logDetail: "{$ip} → {$ptr}")
            : PanelResult::fail(__('Virtualizor did not confirm the action.'));
    }

    /**
     * Remove the reverse DNS record of one of the VPS's own addresses, found again in a fresh list.
     */
    private function deleteRdns(Service $service, string $ip, ClientContext $context): PanelResult
    {
        if (! self::flag($this->manage($service, $context), 'enable_rdns')) {
            return PanelResult::unavailable(__('Reverse DNS is not available for this server.'));
        }

        $info = $this->admin->vpsInfo($service->server, AdminApi::vpsId($service));
        $ips = AdminApi::addresses($info);
        $subnets = self::subnets($info);

        if (! self::ownsIp($ip, $ips, $subnets)) {
            return PanelResult::invalid(['ip' => [__('Choose an IP address of this server.')]]);
        }

        $record = $this->recordFor($this->ownRecords($service, $ips, $subnets, $context), $ip);

        if ($record === null) {
            return PanelResult::fail(__('This address has no reverse DNS name.'));
        }

        $answer = $this->endUserCall($service, $context, 'rdns', ['delete' => $record['id']], [], 20);

        return $answer['done']
            ? PanelResult::ok(__('Reverse DNS removed.'), logDetail: $ip)
            : PanelResult::fail(__('Virtualizor did not confirm the action.'));
    }

    /**
     * The VPS's IPv6 subnets with their prefix length, from the Admin API.
     *
     * @param  array<string, mixed>  $info
     * @return list<string>
     */
    private static function subnets(array $info): array
    {
        $subnets = [];

        foreach ((array) ($info['ips6_subnet'] ?? []) as $value) {
            if (is_string($value) && preg_match('#^([0-9a-f:]+)/(\d{1,3})\z#i', trim($value), $match) === 1
                && filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && (int) $match[2] >= 1 && (int) $match[2] <= 128) {
                $subnets[] = strtolower($match[1]).'/'.(int) $match[2];
            }
        }

        return array_values(array_unique($subnets));
    }

    /**
     * Whether the address is one of the VPS's own: an exact match, or an IPv6 address inside one
     * of its subnets.
     *
     * @param  list<string>  $ips
     * @param  list<string>  $subnets
     */
    private static function ownsIp(string $ip, array $ips, array $subnets): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        foreach ($ips as $own) {
            if (@inet_pton($own) === $binary) {
                return true;
            }
        }

        if (strlen($binary) !== 16) {
            return false;
        }

        foreach ($subnets as $subnet) {
            [$network, $length] = explode('/', $subnet);
            $prefix = @inet_pton($network);
            $bytes = intdiv((int) $length, 8);
            $bits = (int) $length % 8;

            if ($prefix === false || strlen($prefix) !== 16 || strncmp($prefix, $binary, $bytes) !== 0) {
                continue;
            }

            if ($bits === 0 || ((ord($prefix[$bytes]) ^ ord($binary[$bytes])) & (0xFF << (8 - $bits)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The firewall tab: the plan in use and the account's plans.
     */
    private function firewall(Service $service, ClientContext $context): PanelResult
    {
        $this->requireExclusive($service);
        $answer = $this->endUserCall($service, $context, 'firewallplan');
        $uid = AdminApi::userId($service->module_data['uid'] ?? null);

        return PanelResult::ok('', [
            'current' => $answer['current'],
            'plans' => array_map(fn (array $plan): array => [
                'id' => $plan['id'],
                'name' => $plan['name'],
                'default_policy' => $plan['default_policy'],
                'rules' => $plan['rules'],
                'own' => $uid !== null && $plan['uid'] === $uid,
            ], $answer['plans']),
            'can' => ['apply' => true, 'create' => true, 'delete' => true],
        ]);
    }

    /**
     * Use one of the account's firewall plans for the VPS, or none (0).
     */
    private function applyFirewall(Service $service, string $planId, ClientContext $context): PanelResult
    {
        $this->requireExclusive($service);
        $plans = $this->endUserCall($service, $context, 'firewallplan')['plans'];

        if ($planId !== '0' && ! in_array($planId, array_column($plans, 'id'), true)) {
            return PanelResult::invalid(['plan_id' => [__('Choose a firewall plan from the list.')]]);
        }

        $answer = $this->endUserCall($service, $context, 'firewallplan', [], ['changefirewallplan' => 1, 'change_fwid' => $planId], 20);

        return $answer['done']
            ? PanelResult::ok(__('The firewall plan is saved.'), logDetail: $planId === '0' ? 'no plan' : "plan #{$planId}")
            : PanelResult::fail(__('Virtualizor did not confirm the action.'));
    }

    /**
     * A new firewall plan in the account. Ports and sources are checked here as well.
     *
     * @param  array<int|string, mixed>  $rules
     */
    private function createFirewall(Service $service, string $name, string $policy, array $rules, ClientContext $context): PanelResult
    {
        $this->requireExclusive($service);
        $errors = [];
        $clean = [];

        foreach (array_values($rules) as $index => $rule) {
            $rule = is_array($rule) ? $rule : [];
            $protocol = (string) ($rule['protocol'] ?? 'all');
            $port = trim((string) ($rule['port'] ?? ''));
            $source = trim((string) ($rule['source'] ?? ''));

            if ($port !== '' && in_array($protocol, ['icmp', 'all'], true)) {
                $errors["rules.{$index}.port"] = [__('ICMP and "All" have no port. Leave it empty.')];
            } elseif ($port !== '' && ! self::validPort($port)) {
                $errors["rules.{$index}.port"] = [__('Use a port from 1 to 65535, or a range such as 8000:8100.')];
            }

            if ($source !== '' && ! self::validSource($source)) {
                $errors["rules.{$index}.source"] = [__('Use an IP address or a network such as 203.0.113.0/24.')];
            }

            $clean[] = [
                'direction' => ($rule['direction'] ?? 'in') === 'out' ? 'out' : 'in',
                'protocol' => $protocol,
                'port' => $port,
                'source' => $source,
                'action' => ($rule['action'] ?? 'accept') === 'drop' ? 'drop' : 'accept',
            ];
        }

        if ($errors !== []) {
            return PanelResult::invalid($errors);
        }

        $answer = $this->endUserCall($service, $context, 'addfirewallplan', [], [
            'addfirewallplan' => 1,
            'fwp_name' => $name,
            'default_policy' => $policy === 'drop' ? 'drop' : 'accept',
            'api_firewall_rules' => (string) json_encode($clean),
        ], 20);

        return $answer['done']
            ? PanelResult::ok(__('The firewall plan is saved. Choose it above to use it.'), logDetail: "plan \"{$name}\"")
            : PanelResult::fail(__('Virtualizor did not confirm the action.'));
    }

    /**
     * Delete one of the account's own firewall plans.
     */
    private function deleteFirewall(Service $service, string $planId, ClientContext $context): PanelResult
    {
        $this->requireExclusive($service);
        $uid = AdminApi::userId($service->module_data['uid'] ?? null);
        $plan = collect($this->endUserCall($service, $context, 'firewallplan')['plans'])->firstWhere('id', $planId);

        if ($plan === null || $uid === null || $plan['uid'] !== $uid) {
            return PanelResult::invalid(['plan_id' => [__('You can only delete your own firewall plans.')]]);
        }

        $answer = $this->endUserCall($service, $context, 'firewallplan', [], ['delete_fwids' => $planId], 20);

        return $answer['done']
            ? PanelResult::ok(__('The firewall plan is deleted.'), logDetail: "plan #{$planId}")
            : PanelResult::fail(__('Virtualizor did not confirm the action.'));
    }

    private static function validPort(string $port): bool
    {
        $parts = explode(':', $port);
        $low = (int) $parts[0];
        $high = (int) ($parts[1] ?? $parts[0]);

        return $low >= 1 && $high <= 65535 && $low <= $high;
    }

    /**
     * An IP address, or a network in CIDR form (IPv4 or IPv6).
     */
    private static function validSource(string $source): bool
    {
        [$address, $length] = array_pad(explode('/', $source, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($length === null) {
            return true;
        }

        $max = str_contains((string) $address, ':') ? 128 : 32;

        return ctype_digit($length) && (int) $length <= $max;
    }

    /**
     * The backups tab: the VPS's backups and the plan's limits.
     */
    private function backups(Service $service, ClientContext $context): PanelResult
    {
        $answer = $this->endUserCall($service, $context, 'backup2');
        $used = count($answer['items']);

        return PanelResult::ok('', array_filter([
            'items' => array_map(fn (array $item): array => $item + ['kind' => __('Backup'), 'status' => null], $answer['items']),
            'used' => $used,
            'limit' => $answer['limit'],
            'restores_used' => $answer['restores_used'],
            'restores_limit' => $answer['restores_limit'],
            'credential_mode' => 'none',
            'can' => ['create' => $answer['limit'] === null || $used < $answer['limit'], 'restore' => true, 'delete' => true],
        ], fn (mixed $value): bool => $value !== null));
    }

    private function createBackup(Service $service, ClientContext $context): PanelResult
    {
        $list = $this->endUserCall($service, $context, 'backup2');

        if ($list['limit'] !== null && count($list['items']) >= $list['limit']) {
            return PanelResult::fail(__('You have the most backups your plan allows. Delete one first.'));
        }

        $answer = $this->endUserCall($service, $context, 'backup2', [], ['cbackup' => 1], 20);

        return $answer['done']
            ? PanelResult::ok(__('The backup has started. It shows in the list when it is done.'))
            : PanelResult::fail(__('Virtualizor did not confirm the action.'));
    }

    /**
     * Restore or delete one of the VPS's backups, found again in a fresh list.
     */
    private function backupAction(Service $service, string $id, string $action, ClientContext $context): PanelResult
    {
        $items = $this->endUserCall($service, $context, 'backup2')['items'];

        if (! in_array($id, array_column($items, 'id'), true)) {
            return PanelResult::invalid(['id' => [__('Choose a backup from the list.')]]);
        }

        $answer = $this->endUserCall($service, $context, 'backup2', [], ['bkid' => $id, $action => 1], 20);

        if (! $answer['done']) {
            return PanelResult::fail(__('Virtualizor did not confirm the action.'));
        }

        return $action === 'restore'
            ? PanelResult::ok(__('The restore has started. The server comes back with the data of the backup.'), logDetail: "backup {$id}")
            : PanelResult::ok(__('The backup is deleted.'), logDetail: "backup {$id}");
    }

    /**
     * The rescue tab: whether rescue mode is on, and why it cannot be turned on now.
     */
    private function rescue(Service $service, ClientContext $context): PanelResult
    {
        $answer = $this->endUserCall($service, $context, 'rescue');

        return PanelResult::ok('', [
            'enabled' => $answer['enabled'],
            'blocked_reason' => $answer['enabled'] ? null : $answer['blocked'],
            'credential_mode' => 'set',
            'can' => ['enable' => ! $answer['enabled'] && $answer['blocked'] === null, 'disable' => $answer['enabled']],
        ]);
    }

    private function enableRescue(Service $service, string $password, bool $reboot, ClientContext $context): PanelResult
    {
        $state = $this->endUserCall($service, $context, 'rescue');

        if ($state['enabled']) {
            return PanelResult::fail(__('Rescue mode is already on.'));
        }

        if ($state['blocked'] !== null) {
            return PanelResult::fail($state['blocked']);
        }

        $answer = $this->endUserCall($service, $context, 'rescue', [], ['enablerescue' => 1, 'password' => $password, 'conf_password' => $password], 30);

        if (! $answer['done']) {
            return PanelResult::fail(__('Virtualizor did not confirm the action.'));
        }

        Cache::forget($this->manageKey($service, AdminApi::vpsId($service)));

        return $this->afterRescue($service, $reboot, __('Rescue mode is on.'));
    }

    private function disableRescue(Service $service, bool $reboot, ClientContext $context): PanelResult
    {
        $answer = $this->endUserCall($service, $context, 'rescue', [], ['disablerescue' => 1], 30);

        if (! $answer['done']) {
            return PanelResult::fail(__('Virtualizor did not confirm the action.'));
        }

        Cache::forget($this->manageKey($service, AdminApi::vpsId($service)));

        return $this->afterRescue($service, $reboot, __('Rescue mode is off.'));
    }

    /**
     * Restart the VPS after a rescue change when the client asked for it.
     */
    private function afterRescue(Service $service, bool $reboot, string $message): PanelResult
    {
        if (! $reboot) {
            return PanelResult::ok($message.' '.__('Restart the server for this to work.'));
        }

        $restart = $this->power($service, 'restart');

        return $restart->success
            ? PanelResult::ok($message.' '.__('The VPS is restarting.'), pending: 'restart', state: is_string($restart->data['state'] ?? null) ? $restart->data['state'] : null)
            : PanelResult::ok($message.' '.__('The restart did not start. Restart the server yourself.'));
    }

    /**
     * The SSH keys tab: the keys of the client's Virtualizor account.
     */
    private function sshKeys(Service $service, ClientContext $context): PanelResult
    {
        $this->requireExclusive($service);
        $keys = $this->endUserCall($service, $context, 'sshkeys')['keys'];

        return PanelResult::ok('', [
            'keys' => $keys,
            'applies_on_boot' => true,
            'can' => ['add' => count($keys) < self::SSH_KEY_LIMIT, 'delete' => true, 'apply' => $keys !== []],
        ]);
    }

    private function addSshKey(Service $service, string $name, string $key, ClientContext $context): PanelResult
    {
        $this->requireExclusive($service);
        $problem = self::sshKeyProblem($key);

        if ($problem !== null) {
            return PanelResult::invalid(['key' => [$problem]]);
        }

        if (count($this->endUserCall($service, $context, 'sshkeys')['keys']) >= self::SSH_KEY_LIMIT) {
            return PanelResult::fail(__('Your account has the most SSH keys allowed. Delete one first.'));
        }

        [$type, $encoded] = preg_split('/\s+/', trim($key)) ?: ['', ''];
        $answer = $this->endUserCall($service, $context, 'addsshkey', [], ['addsshkey' => 1, 'name' => $name, 'value' => $type.' '.$encoded], 20);

        return $answer['done']
            ? PanelResult::ok(__('The SSH key is added to your account.'), logDetail: "key \"{$name}\"")
            : PanelResult::fail(__('Virtualizor did not confirm the action.'));
    }

    private function deleteSshKey(Service $service, string $id, ClientContext $context): PanelResult
    {
        $this->requireExclusive($service);

        if (! in_array($id, array_column($this->endUserCall($service, $context, 'sshkeys')['keys'], 'id'), true)) {
            return PanelResult::invalid(['id' => [__('Choose an SSH key from the list.')]]);
        }

        $answer = $this->endUserCall($service, $context, 'sshkeys', [], ['delete' => $id], 20);

        return $answer['done']
            ? PanelResult::ok(__('The SSH key is deleted.'), logDetail: "key #{$id}")
            : PanelResult::fail(__('Virtualizor did not confirm the action.'));
    }

    /**
     * Put some of the account's SSH keys on this VPS.
     *
     * @param  array<int|string, mixed>  $ids
     */
    private function applySshKeys(Service $service, array $ids, ClientContext $context): PanelResult
    {
        $this->requireExclusive($service);
        $ids = array_values(array_unique(array_map('strval', array_filter($ids, 'is_scalar'))));
        $listed = array_column($this->endUserCall($service, $context, 'sshkeys')['keys'], 'id');

        if ($ids === [] || array_diff($ids, $listed) !== []) {
            return PanelResult::invalid(['keys' => [__('Choose SSH keys from the list.')]]);
        }

        $answer = $this->endUserCall($service, $context, 'sshkeys', [], ['addkeyvps' => 1, 'ssh_keys' => $ids], 20);

        return $answer['done']
            ? PanelResult::ok(__('The SSH keys are added. They work after the next restart.'), logDetail: 'keys #'.implode(', #', $ids))
            : PanelResult::fail(__('Virtualizor did not confirm the action.'));
    }

    /**
     * What is wrong with an SSH public key, or null when it may be added: a known safe type, a
     * key that decodes and names that type inside, RSA of 2048 bits or more. A private key is
     * refused before it goes anywhere.
     */
    private static function sshKeyProblem(string $key): ?string
    {
        $key = trim($key);

        if (str_contains($key, 'PRIVATE KEY')) {
            return __('This is a private key. Paste the public key, the file that ends in .pub.');
        }

        $parts = preg_split('/\s+/', $key) ?: [];

        if (count($parts) < 2) {
            return __('Paste the whole line of the public key, such as ssh-ed25519 AAAA… name.');
        }

        [$type, $encoded] = $parts;

        if ($type === 'ssh-dss') {
            return __('DSA keys are not safe anymore. Use an ed25519 key.');
        }

        if (! in_array($type, self::SSH_KEY_TYPES, true)) {
            return __('Use an ed25519, ECDSA or RSA key.');
        }

        $invalid = __('This key is not valid. Paste the whole line from the .pub file.');
        $blob = base64_decode($encoded, true);
        $fields = is_string($blob) && $blob !== '' ? self::sshFields($blob) : null;

        if ($fields === null || ($fields[0] ?? null) !== $type) {
            return $invalid;
        }

        if ($type === 'ssh-rsa') {
            $modulus = ltrim($fields[2] ?? '', "\0");
            $bits = $modulus === '' ? 0 : (strlen($modulus) - 1) * 8 + strlen(decbin(ord($modulus[0])));

            return $bits >= 2048 ? null : __('RSA keys need 2048 bits or more. Use an ed25519 key.');
        }

        if (str_starts_with($type, 'ecdsa-sha2-')) {
            return ($fields[1] ?? null) === substr($type, 11) ? null : $invalid;
        }

        return strlen($fields[1] ?? '') === 32 ? null : $invalid;
    }

    /**
     * The length-prefixed fields of an SSH public key, or null when it does not decode cleanly.
     *
     * @return list<string>|null
     */
    private static function sshFields(string $blob): ?array
    {
        $fields = [];
        $offset = 0;
        $length = strlen($blob);

        while ($offset < $length) {
            if ($offset + 4 > $length || count($fields) >= 8) {
                return null;
            }

            $size = unpack('N', substr($blob, $offset, 4))[1];
            $offset += 4;

            if ($size > $length - $offset) {
                return null;
            }

            $fields[] = substr($blob, $offset, $size);
            $offset += $size;
        }

        return $fields;
    }

    /**
     * The ISO and boot tab: the ISO images, the one mounted now and the boot order.
     */
    private function media(Service $service, ClientContext $context): PanelResult
    {
        $answer = $this->endUserCall($service, $context, 'hvmsettings');
        $boot = array_search($answer['boot'], self::BOOT_ORDERS, true);

        return PanelResult::ok('', [
            'isos' => $answer['isos'],
            'mounted' => $answer['iso'],
            'boot' => is_string($boot) ? $boot : null,
            'restart_needed' => Cache::has($this->restartKey($service)),
            'can' => ['mount' => true, 'boot' => is_string($boot)],
        ]);
    }

    /**
     * Mount or eject an ISO image, or change the boot order. Virtualizor's settings page is sent
     * back whole with only that value changed, so nothing else on it is reset; when a setting is
     * missing from the page, nothing is sent.
     *
     * @param  array{iso?: string, order?: string}  $change
     */
    private function changeMedia(Service $service, array $change, ClientContext $context): PanelResult
    {
        $page = $this->endUserCall($service, $context, 'hvmsettings');

        if (array_diff(EnduserApi::HVM_FIELDS, array_keys($page['settings'])) !== []) {
            return PanelResult::fail(__('This change cannot be made here. Contact support.'), 'Virtualizor\'s VPS settings page did not list every setting, so a change could reset the others.');
        }

        $post = ['hvmsettings' => 1] + $page['settings'];
        $iso = $page['iso'] ?? '0';
        $boot = $page['boot'];

        if (array_key_exists('iso', $change)) {
            if ($change['iso'] !== '' && ! in_array($change['iso'], array_column($page['isos'], 'id'), true)) {
                return PanelResult::invalid(['iso' => [__('Choose an ISO image from the list.')]]);
            }

            $iso = $change['iso'] !== '' ? $change['iso'] : '0';
        }

        if (array_key_exists('order', $change)) {
            $boot = self::BOOT_ORDERS[$change['order']] ?? null;

            if ($boot === null || ! in_array($page['boot'], self::BOOT_ORDERS, true)) {
                return PanelResult::fail(__('The boot order cannot be changed for this server.'));
            }
        }

        $post['isos'] = $iso;

        if ($boot !== null) {
            $post['boot_ord_val'] = $boot;
        }

        $answer = $this->endUserCall($service, $context, 'hvmsettings', [], $post, 20);

        if (! $answer['done']) {
            return PanelResult::fail(__('Virtualizor did not confirm the action.'));
        }

        Cache::put($this->restartKey($service), true, 86400);

        $message = array_key_exists('order', $change)
            ? __('The boot order is saved.')
            : ($iso === '0' ? __('The ISO image is ejected.') : __('The ISO image is mounted.'));

        return PanelResult::ok($message.' '.__('Restart the server for this to work.'), logDetail: array_key_exists('order', $change) ? 'boot '.$change['order'] : ($iso === '0' ? 'eject' : "ISO {$iso}"));
    }

    private function restartKey(Service $service): string
    {
        return 'nuvabill:vz:media-restart:'.$service->id;
    }

    /**
     * The tasks and logs tab: the VPS's recent tasks (unless Virtualizor hides them) and, for a
     * Virtualizor user that holds only this client's VPS, the account's log lines.
     */
    private function activity(Service $service, int $page, ClientContext $context): PanelResult
    {
        $manage = $this->manage($service, $context);
        $data = ['tasks' => [], 'running' => false];

        if (! self::flag($manage, 'hide_eu_tasks')) {
            $tasks = $this->endUserCall($service, $context, 'ctasks', ['page' => max(1, $page)])['tasks'];
            $data['tasks'] = array_map(fn (array $task): array => [
                'id' => $task['id'],
                'action' => $task['action'],
                'status' => $task['status'],
                'progress' => $task['progress'],
                'started_at' => $task['started_at'],
                'ended_at' => $task['ended_at'],
            ], $tasks);
            $data['running'] = collect($tasks)->contains(fn (array $task): bool => $task['ended_at'] === null && $task['progress'] !== null && $task['progress'] < 100);
        }

        if (! self::flag($manage, 'disable_logs') && $this->signIn->exclusive($service)) {
            $data['logs'] = $this->endUserCall($service, $context, 'logs')['logs'];
        }

        return PanelResult::ok('', $data);
    }

    /**
     * The settings tab: the hostname now, and which changes the client may make.
     */
    private function settings(Service $service, ClientContext $context): PanelResult
    {
        $facts = $this->facts($service, AdminApi::vpsId($service));
        $manage = $this->manage($service, $context);
        $hvm = in_array((string) self::setting($service, 'virt', 'kvm'), self::HVM, true);

        return PanelResult::ok('', [
            'hostname' => $facts['hostname'] ?? (string) $service->domain,
            'credential_mode' => 'set',
            'can' => [
                'hostname' => ! self::flag($manage, 'disable_change_hostname'),
                'root-password' => ! self::flag($manage, 'disable_change_password'),
                'vnc-password' => $hvm && $manage !== null && $manage['vps']['vnc'] !== false && ! self::flag($manage, 'disable_change_vnc_password'),
            ],
        ]);
    }

    private function changeHostnameIfAllowed(Service $service, string $hostname, ClientContext $context): PanelResult
    {
        if (self::flag($this->manage($service, $context), 'disable_change_hostname')) {
            return PanelResult::unavailable(__('Changing the hostname is turned off for this server.'));
        }

        return $this->result($this->changeHostname($service, $hostname));
    }

    private function changePasswordIfAllowed(Service $service, string $password, ClientContext $context): PanelResult
    {
        if (self::flag($this->manage($service, $context), 'disable_change_password')) {
            return PanelResult::unavailable(__('Changing the root password is turned off for this server.'));
        }

        return $this->result($this->changePassword($service, $password));
    }

    /**
     * A new VNC password for the console, on the end-user panel. It may only work after a restart.
     */
    private function changeVncPassword(Service $service, string $password, ClientContext $context): PanelResult
    {
        $manage = $this->manage($service, $context);

        if ($manage === null || $manage['vps']['vnc'] === false || self::flag($manage, 'disable_change_vnc_password')) {
            return PanelResult::unavailable(__('Changing the console password is not available for this server.'));
        }

        $answer = $this->endUserCall($service, $context, 'vncpass', [], ['vncpass' => 1, 'newpass' => $password, 'conf' => $password], 20);

        if (! $answer['done'] && ! $answer['onboot']) {
            return PanelResult::fail(__('Virtualizor did not confirm the action.'));
        }

        return PanelResult::ok($answer['onboot'] ? __('Console password changed. It works after the next restart.') : __('Console password changed.'));
    }

    /**
     * Start, stop, restart or power off, as a panel answer. A start or restart also ends the
     * "restart needed" note of the ISO tab.
     */
    private function powerResult(Service $service, string $action): PanelResult
    {
        $result = $this->power($service, $action);

        if ($result->success && in_array($action, ['start', 'restart'], true)) {
            Cache::forget($this->restartKey($service));
        }

        return $this->result($result);
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
     * The details for a VNC app, shown once. Virtualizor can hide them from clients for a VPS; the
     * browser console still works then.
     */
    private function vncDetails(Service $service, ClientContext $context): PanelResult
    {
        try {
            $hidden = self::flag($this->manage($service, $context), 'hide_enduser_vnc_info');
        } catch (PanelException $exception) {
            return $exception->toResult();
        }

        if ($hidden) {
            return PanelResult::unavailable(__('VNC details are not shown for this server. Use the console instead.'));
        }

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
