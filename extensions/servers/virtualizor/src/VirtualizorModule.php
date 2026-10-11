<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Contracts\ChecksClientAccess;
use App\Contracts\HasClientPanel;
use App\Contracts\HasConsole;
use App\Contracts\HasPanelSections;
use App\Contracts\HasPanelState;
use App\Contracts\HasResourceOptions;
use App\Contracts\KeepsCreateServer;
use App\Contracts\LinksExistingAccount;
use App\Contracts\SignsInWithContext;
use App\Contracts\WaitsForPayment;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\ConsoleSession;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Resources\ResourceCheck;
use App\Extensions\Servers\Resources\ResourceExtras;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Virtualizor VPS. Staff tasks (create, suspend, remove, plan changes) use the Admin API. Clients
 * manage their VPS in the tabbed server panel of the client area, open its console in the
 * browser, and can open their own VPS in Virtualizor's end-user panel with one click.
 *
 * The work is split over helpers: AdminApi (the Admin API), EnduserApi (the end-user panel),
 * Panel (the client server panel and console), SignIn (sign-in and the staff access tools) and
 * ResourceTotals (extra resources). This class keeps the module contracts and the safe create.
 */
class VirtualizorModule extends Module implements ChecksClientAccess, HasClientPanel, HasConsole, HasPanelSections, HasPanelState, HasResourceOptions, KeepsCreateServer, LinksExistingAccount, SignsInWithContext, WaitsForPayment
{
    /**
     * Control panels Virtualizor installs on a new VPS, by Virtualizor's own names.
     */
    private const CONTROL_PANELS = [
        'cpanel' => 'cPanel & WHM',
        'plesk' => 'Plesk',
        'webuzo' => 'Webuzo',
        'webmin' => 'Webmin',
        'inteworx' => 'InterWorx',
        'ispconfig' => 'ISPConfig',
        'cwp' => 'CentOS Web Panel',
        'vesta' => 'Vesta',
    ];

    /**
     * Product settings sent as whole numbers with a new VPS, with their smallest value and their label.
     */
    private const NUMBER_OPTIONS = [
        'num_ips6' => [0, 'IPv6 addresses'],
        'num_ips6_subnet' => [0, 'IPv6 subnets'],
        'network_speed' => [0, 'Network speed'],
        'upload_speed' => [0, 'Upload speed'],
        'osreinstall_limit' => [0, 'OS reinstalls a month'],
        'server_group' => [0, 'Virtualizor server group ID'],
        'slave_server' => [0, 'Virtualizor server ID'],
    ];

    private ?AdminApi $adminApi = null;

    private ?EnduserApi $enduserApi = null;

    private ?SignIn $signInHelper = null;

    private ?Panel $panelHelper = null;

    private ?ResourceTotals $resourceTotals = null;

    public function productFields(): array
    {
        $panels = array_map(fn (string $name): string => $name.' (license not included)', self::CONTROL_PANELS);

        return [
            'virt' => [
                'label' => 'Virtualization',
                'type' => 'select',
                'options' => ['kvm' => 'KVM', 'xen' => 'Xen', 'xenhvm' => 'Xen HVM', 'openvz' => 'OpenVZ', 'lxc' => 'LXC', 'proxk' => 'Proxmox KVM', 'proxl' => 'Proxmox LXC'],
                'required' => true,
            ],
            'plan_id' => [
                'label' => 'Virtualizor plan ID',
                'type' => 'text',
                'help' => 'Plans → List plans in Virtualizor. The plan sets RAM, disk, CPU and bandwidth.',
            ],
            'os_id' => [
                'label' => 'Operating system ID',
                'type' => 'text',
                'required' => true,
                'help' => 'Media → OS templates in Virtualizor, for example the ID of Ubuntu 24.04.',
            ],
            'num_ips' => ['label' => 'IPv4 addresses', 'type' => 'text', 'help' => 'Default 1.'],
            'ram' => ['label' => 'RAM (MB)', 'type' => 'text', 'help' => 'Only needed without a plan.'],
            'disk' => ['label' => 'Disk (GB)', 'type' => 'text', 'help' => 'Only needed without a plan.'],
            'cores' => ['label' => 'CPU cores', 'type' => 'text', 'help' => 'Only needed without a plan.'],
            'bandwidth' => ['label' => 'Bandwidth (GB, 0 = unlimited)', 'type' => 'text', 'help' => 'Only needed without a plan.'],
            'recipe_id' => [
                'label' => 'Recipe ID',
                'type' => 'text',
                'help' => 'Optional. A Virtualizor recipe (Recipes → List recipes) that runs once on a new VPS. Clients may run it again when they reinstall.',
            ],
            'control_panel' => [
                'label' => 'Control panel to install',
                'type' => 'select',
                'options' => ['' => 'None'] + $panels,
                'help' => 'Optional. Virtualizor installs it on a new VPS. The license is not included: buy it separately. The order needs a real hostname such as server.example.com, and the product no recipe.',
            ],
            'num_ips6' => ['label' => 'IPv6 addresses', 'type' => 'text', 'help' => 'Optional. Empty gives none.'],
            'num_ips6_subnet' => ['label' => 'IPv6 subnets', 'type' => 'text', 'help' => 'Optional. Empty gives none.'],
            'network_speed' => ['label' => 'Network speed (KB/s)', 'type' => 'text', 'help' => 'Optional. 0 = no limit. Empty uses the plan.'],
            'upload_speed' => ['label' => 'Upload speed (KB/s)', 'type' => 'text', 'help' => 'Optional. 0 = no limit. Empty uses the plan.'],
            'server_group' => ['label' => 'Virtualizor server group ID', 'type' => 'text', 'help' => 'Optional. New VPS go to a server in this group.'],
            'slave_server' => ['label' => 'Virtualizor server ID', 'type' => 'text', 'help' => 'Optional. New VPS go to this server (0 = the master). It wins over the server group.'],
            'osreinstall_limit' => ['label' => 'OS reinstalls a month', 'type' => 'text', 'help' => 'Optional. 0 = no limit.'],
            'reinstall_os_ids' => [
                'label' => 'OS templates clients may choose',
                'type' => 'text',
                'help' => 'Optional. Comma-separated OS IDs. Empty allows every template of this virtualization type.',
            ],
            'ipv4_pool_id' => [
                'label' => 'IP pool for extra IPv4',
                'type' => 'text',
                'help' => 'Optional. Extra IPv4 addresses must come from this Virtualizor IP pool. They are checked after each change.',
            ],
            'client_login' => [
                'label' => 'Client panel sign-in',
                'type' => 'select',
                'options' => ['' => 'Off', '1' => 'On'],
                'help' => 'Clients get an "Open Virtualizor panel" button that signs them in to their own VPS. It needs: "Connect with HTTPS" on for the server, a valid SSL certificate on port 4083, and port 4083 open. Each client should have their own Virtualizor user. First turn off panel features that skip billing in Virtualizor (Configuration → Enduser settings), such as creating VPS or changing resources. To find problems, open a service in Admin and use Test panel sign-in.',
            ],
            'panel_port' => ['label' => 'Client panel port', 'type' => 'text', 'help' => 'Default 4083, Virtualizor\'s end-user port. Never the admin port (4085) or the server\'s API port: sign-in stays off with those. It needs a valid SSL certificate.'],
        ];
    }

    public function serverHelp(): string
    {
        return 'Hostname: the Virtualizor master, as a name with a valid SSL certificate (for example vz.example.com). Put the Admin API key in "API token" and the API password in "Password" (Virtualizor → Configuration → Server Info), and allow this server\'s IP there. Keep "Connect with HTTPS" on: without it the API key and password travel unencrypted. Nuvabill uses port 4085 for staff tasks and port 4083 for the client panel, console and sign-in. Clients\' browsers also connect to port 4083, so it must be open and have a valid certificate. Behind Cloudflare, set this hostname to "DNS only". If you use a key from API Credentials, allow these acts: vs, addvs, managevps, rebuild, vnc, users, plans, ostemplates, ips, sso, listvs, and the end-user acts of the panel.';
    }

    public function defaultPort(): int
    {
        return AdminApi::DEFAULT_PORT;
    }

    /**
     * Off: the server panel shows its own "Open Virtualizor panel" button for products with client
     * panel sign-in on, so the page has no second, general button.
     */
    public function hasLoginLink(): bool
    {
        return false;
    }

    /**
     * The Admin API answers, plus a line about the end-user port when there is one to add.
     */
    public function testConnection(Server $server): ModuleResult
    {
        return $this->attempt(function () use ($server): ModuleResult {
            $response = $this->admin()->call($server, ['act' => 'vs', 'reslen' => 1]);
            $message = __('Connected to Virtualizor. :count VPS found.', ['count' => (int) ($response['counts']['vs'] ?? count((array) ($response['vs'] ?? [])))]);
            $note = $this->enduser()->connectionNote($server);

            return ModuleResult::ok($note !== null && $note !== '' ? $message.' '.$note : $message);
        });
    }

    /**
     * Make the VPS: the create guard (SignIn), then the totals with any extras (ResourceTotals),
     * then the safe create. A create with extras is noted before it is sent, so a request without
     * a clear answer is never sent twice.
     */
    public function create(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            // Only this try decides whether the VPS waits for its payment (see waitsForPayment).
            ResourceAddons::stopWaiting($service);
            $record = ResourceAddons::record($service);

            $refusal = $this->signIn()->guardCreate($service, $record);

            if ($refusal !== null) {
                return $refusal;
            }

            // The VPS of an earlier create request is only looked for on the server it was sent to.
            if ($record !== null && $record['server_id'] !== null && $record['server_id'] !== (int) $service->server_id) {
                return ModuleResult::fail(__('The create request for this VPS was sent to server #:id, so a VPS it made is only looked for there. Move the service back to that server and try again. No VPS is made on another server.', ['id' => $record['server_id']]));
            }

            // A create with extras was sent before: never send another one for this service.
            if ($record !== null && $record['state'] === ResourceAddons::REQUESTED) {
                $record = $this->reconcile($service, $record);

                // The service stayed on this server only to look for that VPS (see keepsServer).
                if ($record === null && ! $service->server->is_active) {
                    return ModuleResult::fail(__('Virtualizor shows the earlier create request made no VPS, and this server is turned off. Try again: the next try makes the VPS on a server that is on.'));
                }
            }

            if ($record !== null && $record['vpsid'] !== null) {
                return $this->verifyResources($service, $record);
            }

            $options = $this->createOptions($service);
            $hostname = isset($options['control_panel']) ? $this->panelHostname($service, $options['control_panel']) : $this->hostnameFor($service);
            $rootPassword = $this->makePassword();
            $disk = (int) $this->productSetting($service, 'disk', 0);

            $post = array_filter([
                'addvps' => 1,
                'virt' => (string) $this->productSetting($service, 'virt', 'kvm'),
                'user_email' => $service->client->email,
                'user_pass' => $this->makePassword(),
                'hostname' => $hostname,
                'rootpass' => $rootPassword,
                'osid' => (int) $this->productSetting($service, 'os_id'),
                'plid' => (int) $this->productSetting($service, 'plan_id', 0) ?: null,
                'num_ips' => (int) $this->productSetting($service, 'num_ips', 1) ?: 1,
                'ram' => (int) $this->productSetting($service, 'ram', 0) ?: null,
                'cores' => (int) $this->productSetting($service, 'cores', 0) ?: null,
                'bandwidth' => $this->productSetting($service, 'bandwidth') !== null ? (int) $this->productSetting($service, 'bandwidth') : null,
                'space' => $disk > 0 ? [['size' => $disk]] : null,
                'node_select' => 1,
            ], fn (mixed $value): bool => $value !== null) + $options;

            // A chosen Virtualizor server replaces the automatic choice.
            if (isset($options['slave_server'])) {
                unset($post['node_select']);
            }

            try {
                $resources = $this->totals()->totalsForCreate($service);
            } catch (RuntimeException $exception) {
                if ($exception->getCode() === ResourceAddons::UNPAID) {
                    ResourceAddons::waitForPayment($service);
                }

                throw $exception;
            }

            if ($resources !== null) {
                return $this->createWithResources($service, $post, $resources, $hostname, $rootPassword);
            }

            $response = $this->admin()->call($service->server, ['act' => 'addvs'], $post, timeout: 180);
            $vpsId = $response['vs_info']['vpsid'] ?? $response['newvs']['vpsid'] ?? null;

            if (blank($vpsId)) {
                return ModuleResult::fail(__('Virtualizor did not return the new VPS ID.'));
            }

            return ModuleResult::ok(__('VPS #:id created.', ['id' => $vpsId]), [
                'username' => 'root',
                'password' => $rootPassword,
                'module_data' => array_filter([
                    'vpsid' => (string) $vpsId,
                    'ips' => array_values((array) ($response['vs_info']['ips'] ?? [])),
                    'uid' => AdminApi::userId($response['vs_info']['uid'] ?? null),
                ], fn (mixed $value): bool => $value !== null),
            ]);
        });
    }

    /**
     * A create with extras that was sent stays on its server: the VPS it may have made is looked
     * for there, also when that server was turned off or is full since.
     */
    public function keepsServer(Service $service): bool
    {
        return ResourceAddons::record($service) !== null;
    }

    /**
     * Only a VPS whose last create was refused because its order invoice with the add-on extras
     * was not paid yet (the flag from before 2.0). A create that was sent, also one without a
     * clear answer, is never tried again by itself: it may have made a VPS.
     */
    public function waitsForPayment(Service $service): bool
    {
        return ResourceAddons::waitsForPayment($service);
    }

    public function suspend(Service $service, string $reason): ModuleResult
    {
        return $this->simple($service, ['act' => 'vs', 'suspend' => $this->vpsId($service)], ['suspend_reason' => $reason], __('VPS suspended.'));
    }

    public function unsuspend(Service $service): ModuleResult
    {
        return $this->simple($service, ['act' => 'vs', 'unsuspend' => $this->vpsId($service)], [], __('VPS unsuspended.'));
    }

    public function terminate(Service $service): ModuleResult
    {
        return $this->simple($service, ['act' => 'vs', 'delete' => $this->vpsId($service)], [], __('VPS deleted.'));
    }

    /**
     * Applies the product's plan. A VPS with extras then gets them back on top of the new plan
     * (ResourceTotals), and the result is checked.
     */
    public function changePackage(Service $service): ModuleResult
    {
        $planId = (int) $this->productSetting($service, 'plan_id', 0);

        if ($planId === 0) {
            return ModuleResult::fail(__('Set a Virtualizor plan ID on the product first.'));
        }

        return $this->attempt(function () use ($service, $planId): ModuleResult {
            $totals = $this->totals()->totalsForPlanChange($service);

            if ($totals === null) {
                return $this->simple($service, ['act' => 'managevps', 'vpsid' => $this->vpsId($service)], ['editvps' => 1, 'plid' => $planId, 'apply_plan' => 1], __('Plan applied to the VPS.'));
            }

            return $this->totals()->changePlan($service, $planId, $totals);
        });
    }

    /**
     * The sign-in link without a client context. Core asks signInUrl() for the client's button.
     */
    public function loginUrl(Service $service): ?string
    {
        return $this->signIn()->signInUrl($service, null);
    }

    public function signInUrl(Service $service, ClientContext $context): ?string
    {
        return $this->signIn()->signInUrl($service, $context);
    }

    public function checkClientAccess(Service $service): array
    {
        return $this->signIn()->checks($service);
    }

    public function linkFields(): array
    {
        return $this->signIn()->linkFields();
    }

    public function linkExistingAccount(Service $service, array $input): ModuleResult
    {
        return $this->signIn()->link($service, $input);
    }

    public function panelSections(Product $product): array
    {
        return $this->panel()->sections($product);
    }

    public function availablePanelSections(Service $service, ClientContext $context): ?array
    {
        return $this->panel()->available($service, $context);
    }

    public function readPanelSection(Service $service, string $section, array $input, ClientContext $context): PanelResult
    {
        return $this->panel()->read($service, $section, $input, $context);
    }

    public function runPanelAction(Service $service, string $section, string $action, array $input, ClientContext $context): PanelResult
    {
        return $this->panel()->run($service, $section, $action, $input, $context);
    }

    public function consoleSession(Service $service, ClientContext $context): ConsoleSession
    {
        return $this->panel()->consoleSession($service, $context);
    }

    public function resourceKinds(Product $product): array
    {
        return $this->totals()->kinds($product);
    }

    public function baseResources(Product $product, ?Server $server): array
    {
        return $this->totals()->base($product, $server);
    }

    public function resourceLimits(Service $service): array
    {
        return $this->totals()->limits($service);
    }

    public function applyResources(Service $service, ResourceExtras $extras): ModuleResult
    {
        return $this->totals()->apply($service, $extras);
    }

    public function checkResources(Service $service, ResourceExtras $extras): ResourceCheck
    {
        return $this->totals()->check($service, $extras);
    }

    /**
     * The tabbed server panel. Core uses it for every module with panel sections.
     */
    public function clientPanelView(): string
    {
        return 'theme::client.services.server-panel';
    }

    /**
     * The old client panel actions. Core maps the old panel route to the panel sections, so these
     * are only for direct calls. VNC details are shown once in the console tab, never through them.
     */
    public function clientActions(): array
    {
        return [
            'start' => __('Start'),
            'stop' => __('Shut down'),
            'restart' => __('Restart'),
            'poweroff' => __('Power off'),
            'hostname' => __('Change hostname'),
            'password' => __('Change root password'),
            'reinstall' => __('Reinstall OS'),
        ];
    }

    /**
     * The first paint of the server panel: the VPS, its state and usage, and whether the client
     * gets the "Open Virtualizor panel" button. The button is worked out first and on its own, so a
     * failed status or VPS read never hides it. Only when both reads fail does the panel show that
     * the server did not answer.
     */
    public function clientPanel(Service $service): array
    {
        $server = $service->server;
        $vpsId = $this->vpsId($service);
        $failed = null;

        try {
            $info = $this->admin()->vpsInfo($server, $vpsId);
        } catch (RuntimeException $exception) {
            $info = [];
            $failed = $exception;
        }

        $login = $this->signIn()->offersSignIn($service, $info) ? __('Open Virtualizor panel') : null;

        try {
            $status = $this->admin()->vpsStatus($server, $vpsId);
        } catch (RuntimeException $exception) {
            if ($failed !== null) {
                throw $failed;
            }

            $status = [];
        }

        $state = AdminApi::state($status['status'] ?? null);

        if ($state === 'unknown' && (int) ($info['suspended'] ?? 0) === 1) {
            $state = 'suspended';
        }

        // Without live figures the panel shows no usage rather than zeros.
        $live = $status !== [];

        return [
            'hostname' => $info['hostname'] ?? $service->domain,
            'os' => $info['os_name'] ?? null,
            'ips' => AdminApi::addresses($info !== [] ? $info : ['ips' => $this->moduleValue($service, 'ips', [])]),
            'state' => $state,
            'cpu' => isset($status['used_cpu']) && is_numeric($status['used_cpu']) ? (float) $status['used_cpu'] : null,
            'ram' => $live ? ['used' => (float) ($status['used_ram'] ?? 0), 'total' => (float) ($status['ram'] ?? $info['ram'] ?? 0), 'unit' => 'MB'] : null,
            'disk' => $live ? ['used' => (float) ($status['used_disk'] ?? 0), 'total' => (float) ($status['disk'] ?? $info['space'] ?? 0), 'unit' => 'GB'] : null,
            'bandwidth' => $live ? ['used' => (float) ($status['used_bandwidth'] ?? 0), 'total' => (float) ($status['bandwidth'] ?? $info['bandwidth'] ?? 0), 'unit' => 'GB'] : null,
            'network' => isset($status['net_in']) || isset($status['net_out'])
                ? ['in' => max(0, (float) ($status['net_in'] ?? 0)), 'out' => max(0, (float) ($status['net_out'] ?? 0))]
                : null,
            'login' => $login,
        ];
    }

    /**
     * Only the state, for the panel's checks after a power action: one status call. The search for
     * the VPS runs only when the status is unclear, to see whether Virtualizor suspended it.
     */
    public function clientPanelState(Service $service): string
    {
        $vpsId = $this->vpsId($service);
        $state = AdminApi::state($this->admin()->vpsStatus($service->server, $vpsId)['status'] ?? null);

        if ($state === 'unknown' && (int) ($this->admin()->vpsInfo($service->server, $vpsId)['suspended'] ?? 0) === 1) {
            $state = 'suspended';
        }

        return $state;
    }

    public function clientAction(Service $service, string $action, array $input): ModuleResult
    {
        return $this->panel()->clientAction($service, $action, $input);
    }

    /**
     * The optional product settings for a new VPS, checked. Empty settings send nothing, so the
     * request is the same as without them.
     *
     * @return array<string, int|string>
     */
    private function createOptions(Service $service): array
    {
        $options = [];
        $recipe = $this->productNumber($service, 'recipe_id', 1, 'Recipe ID');
        $panel = trim((string) $this->productSetting($service, 'control_panel', ''));

        if ($panel !== '' && ! array_key_exists($panel, self::CONTROL_PANELS)) {
            throw new RuntimeException(__('Choose a control panel from the list on the product, or none.'));
        }

        if ($recipe !== null && $panel !== '') {
            throw new RuntimeException(__('Choose either a recipe or a control panel on the product, not both.'));
        }

        if ($recipe !== null) {
            $options['recipe'] = $recipe;
        }

        if ($panel !== '') {
            $options['control_panel'] = $panel;
        }

        foreach (self::NUMBER_OPTIONS as $key => [$min, $label]) {
            $value = $this->productNumber($service, $key, $min, $label);

            if ($value !== null) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * A whole-number product setting, null when it is empty.
     */
    private function productNumber(Service $service, string $key, int $min, string $label): ?int
    {
        $value = $this->productSetting($service, $key);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $number = is_int($value) || is_string($value) ? filter_var(trim((string) $value), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => 2147483647]]) : false;

        if ($number === false) {
            throw new RuntimeException(__('Set ":field" on the product to a whole number of :min or more, or leave it empty.', ['field' => $label, 'min' => $min]));
        }

        return $number;
    }

    /**
     * The hostname for a VPS that gets a control panel: the order's own domain, which must be a
     * real server name. cPanel has stricter rules.
     */
    private function panelHostname(Service $service, string $panel): string
    {
        $hostname = strtolower(trim((string) $service->domain));
        $labels = explode('.', $hostname);
        $valid = strlen($hostname) <= 253
            && filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/D', $hostname)
            && ! in_array(end($labels), ['localhost', 'localdomain', 'local', 'invalid', 'test', 'example'], true);

        if ($valid && $panel === 'cpanel') {
            $valid = strlen($hostname) <= 60
                && preg_match('/^[a-z]/', $hostname)
                && ! str_starts_with($hostname, 'www')
                && ! in_array($labels[0], ['cpanel', 'whm', 'webmail', 'webdisk', 'autoconfig', 'autodiscover', 'cpcalendars', 'cpcontacts'], true);
        }

        if (! $valid) {
            throw new RuntimeException($panel === 'cpanel'
                ? __('Set a valid cPanel server hostname such as server.example.com (60 characters at most) on the order. Local names, www and service names such as cpanel or webmail are not allowed.')
                : __('Set a real server hostname such as server.example.com on the order, so the control panel can be installed.'));
        }

        return $hostname;
    }

    /**
     * Create a VPS with extras. The request is noted before it is sent, so a request without a
     * clear answer is never sent again: the next try looks for the VPS it may have made.
     *
     * @param  array<string, mixed>  $post
     * @param  array{totals: array<string, int>, pool: int|null}  $resources  From ResourceTotals; everything besides the totals goes into the note.
     */
    private function createWithResources(Service $service, array $post, array $resources, string $hostname, string $rootPassword): ModuleResult
    {
        $post = $this->totals()->createFields($resources['totals']) + $post;

        // The root password stays encrypted in the note until the VPS is checked, so a pending
        // service never shows it. The email is the one Virtualizor files the VPS under.
        $record = ResourceAddons::remember($service, [
            'state' => ResourceAddons::REQUESTED,
            'vpsid' => null,
            'server_id' => (int) $service->server_id,
            'hostname' => $hostname,
            'email' => strtolower((string) $post['user_email']),
            'requested_at' => now()->getTimestamp(),
        ] + $resources, $rootPassword);

        try {
            $response = $this->admin()->call($service->server, ['act' => 'addvs'], $post, timeout: 180);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === AdminApi::REFUSED) {
                ResourceAddons::forget($service);

                return ModuleResult::fail(__('Virtualizor refused to create the VPS, so none was made. Check the plan, the OS template and free IP addresses in Virtualizor, then try again.'));
            }

            return ModuleResult::fail($this->unclearCreate($hostname));
        }

        $vpsId = $response['vs_info']['vpsid'] ?? $response['newvs']['vpsid'] ?? null;

        if (! is_scalar($vpsId) || ! ctype_digit((string) $vpsId)) {
            return ModuleResult::fail($this->unclearCreate($hostname));
        }

        $record = ResourceAddons::remember($service, ['state' => ResourceAddons::CREATED, 'vpsid' => (string) $vpsId] + $record);

        return $this->verifyResources($service, $record);
    }

    /**
     * After a create request without a clear answer: the one VPS it made, or null when Virtualizor
     * shows none was made and enough time has passed to try again. Throws while it is unclear.
     *
     * A VPS made by the request has its hostname and the email it was sent with (kept in the note,
     * as the client may change theirs). VPS that other services already use are not it.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function reconcile(Service $service, array $record): ?array
    {
        $hostname = strtolower((string) ($record['hostname'] ?? ''));

        if ($hostname === '') {
            throw new RuntimeException($this->unclearCreate(__('(unknown)')));
        }

        $reslen = 50;
        $listed = [];

        foreach ((array) ($this->admin()->call($service->server, ['act' => 'vs', 'search' => 1, 'vpshostname' => $hostname, 'page' => 1, 'reslen' => $reslen])['vs'] ?? []) as $key => $vps) {
            $vpsId = is_array($vps) ? (string) ($vps['vpsid'] ?? $key) : '';

            if (! ctype_digit($vpsId)) {
                throw new RuntimeException($this->unclearCreate($hostname));
            }

            $listed[$vpsId] = ['hostname' => strtolower(trim((string) ($vps['hostname'] ?? ''))), 'email' => strtolower(trim((string) ($vps['email'] ?? '')))];
        }

        $email = strtolower((string) ($record['email'] ?? $service->client->email));
        $free = array_diff_key($listed, array_flip($this->vpsIdsOfOtherServices($service, array_map('strval', array_keys($listed)))));
        $mine = array_filter($free, fn (array $vps): bool => $vps['email'] === $email);
        $named = array_filter($mine, fn (array $vps): bool => $vps['hostname'] === $hostname);

        if (count($named) === 1) {
            return ResourceAddons::remember($service, ['state' => ResourceAddons::CREATED, 'vpsid' => (string) array_key_first($named)] + $record);
        }

        $age = isset($record['requested_at']) ? now()->getTimestamp() - (int) $record['requested_at'] : PHP_INT_MAX;

        // Only a full list from a search that worked shows that no VPS of this client has the name:
        // every VPS in it has a user email and the name, and the list was not cut short.
        $complete = count($listed) < $reslen && array_filter($listed, fn (array $vps): bool => $vps['email'] === '' || ! str_contains($vps['hostname'], $hostname)) === [];

        // Well after the request, none was made: Virtualizor has no VPS of this client with that name.
        if ($mine === [] && $complete && $age >= 600) {
            ResourceAddons::forget($service);

            return null;
        }

        throw new RuntimeException($this->unclearCreate($hostname));
    }

    /**
     * Which of the VPS IDs other services on the same server already use.
     *
     * @param  list<string>  $vpsIds
     * @return list<string>
     */
    private function vpsIdsOfOtherServices(Service $service, array $vpsIds): array
    {
        if ($vpsIds === []) {
            return [];
        }

        $used = Service::query()
            ->whereKeyNot($service->id)
            ->where('server_id', $service->server_id)
            ->whereIn('module_data->vpsid', $vpsIds)
            ->get(['id', 'module_data'])
            ->map(fn (Service $other): string => (string) ($other->module_data['vpsid'] ?? ''))
            ->all();

        return array_values(array_intersect($vpsIds, $used));
    }

    private function unclearCreate(string $hostname): string
    {
        return __('The create request for this VPS had no clear answer, so no second VPS is made yet. Look in Virtualizor for a VPS named :hostname: when this client has exactly one, the next try takes it; when there is none, a try after 10 minutes makes a new one. If you cancel the order instead, delete that VPS in Virtualizor.', ['hostname' => $hostname]);
    }

    /**
     * Check a VPS made with extras before the service is activated: its name, resources and IP
     * addresses must match what was paid (ResourceTotals). A VPS that does not match stays pending
     * for staff.
     *
     * @param  array<string, mixed>  $record
     */
    private function verifyResources(Service $service, array $record): ModuleResult
    {
        $vpsId = (string) $record['vpsid'];

        try {
            $info = $this->admin()->vpsInfo($service->server, $vpsId);
            $problems = $this->totals()->problems($service, $record, $info);
        } catch (RuntimeException) {
            return ModuleResult::fail(__('VPS #:id was made but could not be checked yet. Try again; no second VPS is made. If you cancel the order instead, delete VPS #:id in Virtualizor.', ['id' => $vpsId]));
        }

        if ($problems !== []) {
            return ModuleResult::fail(__('VPS #:id does not match the paid add-on resources (:problems). It stays pending: fix it in Virtualizor, then try again. No second VPS is made. If you cancel the order instead, delete VPS #:id in Virtualizor.', ['id' => $vpsId, 'problems' => implode(', ', $problems)]));
        }

        $uid = AdminApi::userId($info['uid'] ?? null);
        // Notes from before 1.1.0 left the root password on the service itself.
        $password = ResourceAddons::password($service) ?? (filled($service->password) ? (string) $service->password : null);
        ResourceAddons::remember($service, ['state' => ResourceAddons::VERIFIED] + $record);

        return ModuleResult::ok(__('VPS #:id created with its add-on resources.', ['id' => $vpsId]), array_filter([
            'username' => 'root',
            'password' => $password,
            'module_data' => array_filter(['vpsid' => $vpsId, 'ips' => AdminApi::addresses($info), 'uid' => $uid], fn (mixed $value): bool => $value !== null),
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $post
     */
    private function simple(Service $service, array $query, array $post, string $message): ModuleResult
    {
        return $this->attempt(function () use ($service, $query, $post, $message): ModuleResult {
            $response = $this->admin()->call($service->server, $query, $post);

            return empty($response['done']) && empty($response['vsop']) && empty($response['vs_info'])
                ? ModuleResult::fail(__('Virtualizor did not confirm the action.'))
                : ModuleResult::ok($message);
        });
    }

    private function vpsId(Service $service): string
    {
        return AdminApi::vpsId($service);
    }

    private function hostnameFor(Service $service): string
    {
        $domain = strtolower((string) $service->domain);

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) ? $domain : 'vps'.$service->id.'.localdomain';
    }

    private function makePassword(): string
    {
        return Str::password(14, symbols: false).'a7';
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
     * The Admin API client of this module.
     */
    private function admin(): AdminApi
    {
        return $this->adminApi ??= new AdminApi;
    }

    /**
     * The end-user panel client of this module.
     */
    private function enduser(): EnduserApi
    {
        return $this->enduserApi ??= new EnduserApi($this->admin());
    }

    /**
     * Sign-in and the staff access tools.
     */
    private function signIn(): SignIn
    {
        return $this->signInHelper ??= new SignIn($this->admin(), $this->enduser(), $this->slug());
    }

    /**
     * The client server panel and console.
     */
    private function panel(): Panel
    {
        return $this->panelHelper ??= new Panel($this->admin(), $this->enduser(), $this->signIn());
    }

    /**
     * Extra resources on top of the plan.
     */
    private function totals(): ResourceTotals
    {
        return $this->resourceTotals ??= new ResourceTotals($this->admin());
    }
}
