<?php

namespace Tests\Feature\Panel;

use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Panel\PanelAction;
use App\Extensions\Servers\Panel\PanelSection;
use App\Extensions\Servers\Panel\StandardSections;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Provisioning\PanelGate;
use App\Provisioning\Provisioner;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * The client server panel: section reads and actions through the panel gate (owner, status,
 * product switches, live availability, input allowlist, throttles, read cache, service lock,
 * scrubber, log and email), the serverPanel array of the service page, and the old panel route.
 */
class PanelSectionsTest extends TestCase
{
    use RefreshDatabase, UsesPanelFake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPanelFake();
        Mail::fake();
    }

    public function test_the_owner_reads_a_section_as_json_that_is_never_cached(): void
    {
        $this->travelTo(Date::parse('2026-10-10 12:00:00', 'UTC'));
        // The fake runs on the hardware inventory: no server record is needed.
        $service = $this->panelService();

        $this->actingAs($this->client, 'web')
            ->getJson(route('client.services.sections.show', [$service, 'overview']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('ok', true)
            ->assertJsonPath('section', 'overview')
            ->assertJsonPath('data.state', 'running')
            ->assertJsonPath('data.ips.0.ip', '192.0.2.10')
            ->assertJsonPath('fetched_at', '2026-10-10T12:00:00Z')
            ->assertJsonPath('live', true)
            ->assertJsonMissingPath('data.fetched_at');

        $read = PanelFakeModule::calls('readPanelSection')[0]['args'];
        $this->assertSame('overview', $read['section']);
        $this->assertSame($this->client->id, $read['context']->clientId);
        $this->assertNull($service->fresh()->server_id);
    }

    public function test_other_clients_and_guests_never_reach_the_panel(): void
    {
        $service = $this->panelService();

        $this->get(route('client.services.sections.show', [$service, 'overview']))->assertRedirect(route('client.login'));
        $this->post(route('client.services.sections.action', [$service, 'power', 'start']))->assertRedirect(route('client.login'));

        $this->actingAs(Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null]), 'web');
        $this->getJson(route('client.services.sections.show', [$service, 'overview']))->assertNotFound();
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'start']))->assertNotFound();

        $this->assertSame([], PanelFakeModule::$calls);
    }

    public function test_a_suspended_service_is_refused_and_a_pending_one_is_not_found(): void
    {
        $suspended = $this->panelService(['status' => ServiceStatus::Suspended]);
        $pending = $this->panelService(['status' => ServiceStatus::Pending]);
        $this->actingAs($this->client, 'web');

        $this->getJson(route('client.services.sections.show', [$suspended, 'overview']))->assertForbidden()->assertJsonPath('message', 'This service is suspended.');
        $this->postJson(route('client.services.sections.action', [$suspended, 'power', 'start']))->assertForbidden();
        $this->getJson(route('client.services.sections.show', [$pending, 'overview']))->assertNotFound();
        $this->postJson(route('client.services.sections.action', [$pending, 'power', 'start']))->assertNotFound();

        $this->assertSame([], PanelFakeModule::calls('readPanelSection'));
        $this->assertSame([], PanelFakeModule::calls('runPanelAction'));
        $this->assertNull(app(Provisioner::class)->serverPanel($suspended, $this->contextFor($suspended)));
    }

    public function test_a_section_switched_off_on_the_product_is_refused_and_not_drawn(): void
    {
        $service = $this->panelService([], ['panel_sections' => ['network' => false]]);
        $this->actingAs($this->client, 'web');

        $this->getJson(route('client.services.sections.show', [$service, 'network']))->assertForbidden()->assertJsonPath('message', 'This feature is turned off for your plan.');
        $this->postJson(route('client.services.sections.action', [$service, 'network', 'rdns-set']), ['ip' => '192.0.2.10', 'ptr' => 'mail.example.test'])->assertForbidden();
        // Snapshots start off on every product until staff turn them on.
        $this->getJson(route('client.services.sections.show', [$service, 'snapshots']))->assertForbidden();
        $this->assertSame([], PanelFakeModule::calls('readPanelSection'));

        $panel = app(Provisioner::class)->serverPanel($service, $this->contextFor($service));
        $this->assertSame(['overview', 'console', 'graphs', 'reinstall', 'settings'], array_column($panel['sections'], 'key'));

        $service->product->update(['panel_sections' => ['network' => false, 'snapshots' => true]]);
        $this->getJson(route('client.services.sections.show', [$service, 'snapshots']))->assertOk()->assertJsonPath('data.limit', 2);
    }

    public function test_a_section_the_live_server_does_not_allow_is_hidden_and_refused_for_a_minute(): void
    {
        PanelFakeModule::$behaviour['available'] = ['overview', 'power', 'console'];
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $panel = app(Provisioner::class)->serverPanel($service, $this->contextFor($service));
        $this->assertSame(['overview', 'console'], array_column($panel['sections'], 'key'));
        $this->assertSame(['start', 'stop', 'restart', 'poweroff'], array_column($panel['power'], 'key'));

        $this->getJson(route('client.services.sections.show', [$service, 'graphs']))->assertForbidden()->assertJsonPath('message', 'This feature is not available on your server.');
        $this->postJson(route('client.services.sections.action', [$service, 'network', 'rdns-set']), ['ip' => '192.0.2.10', 'ptr' => 'mail.example.test'])->assertForbidden();

        // The live answer is kept a minute.
        PanelFakeModule::$behaviour['available'] = null;
        $this->getJson(route('client.services.sections.show', [$service, 'graphs']))->assertForbidden();
        $this->assertCount(1, PanelFakeModule::calls('availablePanelSections'));

        $this->travel(61)->seconds();
        $this->getJson(route('client.services.sections.show', [$service, 'graphs']))->assertOk();
    }

    public function test_when_the_module_cannot_tell_its_live_sections_only_overview_and_power_stay(): void
    {
        Exceptions::fake();
        PanelFakeModule::$behaviour['throw'] = ['availablePanelSections'];
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $panel = app(Provisioner::class)->serverPanel($service, $this->contextFor($service));
        $this->assertSame(['overview'], array_column($panel['sections'], 'key'));
        $this->assertNotSame([], $panel['power']);
        $this->assertNull($panel['console']);

        $this->getJson(route('client.services.sections.show', [$service, 'graphs']))->assertForbidden();
        $this->getJson(route('client.services.sections.show', [$service, 'overview']))->assertOk();
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'start']))->assertOk();

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Panel panelfake.available: RuntimeException' && $exception->getPrevious() === null);
    }

    public function test_unknown_sections_and_actions_are_not_found(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $this->getJson(route('client.services.sections.show', [$service, 'firewall']))->assertNotFound();
        $this->getJson(route('client.services.sections.show', [$service, 'power']))->assertNotFound();
        $this->getJson('/client/services/'.$service->id.'/sections/Bad_Key')->assertNotFound();
        $this->postJson(route('client.services.sections.action', [$service, 'network', 'rdns-delete']), ['ip' => '192.0.2.10'])
            ->assertNotFound()
            ->assertJsonPath('message', 'This action is not available.');

        $this->assertSame([], PanelFakeModule::calls('readPanelSection'));
        $this->assertSame([], PanelFakeModule::calls('runPanelAction'));
    }

    public function test_only_the_declared_input_reaches_the_module(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$service, 'network', 'rdns-set']), [
            'ip' => '192.0.2.10', 'ptr' => 'mail.example.test', 'svs' => '999', 'act' => 'users', 'uid' => '1',
        ])->assertOk()->assertJsonPath('message', 'Done.');

        $this->getJson(route('client.services.sections.show', [$service, 'graphs', 'period' => 'week', 'svs' => '999']))->assertOk()->assertJsonPath('data.period.value', 'week');

        $this->assertSame(['ip' => '192.0.2.10', 'ptr' => 'mail.example.test'], PanelFakeModule::calls('runPanelAction')[0]['args']['input']);
        $this->assertSame(['period' => 'week'], PanelFakeModule::calls('readPanelSection')[0]['args']['input']);
    }

    public function test_input_that_does_not_pass_the_rules_is_refused_before_the_module(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$service, 'network', 'rdns-set']), ['ip' => 'not-an-ip'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonValidationErrors('ip');
        $this->getJson(route('client.services.sections.show', [$service, 'graphs', 'period' => 'year']))->assertStatus(422)->assertJsonValidationErrors('period');

        $this->assertSame([], PanelFakeModule::calls('runPanelAction'));
        $this->assertSame([], PanelFakeModule::calls('readPanelSection'));
    }

    public function test_actions_are_limited_per_service(): void
    {
        $first = $this->panelService();
        $second = $this->panelService();
        $this->actingAs($this->client, 'web');

        // Every power action shares one limit of 10 a minute per service.
        foreach (range(1, 10) as $try) {
            $this->postJson(route('client.services.sections.action', [$first, 'power', $try % 2 === 0 ? 'start' : 'restart']))->assertOk();
        }

        $this->postJson(route('client.services.sections.action', [$first, 'power', 'stop']))
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('ok', false);
        $this->assertCount(10, PanelFakeModule::calls('runPanelAction'));

        $this->postJson(route('client.services.sections.action', [$second, 'power', 'stop']))->assertOk()->assertJsonPath('pending', 'stop');
    }

    public function test_reads_are_cached_and_a_change_reads_again(): void
    {
        $this->travelTo(Date::parse('2026-10-10 12:00:00', 'UTC'));
        $service = $this->panelService();
        $gate = app(PanelGate::class);
        $module = app(ExtensionManager::class)->serverModule('panelfake');
        $this->actingAs($this->client, 'web');
        $this->assertNull($gate->overviewState($service, $module));

        $this->getJson(route('client.services.sections.show', [$service, 'overview']))->assertOk();
        $this->travel(5)->seconds();
        // The overview is kept 15 seconds, so the second read shows the first answer and its time.
        $this->getJson(route('client.services.sections.show', [$service, 'overview']))->assertOk()->assertJsonPath('fetched_at', '2026-10-10T12:00:00Z');
        $this->assertCount(1, PanelFakeModule::calls('readPanelSection'));
        $this->assertSame('running', $gate->overviewState($service, $module));

        // A change empties the service's caches.
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'restart']))->assertOk()->assertJsonPath('pending', 'restart');
        $this->assertNull($gate->overviewState($service, $module));
        $this->getJson(route('client.services.sections.show', [$service, 'overview']))->assertOk();
        $this->assertCount(2, PanelFakeModule::calls('readPanelSection'));

        $this->travel(16)->seconds();
        $this->getJson(route('client.services.sections.show', [$service, 'overview']))->assertOk();
        $this->assertCount(3, PanelFakeModule::calls('readPanelSection'));
    }

    public function test_a_change_is_logged_without_the_posted_values(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$service, 'settings', 'root-password']), [
            'password' => 'Correct-Horse-91', 'password_confirmation' => 'Correct-Horse-91',
        ])->assertOk();

        $log = ActivityLog::query()->where('action', 'service.client_action')->sole();
        $this->assertStringContainsString('"settings: root-password"', $log->description);
        $this->assertSame($service->id, $log->subject_id);
        $this->assertStringNotContainsString('Correct-Horse-91', ActivityLog::query()->pluck('description')->implode("\n"));
        $this->assertStringNotContainsString('Correct-Horse-91', (string) json_encode(session()->all()));
    }

    public function test_a_module_error_answers_503_and_is_reported_without_its_message(): void
    {
        Exceptions::fake();
        Log::spy();
        PanelFakeModule::$behaviour['throw'] = ['network.rdns-set', 'graphs'];
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$service, 'network', 'rdns-set']), ['ip' => '192.0.2.10', 'ptr' => 'mail.example.test'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'The server did not answer. Please try again in a minute.')
            ->assertDontSee(PanelFakeModule::API_KEY);

        // Live refreshes of a failing read are reported once in five minutes.
        $this->getJson(route('client.services.sections.show', [$service, 'graphs']))->assertStatus(503);
        $this->getJson(route('client.services.sections.show', [$service, 'graphs']))->assertStatus(503);

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Panel panelfake.network: RuntimeException');
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Panel panelfake.graphs: RuntimeException');
        Exceptions::assertReportedCount(2);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.module_failed')->exists());
        $this->assertStringNotContainsString(PanelFakeModule::API_KEY, ActivityLog::query()->pluck('description')->implode("\n"));
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_secret_is_shown_once_and_only_by_actions_that_reveal(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$service, 'console', 'details']))
            ->assertOk()
            ->assertJsonPath('secret.0.label', 'Root password')
            ->assertJsonPath('secret.0.value', PanelFakeModule::SECRET);
        $this->postJson(route('client.services.sections.action', [$service, 'console', 'details']))->assertOk();
        $this->assertCount(2, PanelFakeModule::calls('runPanelAction'), 'A secret is never cached.');
        $this->assertSame(2, ActivityLog::query()->where('action', 'service.secret_shown')->count());

        // An action that is not declared to reveal never passes a secret on.
        PanelFakeModule::$behaviour['reveal'] = ['network.rdns-set'];
        $this->postJson(route('client.services.sections.action', [$service, 'network', 'rdns-set']), ['ip' => '192.0.2.10', 'ptr' => 'mail.example.test'])
            ->assertOk()
            ->assertJsonPath('secret', null)
            ->assertDontSee(PanelFakeModule::SECRET);
        $this->assertSame(2, ActivityLog::query()->where('action', 'service.secret_shown')->count());
        $this->assertStringNotContainsString(PanelFakeModule::SECRET, ActivityLog::query()->pluck('description')->implode("\n"));
    }

    public function test_keys_that_look_like_secrets_are_removed_from_the_data(): void
    {
        PanelFakeModule::$behaviour['data']['overview'] = [
            'state' => 'running',
            'password' => 'one',
            'vnc_pass' => 'two',
            'credential_mode' => 'set',
            'facts' => [['label' => 'Location', 'value' => 'Test lab', 'api_key' => 'three']],
            'console' => ['token' => 'four', 'root-password' => 'shown', 'host' => 'console.example.test'],
        ];
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $this->getJson(route('client.services.sections.show', [$service, 'overview']))
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.vnc_pass')
            ->assertJsonMissingPath('data.facts.0.api_key')
            ->assertJsonMissingPath('data.console.token')
            ->assertJsonPath('data.credential_mode', 'set')
            ->assertJsonPath('data.facts.0.value', 'Test lab')
            ->assertJsonPath('data.console.host', 'console.example.test');
    }

    public function test_without_javascript_an_action_goes_back_to_its_tab_and_a_reveal_is_refused(): void
    {
        $service = $this->panelService();
        $tab = fn (string $section): string => route('client.services.show', $service).'#panel-'.$section;
        $this->actingAs($this->client, 'web');

        $this->post(route('client.services.sections.action', [$service, 'network', 'rdns-set']), ['ip' => '192.0.2.10', 'ptr' => 'mail.example.test'])
            ->assertRedirect($tab('network'))
            ->assertSessionHas('status', 'Done.');

        $this->post(route('client.services.sections.action', [$service, 'network', 'rdns-set']), ['ip' => 'nope', 'ptr' => 'mail.example.test'])
            ->assertRedirect($tab('network'))
            ->assertSessionHasErrors('ip')
            ->assertSessionMissing('_old_input');

        // The secret could not be shown, so the action does not run at all.
        $this->post(route('client.services.sections.action', [$service, 'console', 'details']))
            ->assertRedirect($tab('console'))
            ->assertSessionHas('error', 'Turn on JavaScript to do this.');
        $this->post(route('client.services.sections.action', [$service, 'reinstall', 'run']), ['template' => 'debian-12', 'confirm' => '1'])
            ->assertSessionHas('error', 'Turn on JavaScript to do this.');

        $this->assertSame(['network'], array_column(array_column(PanelFakeModule::calls('runPanelAction'), 'args'), 'section'));
        $this->assertStringNotContainsString(PanelFakeModule::SECRET, (string) json_encode(session()->all()));
    }

    public function test_a_destructive_action_needs_the_confirm_box(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'run']), ['template' => 'debian-12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'poweroff']))->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->assertSame([], PanelFakeModule::calls('runPanelAction'));

        $this->postJson(route('client.services.sections.action', [$service, 'reinstall', 'run']), ['template' => 'debian-12', 'confirm' => '1'])
            ->assertOk()
            ->assertJsonPath('secret.0.value', PanelFakeModule::SECRET);
        $this->assertSame(['template' => 'debian-12', 'confirm' => '1'], PanelFakeModule::calls('runPanelAction')[0]['args']['input']);
    }

    public function test_a_change_waits_for_other_work_on_the_service_and_reads_its_status_again(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');
        $lock = app(Provisioner::class)->lockFor($service, 120);
        $this->assertTrue($lock->get());

        $this->postJson(route('client.services.sections.action', [$service, 'power', 'start']))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Another change is running on this server. Try again in a moment.');
        // Showing details changes nothing, so it does not wait for the lock.
        $this->postJson(route('client.services.sections.action', [$service, 'console', 'details']))->assertOk();
        $this->assertSame(['console'], array_column(array_column(PanelFakeModule::calls('runPanelAction'), 'args'), 'section'));

        $lock->release();
        $this->postJson(route('client.services.sections.action', [$service, 'power', 'start']))->assertOk();

        // A suspend that finished while the request waited is seen inside the lock.
        $stale = $service->fresh();
        $service->update(['status' => ServiceStatus::Suspended]);
        $result = app(Provisioner::class)->panelAction($stale, 'power', 'stop', [], $this->contextFor($stale), true);
        $this->assertSame(403, $result->status);
        $this->assertCount(2, PanelFakeModule::calls('runPanelAction'));
    }

    public function test_the_client_gets_one_email_about_a_change_every_ten_minutes(): void
    {
        $service = $this->panelService();
        $send = fn () => $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->postJson(route('client.services.sections.action', [$service, 'network', 'rdns-set']), ['ip' => '192.0.2.10', 'ptr' => 'mail.example.test'])
            ->assertOk();
        $this->actingAs($this->client, 'web');

        $send();
        $send();
        Mail::assertSent(TemplatedMessage::class, 1);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('mer.las@example.test')
            && $mail->subjectLine === 'Change on your server vps.example.test'
            && str_contains($mail->bodyHtml, 'Reverse DNS changed')
            && str_contains($mail->bodyHtml, '198.51.100.20'));

        $this->travel(11)->minutes();
        $send();
        Mail::assertSent(TemplatedMessage::class, 2);

        $this->setSettings(['services.panel_change_emails' => false]);
        $this->travel(11)->minutes();
        $send();
        Mail::assertSent(TemplatedMessage::class, 2);
    }

    public function test_a_body_over_64_kb_is_refused(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$service, 'network', 'rdns-set']), ['ip' => '192.0.2.10', 'ptr' => str_repeat('a', 70000)])
            ->assertStatus(413)
            ->assertJsonPath('ok', false);
        $this->assertSame([], PanelFakeModule::calls('runPanelAction'));
    }

    public function test_the_demo_locks_actions_and_keeps_reads(): void
    {
        config(['nuvabill.demo' => true]);
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.sections.action', [$service, 'power', 'restart']))
            ->assertForbidden()
            ->assertJsonPath('message', 'This is turned off in the demo, so it keeps working for every visitor.');
        $this->getJson(route('client.services.sections.show', [$service, 'overview']))->assertOk();
        $this->assertSame([], PanelFakeModule::calls('runPanelAction'));
    }

    public function test_the_service_page_gets_the_panel_with_its_tabs_in_the_declared_order(): void
    {
        $service = $this->panelService();
        $this->actingAs($this->client, 'web');

        $panel = app(Provisioner::class)->serverPanel($service, $this->contextFor($service));

        $this->assertSame('theme::client.services.server-panel', $panel['view']);
        $this->assertSame(['overview', 'console', 'graphs', 'network', 'reinstall', 'settings'], array_column($panel['sections'], 'key'));
        $this->assertSame('theme::client.services.panel.graphs', $panel['sections'][2]['view']);
        $this->assertSame(route('client.services.sections.show', [$service, 'graphs']), $panel['sections'][2]['read_url']);
        $this->assertSame([
            'label' => 'Save reverse DNS',
            'url' => route('client.services.sections.action', [$service, 'network', 'rdns-set']),
            'confirm' => null,
            'destructive' => false,
            'reveals' => false,
        ], $panel['sections'][3]['actions']['rdns-set']);
        $this->assertTrue($panel['sections'][4]['actions']['run']['destructive']);
        $this->assertSame(['start', 'stop', 'restart', 'poweroff'], array_column($panel['power'], 'key'));
        $this->assertSame(['running', 'stopped', 'running', 'stopped'], array_column($panel['power'], 'expects'));
        $this->assertSame(route('client.services.sections.action', [$service, 'power', 'stop']), $panel['power'][1]['url']);
        $this->assertTrue($panel['power'][3]['destructive']);
        $this->assertSame(['url' => route('client.services.console.open', $service)], $panel['console']);
        $this->assertSame(['label' => 'Open Panel fake panel', 'url' => route('client.services.login', $service)], $panel['login']);
        $this->assertSame('running', $panel['state']);
        $this->assertSame(route('client.services.panel-status', $service), $panel['status_url']);
        $this->assertNull($panel['resources_url']);
        $this->assertSame([], $panel['data']);

        $this->get(route('client.services.show', $service))
            ->assertOk()
            ->assertSee('Graphs')
            ->assertDontSee('Snapshots')
            ->assertDontSee('Open control panel');
    }

    public function test_the_live_refresh_of_the_overview_never_runs_faster_than_the_setting_or_the_module_allows(): void
    {
        $service = $this->panelService();
        $poll = function (?int $pollSeconds) use ($service): ?int {
            PanelFakeModule::$behaviour['sections'] = [PanelSection::standard('overview', pollSeconds: $pollSeconds), PanelSection::standard('graphs', pollSeconds: 45)];
            $sections = app(Provisioner::class)->serverPanel($service, $this->contextFor($service))['sections'];

            $this->assertSame(45, $sections[1]['poll']);

            return $sections[0]['poll'];
        };

        $this->assertSame(10, $poll(null));
        $this->assertSame(30, $poll(30));

        $this->setSettings(['services.panel_live_seconds' => 20]);
        $this->assertSame(20, $poll(15));
    }

    public function test_bad_section_declarations_are_left_out_and_logged_once(): void
    {
        Log::spy();
        PanelFakeModule::$behaviour['sections'] = [
            PanelSection::standard('overview'),
            PanelSection::standard('overview', cacheSeconds: 5),
            new PanelSection(key: 'notes', label: 'Notes', view: 'ext-other::notes'),
            PanelSection::standard('network', actions: ['wipe' => new PanelAction('wipe', 'Wipe', destructive: true)]),
            PanelFakeModule::customSection(),
        ];
        $service = $this->panelService();

        $sections = app(Provisioner::class)->serverPanel($service, $this->contextFor($service))['sections'];
        app(Provisioner::class)->serverPanel($service, $this->contextFor($service));

        $this->assertSame(['overview', 'notes'], array_column($sections, 'key'));
        $this->assertSame('ext-panelfake::notes', $sections[1]['view']);
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_the_old_panel_route_goes_through_the_gate_for_modules_with_sections(): void
    {
        $service = $this->panelService([], ['panel_sections' => ['power' => false]]);
        $this->actingAs($this->client, 'web');

        $this->postJson(route('client.services.panel', [$service, 'restart']))
            ->assertForbidden()
            ->assertJsonPath('message', 'This feature is turned off for your plan.');
        $this->assertSame([], PanelFakeModule::calls('runPanelAction'));

        $service->product->update(['panel_sections' => null]);
        $this->postJson(route('client.services.panel', [$service, 'restart']))
            ->assertOk()
            ->assertJsonPath('pending', 'restart')
            ->assertJsonPath('state', 'running');
        $this->assertSame(['power', 'restart'], [PanelFakeModule::calls('runPanelAction')[0]['args']['section'], PanelFakeModule::calls('runPanelAction')[0]['args']['action']]);

        // The old VNC button is a reveal: JSON only, and never into the session.
        $this->post(route('client.services.panel', [$service, 'vnc']))->assertSessionHas('error', 'Turn on JavaScript to do this.')->assertSessionMissing('panel_result');
        $this->postJson(route('client.services.panel', [$service, 'vnc']))->assertOk()->assertJsonPath('secret.0.value', PanelFakeModule::SECRET);
        $this->postJson(route('client.services.panel', [$service, 'format-disk']))->assertNotFound()->assertJsonPath('message', 'This action is not available.');

        // The old reinstall form's os_id is the template, and its password was typed once.
        $this->postJson(route('client.services.panel', [$service, 'reinstall']), ['os_id' => 'debian-12', 'confirm' => '1'])->assertOk();
        $this->assertSame(['template' => 'debian-12', 'confirm' => '1'], PanelFakeModule::calls('runPanelAction')[2]['args']['input']);
        $this->postJson(route('client.services.panel', [$service, 'password']), ['password' => 'Correct-Horse-91'])->assertOk();
        $this->assertSame(['settings', 'root-password'], [PanelFakeModule::calls('runPanelAction')[3]['args']['section'], PanelFakeModule::calls('runPanelAction')[3]['args']['action']]);
    }

    public function test_the_panel_routes_are_limited_and_locked_in_the_demo(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertContains('throttle:panel-reads', $routes->getByName('client.services.sections.show')->gatherMiddleware());
        $this->assertContains('throttle:panel-actions', $routes->getByName('client.services.sections.action')->gatherMiddleware());

        foreach (['client.services.sections.action', 'client.services.console.open', 'client.services.console.redeem', 'admin.services.access-check', 'admin.services.link'] as $name) {
            $this->assertContains($name, Demo::LOCKED_ROUTES);
        }

        $this->assertNotContains('client.services.sections.show', Demo::LOCKED_ROUTES);
        $this->assertSame([10, 60], StandardSections::DEFAULT_THROTTLES['power.*']);
    }
}
