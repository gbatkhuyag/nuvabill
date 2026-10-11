<?php

namespace Tests\Feature\Servers;

use App\Billing\Cart;
use App\Billing\OrderPlacer;
use App\Billing\PaymentRecorder;
use App\Billing\ResourceChanges;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Jobs\ApplyServiceResources;
use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Models\ServiceResourceOption;
use App\Provisioning\OrderPaymentGate;
use App\Provisioning\Provisioner;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Nuvabill\Extensions\Virtualizor\ResourceAddons;
use Nuvabill\Extensions\Virtualizor\ResourceTotals;
use stdClass;
use Tests\TestCase;

/**
 * Extra CPU cores, RAM, disk, IPv4 and more sold with resource options (sliders) on Virtualizor
 * VPS: a new VPS is made with its plan's base plus the paid extras and checked before it is
 * activated, a create without a clear answer is never sent twice, a live VPS is set to new totals
 * without its plan, and disk space only grows.
 */
class VirtualizorResourcesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The step of each option the test products sell.
     */
    private const STEPS = ['cpu' => 1, 'ram' => 2048, 'disk' => 40, 'ipv4' => 1];

    private ?stdClass $api = null;

    private Server $server;

    private int $clients = 0;

    /**
     * The fake master's VPS, by ID, as its Admin API lists them. Changes sent to it are kept.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $live = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSettings(['billing.currency' => 'USD']);
        $this->server = Server::factory()->create(['module' => 'virtualizor', 'hostname' => 'vz.example.test', 'port' => 4085, 'password' => 'S3cretApiPass99']);
    }

    public function test_the_kinds_are_local_and_the_base_comes_from_the_product_or_its_plan(): void
    {
        $api = $this->fakeVirtualizor();
        $product = $this->product(['num_ips6' => '0']);

        $kinds = app(Provisioner::class)->resourceKinds($product);

        $this->assertSame([], $api->calls);
        $this->assertSame(
            ['cpu' => 'core', 'ram' => 'MB', 'disk' => 'GB', 'ipv4' => 'IP', 'ipv6' => 'IP', 'bandwidth' => 'GB', 'virtualizor_ipv6_subnet' => '', 'virtualizor_network_speed' => 'KB/s'],
            array_map(fn ($kind): string => $kind->unit, $kinds),
        );
        $this->assertFalse($kinds['disk']->canShrink);
        $this->assertSame('Grow the partition inside your server to use the new space.', $kinds['disk']->note);
        $this->assertTrue($kinds['cpu']->restarts && $kinds['ram']->restarts);
        $this->assertSame(['IPv6 subnets', 'Network speed'], [$kinds['virtualizor_ipv6_subnet']->label, $kinds['virtualizor_network_speed']->label]);

        $base = app(Provisioner::class)->baseResources($product);
        app(Provisioner::class)->baseResources($product);

        $this->assertTrue($base->success, $base->message);
        $this->assertSame(['cpu' => 2, 'ram' => 4096, 'disk' => 80, 'ipv4' => 1, 'ipv6' => 0, 'bandwidth' => 2000, 'virtualizor_ipv6_subnet' => 1, 'virtualizor_network_speed' => 0], $base->data['base']);
        // The plan is kept for a while: one call for both reads.
        $this->assertSame(['plans'], array_column($api->calls, 'kind'));

        // Traffic and network speed set to 0 on the product have no limit, so they are not sold.
        $unlimited = app(Provisioner::class)->resourceKinds($this->product(['bandwidth' => '0', 'network_speed' => '0']));
        $this->assertArrayNotHasKey('bandwidth', $unlimited);
        $this->assertArrayNotHasKey('virtualizor_network_speed', $unlimited);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Set "IP pool for extra IPv4" on the product to a whole number of 1 or more, or leave it empty.');
        app(Provisioner::class)->resourceKinds($this->product(['ipv4_pool_id' => 'five']));
    }

    public function test_a_vps_with_extras_is_made_with_plan_base_plus_extras_and_checked_before_activation(): void
    {
        $noteBeforeRequest = null;
        $api = $this->fakeVirtualizor([
            'addvs' => function () use (&$noteBeforeRequest): array {
                $noteBeforeRequest = Service::query()->latest('id')->first();

                return ['done' => 1, 'vs_info' => ['vpsid' => '90', 'uid' => '58', 'ips' => ['203.0.113.9', '203.0.113.10']]];
            },
            'vps' => $this->vps(['cores' => '3', 'ram' => '6144', 'ips' => ['1' => '203.0.113.9', '2' => '203.0.113.10']]),
        ]);
        $service = $this->orderedVps(['cpu' => 1, 'ram' => 1, 'ipv4' => 1]);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['plans', 'addvs', 'vps'], array_column($api->calls, 'kind'));
        $post = $api->calls[1]['data'];
        $this->assertSame([3, 3, 6144, 2], [$post['plid'], $post['cores'], $post['ram'], $post['num_ips']]);
        $this->assertArrayNotHasKey('space', $post);

        // Noted before the request, with the root password encrypted in the note, not on the service.
        $note = $noteBeforeRequest->module_data[ResourceAddons::KEY];
        $this->assertSame('requested', $note['state']);
        $this->assertSame(['cpu' => 1, 'ram' => 2048, 'ipv4' => 1], $note['extras']);
        $this->assertSame(['cores' => 3, 'ram' => 6144, 'num_ips' => 2], $note['totals']);
        $this->assertSame($service->client->email, $note['email']);
        $this->assertNull($noteBeforeRequest->password);
        $this->assertStringNotContainsString($post['rootpass'], json_encode($noteBeforeRequest->module_data));
        $this->assertSame($post['rootpass'], ResourceAddons::password($noteBeforeRequest));

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(['90', '58', ['203.0.113.9', '203.0.113.10']], [$service->module_data['vpsid'], $service->module_data['uid'], $service->module_data['ips']]);
        $this->assertSame('verified', $service->module_data[ResourceAddons::KEY]['state']);
        $this->assertArrayNotHasKey('secret', $service->module_data[ResourceAddons::KEY]);
        $this->assertSame($post['rootpass'], $service->password);
    }

    public function test_a_vps_without_extras_is_made_exactly_as_before(): void
    {
        $api = $this->fakeVirtualizor();
        $service = $this->orderedVps([]);
        ServiceResourceOption::factory()->cancelled()->create(['service_id' => $service->id, 'resource_option_id' => $this->option($service, 'ram')->id]);

        $this->assertTrue(app(Provisioner::class)->create($service)->success);

        $this->assertSame(['addvs'], array_column($api->calls, 'kind'));
        $this->assertSame(['addvps', 'hostname', 'node_select', 'num_ips', 'osid', 'plid', 'rootpass', 'user_email', 'user_pass', 'virt'], $this->keys($api->calls[0]['data']));
        $this->assertArrayNotHasKey(ResourceAddons::KEY, $service->fresh()->module_data);
    }

    public function test_extras_on_a_product_without_a_plan_use_the_product_settings(): void
    {
        $api = $this->fakeVirtualizor(['vps' => $this->vps(['ram' => '8192', 'space' => '120'])]);
        $service = $this->orderedVps(['ram' => 2, 'disk' => 1], config: ['plan_id' => null, 'ram' => '4096', 'disk' => '80']);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['addvs', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame(8192, $api->calls[0]['data']['ram']);
        $this->assertSame([['size' => 120]], $api->calls[0]['data']['space']);

        // Without a plan or a product setting the base is not known, so nothing is sent.
        $api = $this->fakeVirtualizor();
        $unknown = $this->orderedVps(['cpu' => 1], config: ['plan_id' => null]);

        $this->assertSame('CPU cores: the plan amount of this product is not known, so its extras cannot be added. Set it on the product or in its Virtualizor plan. Nothing was changed.', app(Provisioner::class)->create($unknown)->message);
        $this->assertSame([], $api->calls);
    }

    public function test_a_vps_with_paid_extras_waits_for_the_order_invoice_then_is_made(): void
    {
        $api = $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '3'])]);
        $service = $this->orderedVps(['cpu' => 1], paid: false);
        $service->product->update(['auto_setup' => AutoSetup::OnOrder]);
        $invoice = $service->order->invoice;
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'service_id' => $service->id, 'type' => InvoiceItem::TYPE_SERVICE, 'description' => 'VPS - vps1.example.com', 'amount' => 2500, 'period_start' => today(), 'period_end' => today()->addMonth()->subDay()]);

        // The setup when the order was placed waits for the payment: nothing is sent.
        $this->assertSame('The extras on this order are not paid yet. The server is made once the order invoice is paid.', app(Provisioner::class)->create($service)->message);
        $this->assertSame([], $api->calls);
        $this->assertTrue($service->fresh()->module_data[OrderPaymentGate::WAITING]);

        app(PaymentRecorder::class)->record($invoice->fresh(), 3000, 'banktransfer', 'BANK-1');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame(['plans', 'addvs', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame(3, $api->calls[1]['data']['cores']);
        $this->assertArrayNotHasKey(OrderPaymentGate::WAITING, $service->fresh()->module_data);
    }

    public function test_a_vps_with_extras_ordered_and_paid_from_the_wallet_at_once_is_made_once(): void
    {
        $api = $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '3'])]);

        $order = $this->placeVpsOrder(['cpu' => 1], credit: 3000);
        $service = $order->services()->sole();

        $this->assertSame(InvoiceStatus::Paid, $order->invoice->fresh()->status);
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(['plans', 'addvs', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame(3, $api->calls[1]['data']['cores']);
    }

    public function test_an_unclear_create_is_never_sent_twice(): void
    {
        $api = $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $service = $this->orderedVps(['cpu' => 1]);

        $first = app(Provisioner::class)->create($service);
        $rootPassword = $api->calls[1]['data']['rootpass'];

        $this->assertFalse($first->success);
        $this->assertStringContainsString('no clear answer', $first->message);
        $this->assertSame('requested', $service->fresh()->module_data[ResourceAddons::KEY]['state']);
        // The pending service shows no root password; the note keeps it for a VPS found later.
        $this->assertNull($service->fresh()->password);
        $this->assertSame($rootPassword, ResourceAddons::password($service->fresh()));

        // Virtualizor lists another VPS with a similar name: still unclear, so nothing is sent.
        $api = $this->fakeVirtualizor(['byname' => ['vs' => ['12' => ['vpsid' => '12', 'hostname' => 'vps1.example.com', 'email' => 'someone@example.net']]]]);
        $this->assertFalse(app(Provisioner::class)->create($service->fresh())->success);
        $this->assertSame(['byname'], array_column($api->calls, 'kind'));

        // Nothing is listed yet, but the request was only just sent.
        $api = $this->fakeVirtualizor(['byname' => ['vs' => []]]);
        $this->assertFalse(app(Provisioner::class)->create($service->fresh())->success);
        $this->assertSame(['byname'], array_column($api->calls, 'kind'));
        $this->assertSame(['act' => 'vs', 'search' => '1', 'vpshostname' => 'vps1.example.com', 'page' => '1', 'reslen' => '50'], $this->withoutCredentials($api->calls[0]['query']));

        // The VPS it made shows up: it is taken and checked, not made again.
        $api = $this->fakeVirtualizor([
            'byname' => ['vs' => ['90' => ['vpsid' => '90', 'hostname' => 'VPS1.example.com', 'email' => 'mer.las@example.com']]],
            'vps' => $this->vps(['cores' => '3']),
        ]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['byname', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame('90', $service->fresh()->module_data['vpsid']);
        $this->assertSame($rootPassword, $service->fresh()->password);
    }

    public function test_a_vps_found_after_an_unclear_create_is_matched_by_the_email_it_was_made_with(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $service = $this->orderedVps(['cpu' => 1]);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);

        // The client changed their email since, and another service already uses VPS 12 of that name.
        $service->client->update(['email' => 'new.address@example.org']);
        $other = $this->orderedVps([]);
        $other->update(['status' => ServiceStatus::Active, 'module_data' => ['vpsid' => '12']]);
        $api = $this->fakeVirtualizor([
            'byname' => ['vs' => [
                '12' => ['vpsid' => '12', 'hostname' => 'vps1.example.com', 'email' => 'mer.las@example.com'],
                '90' => ['vpsid' => '90', 'hostname' => 'vps1.example.com', 'email' => 'mer.las@example.com'],
            ]],
            'vps' => $this->vps(['cores' => '3']),
        ]);

        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['byname', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame('90', $service->fresh()->module_data['vpsid']);
    }

    public function test_another_users_vps_with_the_same_name_does_not_block_a_new_create_for_ever(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 7: refused')]);
        $service = $this->orderedVps(['cpu' => 1]);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);
        $someoneElse = ['vs' => ['12' => ['vpsid' => '12', 'hostname' => 'vps1.example.com', 'email' => 'someone@example.net']]];

        // A search that did not work (other names, or no email) never counts as "none was made".
        $this->travel(11)->minutes();

        foreach ([
            ['vs' => ['12' => ['vpsid' => '12', 'hostname' => 'other.example.net', 'email' => 'someone@example.net']]],
            ['vs' => ['12' => ['vpsid' => '12', 'hostname' => 'vps1.example.com']]],
        ] as $answer) {
            $api = $this->fakeVirtualizor(['byname' => $answer]);
            $this->assertStringContainsString('no clear answer', app(Provisioner::class)->create($service->fresh())->message);
            $this->assertSame(['byname'], array_column($api->calls, 'kind'));
        }

        $api = $this->fakeVirtualizor(['byname' => $someoneElse, 'vps' => $this->vps(['cores' => '3'])]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        // The plan is read again: it is kept for 10 minutes only.
        $this->assertSame(['byname', 'plans', 'addvs', 'vps'], array_column($api->calls, 'kind'));
    }

    public function test_a_create_that_virtualizor_shows_never_ran_may_be_sent_again_later(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 7: refused')]);
        $service = $this->orderedVps(['cpu' => 1]);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);

        $this->travel(11)->minutes();
        $api = $this->fakeVirtualizor(['byname' => ['vs' => []], 'vps' => $this->vps(['cores' => '3'])]);

        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['byname', 'plans', 'addvs', 'vps'], array_column($api->calls, 'kind'));
    }

    public function test_an_unclear_create_stays_on_its_server_when_that_server_is_turned_off(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $service = $this->orderedVps(['cpu' => 1]);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);
        $this->assertSame($this->server->id, $service->fresh()->module_data[ResourceAddons::KEY]['server_id']);

        // Staff turn the server off and add another one.
        $this->server->update(['is_active' => false]);
        $other = Server::factory()->create(['module' => 'virtualizor', 'hostname' => 'vz2.example.test', 'port' => 4085]);
        $this->travel(11)->minutes();

        // The VPS the request made is found on the first server, and taken.
        $api = $this->fakeVirtualizor([
            'byname' => ['vs' => ['90' => ['vpsid' => '90', 'hostname' => 'vps1.example.com', 'email' => 'mer.las@example.com']]],
            'vps' => $this->vps(['cores' => '3']),
        ]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['byname', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame($this->server->id, $service->fresh()->server_id);
        $this->assertNotSame($other->id, $service->fresh()->server_id);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'vz2.example.test'));
    }

    public function test_a_create_that_made_no_vps_on_a_server_turned_off_moves_on_only_at_the_next_try(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $service = $this->orderedVps(['cpu' => 1]);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);
        $this->server->update(['is_active' => false]);
        $other = Server::factory()->create(['module' => 'virtualizor', 'hostname' => 'vz2.example.test', 'port' => 4085]);
        $this->travel(11)->minutes();

        // Virtualizor shows none was made on the first server: no new VPS is sent to it.
        $api = $this->fakeVirtualizor(['byname' => ['vs' => []]]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertSame('Virtualizor shows the earlier create request made no VPS, and this server is turned off. Try again: the next try makes the VPS on a server that is on.', $result->message);
        $this->assertSame(['byname'], array_column($api->calls, 'kind'));
        $this->assertArrayNotHasKey(ResourceAddons::KEY, (array) $service->fresh()->module_data);

        // The next try picks the server that is on, and reads its own plan.
        $api = $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '3'])]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['plans', 'addvs', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame($other->id, $service->fresh()->server_id);
        $this->assertStringContainsString('vz2.example.test', $api->calls[1]['url']);
    }

    public function test_an_unclear_create_is_not_sent_again_after_the_service_was_moved_by_hand(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $service = $this->orderedVps(['cpu' => 1]);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);

        $other = Server::factory()->create(['module' => 'virtualizor', 'hostname' => 'vz2.example.test', 'port' => 4085]);
        $service->update(['server_id' => $other->id]);
        $this->travel(11)->minutes();
        $api = $this->fakeVirtualizor(['byname' => ['vs' => []]]);

        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertFalse($result->success);
        $this->assertSame("The create request for this VPS was sent to server #{$this->server->id}, so a VPS it made is only looked for there. Move the service back to that server and try again. No VPS is made on another server.", $result->message);
        $this->assertSame([], $api->calls);
        $this->assertSame('requested', $service->fresh()->module_data[ResourceAddons::KEY]['state']);
    }

    public function test_a_vps_set_up_on_order_whose_create_had_no_clear_answer_is_not_sent_again_when_its_order_invoice_is_paid(): void
    {
        $api = $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $service = $this->orderedVps([], paid: false);
        $service->product->update(['auto_setup' => AutoSetup::OnOrder]);
        $invoice = $service->order->invoice;
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'service_id' => $service->id, 'type' => InvoiceItem::TYPE_SERVICE, 'description' => 'VPS - vps1.example.com', 'amount' => 3000, 'period_start' => today(), 'period_end' => today()->addMonth()->subDay()]);

        // The setup when the order was placed sent the create, and no answer came back.
        $this->assertSame('Could not connect to vz.example.test.', app(Provisioner::class)->create($service)->message);

        app(PaymentRecorder::class)->record($invoice->fresh(), 3000, 'banktransfer', 'BANK-1');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(['addvs'], array_column($api->calls, 'kind'));
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
    }

    public function test_a_vps_set_up_on_order_and_paid_from_the_wallet_at_once_is_not_sent_twice_after_a_create_without_an_answer(): void
    {
        $api = $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $order = $this->placeVpsOrder([], credit: 2500);

        $this->assertSame(InvoiceStatus::Paid, $order->invoice->fresh()->status);
        $this->assertSame(['addvs'], array_column($api->calls, 'kind'));
        $this->assertSame(ServiceStatus::Pending, $order->services()->sole()->status);
    }

    public function test_a_create_virtualizor_refused_may_be_tried_again_and_leaves_no_root_password(): void
    {
        $api = $this->fakeVirtualizor(['addvs' => ['error' => ['No free IP in pool 5']]]);
        $service = $this->orderedVps(['cpu' => 1]);
        // An older version put the password on the service before it sent the request.
        $service->update(['password' => 'Legacy12345']);

        $result = app(Provisioner::class)->create($service);

        $this->assertSame('Virtualizor refused to create the VPS, so none was made. Check the plan, the OS template and free IP addresses in Virtualizor, then try again.', $result->message);
        $this->assertArrayNotHasKey(ResourceAddons::KEY, (array) $service->fresh()->module_data);
        $this->assertNull($service->fresh()->password);

        $api = $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '3'])]);
        $this->assertTrue(app(Provisioner::class)->create($service->fresh())->success);
        $this->assertContains('addvs', array_column($api->calls, 'kind'));
    }

    public function test_a_vps_that_does_not_match_its_paid_extras_stays_pending_and_is_not_made_again(): void
    {
        $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '2'])]);
        $service = $this->orderedVps(['cpu' => 1]);

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertSame('VPS #90 does not match the paid add-on resources (CPU cores). It stays pending: fix it in Virtualizor, then try again. No second VPS is made. If you cancel the order instead, delete VPS #90 in Virtualizor.', $result->message);
        $service->refresh();
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame('90', $service->module_data['vpsid']);
        // The client sees no root password while the VPS waits.
        $this->assertNull($service->password);
        $this->actingAs($service->client, 'web')->get(route('client.services.show', $service))->assertOk()->assertDontSee(ResourceAddons::password($service));

        $api = $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '3'])]);
        $this->assertTrue(app(Provisioner::class)->create($service)->success);
        $this->assertSame(['vps'], array_column($api->calls, 'kind'));
        $this->assertNotNull($service->fresh()->password);
    }

    public function test_extra_ipv4_from_a_pool_needs_a_plan_on_that_pool_and_is_checked_after_the_create(): void
    {
        $api = $this->fakeVirtualizor([
            'vps' => $this->vps(['ips' => ['1' => '203.0.113.9', '2' => '203.0.113.10']]),
            'ips' => fn (array $query): array => ['ips' => ['7' => ['ip' => $query['ipsearch'], 'ippid' => '5', 'vpsid' => '90', 'ipv6' => '0', 'internal' => '0', 'nat' => '0']]],
        ]);
        $service = $this->orderedVps(['ipv4' => 1], config: ['ipv4_pool_id' => '5']);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['plans', 'addvs', 'vps', 'ips', 'ips'], array_column($api->calls, 'kind'));
        $this->assertSame(5, $service->fresh()->module_data[ResourceAddons::KEY]['pool']);

        // A plan on other pools would make a VPS that cannot match: nothing is sent.
        $api = $this->fakeVirtualizor(['plans' => ['plans' => ['4' => $this->plan(['plid' => '4', 'ippoolid' => 'a:2:{i:0;s:1:"5";i:1;s:1:"6";}'])]]]);
        $other = $this->orderedVps(['ipv4' => 1], config: ['ipv4_pool_id' => '5', 'plan_id' => '4']);

        $this->assertSame('The Virtualizor plan does not use exactly the IP pool set for extra IPv4 on the product, so nothing was changed.', app(Provisioner::class)->create($other)->message);
        $this->assertSame(['plans'], array_column($api->calls, 'kind'));
    }

    public function test_apply_resources_sends_only_changed_totals_without_the_plan(): void
    {
        $api = $this->fakeVirtualizor();
        $service = $this->activeVps(['cpu' => 1, 'ram' => 1]);

        $result = app(Provisioner::class)->applyResources($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('Saved. CPU and memory change after the next restart.', $result->message);
        $this->assertTrue($result->data['restart']);
        $this->assertSame(['plans', 'vps', 'managevps', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame(['editvps' => 1, 'cores' => 3, 'ram' => 6144], $api->calls[2]['data']);
        $this->assertSame(['act' => 'managevps', 'vpsid' => '77'], $this->withoutCredentials($api->calls[2]['query']));
        $this->assertSame(['cores' => 3, 'ram' => 6144, 'disk' => 80, 'num_ips' => 1], $service->fresh()->module_data[ResourceTotals::DATA]['totals']);

        // Running it again changes nothing.
        $api = $this->fakeVirtualizor();
        $again = app(Provisioner::class)->applyResources($service->fresh());

        $this->assertSame('The server already has these resources.', $again->message);
        $this->assertSame(['vps'], array_column($api->calls, 'kind'));

        // The check reads the VPS only and finds it matches.
        $check = app(Provisioner::class)->checkResources($service->fresh());
        $this->assertTrue($check->matches(), implode('; ', $check->problems));
        $this->assertSame(['cpu' => 3, 'ram' => 6144, 'disk' => 80, 'ipv4' => 1], $check->expected);
    }

    public function test_apply_resources_grows_the_single_disk_and_refuses_to_shrink(): void
    {
        $api = $this->fakeVirtualizor();
        $service = $this->activeVps(['disk' => 1]);

        $result = app(Provisioner::class)->applyResources($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('Saved. The VPS has its new resources.', $result->message);
        $this->assertSame(['plans', 'vps', 'vpspage', 'managevps', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame(['editvps' => 1, 'space' => [['size' => 120, 'st_uuid' => 'st1', 'disk_uuid' => 'dk1']]], $api->calls[3]['data']);
        $this->assertSame('120', $this->live['77']['space']);

        // Staff take the extra disk away: the disk never shrinks, and the check shows the difference.
        $service->resources()->update(['steps' => 0, 'recurring_amount' => 0, 'status' => ServiceResourceOption::STATUS_CANCELLED]);
        $api = $this->fakeVirtualizor();

        $result = app(Provisioner::class)->applyResources($service->fresh());

        $this->assertSame('The server already has these resources.', $result->message);
        $this->assertSame(['vps'], array_column($api->calls, 'kind'));
        $this->assertSame('120', $this->live['77']['space']);
        $this->assertSame(['Disk space: 120 GB, expected 80 GB'], app(Provisioner::class)->checkResources($service->fresh())->problems);

        // A VPS with two disks gets the rest, and staff grow its disk by hand.
        $api = $this->fakeVirtualizor(['vpspage' => ['vps' => ['disks' => [['st_uuid' => 'st1', 'size' => 80], ['st_uuid' => 'st2', 'size' => 20]]]]]);
        $twoDisks = $this->activeVps(['cpu' => 1, 'disk' => 1], '78');

        $result = app(Provisioner::class)->applyResources($twoDisks);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['manual' => true, 'restart' => true], $result->data);
        $this->assertSame('Saved, but the disk did not grow: this VPS has more than one disk, or its disk has no storage ID. Grow it to 120 GB in Virtualizor.', $result->message);
        $this->assertSame(['editvps' => 1, 'cores' => 3], collect($api->calls)->firstWhere('kind', 'managevps')['data']);
    }

    public function test_lowering_ipv4_keeps_the_first_addresses(): void
    {
        $api = $this->fakeVirtualizor();
        $service = $this->activeVps(['ipv4' => 2], live: ['ips' => ['1' => '203.0.113.7', '2' => '203.0.113.8', '3' => '203.0.113.9']]);
        $service->resources()->update(['steps' => 1, 'recurring_amount' => 500]);

        $result = app(Provisioner::class)->applyResources($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['editvps' => 1, 'num_ips' => 2, 'ips' => ['203.0.113.7', '203.0.113.8']], collect($api->calls)->firstWhere('kind', 'managevps')['data']);
        $this->assertSame(['203.0.113.7', '203.0.113.8'], $service->fresh()->module_data['ips']);

        // More addresses keep these and add new ones.
        $service->resources()->update(['steps' => 3, 'recurring_amount' => 1500]);
        $api = $this->fakeVirtualizor();

        $this->assertTrue(app(Provisioner::class)->applyResources($service->fresh())->success);
        $this->assertSame(['editvps' => 1, 'num_ips' => 4], collect($api->calls)->firstWhere('kind', 'managevps')['data']);
        $this->assertSame(['203.0.113.7', '203.0.113.8', '203.0.113.22', '203.0.113.23'], $service->fresh()->module_data['ips']);
    }

    public function test_apply_resources_reports_mismatches_and_pool_problems(): void
    {
        // Virtualizor confirms the change, but the RAM stays as it was.
        $api = $this->fakeVirtualizor(['managevps' => ['done' => ['done' => true], 'vs_info' => ['vpsid' => '77']]]);
        $service = $this->activeVps(['ram' => 1]);

        $result = app(Provisioner::class)->applyResources($service);

        $this->assertFalse($result->success);
        $this->assertSame('The server does not match yet: RAM: 4 GB, expected 6 GB', $result->message);
        $this->assertArrayNotHasKey(ResourceTotals::DATA, $service->fresh()->module_data);

        // A new IPv4 address from another pool is refused, and checked again on the next try.
        $notInPool = fn (array $query): array => ['ips' => ['7' => ['ip' => $query['ipsearch'], 'ippid' => '6', 'vpsid' => '78', 'ipv6' => '0', 'internal' => '0', 'nat' => '0']]];
        $api = $this->fakeVirtualizor(['ips' => $notInPool]);
        $pooled = $this->activeVps(['ipv4' => 1], '78', config: ['ipv4_pool_id' => '5']);

        $result = app(Provisioner::class)->applyResources($pooled);

        $this->assertSame('The server does not match yet: 203.0.113.21 is not from IP pool 5', $result->message);
        $this->assertSame(['203.0.113.21'], array_column(array_column(array_filter($api->calls, fn (array $call): bool => $call['kind'] === 'ips'), 'query'), 'ipsearch'));
        $this->assertSame(['203.0.113.7'], $pooled->fresh()->module_data['ips']);

        $api = $this->fakeVirtualizor(['ips' => $notInPool]);
        $this->assertSame('The server does not match yet: 203.0.113.21 is not from IP pool 5', app(Provisioner::class)->applyResources($pooled->fresh())->message);
        $this->assertSame(['vps', 'ips'], array_column($api->calls, 'kind'));
    }

    public function test_limits_keep_the_disk_from_shrinking_and_sell_no_extras_on_unlimited_traffic(): void
    {
        $api = $this->fakeVirtualizor();
        $service = $this->activeVps(['disk' => 1], live: ['space' => '120'], config: ['bandwidth' => '0']);
        ResourceOption::factory()->kind('bandwidth', 1000)->priced(500)->create(['product_id' => $service->product_id]);

        $limits = app(Provisioner::class)->resourceLimits($service);

        $this->assertSame([40, 'Disk space can only grow.'], [$limits['disk']->minUnits, $limits['disk']->reason]);
        $this->assertSame([0, 'Your plan has no limit here, so extras add nothing.'], [$limits['bandwidth']->maxUnits, $limits['bandwidth']->reason]);
        $this->assertSame([62, 15], [$limits['cpu']->maxUnits, $limits['ipv4']->maxUnits]);
        $this->assertSame(['plans', 'vps'], array_column($api->calls, 'kind'));
    }

    public function test_change_package_applies_the_plan_then_the_extras(): void
    {
        $api = $this->fakeVirtualizor();
        $service = $this->activeVps(['cpu' => 1, 'ram' => 1], live: ['cores' => '3', 'ram' => '6144'], config: ['plan_id' => '4']);

        $result = app(Provisioner::class)->changePackage($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('Plan applied to the VPS, with its extras.', $result->message);
        $this->assertSame(['plans', 'vps', 'managevps', 'vps', 'managevps', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame(['editvps' => 1, 'plid' => 4, 'apply_plan' => 1], $api->calls[2]['data']);
        $this->assertSame(['editvps' => 1, 'cores' => 5, 'ram' => 10240], $api->calls[4]['data']);

        // Without extras it is one call, as before.
        $api = $this->fakeVirtualizor();
        $plain = $this->activeVps([], '78', config: ['plan_id' => '4']);

        $this->assertTrue(app(Provisioner::class)->changePackage($plain)->success);
        $this->assertSame(['managevps'], array_column($api->calls, 'kind'));
        $this->assertSame(['editvps' => 1, 'plid' => 4, 'apply_plan' => 1], $api->calls[0]['data']);

        // Extras Virtualizor does not take are listed for staff to set by hand.
        $this->fakeVirtualizor(['managevps' => ['done' => ['done' => true], 'vs_info' => ['vpsid' => '79']], 'vps' => $this->vps(['cores' => '4'], '79')]);
        $stuck = $this->activeVps(['cpu' => 1], '79', config: ['plan_id' => '4']);

        $result = app(Provisioner::class)->changePackage($stuck);

        $this->assertFalse($result->success);
        $this->assertSame('The new plan is applied, but its extras could not all be set again (CPU cores). Set them in Virtualizor by hand: CPU cores 5.', $result->message);
    }

    public function test_a_change_of_extras_is_set_on_the_vps_by_the_queued_job(): void
    {
        Mail::fake();
        Queue::fake();
        $api = $this->fakeVirtualizor();
        $service = $this->activeVps(['ram' => 1], live: ['ram' => '6144']);

        $change = app(ResourceChanges::class)->start($service, ['ram' => 2], Admin::factory()->create(), charge: false);

        $this->assertSame(ResourceChange::SERVER_QUEUED, $change->fresh()->server_state);
        Queue::assertPushed(ApplyServiceResources::class);

        app(ResourceChanges::class)->applyToServer($change->id);

        $this->assertSame(ResourceChange::SERVER_DONE, $change->fresh()->server_state);
        $this->assertSame(['editvps' => 1, 'ram' => 8192], collect($api->calls)->firstWhere('kind', 'managevps')['data']);
        $this->assertSame('8192', $this->live['77']['ram']);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo($service->client->email));
    }

    public function test_a_product_with_unconverted_json_refuses_with_a_clear_message(): void
    {
        $refusal = 'This product still has resource add-on JSON that was not moved to sliders. Set up its resource options first; nothing was changed.';
        $addon = ProductAddon::factory()->create(['name' => 'Extra CPU core']);
        $json = json_encode(['version' => 1, 'ids' => ['cpu' => $addon->id]]);
        $api = $this->fakeVirtualizor();
        $service = $this->orderedVps([], config: ['resource_addons' => $json]);
        $service->addons()->create(['product_addon_id' => $addon->id, 'name' => $addon->name, 'recurring_amount' => 500, 'status' => ServiceAddon::STATUS_ACTIVE]);

        $this->assertSame($refusal, app(Provisioner::class)->create($service)->message);
        $this->assertSame([], $api->calls);

        // The same for a plan change and a change of extras, before anything is sent.
        $service->update(['status' => ServiceStatus::Active, 'module_data' => ['vpsid' => '77', 'uid' => '58']]);
        $this->assertSame($refusal, app(Provisioner::class)->changePackage($service->fresh())->message);
        $this->assertSame($refusal, app(Provisioner::class)->applyResources($service->fresh())->message);
        $this->assertSame([], $api->calls);

        // JSON that cannot be read stops a service with any add-on.
        $broken = $this->orderedVps([], config: ['resource_addons' => 'not json']);
        $broken->addons()->create(['product_addon_id' => ProductAddon::factory()->create()->id, 'name' => 'Daily backups', 'recurring_amount' => 200, 'status' => ServiceAddon::STATUS_ACTIVE]);
        $this->assertSame($refusal, app(Provisioner::class)->create($broken)->message);

        // A service without the add-ons the JSON names is made as usual.
        $plain = $this->orderedVps([], config: ['resource_addons' => $json]);
        $this->assertTrue(app(Provisioner::class)->create($plain)->success);
        $this->assertSame(['addvs'], array_column($api->calls, 'kind'));
    }

    public function test_notes_from_older_versions_keep_working(): void
    {
        // A create a version before 1.1.0 sent without a clear answer is not sent again.
        $api = $this->fakeVirtualizor(['byname' => ['vs' => ['5' => ['vpsid' => '5', 'hostname' => 'other.example.com', 'email' => 'raz@example.com']]]]);
        $old = $this->orderedVps(['cpu' => 1]);
        $old->update(['module_data' => [ResourceAddons::KEY => ['snapshot' => ['version' => 1, 'totals' => ['cores' => 3]], 'state' => ['status' => 'requested', 'vpsid' => null, 'hostname' => 'vps1.example.com'], 'signature' => 'abc']]]);

        $this->assertStringContainsString('no clear answer', app(Provisioner::class)->create($old->fresh())->message);
        $this->assertSame(['byname'], array_column($api->calls, 'kind'));

        // A VPS that 1.x made with add-on extras is checked against its noted totals, and taken.
        $api = $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '3'])]);
        $made = $this->orderedVps(['cpu' => 1]);
        $made->update(['module_data' => [ResourceAddons::KEY => ['state' => 'created', 'vpsid' => '90', 'server_id' => $this->server->id, 'hostname' => 'vps1.example.com', 'email' => 'mer.las@example.com', 'totals' => ['cores' => 3], 'addons' => [5], 'pool' => null, 'requested_at' => now()->getTimestamp()]]]);

        $result = app(Provisioner::class)->create($made->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['vps'], array_column($api->calls, 'kind'));
        $note = ResourceAddons::record($made->fresh());
        $this->assertSame(['verified', [5], ['cores' => 3]], [$note['state'], $note['addons'], $note['totals']]);

        // Its plan changes now take the extras from its resource rows.
        $made->product->update(['module_config' => ['virt' => 'kvm', 'os_id' => '100', 'plan_id' => '4']]);
        $this->live['90'] = $this->vpsRow('90', ['cores' => '3']);
        $api = $this->fakeVirtualizor();

        $this->assertTrue(app(Provisioner::class)->changePackage($made->fresh())->success);
        $this->assertSame(['editvps' => 1, 'cores' => 5], $api->calls[4]['data']);
    }

    public function test_store_pages_never_ask_virtualizor_for_kinds(): void
    {
        $this->fakeVirtualizor();
        $product = Product::factory()->priced(2500)->withoutDomain()->create(['name' => 'VPS 2', 'server_module' => 'virtualizor', 'server_id' => $this->server->id, 'module_config' => ['virt' => 'kvm', 'os_id' => '100', 'plan_id' => '3']]);

        foreach (self::STEPS as $kind => $step) {
            ResourceOption::factory()->kind($kind, $step)->priced(500)->create(['product_id' => $product->id, 'note' => $kind === 'disk' ? 'Grow the partition inside your server to use the new space.' : null]);
        }

        $this->get(route('store.product', [$product->group, $product]))
            ->assertOk()
            ->assertSee('CPU cores')
            ->assertSee('Grow the partition inside your server to use the new space.');

        Http::assertNothingSent();
    }

    /**
     * A Virtualizor product on plan 3 that sells the four kinds of STEPS at 5.00 a step a month.
     *
     * @param  array<string, mixed>  $config
     */
    private function product(array $config = []): Product
    {
        $product = Product::factory()->create([
            'server_module' => 'virtualizor',
            'server_id' => $this->server->id,
            'module_config' => array_filter($config + ['virt' => 'kvm', 'os_id' => '100', 'plan_id' => '3'], fn (mixed $value): bool => $value !== null),
        ]);

        foreach (self::STEPS as $kind => $step) {
            ResourceOption::factory()->kind($kind, $step)->priced(500)->create([
                'product_id' => $product->id,
                'note' => $kind === 'disk' ? 'Grow the partition inside your server to use the new space.' : null,
                'restarts' => in_array($kind, ['cpu', 'ram'], true),
            ]);
        }

        return $product;
    }

    /**
     * The service's option of a kind.
     */
    private function option(Service $service, string $kind): ResourceOption
    {
        return ResourceOption::query()->where('product_id', $service->product_id)->where('kind', $kind)->sole();
    }

    /**
     * A pending VPS of Mer Las on an order, with these extras (kind => steps) at 5.00 a step.
     *
     * @param  array<string, int>  $extras
     * @param  array<string, mixed>  $config
     */
    private function orderedVps(array $extras, bool $paid = true, array $config = []): Service
    {
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null, 'email' => $this->clients++ === 0 ? 'mer.las@example.com' : 'mer.las'.$this->clients.'@example.com', 'email_verified_at' => now()]);
        $product = $this->product($config);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'total' => 3000, 'subtotal' => 3000] + ($paid ? ['status' => InvoiceStatus::Paid, 'amount_paid' => 3000, 'paid_at' => now()] : []));
        $order = Order::factory()->create(['client_id' => $client->id, 'invoice_id' => $invoice->id, 'status' => OrderStatus::Active]);
        $service = Service::factory()->create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'server_id' => $this->server->id,
            'order_id' => $order->id,
            'status' => ServiceStatus::Pending,
            'domain' => 'vps1.example.com',
            'module_data' => null,
            'registration_date' => today(),
        ]);

        foreach ($extras as $kind => $steps) {
            ServiceResourceOption::factory()->create([
                'service_id' => $service->id,
                'resource_option_id' => $this->option($service, $kind)->id,
                'steps' => $steps,
                'recurring_amount' => 500 * $steps,
            ]);
        }

        return $service;
    }

    /**
     * An active VPS with these extras, already made on the fake master with its plan's base (plan 3:
     * 2 cores, 4096 MB, 80 GB and one IPv4 address), or with the given live values.
     *
     * @param  array<string, int>  $extras
     * @param  array<string, mixed>  $live
     * @param  array<string, mixed>  $config
     */
    private function activeVps(array $extras, string $vpsId = '77', array $live = [], array $config = []): Service
    {
        $service = $this->orderedVps($extras, config: $config);
        $this->live[$vpsId] = $this->vpsRow($vpsId, $live);
        $service->update(['status' => ServiceStatus::Active, 'module_data' => ['vpsid' => $vpsId, 'uid' => '58', 'ips' => array_values($this->live[$vpsId]['ips'])]]);

        return $service->fresh();
    }

    /**
     * An order of Mer Las for a VPS set up as soon as the order is placed, with these extras. Her
     * wallet pays the order invoice right after the order is placed.
     *
     * @param  array<string, int>  $extras
     */
    private function placeVpsOrder(array $extras, int $credit): Order
    {
        $this->setSettings(['wallet.auto_apply' => true]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null, 'email' => 'mer.las@example.com', 'email_verified_at' => now(), 'currency' => 'USD', 'credit' => $credit]);
        $product = $this->product();
        $product->update(['auto_setup' => AutoSetup::OnOrder]);
        $product->prices()->create(['currency' => 'USD', 'billing_cycle' => BillingCycle::Monthly, 'price' => 2500]);

        $cart = app(Cart::class);
        $cart->addProduct($product->fresh(), BillingCycle::Monthly, 'vps1.example.com', [], $extras);

        return app(OrderPlacer::class)->place($client, $cart->lines('USD'));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function plan(array $values = []): array
    {
        return $values + ['plid' => '3', 'virt' => 'kvm', 'ram' => '4096', 'space' => '80', 'cores' => '2', 'bandwidth' => '2000', 'ips' => '1', 'ips6' => '2', 'ips6_subnet' => '1', 'network_speed' => '0', 'ippoolid' => 'a:1:{i:0;s:1:"5";}'];
    }

    /**
     * One VPS as Virtualizor's list shows it: plan 3's base unless the values say otherwise.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function vpsRow(string $id, array $values = []): array
    {
        return $values + [
            'vpsid' => $id,
            'uid' => '58',
            'suspended' => '0',
            'virt' => 'kvm',
            'hostname' => 'vps1.example.com',
            'email' => 'mer.las@example.com',
            'cores' => '2',
            'ram' => '4096',
            'space' => '80',
            'bandwidth' => '2000',
            'ips' => ['1' => '203.0.113.7'],
        ];
    }

    /**
     * The answer for one VPS in Virtualizor's list, made at create with these values.
     *
     * @param  array<string, mixed>  $values
     */
    private function vps(array $values, string $id = '90'): Closure
    {
        return fn (array $query): array => ['vs' => [$id => $this->vpsRow($id, $values + ['ips' => ['1' => '203.0.113.9']])]];
    }

    /**
     * A fake Virtualizor answering by request kind; a second call swaps the answers and clears the
     * log. By default the VPS in $this->live answer reads and take the changes sent to them.
     *
     * @param  array<string, mixed>  $answers
     */
    private function fakeVirtualizor(array $answers = []): stdClass
    {
        $answers += [
            'addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '90', 'uid' => '58', 'ips' => ['203.0.113.9']]],
            'plans' => ['plans' => ['3' => $this->plan(), '4' => $this->plan(['plid' => '4', 'cores' => '4', 'ram' => '8192'])]],
            'vps' => fn (array $query): array => ['vs' => isset($this->live[$query['vpsid']]) ? [$query['vpsid'] => $this->live[$query['vpsid']]] : []],
            'managevps' => fn (array $query, array $data): array => $this->manage((string) $query['vpsid'], $data),
            'vpspage' => ['vps' => ['disks' => [['st_uuid' => 'st1', 'disk_uuid' => 'dk1', 'size' => 80]]]],
            // The create-time check for a Virtualizor user with the client's email finds none.
            'users' => ['users' => []],
        ];

        if ($this->api === null) {
            $this->api = new stdClass;

            Http::fake(function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $act = (string) ($query['act'] ?? '');
                $kind = match (true) {
                    $act === 'managevps' && $request->method() !== 'POST' => 'vpspage',
                    $act !== 'vs' => $act,
                    isset($query['vpshostname']) => 'byname',
                    isset($query['vpsid']) => 'vps',
                    default => 'vs',
                };
                // The email check runs before every new create; these tests are about the extras.
                if ($kind !== 'users') {
                    $this->api->calls[] = ['kind' => $kind, 'query' => $query, 'data' => $request->method() === 'POST' ? $request->data() : [], 'url' => $request->url()];
                }

                $answer = $this->api->answers[$kind] ?? ['error' => ['Unexpected request: '.$kind]];
                $answer = $answer instanceof Closure ? $answer($query, $request->method() === 'POST' ? $request->data() : []) : $answer;

                return is_array($answer) ? Http::response($answer) : $answer;
            });
        }

        $this->api->answers = $answers;
        $this->api->calls = [];

        return $this->api;
    }

    /**
     * The fake master takes a managevps change: plan 4 with apply_plan, or the fields sent. New
     * IPv4 addresses come from 203.0.113.20 up.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function manage(string $vpsId, array $data): array
    {
        $vps = $this->live[$vpsId] ?? $this->vpsRow($vpsId);

        if (isset($data['apply_plan'])) {
            $vps = ['cores' => '4', 'ram' => '8192'] + $vps;
        }

        foreach (['cores', 'ram', 'bandwidth'] as $field) {
            if (isset($data[$field])) {
                $vps[$field] = (string) $data[$field];
            }
        }

        if (isset($data['space'][0]['size'])) {
            $vps['space'] = (string) $data['space'][0]['size'];
        }

        if (isset($data['num_ips'])) {
            $ips = isset($data['ips']) ? array_values($data['ips']) : array_values($vps['ips']);

            for ($next = 20 + count($ips); count($ips) < (int) $data['num_ips']; $next++) {
                $ips[] = '203.0.113.'.$next;
            }

            $vps['ips'] = array_slice($ips, 0, (int) $data['num_ips']);
        }

        $this->live[$vpsId] = $vps;

        return ['done' => ['done' => true], 'vs_info' => ['vpsid' => $vpsId]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function keys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function withoutCredentials(array $query): array
    {
        return array_diff_key($query, array_flip(['api', 'adminapikey', 'adminapipass']));
    }
}
