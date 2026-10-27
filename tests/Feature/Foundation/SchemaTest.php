<?php

namespace Tests\Feature\Foundation;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Product;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Models\ServiceResourceOption;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The tables, columns, templates and permissions for resource options, the client server panel and
 * dedicated server hardware, and the model methods every workstream builds on.
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        '2027_08_01_000001_create_resource_options_tables.php',
        '2027_08_01_000002_add_client_server_panel.php',
        '2027_08_01_000003_create_hardware_inventory.php',
    ];

    private const TEMPLATE_KEYS = [
        'service.resources_changed', 'admin.resources_failed', 'admin.resources_by_hand', 'service.server_change',
        'service.dedicated_ready', 'admin.machine_install', 'admin.machine_wipe', 'admin.machine_alert',
    ];

    public function test_the_new_migrations_run_again_without_harm(): void
    {
        $roles = Role::query()->orderBy('id')->pluck('permissions', 'id')->all();

        $this->runMigrations('up');

        foreach (['resource_options', 'resource_option_prices', 'service_resource_options', 'resource_changes', 'resource_change_items', 'machines', 'machine_ips', 'machine_product'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumns('products', ['panel_sections', 'uses_machines']));
        $this->assertTrue(Schema::hasColumn('invoice_items', 'service_resource_option_id'));
        $this->assertTrue(Schema::hasColumns('resource_options', ['can_shrink', 'restarts', 'note', 'ladder', 'max_extra']));
        $this->assertTrue(Schema::hasColumn('resource_changes', 'server_checks'));
        $this->assertTrue(Schema::hasIndex('resource_options', 'resource_options_product_kind_unique'));
        $this->assertTrue(Schema::hasIndex('resource_option_prices', ['resource_option_id', 'currency', 'billing_cycle'], 'unique'));
        $this->assertTrue(Schema::hasIndex('service_resource_options', ['service_id', 'kind'], 'unique'));
        $this->assertTrue(Schema::hasIndex('invoice_items', 'invoice_items_resource_row_index'));
        $this->assertTrue(Schema::hasIndex('machines', ['service_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('machine_ips', ['address'], 'unique'));

        foreach (self::TEMPLATE_KEYS as $key) {
            $this->assertSame(1, EmailTemplate::query()->where('key', $key)->count(), $key);
        }

        $this->assertSame($roles, Role::query()->orderBy('id')->pluck('permissions', 'id')->all());
    }

    public function test_the_new_migrations_can_be_undone_and_run_again(): void
    {
        $this->runMigrations('down');

        $this->assertFalse(Schema::hasTable('resource_options'));
        $this->assertFalse(Schema::hasTable('machines'));
        $this->assertFalse(Schema::hasColumn('products', 'panel_sections'));
        $this->assertFalse(Schema::hasColumn('products', 'uses_machines'));
        $this->assertFalse(Schema::hasColumn('invoice_items', 'service_resource_option_id'));
        $this->assertSame(0, EmailTemplate::query()->whereIn('key', self::TEMPLATE_KEYS)->count());
        $this->assertFalse(Role::query()->where('name', 'Billing')->firstOrFail()->allows('hardware.power'));

        $this->runMigrations('up');

        $this->assertTrue(Schema::hasTable('resource_options'));
        $this->assertTrue(Schema::hasTable('machines'));
        $this->assertTrue(Schema::hasColumns('products', ['panel_sections', 'uses_machines']));
        $this->assertSame(count(self::TEMPLATE_KEYS), EmailTemplate::query()->whereIn('key', self::TEMPLATE_KEYS)->count());
        $this->assertTrue(Role::query()->where('name', 'Billing')->firstOrFail()->allows('hardware.power'));
    }

    public function test_templates_are_added_once_and_a_template_staff_edited_is_kept(): void
    {
        EmailTemplate::query()->whereIn('key', self::TEMPLATE_KEYS)->delete();

        $this->runMigrations('up');
        $this->runMigrations('up');

        $english = DefaultDataSeeder::templates();

        foreach (self::TEMPLATE_KEYS as $key) {
            $template = EmailTemplate::query()->where('key', $key)->sole();
            $this->assertSame($english[$key][1], $template->subject, $key);
            $this->assertTrue($template->is_active);
        }

        EmailTemplate::query()->where('key', 'service.server_change')->update(['subject' => 'Our own subject']);
        $this->runMigrations('up');

        $this->assertSame('Our own subject', EmailTemplate::query()->where('key', 'service.server_change')->value('subject'));
    }

    public function test_no_new_template_holds_a_password_bmc_login_or_token(): void
    {
        foreach (self::TEMPLATE_KEYS as $key) {
            [, $subject, $body] = DefaultDataSeeder::templates()[$key];

            $this->assertDoesNotMatchRegularExpression('/{{\s*[a-z_.]*(password|token|secret|bmc|console)[a-z_.]*\s*}}/i', $subject.$body, $key);
        }
    }

    public function test_staff_who_manage_services_or_products_get_the_hardware_permissions(): void
    {
        $services = Role::query()->create(['name' => 'Services', 'permissions' => ['clients.view', 'services.manage']]);
        $products = Role::query()->create(['name' => 'Products', 'permissions' => ['products.manage']]);
        $support = Role::query()->create(['name' => 'Helpdesk', 'permissions' => ['support.manage']]);
        $owner = Role::query()->where('name', 'Owner')->firstOrFail();

        $this->migration('2027_08_01_000003_create_hardware_inventory.php')->up();
        $this->migration('2027_08_01_000003_create_hardware_inventory.php')->up();

        $this->assertSame(['clients.view', 'services.manage', 'hardware.power'], $services->fresh()->permissions);
        $this->assertSame(['products.manage', 'hardware.manage', 'hardware.power'], $products->fresh()->permissions);
        $this->assertSame(['support.manage'], $support->fresh()->permissions);
        $this->assertSame(['*'], $owner->fresh()->permissions);

        // A new site gets the same: the Billing role manages services.
        $this->assertTrue(Role::query()->where('name', 'Billing')->firstOrFail()->allows('hardware.power'));
        $this->assertFalse(Role::query()->where('name', 'Billing')->firstOrFail()->allows('hardware.manage'));
        $this->assertArrayHasKey('hardware.manage', Role::PERMISSIONS['Setup']);
        $this->assertArrayHasKey('hardware.power', Role::PERMISSIONS['Setup']);
    }

    public function test_renewal_total_adds_the_active_addons_and_extras_to_the_plan_price(): void
    {
        $service = $this->service(['recurring_amount' => 1000]);
        $service->addons()->createMany([
            ['name' => 'Daily backups', 'recurring_amount' => 300, 'status' => ServiceAddon::STATUS_ACTIVE],
            ['name' => 'Old add-on', 'recurring_amount' => 200, 'status' => ServiceAddon::STATUS_CANCELLED],
            ['name' => 'Extra RAM', 'recurring_amount' => 500, 'status' => ServiceAddon::STATUS_CONVERTED],
        ]);
        $ram = ResourceOption::factory()->create(['product_id' => $service->product_id]);
        $ipv4 = ResourceOption::factory()->kind('ipv4', 1)->create(['product_id' => $service->product_id]);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $ram->id, 'steps' => 2, 'recurring_amount' => 400]);
        ServiceResourceOption::factory()->cancelled()->create(['service_id' => $service->id, 'resource_option_id' => $ipv4->id]);

        $this->assertSame(1700, $service->fresh()->renewalTotal());
        $this->assertSame(1700, $service->fresh()->load(['addons', 'resources'])->renewalTotal());
        $this->assertSame(1000, $service->fresh()->recurring_amount);
    }

    public function test_resource_extras_list_rows_and_the_products_options(): void
    {
        $service = $this->service();
        $ram = ResourceOption::factory()->create(['product_id' => $service->product_id]);
        $ipv4 = ResourceOption::factory()->kind('ipv4', 1)->create(['product_id' => $service->product_id]);
        ResourceOption::factory()->kind('disk', 10)->retired()->create(['product_id' => $service->product_id]);
        ResourceOption::factory()->kind('cpu', 1)->create();

        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $ram->id, 'steps' => 2]);
        ServiceResourceOption::factory()->cancelled()->create(['service_id' => $service->id, 'resource_option_id' => $ipv4->id]);
        // A row whose option is gone keeps its kind and units.
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => null, 'kind' => 'bandwidth', 'unit' => 'GB', 'step_size' => 1000, 'steps' => 1]);

        $extras = $service->fresh()->resourceExtras();

        $this->assertEqualsCanonicalizing(['ram', 'ipv4', 'disk', 'bandwidth'], $extras->kinds());
        $this->assertSame(2048, $extras->get('ram'));
        $this->assertSame(0, $extras->get('ipv4'));
        $this->assertTrue($extras->has('disk'));
        $this->assertSame(0, $extras->get('disk'));
        $this->assertSame(1000, $extras->get('bandwidth'));
        $this->assertFalse($extras->has('cpu'));
        $this->assertFalse($extras->isEmpty());
    }

    public function test_relinking_points_rows_at_the_new_products_options_and_keeps_steps_and_prices(): void
    {
        $service = $this->service();
        $ram = ResourceOption::factory()->create(['product_id' => $service->product_id]);
        $disk = ResourceOption::factory()->kind('disk', 10)->create(['product_id' => $service->product_id]);
        $ipv4 = ResourceOption::factory()->kind('ipv4', 1)->create(['product_id' => $service->product_id]);
        $ramRow = ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $ram->id, 'steps' => 3, 'recurring_amount' => 600]);
        $diskRow = ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $disk->id, 'steps' => 2, 'recurring_amount' => 200]);
        $ipv4Row = ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $ipv4->id, 'steps' => 1, 'recurring_amount' => 300]);

        $bigger = Product::factory()->create();
        $newRam = ResourceOption::factory()->create(['product_id' => $bigger->id]);
        ResourceOption::factory()->kind('disk', 20)->create(['product_id' => $bigger->id]);

        $service->relinkResources($bigger);

        $this->assertSame($newRam->id, $ramRow->fresh()->resource_option_id);
        // Another step size, or no option of that kind: the row keeps its kind, units and price.
        $this->assertNull($diskRow->fresh()->resource_option_id);
        $this->assertNull($ipv4Row->fresh()->resource_option_id);
        $this->assertSame([3, 600, 1024], [$ramRow->fresh()->steps, $ramRow->fresh()->recurring_amount, $ramRow->fresh()->step_size]);
        $this->assertSame([2, 200, 'disk'], [$diskRow->fresh()->steps, $diskRow->fresh()->recurring_amount, $diskRow->fresh()->kind]);
        $this->assertSame(['ram' => 3072, 'disk' => 20, 'ipv4' => 1], $service->fresh()->resourceExtras()->units);
    }

    public function test_option_stock_counts_active_rows_and_steps_held_by_waiting_changes(): void
    {
        $product = Product::factory()->create();
        $option = ResourceOption::factory()->create(['product_id' => $product->id, 'stock' => 10]);
        $this->assertNull(ResourceOption::factory()->create()->stockLeft());

        $active = $this->service(['product_id' => $product->id]);
        ServiceResourceOption::factory()->create(['service_id' => $active->id, 'resource_option_id' => $option->id, 'steps' => 3]);
        $gone = $this->service(['product_id' => $product->id, 'status' => 'terminated']);
        ServiceResourceOption::factory()->create(['service_id' => $gone->id, 'resource_option_id' => $option->id, 'steps' => 4]);
        $other = $this->service(['product_id' => $product->id]);
        ServiceResourceOption::factory()->cancelled()->create(['service_id' => $other->id, 'resource_option_id' => $option->id]);

        // A change at the renewal date holds the steps it adds; one paid first counts them when it happens.
        ResourceChange::factory()->atRenewal()->withItem($option, 0, 2)->create(['service_id' => $other->id]);
        ResourceChange::factory()->withItem($option, 0, 5)->create(['service_id' => $other->id]);
        ResourceChange::factory()->atRenewal()->withItem($option, 3, 1)->create(['service_id' => $active->id]);

        $this->assertSame(5, $option->stockLeft());
        $this->assertSame(5, DB::transaction(fn (): ?int => $option->lockedStockLeft()));
        $this->assertTrue($option->isUsed());
    }

    public function test_rows_describe_themselves_in_plain_words(): void
    {
        $service = $this->service();
        $ram = ResourceOption::factory()->create(['product_id' => $service->product_id]);
        $backups = ResourceOption::factory()->kind('backups', 1)->create(['product_id' => $service->product_id]);
        $size = ResourceOption::factory()->ladder(['CX23', 'CX33', 'CX43'])->create(['product_id' => $service->product_id, 'label' => 'Server size']);

        $row = fn (array $attributes): ServiceResourceOption => ServiceResourceOption::factory()->make(['service_id' => $service->id] + $attributes);

        $this->assertSame('RAM +1.5 GB', $row(['resource_option_id' => $ram->id, 'step_size' => 512, 'steps' => 3])->describe());
        $this->assertSame('Backups', $row(['resource_option_id' => $backups->id, 'steps' => 1])->describe());
        $this->assertSame('Server size: CX43', $row(['resource_option_id' => $size->id, 'steps' => 2])->describe());
        $this->assertSame('Monthly traffic +2 TB', $row(['resource_option_id' => null, 'kind' => 'bandwidth', 'unit' => 'GB', 'step_size' => 1000, 'steps' => 2])->describe());
    }

    public function test_panel_switches_default_to_the_modules_choice(): void
    {
        $product = Product::factory()->create();
        $this->assertTrue($product->panelSectionEnabled('graphs', true));
        $this->assertFalse($product->panelSectionEnabled('media', false));

        $product->update(['panel_sections' => ['graphs' => false, 'media' => true]]);
        $product = $product->fresh();

        $this->assertFalse($product->panelSectionEnabled('graphs', true));
        $this->assertTrue($product->panelSectionEnabled('media', false));
        $this->assertTrue($product->panelSectionEnabled('console', true));
    }

    public function test_settings_have_the_new_defaults(): void
    {
        $this->assertTrue(setting('billing.resource_changes'));
        $this->assertTrue(setting('services.panel_console'));
        $this->assertSame(10, setting('services.panel_live_seconds'));
        $this->assertTrue(setting('services.panel_change_emails'));
        $this->assertSame(['Ubuntu 24.04', 'Debian 12', 'AlmaLinux 9', 'Rocky Linux 9'], setting('hardware.os_choices'));
        $this->assertNull(setting('hardware.ticket_department'));
        $this->assertSame(10, setting('hardware.suspend_grace_minutes'));
        $this->assertTrue(setting('hardware.show_location'));
    }

    public function test_money_entries_of_resource_changes_are_hidden_from_staff_without_billing_access(): void
    {
        foreach (['service.resource_change_credit', 'service.resource_change_refund', 'service.resource_change_expired', 'service.resource_change'] as $action) {
            ActivityLog::query()->create(['action' => $action, 'description' => $action]);
        }

        $this->assertSame(['service.resource_change'], ActivityLog::query()->withoutBilling()->pluck('action')->all());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function service(array $attributes = []): Service
    {
        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '']);

        return Service::factory()->create(['client_id' => $client->id] + $attributes);
    }

    private function runMigrations(string $direction): void
    {
        $files = $direction === 'down' ? array_reverse(self::MIGRATIONS) : self::MIGRATIONS;

        foreach ($files as $file) {
            $this->migration($file)->{$direction}();
        }
    }

    private function migration(string $file): object
    {
        return require database_path('migrations/'.$file);
    }
}
