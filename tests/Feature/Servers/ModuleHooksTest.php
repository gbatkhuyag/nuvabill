<?php

namespace Tests\Feature\Servers;

use App\Contracts\HasAdminPages;
use App\Contracts\HasConsole;
use App\Enums\AutoSetup;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\ModuleConfig;
use App\Extensions\Servers\ModuleHooks;
use App\Extensions\Servers\ScheduledTask;
use App\Extensions\Servers\Staff\ModuleAdmin;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Security\SignInLimiter;
use App\Security\StaffSecondFactor;
use App\Security\Totp;
use App\Support\AttentionItems;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Fixtures\HookFake\HookFakeModule;
use Tests\TestCase;

/**
 * The hooks server modules get in 0.7.0, through the hookfake fixture: admin pages behind the
 * module's own permission and two-factor login, dashboard items, a staff card on the service
 * page with checked actions, scheduled work run by core's scheduler, order form fields kept
 * encrypted on the service, and long and secret product settings.
 */
class ModuleHooksTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->app->forgetInstance(ExtensionManager::class);
        $extensions = app(ExtensionManager::class);
        $extensions->registerViews();
        HookFakeModule::reset();

        // The app registered its routes and dashboard items before this test pointed at the fixtures.
        Route::middleware(['web', 'auth:admin', 'auth.session', 'admin.can', 'admin.two-factor', 'admin.two-factor-on', 'throttle:30,1'])
            ->prefix(config('nuvabill.admin_path').'/server-modules')->name('admin.server-modules.')
            ->group(fn () => app(ModuleHooks::class)->registerAdminRoutes());
        app('router')->getRoutes()->refreshNameLookups();
        app(ModuleHooks::class)->registerAttentionItems(app(AttentionItems::class));

        $this->server = Server::factory()->create(['module' => 'hookfake', 'hostname' => 'api.example.test']);
    }

    public function test_admin_pages_need_the_modules_permission_and_two_factor_login(): void
    {
        $index = route(ModuleAdmin::routeName('hookfake', 'index'));
        $this->assertStringEndsWith('/server-modules/hookfake', $index);

        // The owner without two-factor login is sent to set it up.
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->get($index)->assertRedirect(route('admin.profile.edit'))
            ->assertSessionHas('error', 'Turn on two-factor login in your profile to use server module tools.');

        $this->signInWithTwoFactor(Admin::factory()->create(['name' => 'Raz']));
        $this->get($index)->assertOk()->assertSee('Node status for staff');
        $this->post(route(ModuleAdmin::routeName('hookfake', 'maintenance')))->assertRedirect();
        $this->assertCount(1, HookFakeModule::callsTo('maintenance'));

        // The menu lists the page.
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('href="'.$index.'"', false);

        // Staff with services but not the module's permission get neither the page nor the link.
        $this->signInWithTwoFactor(Admin::factory()->withPermissions(['services.manage'])->create(['name' => 'Las']));
        $this->get($index)->assertForbidden();
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('href="'.$index.'"', false);

        // A role with only the module's permission is enough.
        $this->signInWithTwoFactor(Admin::factory()->withPermissions(['hookfake.manage'])->create(['name' => 'Mer']));
        $this->get($index)->assertOk();
        $this->assertCount(1, HookFakeModule::callsTo('maintenance'));
    }

    public function test_the_modules_permission_shows_in_the_role_editor_and_can_be_given(): void
    {
        $this->signInWithTwoFactor(Admin::factory()->create(['name' => 'Raz']));

        $this->assertSame(['Hook fake' => ['hookfake.manage' => 'Manage Hook fake nodes']], app(ModuleHooks::class)->permissionGroups());
        $this->assertContains('hookfake.manage', Role::allPermissionKeys());

        $this->get(route('admin.settings.roles.create'))->assertOk()->assertSee('Manage Hook fake nodes')->assertSee('hookfake.manage');
        $this->post(route('admin.settings.roles.store'), ['name' => 'Node staff', 'permissions' => ['hookfake.manage', 'services.manage']])->assertRedirect();

        $this->assertSame(['hookfake.manage', 'services.manage'], Role::query()->where('name', 'Node staff')->firstOrFail()->permissions);
    }

    public function test_a_module_can_never_claim_a_core_permission_or_another_modules(): void
    {
        $module = app(ExtensionManager::class)->serverModule('hookfake');

        foreach (['billing.manage', 'proxmox-ve-servers.manage', 'hookfake', 'hookfake.', 'hookfake.Manage', 'hookfake.manage.all', '*'] as $key) {
            HookFakeModule::$behaviour = ['permission' => $key];

            $this->assertNull(app(ModuleHooks::class)->permissionOf($module), "{$key} is refused.");
            $this->assertSame([], app(ModuleHooks::class)->permissionGroups());
            $this->assertSame(Role::allPermissionKeys(), array_merge(...array_map('array_keys', array_values(Role::PERMISSIONS))));
        }

        // A module that fails to name its permission gets no staff pages either, and the page stays up.
        Exceptions::fake();
        HookFakeModule::$behaviour = ['throw' => ['adminPermission']];
        $this->assertNull(app(ModuleHooks::class)->permissionOf($module));
        Exceptions::assertReported(fn (RuntimeException $exception): bool => ! str_contains($exception->getMessage(), HookFakeModule::SECRET));
    }

    public function test_only_modules_whose_manifest_lists_the_permission_get_a_hook(): void
    {
        $hooks = app(ModuleHooks::class);

        $this->assertSame(['hookfake'], array_keys($hooks->modulesWith(HasAdminPages::class, 'admin-page')));
        $this->assertSame([], $hooks->modulesWith(HasAdminPages::class, 'hardware'));
        // panelfake lists no hook permissions.
        $this->assertArrayNotHasKey('panelfake', $hooks->modulesWith(HasConsole::class, 'admin-page'));
    }

    public function test_dashboard_items_are_checked_and_shown_only_to_staff_with_the_permission(): void
    {
        $owner = $this->withTwoFactor(Admin::factory()->create(['name' => 'Raz']));

        // Like the module's pages: only once this session passed two-factor login.
        $this->assertSame([], app(AttentionItems::class)->for($owner));
        $this->signInAdmin($owner);
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('Node pve2 is in maintenance');

        $this->signInWithTwoFactor($owner);
        $items = app(AttentionItems::class)->for($owner);
        $this->assertSame(['ext.hookfake.nodes'], array_column($items, 'key'), 'Bad keys and links that leave the site are left out.');
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Node pve2 is in maintenance');

        $this->assertSame([], app(AttentionItems::class)->for(Admin::factory()->withPermissions(['services.manage'])->create()));

        // A module that fails never breaks the dashboard.
        Exceptions::fake();
        HookFakeModule::$behaviour = ['throw' => ['attentionItems']];
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('Node pve2 is in maintenance');
        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'Attention items of hookfake')
            && ! str_contains($exception->getMessage(), HookFakeModule::SECRET));
    }

    public function test_scheduled_tasks_run_from_cores_scheduler_without_overlapping(): void
    {
        $schedule = new Schedule;
        app(ModuleHooks::class)->schedule($schedule);

        $events = collect($schedule->events())->keyBy('description');
        $this->assertSame(['server-module:hookfake:traffic', 'server-module:hookfake:cleanup'], $events->keys()->all(), 'A task with a frequency core does not allow is left out.');

        $traffic = $events['server-module:hookfake:traffic'];
        $this->assertInstanceOf(CallbackEvent::class, $traffic);
        $this->assertSame('*/5 * * * *', $traffic->expression);
        $this->assertTrue($traffic->withoutOverlapping);
        $this->assertSame(10, $traffic->expiresAt);
        $this->assertTrue($traffic->onOneServer);
        $this->assertSame('0 0 * * *', $events['server-module:hookfake:cleanup']->expression);
        $this->assertSame(1440, $events['server-module:hookfake:cleanup']->expiresAt, 'The lock lasts at least one interval.');

        $traffic->run($this->app);
        $this->assertCount(1, HookFakeModule::callsTo('traffic'));

        // A failing task is reported by name, never with its message.
        Exceptions::fake();
        $events['server-module:hookfake:cleanup']->run($this->app);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Scheduled task hookfake:cleanup: RuntimeException');

        // Names and lock times are checked too.
        $this->assertNotNull((new ScheduledTask('Bad Name', fn () => null))->problem());
        $this->assertNotNull((new ScheduledTask('ok', fn () => null, 'hourly', 0))->problem());
        $this->assertNull((new ScheduledTask('ok', fn () => null, 'weekly', 1440))->problem());

    }

    public function test_order_fields_are_shown_checked_and_kept_encrypted_on_the_service(): void
    {
        $product = $this->hookProduct();
        $page = $this->get(route('store.product', [$product->group, $product]))->assertOk()
            ->assertSee('Server details')
            ->assertSee('name="fields[hostname]"', false)
            ->assertSee('name="fields[ssh_keys]"', false)
            ->assertSee('name="fields[region]"', false)
            ->assertSee('name="secret_fields[root_token]"', false)
            ->assertSee('Frankfurt')
            ->assertDontSee('Left out');
        $this->assertStringContainsString('type="password"', $page->getContent());

        // Core's checks and the module's own rule; the secret field is never written back.
        $this->from(route('store.product', [$product->group, $product]))->post(route('cart.store'), [
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'fields' => ['hostname' => 'Bad Host!', 'ssh_keys' => 'ssh-ed25519 notakey', 'region' => 'nyc1'],
            'secret_fields' => ['root_token' => 'tok-'.HookFakeModule::SECRET],
        ])->assertSessionHasErrors(['fields.hostname', 'fields.ssh_keys', 'fields.region']);
        $this->assertSame('Bad Host!', session()->getOldInput('fields.hostname'));
        $this->assertNull(session()->getOldInput('secret_fields'));

        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'fields' => ['hostname' => "web1\nexample"]])
            ->assertSessionHasErrors(['fields.hostname']);
        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertSessionHasErrors(['fields.hostname']);

        $key = self::sshKey('mer@laptop');
        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'fields' => ['hostname' => 'web1.example.test', 'ssh_keys' => "\r\n{$key}\r\n\r\n", 'region' => 'fra1', 'root_token' => 'ignored: a secret field is only read from secret_fields'],
            'secret_fields' => ['root_token' => 'tok-'.HookFakeModule::SECRET],
        ])->assertRedirect(route('cart.show'));

        // In the session the values are encrypted.
        $item = session('cart.items')[0];
        $this->assertIsString($item['fields']);
        $this->assertStringNotContainsString('web1', $item['fields']);
        $this->assertStringNotContainsString(HookFakeModule::SECRET, (string) json_encode(session()->all()));

        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => 'Las', 'email' => 'raz@example.test']);
        $this->actingAs($client, 'web');
        $this->post(route('checkout.store'))->assertRedirect();

        $service = Service::query()->where('client_id', $client->id)->sole();
        // The service was set up on the order, so the secret is gone (see the next test).
        $this->assertSame(['hostname' => 'web1.example.test', 'ssh_keys' => $key, 'region' => 'fra1'], $service->order_fields);
        $raw = (string) DB::table('services')->where('id', $service->id)->value('order_fields');
        $this->assertStringNotContainsString('web1', $raw);
        $this->assertStringNotContainsString(HookFakeModule::SECRET, $raw);
        $this->assertArrayNotHasKey('order_fields', $service->toArray());

        // The module reads them in create().
        $this->assertSame([['service' => $service->id, 'hostname' => 'web1.example.test', 'ssh_keys' => $key, 'root_token' => 'tok-'.HookFakeModule::SECRET, 'user_data' => null, 'api_token' => null]], HookFakeModule::callsTo('create'));

        // Staff see what was entered.
        $this->signInWithTwoFactor(Admin::factory()->create(['name' => 'Raz']));
        $this->get(route('admin.services.show', $service))->assertOk()
            ->assertSee('Order details')->assertSee('web1.example.test')
            ->assertDontSee(HookFakeModule::SECRET);
        $this->assertStringNotContainsString(HookFakeModule::SECRET, (string) json_encode(ActivityLog::query()->pluck('description')));
    }

    public function test_one_page_orders_check_the_fields_before_an_account_is_made(): void
    {
        $product = $this->hookProduct();
        $guest = ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'first_name' => 'Raz', 'last_name' => 'Las', 'email' => 'raz@example.test', 'country' => 'IQ', 'password' => 'secret-pass-1'];

        // A price check never needs them.
        $this->postJson(route('store.api.quote'), ['product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertOk();

        $this->post(route('order.store'), $guest + ['secret_fields' => ['root_token' => 'tok-'.HookFakeModule::SECRET]])
            ->assertSessionHasErrors(['fields.hostname']);
        $this->assertSame(0, Client::query()->count(), 'No account is made for an order that cannot be placed.');
        $this->assertNull(session()->getOldInput('secret_fields'));

        $this->post(route('order.store'), $guest + ['fields' => ['hostname' => 'web2.example.test'], 'secret_fields' => ['root_token' => 'tok-'.HookFakeModule::SECRET]])
            ->assertSessionHasNoErrors()->assertRedirect();

        // The module got both in create(); the secret is not kept once the service is set up.
        $service = Service::query()->sole();
        $this->assertSame('tok-'.HookFakeModule::SECRET, HookFakeModule::callsTo('create')[0]['root_token']);
        $this->assertSame(['hostname' => 'web2.example.test'], $service->order_fields);
    }

    public function test_a_module_without_the_order_fields_permission_adds_no_fields(): void
    {
        $product = Product::factory()->priced(1000)->withoutDomain()->create(['server_module' => 'panelfake']);

        $this->get(route('store.product', [$product->group, $product]))->assertOk()->assertDontSee('Server details');
        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'fields' => ['hostname' => 'x']])
            ->assertRedirect(route('cart.show'));
        $this->assertNull(session('cart.items')[0]['fields']);
    }

    public function test_long_and_secret_product_settings(): void
    {
        $this->signInWithTwoFactor(Admin::factory()->create(['name' => 'Raz']));
        // Forms trim the ends of values, so the script ends without a line break here.
        $script = rtrim("#cloud-config\n".str_repeat('runcmd: [echo ok]'."\n", 700));
        $this->assertGreaterThan(10000, mb_strlen($script));

        $form = [
            'product_group_id' => ProductGroup::factory()->create()->id,
            'name' => 'Cloud plan',
            'slug' => 'cloud-plan',
            'type' => 'server',
            'auto_setup' => 'payment',
            'server_module' => 'hookfake',
            'prices' => ['monthly' => ['enabled' => '1', 'price' => '20.00', 'setup_fee' => '0']],
        ];

        // A textarea without "max", and a text field, stay at 190 characters.
        $this->post(route('admin.products.store'), $form + ['module_config' => ['notes' => str_repeat('n', 191), 'plan' => str_repeat('p', 191)]])
            ->assertSessionHasErrors(['module_config.notes', 'module_config.plan']);
        $this->post(route('admin.products.store'), $form + ['module_config' => ['user_data' => str_repeat('u', ModuleConfig::MAX_LONG + 1)]])
            ->assertSessionHasErrors(['module_config.user_data']);

        // After an error the form keeps what was typed, but never a secret setting: not in the
        // session and not in the page, also not a long secret textarea.
        $this->from(route('admin.products.create'))->post(route('admin.products.store'), $form + ['module_config' => [
            'plan' => str_repeat('p', 191), 'private_key' => "-----BEGIN KEY-----\nkey-".HookFakeModule::SECRET, 'api_token' => 'tok-'.HookFakeModule::SECRET,
        ]])->assertSessionHasErrors(['module_config.plan']);
        $this->assertSame(str_repeat('p', 191), session()->getOldInput('module_config.plan'));
        $this->assertNull(session()->getOldInput('module_config.private_key'));
        $this->assertStringNotContainsString(HookFakeModule::SECRET, (string) json_encode(session()->all()));
        $this->get(route('admin.products.create'))->assertOk()->assertDontSee(HookFakeModule::SECRET);

        $this->post(route('admin.products.store'), $form + ['module_config' => ['plan' => 's-1vcpu', 'user_data' => $script, 'api_token' => 'tok-'.HookFakeModule::SECRET]])
            ->assertSessionHasNoErrors()->assertRedirect();

        $product = Product::query()->where('slug', 'cloud-plan')->sole();
        $this->assertSame($script, $product->module_config['user_data']);
        $this->assertTrue(ModuleConfig::isEncrypted($product->module_config['api_token']));
        $this->assertStringNotContainsString(HookFakeModule::SECRET, (string) DB::table('products')->where('id', $product->id)->value('module_config'));

        // The form never shows the secret; left empty, it stays.
        $this->get(route('admin.products.edit', $product))->assertOk()->assertDontSee(HookFakeModule::SECRET)->assertSee('maxlength="16384"', false);
        $this->put(route('admin.products.update', $product), $form + ['module_config' => ['plan' => 's-2vcpu', 'user_data' => $script, 'api_token' => '']])
            ->assertSessionHasNoErrors();
        $config = $product->fresh()->module_config;
        $this->assertSame('s-2vcpu', $config['plan']);
        $this->assertSame('tok-'.HookFakeModule::SECRET, ModuleConfig::read($config['api_token']));

        // The module reads it in plain text.
        $service = Service::factory()->create(['product_id' => $product->id, 'server_id' => $this->server->id, 'status' => ServiceStatus::Pending]);
        app(ExtensionManager::class)->serverModule('hookfake')->create($service->fresh());
        $this->assertSame('tok-'.HookFakeModule::SECRET, HookFakeModule::callsTo('create')[0]['api_token']);
        $this->assertSame($script, HookFakeModule::callsTo('create')[0]['user_data']);
    }

    public function test_the_staff_card_shows_facts_and_runs_only_declared_checked_actions(): void
    {
        $service = Service::factory()->create(['product_id' => $this->hookProduct()->id, 'server_id' => $this->server->id, 'status' => ServiceStatus::Active]);
        $this->signInWithTwoFactor(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.services.show', $service))->assertOk()
            ->assertSee('Hook fake guest')->assertSee('Node pve1 is healthy.')->assertSee('pve1')
            ->assertSee('&lt;b&gt;nuvabill:s'.$service->id.'&lt;/b&gt;', false)
            ->assertDontSee('Bad row')
            ->assertSee('Move to another node')->assertSee('Move this guest?')
            ->assertSee(route('admin.services.staff-action', [$service, 'migrate']));

        $action = route('admin.services.staff-action', [$service, 'migrate']);

        // A node the card does not offer, or a note that is too long, is refused before the module is asked.
        $this->post($action, ['node' => 'pve3', 'note' => str_repeat('n', 21)])->assertSessionHasErrors(['node', 'note'], null, 'staff_migrate');
        $this->assertSame([], HookFakeModule::callsTo('runStaffAction'));

        $this->post($action, ['node' => 'pve2', 'live' => '1', 'note' => 'after hours', 'extra' => 'dropped'])
            ->assertRedirect(route('admin.services.show', $service).'#module-staff')
            ->assertSessionHas('status', 'Moved to pve2.');
        $this->assertSame([['service' => $service->id, 'action' => 'migrate', 'input' => ['node' => 'pve2', 'live' => '1', 'note' => 'after hours'], 'staff' => 'Raz']], HookFakeModule::callsTo('runStaffAction'));
        $this->assertTrue(ActivityLog::query()->where('action', 'service.staff_action')->where('description', 'like', '%"migrate" of hookfake%')->exists());

        // An action the card does not offer never reaches the module.
        $this->post(route('admin.services.staff-action', [$service, 'delete-guest']))->assertSessionHas('error', 'This action is not available for this service.');
        $this->assertCount(1, HookFakeModule::callsTo('runStaffAction'));

        // A failure in the module shows a plain message, never the module's own.
        Exceptions::fake();
        HookFakeModule::$behaviour = ['throw' => ['runStaffAction']];
        $this->post($action, ['node' => 'pve1'])->assertSessionHas('error', 'The server module could not do this. Try again in a minute.');
        Exceptions::assertReported(fn (RuntimeException $exception): bool => ! str_contains($exception->getMessage(), HookFakeModule::SECRET));
        $this->assertTrue(ActivityLog::query()->where('action', 'service.staff_action_failed')->exists());

        // While other work holds the service, nothing runs.
        HookFakeModule::$behaviour = [];
        $lock = app(Provisioner::class)->lockFor($service);
        $lock->get();
        $this->post($action, ['node' => 'pve1'])->assertSessionHas('error');
        $lock->release();
        $this->assertCount(2, HookFakeModule::callsTo('runStaffAction'));
    }

    public function test_the_staff_card_needs_the_modules_permission_and_its_actions_two_factor_login(): void
    {
        $service = Service::factory()->create(['product_id' => $this->hookProduct()->id, 'server_id' => $this->server->id, 'status' => ServiceStatus::Active]);
        $action = route('admin.services.staff-action', [$service, 'migrate']);

        $this->signInWithTwoFactor(Admin::factory()->withPermissions(['services.manage'])->create(['name' => 'Las']));
        $this->get(route('admin.services.show', $service))->assertOk()->assertDontSee('Hook fake guest');
        $this->post($action, ['node' => 'pve2'])->assertForbidden();

        // Without two-factor login: neither the facts nor the buttons (the module is not even
        // asked), and the action is refused.
        $mer = Admin::factory()->withPermissions(['services.manage', 'hookfake.manage'])->create(['name' => 'Mer']);
        $this->signInAdmin($mer);
        $this->get(route('admin.services.show', $service))->assertOk()
            ->assertSee('Hook fake')->assertDontSee('Hook fake guest')->assertDontSee('Node pve1 is healthy.')->assertDontSee('Move to another node')
            ->assertSee('Turn on two-factor login in your profile to use server module tools.');
        $this->post($action, ['node' => 'pve2'])->assertRedirect(route('admin.profile.edit'));

        // With two-factor login on the account but not passed in this session (a remember-me
        // sign-in, say): the same, with the way to enter a code.
        $this->withTwoFactor($mer);
        $this->get(route('admin.services.show', $service))->assertOk()
            ->assertDontSee('Hook fake guest')->assertDontSee('Move to another node')
            ->assertSee('Enter a code from your authenticator app to use server module tools.')
            ->assertSee(route('admin.two-factor.step-up'));
        $this->post($action, ['node' => 'pve2'])->assertRedirect(route('admin.two-factor.step-up'));

        $this->assertSame([], HookFakeModule::callsTo('runStaffAction'));
        $this->assertSame([], HookFakeModule::callsTo('staffPanel'));

        // A card that fails to load says so, without the module's message.
        Exceptions::fake();
        HookFakeModule::$behaviour = ['throw' => ['staffPanel']];
        $this->signInWithTwoFactor(Admin::factory()->create(['name' => 'Raz']));
        $this->get(route('admin.services.show', $service))->assertOk()
            ->assertSee('The server module could not load its staff tools. Try again in a minute.')
            ->assertDontSee(HookFakeModule::SECRET);
    }

    public function test_a_session_that_did_not_pass_two_factor_login_is_asked_for_a_code_first(): void
    {
        $index = route(ModuleAdmin::routeName('hookfake', 'index'));
        $secret = Totp::generateSecret();
        $admin = Admin::factory()->create(['name' => 'Raz']);
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        // Signed in without the code (a remember-me cookie, say): asked for one first.
        $this->signInAdmin($admin);
        $this->get($index)->assertRedirect(route('admin.two-factor.step-up'));
        $this->getJson($index)->assertForbidden()->assertJsonPath('message', 'Enter a code from your authenticator app to use server module tools.');
        $this->get(route('admin.two-factor.step-up'))->assertOk()->assertSee('Two-factor check');

        $this->post(route('admin.two-factor.step-up'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        $this->get($index)->assertRedirect(route('admin.two-factor.step-up'));
        $this->assertSame([], HookFakeModule::callsTo('maintenance'));

        // The right code opens the page asked for.
        $this->post(route('admin.two-factor.step-up'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])->assertRedirect($index);
        $this->get($index)->assertOk()->assertSee('Node status for staff');
        $this->assertTrue(ActivityLog::query()->where('action', 'admin.two_factor_step_up')->exists());

        // The marker belongs to this staff member only.
        $las = $this->withTwoFactor(Admin::factory()->create(['name' => 'Las']));
        $this->signInAdmin($las);
        $this->get($index)->assertRedirect(route('admin.two-factor.step-up'));
    }

    public function test_too_many_wrong_step_up_codes_sign_the_session_out(): void
    {
        $secret = Totp::generateSecret();
        $admin = Admin::factory()->create(['name' => 'Raz']);
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $this->signInAdmin($admin);

        for ($try = 1; $try < SignInLimiter::CODES; $try++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$try])
                ->post(route('admin.two-factor.step-up'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->post(route('admin.two-factor.step-up'), ['code' => $this->wrongCode($secret)])->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
        $this->assertTrue(ActivityLog::query()->where('action', 'admin.two_factor_locked')->exists());
    }

    public function test_staff_without_two_factor_login_cannot_add_an_authenticator_without_their_password(): void
    {
        $index = route(ModuleAdmin::routeName('hookfake', 'index'));
        $admin = $this->signInAdmin(Admin::factory()->create(['name' => 'Mer']));

        $this->get($index)->assertRedirect(route('admin.profile.edit'));
        $this->post(route('admin.profile.two-factor.start'))->assertSessionHasErrors('current_password');
        $this->assertNull($admin->fresh()->two_factor_secret);

        // With the password, a fresh code from the new app counts as this session's second factor.
        $this->post(route('admin.profile.two-factor.start'), ['current_password' => 'password'])->assertRedirect();
        $code = Totp::codeAt((string) $admin->fresh()->two_factor_secret, intdiv(time(), 30));
        $this->post(route('admin.profile.two-factor.confirm'), ['code' => $code])->assertSessionHasErrors('current_password');
        $this->get($index)->assertRedirect(route('admin.profile.edit'));

        $this->post(route('admin.profile.two-factor.confirm'), ['code' => $code, 'current_password' => 'password'])->assertSessionHas('recovery_codes');
        $this->get($index)->assertOk();
    }

    public function test_a_password_field_of_a_staff_action_is_never_written_back(): void
    {
        $service = Service::factory()->create(['product_id' => $this->hookProduct()->id, 'server_id' => $this->server->id, 'status' => ServiceStatus::Active]);
        $this->signInWithTwoFactor(Admin::factory()->create(['name' => 'Raz']));
        $show = route('admin.services.show', $service);
        $set = route('admin.services.staff-action', [$service, 'set-password']);

        $this->get($show)->assertOk()->assertSee('Set root password')
            ->assertSee('name="new_secret" type="password"', false);

        // A field error keeps the reason, never the password.
        $this->from($show)->post($set, ['new_secret' => 'pw-'.HookFakeModule::SECRET, 'reason' => ''])
            ->assertSessionHasErrors(['reason'], null, 'staff_set-password');
        $this->assertNull(session()->getOldInput('new_secret'));
        $this->assertStringNotContainsString(HookFakeModule::SECRET, (string) json_encode(session()->all()));
        $this->get($show)->assertOk()->assertDontSee(HookFakeModule::SECRET);

        $this->post($set, ['new_secret' => 'pw-'.HookFakeModule::SECRET, 'reason' => 'Lost it'])->assertSessionHas('status');
        $this->assertSame(['new_secret' => 'pw-'.HookFakeModule::SECRET, 'reason' => 'Lost it'], HookFakeModule::callsTo('runStaffAction')[0]['input']);
        $this->assertStringNotContainsString(HookFakeModule::SECRET, (string) json_encode(ActivityLog::query()->pluck('description')));
    }

    public function test_scheduled_work_runs_only_for_modules_in_use_and_at_most_ten_tasks(): void
    {
        HookFakeModule::$behaviour = ['tasks' => array_map(fn (int $n): ScheduledTask => new ScheduledTask('task-'.$n, fn () => null, 'hourly', 5), range(1, 12))];

        Log::spy();
        $schedule = new Schedule;
        app(ModuleHooks::class)->schedule($schedule);
        $events = collect($schedule->events());
        $this->assertCount(ModuleHooks::MAX_TASKS, $events);
        $this->assertSame('server-module:hookfake:task-10', $events->last()->description);
        // A lock shorter than the interval lasts one interval.
        $this->assertSame(60, $events->first()->expiresAt);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'at most 10 scheduled tasks'));

        // A module without a server or product runs nothing.
        $this->server->delete();
        $schedule = new Schedule;
        app(ModuleHooks::class)->schedule($schedule);
        $this->assertSame([], $schedule->events());

        Product::factory()->priced(1000)->withoutDomain()->create(['server_module' => 'hookfake']);
        $schedule = new Schedule;
        app(ModuleHooks::class)->schedule($schedule);
        $this->assertCount(ModuleHooks::MAX_TASKS, $schedule->events());
    }

    public function test_secret_order_fields_are_removed_once_the_service_is_set_up_and_all_on_termination(): void
    {
        $service = Service::factory()->create([
            'product_id' => $this->hookProduct()->id,
            'server_id' => $this->server->id,
            'status' => ServiceStatus::Pending,
            'order_fields' => ['hostname' => 'web1.example.test', 'root_token' => 'tok-'.HookFakeModule::SECRET, 'gone' => 'from an older module version'],
        ]);

        // While it waits, staff see that a secret was saved, never its value.
        $this->signInWithTwoFactor(Admin::factory()->create(['name' => 'Raz']));
        $this->get(route('admin.services.show', $service))->assertOk()->assertSee('Saved, hidden')->assertDontSee(HookFakeModule::SECRET);

        $this->assertTrue(app(Provisioner::class)->create($service)->success);

        // The module had the secret in create(); afterwards only the plain, declared values stay.
        $this->assertSame('tok-'.HookFakeModule::SECRET, HookFakeModule::callsTo('create')[0]['root_token']);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame(['hostname' => 'web1.example.test'], $service->fresh()->order_fields);

        $this->assertTrue(app(Provisioner::class)->terminate($service->fresh())->success);
        $this->assertNull($service->fresh()->order_fields);
        $this->assertNull(DB::table('services')->where('id', $service->id)->value('order_fields'));
    }

    private function wrongCode(string $secret): string
    {
        $step = intdiv(time(), 30);
        $valid = [Totp::codeAt($secret, $step - 1), Totp::codeAt($secret, $step), Totp::codeAt($secret, $step + 1)];

        foreach (['111111', '222222', '333333', '444444'] as $code) {
            if (! in_array($code, $valid, true)) {
                return $code;
            }
        }

        return '555555';
    }

    private function hookProduct(): Product
    {
        return Product::factory()->priced(1000)->withoutDomain()->create([
            'server_module' => 'hookfake',
            'server_id' => $this->server->id,
            'auto_setup' => AutoSetup::OnOrder,
        ]);
    }

    /**
     * Sign in as staff with two-factor login on, in a session that passed it.
     */
    private function signInWithTwoFactor(Admin $admin): Admin
    {
        $this->signInAdmin($this->withTwoFactor($admin));
        $this->withSession([StaffSecondFactor::SESSION_KEY => (int) $admin->id]);

        return $admin;
    }

    private function withTwoFactor(Admin $admin): Admin
    {
        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        return $admin;
    }

    /**
     * An OpenSSH ed25519 public key line with a well-formed body.
     */
    private static function sshKey(string $comment): string
    {
        return 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat("\x07", 32)).' '.$comment;
    }
}
