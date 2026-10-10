<?php

namespace Tests\Feature\Provisioning;

use App\Billing\InvoicePaidHandler;
use App\Billing\PaymentRecorder;
use App\Contracts\HasResourceOptions;
use App\Contracts\UsesMachineInventory;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Events\ServiceActivated;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Extensions\Servers\Resources\ResourceLimit;
use App\Jobs\ChangeServicePackage;
use App\Jobs\UnsuspendService;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResourceOption;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Provisioning\OrderPaymentGate;
use App\Provisioning\Provisioner;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * What Provisioner does for the new optional server module contracts: services without a server
 * record, setups that staff finish later, the module's own welcome email, the core payment wait for
 * paid extras, slow plan changes in a queued job, the service lock on suspend, and resource options.
 */
class ProvisionerContractsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        PanelFakeModule::reset();
        Mail::fake();

        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer.las@example.test', 'company_name' => null]);
    }

    public function test_a_service_on_the_hardware_inventory_is_set_up_without_a_server(): void
    {
        // A server of the same module exists, but a machine is not a server record.
        Server::factory()->create(['module' => 'panelfake']);
        $service = $this->fakeService(['status' => ServiceStatus::Pending]);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success);
        $fresh = $service->fresh();
        $this->assertSame(ServiceStatus::Active, $fresh->status);
        $this->assertNull($fresh->server_id);
        $this->assertSame('fake'.$service->id, $fresh->username);
        $this->assertSame('vm-'.$service->id, $fresh->module_data['fake_id']);
        $this->assertCount(1, PanelFakeModule::calls('create'));
        Mail::assertSent(TemplatedMessage::class, 1);

        // The client panel works without a server record too.
        $this->assertSame('running', app(Provisioner::class)->clientPanelState($fresh));
    }

    public function test_a_setup_that_staff_finish_later_keeps_the_service_pending_without_a_welcome_email(): void
    {
        Event::fake([ServiceActivated::class]);
        PanelFakeModule::$behaviour['pending'] = ['setupPending'];
        $service = $this->fakeService(['status' => ServiceStatus::Pending]);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success);
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        $this->assertSame('vm-'.$service->id, $service->fresh()->module_data['fake_id'], 'The account details are kept.');
        Mail::assertNothingSent();
        Event::assertNotDispatched(ServiceActivated::class);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.setup_waiting')->exists());
        $this->assertFalse(ActivityLog::query()->where('action', 'service.created')->exists());

        // A module that cannot tell is treated as still waiting.
        PanelFakeModule::$behaviour = ['throw' => ['setupPending']];
        $this->assertTrue(app(Provisioner::class)->create($service)->success);
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        Mail::assertNothingSent();

        // Staff finished the setup: the next create activates it and sends the welcome email.
        PanelFakeModule::$behaviour = [];
        $this->assertTrue(app(Provisioner::class)->create($service)->success);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        Mail::assertSent(TemplatedMessage::class, 1);
        Event::assertDispatched(ServiceActivated::class);
    }

    public function test_the_module_chooses_the_welcome_email(): void
    {
        PanelFakeModule::$behaviour['welcome'] = ['key' => 'service.dedicated_ready', 'context' => ['machine' => ['ip' => '192.0.2.10', 'hostname' => 'ds1.example.test']]];
        $service = $this->fakeService(['status' => ServiceStatus::Pending]);

        app(Provisioner::class)->create($service);

        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_starts_with($mail->subjectLine, 'Your server ')
            && str_contains($mail->bodyHtml, '192.0.2.10') && str_contains($mail->bodyHtml, 'ds1.example.test'));
    }

    public function test_the_welcome_email_falls_back_when_the_module_fails_and_never_shows_a_secret(): void
    {
        Exceptions::fake();
        EmailTemplate::query()->where('key', 'service.welcome')->update(['body' => 'Note: {{ panel.note }} Password: {{ root_password }}']);

        // The fixture's own welcome context holds a root password next to a normal note.
        $first = $this->fakeService(['status' => ServiceStatus::Pending]);
        app(Provisioner::class)->create($first);

        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->bodyHtml, 'Your fake server is ready.')
            && ! str_contains($mail->bodyHtml, PanelFakeModule::SECRET));

        // A module that throws, or names a template that does not exist, gets the normal welcome.
        foreach ([['throw' => ['welcomeEmail']], ['welcome' => ['key' => 'service.not_there', 'context' => []]]] as $behaviour) {
            PanelFakeModule::$behaviour = $behaviour;
            $service = $this->fakeService(['status' => ServiceStatus::Pending]);

            app(Provisioner::class)->create($service);

            $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        }

        Mail::assertSent(TemplatedMessage::class, 3);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'Welcome email of service')
            && ! str_contains($exception->getMessage(), PanelFakeModule::API_KEY));
    }

    public function test_paid_extras_wait_for_the_order_invoice_and_the_wait_is_cleared_once_it_is_paid(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id]);
        $order = Order::factory()->create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id]);
        $service = $this->fakeService(['status' => ServiceStatus::Pending, 'order_id' => $order->id]);
        ServiceResourceOption::factory()->create([
            'service_id' => $service->id,
            'resource_option_id' => ResourceOption::factory()->create(['product_id' => $service->product_id])->id,
            'steps' => 2,
            'recurring_amount' => 400,
        ]);

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertSame('The extras on this order are not paid yet. The server is made once the order invoice is paid.', $result->message);
        $this->assertTrue($service->fresh()->module_data[OrderPaymentGate::WAITING]);
        $this->assertSame([], PanelFakeModule::calls('create'), 'Nothing is made on the server.');
        $this->assertTrue(app(Provisioner::class)->waitsForPayment($service));

        // An order held for a staff review keeps waiting, even when it is paid.
        $invoice->update(['status' => InvoiceStatus::Paid, 'amount_paid' => $invoice->total, 'paid_at' => now()]);
        $order->update(['needs_review' => true]);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);

        $order->update(['needs_review' => false]);
        $this->assertTrue(app(Provisioner::class)->create($service)->success);

        $fresh = $service->fresh();
        $this->assertSame(ServiceStatus::Active, $fresh->status);
        $this->assertArrayNotHasKey(OrderPaymentGate::WAITING, $fresh->module_data);
        $this->assertSame(['ram' => 2048], PanelFakeModule::calls('create')[0]['args']['extras']);
        $this->assertFalse(app(Provisioner::class)->waitsForPayment($fresh));
    }

    public function test_free_extras_and_services_without_an_order_do_not_wait(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id]);
        $order = Order::factory()->create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id]);
        $free = $this->fakeService(['status' => ServiceStatus::Pending, 'order_id' => $order->id]);
        ServiceResourceOption::factory()->create(['service_id' => $free->id, 'resource_option_id' => ResourceOption::factory()->create(['product_id' => $free->product_id])->id, 'recurring_amount' => 0]);
        $staffMade = $this->fakeService(['status' => ServiceStatus::Pending]);
        ServiceResourceOption::factory()->create(['service_id' => $staffMade->id, 'resource_option_id' => ResourceOption::factory()->create(['product_id' => $staffMade->product_id])->id, 'recurring_amount' => 400]);

        $this->assertFalse(OrderPaymentGate::extrasUnpaid($free));
        $this->assertFalse(OrderPaymentGate::extrasUnpaid($staffMade));
        $this->assertTrue(app(Provisioner::class)->create($free)->success);
        $this->assertTrue(app(Provisioner::class)->create($staffMade)->success);

        // A setup fee line of the extras on the order invoice counts as money too.
        $invoice->items()->create(['service_id' => $free->id, 'type' => 'resource', 'description' => 'Setup fee - RAM +1 GB', 'amount' => 500]);
        $this->assertTrue(OrderPaymentGate::extrasUnpaid($free));
    }

    public function test_a_slow_plan_change_runs_in_a_queued_job(): void
    {
        Queue::fake();
        $service = $this->fakeService();

        $result = app(Provisioner::class)->changePackage($service);

        $this->assertTrue($result->success);
        $this->assertTrue($result->data['queued']);
        $this->assertSame('The new plan is being set on the server. This can take a few minutes.', $result->message);
        $this->assertSame([], PanelFakeModule::calls('changePackage'), 'Nothing runs inside the request.');
        $this->assertTrue(ActivityLog::query()->where('action', 'service.package_queued')->exists());
        Queue::assertPushed(ChangeServicePackage::class, fn (ChangeServicePackage $job): bool => $job->serviceId === $service->id && $job->timeout === 900);

        (new ChangeServicePackage($service->id))->handle(app(Provisioner::class));

        $this->assertCount(1, PanelFakeModule::calls('changePackage'));
        $this->assertTrue(ActivityLog::query()->where('action', 'service.package_changed')->exists());
    }

    public function test_the_plan_change_job_tries_again_later_while_the_service_is_busy(): void
    {
        Queue::fake();
        Sleep::fake(syncWithCarbon: true);
        $service = $this->fakeService();
        $lock = Cache::lock(Provisioner::LOCK_PREFIX.$service->id, 900);
        $lock->get();

        (new ChangeServicePackage($service->id))->handle(app(Provisioner::class));

        $this->assertSame([], PanelFakeModule::calls('changePackage'));
        Queue::assertPushed(ChangeServicePackage::class, fn (ChangeServicePackage $job): bool => $job->serviceId === $service->id && $job->busyTry === 1);

        // After the last try staff are told.
        (new ChangeServicePackage($service->id, ChangeServicePackage::BUSY_TRIES - 1))->handle(app(Provisioner::class));
        $this->assertTrue(ActivityLog::query()->where('action', 'service.module_failed')->where('description', 'like', '%kept the service busy%')->exists());

        $lock->release();
    }

    public function test_suspend_waits_for_other_work_on_the_service(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $service = $this->fakeService();
        $lock = Cache::lock(Provisioner::LOCK_PREFIX.$service->id, 900);
        $this->assertTrue($lock->get());

        $result = app(Provisioner::class)->suspend($service, 'Overdue on payment');

        $this->assertFalse($result->success);
        $this->assertTrue($result->data['busy']);
        $this->assertSame('This service is busy on the server. Try again in a minute.', $result->message);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame([], PanelFakeModule::calls('suspend'));
        $this->assertTrue(ActivityLog::query()->where('action', 'service.module_failed')->where('description', 'like', 'Could not suspend%busy%')->exists());
        Mail::assertNothingSent();

        $lock->release();
        $this->assertTrue(app(Provisioner::class)->suspend($service, 'Overdue on payment')->success);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
    }

    public function test_an_invoice_paid_while_the_service_is_busy_unsuspends_it_in_the_background(): void
    {
        Queue::fake();
        Sleep::fake();
        $service = $this->fakeService(['status' => ServiceStatus::Suspended, 'suspended_at' => now()->subDays(2), 'suspension_reason' => InvoicePaidHandler::OVERDUE_REASON]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'due_at' => today()->subDays(4), 'total' => 1000]);
        $invoice->items()->create(['type' => 'service', 'service_id' => $service->id, 'description' => 'Renewal', 'amount' => 1000]);
        // A resource change job holds the service on its server.
        $lock = Cache::lock(Provisioner::LOCK_PREFIX.$service->id, 900);
        $this->assertTrue($lock->get());

        app(PaymentRecorder::class)->record($invoice, 1000, 'banktransfer');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
        $this->assertSame([], PanelFakeModule::calls('unsuspend'));
        Sleep::assertNeverSlept();
        $this->assertTrue(ActivityLog::query()->where('action', 'service.unsuspend_queued')->exists());
        $this->assertFalse(ActivityLog::query()->where('action', 'service.module_failed')->exists(), 'A busy service is tried again, not reported.');
        Queue::assertPushed(UnsuspendService::class, fn (UnsuspendService $job): bool => $job->serviceId === $service->id && $job->busyTry === 0 && $job->delay === UnsuspendService::BUSY_DELAY);

        // Still busy at the first try: it tries again later.
        $this->runQueued(Queue::pushed(UnsuspendService::class)->first());
        Queue::assertPushed(UnsuspendService::class, fn (UnsuspendService $job): bool => $job->busyTry === 1);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);

        // The other work ends, and the next try unsuspends it.
        $lock->release();
        $this->runQueued(Queue::pushed(UnsuspendService::class, fn (UnsuspendService $job): bool => $job->busyTry === 1)->first());

        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertCount(1, PanelFakeModule::calls('unsuspend'));
        $this->assertTrue(ActivityLog::query()->where('action', 'service.unsuspended')->exists());
        Queue::assertPushed(UnsuspendService::class, 2);
    }

    public function test_the_unsuspend_job_gives_up_after_its_tries_and_never_lifts_a_newer_suspension(): void
    {
        Queue::fake();
        $service = $this->fakeService(['status' => ServiceStatus::Suspended, 'suspended_at' => now()->subDays(2), 'suspension_reason' => 'Suspended by staff']);
        $lock = Cache::lock(Provisioner::LOCK_PREFIX.$service->id, 900);
        $lock->get();

        // Staff press "Unsuspend" while the service is busy.
        $result = app(Provisioner::class)->unsuspend($service);

        $this->assertTrue($result->success);
        $this->assertTrue($result->data['queued']);
        $this->assertSame('This service is busy on the server. It will be unsuspended in a few minutes.', $result->message);
        $suspendedAt = $service->fresh()->suspended_at->getTimestamp();
        Queue::assertPushed(UnsuspendService::class, fn (UnsuspendService $job): bool => $job->suspendedAt === $suspendedAt);

        // After the last try staff are told.
        (new UnsuspendService($service->id, $suspendedAt, UnsuspendService::BUSY_TRIES - 1))->handle(app(Provisioner::class));
        $this->assertTrue(ActivityLog::query()->where('action', 'service.module_failed')->where('description', 'like', '%kept the service busy%')->exists());
        Queue::assertPushed(UnsuspendService::class, 1);
        $lock->release();

        // Unsuspended and suspended again by staff meanwhile: the old job leaves the new suspension.
        $service->update(['suspended_at' => now()->subHour(), 'suspension_reason' => 'Abuse report']);
        (new UnsuspendService($service->id, $suspendedAt))->handle(app(Provisioner::class));

        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
        $this->assertSame([], PanelFakeModule::calls('unsuspend'));
        $this->assertTrue(ActivityLog::query()->where('action', 'service.unsuspend_skipped')->exists());

        (new UnsuspendService($service->id, $service->fresh()->suspended_at->getTimestamp()))->handle(app(Provisioner::class));
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
    }

    public function test_the_nightly_suspension_recheck_leaves_a_busy_service_for_the_next_night(): void
    {
        $service = $this->fakeService(['status' => ServiceStatus::Suspended, 'suspension_reason' => 'Overdue on payment', 'module_data' => ['fake_id' => 'vm-1', Provisioner::RECHECK_SUSPENSION => 3]]);
        $lock = Cache::lock(Provisioner::LOCK_PREFIX.$service->id, 900);
        $lock->get();

        $this->assertSame(0, app(Provisioner::class)->recheckSuspensions());

        $this->assertSame([], PanelFakeModule::calls('suspend'));
        $this->assertSame(3, $service->fresh()->module_data[Provisioner::RECHECK_SUSPENSION], 'No try is used up.');

        $lock->release();
        $this->assertSame(1, app(Provisioner::class)->recheckSuspensions());

        $this->assertCount(1, PanelFakeModule::calls('suspend'));
        $this->assertSame(['fake_id' => 'vm-1'], $service->fresh()->module_data);
        $this->assertTrue(Cache::lock(Provisioner::LOCK_PREFIX.$service->id, 10)->get(), 'The lock is released.');
    }

    public function test_apply_resources_without_a_module_is_done_by_hand(): void
    {
        $product = Product::factory()->create(['server_module' => null]);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id]);

        $result = app(Provisioner::class)->applyResources($service);

        $this->assertTrue($result->success);
        $this->assertTrue($result->data['manual']);
    }

    public function test_apply_resources_fails_for_a_module_that_cannot_change_resources(): void
    {
        $product = Product::factory()->create(['server_module' => 'formpanel']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id]);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => ResourceOption::factory()->create(['product_id' => $product->id])->id]);

        $result = app(Provisioner::class)->applyResources($service);

        $this->assertFalse($result->success);
        $this->assertSame('This server module cannot change resources.', $result->message);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.module_failed')->exists());
    }

    public function test_apply_resources_is_busy_while_other_work_holds_the_service(): void
    {
        $service = $this->fakeService();
        $lock = Cache::lock(Provisioner::LOCK_PREFIX.$service->id, 900);
        $lock->get();

        $result = app(Provisioner::class)->applyResources($service);

        $this->assertFalse($result->success);
        $this->assertTrue($result->data['busy']);
        $this->assertSame([], PanelFakeModule::calls('applyResources'));
        $this->assertFalse(ActivityLog::query()->where('action', 'service.module_failed')->exists(), 'A busy service is tried again, not reported.');
        $lock->release();
    }

    public function test_apply_resources_sends_only_the_kinds_the_module_sells_and_logs_the_others_once(): void
    {
        $service = $this->fakeService();
        $ram = ResourceOption::factory()->create(['product_id' => $service->product_id]);
        $ipv6 = ResourceOption::factory()->kind('ipv6', 1)->create(['product_id' => $service->product_id]);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $ram->id, 'steps' => 2]);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $ipv6->id, 'steps' => 1]);
        PanelFakeModule::$behaviour['restart'] = true;

        $first = app(Provisioner::class)->applyResources($service);
        app(Provisioner::class)->applyResources($service);

        $this->assertTrue($first->success);
        $this->assertTrue($first->data['restart']);
        $this->assertSame(['ram' => 2048], PanelFakeModule::calls('applyResources')[0]['args']['extras']);
        $this->assertSame(1, ActivityLog::query()->where('action', 'service.resources_failed')->where('description', 'like', '%kind ipv6 is not supported%')->count());
        $this->assertSame(2, ActivityLog::query()->where('action', 'service.resources_applied')->count());
    }

    public function test_apply_resources_never_keeps_a_raw_module_error(): void
    {
        Exceptions::fake();
        PanelFakeModule::$behaviour['throw'] = ['applyResources'];
        $service = $this->fakeService();

        $result = app(Provisioner::class)->applyResources($service);

        $this->assertFalse($result->success);
        $this->assertStringNotContainsString(PanelFakeModule::API_KEY, $result->message);
        $this->assertSame(0, ActivityLog::query()->where('description', 'like', '%'.PanelFakeModule::API_KEY.'%')->count());
        Exceptions::assertNotReported(fn (\Throwable $exception): bool => str_contains($exception->getMessage(), PanelFakeModule::API_KEY));
    }

    public function test_resource_kinds_leave_out_a_bad_key_and_a_wrong_built_in_unit_and_log_it_once(): void
    {
        Log::spy();
        $product = Product::factory()->create(['server_module' => 'panelfake']);
        PanelFakeModule::$behaviour['kinds'] = [
            'ram' => new ResourceKind('ram', 'RAM', 'MB'),
            'cpu' => new ResourceKind('cpu', 'CPU cores', 'cores'),
            'Bad Key' => new ResourceKind('Bad Key', 'Bad', ''),
            'other_speed' => new ResourceKind('other_speed', 'Speed', 'KB/s'),
            'panelfake_speed' => new ResourceKind('panelfake_speed', 'Port speed', 'KB/s'),
        ];

        $kinds = app(Provisioner::class)->resourceKinds($product);
        app(Provisioner::class)->resourceKinds($product);

        $this->assertSame(['ram', 'panelfake_speed'], array_keys($kinds));
        Log::shouldHaveReceived('warning')->times(3);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, '"cpu"') && str_contains($message, 'unit "core"'))->once();
    }

    public function test_resource_kinds_for_products_without_a_module_or_the_contract(): void
    {
        $plain = Product::factory()->create(['server_module' => null]);
        $form = Product::factory()->create(['server_module' => 'formpanel']);
        $missing = Product::factory()->create(['server_module' => 'nothere']);

        $this->assertSame(['cpu', 'ram', 'disk', 'ipv4', 'ipv6', 'bandwidth', 'backups'], array_keys(app(Provisioner::class)->resourceKinds($plain)));
        $this->assertFalse(app(Provisioner::class)->resourceKinds($plain)['disk']->canShrink);
        $this->assertSame([], app(Provisioner::class)->resourceKinds($form));
        $this->assertTrue(app(Provisioner::class)->productSupports(Product::factory()->create(['server_module' => 'panelfake']), HasResourceOptions::class));
        $this->assertFalse(app(Provisioner::class)->productSupports($form, UsesMachineInventory::class));
        $this->assertFalse(app(Provisioner::class)->productSupports($missing, HasResourceOptions::class));

        $this->expectException(InvalidArgumentException::class);
        app(Provisioner::class)->resourceKinds($missing);
    }

    public function test_base_resources_come_from_the_module_without_a_server_for_the_hardware_inventory(): void
    {
        $product = Product::factory()->create(['server_module' => 'panelfake']);

        $result = app(Provisioner::class)->baseResources($product);

        $this->assertTrue($result->success);
        $this->assertSame(2048, $result->data['base']['ram']);
        $this->assertNull($result->data['base']['panelfake_speed']);
        $this->assertNull(PanelFakeModule::calls('baseResources')[0]['args']['server']);

        PanelFakeModule::$behaviour['throw'] = ['baseResources'];
        $failed = app(Provisioner::class)->baseResources($product);
        $this->assertFalse($failed->success);
        $this->assertStringNotContainsString(PanelFakeModule::API_KEY, $failed->message);
    }

    public function test_resource_limits_are_kept_for_a_minute_and_hold_additions_when_the_module_fails(): void
    {
        $service = $this->fakeService();
        $ram = ResourceOption::factory()->create(['product_id' => $service->product_id]);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $ram->id, 'steps' => 2]);
        PanelFakeModule::$behaviour['limits'] = ['ram' => new ResourceLimit(1024, 4096, 'The node has room for 4 GB more.')];

        $limits = app(Provisioner::class)->resourceLimits($service);
        app(Provisioner::class)->resourceLimits($service);

        $this->assertSame(4096, $limits['ram']->maxUnits);
        $this->assertSame(1024, $limits['ram']->minUnits);
        $this->assertSame('The node has room for 4 GB more.', $limits['ram']->reason);
        $this->assertCount(1, PanelFakeModule::calls('resourceLimits'), 'The second read comes from the cache.');

        // A minute later the module is asked again; when it fails, nothing may grow for now.
        $this->travel(61)->seconds();
        PanelFakeModule::$behaviour = ['throw' => ['resourceLimits']];

        $fallback = app(Provisioner::class)->resourceLimits($service);

        $this->assertSame(2048, $fallback['ram']->maxUnits);
        $this->assertNull($fallback['ram']->minUnits);
        $this->assertSame('We cannot check your server right now. Try again later.', $fallback['ram']->reason);
    }

    public function test_sign_in_links_account_checks_and_account_links(): void
    {
        $service = $this->fakeService();
        $own = new ClientContext($this->client->id, '198.51.100.7', true, 'https://billing.example.test/client/services/'.$service->id, 'en');
        $other = new ClientContext($this->client->id + 1, '198.51.100.7', true, 'https://billing.example.test/client/services/'.$service->id, 'en');

        $this->assertSame('https://panel.example.test/sso/one-time', app(Provisioner::class)->signInUrl($service, $own));
        $this->assertNull(app(Provisioner::class)->signInUrl($service, $other));

        PanelFakeModule::$behaviour['sign_in_url'] = 'http://panel.example.test/sso/one-time';
        $this->assertNull(app(Provisioner::class)->signInUrl($service, $own), 'A sign-in link is only given over https.');

        $this->assertSame(['ok', 'skip'], array_column(app(Provisioner::class)->checkClientAccess($service), 'status'));
        $this->assertSame(['vps_id'], array_keys(app(Provisioner::class)->linkFields($service)));

        $result = app(Provisioner::class)->linkAccount($service, ['vps_id' => ' 77 ', 'svs' => '1']);

        $this->assertTrue($result->success);
        $this->assertSame(['vps_id' => '77'], PanelFakeModule::calls('linkExistingAccount')[0]['args']['input'], 'Only the module\'s own fields reach it.');
        $this->assertSame('77', $service->fresh()->module_data['fake_id']);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.account_linked')->exists());
    }

    /**
     * Run a queued job the way the worker does: a job that is unique until processing frees its
     * place in the queue first, so it can send itself again.
     */
    private function runQueued(UnsuspendService $job): void
    {
        (new UniqueLock(app(CacheRepository::class)))->release($job);

        $job->handle(app(Provisioner::class));
    }

    /**
     * An active service on the test module, which uses the hardware inventory (no server record).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fakeService(array $attributes = []): Service
    {
        $product = Product::factory()->priced(1000)->create(['server_module' => 'panelfake', 'uses_machines' => true, 'requires_domain' => false]);

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'server_id' => null, 'domain' => 'vps.example.test'] + $attributes);
    }
}
