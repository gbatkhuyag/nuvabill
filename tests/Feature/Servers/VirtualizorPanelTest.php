<?php

namespace Tests\Feature\Servers;

use App\Extensions\ExtensionManager;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Virtualizor 2.0: the client server panel sections on the end-user port. Calls are pinned to the
 * service's VPS and signed; answers never carry a password; Virtualizor's switches for the VPS
 * decide the tabs; and every change is checked against what the VPS really owns.
 */
class VirtualizorPanelTest extends TestCase
{
    use FakesVirtualizor, RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeVirtualizor();
        $this->service = $this->vpsService();
        $this->service->product->update(['panel_sections' => ['firewall' => true, 'backups' => true, 'media' => true]]);
        $this->actingAs($this->service->client, 'web');
    }

    public function test_end_user_calls_are_pinned_to_the_services_vps_and_signed(): void
    {
        $this->fakeVirtualizor(['monitor' => ['monthly_data' => []], 'bandwidth' => ['bandwidth' => []]]);

        $this->getJson(route('client.services.sections.show', [$this->service, 'graphs']).'?svs=99&act=users&uid=1&month='.Date::now()->format('Ym'))->assertOk();

        $monitor = $this->sent('monitor')[0];
        $this->assertSame(4083, $monitor['port']);
        $this->assertSame('monitor', $monitor['query']['act']);
        $this->assertSame('77', $monitor['query']['svs']);
        $this->assertSame('json', $monitor['query']['api']);
        $this->assertSame('127.0.0.1', $monitor['query']['SET_REMOTE_IP']);
        $this->assertSame(Date::now()->format('Ym'), $monitor['query']['show']);
        $this->assertArrayNotHasKey('uid', $monitor['query']);

        foreach (array_filter($this->api->calls, fn (array $call): bool => $call['port'] === 4083) as $call) {
            $this->assertArrayNotHasKey('adminapipass', $call['query']);
            $this->assertStringNotContainsString(self::API_PASSWORD, $call['url']);
            $this->assertSame('77', $call['query']['svs']);
        }
    }

    public function test_passwords_in_virtualizors_answers_never_reach_the_browser_or_the_cache(): void
    {
        $this->fakeVirtualizor(['rescue' => ['rescue_enabled' => 0, 'password' => 'RootSecret99']]);
        $texts = '';

        foreach (['overview', 'console', 'settings', 'network', 'rescue'] as $section) {
            $texts .= $this->read($section)->assertOk()->getContent();
        }

        $texts .= $this->get(route('client.services.show', $this->service))->getContent();
        $texts .= json_encode(Cache::get("nuvabill:vz:manage:{$this->service->id}:77"));

        $this->assertStringNotContainsString('RootSecret99', $texts);
        $this->assertStringNotContainsString('Vnc7pass', $texts);
        $this->assertStringNotContainsString('vm_pass', $texts);
    }

    public function test_virtualizors_switches_decide_the_tabs_and_the_actions(): void
    {
        $page = $this->get(route('client.services.show', $this->service))->assertOk();

        foreach (['overview', 'console', 'graphs', 'reinstall', 'network', 'firewall', 'backups', 'rescue', 'ssh-keys', 'media', 'settings', 'activity'] as $key) {
            $page->assertSee('aria-controls="panel-'.$key.'"', false);
        }

        // Reinstall turned off for the VPS.
        $off = $this->service(['disable_os_reinstall' => 1]);
        $this->get(route('client.services.show', $off))->assertDontSee('aria-controls="panel-reinstall"', false);
        $this->postJson(route('client.services.sections.action', [$off, 'reinstall', 'run']), [
            'template' => '100', 'password' => 'Better12345', 'password_confirmation' => 'Better12345', 'confirm' => '1',
        ])->assertForbidden();
        $this->assertSame([], $this->sent('rebuild'));

        // rDNS off: the addresses show, without names, and no name can be set.
        $noRdns = $this->service(['enable_rdns' => 0]);
        $this->read('network', $noRdns)->assertOk()->assertJsonPath('data.rdns', false)->assertJsonPath('data.can.rdns-set', false);
        $this->postJson(route('client.services.sections.action', [$noRdns, 'network', 'rdns-set']), ['ip' => '203.0.113.7', 'ptr' => 'mail.example.com'])->assertForbidden();
        $this->assertSame([], $this->sent('rdns'));

        // Managed by the host: power only.
        $managed = $this->service([], ['admin_managed' => '1']);
        $page = $this->get(route('client.services.show', $managed))->assertOk();
        $page->assertSee('aria-controls="panel-overview"', false);

        foreach (['console', 'graphs', 'reinstall', 'network', 'settings'] as $key) {
            $page->assertDontSee('aria-controls="panel-'.$key.'"', false);
        }

        $this->read('settings', $managed)->assertForbidden();
    }

    public function test_sections_of_the_whole_virtualizor_user_hide_when_it_has_vps_of_other_clients(): void
    {
        $this->vpsService(attributes: ['module_data' => ['vpsid' => '80']], server: $this->service->server, client: $this->client('Raz'));
        $this->fakeVirtualizor(['listvs' => ['vs' => ['77' => ['vpsid' => '77', 'uid' => '58'], '80' => ['vpsid' => '80', 'uid' => '58']]]]);
        Cache::flush();

        $page = $this->get(route('client.services.show', $this->service))->assertOk();
        $page->assertDontSee('aria-controls="panel-ssh-keys"', false)->assertDontSee('aria-controls="panel-firewall"', false);
        $page->assertSee('aria-controls="panel-network"', false);

        $this->read('ssh-keys')->assertForbidden();
        $this->postJson(route('client.services.sections.action', [$this->service, 'firewall', 'apply']), ['plan_id' => '4'])->assertForbidden();
        $this->assertSame([], $this->sent('sshkeys'));
        $this->assertSame([], $this->sent('firewallplan'));

        // The tasks stay; the account's logs do not.
        $this->fakeVirtualizor(['listvs' => ['vs' => ['77' => ['vpsid' => '77', 'uid' => '58'], '80' => ['vpsid' => '80', 'uid' => '58']]], 'ctasks' => ['tasks' => []]]);
        $this->read('activity')->assertOk()->assertJsonMissingPath('data.logs');
        $this->assertSame([], $this->sent('logs'));
        $this->assertSame(1, ActivityLog::query()->where('description', 'like', '%hides SSH keys, firewall plans and logs%')->count());
    }

    public function test_an_action_on_the_whole_virtualizor_user_checks_its_vps_again_live(): void
    {
        $keys = fn (array $query, array $data): array => $data !== [] ? ['done' => 1] : ['ssh_keys' => ['3' => ['keyid' => 3, 'name' => 'Mer Las laptop']]];
        $this->fakeVirtualizor(['sshkeys' => $keys]);

        // Read while the user held only this client's VPS: that answer is kept for 10 minutes.
        $this->read('ssh-keys')->assertOk()->assertJsonPath('data.keys.0.id', '3');

        // Staff then put a VPS of another client under the same Virtualizor user.
        $this->vpsService(attributes: ['module_data' => ['vpsid' => '80']], server: $this->service->server, client: $this->client('Raz'));
        $this->fakeVirtualizor([
            'sshkeys' => $keys,
            'listvs' => ['vs' => ['77' => ['vpsid' => '77', 'uid' => '58'], '80' => ['vpsid' => '80', 'uid' => '58']]],
        ]);

        $this->postJson(route('client.services.sections.action', [$this->service, 'ssh-keys', 'delete']), ['id' => '3'])->assertForbidden();
        $this->assertSame([], $this->sent('sshkeys'), 'Nothing of the user is read or changed.');
        $this->assertCount(1, $this->sent('listvs'));

        // The new answer is kept, so the tab hides too.
        $this->get(route('client.services.show', $this->service))->assertOk()->assertDontSee('aria-controls="panel-ssh-keys"', false);
        $this->read('ssh-keys')->assertForbidden();
    }

    public function test_reverse_dns_only_for_the_vps_own_addresses(): void
    {
        $this->fakeVirtualizor(['rdns' => fn (array $query, array $data): array => $data !== [] || isset($query['delete']) ? ['done' => 1] : ['rdns' => [
            '5' => ['id' => 5, 'ip' => '203.0.113.7', 'content' => 'vps1.example.com'],
            '6' => ['id' => 6, 'ip' => '192.0.2.50', 'content' => 'someone-else.example.net'],
            '7' => ['id' => 7, 'ip' => '2001:db8:77::10', 'content' => 'v6.example.com'],
        ]]]);

        $network = $this->read('network')->assertOk();
        $network->assertJsonPath('data.rdns', true)
            ->assertJsonPath('data.ips.0', ['ip' => '203.0.113.7', 'version' => 4, 'primary' => true, 'rdns' => 'vps1.example.com'])
            ->assertJsonPath('data.subnets', [['cidr' => '2001:db8:77::/64']]);
        $this->assertStringNotContainsString('someone-else', $network->getContent());

        $set = fn (string $ip, string $ptr = 'mail.example.com') => $this->postJson(route('client.services.sections.action', [$this->service, 'network', 'rdns-set']), ['ip' => $ip, 'ptr' => $ptr]);
        $set('192.0.2.50')->assertStatus(422)->assertJsonPath('errors.ip.0', 'Choose an IP address of this server.');
        $set('2001:db8:78::1')->assertStatus(422);
        $set('203.0.113.7', 'not a name')->assertStatus(422)->assertJsonStructure(['errors' => ['ptr']]);
        $set('203.0.113.7', 'Mail.Example.com')->assertOk()->assertJsonPath('message', 'Reverse DNS saved.');
        $set('2001:db8:77::abcd')->assertOk();

        $posts = array_column($this->sent('rdns'), 'data');
        $this->assertContains(['rdns' => 1, 'rdns_ip' => '203.0.113.7', 'rdns_domain' => 'mail.example.com'], $posts);
        $this->assertContains(['rdns' => 1, 'rdns_ip' => '2001:db8:77::abcd', 'rdns_domain' => 'mail.example.com'], $posts);
        $this->assertTrue(ActivityLog::query()->where('description', 'like', '%203.0.113.7 → mail.example.com%')->exists());

        // A record is removed by the address, found again in a fresh list; never another IP's record.
        $delete = fn (string $ip) => $this->postJson(route('client.services.sections.action', [$this->service, 'network', 'rdns-delete']), ['ip' => $ip, 'id' => '6']);
        $delete('192.0.2.50')->assertStatus(422);
        $delete('2001:db8:77::10')->assertOk()->assertJsonPath('message', 'Reverse DNS removed.');
        $this->assertSame(['7'], array_values(array_filter(array_map(fn (array $call): ?string => $call['query']['delete'] ?? null, $this->sent('rdns')))));
    }

    public function test_a_reinstall_needs_its_confirm_box_an_allowed_template_and_a_reinstall_left(): void
    {
        $this->fakeVirtualizor(['rebuild' => ['done' => 1]]);
        $this->service->product->update(['module_config' => ['virt' => 'kvm', 'os_id' => '100', 'osreinstall_limit' => '1']]);
        $run = fn (array $input) => $this->postJson(route('client.services.sections.action', [$this->service, 'reinstall', 'run']), $input + [
            'template' => '100', 'password' => 'Better12345', 'password_confirmation' => 'Better12345',
        ]);

        $run([])->assertStatus(422)->assertJsonStructure(['errors' => ['confirm']]);
        $run(['template' => '200', 'confirm' => '1'])->assertStatus(422)->assertJsonPath('message', 'Choose an operating system from the list.');
        $run(['confirm' => '1'])->assertOk();
        $run(['confirm' => '1'])->assertStatus(422)->assertJsonPath('message', 'You have used all OS reinstalls for this month. Contact support if you need another one.');

        $this->assertCount(1, $this->sent('rebuild'));
        $this->assertSame('Better12345', $this->service->fresh()->password);
        Mail::assertSent(TemplatedMessage::class, 1);
    }

    public function test_graphs_take_a_month_in_range_and_send_at_most_400_points(): void
    {
        $month = Date::now()->startOfMonth();
        $rows = [];

        foreach (range(0, 743) as $hour) {
            $rows[(string) $month->copy()->addHours($hour)->getTimestamp()] = ['cpu' => $hour % 100, 'ram' => 512, 'disk' => 10, 'net_in' => 100, 'net_out' => 50, 'io_read' => 1, 'io_write' => 2];
        }

        $this->fakeVirtualizor([
            'monitor' => ['monthly_data' => $rows, 'vps_create_date' => $month->copy()->subMonths(2)->getTimestamp()],
            'bandwidth' => ['bandwidth' => ['limit_gb' => 1000, 'used_gb' => 2, 'in' => [1 => 100, 2 => 200], 'out' => [1 => 50, 2 => 60]]],
        ]);

        $graphs = $this->read('graphs', query: ['month' => $month->format('Ym')])->assertOk();
        $series = $graphs->json('data.series');

        $this->assertSame(['cpu', 'ram', 'disk', 'net_in', 'net_out', 'io_read', 'io_write'], array_column($series, 'key'));

        foreach ($series as $one) {
            $this->assertLessThanOrEqual(400, count($one['points']));
            $this->assertGreaterThan(300, count($one['points']));
        }

        $graphs->assertJsonPath('data.period.param', 'month')
            ->assertJsonPath('data.bars.points.0', [$month->getTimestamp(), 100, 50]);
        $this->assertCount(3, $graphs->json('data.period.options'));

        $this->read('graphs', query: ['month' => '2026-1'])->assertStatus(422);
        $this->read('graphs', query: ['month' => $month->copy()->addMonth()->format('Ym')])->assertStatus(422);
        $this->read('graphs', query: ['month' => $month->copy()->subMonths(3)->format('Ym')])->assertStatus(422)->assertJsonPath('errors.month.0', 'Choose a month from the list.');
        $this->read('graphs', query: ['month' => $month->copy()->subMonths(13)->format('Ym')])->assertStatus(422);
    }

    public function test_graphs_of_a_month_at_five_minute_steps_reach_its_last_day(): void
    {
        $this->travelTo(Date::create(2026, 11, 30, 12));
        $month = Date::now()->startOfMonth();
        $lastDay = $month->copy()->endOfMonth()->startOfDay();
        $rows = [];

        // 8,640 rows: the whole of November. The last day has its own CPU figure.
        foreach (range(0, 8639) as $step) {
            $at = $month->copy()->addMinutes($step * 5);
            $rows[(string) $at->getTimestamp()] = ['cpu' => $at->gte($lastDay) ? 90 : 10, 'ram' => 512, 'disk' => 10, 'net_in' => 100, 'net_out' => 50, 'io_read' => 1, 'io_write' => 2];
        }

        $this->fakeVirtualizor([
            'monitor' => ['monthly_data' => $rows, 'vps_create_date' => $month->copy()->subMonths(2)->getTimestamp()],
            'bandwidth' => ['bandwidth' => []],
        ]);

        $series = collect($this->read('graphs', query: ['month' => $month->format('Ym')])->assertOk()->json('data.series'))->keyBy('key')->all();
        $this->assertCount(7, $series);

        foreach ($series as $key => $one) {
            $points = $one['points'];
            $this->assertLessThanOrEqual(400, count($points), $key);
            $this->assertSame($month->getTimestamp(), $points[0][0], $key);
            $this->assertGreaterThanOrEqual($lastDay->getTimestamp(), $points[array_key_last($points)][0], "{$key}: the newest days are kept.");
        }

        $cpu = $series['cpu']['points'];
        $this->assertEquals(10, $cpu[0][1]);
        $this->assertEquals(90, $cpu[array_key_last($cpu)][1]);
    }

    public function test_mounting_an_iso_sends_the_settings_page_back_unchanged(): void
    {
        $settings = ['acpi' => '1', 'apic' => '1', 'vnc' => '1', 'vnc_keymap' => 'en-us', 'kvm_vga' => '1', 'acceleration' => '1', 'nic_type' => 'virtio', 'virtio' => '1', 'timezone' => 'UTC'];
        $page = $settings;
        $this->fakeVirtualizor(['hvmsettings' => function (array $query, array $data) use (&$page): array {
            return $data !== [] ? ['done' => 1] : [
                'vps' => $page + ['vpsid' => '77', 'iso' => '0', 'boot' => 'cda'],
                'isos' => ['11' => ['isoid' => 11, 'name' => 'ubuntu-24.04.iso'], '12' => ['isoid' => 12, 'name' => 'debian-12.iso']],
            ];
        }]);

        $this->read('media')->assertOk()
            ->assertJsonPath('data.isos', [['id' => '11', 'name' => 'ubuntu-24.04.iso'], ['id' => '12', 'name' => 'debian-12.iso']])
            ->assertJsonPath('data.mounted', null)
            ->assertJsonPath('data.boot', 'disk');

        $this->postJson(route('client.services.sections.action', [$this->service, 'media', 'mount']), ['iso' => '99'])->assertStatus(422);
        $this->postJson(route('client.services.sections.action', [$this->service, 'media', 'mount']), ['iso' => '12'])
            ->assertOk()
            ->assertJsonPath('message', 'The ISO image is mounted. Restart the server for this to work.');

        $post = collect($this->sent('hvmsettings'))->last()['data'];
        $this->assertSame(['hvmsettings' => 1] + $settings + ['isos' => '12', 'boot_ord_val' => 'cda'], $post);
        $this->read('media')->assertJsonPath('data.restart_needed', true);

        $this->postJson(route('client.services.sections.action', [$this->service, 'media', 'boot']), ['order' => 'cdrom'])->assertOk();
        $this->assertSame('dca', collect($this->sent('hvmsettings'))->last()['data']['boot_ord_val']);

        // A page that does not list every setting is never sent back.
        unset($page['timezone']);
        $posts = count($this->sent('hvmsettings'));
        $this->postJson(route('client.services.sections.action', [$this->service, 'media', 'mount']), ['iso' => '11'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This change cannot be made here. Contact support.');
        $this->assertCount($posts + 1, $this->sent('hvmsettings'));
    }

    public function test_ssh_keys_must_be_safe_public_keys(): void
    {
        $this->fakeVirtualizor([
            'sshkeys' => fn (array $query, array $data): array => $data !== [] ? ['done' => 1] : ['ssh_keys' => ['3' => ['keyid' => 3, 'name' => 'Raz laptop']]],
            'addsshkey' => ['done' => 1],
        ]);
        $add = fn (string $key) => $this->postJson(route('client.services.sections.action', [$this->service, 'ssh-keys', 'add']), ['name' => 'Mer Las laptop', 'key' => $key]);
        $ed25519 = 'ssh-ed25519 '.base64_encode(self::field('ssh-ed25519').self::field(random_bytes(32)));
        $rsa1024 = 'ssh-rsa '.base64_encode(self::field('ssh-rsa').self::field("\x01\x00\x01").self::field("\x00".chr(0xC1).random_bytes(127)));
        $dss = 'ssh-dss '.base64_encode(self::field('ssh-dss').self::field(random_bytes(20)));

        $add($dss)->assertStatus(422)->assertJsonPath('errors.key.0', 'DSA keys are not safe anymore. Use an ed25519 key.');
        $add($rsa1024)->assertStatus(422)->assertJsonPath('errors.key.0', 'RSA keys need 2048 bits or more. Use an ed25519 key.');
        $add('ssh-ed25519 not*base64')->assertStatus(422);
        $add('ssh-ed25519 '.base64_encode(self::field('ssh-rsa').self::field(random_bytes(32))))->assertStatus(422);
        $add("-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----")->assertStatus(422);
        $this->assertSame([], $this->sent('addsshkey'));

        $add($ed25519.' mer@laptop')->assertOk();
        $this->assertSame(['addsshkey' => 1, 'name' => 'Mer Las laptop', 'value' => $ed25519], $this->sent('addsshkey')[0]['data']);

        // Keys go on the server only from the account's own list.
        $this->postJson(route('client.services.sections.action', [$this->service, 'ssh-keys', 'apply']), ['keys' => ['3', '9']])->assertStatus(422);
        $this->postJson(route('client.services.sections.action', [$this->service, 'ssh-keys', 'apply']), ['keys' => ['3']])->assertOk();
        $this->assertSame(['addkeyvps' => 1, 'ssh_keys' => ['3']], collect($this->sent('sshkeys'))->last()['data']);
    }

    public function test_firewall_plans_backups_and_rescue_keep_to_the_vps_own_items(): void
    {
        $this->fakeVirtualizor([
            'firewallplan' => fn (array $query, array $data): array => $data !== [] ? ['done' => 1] : ['current_firewall_plan' => '0', 'firewall_plans' => [
                '4' => ['fwid' => 4, 'fwp_name' => 'Web', 'default_policy' => 'drop', 'uid' => '58', 'rules' => [['direction' => 'in', 'protocol' => 'tcp', 'port' => '443', 'action' => 'accept']]],
                '5' => ['fwid' => 5, 'fwp_name' => 'Host plan', 'default_policy' => 'accept', 'uid' => '0', 'rules' => []],
            ]],
            'addfirewallplan' => ['done' => 1],
            'backup2' => fn (array $query, array $data): array => $data !== [] ? ['done' => 1] : ['backup_file_list' => ['b1' => ['name' => '2026-10-01_v77.tar.gz', 'time' => 1790000000, 'size' => 1048576]], 'backup_limit' => 2],
            'rescue' => fn (array $query, array $data): array => $data !== [] ? ['done' => 1] : ['rescue_enabled' => 0],
            'power' => ['done' => true, 'vsop' => ['status' => ['77' => 1]]],
        ]);
        $action = fn (string $section, string $name, array $input = []) => $this->postJson(route('client.services.sections.action', [$this->service, $section, $name]), $input);

        $this->read('firewall')->assertOk()->assertJsonPath('data.plans.0.own', true)->assertJsonPath('data.plans.1.own', false);
        $action('firewall', 'apply', ['plan_id' => '9'])->assertStatus(422);
        $action('firewall', 'apply', ['plan_id' => '4'])->assertOk();
        $action('firewall', 'delete', ['plan_id' => '5', 'confirm' => '1'])->assertStatus(422);
        $action('firewall', 'create', ['name' => 'SSH', 'default_policy' => 'drop', 'rules' => [['direction' => 'in', 'protocol' => 'icmp', 'port' => '22', 'action' => 'accept']]])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['rules.0.port']]);
        $action('firewall', 'create', ['name' => 'SSH', 'default_policy' => 'drop', 'rules' => [['direction' => 'in', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.0/24', 'action' => 'accept', 'extra' => 'x']]])->assertOk();
        $this->assertSame([['direction' => 'in', 'protocol' => 'tcp', 'port' => '22', 'source' => '203.0.113.0/24', 'action' => 'accept']], json_decode($this->sent('addfirewallplan')[0]['data']['api_firewall_rules'], true));

        $this->read('backups')->assertOk()->assertJsonPath('data.items.0.id', '2026-10-01_v77.tar.gz')->assertJsonPath('data.limit', 2);
        $action('backups', 'restore', ['id' => '2026-09-01_v12.tar.gz', 'confirm' => '1'])->assertStatus(422);
        $action('backups', 'restore', ['id' => '2026-10-01_v77.tar.gz'])->assertStatus(422);
        $action('backups', 'restore', ['id' => '2026-10-01_v77.tar.gz', 'confirm' => '1'])->assertOk();
        $this->assertSame(['bkid' => '2026-10-01_v77.tar.gz', 'restore' => 1], collect($this->sent('backup2'))->last()['data']);

        $action('rescue', 'enable', ['password' => 'Rescue12345', 'reboot' => '1', 'confirm' => '1'])->assertOk()->assertJsonPath('pending', 'restart');
        $this->assertSame(['enablerescue' => 1, 'password' => 'Rescue12345', 'conf_password' => 'Rescue12345'], collect($this->sent('rescue'))->last()['data']);
        $this->assertCount(1, $this->sent('power'));
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%Rescue12345%')->exists());
    }

    public function test_when_the_end_user_port_does_not_answer_only_the_basic_sections_stay(): void
    {
        $this->fakeVirtualizor(['vpsmanage' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to vz.example.test port 4083')]);
        $other = $this->vpsService(server: $this->service->server, attributes: ['module_data' => ['vpsid' => '78']]);

        $page = $this->get(route('client.services.show', $this->service))->assertOk();

        foreach (['overview', 'console', 'reinstall', 'settings'] as $key) {
            $page->assertSee('aria-controls="panel-'.$key.'"', false);
        }

        foreach (['graphs', 'network', 'backups', 'rescue', 'ssh-keys', 'activity'] as $key) {
            $page->assertDontSee('aria-controls="panel-'.$key.'"', false);
        }

        $this->read('network')->assertForbidden();

        // Staff see why once a day for the server, not on every page.
        $this->travel(6)->minutes();
        $this->get(route('client.services.show', $this->service))->assertOk();
        $this->actingAs($other->client, 'web')->get(route('client.services.show', $other))->assertOk();
        $this->assertSame(1, ActivityLog::query()->where('description', 'like', '%shows only basic features%')->count());
        $this->assertCount(3, $this->sent('vpsmanage'));
    }

    public function test_an_answer_for_a_vps_with_another_uuid_is_refused(): void
    {
        $this->read('overview')->assertOk();
        $this->assertSame(self::VPS_UUID, $this->service->fresh()->module_data['vps_uuid']);

        // VPS 77 was deleted and its ID given to a new VPS.
        $this->fakeVirtualizor(['vpsmanage' => $this->vpsmanage(vps: ['uuid' => 'ffff0000-1111-2222-3333-444455556666'])]);
        Cache::flush();

        $this->read('overview')->assertStatus(422)->assertJsonPath('message', 'This server changed. Contact support.');
        // The panel keeps only what needs no answer about the VPS.
        $this->read('network')->assertForbidden();
        $this->assertSame([], $this->sent('rdns'));
        $this->assertSame(1, ActivityLog::query()->where('description', 'like', '%now has UUID ffff0000-1111-2222-3333-444455556666%')->count());
    }

    public function test_the_console_uses_the_vnc_password_from_the_admin_api_on_the_end_user_port(): void
    {
        $module = app(ExtensionManager::class)->serverModule('virtualizor');

        $session = $module->consoleSession($this->service, $this->context($this->service));

        $this->assertSame('wss://vz.example.test:4083/novnc/?virttoken=77', $session->url);
        $this->assertSame(['password' => 'Vnc7pass'], $session->credentials);
        $this->assertSame(4085, $this->sent('vnc')[0]['port']);

        // Virtualizor can hide the VNC details from clients; the browser console still works.
        $hidden = $this->service(['hide_enduser_vnc_info' => 1]);
        $this->read('console', $hidden)->assertOk()->assertJsonPath('data.details', false);
        $this->postJson(route('client.services.sections.action', [$hidden, 'console', 'details']))->assertForbidden();
    }

    /**
     * Another service of the client with VPS 78, whose end-user panel has these switches.
     *
     * @param  array<string, mixed>  $flags
     * @param  array<string, mixed>  $vps
     */
    private function service(array $flags, array $vps = []): Service
    {
        $answers = $this->api->answers;
        $manage = $answers['vpsmanage'];
        $this->api->answers['vpsmanage'] = fn (array $query, array $data): array => $query['svs'] === '78' ? $this->vpsmanage($flags, $vps)($query) : $manage($query, $data);

        return $this->vpsService(attributes: ['module_data' => ['vpsid' => '78']], server: $this->service->server, client: $this->service->client);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function read(string $section, ?Service $service = null, array $query = []): TestResponse
    {
        return $this->getJson(route('client.services.sections.show', [$service ?? $this->service, $section]).($query !== [] ? '?'.http_build_query($query) : ''));
    }

    /**
     * One length-prefixed field of an SSH public key.
     */
    private static function field(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }
}
