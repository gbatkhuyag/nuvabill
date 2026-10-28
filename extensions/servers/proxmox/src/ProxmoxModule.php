<?php

namespace Nuvabill\Extensions\Proxmox;

use App\Contracts\HasClientPanel;
use App\Contracts\HasResourceOptions;
use App\Enums\ServiceStatus;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Resources\ResourceCheck;
use App\Extensions\Servers\Resources\ResourceExtras;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Extensions\Servers\Resources\ResourceLimit;
use App\Models\Product;
use App\Models\ResourceOption;
use App\Models\Server;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proxmox VE through its API with an API token. New servers are full clones of a cloud-init
 * template; cloud-init sets the root password and network on first boot.
 *
 * Clients can buy extra CPU cores, RAM and disk space with sliders (HasResourceOptions). The VM
 * always gets the product's cores, memory and disk plus the service's extras. CPU and memory
 * changes on a running VM wait in Proxmox until the next restart; the disk only grows.
 */
class ProxmoxModule extends Module implements HasClientPanel, HasResourceOptions
{
    /**
     * How long to wait for a Proxmox task (stop, start, delete), in seconds.
     */
    private const TASK_TIMEOUT = 600;

    /**
     * How long applyResources() waits for a disk resize, in seconds. It may run in a staff request,
     * so a slower resize is reported as pending and core checks the server again later.
     */
    private const RESIZE_WAIT = 30;

    /**
     * The most extra units each kind may add: cores, MB of RAM, and GB of disk (no limit of its own).
     *
     * @var array<string, int|null>
     */
    private const MAX_EXTRA = ['cpu' => 64, 'ram' => 262144, 'disk' => null];

    /**
     * create() runs in the setup job, which is stopped after 900 seconds. So all its tasks
     * together (clone, resize, start) get this long, and removing a half-made VM gets CLEANUP_TIMEOUT.
     * Both end in time for the error to be logged and the VM to be removed.
     */
    private const CREATE_TIMEOUT = 660;

    private const CLEANUP_TIMEOUT = 90;

    public function productFields(): array
    {
        return [
            'node' => ['label' => 'Node', 'type' => 'text', 'required' => true, 'help' => 'The Proxmox node name, for example "pve1".'],
            'template' => ['label' => 'Template VM ID', 'type' => 'text', 'required' => true, 'help' => 'A KVM template with cloud-init, for example 9000.'],
            'storage' => ['label' => 'Storage', 'type' => 'text', 'help' => 'Where to put the new disk, for example "local-lvm". Empty uses the template\'s storage.'],
            'cores' => ['label' => 'CPU cores', 'type' => 'text', 'required' => true],
            'memory' => ['label' => 'RAM (MB)', 'type' => 'text', 'required' => true],
            'disk' => ['label' => 'Disk size (GB)', 'type' => 'text', 'help' => 'The disk grows to this size. Empty keeps the template size, and then extra disk space cannot be sold.'],
            'disk_device' => ['label' => 'Disk device', 'type' => 'text', 'help' => 'Default "scsi0".'],
            'ip_config' => ['label' => 'Network (ipconfig0)', 'type' => 'text', 'help' => 'Default "ip=dhcp". Every VPS of this product gets this setting, so a fixed IP such as "ip=203.0.113.10/24,gw=203.0.113.1" only works for one VPS: set the product stock to 1.'],
        ];
    }

    public function serverHelp(): string
    {
        return 'Create an API token in Proxmox → Datacenter → Permissions → API Tokens. Put the token ID in "Username" (for example root@pam!nuvabill) and the secret in "API token". Port 8006 needs a valid SSL certificate (Proxmox can get one with ACME).';
    }

    public function defaultPort(): int
    {
        return 8006;
    }

    public function hasLoginLink(): bool
    {
        return false;
    }

    public function testConnection(Server $server): ModuleResult
    {
        return $this->attempt(function () use ($server): ModuleResult {
            $version = (array) $this->call($server, 'get', 'version');

            return ModuleResult::ok(__('Connected to Proxmox VE :version.', ['version' => $version['version'] ?? '']));
        });
    }

    /**
     * Clones the template, then sets the plan's cores and memory plus the service's extras and
     * grows the disk to the plan's size plus extra disk space.
     */
    public function create(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            $server = $service->server;
            $node = $this->node($service);
            $template = (int) $this->productSetting($service, 'template');

            if ($template === 0) {
                return ModuleResult::fail(__('Set the template VM ID on the product first.'));
            }

            $extras = $service->resourceExtras();
            $refusal = $this->refusal($service->product, $extras);

            if ($refusal !== null) {
                return ModuleResult::fail($refusal);
            }

            $totals = $this->totals($service->product, $extras);
            $ipConfig = (string) ($this->productSetting($service, 'ip_config') ?: 'ip=dhcp');
            $fixedIp = $this->fixedIp($ipConfig);

            if ($fixedIp !== null && $this->fixedIpInUse($service, $fixedIp)) {
                return ModuleResult::fail(__('This product has a fixed IP that another service already uses. Give each VPS its own IP.'));
            }

            $deadline = now()->addSeconds(self::CREATE_TIMEOUT);
            $vmId = (int) $this->call($server, 'get', 'cluster/nextid');
            $password = $this->makePassword();

            $clone = $this->call($server, 'post', "nodes/{$node}/qemu/{$template}/clone", array_filter([
                'newid' => $vmId,
                'name' => $this->vmName($service),
                'full' => 1,
                'storage' => $this->productSetting($service, 'storage') ?: null,
                'description' => 'Nuvabill service #'.$service->id,
            ]));

            try {
                $this->waitFor($server, $node, $clone, $deadline);
            } catch (RuntimeException $exception) {
                // A clone that fails or is stopped removes its half-copied VM itself.
                $this->stopTask($server, $node, $clone);

                throw new RuntimeException($exception->getMessage().' '.__('Also check that VM :id was removed from the node.', ['id' => $vmId]));
            }

            // From here the VM exists. If a later step fails, remove it again: Nuvabill does not
            // store its ID, so it would be left on the node, and the next try clones a new one.
            try {
                $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/config", [
                    'cores' => $totals['cpu'],
                    'memory' => $totals['ram'],
                    'ciuser' => 'root',
                    'cipassword' => $password,
                    'ipconfig0' => $ipConfig,
                    'onboot' => 1,
                ]);

                $this->resizeDisk($service, $node, $vmId, $totals['disk'], $deadline);
                $this->waitFor($server, $node, $this->call($server, 'post', "nodes/{$node}/qemu/{$vmId}/status/start"), $deadline);
            } catch (RuntimeException $exception) {
                if (! $this->removeVm($server, $node, $vmId)) {
                    throw new RuntimeException($exception->getMessage().' '.__('Also check that VM :id was removed from the node.', ['id' => $vmId]));
                }

                throw $exception;
            }

            return ModuleResult::ok(__('Virtual server :id created.', ['id' => $vmId]), [
                'username' => 'root',
                'password' => $password,
                // The network the VM got, so the fixed IP check does not depend on the product's
                // setting, which staff may change for the next sale.
                'module_data' => array_filter(['vmid' => $vmId, 'node' => $node, 'ip_config' => $ipConfig, 'ip' => $fixedIp]),
            ]);
        });
    }

    /**
     * Stops the VM and waits until it is off. Start on boot is turned off first, so a node
     * restart does not bring a suspended VM back. A stop that fails (for example while a backup
     * holds a lock) fails the suspension, so the service stays active and is tried again.
     *
     * The nightly run also calls this for services that are already suspended, to finish
     * suspensions from before this worked (see Provisioner::recheckSuspensions()).
     */
    public function suspend(Service $service, string $reason): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            [$node, $vmId] = $this->vm($service);
            $server = $service->server;

            $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/config", ['onboot' => 0]);

            try {
                if (($this->status($service)['status'] ?? '') !== 'stopped') {
                    $this->waitFor($server, $node, $this->call($server, 'post', "nodes/{$node}/qemu/{$vmId}/status/stop"));
                }
            } catch (RuntimeException $exception) {
                // An active service stays active, so it should start on boot again. A suspended one stays off.
                if ($service->status !== ServiceStatus::Suspended) {
                    rescue(fn () => $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/config", ['onboot' => 1]), report: false);
                }

                throw $exception;
            }

            return ModuleResult::ok(__('Virtual server stopped.'));
        });
    }

    /**
     * Turns start on boot back on and starts the VM. When the start fails, the service stays
     * suspended, so start on boot is turned off again.
     */
    public function unsuspend(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            [$node, $vmId] = $this->vm($service);
            $server = $service->server;

            $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/config", ['onboot' => 1]);

            try {
                if (($this->status($service)['status'] ?? '') !== 'running') {
                    $this->waitFor($server, $node, $this->call($server, 'post', "nodes/{$node}/qemu/{$vmId}/status/start"));
                }
            } catch (RuntimeException $exception) {
                rescue(fn () => $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/config", ['onboot' => 0]), report: false);

                throw $exception;
            }

            return ModuleResult::ok(__('Virtual server started.'));
        });
    }

    public function terminate(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            [$node, $vmId] = $this->vm($service);
            $server = $service->server;

            if (($this->status($service)['status'] ?? '') === 'running') {
                $this->waitFor($server, $node, $this->call($server, 'post', "nodes/{$node}/qemu/{$vmId}/status/stop"));
            }

            $this->waitFor($server, $node, $this->call($server, 'delete', "nodes/{$node}/qemu/{$vmId}", ['purge' => 1, 'destroy-unreferenced-disks' => 1]));

            return ModuleResult::ok(__('Virtual server :id deleted.', ['id' => $vmId]));
        });
    }

    /**
     * Sets the new plan's cores, memory and disk plus the service's extras, which it keeps after a
     * plan change. When the new plan has no disk size, the disk is left as it is (it never shrinks).
     */
    public function changePackage(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            [$node, $vmId] = $this->vm($service);
            $extras = $service->resourceExtras();
            $refusal = $this->tooLarge($extras);

            if ($refusal !== null) {
                return ModuleResult::fail($refusal);
            }

            $totals = $this->totals($service->product, $extras);

            $this->call($service->server, 'put', "nodes/{$node}/qemu/{$vmId}/config", [
                'cores' => $totals['cpu'],
                'memory' => $totals['ram'],
            ]);

            $this->resizeDisk($service, $node, $vmId, $totals['disk']);

            return ModuleResult::ok(__('New plan saved. CPU and memory change after the next restart.'));
        });
    }

    /**
     * CPU cores, RAM and, when the product has a disk size, disk space. Without a disk size the VM
     * keeps the template's disk, so there is no base to add to and staff cannot add a disk option.
     * Read from this module only, never from the server.
     *
     * @return array<string, ResourceKind>
     */
    public function resourceKinds(Product $product): array
    {
        $kinds = [
            'cpu' => new ResourceKind(key: 'cpu', label: ResourceOption::kindLabel('cpu'), unit: 'core', maxExtra: self::MAX_EXTRA['cpu'], restarts: true),
            'ram' => new ResourceKind(key: 'ram', label: ResourceOption::kindLabel('ram'), unit: 'MB', minStep: 128, maxExtra: self::MAX_EXTRA['ram'], restarts: true),
        ];

        if ($this->planBase($product)['disk'] !== null) {
            $kinds['disk'] = new ResourceKind(
                key: 'disk',
                label: ResourceOption::kindLabel('disk'),
                unit: 'GB',
                maxExtra: self::MAX_EXTRA['disk'],
                canShrink: false,
                note: __('Grow the partition inside your server to use the new space.'),
            );
        }

        return $kinds;
    }

    /**
     * The product's cores, memory (MB) and disk size (GB, null when it keeps the template's size),
     * as create() sets them. It does not ask the server.
     *
     * @return array{cpu: int, ram: int, disk: int|null}
     */
    public function baseResources(Product $product, ?Server $server): array
    {
        return $this->planBase($product);
    }

    /**
     * The disk cannot go below what the VM has now, as Proxmox cannot shrink a disk. Asks the node
     * for the VM's config; a service without a VM yet has no limit.
     *
     * @return array<string, ResourceLimit>
     */
    public function resourceLimits(Service $service): array
    {
        $base = $this->planBase($service->product)['disk'];

        if ($base === null || (int) $this->moduleValue($service, 'vmid', 0) === 0) {
            return [];
        }

        [$node, $vmId] = $this->vm($service);
        $live = $this->diskSize($this->config($service, $node, $vmId), $this->diskDevice($service));

        if ($live === null) {
            return [];
        }

        return ['disk' => new ResourceLimit(minUnits: max(0, $live - $base), reason: __('Disk space can only grow.'))];
    }

    /**
     * Sets cores and memory for the kinds in $extras (only when they differ, so running it again
     * changes nothing) and grows the disk when it is smaller than the plan plus its extras. Then it
     * reads the config again: Proxmox returns changes that wait for a restart as already set. A disk
     * that is bigger than expected is fine, as it cannot shrink. A resize that takes longer than
     * RESIZE_WAIT gives 'pending', and core checks the server later.
     */
    public function applyResources(Service $service, ResourceExtras $extras): ModuleResult
    {
        return $this->attempt(function () use ($service, $extras): ModuleResult {
            $refusal = $this->refusal($service->product, $extras);

            if ($refusal !== null) {
                return ModuleResult::fail($refusal);
            }

            [$node, $vmId] = $this->vm($service);
            $server = $service->server;
            $totals = $this->totals($service->product, $extras);
            $device = $this->diskDevice($service);
            $config = $this->config($service, $node, $vmId);
            $changes = [];

            if ($extras->has('cpu') && $this->cores($config) !== $totals['cpu']) {
                $changes['cores'] = $totals['cpu'];
            }

            if ($extras->has('ram') && $this->memory($config) !== $totals['ram']) {
                $changes['memory'] = $totals['ram'];
            }

            $growTo = $extras->has('disk') && $totals['disk'] !== null && ($this->diskSize($config, $device) ?? 0) < $totals['disk'] ? $totals['disk'] : null;

            if ($changes !== []) {
                $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/config", $changes);
            }

            if ($growTo !== null) {
                $task = $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/resize", ['disk' => $device, 'size' => $growTo.'G']);

                if (! $this->taskFinished($server, $node, $task, now()->addSeconds(self::RESIZE_WAIT))) {
                    return ModuleResult::ok(__('The disk is still growing. It is checked again in a few minutes.'), ['pending' => true, 'restart' => $changes !== []]);
                }
            }

            $problems = $this->compare($extras, $totals, $this->config($service, $node, $vmId), $device, diskMayBeBigger: true)['problems'];

            if ($problems !== []) {
                return ModuleResult::fail(__('The server does not match yet: :problems', ['problems' => implode('; ', $problems)]));
            }

            return match (true) {
                $changes !== [] => ModuleResult::ok(__('Saved. CPU and memory change after the next restart.'), ['restart' => true]),
                $growTo !== null => ModuleResult::ok(__('Saved. The disk grew to :size.', ['size' => ResourceOption::formatUnits('GB', $growTo)])),
                default => ModuleResult::ok(__('The server already has these resources.')),
            };
        });
    }

    /**
     * Compares the VM's config with the plan plus the extras, for the kinds in $extras. Proxmox
     * returns changes that wait for a restart as already set. Changes nothing.
     *
     * @throws RuntimeException When the node does not answer or the service has no VM.
     */
    public function checkResources(Service $service, ResourceExtras $extras): ResourceCheck
    {
        [$node, $vmId] = $this->vm($service);
        $result = $this->compare($extras, $this->totals($service->product, $extras), $this->config($service, $node, $vmId), $this->diskDevice($service), diskMayBeBigger: false);
        $problems = $result['problems'];
        $refusal = $this->refusal($service->product, $extras);

        if ($refusal !== null) {
            array_unshift($problems, $refusal);
        }

        return new ResourceCheck($result['expected'], $result['live'], $problems);
    }

    public function clientPanelView(): string
    {
        return 'theme::client.services.vps-panel';
    }

    public function clientActions(): array
    {
        return [
            'start' => __('Start'),
            'stop' => __('Shut down'),
            'restart' => __('Restart'),
            'poweroff' => __('Power off'),
            'password' => __('Change root password'),
        ];
    }

    public function clientPanel(Service $service): array
    {
        $status = $this->status($service);
        [$node, $vmId] = $this->vm($service);
        $config = (array) $this->call($service->server, 'get', "nodes/{$node}/qemu/{$vmId}/config");

        return [
            'hostname' => $status['name'] ?? $config['name'] ?? $this->vmName($service),
            'os' => null,
            'state' => match (true) {
                ($status['qmpstatus'] ?? '') === 'paused' => 'suspended',
                ($status['status'] ?? '') === 'running' => 'running',
                ($status['status'] ?? '') === 'stopped' => 'stopped',
                default => 'unknown',
            },
            'cpu' => isset($status['cpu']) ? round((float) $status['cpu'] * 100, 1) : null,
            'ram' => isset($status['maxmem']) ? ['used' => round((float) ($status['mem'] ?? 0) / 1048576), 'total' => round((float) $status['maxmem'] / 1048576), 'unit' => 'MB'] : null,
            'ips' => $this->ipAddresses($service, $config, ($status['status'] ?? '') === 'running'),
        ];
    }

    public function clientAction(Service $service, string $action, array $input): ModuleResult
    {
        return match ($action) {
            'start' => $this->power($service, 'start', __('The server is starting.')),
            'stop' => $this->power($service, 'shutdown', __('The server is shutting down.')),
            'restart' => $this->power($service, 'reboot', __('The server is restarting.')),
            'poweroff' => $this->power($service, 'stop', __('The server is powered off.')),
            'password' => $this->changePassword($service, (string) ($input['password'] ?? '')),
            default => ModuleResult::fail(__('This action is not available.')),
        };
    }

    private function changePassword(Service $service, string $password): ModuleResult
    {
        if (strlen($password) < 10 || ! preg_match('/[a-z]/i', $password) || ! preg_match('/\d/', $password)) {
            return ModuleResult::fail(__('Use at least 10 characters with letters and numbers.'));
        }

        return $this->attempt(function () use ($service, $password): ModuleResult {
            [$node, $vmId] = $this->vm($service);

            $this->call($service->server, 'put', "nodes/{$node}/qemu/{$vmId}/config", ['cipassword' => $password]);
            $service->update(['password' => $password]);

            return ModuleResult::ok(__('Root password saved. It takes effect after you restart the server.'));
        });
    }

    /**
     * Start, stop, shutdown or reboot. Proxmox runs these as tasks, so this only starts them.
     */
    private function power(Service $service, string $command, string $message): ModuleResult
    {
        return $this->attempt(function () use ($service, $command, $message): ModuleResult {
            [$node, $vmId] = $this->vm($service);

            $this->call($service->server, 'post', "nodes/{$node}/qemu/{$vmId}/status/{$command}");

            return ModuleResult::ok($message);
        });
    }

    /**
     * Grow the VM's disk to this many GB. Nothing happens when the size is null (the template's
     * size is kept) or the disk is already as big: Proxmox cannot shrink a disk.
     */
    private function resizeDisk(Service $service, string $node, int $vmId, ?int $size, ?CarbonInterface $deadline = null): void
    {
        if ($size === null || $size <= 0) {
            return;
        }

        $device = $this->diskDevice($service);
        $current = $this->diskSize($this->config($service, $node, $vmId), $device);

        if ($current !== null && $current >= $size) {
            return;
        }

        $this->waitFor($service->server, $node, $this->call($service->server, 'put', "nodes/{$node}/qemu/{$vmId}/resize", ['disk' => $device, 'size' => $size.'G']), $deadline);
    }

    /**
     * What the product's plan gives, as create() sets it: cores (at least 1), memory in MB (at
     * least 256) and the disk size in GB, or null when the VM keeps the template's disk.
     *
     * @return array{cpu: int, ram: int, disk: int|null}
     */
    private function planBase(Product $product): array
    {
        $config = (array) ($product->module_config ?? []);
        $disk = (int) ($config['disk'] ?? 0);

        return [
            'cpu' => max(1, (int) ($config['cores'] ?? 1)),
            'ram' => max(256, (int) ($config['memory'] ?? 1024)),
            'disk' => $disk > 0 ? $disk : null,
        ];
    }

    /**
     * The plan plus the extras, per kind this module sets. Disk is null when the product keeps the
     * template's disk.
     *
     * @return array{cpu: int, ram: int, disk: int|null}
     */
    private function totals(Product $product, ResourceExtras $extras): array
    {
        $base = $this->planBase($product);

        return [
            'cpu' => $base['cpu'] + max(0, $extras->get('cpu')),
            'ram' => $base['ram'] + max(0, $extras->get('ram')),
            'disk' => $base['disk'] === null ? null : $base['disk'] + max(0, $extras->get('disk')),
        ];
    }

    /**
     * Why these extras cannot be set at all, for staff, or null when they can. Checked before
     * anything is sent, so nothing changes.
     */
    private function refusal(Product $product, ResourceExtras $extras): ?string
    {
        if ($extras->get('disk') > 0 && $this->planBase($product)['disk'] === null) {
            return __('Set the disk size on the product first.');
        }

        return $this->tooLarge($extras);
    }

    /**
     * A message for staff when an extra is more than this module adds, or null.
     */
    private function tooLarge(ResourceExtras $extras): ?string
    {
        foreach (self::MAX_EXTRA as $kind => $max) {
            if ($max !== null && $extras->get($kind) > $max) {
                return __(':kind: :amount is more than Proxmox can add. Nothing was changed.', [
                    'kind' => ResourceOption::kindLabel($kind),
                    'amount' => ResourceOption::formatUnits($kind === 'ram' ? 'MB' : 'core', $extras->get($kind)),
                ]);
            }
        }

        return null;
    }

    /**
     * What each kind in $extras should be (expected) and is (live), with a line for staff for each
     * kind that differs. With $diskMayBeBigger, a disk bigger than expected is not a problem: it
     * cannot shrink, and the client has at least what they pay for.
     *
     * @param  array{cpu: int, ram: int, disk: int|null}  $totals
     * @param  array<string, mixed>  $config
     * @return array{expected: array<string, int>, live: array<string, int|null>, problems: list<string>}
     */
    private function compare(ResourceExtras $extras, array $totals, array $config, string $device, bool $diskMayBeBigger): array
    {
        $units = ['cpu' => 'core', 'ram' => 'MB', 'disk' => 'GB'];
        $live = ['cpu' => $this->cores($config), 'ram' => $this->memory($config), 'disk' => $this->diskSize($config, $device)];
        $result = ['expected' => [], 'live' => [], 'problems' => []];

        foreach ($units as $kind => $unit) {
            if (! $extras->has($kind) || $totals[$kind] === null) {
                continue;
            }

            $result['expected'][$kind] = $totals[$kind];
            $result['live'][$kind] = $live[$kind];
            $label = ResourceOption::kindLabel($kind);

            if ($live[$kind] === null) {
                $result['problems'][] = __(':kind could not be read from the server.', ['kind' => $label]);
            } elseif ($live[$kind] !== $totals[$kind] && ! ($diskMayBeBigger && $kind === 'disk' && $live[$kind] > $totals[$kind])) {
                $result['problems'][] = __(':kind: :live, expected :expected', [
                    'kind' => $label,
                    'live' => ResourceOption::formatUnits($unit, $live[$kind]),
                    'expected' => ResourceOption::formatUnits($unit, $totals[$kind]),
                ]);
            }
        }

        return $result;
    }

    /**
     * The VM's config. Proxmox returns changes that wait for a restart as already set.
     *
     * @return array<string, mixed>
     */
    private function config(Service $service, string $node, int $vmId): array
    {
        return (array) $this->call($service->server, 'get', "nodes/{$node}/qemu/{$vmId}/config");
    }

    /**
     * The disk that grows, such as "scsi0".
     */
    private function diskDevice(Service $service): string
    {
        return trim((string) $this->productSetting($service, 'disk_device', '')) ?: 'scsi0';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function cores(array $config): ?int
    {
        $cores = $config['cores'] ?? null;

        return is_numeric($cores) ? (int) $cores : null;
    }

    /**
     * Memory in MB. Newer Proxmox versions may write it as "current=4096".
     *
     * @param  array<string, mixed>  $config
     */
    private function memory(array $config): ?int
    {
        return preg_match('/^(?:current=)?(\d+)/', (string) ($config['memory'] ?? ''), $match) ? (int) $match[1] : null;
    }

    /**
     * The size of a disk in whole GB (rounded down), from a value such as
     * "local-lvm:vm-105-disk-0,size=40G", or null when it cannot be read.
     *
     * @param  array<string, mixed>  $config
     */
    private function diskSize(array $config, string $device): ?int
    {
        if (! preg_match('/(?:^|,)size=(\d+(?:\.\d+)?)([KMGT]?)(?:,|$)/', (string) ($config[$device] ?? ''), $match)) {
            return null;
        }

        $gb = (float) $match[1] * ['' => 1 / 1073741824, 'K' => 1 / 1048576, 'M' => 1 / 1024, 'G' => 1, 'T' => 1024][$match[2]];

        return (int) floor($gb + 0.0005);
    }

    /**
     * The fixed IPv4 address in an ipconfig0 value such as "ip=203.0.113.10/24,gw=203.0.113.1",
     * or null for DHCP.
     */
    private function fixedIp(string $ipConfig): ?string
    {
        return preg_match('/(?:^|,)ip=([0-9.]+)\//', $ipConfig, $match) ? $match[1] : null;
    }

    /**
     * Whether a live VPS of a Proxmox product already has this fixed IP. Two VMs with one IP knock
     * each other offline. A VPS remembers the network it got; one made before Nuvabill stored that
     * is checked by its product's current setting.
     */
    private function fixedIpInUse(Service $service, string $ip): bool
    {
        $products = Product::query()->where('server_module', $this->slug())->get(['id', 'module_config']);
        $sameIp = '/(?:^|,)ip='.preg_quote($ip, '/').'\//';
        $olderWithIp = $products
            ->filter(fn (Product $product): bool => preg_match($sameIp, (string) ($product->module_config['ip_config'] ?? '')) === 1)
            ->modelKeys();

        return Service::query()
            ->whereIn('product_id', $products->modelKeys())
            ->whereKeyNot($service->id)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->whereNotNull('module_data->vmid')
            ->where(fn (Builder $query): Builder => $query
                ->where('module_data->ip', $ip)
                ->orWhere(fn (Builder $query): Builder => $query->whereNull('module_data->ip_config')->whereIn('product_id', $olderWithIp)))
            ->exists();
    }

    /**
     * Stop and delete a VM that create() could not finish. False when that did not work,
     * so staff can be told to check the node. The create error stays the one shown.
     */
    private function removeVm(?Server $server, string $node, int $vmId): bool
    {
        return rescue(function () use ($server, $node, $vmId): bool {
            $deadline = now()->addSeconds(self::CLEANUP_TIMEOUT);
            $status = (array) $this->call($server, 'get', "nodes/{$node}/qemu/{$vmId}/status/current");

            if (($status['status'] ?? '') === 'running') {
                $this->waitFor($server, $node, $this->call($server, 'post', "nodes/{$node}/qemu/{$vmId}/status/stop"), $deadline);
            }

            $this->waitFor($server, $node, $this->call($server, 'delete', "nodes/{$node}/qemu/{$vmId}", ['purge' => 1, 'destroy-unreferenced-disks' => 1]), $deadline);

            return true;
        }, false, report: false);
    }

    /**
     * Ask Proxmox to stop a running task. Nothing happens when it already finished.
     */
    private function stopTask(?Server $server, string $node, mixed $task): void
    {
        if (is_string($task) && str_starts_with($task, 'UPID:')) {
            rescue(fn () => $this->call($server, 'delete', "nodes/{$node}/tasks/".rawurlencode($task)), report: false);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function ipAddresses(Service $service, array $config, bool $running): array
    {
        if (preg_match('/ip=([0-9.]+)\//', (string) ($config['ipconfig0'] ?? ''), $match)) {
            return [$match[1]];
        }

        if (! $running || empty($config['agent'])) {
            return [];
        }

        [$node, $vmId] = $this->vm($service);
        $interfaces = rescue(fn (): array => (array) ($this->call($service->server, 'get', "nodes/{$node}/qemu/{$vmId}/agent/network-get-interfaces")['result'] ?? []), [], report: false);
        $addresses = [];

        foreach ($interfaces as $interface) {
            foreach ((array) ($interface['ip-addresses'] ?? []) as $address) {
                $ip = (string) ($address['ip-address'] ?? '');

                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $addresses[] = $ip;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @return array<string, mixed>
     */
    private function status(Service $service): array
    {
        [$node, $vmId] = $this->vm($service);

        return (array) $this->call($service->server, 'get', "nodes/{$node}/qemu/{$vmId}/status/current");
    }

    /**
     * Wait until a Proxmox task (a "UPID:..." string) has finished, and fail if it did not succeed.
     * A task succeeded when it ended with "OK", or with "WARNINGS: n" (done, but it logged warnings).
     */
    private function waitFor(?Server $server, string $node, mixed $task, ?CarbonInterface $deadline = null): void
    {
        if (! $this->taskFinished($server, $node, $task, $deadline ?? now()->addSeconds(self::TASK_TIMEOUT))) {
            throw new RuntimeException(__('Proxmox did not finish the task in time. Check the task log on the node.'));
        }
    }

    /**
     * Wait until a Proxmox task (a "UPID:..." string) has finished, up to the deadline. True when
     * it is done (or there was no task), false when it still runs at the deadline.
     *
     * @throws RuntimeException When the task failed.
     */
    private function taskFinished(?Server $server, string $node, mixed $task, CarbonInterface $deadline): bool
    {
        if (! is_string($task) || ! str_starts_with($task, 'UPID:')) {
            return true;
        }

        do {
            $status = (array) $this->call($server, 'get', "nodes/{$node}/tasks/".rawurlencode($task).'/status');

            if (($status['status'] ?? '') === 'stopped') {
                $exit = trim((string) ($status['exitstatus'] ?? ''));

                if ($exit !== 'OK' && ! preg_match('/^WARNINGS: \d+$/', $exit)) {
                    throw new RuntimeException('Proxmox: '.($exit !== '' ? $exit : __('the task failed')));
                }

                return true;
            }

            Sleep::for(3)->seconds();
        } while (now()->lessThan($deadline));

        return false;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function vm(Service $service): array
    {
        $vmId = (int) $this->moduleValue($service, 'vmid', 0);

        if ($vmId === 0) {
            throw new RuntimeException(__('This service has no Proxmox VM ID yet.'));
        }

        return [(string) $this->moduleValue($service, 'node', $this->node($service)), $vmId];
    }

    private function node(Service $service): string
    {
        $node = (string) $this->productSetting($service, 'node', '');

        if (! preg_match('/^[A-Za-z0-9.-]+$/', $node)) {
            throw new RuntimeException(__('Set the Proxmox node name on the product first.'));
        }

        return $node;
    }

    private function vmName(Service $service): string
    {
        $domain = strtolower((string) $service->domain);

        return preg_match('/^[a-z0-9]([a-z0-9.-]{0,61}[a-z0-9])?$/', $domain) && str_contains($domain, '.') ? $domain : 'vps'.$service->id;
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
     * Call the API and return its "data" value.
     *
     * @param  array<string, mixed>  $params
     */
    private function call(?Server $server, string $method, string $path, array $params = []): mixed
    {
        if ($server === null) {
            throw new RuntimeException(__('No server is assigned.'));
        }

        $url = $server->apiScheme().'://'.$server->hostname.':'.($server->port ?: $this->defaultPort()).'/api2/json/'.$path;

        try {
            $request = Http::withHeaders(['Authorization' => 'PVEAPIToken='.$server->username.'='.$server->api_token])
                ->timeout(30)
                ->acceptJson()
                ->asForm();

            $response = match ($method) {
                'get' => $request->get($url, $params),
                'put' => $request->put($url, $params),
                'delete' => $request->delete($url.($params === [] ? '' : '?'.http_build_query($params))),
                default => $request->post($url, $params),
            };
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('Could not connect to :host: :error', ['host' => $server->hostname, 'error' => $exception->getMessage()]));
        }

        if ($response->status() === 401) {
            throw new RuntimeException(__('Proxmox rejected the API token.'));
        }

        if (! $response->successful()) {
            $details = collect((array) $response->json('errors', []))->map(fn (mixed $error, string|int $field): string => $field.': '.trim((string) $error))->implode(' ');

            throw new RuntimeException('Proxmox: '.trim($response->toPsrResponse()->getReasonPhrase().' '.$details));
        }

        return $response->json('data');
    }
}
