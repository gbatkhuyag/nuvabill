<?php

namespace Tests\Feature\Billing;

use App\Automation\DailyAutomation;
use App\Billing\ResourceChanges;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Jobs\ApplyServiceResources;
use App\Jobs\CheckServiceResources;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Product;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Provisioning\Provisioner;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * After the billing side of a resource change is applied, a queued job sets the server: it tries 3
 * times, waits without using a try while other work holds the service, checks a slow module again
 * later, asks staff when it cannot finish, and the nightly run sends lost jobs again. Clients only
 * ever see plain general texts, never the server's own message.
 */
class ResourceServerApplyTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Service $service;

    private ResourceOption $ram;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        PanelFakeModule::reset();
        Mail::fake();
        Queue::fake();

        $this->travelTo('2026-10-15 10:00');
        $this->setSettings(['billing.currency' => 'USD', 'billing.resource_changes' => true, 'company.email' => 'billing@example.test']);

        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null, 'email' => 'mer.las@example.test', 'currency' => 'USD']);
        $product = Product::factory()->priced(1000)->withoutDomain()->create(['name' => 'VPS 2', 'server_module' => 'panelfake', 'uses_machines' => true]);
        $this->ram = ResourceOption::factory()->priced(200)->create(['product_id' => $product->id]);
        $this->service = Service::factory()->create([
            'client_id' => $this->client->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'registration_date' => '2026-07-01',
            'next_due_date' => '2026-11-01',
            'domain' => 'vps1.example.test',
        ]);
    }

    public function test_the_apply_job_retries_then_marks_failed_and_emails_staff(): void
    {
        PanelFakeModule::$behaviour['fail'] = ['applyResources'];
        $change = $this->appliedChange(2);
        $changes = app(ResourceChanges::class);

        $changes->applyToServer($change->id);
        $this->assertSame([ResourceChange::SERVER_QUEUED, 1, 'The fake panel could not set the resources.'], [$change->fresh()->server_state, $change->fresh()->server_attempts, $change->fresh()->server_message]);
        Queue::assertPushed(ApplyServiceResources::class, fn (ApplyServiceResources $job): bool => $job->delay === 60);

        $this->startWaitingJobs();
        $changes->applyToServer($change->id);
        Queue::assertPushed(ApplyServiceResources::class, fn (ApplyServiceResources $job): bool => $job->delay === 300);

        $this->startWaitingJobs();
        $changes->applyToServer($change->id);
        $this->assertSame([ResourceChange::SERVER_FAILED, 3], [$change->fresh()->server_state, $change->fresh()->server_attempts]);
        Queue::assertPushed(ApplyServiceResources::class, 2);
        $this->assertCount(3, PanelFakeModule::calls('applyResources'));
        $this->assertTrue(ActivityLog::query()->where('action', 'service.resources_failed')->exists());
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('billing@example.test')
            && $mail->subjectLine === "Resources not set on service #{$this->service->id}"
            && str_contains($mail->bodyHtml, 'The fake panel could not set the resources.'));
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('mer.las@example.test'));

        // The client sees a general text, never the server's own words.
        $this->actingAs($this->client, 'web')
            ->get(route('client.services.show', $this->service))
            ->assertOk()
            ->assertSee('We could not add your resources yet. Our team is on it.')
            ->assertDontSee('The fake panel could not set the resources.');
        $this->getJson(route('client.services.resources', $this->service))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['state' => 'failed', 'text' => 'We could not add your resources yet. Our team is on it.', 'busy' => false]);
    }

    public function test_a_module_error_never_reaches_the_log_or_the_staff_message_raw(): void
    {
        PanelFakeModule::$behaviour['throw'] = ['applyResources'];
        $change = $this->appliedChange(1);

        foreach (range(1, 3) as $try) {
            app(ResourceChanges::class)->applyToServer($change->id);
        }

        $this->assertSame(ResourceChange::SERVER_FAILED, $change->fresh()->server_state);
        $this->assertStringNotContainsString(PanelFakeModule::API_KEY, (string) $change->fresh()->server_message);
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%'.PanelFakeModule::API_KEY.'%')->exists());
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->bodyHtml, PanelFakeModule::API_KEY));
    }

    public function test_a_busy_service_lock_delays_without_using_an_attempt(): void
    {
        $change = $this->appliedChange(2);
        $lock = app(Provisioner::class)->lockFor($this->service);
        $this->assertTrue($lock->get());

        app(ResourceChanges::class)->applyToServer($change->id);

        $this->assertSame([ResourceChange::SERVER_QUEUED, 0], [$change->fresh()->server_state, $change->fresh()->server_attempts]);
        Queue::assertPushed(ApplyServiceResources::class, fn (ApplyServiceResources $job): bool => $job->delay === 120 && $job->busyTry === 1);
        $this->assertSame([], PanelFakeModule::calls('applyResources'));

        $lock->release();
        app(ResourceChanges::class)->applyToServer($change->id, 1);
        $this->assertSame([ResourceChange::SERVER_DONE, 1], [$change->fresh()->server_state, $change->fresh()->server_attempts]);
    }

    public function test_a_pending_module_result_is_checked_until_it_matches(): void
    {
        PanelFakeModule::$behaviour['pending'] = ['applyResources'];
        $change = $this->appliedChange(2);

        app(ResourceChanges::class)->applyToServer($change->id);

        $this->assertSame(ResourceChange::SERVER_CHECKING, $change->fresh()->server_state);
        Queue::assertPushed(CheckServiceResources::class, fn (CheckServiceResources $job): bool => $job->changeId === $change->id && $job->delay === 120);
        Mail::assertNothingSent();
        $this->assertSame('working', app(ResourceChanges::class)->serverStatus($this->service)['state']);

        app(ResourceChanges::class)->checkServer($change->id);

        $this->assertSame([ResourceChange::SERVER_DONE, 1], [$change->fresh()->server_state, $change->fresh()->server_checks]);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('mer.las@example.test') && $mail->subjectLine === 'Your server resources changed');
        $this->assertSame('done', app(ResourceChanges::class)->serverStatus($this->service)['state']);
    }

    public function test_a_pending_module_result_that_never_matches_fails_after_four_checks(): void
    {
        PanelFakeModule::$behaviour['pending'] = ['applyResources'];
        PanelFakeModule::$behaviour['live'] = ['ram' => 2048];
        $change = $this->appliedChange(2);
        app(ResourceChanges::class)->applyToServer($change->id);

        foreach ([300, 600, 600] as $delay) {
            $this->startWaitingJobs();
            app(ResourceChanges::class)->checkServer($change->id);
            $this->assertSame(ResourceChange::SERVER_CHECKING, $change->fresh()->server_state);
            Queue::assertPushed(CheckServiceResources::class, fn (CheckServiceResources $job): bool => $job->delay === $delay);
        }

        $this->startWaitingJobs();
        app(ResourceChanges::class)->checkServer($change->id);

        $this->assertSame([ResourceChange::SERVER_FAILED, 4], [$change->fresh()->server_state, $change->fresh()->server_checks]);
        $this->assertStringContainsString('ram: 2048, expected 4096', (string) $change->fresh()->server_message);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('billing@example.test'));
    }

    public function test_the_nightly_run_sends_lost_apply_jobs_again(): void
    {
        $applying = $this->appliedChange(1, ['server_state' => ResourceChange::SERVER_APPLYING, 'server_attempts' => 1]);
        $queued = $this->appliedChange(1, ['server_state' => ResourceChange::SERVER_QUEUED], Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->service->product_id]));
        $checking = $this->appliedChange(1, ['server_state' => ResourceChange::SERVER_CHECKING], Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->service->product_id]));
        $fresh = $this->appliedChange(1, ['server_state' => ResourceChange::SERVER_QUEUED], Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->service->product_id]));
        ResourceChange::query()->whereKey([$applying->id, $queued->id, $checking->id])->update(['updated_at' => now()->subHours(2)]);
        ResourceChange::query()->whereKey($fresh->id)->update(['updated_at' => now()->subMinutes(30)]);

        app(DailyAutomation::class)->run(CarbonImmutable::parse('2026-10-15'));

        Queue::assertPushed(ApplyServiceResources::class, fn (ApplyServiceResources $job): bool => $job->changeId === $applying->id);
        Queue::assertPushed(ApplyServiceResources::class, fn (ApplyServiceResources $job): bool => $job->changeId === $queued->id);
        Queue::assertPushed(CheckServiceResources::class, fn (CheckServiceResources $job): bool => $job->changeId === $checking->id);
        Queue::assertNotPushed(ApplyServiceResources::class, fn (ApplyServiceResources $job): bool => $job->changeId === $fresh->id);
        $this->assertSame([ResourceChange::SERVER_QUEUED, 1], [$applying->fresh()->server_state, $applying->fresh()->server_attempts], 'The try the lost job used still counts.');
    }

    public function test_a_later_successful_apply_marks_older_failed_changes_done(): void
    {
        $older = $this->appliedChange(1, ['server_state' => ResourceChange::SERVER_FAILED, 'server_attempts' => 3]);
        $newer = $this->appliedChange(2);

        app(ResourceChanges::class)->applyToServer($newer->id);

        $this->assertSame([ResourceChange::SERVER_DONE, ResourceChange::SERVER_DONE], [$older->fresh()->server_state, $newer->fresh()->server_state]);
        $this->assertSame(['ram' => 2048], PanelFakeModule::$applied[$this->service->id], 'The job always sends what the service has now.');
    }

    public function test_staff_billing_only_change_does_not_call_the_server(): void
    {
        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 2], Admin::factory()->create(['name' => 'Raz']), charge: false, setsServer: false);

        $this->assertSame([ResourceChange::STATUS_APPLIED, ResourceChange::SERVER_DONE], [$change->status, $change->server_state]);
        Queue::assertNothingPushed();
        $this->assertSame([], PanelFakeModule::calls('applyResources'));
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('mer.las@example.test') && $mail->subjectLine === 'Your server resources changed');
    }

    public function test_a_service_that_ended_before_its_job_ran_sets_nothing(): void
    {
        $change = $this->appliedChange(2);
        $this->service->update(['status' => ServiceStatus::Terminated]);

        app(ResourceChanges::class)->applyToServer($change->id);

        $this->assertSame(ResourceChange::SERVER_NONE, $change->fresh()->server_state);
        $this->assertSame([], PanelFakeModule::calls('applyResources'));
        Mail::assertNothingSent();
    }

    public function test_staff_try_again_and_apply_now(): void
    {
        $admin = Admin::factory()->create(['name' => 'Raz']);
        $change = $this->appliedChange(2, ['server_state' => ResourceChange::SERVER_FAILED, 'server_attempts' => 3]);

        app(ResourceChanges::class)->retryNow($change, $admin);
        $this->assertSame([ResourceChange::SERVER_QUEUED, 0], [$change->fresh()->server_state, $change->fresh()->server_attempts]);
        Queue::assertPushed(ApplyServiceResources::class);

        $result = app(ResourceChanges::class)->applyNow($this->service);
        $this->assertTrue($result->success);
        $this->assertSame(ResourceChange::SERVER_DONE, $change->fresh()->server_state);
        $this->assertSame(['ram' => 2048], PanelFakeModule::$applied[$this->service->id]);
    }

    /**
     * A queue worker starts the waiting jobs, which frees their unique locks, as a real worker does
     * (the fake queue never runs them).
     */
    private function startWaitingJobs(): void
    {
        $lock = new UniqueLock(app(CacheRepository::class));

        foreach ([ApplyServiceResources::class, CheckServiceResources::class] as $class) {
            Queue::pushed($class)->each(fn (object $job) => $lock->release($job));
        }
    }

    /**
     * A client change whose billing side is applied: the service has these RAM steps now, and the
     * server job waits.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function appliedChange(int $steps, array $attributes = [], ?Service $service = null): ResourceChange
    {
        $service ??= $this->service;
        ServiceResourceOption::query()->updateOrCreate(
            ['service_id' => $service->id, 'kind' => 'ram'],
            ['resource_option_id' => $this->ram->id, 'unit' => 'MB', 'step_size' => 1024, 'steps' => $steps, 'recurring_amount' => 200 * $steps, 'status' => ServiceResourceOption::STATUS_ACTIVE],
        );

        return ResourceChange::factory()->applied()->withItem($this->ram, 0, $steps, 0, 200 * $steps)->create([
            'service_id' => $service->id,
            'mode' => ResourceChange::MODE_INVOICE,
            'server_state' => ResourceChange::SERVER_QUEUED,
            ...$attributes,
        ]);
    }
}
