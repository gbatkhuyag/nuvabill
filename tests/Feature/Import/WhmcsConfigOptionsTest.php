<?php

namespace Tests\Feature\Import;

use App\Import\Whmcs\WhmcsImporter;
use App\Jobs\RunImport;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Support\Settings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WHMCS configurable options brought across as resource options (spec A §14): only the options
 * staff choose, and every imported service keeps exactly its WHMCS renewal total.
 */
class WhmcsConfigOptionsTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION = 'whmcs_options_test';

    private const OS_TEXT = 'Configurable option ":name" (dropdown or radio) was not imported: set resource options by hand. The service price still includes it.';

    private const UNMAPPED_TEXT = 'Configurable option values, not imported: :count.';

    private const IN_PLAN_TEXT = 'Services whose extras cost more than their WHMCS price: :count. Their extras come across at no charge, and the whole price stays in the plan.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge(self::CONNECTION);
        $this->createWhmcsTables();
        $this->seedWhmcs();
    }

    public function test_a_mapped_quantity_option_becomes_an_option_and_rows_with_the_total_unchanged(): void
    {
        $this->choose([11 => ['kind' => 'ram', 'units' => 1024], 12 => ['kind' => 'ipv4', 'units' => 1]]);

        $preview = (new WhmcsImporter(self::CONNECTION))->preflight()->toArray();
        $texts = collect($preview['problems'])->keyBy('text');
        $this->assertSame(2, $texts['Configurable options brought across as resource options: :count. Each service keeps the same renewal total.']['params']['count']);
        $this->assertSame(2, $texts['Services that get extras from configurable options: :count.']['params']['count']);
        $this->assertArrayNotHasKey(self::IN_PLAN_TEXT, $texts->all());
        $this->assertSame(0, ResourceOption::query()->count(), 'The dry run changes nothing');

        $this->assertSame([], $this->runImport());

        $product = Product::query()->where('name', 'VPS 2 GB')->firstOrFail();
        $ram = $product->resourceOptions()->where('kind', 'ram')->firstOrFail();
        $this->assertSame(['MB', 1024, 0, 8, true, true, true], [$ram->unit, $ram->step_size, $ram->min_steps, $ram->max_steps, $ram->is_active, $ram->restarts, $ram->client_can_remove]);
        $this->assertSame(200, $ram->prices()->where('currency', 'USD')->where('billing_cycle', 'monthly')->value('price'));
        $this->assertSame(100, $ram->prices()->where('currency', 'USD')->where('billing_cycle', 'monthly')->value('setup_fee'));
        $this->assertSame(2000, $ram->prices()->where('currency', 'USD')->where('billing_cycle', 'annually')->value('price'));
        $this->assertFalse($ram->prices()->where('billing_cycle', 'quarterly')->exists(), 'Only cycles the product is sold with');
        $ipv4 = $product->resourceOptions()->where('kind', 'ipv4')->firstOrFail();
        $this->assertSame([1, 4, 1], [$ipv4->step_size, $ipv4->max_steps, $ipv4->min_steps]);

        $service = Service::query()->where('domain', 'vps.raz.example')->firstOrFail();
        $this->assertSame(2900, $service->recurring_amount, 'The plan keeps what is left after the extras');
        $this->assertSame(3600, $service->renewalTotal(), 'The renewal total is the WHMCS amount');
        $this->assertSame(['ipv4' => [1, 300, $ipv4->id], 'ram' => [2, 400, $ram->id]], $this->rows($service));
        $this->assertSame(['ram' => 2048, 'ipv4' => 1], $service->resourceExtras()->units);

        $other = Service::query()->where('domain', 'vps.mer.example')->firstOrFail();
        $this->assertSame(1000, $other->recurring_amount);
        $this->assertSame(1200, $other->renewalTotal());
        $this->assertSame(['ram' => [1, 200, $ram->id]], $this->rows($other), 'A quantity of 0 gets no row');

        $whmcs = DB::connection(self::CONNECTION);
        $whmcs->table('tblhostingconfigoptions')->where('relid', 10)->where('configid', 11)->update(['qty' => 3]);
        $whmcs->table('tblhosting')->where('id', 10)->update(['amount' => '38.00']);

        $this->assertSame([], $this->runImport());

        $this->assertSame(2, ResourceOption::query()->count(), 'A rerun makes no copies');
        $service->refresh();
        $this->assertSame(2900, $service->recurring_amount);
        $this->assertSame(3800, $service->renewalTotal());
        $this->assertSame(['ipv4' => [1, 300, $ipv4->id], 'ram' => [3, 600, $ram->id]], $this->rows($service));
    }

    public function test_unmapped_options_stay_in_the_plan_price_with_a_warning(): void
    {
        $preview = (new WhmcsImporter(self::CONNECTION))->preflight()->toArray();
        $texts = collect($preview['problems'])->keyBy('text');
        $this->assertSame('warning', $texts[self::OS_TEXT]['level']);
        $this->assertSame('Operating System', $texts[self::OS_TEXT]['params']['name']);
        $this->assertSame(5, $texts[self::UNMAPPED_TEXT]['params']['count'], 'Every value stays out');

        $this->runImport();

        $this->assertSame(0, ResourceOption::query()->count());
        $this->assertSame(0, ServiceResourceOption::query()->count());
        $this->assertSame(3600, Service::query()->where('domain', 'vps.raz.example')->value('recurring_amount'), 'The whole price stays in the plan');

        $this->choose([11 => ['kind' => 'ram', 'units' => 1024]]);
        $texts = collect((new WhmcsImporter(self::CONNECTION))->preflight()->toArray()['problems'])->keyBy('text');
        $this->assertSame(3, $texts[self::UNMAPPED_TEXT]['params']['count'], 'The IP, OS and backups values stay out');
        $this->assertArrayHasKey(self::OS_TEXT, $texts->all());

        $this->runImport();

        $service = Service::query()->where('domain', 'vps.raz.example')->firstOrFail();
        $this->assertSame(3200, $service->recurring_amount, 'The IP, Windows and backups prices stay in the plan');
        $this->assertSame(3600, $service->renewalTotal());
        $this->assertSame(['ram'], array_keys($this->rows($service)));

        $this->choose([]);
        $this->runImport();

        $service->refresh();
        $this->assertSame(3600, $service->recurring_amount, 'Not chosen any more: the plan takes the price back');
        $this->assertSame(3600, $service->renewalTotal());
        $this->assertSame(ServiceResourceOption::STATUS_CANCELLED, $service->resources()->value('status'));
    }

    public function test_resource_prices_above_the_service_amount_do_not_split_it(): void
    {
        DB::connection(self::CONNECTION)->table('tblhosting')->where('id', 10)->update(['amount' => '5.00']);
        $this->choose([11 => ['kind' => 'ram', 'units' => 1024], 12 => ['kind' => 'ipv4', 'units' => 1]]);

        $texts = collect((new WhmcsImporter(self::CONNECTION))->preflight()->toArray()['problems'])->keyBy('text');
        $this->assertSame(['vps.raz.example'], $texts[self::IN_PLAN_TEXT]['examples']);

        $this->runImport();
        $this->runImport();

        $service = Service::query()->where('domain', 'vps.raz.example')->firstOrFail();
        $this->assertSame(500, $service->recurring_amount, 'The plan keeps the whole amount');
        $this->assertSame(500, $service->renewalTotal());
        $this->assertSame(['ipv4' => 0, 'ram' => 0], array_map(fn (array $row): int => $row[1], $this->rows($service)), 'The extras come across at no charge');
        $this->assertSame(['ram' => 2048, 'ipv4' => 1], $service->resourceExtras()->units, 'The server keeps what it has');
        $this->assertSame(1, ActivityLog::query()->where('action', 'service.resources_imported')->where('subject_id', $service->id)->count(), 'Logged once');
    }

    public function test_a_yes_no_option_switches_backups_and_a_kind_the_module_cannot_set_is_hidden(): void
    {
        $this->choose([14 => ['kind' => 'backups', 'units' => 5]]);

        $texts = collect((new WhmcsImporter(self::CONNECTION))->preflight()->toArray()['problems'])->keyBy('text');
        $this->assertSame(['VPS 2 GB (Backups)'], $texts['Resource options the server module of the product cannot set: :count. They are imported hidden from the store; change these extras on the server by hand.']['examples']);

        $this->runImport();

        $backups = ResourceOption::query()->where('kind', 'backups')->firstOrFail();
        $this->assertSame([1, 1, false], [$backups->step_size, $backups->max_steps, $backups->is_active], 'Virtualizor cannot set backups, so staff check it first');

        $service = Service::query()->where('domain', 'vps.raz.example')->firstOrFail();
        $this->assertSame(['backups' => [1, 400, $backups->id]], $this->rows($service));
        $this->assertSame(3200, $service->recurring_amount);
        $this->assertSame(3600, $service->renewalTotal());
    }

    public function test_a_service_whose_extras_changed_in_nuvabill_is_left_as_it_is(): void
    {
        $this->choose([11 => ['kind' => 'ram', 'units' => 1024]]);
        $this->runImport();

        $service = Service::query()->where('domain', 'vps.raz.example')->firstOrFail();
        ResourceChange::factory()->create(['service_id' => $service->id, 'client_id' => $service->client_id]);
        $service->resources()->update(['steps' => 4, 'recurring_amount' => 800]);
        $service->forceFill(['recurring_amount' => 2700])->save();
        DB::connection(self::CONNECTION)->table('tblhostingconfigoptions')->where('relid', 10)->where('configid', 11)->update(['qty' => 1]);

        $this->runImport();

        $service->refresh();
        $this->assertSame(2700, $service->recurring_amount);
        $this->assertSame(4, $service->resources()->value('steps'));
        $this->assertSame(3500, $service->renewalTotal());
    }

    public function test_staff_choose_the_options_after_the_dry_run(): void
    {
        $this->signInAdmin();
        $settings = app(Settings::class);
        $settings->set('import.connection', ['source' => 'whmcs', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'whmcs', 'username' => 'whmcs', 'password' => 'secret', 'prefix' => '', 'tls' => false]);
        $importer = new WhmcsImporter(self::CONNECTION);
        $settings->set('import.preview', ['source' => 'whmcs', 'config_options' => $importer->configOptions()] + $importer->preflight()->toArray());

        $listed = collect(setting('import.preview')['config_options']['options'])->keyBy('id');
        $this->assertSame(['ram', 1024], [$listed[11]['suggest'], $listed[11]['units']], 'Named like the RAM option of Virtualizor');
        $this->assertSame('ipv4', $listed[12]['suggest']);
        $this->assertSame('backups', $listed[14]['suggest']);
        $this->assertSame(['dropdown', null, 1], [$listed[13]['type'], $listed[13]['suggest'], $listed[13]['services']], 'A priced dropdown is listed, never mapped');

        $this->get(route('admin.settings.import.index'))->assertOk()
            ->assertSee('Configurable options')
            ->assertSee('Extra RAM')
            ->assertSee('Suggested: RAM')
            ->assertSee('Not imported: a dropdown or radio option picks a total.');

        $form = fn (array $options): array => ['config_options_form' => '1', 'config_options' => $options];

        $this->from(route('admin.settings.import.index'))
            ->post(route('admin.settings.import.preview'), $form([13 => ['kind' => 'ram']]))
            ->assertSessionHasErrors(['config_options.13.kind' => 'This option cannot become RAM.']);
        $this->post(route('admin.settings.import.preview'), $form([11 => ['kind' => 'ram', 'units' => '64']]))
            ->assertSessionHasErrors(['config_options.11.units' => 'Each step must add at least 128 MB.']);
        $this->post(route('admin.settings.import.preview'), $form([11 => ['kind' => 'ram', 'units' => '1024', 'price' => '0']]))
            ->assertSessionHasErrors('config_options.11');
        $this->assertSame([], WhmcsImporter::savedConfigOptions(), 'Nothing is saved after an error');

        $this->post(route('admin.settings.import.preview'), $form([
            11 => ['kind' => 'ram', 'units' => '1024'],
            12 => ['kind' => '', 'units' => '1'],
            14 => ['kind' => 'backups'],
            999 => ['kind' => 'cpu', 'units' => '1'],
        ]))->assertSessionHas('error');

        $this->assertSame([11 => ['kind' => 'ram', 'units' => 1024], 14 => ['kind' => 'backups', 'units' => 1]], WhmcsImporter::savedConfigOptions(), 'Saved before the dry run is made again; options not listed are ignored');
        $this->assertTrue(ActivityLog::query()->where('action', 'import.options_saved')->exists());
        $this->get(route('admin.settings.import.index'))->assertOk()->assertSee('<option value="ram" selected>RAM</option>', false);

        $settings->set('import.status', ['state' => 'running', 'source' => 'whmcs', 'updated_at' => now()->toIso8601String()]);
        $this->assertTrue(RunImport::isRunning());
        $this->post(route('admin.settings.import.preview'), $form([]))->assertSessionHas('error', 'The import is running. Change the choices when it has finished.');
        $this->assertCount(2, WhmcsImporter::savedConfigOptions());
    }

    /**
     * @param  array<int, array{kind: string, units: int}>  $choices
     */
    private function choose(array $choices): void
    {
        WhmcsImporter::saveConfigOptions(app(Settings::class), $choices);
    }

    /**
     * The service's active rows: kind => [steps, renewal price, option ID].
     *
     * @return array<string, array{0: int, 1: int, 2: int|null}>
     */
    private function rows(Service $service): array
    {
        return $service->resources()->where('status', ServiceResourceOption::STATUS_ACTIVE)->orderBy('kind')->get()
            ->mapWithKeys(fn (ServiceResourceOption $row): array => [$row->kind => [$row->steps, $row->recurring_amount, $row->resource_option_id]])
            ->all();
    }

    /**
     * @return list<array{step: string, id: int, error: string}>
     */
    private function runImport(): array
    {
        $importer = new WhmcsImporter(self::CONNECTION);
        $errors = [];

        foreach (array_keys(WhmcsImporter::STEPS) as $step) {
            $afterId = 0;

            do {
                $result = $importer->run($step, $afterId, 1);
                $afterId = $result['last_id'];

                foreach ($result['errors'] as $error) {
                    $errors[] = ['step' => $step] + $error;
                }
            } while (! $result['done']);
        }

        return $errors;
    }

    /**
     * A VPS product with a RAM and an IP quantity option, a priced OS dropdown and a backups
     * switch. Raz's VPS has 2 GB extra RAM, one extra IP, Windows and backups (36.00 a month in
     * all); Mer Las's has 1 GB extra RAM (12.00).
     */
    private function seedWhmcs(): void
    {
        $db = DB::connection(self::CONNECTION);
        $none = ['msetupfee' => '0.00', 'qsetupfee' => '0.00', 'ssetupfee' => '0.00', 'asetupfee' => '0.00', 'bsetupfee' => '0.00', 'tsetupfee' => '0.00', 'monthly' => '0.00', 'quarterly' => '-1.00', 'semiannually' => '-1.00', 'annually' => '0.00', 'biennially' => '-1.00', 'triennially' => '-1.00'];

        $db->table('tblcurrencies')->insert(['id' => 1, 'code' => 'USD', 'default' => 1]);
        $db->table('tblclients')->insert([
            ['id' => 1, 'firstname' => 'Raz', 'lastname' => 'Las', 'email' => 'raz@example.com', 'currency' => 1, 'credit' => '0.00', 'status' => 'Active', 'password' => '', 'datecreated' => '2024-01-02'],
            ['id' => 2, 'firstname' => 'Mer', 'lastname' => 'Las', 'email' => 'mer@example.com', 'currency' => 1, 'credit' => '0.00', 'status' => 'Active', 'password' => '', 'datecreated' => '2024-02-03'],
        ]);
        $db->table('tblproductgroups')->insert(['id' => 1, 'name' => 'VPS', 'headline' => '', 'hidden' => 0, 'order' => 1]);
        $db->table('tblproducts')->insert(['id' => 3, 'gid' => 1, 'type' => 'server', 'name' => 'VPS 2 GB', 'description' => '', 'hidden' => 0, 'retired' => 0, 'showdomainoptions' => 0, 'paytype' => 'recurring', 'servertype' => 'virtualizor', 'configoption1' => '', 'autosetup' => 'payment', 'stockcontrol' => 0, 'qty' => 0, 'order' => 0]);
        $db->table('tblpricing')->insert([
            ['type' => 'product', 'relid' => 3, 'currency' => 1, 'monthly' => '10.00', 'annually' => '100.00'] + $none,
            ['type' => 'configoptions', 'relid' => 111, 'currency' => 1, 'monthly' => '2.00', 'msetupfee' => '1.00', 'annually' => '20.00'] + $none,
            ['type' => 'configoptions', 'relid' => 121, 'currency' => 1, 'monthly' => '3.00', 'annually' => '30.00'] + $none,
            ['type' => 'configoptions', 'relid' => 131, 'currency' => 1] + $none,
            ['type' => 'configoptions', 'relid' => 132, 'currency' => 1, 'monthly' => '15.00', 'annually' => '150.00'] + $none,
            ['type' => 'configoptions', 'relid' => 141, 'currency' => 1, 'monthly' => '4.00', 'annually' => '40.00'] + $none,
        ]);

        $db->table('tblproductconfiglinks')->insert(['id' => 1, 'gid' => 7, 'pid' => 3]);
        $db->table('tblproductconfigoptions')->insert([
            ['id' => 11, 'gid' => 7, 'optionname' => 'additional_ram|Extra RAM', 'optiontype' => 4, 'qtyminimum' => 0, 'qtymaximum' => 8, 'order' => 1, 'hidden' => 0],
            ['id' => 12, 'gid' => 7, 'optionname' => 'Number of IPs', 'optiontype' => 4, 'qtyminimum' => 1, 'qtymaximum' => 4, 'order' => 2, 'hidden' => 0],
            ['id' => 13, 'gid' => 7, 'optionname' => 'Operating System', 'optiontype' => 1, 'qtyminimum' => 0, 'qtymaximum' => 0, 'order' => 3, 'hidden' => 0],
            ['id' => 14, 'gid' => 7, 'optionname' => 'Daily Backups', 'optiontype' => 3, 'qtyminimum' => 0, 'qtymaximum' => 0, 'order' => 4, 'hidden' => 0],
        ]);
        $db->table('tblproductconfigoptionssub')->insert([
            ['id' => 111, 'configid' => 11, 'optionname' => 'GB', 'sortorder' => 0, 'hidden' => 0],
            ['id' => 121, 'configid' => 12, 'optionname' => 'IP', 'sortorder' => 0, 'hidden' => 0],
            ['id' => 131, 'configid' => 13, 'optionname' => 'Ubuntu', 'sortorder' => 0, 'hidden' => 0],
            ['id' => 132, 'configid' => 13, 'optionname' => 'Windows', 'sortorder' => 1, 'hidden' => 0],
            ['id' => 141, 'configid' => 14, 'optionname' => 'Yes', 'sortorder' => 0, 'hidden' => 0],
        ]);

        $service = ['packageid' => 3, 'server' => 0, 'regdate' => '2025-10-01', 'billingcycle' => 'Monthly', 'nextduedate' => '2026-11-01', 'domainstatus' => 'Active', 'username' => '', 'password' => '', 'suspendreason' => ''];
        $db->table('tblhosting')->insert([
            ['id' => 10, 'userid' => 1, 'domain' => 'vps.raz.example', 'firstpaymentamount' => '36.00', 'amount' => '36.00'] + $service,
            ['id' => 11, 'userid' => 2, 'domain' => 'vps.mer.example', 'firstpaymentamount' => '12.00', 'amount' => '12.00'] + $service,
        ]);
        $db->table('tblhostingconfigoptions')->insert([
            ['relid' => 10, 'configid' => 11, 'optionid' => 111, 'qty' => 2],
            ['relid' => 10, 'configid' => 12, 'optionid' => 121, 'qty' => 1],
            ['relid' => 10, 'configid' => 13, 'optionid' => 132, 'qty' => 0],
            ['relid' => 10, 'configid' => 14, 'optionid' => 141, 'qty' => 1],
            ['relid' => 11, 'configid' => 11, 'optionid' => 111, 'qty' => 1],
        ]);
    }

    private function createWhmcsTables(): void
    {
        $schema = Schema::connection(self::CONNECTION);
        $text = [
            'tblcurrencies' => ['code', 'default'],
            'tblclients' => ['firstname', 'lastname', 'companyname', 'email', 'country', 'currency', 'credit', 'status', 'password', 'datecreated'],
            'tblproductgroups' => ['name', 'headline', 'hidden', 'order'],
            'tblproducts' => ['gid', 'type', 'name', 'description', 'hidden', 'retired', 'showdomainoptions', 'paytype', 'servertype', 'configoption1', 'autosetup', 'stockcontrol', 'qty', 'order'],
            'tblpricing' => ['type', 'relid', 'currency', 'msetupfee', 'qsetupfee', 'ssetupfee', 'asetupfee', 'bsetupfee', 'tsetupfee', 'monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'],
            'tblhosting' => ['userid', 'packageid', 'server', 'regdate', 'domain', 'firstpaymentamount', 'amount', 'billingcycle', 'nextduedate', 'domainstatus', 'username', 'password', 'suspendreason'],
            'tblproductconfigoptions' => ['optionname'],
            'tblproductconfigoptionssub' => ['optionname'],
        ];
        $numbers = [
            'tblproductconfiglinks' => ['gid', 'pid'],
            'tblproductconfigoptions' => ['gid', 'optiontype', 'qtyminimum', 'qtymaximum', 'order', 'hidden'],
            'tblproductconfigoptionssub' => ['configid', 'sortorder', 'hidden'],
            'tblhostingconfigoptions' => ['relid', 'configid', 'optionid', 'qty'],
        ];

        foreach (array_unique([...array_keys($text), ...array_keys($numbers)]) as $table) {
            $schema->create($table, function (Blueprint $blueprint) use ($table, $text, $numbers): void {
                $blueprint->id();

                foreach ($text[$table] ?? [] as $name) {
                    $blueprint->string($name)->nullable();
                }

                foreach ($numbers[$table] ?? [] as $name) {
                    $blueprint->integer($name)->default(0);
                }
            });
        }
    }
}
