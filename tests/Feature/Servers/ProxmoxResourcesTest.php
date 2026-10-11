<?php

namespace Tests\Feature\Servers;

use App\Billing\ResourceChanges;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Resources\ResourceExtras;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Nuvabill\Extensions\Proxmox\ProxmoxModule;
use Tests\TestCase;

/**
 * Proxmox 1.1.0: extra CPU cores, RAM and disk space sold with sliders.
 */
class ProxmoxResourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_sets_cores_memory_and_disk_to_base_plus_extras(): void
    {
        Sleep::fake();
        $node = $this->fakeNode(['cores' => 1, 'memory' => '1024', 'scsi0' => 'local-lvm:vm-105-disk-0,size=10G']);
        $service = $this->service(['status' => ServiceStatus::Pending]);
        $this->extra($service, 'cpu', 1, 2);
        $this->extra($service, 'ram', 1024, 2);
        $this->extra($service, 'disk', 10, 1);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertContains('PUT config {"cores":4,"memory":4096,"ciuser":"root","ipconfig0":"ip=dhcp","onboot":1}', $node->log);
        $this->assertContains('PUT resize {"disk":"scsi0","size":"50G"}', $node->log);
        $this->assertSame(['cores' => 4, 'memory' => 4096, 'scsi0' => 'local-lvm:vm-105-disk-0,size=50G'], array_intersect_key($node->config, array_flip(['cores', 'memory', 'scsi0'])));
    }

    public function test_change_package_sets_the_new_plan_plus_the_extras(): void
    {
        Sleep::fake();
        $node = $this->fakeNode(['cores' => 3, 'memory' => '4096', 'scsi0' => 'local-lvm:vm-105-disk-0,size=50G']);
        $service = $this->service(['module_data' => ['vmid' => 105, 'node' => 'pve1']]);
        $this->extra($service, 'cpu', 1, 1);
        $this->extra($service, 'ram', 1024, 2);
        $this->extra($service, 'disk', 10, 1);
        $service->product->update(['module_config' => ['cores' => '4', 'memory' => '8192', 'disk' => '80'] + $service->product->module_config]);

        $result = app(Provisioner::class)->changePackage($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertContains('PUT config {"cores":5,"memory":10240}', $node->log);
        $this->assertContains('PUT resize {"disk":"scsi0","size":"90G"}', $node->log);
    }

    public function test_apply_resources_updates_config_and_only_grows_the_disk(): void
    {
        Sleep::fake();
        $node = $this->fakeNode(['cores' => 2, 'memory' => '2048', 'scsi0' => 'local-lvm:vm-105-disk-0,discard=on,size=40G']);
        $service = $this->service(['module_data' => ['vmid' => 105, 'node' => 'pve1']]);
        $cpu = $this->extra($service, 'cpu', 1, 2);
        $ram = $this->extra($service, 'ram', 1024, 2);
        $disk = $this->extra($service, 'disk', 10, 1);

        $result = app(Provisioner::class)->applyResources($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('Saved. CPU and memory change after the next restart.', $result->message);
        $this->assertTrue($result->data['restart'] ?? false);
        $this->assertSame(['GET config', 'PUT config {"cores":4,"memory":4096}', 'PUT resize {"disk":"scsi0","size":"50G"}', 'GET config'], $this->calls($node));
        $this->assertTrue(ActivityLog::query()->where('action', 'service.resources_applied')->where('subject_id', $service->id)->exists());

        // Running it again with the same extras changes nothing.
        $node->log = [];
        $again = app(Provisioner::class)->applyResources($service->fresh());

        $this->assertTrue($again->success, $again->message);
        $this->assertSame('The server already has these resources.', $again->message);
        $this->assertSame(['GET config', 'GET config'], $this->calls($node));

        // Less RAM goes back down; disk extras that end cannot shrink the disk, which stays as it is.
        $ram->update(['steps' => 1]);
        $disk->update(['steps' => 0, 'status' => ServiceResourceOption::STATUS_CANCELLED]);
        $cpu->update(['steps' => 2]);
        $node->log = [];

        $lower = app(Provisioner::class)->applyResources($service->fresh());

        $this->assertTrue($lower->success, $lower->message);
        $this->assertSame(['GET config', 'PUT config {"memory":3072}', 'GET config'], $this->calls($node));
        $this->assertStringContainsString('size=50G', $node->config['scsi0']);

        // A kind that is not listed is left as it is.
        $node->log = [];
        $only = $this->module()->applyResources($service->fresh(), new ResourceExtras(['ram' => 0]));

        $this->assertTrue($only->success, $only->message);
        $this->assertSame(['GET config', 'PUT config {"memory":2048}', 'GET config'], $this->calls($node));
        $this->assertSame(4, $node->config['cores']);
    }

    public function test_check_resources_reads_the_pending_config(): void
    {
        // Proxmox lists a change that waits for a restart as already set.
        $node = $this->fakeNode(['cores' => '4', 'memory' => '4096', 'scsi0' => 'local-lvm:vm-105-disk-0,size=51200M']);
        $service = $this->service(['module_data' => ['vmid' => 105, 'node' => 'pve1']]);
        $this->extra($service, 'cpu', 1, 2);
        $this->extra($service, 'ram', 1024, 2);
        $this->extra($service, 'disk', 10, 1);

        $check = app(Provisioner::class)->checkResources($service);

        $this->assertNotNull($check);
        $this->assertTrue($check->matches(), implode('; ', $check->problems));
        $this->assertSame(['cpu' => 4, 'ram' => 4096, 'disk' => 50], $check->expected);
        $this->assertSame(['cpu' => 4, 'ram' => 4096, 'disk' => 50], $check->live);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/qemu/105/config') && ! str_contains($request->url(), 'current='));
        $this->assertSame(['GET config'], $this->calls($node), 'A check changes nothing.');

        $node->config['memory'] = 'current=2048';
        $node->config['scsi0'] = 'local-lvm:vm-105-disk-0,size=60G';
        $check = app(Provisioner::class)->checkResources($service->fresh());

        $this->assertFalse($check->matches());
        $this->assertSame(['RAM: 2 GB, expected 4 GB', 'Disk space: 60 GB, expected 50 GB'], $check->problems);

        // A node that does not answer gives no check.
        $node->down = true;

        $this->assertNull(app(Provisioner::class)->checkResources($service->fresh()));
    }

    public function test_kinds_and_base_come_from_the_product_and_disk_needs_a_disk_size(): void
    {
        Http::fake();
        $service = $this->service(['status' => ServiceStatus::Pending]);

        $kinds = app(Provisioner::class)->resourceKinds($service->product);

        $this->assertSame(['cpu', 'ram', 'disk'], array_keys($kinds));
        $this->assertSame(['core', 'MB', 'GB'], [$kinds['cpu']->unit, $kinds['ram']->unit, $kinds['disk']->unit]);
        $this->assertSame([64, 262144, null], [$kinds['cpu']->maxExtra, $kinds['ram']->maxExtra, $kinds['disk']->maxExtra]);
        $this->assertSame(128, $kinds['ram']->minStep);
        $this->assertTrue($kinds['cpu']->restarts && $kinds['ram']->restarts);
        $this->assertFalse($kinds['disk']->canShrink);
        $this->assertSame('Grow the partition inside your server to use the new space.', $kinds['disk']->note);
        $this->assertSame(['cpu' => 2, 'ram' => 2048, 'disk' => 40], app(Provisioner::class)->baseResources($service->product)->data['base']);

        // Without a disk size the VM keeps the template's disk, so extra disk cannot be sold or set.
        $service->product->update(['module_config' => ['disk' => ''] + $service->product->module_config]);
        $this->extra($service, 'disk', 10, 1);

        $this->assertSame(['cpu', 'ram'], array_keys(app(Provisioner::class)->resourceKinds($service->product->fresh())));
        $this->assertSame(['cpu' => 2, 'ram' => 2048, 'disk' => null], app(Provisioner::class)->baseResources($service->product->fresh())->data['base']);

        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertFalse($result->success);
        $this->assertSame('Set the disk size on the product first.', $result->message);
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);

        // More than the module can add is refused before anything is sent.
        $result = $this->module()->applyResources($service->fresh(), new ResourceExtras(['cpu' => 65]));

        $this->assertFalse($result->success);
        $this->assertSame('CPU cores: 65 is more than Proxmox can add. Nothing was changed.', $result->message);
        Http::assertNothingSent();
    }

    public function test_the_disk_cannot_go_below_what_the_vm_has_now(): void
    {
        $node = $this->fakeNode(['cores' => 2, 'memory' => '2048', 'scsi0' => 'local-lvm:vm-105-disk-0,size=55G']);
        $service = $this->service(['module_data' => ['vmid' => 105, 'node' => 'pve1']]);

        $limits = app(Provisioner::class)->resourceLimits($service);

        $this->assertSame(['disk'], array_keys($limits));
        $this->assertSame(15, $limits['disk']->minUnits);
        $this->assertNull($limits['disk']->maxUnits);
        $this->assertSame('Disk space can only grow.', $limits['disk']->reason);
        $this->assertSame(['GET config'], $this->calls($node));

        // A service whose VM is not made yet has no live limits, and the node is not asked.
        $pending = Service::factory()->pending()->create(['product_id' => $service->product_id, 'server_id' => $service->server_id]);
        $node->log = [];

        $this->assertSame([], app(Provisioner::class)->resourceLimits($pending));
        $this->assertSame([], $node->log);
    }

    public function test_a_client_change_asks_the_node_for_its_limits_before_any_lock(): void
    {
        Mail::fake();
        $this->setSettings(['billing.currency' => 'USD']);
        $node = $this->fakeNode(['cores' => 2, 'memory' => '2048', 'scsi0' => 'local-lvm:vm-105-disk-0,size=40G']);
        $service = $this->service(['module_data' => ['vmid' => 105, 'node' => 'pve1']]);
        ResourceOption::factory()->kind('ram', 1024)->priced(200)->create(['product_id' => $service->product_id]);
        $outside = DB::transactionLevel();

        $change = app(ResourceChanges::class)->start($service->fresh(), ['ram' => 2]);

        $this->assertSame(ResourceChange::MODE_INVOICE, $change->mode);
        $this->assertContains('GET config', $node->log, 'The limits were read from the node (the cache was cold).');
        $this->assertSame([$outside], array_values(array_unique($node->levels)), 'No request ran while the service and option rows were locked.');
    }

    public function test_a_slow_disk_resize_is_checked_again_later(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $node = $this->fakeNode(['cores' => 2, 'memory' => '2048', 'scsi0' => 'local-lvm:vm-105-disk-0,size=40G']);
        $node->resizeRuns = true;
        $service = $this->service(['module_data' => ['vmid' => 105, 'node' => 'pve1']]);
        $this->extra($service, 'disk', 10, 2);
        $started = now();

        $result = app(Provisioner::class)->applyResources($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertTrue($result->data['pending'] ?? false);
        $this->assertFalse($result->data['restart'] ?? true);
        $this->assertLessThanOrEqual(35, $started->diffInSeconds(now()), 'A staff request is not held for minutes.');
    }

    /**
     * A Proxmox node with VM 105 (cloned from template 9000) whose config changes as it is set.
     * Tasks end with "OK" at once, except a resize while resizeRuns is true. Its log lists each
     * request as "METHOD what", with the sent values, and levels the database transaction level
     * each request ran at.
     *
     * @param  array<string, mixed>  $config
     */
    private function fakeNode(array $config): \stdClass
    {
        $node = (object) ['config' => $config, 'resizeRuns' => false, 'down' => false, 'log' => [], 'levels' => []];

        Http::fake(function (Request $request) use ($node) {
            $node->levels[] = DB::transactionLevel();
            $path = (string) preg_replace('#^/api2/json/(nodes/pve1/)?#', '', (string) parse_url($request->url(), PHP_URL_PATH));
            $method = $request->method();
            $data = array_diff_key($request->data(), ['cipassword' => true]);

            if ($node->down) {
                return Http::response(['data' => null], 500);
            }

            $node->log[] = match (true) {
                str_starts_with($path, 'tasks/') => 'GET task',
                $path === 'qemu/105/config' && $method === 'GET' => 'GET config',
                $path === 'qemu/105/config' => 'PUT config '.json_encode($data),
                $path === 'qemu/105/resize' => 'PUT resize '.json_encode($data),
                default => $method.' '.$path,
            };

            if ($path === 'qemu/105/config' && $method === 'PUT') {
                $node->config = array_merge($node->config, array_intersect_key($data, array_flip(['cores', 'memory'])));
            }

            if ($path === 'qemu/105/resize') {
                $node->config[$data['disk']] = preg_replace('/size=[^,]+/', 'size='.$data['size'], (string) $node->config[$data['disk']]);
            }

            return match (true) {
                $path === 'cluster/nextid' => Http::response(['data' => '105']),
                $path === 'qemu/9000/clone' => Http::response(['data' => 'UPID:pve1:0001:clone']),
                str_starts_with($path, 'tasks/') => Http::response(['data' => $node->resizeRuns && str_contains($path, 'resize') ? ['status' => 'running'] : ['status' => 'stopped', 'exitstatus' => 'OK']]),
                $path === 'qemu/105/config' && $method === 'GET' => Http::response(['data' => $node->config]),
                $path === 'qemu/105/resize' => Http::response(['data' => 'UPID:pve1:0004:resize']),
                in_array($path, ['qemu/105/status/start', 'qemu/105/status/stop'], true) => Http::response(['data' => 'UPID:pve1:0002:power']),
                default => Http::response(['data' => null]),
            };
        });

        return $node;
    }

    /**
     * The node's requests, without the task status checks in between.
     *
     * @return list<string>
     */
    private function calls(\stdClass $node): array
    {
        return array_values(array_filter($node->log, fn (string $line): bool => $line !== 'GET task'));
    }

    /**
     * A Proxmox service whose plan has 2 cores, 2048 MB of RAM and a 40 GB disk.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function service(array $attributes = []): Service
    {
        $server = Server::factory()->create(['module' => 'proxmox', 'hostname' => 'pve.example.test', 'port' => 8006]);
        $product = Product::factory()->create(['server_module' => 'proxmox', 'server_id' => $server->id, 'module_config' => ['node' => 'pve1', 'template' => '9000', 'cores' => '2', 'memory' => '2048', 'disk' => '40']]);

        return Service::factory()->create($attributes + ['product_id' => $product->id, 'server_id' => $server->id]);
    }

    /**
     * Give the service extras of one kind: the product's option of that kind (made when missing)
     * and a row with these steps.
     */
    private function extra(Service $service, string $kind, int $step, int $steps): ServiceResourceOption
    {
        $option = ResourceOption::query()->where('product_id', $service->product_id)->where('kind', $kind)->first()
            ?? ResourceOption::factory()->kind($kind, $step)->create(['product_id' => $service->product_id]);

        return ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $option->id, 'steps' => $steps]);
    }

    private function module(): ProxmoxModule
    {
        $module = app(ExtensionManager::class)->serverModule('proxmox');
        $this->assertInstanceOf(ProxmoxModule::class, $module);

        return $module;
    }
}
