<?php

namespace Tests\Feature\Billing;

use App\Billing\PaymentRecorder;
use App\Billing\ResourceChanges;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * The "Upgrade resources" card on the client's service page, the review page that works without
 * JavaScript, the quote and state answers, and the staff tools on the admin service page.
 */
class ResourceChangePagesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Product $vps;

    private ResourceOption $ram;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        PanelFakeModule::reset();
        Mail::fake();

        $this->travelTo('2026-10-15 10:00');
        $this->setSettings(['billing.currency' => 'USD', 'billing.resource_changes' => true, 'billing.downgrade' => 'credit', 'wallet.enabled' => true, 'company.email' => 'billing@example.test']);

        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null, 'email' => 'mer.las@example.test', 'currency' => 'USD']);
        $this->vps = Product::factory()->priced(1000)->withoutDomain()->create(['name' => 'VPS 2']);
        $this->ram = ResourceOption::factory()->priced(200)->create(['product_id' => $this->vps->id]);
        ResourceOption::factory()->kind('disk', 10)->priced(100)->create(['product_id' => $this->vps->id, 'sort_order' => 1]);
        $this->service = Service::factory()->create([
            'client_id' => $this->client->id,
            'product_id' => $this->vps->id,
            'status' => ServiceStatus::Active,
            'recurring_amount' => 1000,
            'registration_date' => '2026-07-01',
            'next_due_date' => '2026-11-01',
            'domain' => 'vps1.example.test',
        ]);
        ServiceResourceOption::factory()->create(['service_id' => $this->service->id, 'resource_option_id' => $this->ram->id, 'steps' => 2, 'recurring_amount' => 400]);
    }

    public function test_the_service_page_shows_the_upgrade_card_with_labelled_sliders(): void
    {
        $this->actingAs($this->client, 'web')
            ->get(route('client.services.show', $this->service))
            ->assertOk()
            ->assertSee('Upgrade resources')
            ->assertSee('id="resources"', false)
            ->assertSee('RAM +2 GB')
            ->assertSee('name="steps[ram]"', false)
            ->assertSee('<label class="range-label" for="svc-rs-ram">', false)
            ->assertSee('name="steps[disk]"', false)
            ->assertSee('Disk can grow but not shrink.')
            ->assertSee('Upgrades are added once paid.')
            ->assertSee('x-data="resourceQuote(', false)
            ->assertSee('Review the price');
    }

    public function test_without_javascript_the_review_page_works_out_the_price_and_confirms_it(): void
    {
        $this->actingAs($this->client, 'web');

        $this->get(route('client.services.resources', ['service' => $this->service, 'steps' => ['ram' => 5, 'disk' => 0]]))
            ->assertOk()
            ->assertSee('Upgrade resources')
            ->assertSee('RAM')
            ->assertSee('Pay $3.29 and upgrade')
            ->assertSee('name="expected" value="329"', false)
            ->assertSee('name="steps[ram]" value="5"', false)
            ->assertDontSee('name="steps[disk]" value="0"', false);

        $this->get(route('client.services.resources', ['service' => $this->service, 'steps' => ['ram' => 99]]))
            ->assertOk()
            ->assertSee('Choose between 0 and 8 for RAM.');

        $this->post(route('client.services.resources.store', $this->service), ['steps' => ['ram' => 5], 'expected' => 329])
            ->assertRedirect(route('client.invoices.show', ResourceChange::query()->sole()->invoice));
    }

    public function test_the_quote_answers_json_without_saving_anything(): void
    {
        $this->actingAs($this->client, 'web')
            ->postJson(route('client.services.resources.quote', $this->service), ['steps' => ['ram' => 5]])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('button', 'Pay $3.29 and upgrade')
            ->assertJsonPath('expected', 329)
            ->assertJsonPath('mode', 'invoice')
            ->assertJsonPath('changes.0.from_text', '+2 GB')
            ->assertJsonPath('changes.0.to_text', '+5 GB');

        $this->postJson(route('client.services.resources.quote', $this->service), ['steps' => ['ram' => 2]])
            ->assertOk()
            ->assertJsonPath('changes', [])
            ->assertJsonPath('button', null);

        $this->postJson(route('client.services.resources.quote', $this->service), ['steps' => ['disk' => 2, 'ipv4' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('errors.steps.0', 'We do not know the option ipv4.');

        $this->assertSame(0, ResourceChange::query()->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_removing_addresses_asks_to_confirm(): void
    {
        $ipv4 = ResourceOption::factory()->kind('ipv4', 1)->priced(300)->create(['product_id' => $this->vps->id]);
        ServiceResourceOption::factory()->create(['service_id' => $this->service->id, 'resource_option_id' => $ipv4->id, 'steps' => 2, 'recurring_amount' => 600]);

        $this->actingAs($this->client, 'web')
            ->postJson(route('client.services.resources.quote', $this->service), ['steps' => ['ipv4' => 1]])
            ->assertOk()
            ->assertJsonPath('confirm', 'Removing addresses takes them off your server. Sites that use them stop working.')
            ->assertJsonPath('button', 'Lower my extras');
    }

    public function test_another_clients_service_gives_404_on_every_resource_route(): void
    {
        $this->actingAs(Client::factory()->create(['first_name' => 'Raz', 'last_name' => 'Raz']), 'web');

        $this->get(route('client.services.resources', $this->service))->assertNotFound();
        $this->getJson(route('client.services.resources', $this->service))->assertNotFound();
        $this->postJson(route('client.services.resources.quote', $this->service), ['steps' => ['ram' => 3]])->assertNotFound();
        $this->post(route('client.services.resources.store', $this->service), ['steps' => ['ram' => 3]])->assertNotFound();
        $this->delete(route('client.services.resources.destroy', $this->service))->assertNotFound();

        $this->assertSame(0, ResourceChange::query()->count());
    }

    public function test_when_client_changes_are_off_the_card_is_read_only(): void
    {
        $this->setSettings(['billing.resource_changes' => false]);
        $this->actingAs($this->client, 'web');

        $this->get(route('client.services.show', $this->service))
            ->assertOk()
            ->assertSee('RAM +2 GB')
            ->assertDontSee('name="steps[ram]"', false)
            ->assertSee('Contact us to change your extras.');
        $this->get(route('client.services.resources', $this->service))->assertNotFound();
        $this->postJson(route('client.services.resources.quote', $this->service), ['steps' => ['ram' => 3]])->assertNotFound();
        $this->post(route('client.services.resources.store', $this->service), ['steps' => ['ram' => 3]])->assertNotFound();
        $this->getJson(route('client.services.resources', $this->service))->assertOk()->assertJsonPath('state', 'none');
    }

    public function test_a_waiting_change_shows_pay_and_stop_on_the_card(): void
    {
        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 3]);

        $this->actingAs($this->client, 'web')
            ->get(route('client.services.show', $this->service))
            ->assertOk()
            ->assertSee('Waiting for payment of invoice '.$change->invoice->displayNumber().'.')
            ->assertSee('RAM: +2 GB → +3 GB')
            ->assertSee(route('client.invoices.show', $change->invoice), false)
            ->assertSee('Stop this change')
            ->assertDontSee('name="steps[ram]"', false);
    }

    public function test_the_card_json_follows_the_server_state(): void
    {
        Queue::fake();
        $this->vps->update(['server_module' => 'panelfake', 'uses_machines' => true]);
        $this->actingAs($this->client, 'web');
        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 3]);
        app(PaymentRecorder::class)->record($change->invoice, $change->invoice->total, 'banktransfer');

        $this->getJson(route('client.services.resources', $this->service))
            ->assertExactJson(['state' => 'working', 'text' => 'We are adding your resources. This can take a few minutes.', 'busy' => true]);
        $this->get(route('client.services.show', $this->service))
            ->assertSee('We are adding your resources. This can take a few minutes.')
            ->assertDontSee('We are still setting the last change on your server.');

        app(ResourceChanges::class)->applyToServer($change->id);

        $this->getJson(route('client.services.resources', $this->service))
            ->assertJsonPath('state', 'done')
            ->assertJsonPath('busy', false);
    }

    public function test_staff_see_the_card_and_change_resources_from_the_service_page(): void
    {
        $admin = $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.services.show', $this->service))
            ->assertOk()
            ->assertSee('Change resources')
            ->assertSee('name="steps[ram]"', false)
            ->assertSee('name="amounts[ram]"', false)
            ->assertSee('Also set it on the server');

        $this->postJson(route('admin.services.resources.quote', $this->service), ['steps' => ['ram' => 4], 'charge' => '0'])
            ->assertOk()
            ->assertJsonPath('message', 'The change happens now, with nothing to pay or credit.');

        $this->post(route('admin.services.resources.change', $this->service), ['steps' => ['ram' => 4, 'disk' => 0], 'amounts' => ['ram' => '7.50', 'disk' => ''], 'charge' => '0', 'sets_server' => '1'])
            ->assertSessionHas('status', 'The extras were changed. Set them on the server by hand, then press Mark done.');

        $row = $this->service->resources()->where('kind', 'ram')->sole();
        $this->assertSame([4, 750], [$row->steps, $row->recurring_amount]);
        $change = ResourceChange::query()->sole();
        $this->assertSame([$admin->id, ResourceChange::SERVER_MANUAL], [$change->admin_id, $change->server_state]);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('billing@example.test') && str_contains($mail->bodyHtml, 'RAM: +4 GB'));

        $this->get(route('admin.services.show', $this->service))->assertSee('Set by hand')->assertSee('Mark done');

        $this->post(route('admin.services.resources.done', $this->service))->assertSessionHas('status', 'The change is marked as done. The client got an email.');
        $this->assertSame(ResourceChange::SERVER_DONE, $change->fresh()->server_state);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('mer.las@example.test') && $mail->subjectLine === 'Your server resources changed');
    }

    public function test_staff_stop_a_waiting_change_and_use_the_server_tools(): void
    {
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->vps->update(['server_module' => 'panelfake', 'uses_machines' => true]);
        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 3]);

        $this->delete(route('admin.services.resources.cancel', $this->service))->assertSessionHas('status', 'The resource change was stopped.');
        $this->assertSame(ResourceChange::STATUS_CANCELLED, $change->fresh()->status);

        PanelFakeModule::$behaviour['live'] = ['ram' => 1024];
        $this->post(route('admin.services.resources.check', $this->service))
            ->assertSessionHas('resource_check', fn (array $check): bool => $check['matches'] === false && $check['expected']['ram'] === 2048 + 2048);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.resources_unverified')->exists());
        $this->get(route('admin.services.show', $this->service))->assertSee('The server does not match the plan plus the extras.');

        PanelFakeModule::$behaviour = [];
        $this->post(route('admin.services.resources.apply', $this->service))->assertSessionHas('status', 'The extras were set on the server.');
        $this->assertSame(['ram' => 2048, 'disk' => 0], PanelFakeModule::$applied[$this->service->id]);
    }

    public function test_staff_without_services_manage_cannot_use_the_tools(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view'])->create(['name' => 'Raz']));

        $this->postJson(route('admin.services.resources.quote', $this->service), ['steps' => ['ram' => 3]])->assertForbidden();
        $this->post(route('admin.services.resources.change', $this->service), ['steps' => ['ram' => 3], 'charge' => '0'])->assertForbidden();
        $this->assertSame(0, ResourceChange::query()->count());
    }

    public function test_the_credit_note_form_warns_that_resources_stay(): void
    {
        $change = app(ResourceChanges::class)->start($this->service, ['ram' => 3]);
        app(PaymentRecorder::class)->record($change->invoice, $change->invoice->total, 'banktransfer');
        $this->signInAdmin();

        $this->get(route('admin.invoices.show', $change->invoice))
            ->assertOk()
            ->assertSee('Resources from this invoice are still on the service.');
    }
}
