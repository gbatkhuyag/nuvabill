<?php

namespace App\Import\Whmcs;

use App\Auth\LegacyPassword;
use App\Billing\RenewalGenerator;
use App\Billing\ResourceKinds;
use App\Billing\ResourceOptions;
use App\Domains\DomainName;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ProductType;
use App\Enums\ServiceStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Import\ImportSource;
use App\Import\Preflight;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Domain;
use App\Models\ImportMapping;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPrice;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\ResourceOptionPrice;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Models\TldPrice;
use App\Models\Transaction;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Copies a WHMCS installation into Nuvabill by reading its MySQL database.
 *
 * With WHMCS's encryption key (cc_encryption_hash from configuration.php) server and service
 * passwords come across too; without it they are left empty. Not imported: saved cards, addons
 * and custom fields (except a Virtualizor "vpsid").
 *
 * Configurable options come across only where staff chose a resource for them in the dry run
 * (setting "import.whmcs", key "config_options"): a quantity option becomes a resource option with
 * the units of one quantity as its step, and a yes/no option can switch backups on. Each service
 * then gets resource rows, and its WHMCS price is split into the plan and the extras, so its
 * renewal total stays exactly the WHMCS amount. Other options stay in the plan price.
 */
class WhmcsImporter extends ImportSource
{
    public const SOURCE = 'whmcs';

    public const CONNECTION = 'whmcs_import';

    /**
     * Steps in the order they run. Later steps need the IDs from earlier ones.
     *
     * @var array<string, string>
     */
    public const STEPS = [
        'staff' => 'Staff',
        'clients' => 'Clients',
        'product_groups' => 'Product groups',
        'servers' => 'Servers',
        'products' => 'Products and prices',
        'services' => 'Services',
        'tlds' => 'Domain prices',
        'domains' => 'Domains',
        'invoices' => 'Invoices',
        'transactions' => 'Payments',
        'departments' => 'Support departments',
        'tickets' => 'Tickets',
    ];

    private const TABLES = [
        'staff' => 'tbladmins',
        'clients' => 'tblclients',
        'product_groups' => 'tblproductgroups',
        'servers' => 'tblservers',
        'products' => 'tblproducts',
        'services' => 'tblhosting',
        'tlds' => 'tbldomainpricing',
        'domains' => 'tbldomains',
        'invoices' => 'tblinvoices',
        'transactions' => 'tblaccounts',
        'departments' => 'tblticketdepartments',
        'tickets' => 'tbltickets',
    ];

    /**
     * WHMCS server module names and the Nuvabill module each becomes. Modules from the marketplace
     * (CyberPanel, HestiaCP, VirtFusion, SolusVM) count only once they are installed.
     */
    private const SERVER_MODULES = [
        'cpanel' => 'cpanel',
        'directadmin' => 'directadmin',
        'plesk' => 'plesk',
        'virtualizor' => 'virtualizor',
        'proxmox' => 'proxmox',
        'proxmoxvps' => 'proxmox',
        'cyberpanel' => 'cyberpanel',
        'hestia' => 'hestiacp',
        'hestiacp' => 'hestiacp',
        'virtfusiondirect' => 'virtfusion',
        'virtfusion' => 'virtfusion',
        'solusvmpro' => 'solusvm',
        'solusvm' => 'solusvm',
    ];

    private const REGISTRARS = ['resellerclub', 'namecheap', 'enom', 'opensrs'];

    /**
     * WHMCS payment module names and the Nuvabill gateway each becomes. Others keep their WHMCS name.
     */
    private const GATEWAYS = [
        'paypal' => 'paypal',
        'paypalcheckout' => 'paypal',
        'paypal_ppcpv' => 'paypal',
        'stripe' => 'stripe',
        'stripe_checkout' => 'stripe',
        'banktransfer' => 'banktransfer',
        'mailin' => 'banktransfer',
    ];

    /**
     * Billing cycle => [price column, setup fee column] in tblpricing.
     */
    private const CYCLE_COLUMNS = [
        'monthly' => ['monthly', 'msetupfee'],
        'quarterly' => ['quarterly', 'qsetupfee'],
        'semiannually' => ['semiannually', 'ssetupfee'],
        'annually' => ['annually', 'asetupfee'],
        'biennially' => ['biennially', 'bsetupfee'],
        'triennially' => ['triennially', 'tsetupfee'],
    ];

    /**
     * Resource kinds a configurable option may become, by option type. A quantity adds that many
     * steps to the plan, and a yes/no option switches backups on. Dropdowns and radio buttons pick
     * a total, not an extra, so they are never brought across.
     *
     * @var array<string, list<string>>
     */
    public const CONFIG_OPTION_KINDS = [
        'quantity' => ['cpu', 'ram', 'disk', 'ipv4', 'ipv6', 'bandwidth'],
        'yesno' => ['backups'],
    ];

    /**
     * The most units one quantity of a configurable option may add.
     */
    public const MAX_UNITS = 1000000;

    /**
     * The most configurable options the dry run lists for staff to choose from.
     */
    public const MAX_LISTED_OPTIONS = 300;

    /**
     * WHMCS configurable option types (tblproductconfigoptions.optiontype).
     *
     * @var array<int, string>
     */
    private const OPTION_TYPES = [1 => 'dropdown', 2 => 'radio', 3 => 'yesno', 4 => 'quantity'];

    /**
     * Option names that suggest a kind, after "additional" or "extra" and underscores are taken
     * away: common WHMCS names and Virtualizor's ("CPU Cores", "RAM", "Space", "Number of IPs",
     * "additional_ram").
     *
     * @var array<string, list<string>>
     */
    private const OPTION_NAMES = [
        'cpu' => ['cpu cores', 'cpu core', 'cores', 'cpu', 'vcpu', 'vcpus'],
        'ram' => ['ram', 'memory'],
        'disk' => ['space', 'disk space', 'disk'],
        'ipv4' => ['number of ips', 'num ips', 'ip address', 'ip addresses', 'ips', 'ipv4', 'ipv4 addresses'],
        'ipv6' => ['number of ipv6 address', 'number of ipv6 addresses', 'num ips6', 'ipv6', 'ipv6 addresses'],
        'bandwidth' => ['bandwidth', 'traffic', 'monthly traffic'],
    ];

    /**
     * The most steps a resource row holds (the column's limit).
     */
    private const MAX_ROW_STEPS = 65535;

    /**
     * Activity entry for a service whose extras came across inside its plan price.
     */
    private const EXTRAS_IN_PLAN = 'service.resources_imported';

    /**
     * Mapping entity of the resource rows the import made: a WHMCS option value of a service
     * (tblhostingconfigoptions) to the Nuvabill row it became.
     */
    private const RESOURCE_ROW = 'service_resource';

    /**
     * @var array<int, string>|null
     */
    private ?array $currencies = null;

    /**
     * WHMCS configurable options in a group linked to a product, by ID.
     *
     * @var array<int, stdClass>|null
     */
    private ?array $configOptions = null;

    /**
     * WHMCS product ID => its configurable option IDs, in the order WHMCS shows them.
     *
     * @var array<int, list<int>>
     */
    private array $productOptions = [];

    /**
     * @var array<int, array{kind: string, units: int}>|null
     */
    private ?array $optionMap = null;

    /**
     * WHMCS option ID => the ID of its first choice (the one a quantity or yes/no option is priced by).
     *
     * @var array<int, int|null>
     */
    private array $firstChoices = [];

    /**
     * WHMCS choice ID => its tblpricing rows.
     *
     * @var array<int, list<stdClass>>
     */
    private array $choicePrices = [];

    /**
     * Nuvabill product ID => its resource options by kind.
     *
     * @var array<int, array<string, ResourceOption>>
     */
    private array $localOptions = [];

    private ?string $defaultCurrency = null;

    /**
     * @var array<string, int|null>|null
     */
    private ?array $staffByName = null;

    /**
     * @var list<string>|null
     */
    private ?array $knownTlds = null;

    public function __construct(string $connection = self::CONNECTION, ?string $secret = null)
    {
        parent::__construct($connection, $secret);
    }

    public static function connect(array $credentials, string $connection = self::CONNECTION): static
    {
        return parent::connect($credentials, $connection);
    }

    public static function key(): string
    {
        return self::SOURCE;
    }

    public static function name(): string
    {
        return 'WHMCS';
    }

    public static function steps(): array
    {
        return self::STEPS;
    }

    public static function keyHelp(): ?string
    {
        return 'Optional. The value of $cc_encryption_hash in configuration.php. With it, server and service passwords come across too.';
    }

    public static function configFile(): string
    {
        return 'configuration.php';
    }

    protected function tables(): array
    {
        return self::TABLES;
    }

    protected function requiredTables(): array
    {
        return ['tblclients', 'tblinvoices'];
    }

    public function version(): string
    {
        return $this->hasTable('tblconfiguration') ? (string) $this->db()->table('tblconfiguration')->where('setting', 'Version')->value('value') : '';
    }

    /**
     * Staff become switched-off accounts, so ticket replies keep their author. Existing staff with the same email are linked.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importStaff(Collection $rows): void
    {
        foreach ($rows as $row) {
            $email = strtolower((string) $this->text($row->email ?? null));

            if ($this->localId('admin', $row->id) !== null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->counts['skipped']++;

                continue;
            }

            if ($existing = Admin::query()->where('email', $email)->first()) {
                $this->remember('admin', (int) $row->id, $existing->id);
                $this->counts['updated']++;

                continue;
            }

            $this->upsert('admin', (int) $row->id, Admin::class, [], [
                'name' => trim($this->text($row->firstname ?? null).' '.$this->text($row->lastname ?? null)) ?: $email,
                'email' => $email,
                'password' => $this->placeholderPassword(),
                'is_active' => false,
            ]);
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importClients(Collection $rows): void
    {
        $passwords = $this->ownerPasswords($rows->pluck('id')->all());

        foreach ($rows as $row) {
            if (($email = $this->clientEmailToImport((int) $row->id, $row->email ?? null, $this->currency($row->currency ?? 0))) === null) {
                continue;
            }

            $client = $this->upsert('client', (int) $row->id, Client::class, [
                'first_name' => $this->text($row->firstname ?? null) ?? '',
                'last_name' => $this->text($row->lastname ?? null) ?? '',
                'company_name' => $this->text($row->companyname ?? null),
                'phone' => $this->text($row->phonenumber ?? null),
                'address_1' => $this->text($row->address1 ?? null),
                'address_2' => $this->text($row->address2 ?? null),
                'city' => $this->text($row->city ?? null),
                'state' => $this->text($row->state ?? null),
                'postcode' => Str::limit((string) $this->text($row->postcode ?? null), 20, '') ?: null,
                'country' => preg_match('/^[A-Za-z]{2}$/', (string) ($row->country ?? '')) ? strtoupper($row->country) : null,
                'status' => (match ((string) ($row->status ?? '')) {
                    'Inactive' => ClientStatus::Inactive,
                    'Closed' => ClientStatus::Closed,
                    default => ClientStatus::Active,
                })->value,
                'notes' => $this->text($row->notes ?? null),
            ], [
                'email' => $email,
                'password' => $this->placeholderPassword(),
                'currency' => $this->currency($row->currency ?? 0),
                'created_at' => $this->date($row->datecreated ?? null) ?? now(),
            ]);

            if ($client->wasRecentlyCreated) {
                $this->importPassword($client, (string) ($passwords[$row->id] ?? $row->password ?? ''));
            }

            $this->syncCredit($client, $this->money($row->credit ?? 0), (int) $row->id);
        }
    }

    /**
     * WHMCS 7+ keeps bcrypt hashes, which work as they are. WHMCS 4.2 to 6.2 kept salted MD5, which is
     * checked at the first sign-in. Very old versions kept passwords encrypted, which the key opens.
     */
    private function importPassword(Client $client, string $stored): void
    {
        if ($this->secret !== null && ! str_contains($stored, '$') && ! preg_match('/^[a-f0-9]{32}:/i', $stored)
            && ($plain = (new WhmcsCrypt($this->secret))->decrypt($stored)) !== null) {
            DB::table('clients')->where('id', $client->id)->update(['password' => Hash::make($plain)]);

            return;
        }

        $this->applyPassword($client, $stored);
    }

    protected function legacyPassword(string $hash): ?string
    {
        if (preg_match('/^([a-f0-9]{32}):(.{1,32})$/i', $hash, $match)) {
            return 'md5-salt:'.strtolower($match[1]).':'.$match[2];
        }

        return str_starts_with($hash, '$argon2') ? 'native:'.$hash : null;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importProductGroups(Collection $rows): void
    {
        foreach ($rows as $row) {
            $name = $this->text($row->name ?? null) ?? 'Group '.$row->id;

            $this->upsert('product_group', (int) $row->id, ProductGroup::class, [
                'name' => $name,
                'description' => $this->text($row->headline ?? null) ?? $this->text($row->tagline ?? null),
                'is_visible' => ! ($row->hidden ?? false),
                'sort_order' => (int) ($row->order ?? 0),
            ], ['slug' => $this->unique(ProductGroup::class, 'slug', Str::slug($name) ?: 'group')]);
        }
    }

    /**
     * Servers are imported switched off, so staff check them before Nuvabill uses them. With the WHMCS key
     * their password comes across; the access hash (API token) is not encrypted in WHMCS.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importServers(Collection $rows): void
    {
        foreach ($rows as $row) {
            $module = $this->serverModule($row->type ?? null, self::SERVER_MODULES);

            if ($module === null) {
                $this->counts['skipped']++;

                continue;
            }

            $ip = filter_var($row->ipaddress ?? null, FILTER_VALIDATE_IP) ?: null;
            $hostname = $this->text($row->hostname ?? null) ?? $ip ?? 'localhost';
            $password = $this->decrypt($row->password ?? null);
            $token = preg_replace('/\s+/', '', (string) ($row->accesshash ?? '')) ?: null;

            $server = $this->upsert('server', (int) $row->id, Server::class, [
                'name' => $this->text($row->name ?? null) ?? $hostname,
                'module' => $module,
                'hostname' => $hostname,
                'ip_address' => $ip,
                'port' => (int) ($row->port ?? 0) ?: null,
                'use_ssl' => ($row->secure ?? '') === 'on',
                'username' => $this->text($row->username ?? null),
                'nameservers' => array_values(array_filter(array_map(fn (int $number): ?string => $this->text($row->{'nameserver'.$number} ?? null), range(1, 5)))),
                'max_accounts' => (int) ($row->maxaccounts ?? 0) ?: null,
            ], ['is_active' => false]);

            // Only filled when empty, so passwords staff entered in Nuvabill are kept.
            $server->forceFill(array_filter([
                'password' => blank($server->password) ? $password : null,
                'api_token' => blank($server->api_token) ? $token : null,
            ]))->save();
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importProducts(Collection $rows): void
    {
        $prices = $this->hasTable('tblpricing')
            ? $this->db()->table('tblpricing')->where('type', 'product')->whereIn('relid', $rows->pluck('id'))->get()->groupBy('relid')
            : collect();
        $this->localOptions = [];

        foreach ($rows as $row) {
            $groupId = $this->localId('product_group', $row->gid ?? 0);

            if ($groupId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $module = $this->serverModule($row->servertype ?? null, self::SERVER_MODULES);
            $name = $this->text($row->name ?? null) ?? 'Product '.$row->id;

            $product = $this->upsert('product', (int) $row->id, Product::class, [
                'product_group_id' => $groupId,
                'name' => $name,
                'type' => (match ((string) ($row->type ?? '')) {
                    'reselleraccount' => ProductType::Reseller,
                    'server' => ProductType::Server,
                    'other' => ProductType::Other,
                    default => ProductType::Hosting,
                })->value,
                'description' => $this->text($row->description ?? null),
                'is_visible' => ! ($row->hidden ?? false) && ! ($row->retired ?? false),
                'requires_domain' => (bool) ($row->showdomainoptions ?? true),
                'server_module' => $module,
                'auto_setup' => (match ((string) ($row->autosetup ?? '')) {
                    'order' => AutoSetup::OnOrder,
                    'payment' => AutoSetup::OnPayment,
                    default => AutoSetup::Manual,
                })->value,
                'stock' => ($row->stockcontrol ?? false) ? max(0, (int) ($row->qty ?? 0)) : null,
                'sort_order' => (int) ($row->order ?? 0),
            ], [
                'slug' => $this->unique(Product::class, 'slug', Str::slug($name) ?: 'product'),
                'module_config' => match ($module) {
                    'cpanel', 'directadmin', 'cyberpanel', 'hestiacp' => ['package' => (string) $this->text($row->configoption1 ?? null)],
                    'plesk' => ['plan' => (string) $this->text($row->configoption1 ?? null)],
                    default => [],
                },
            ]);

            $this->importPrices($product, (string) ($row->paytype ?? 'recurring'), $prices->get($row->id, collect()));
            $this->importResourceOptions($product, (int) $row->id, (string) ($row->paytype ?? 'recurring'));
        }
    }

    /**
     * Resource options for the configurable options staff chose to bring across. Each is made once,
     * with the units of one WHMCS quantity as its step, and is shown to clients only when WHMCS showed
     * it and the product's server module can set that kind. Its prices follow WHMCS on every run,
     * like the product's. An option the product already has with another step is left alone, and
     * the WHMCS price of that configurable option stays in the plan.
     */
    private function importResourceOptions(Product $product, int $sourceProductId, string $payType): void
    {
        $resources = $this->productResources($sourceProductId);

        if ($resources === []) {
            return;
        }

        $moduleKinds = $this->moduleKinds($product);
        $cycles = $product->prices()->pluck('billing_cycle')
            ->map(fn (mixed $cycle): string => $cycle instanceof BillingCycle ? $cycle->value : (string) $cycle)
            ->reject(fn (string $cycle): bool => $cycle === BillingCycle::Free->value)
            ->unique()->values()->all();
        $choices = $this->firstChoices(array_column($resources, 'id'));
        $prices = $this->choicePrices(array_values(array_filter($choices)));

        foreach ($resources as $kind => $resource) {
            $option = ResourceOption::query()->where('product_id', $product->id)->where('kind', $kind)->first();

            if ($option !== null && $option->step_size !== $resource['units']) {
                $this->errors[] = ['id' => $sourceProductId, 'error' => __('Configurable option ":name" was not imported: this product already has :resource with another step size. The service price still includes it.', [
                    'name' => $this->optionName($resource['option']),
                    'resource' => ResourceKinds::label($kind),
                ])];

                continue;
            }

            $option ??= $this->createResourceOption($product, $kind, $resource, $moduleKinds[$kind] ?? null);
            $this->importOptionPrices($option, $prices[$choices[$resource['id']] ?? 0] ?? [], $cycles, $payType);
        }
    }

    /**
     * A new resource option for one configurable option, with the kind's copy (shrink, restarts,
     * note, maximum) from the server module when it can set the kind, else the built-in kind.
     *
     * @param  array{id: int, units: int, option: stdClass}  $resource
     */
    private function createResourceOption(Product $product, string $kind, array $resource, ?ResourceKind $moduleKind): ResourceOption
    {
        $units = $resource['units'];
        $fits = $this->kindFits($moduleKind, $kind, $units);
        $snapshot = $fits ? $moduleKind : ResourceKinds::builtIn($kind);
        $most = (int) ($resource['option']->qtymaximum ?? 0);
        $max = $kind === 'backups' ? 1 : ($most > 0 ? min($most, ResourceOptions::MAX_STEPS) : ResourceOptions::MAX_STEPS);

        if ($snapshot->maxExtra !== null) {
            $max = max(1, min($max, intdiv($snapshot->maxExtra, $units)));
        }

        $min = $kind === 'backups' ? 0 : min(max(0, (int) ($resource['option']->qtyminimum ?? 0)), $max);

        return $product->resourceOptions()->create([
            'kind' => $kind,
            'unit' => ResourceKinds::BUILT_IN[$kind]['unit'],
            'step_size' => $units,
            'min_steps' => $min,
            'max_steps' => $max,
            'default_steps' => $min,
            'client_can_add' => true,
            'client_can_remove' => $snapshot->canShrink,
            'is_active' => $fits && ! (int) ($resource['option']->hidden ?? 0),
            'sort_order' => (int) ResourceOption::query()->where('product_id', $product->id)->max('sort_order') + 1,
            'can_shrink' => $snapshot->canShrink,
            'restarts' => $snapshot->restarts,
            'note' => $snapshot->note !== null ? Str::limit($snapshot->note, 200, '') : null,
            'max_extra' => $snapshot->maxExtra,
        ]);
    }

    /**
     * The price of one quantity in each currency and billing cycle the product is sold with.
     * WHMCS marks a price that is not offered with -1.
     *
     * @param  list<stdClass>  $prices  The option's tblpricing rows.
     * @param  list<string>  $cycles
     */
    private function importOptionPrices(ResourceOption $option, array $prices, array $cycles, string $payType): void
    {
        $columns = $payType === 'onetime' ? [BillingCycle::OneTime->value => ['monthly', 'msetupfee']] : self::CYCLE_COLUMNS;

        foreach ($prices as $price) {
            foreach ($columns as $cycle => [$priceColumn, $setupColumn]) {
                if (! in_array($cycle, $cycles, true) || ! is_numeric($price->{$priceColumn} ?? null) || (float) $price->{$priceColumn} < 0) {
                    continue;
                }

                ResourceOptionPrice::query()->updateOrCreate(
                    ['resource_option_id' => $option->id, 'currency' => $this->currency($price->currency ?? 0), 'billing_cycle' => $cycle],
                    ['price' => $this->money($price->{$priceColumn}), 'setup_fee' => max(0, $this->money($price->{$setupColumn} ?? 0))],
                );
            }
        }
    }

    /**
     * WHMCS marks a cycle that is not offered with a price of -1.
     *
     * @param  Collection<int, stdClass>  $prices
     */
    private function importPrices(Product $product, string $payType, Collection $prices): void
    {
        if ($payType === 'free') {
            ProductPrice::query()->updateOrCreate(
                ['product_id' => $product->id, 'currency' => $this->currency(0), 'billing_cycle' => BillingCycle::Free->value],
                ['price' => 0, 'setup_fee' => 0],
            );

            return;
        }

        foreach ($prices as $price) {
            $cycles = $payType === 'onetime'
                ? [BillingCycle::OneTime->value => ['monthly', 'msetupfee']]
                : self::CYCLE_COLUMNS;

            foreach ($cycles as $cycle => [$priceColumn, $setupColumn]) {
                if (! is_numeric($price->{$priceColumn} ?? null) || (float) $price->{$priceColumn} < 0) {
                    continue;
                }

                ProductPrice::query()->updateOrCreate(
                    ['product_id' => $product->id, 'currency' => $this->currency($price->currency ?? 0), 'billing_cycle' => $cycle],
                    ['price' => $this->money($price->{$priceColumn}), 'setup_fee' => max(0, $this->money($price->{$setupColumn} ?? 0))],
                );
            }
        }
    }

    /**
     * Services, with the extras of their configurable options (see importExtras()). Once a service's
     * extras changed in Nuvabill (see managedHere()), a later run leaves its price, billing cycle
     * and extras as Nuvabill has them, so the cycle and the prices billed for it stay together.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importServices(Collection $rows): void
    {
        $vpsIds = $this->customFieldValues('vpsid', $rows->pluck('id')->all());
        $solusIds = $this->customFieldValues('vserverid', $rows->pluck('id')->all());
        $optionValues = $this->optionValues($rows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->userid ?? 0);
            $productId = $this->localId('product', $row->packageid ?? 0);

            if ($clientId === null || $productId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $cycle = $this->cycle($row->billingcycle ?? '');
            $status = $this->serviceStatus($row->domainstatus ?? '');
            $localId = $this->localId('service', $row->id);
            $managedHere = $localId !== null && $this->managedHere($localId);
            $attributes = [
                'product_id' => $productId,
                'server_id' => $this->localId('server', $row->server ?? 0),
                'domain' => $this->text($row->domain ?? null),
                'username' => $this->text($row->username ?? null),
                'status' => $status->value,
                'billing_cycle' => $cycle->value,
                'first_payment_amount' => $this->money($row->firstpaymentamount ?? 0),
                'recurring_amount' => $this->money($row->amount ?? 0),
                'registration_date' => $this->date($row->regdate ?? null) ?? today()->toDateString(),
                'next_due_date' => $cycle->isRecurring() ? $this->date($row->nextduedate ?? null) : null,
                'suspension_reason' => $status === ServiceStatus::Suspended ? $this->text($row->suspendreason ?? null) : null,
                'terminated_at' => $status === ServiceStatus::Terminated ? ($this->date($row->termination_date ?? null) ?? now()) : null,
            ];

            if ($managedHere) {
                unset($attributes['recurring_amount'], $attributes['billing_cycle']);
            }

            $service = $this->upsert('service', (int) $row->id, Service::class, $attributes, [
                'client_id' => $clientId,
                'currency' => $this->clientCurrency($clientId),
                'module_data' => match (true) {
                    isset($vpsIds[$row->id]) => ['vpsid' => (string) $vpsIds[$row->id]],
                    isset($solusIds[$row->id]) => ['vserverid' => (string) $solusIds[$row->id]],
                    default => null,
                },
            ]);

            // The status Nuvabill kept, which may not be the source's one (see keepLocalStatus()).
            $service->suspended_at = $service->status === ServiceStatus::Suspended ? ($service->suspended_at ?? now()) : null;

            if (blank($service->password) && ($password = $this->decrypt($row->password ?? null)) !== null) {
                $service->password = $password;
            }

            if (! $managedHere) {
                $this->importExtras($service, (int) ($row->packageid ?? 0), $this->money($row->amount ?? 0), $optionValues[(int) $row->id] ?? []);
            }

            $service->save();
        }
    }

    /**
     * Whether a service's extras changed in Nuvabill since it was imported: it has a resource change,
     * or a resource row the import did not make (such as one staff added, or one the move from
     * Virtualizor resource add-ons made).
     */
    private function managedHere(int $serviceId): bool
    {
        return ResourceChange::query()->where('service_id', $serviceId)->exists()
            || ServiceResourceOption::query()->where('service_id', $serviceId)->whereNotIn('id', $this->importedRows())->exists();
    }

    /**
     * The IDs of the resource rows the import made, as a subquery.
     *
     * @return EloquentBuilder<ImportMapping>
     */
    private function importedRows(): EloquentBuilder
    {
        return ImportMapping::query()->select('local_id')->where(['source' => static::key(), 'entity' => self::RESOURCE_ROW]);
    }

    /**
     * Split a service's WHMCS price into its plan and the extras from its configurable options, so
     * its renewal total stays exactly the WHMCS amount. Rows the import made for kinds that no
     * longer come across are cancelled. When the extras would cost more than the whole price
     * (prices went up since), they come across at no charge and the plan keeps the price, which is
     * logged once.
     *
     * @param  array<int, stdClass>  $values  The service's option values, by WHMCS option ID.
     *
     * @throws RuntimeException When the split would change the renewal total; the service is then skipped.
     */
    private function importExtras(Service $service, int $sourceProductId, int $amount, array $values): void
    {
        $resources = $this->productResources($sourceProductId);
        $options = $this->localOptions($service->product_id, $resources);
        $split = $this->splitPrice($amount, $service->billing_cycle, (string) $service->currency, array_intersect_key($resources, $options), $values);
        $rows = $service->resources()->orderBy('id')->get()->keyBy('kind');

        if ($split['rows'] === [] && $rows->every(fn (ServiceResourceOption $row): bool => ! $row->isActive())) {
            return;
        }

        foreach ($split['rows'] as $kind => $extra) {
            $option = $options[$kind];
            $row = ($rows->get($kind) ?? new ServiceResourceOption(['service_id' => $service->id, 'kind' => $kind]))->fill([
                'resource_option_id' => $option->id,
                'unit' => $option->unit,
                'step_size' => $option->step_size,
                'steps' => $extra['steps'],
                'recurring_amount' => $extra['amount'],
                'status' => ServiceResourceOption::STATUS_ACTIVE,
            ]);
            $row->save();

            if ($extra['source'] > 0) {
                $this->remember(self::RESOURCE_ROW, $extra['source'], (int) $row->id);
            }
        }

        $made = $rows->isEmpty() ? [] : $this->importedRows()->whereIn('local_id', $rows->pluck('id'))->pluck('local_id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach ($rows as $kind => $row) {
            if (! isset($split['rows'][$kind]) && $row->status === ServiceResourceOption::STATUS_ACTIVE && in_array((int) $row->id, $made, true)) {
                $row->fill(['steps' => 0, 'recurring_amount' => 0, 'status' => ServiceResourceOption::STATUS_CANCELLED])->save();
            }
        }

        $service->recurring_amount = $split['plan'];
        $extras = (int) $service->resources()->where('status', ServiceResourceOption::STATUS_ACTIVE)->where('steps', '>', 0)->sum('recurring_amount');

        if ($service->recurring_amount + $extras !== $amount) {
            throw new RuntimeException(__('The extras of this service would change its price, so it was not imported. Check its configurable options.'));
        }

        if ($split['included'] && ! ActivityLog::query()->where(['subject_type' => $service->getMorphClass(), 'subject_id' => $service->id, 'action' => self::EXTRAS_IN_PLAN])->exists()) {
            Activity::log(self::EXTRAS_IN_PLAN, 'Imported from WHMCS: its extras cost more than its price there, so they are included in the plan price.', $service);
        }
    }

    /**
     * How a WHMCS price splits into the plan and the extras: each extra costs its quantity times the
     * option's WHMCS price in the service's currency and cycle (nothing on a cycle that does not
     * renew). When the extras cost more than the whole price, they are free and the plan keeps it.
     *
     * @param  array<string, array{id: int, units: int, option: stdClass}>  $resources
     * @param  array<int, stdClass>  $values  Option values by WHMCS option ID.
     * @return array{rows: array<string, array{steps: int, amount: int, source: int}>, plan: int, included: bool} Each row's source is the ID of its WHMCS option value.
     */
    private function splitPrice(int $amount, BillingCycle $cycle, string $currency, array $resources, array $values): array
    {
        $choices = $this->firstChoices(array_column($resources, 'id'));
        $rows = [];

        foreach ($resources as $kind => $resource) {
            $steps = max(0, (int) ($values[$resource['id']]->qty ?? 0));
            $steps = $kind === 'backups' ? min($steps, 1) : $steps;

            if ($steps === 0 || $steps > self::MAX_ROW_STEPS) {
                continue;
            }

            $choice = $choices[$resource['id']] ?? null;
            $price = $cycle->isRecurring() && $choice !== null ? ($this->optionPrice($choice, $currency, $cycle) ?? 0) : 0;
            $rows[$kind] = ['steps' => $steps, 'amount' => $steps * $price, 'source' => (int) ($values[$resource['id']]->id ?? 0)];
        }

        $sum = array_sum(array_column($rows, 'amount'));
        $included = $sum > $amount;

        if ($included) {
            $rows = array_map(fn (array $row): array => ['amount' => 0] + $row, $rows);
        }

        return ['rows' => $rows, 'plan' => $included ? $amount : $amount - $sum, 'included' => $included];
    }

    private function serviceStatus(mixed $value): ServiceStatus
    {
        return match ((string) $value) {
            'Active', 'Completed' => ServiceStatus::Active,
            'Suspended' => ServiceStatus::Suspended,
            'Terminated' => ServiceStatus::Terminated,
            'Cancelled' => ServiceStatus::Cancelled,
            'Fraud' => ServiceStatus::Fraud,
            default => ServiceStatus::Pending,
        };
    }

    /**
     * Prices for one year, per currency. Extensions WHMCS does not sell (register price -1) are skipped.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTlds(Collection $rows): void
    {
        $prices = $this->hasTable('tblpricing')
            ? $this->db()->table('tblpricing')->whereIn('type', ['domainregister', 'domaintransfer', 'domainrenew'])->whereIn('relid', $rows->pluck('id'))->get()->groupBy('relid')
            : collect();

        foreach ($rows as $row) {
            $tld = strtolower(ltrim(trim((string) ($row->extension ?? '')), '.'));

            if (! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $tld)) {
                $this->counts['skipped']++;

                continue;
            }

            $registrar = strtolower((string) ($row->autoreg ?? ''));

            foreach ($prices->get($row->id, collect())->groupBy('currency') as $currencyId => $group) {
                $byType = $group->keyBy('type');
                $register = $byType->get('domainregister')?->msetupfee;

                if (! is_numeric($register) || (float) $register < 0) {
                    continue;
                }

                $price = fn (string $type): int => is_numeric($byType->get($type)?->msetupfee) && (float) $byType->get($type)->msetupfee >= 0
                    ? $this->money($byType->get($type)->msetupfee)
                    : $this->money($register);

                $tldPrice = TldPrice::query()->updateOrCreate(['tld' => $tld, 'currency' => $this->currency($currencyId)], [
                    'registrar' => in_array($registrar, self::REGISTRARS, true) ? $registrar : null,
                    'register_price' => $this->money($register),
                    'transfer_price' => $price('domaintransfer'),
                    'renew_price' => $price('domainrenew'),
                    'epp_required' => (bool) ($row->eppcode ?? true),
                    'is_enabled' => true,
                    'sort_order' => (int) ($row->order ?? 0),
                ]);

                $this->counts[$tldPrice->wasRecentlyCreated ? 'created' : 'updated']++;
            }
        }

        $this->knownTlds = null;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importDomains(Collection $rows): void
    {
        $this->knownTlds ??= TldPrice::query()->distinct()->pluck('tld')->all();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->userid ?? 0);
            $name = strtolower((string) $this->text($row->domain ?? null));

            if ($clientId === null || ! DomainName::isValid($name)) {
                $this->counts['skipped']++;

                continue;
            }

            $registrar = strtolower((string) ($row->registrar ?? ''));

            $this->upsert('domain', (int) $row->id, Domain::class, [
                'name' => $name,
                'tld' => DomainName::split($name, $this->knownTlds)[1],
                'registrar' => in_array($registrar, self::REGISTRARS, true) ? $registrar : null,
                'order_type' => strtolower((string) ($row->type ?? '')) === 'transfer' ? Domain::TYPE_TRANSFER : Domain::TYPE_REGISTER,
                'status' => (match ((string) ($row->status ?? '')) {
                    'Active' => DomainStatus::Active,
                    'Pending Transfer' => DomainStatus::PendingTransfer,
                    'Grace', 'Redemption', 'Expired' => DomainStatus::Expired,
                    'Transferred Away' => DomainStatus::TransferredAway,
                    'Cancelled' => DomainStatus::Cancelled,
                    'Fraud' => DomainStatus::Fraud,
                    default => DomainStatus::Pending,
                })->value,
                'years' => max(1, min(10, (int) ($row->registrationperiod ?? 1))),
                'first_payment_amount' => $this->money($row->firstpaymentamount ?? 0),
                'recurring_amount' => $this->money($row->recurringamount ?? 0),
                'registered_at' => $this->date($row->registrationdate ?? null),
                'expires_at' => $this->date($row->expirydate ?? null),
                'next_due_date' => $this->date($row->nextduedate ?? null),
                'auto_renew' => ! ($row->donotrenew ?? false),
            ], [
                'client_id' => $clientId,
                'currency' => $this->clientCurrency($clientId),
            ]);
        }
    }

    /**
     * Invoices with their lines. Credit applied in WHMCS counts as paid, like account credit in Nuvabill.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importInvoices(Collection $rows): void
    {
        $ids = $rows->pluck('id')->all();
        $payments = $this->hasTable('tblaccounts')
            ? $this->db()->table('tblaccounts')->whereIn('invoiceid', $ids)->groupBy('invoiceid')->selectRaw('invoiceid, SUM(amountin) - SUM(amountout) as paid')->pluck('paid', 'invoiceid')->all()
            : [];
        $items = $this->hasTable('tblinvoiceitems')
            ? $this->db()->table('tblinvoiceitems')->whereIn('invoiceid', $ids)->orderBy('id')->get()->groupBy('invoiceid')
            : collect();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->userid ?? 0);

            if ($clientId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $status = match ((string) ($row->status ?? '')) {
                'Paid' => InvoiceStatus::Paid,
                'Cancelled' => InvoiceStatus::Cancelled,
                'Refunded' => InvoiceStatus::Refunded,
                'Draft' => InvoiceStatus::Draft,
                default => InvoiceStatus::Unpaid,
            };

            $subtotal = $this->money($row->subtotal ?? 0);
            $tax = $this->money($row->tax ?? 0) + $this->money($row->tax2 ?? 0);
            $total = $subtotal + $tax;
            $paid = $status === InvoiceStatus::Paid ? $total : min($total, max(0, $this->money($row->credit ?? 0) + $this->money($payments[$row->id] ?? 0)));
            $localId = $this->localId('invoice', $row->id);
            $isNew = $localId === null;
            $wasDraft = ! $isNew && Invoice::query()->whereKey($localId)->toBase()->value('status') === InvoiceStatus::Draft->value;
            $issued = $this->date($row->date ?? null) ?? today()->toDateString();
            $number = fn (): string => $this->unique(Invoice::class, 'number', $this->text($row->invoicenum ?? null) ?? (string) $row->id);

            $invoice = $this->upsert('invoice', (int) $row->id, Invoice::class, [
                'status' => $status->value,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'amount_paid' => $paid,
                'issued_at' => $issued,
                'due_at' => $this->date($row->duedate ?? null) ?? $issued,
                'paid_at' => $status === InvoiceStatus::Paid ? ($this->date($row->datepaid ?? null) ?? $issued) : null,
                'payment_method' => $this->text($row->paymentmethod ?? null),
                'notes' => $this->text($row->notes ?? null),
            ], [
                'client_id' => $clientId,
                'currency' => $this->clientCurrency($clientId),
                'number' => $status === InvoiceStatus::Draft ? null : $number(),
            ]);

            // A draft published in WHMCS since the last run gets its number, and a draft's lines may have changed.
            if ($wasDraft) {
                if ($invoice->status !== InvoiceStatus::Draft && $invoice->number === null) {
                    $invoice->forceFill(['number' => $number()])->save();
                }

                $invoice->items()->delete();
            }

            if ($isNew || $wasDraft) {
                foreach ($items->get($row->id, collect()) as $item) {
                    $this->importInvoiceItem($invoice, $item);
                }
            } else {
                // Cancelled in WHMCS after the first run: its periods may be invoiced again. One paid in
                // Nuvabill stays paid (see keepLocalProgress()), so it keeps its periods.
                $this->freeBillingKeys($invoice);
            }
        }
    }

    /**
     * Lines for services and domains keep their period, so paying an imported renewal invoice in Nuvabill
     * renews them, and Nuvabill never invoices the same period again.
     */
    private function importInvoiceItem(Invoice $invoice, stdClass $item): void
    {
        $type = match ((string) ($item->type ?? '')) {
            'Hosting' => InvoiceItem::TYPE_SERVICE,
            'Setup' => InvoiceItem::TYPE_SETUP,
            'DomainRegister' => InvoiceItem::TYPE_DOMAIN_REGISTER,
            'DomainTransfer' => InvoiceItem::TYPE_DOMAIN_TRANSFER,
            'Domain' => InvoiceItem::TYPE_DOMAIN_RENEW,
            default => InvoiceItem::TYPE_MANUAL,
        };

        $service = in_array($type, [InvoiceItem::TYPE_SERVICE, InvoiceItem::TYPE_SETUP], true) ? Service::query()->find($this->localId('service', $item->relid ?? 0)) : null;
        $domain = in_array($type, InvoiceItem::DOMAIN_TYPES, true) ? Domain::query()->find($this->localId('domain', $item->relid ?? 0)) : null;
        $start = $this->date($item->duedate ?? null);
        $start = $start !== null ? CarbonImmutable::parse($start) : null;

        $end = match (true) {
            $start === null => null,
            $type === InvoiceItem::TYPE_SERVICE && $service?->billing_cycle->isRecurring() => $service->billing_cycle->advance($start)->subDay(),
            $domain !== null => $start->addYears($domain->years)->subDay(),
            default => null,
        };

        $key = match (true) {
            $end === null || $invoice->status === InvoiceStatus::Cancelled => null,
            $type === InvoiceItem::TYPE_SERVICE && $service !== null => RenewalGenerator::billingKey('service', $service->id, $start),
            $type === InvoiceItem::TYPE_DOMAIN_RENEW && $domain !== null => RenewalGenerator::billingKey('domain', $domain->id, $start),
            default => null,
        };

        // WHMCS may hold two invoices for one period; only the first keeps the key.
        if ($key !== null && InvoiceItem::query()->where('billing_key', $key)->exists()) {
            $key = null;
        }

        $invoice->items()->create([
            'service_id' => $service?->id,
            'domain_id' => $domain?->id,
            'type' => $service === null && $domain === null ? InvoiceItem::TYPE_MANUAL : $type,
            'description' => Str::limit($this->text($item->description ?? null) ?? '-', 250),
            'amount' => $this->money($item->amount ?? 0),
            'period_start' => $end !== null ? $start : null,
            'period_end' => $end,
            'billing_key' => $key,
        ]);
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTransactions(Collection $rows): void
    {
        foreach ($rows as $row) {
            $invoiceId = $this->localId('invoice', $row->invoiceid ?? 0);
            $clientId = $this->localId('client', $row->userid ?? 0) ?? ($invoiceId !== null ? Invoice::query()->whereKey($invoiceId)->value('client_id') : null);
            $received = $this->money($row->amountin ?? 0);
            $refunded = $this->money($row->amountout ?? 0);

            if ($clientId === null || ($received === 0 && $refunded === 0)) {
                $this->counts['skipped']++;

                continue;
            }

            $gateway = $this->gateway($row->gateway ?? null);
            $reference = Str::limit($this->text($row->transid ?? null) ?? 'whmcs-'.$row->id, 180, '');

            if ($this->localId('transaction', $row->id) === null && Transaction::query()->where('gateway', $gateway)->where('reference', $reference)->exists()) {
                $reference .= '-whmcs'.$row->id;
            }

            $this->upsert('transaction', (int) $row->id, Transaction::class, [
                'invoice_id' => $invoiceId,
                'type' => $refunded > 0 ? 'refund' : 'payment',
                'amount' => $refunded > 0 ? $refunded : $received,
                'fee' => max(0, $this->money($row->fees ?? 0)),
                'paid_at' => $this->date($row->date ?? null) ?? now(),
            ], [
                'client_id' => $clientId,
                'gateway' => $gateway,
                'reference' => $reference,
                'currency' => $this->currency($row->currency ?? 0, $this->clientCurrency($clientId)),
                'meta' => array_filter(['whmcs_id' => (int) $row->id, 'description' => $this->text($row->description ?? null)]),
            ]);
        }
    }

    private function gateway(mixed $name): string
    {
        $name = strtolower((string) $this->text($name));

        return self::GATEWAYS[$name] ?? (Str::limit($name, 60, '') ?: 'manual');
    }

    /**
     * Departments with the same name as an existing one are linked to it, and that one is not changed.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importDepartments(Collection $rows): void
    {
        foreach ($rows as $row) {
            $name = $this->text($row->name ?? null) ?? 'Support';

            if (! $this->departmentToImport((int) $row->id, $name)) {
                continue;
            }

            $email = filter_var($row->email ?? null, FILTER_VALIDATE_EMAIL) ?: null;

            $this->upsert('department', (int) $row->id, TicketDepartment::class, [
                'name' => $name,
                'email' => $email,
                'description' => $this->text($row->description ?? null),
                'is_visible' => ! ($row->hidden ?? false),
                'sort_order' => (int) ($row->order ?? 0),
            ]);
        }
    }

    /**
     * Tickets with their messages. Tickets from guests (no client account) are skipped.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTickets(Collection $rows): void
    {
        $replies = $this->hasTable('tblticketreplies')
            ? $this->db()->table('tblticketreplies')->whereIn('tid', $rows->pluck('id'))->orderBy('id')->get()->groupBy('tid')
            : collect();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->userid ?? 0);
            $departmentId = $this->localId('department', $row->did ?? 0);

            if ($clientId === null || $departmentId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $status = match ((string) ($row->status ?? '')) {
                'Answered' => TicketStatus::Answered,
                'Customer-Reply' => TicketStatus::CustomerReply,
                'On Hold' => TicketStatus::OnHold,
                'Closed' => TicketStatus::Closed,
                default => TicketStatus::Open,
            };

            $opened = $this->date($row->date ?? null) ?? now()->toDateTimeString();
            $lastReply = $this->date($row->lastreply ?? null) ?? $opened;
            $isNew = $this->localId('ticket', $row->id) === null;

            $ticket = $this->upsert('ticket', (int) $row->id, Ticket::class, [
                'subject' => Str::limit($this->text($row->title ?? null) ?? __('(no subject)'), 250),
                'status' => $status->value,
                'priority' => (match (strtolower((string) ($row->urgency ?? ''))) {
                    'low' => TicketPriority::Low,
                    'high' => TicketPriority::High,
                    default => TicketPriority::Medium,
                })->value,
                'last_reply_at' => $lastReply,
                'closed_at' => $status === TicketStatus::Closed ? $lastReply : null,
            ], [
                'number' => $this->unique(Ticket::class, 'number', $this->text($row->tid ?? null) ?? (string) $row->id),
                'client_id' => $clientId,
                'ticket_department_id' => $departmentId,
                'service_id' => preg_match('/^S(\d+)$/', (string) ($row->service ?? ''), $match) ? $this->localId('service', $match[1]) : null,
                'created_at' => $opened,
            ]);

            if ($isNew) {
                $this->addReply($ticket, ['client', $clientId], (string) ($row->message ?? ''), $opened, $row->ipaddress ?? null);
            }

            foreach ($replies->get($row->id, collect()) as $reply) {
                if ($this->localId('ticket_reply', $reply->id) !== null) {
                    continue;
                }

                $author = filled($reply->admin ?? null)
                    ? ['admin', $this->staffId((string) $this->text($reply->admin))]
                    : ['client', $this->localId('client', $reply->userid ?? 0) ?? $clientId];

                $local = $this->addReply($ticket, $author, (string) ($reply->message ?? ''), $this->date($reply->date ?? null) ?? $lastReply, null);
                $this->remember('ticket_reply', (int) $reply->id, $local->id);
            }
        }
    }

    /**
     * @param  array{0: string, 1: int|null}  $author
     */
    private function addReply(Ticket $ticket, array $author, string $message, string $at, ?string $ip): TicketReply
    {
        $reply = (new TicketReply)->forceFill([
            'ticket_id' => $ticket->id,
            'author_type' => $author[0],
            'author_id' => $author[1],
            'message' => trim(strip_tags((string) $this->text($message))) ?: '-',
            'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ?: null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $reply->save();

        return $reply;
    }

    protected function entities(): array
    {
        return ['staff' => 'admin', 'clients' => 'client', 'product_groups' => 'product_group', 'servers' => 'server', 'products' => 'product', 'services' => 'service', 'domains' => 'domain', 'invoices' => 'invoice', 'transactions' => 'transaction', 'departments' => 'department', 'tickets' => 'ticket'];
    }

    /**
     * What an import would do, read without changing anything.
     */
    public function preflight(): Preflight
    {
        $report = new Preflight(self::name(), $this->version());

        $this->addSteps($report);
        $this->preflightClients($report);
        $this->preflightCatalog($report);
        $this->preflightBilling($report);
        $this->preflightSupport($report);

        $this->automationAdvice($report);

        return $report;
    }

    private function preflightClients(Preflight $report): void
    {
        $clients = $this->db()->table('tblclients')->get(['id', 'email', 'password']);
        $owners = $this->ownerPasswords();
        $hashes = $clients->map(fn (stdClass $row): string => (string) ($owners[$row->id] ?? $row->password ?? ''));
        $encrypted = $this->secret === null ? collect() : $hashes->filter(fn (string $hash): bool => ! LegacyPassword::isBcrypt($hash) && $this->legacyPassword($hash) === null && (new WhmcsCrypt($this->secret))->decrypt($hash) !== null);

        $this->checkEmails($report, $clients->pluck('email'));
        $this->checkPasswords($report, $hashes->diffKeys($encrypted));
        $report->problem(Preflight::INFO, $encrypted->count(), 'Client passwords that WHMCS encrypted: :count. The key opens them, so they keep working.');

        if ($this->hasTable('tblusers_clients')) {
            $report->problem(Preflight::INFO, $this->db()->table('tblusers_clients')->where('owner', 0)->count(), 'Extra users of client accounts: :count. They are not imported; the account owner signs in.');
        }
    }

    private function preflightCatalog(Preflight $report): void
    {
        if ($this->hasTable('tblservers')) {
            $servers = $this->db()->table('tblservers')->get(['id', 'name', 'type', 'password']);
            [$supported, $unsupported] = $servers->partition(fn (stdClass $row): bool => $this->serverModule($row->type, self::SERVER_MODULES) !== null);
            $withPassword = $supported->filter(fn (stdClass $row): bool => filled($row->password));
            $unreadable = $withPassword->filter(fn (stdClass $row): bool => $this->decrypt($row->password) === null)->count();

            $report->problem(Preflight::WARNING, $unsupported->count(), 'Servers with a module Nuvabill does not have: :count. They are skipped.', examples: $unsupported->map(fn (stdClass $row): string => $this->text($row->name).' ('.$row->type.')')->all());
            $report->problem(Preflight::INFO, $supported->count(), 'Servers are imported switched off: :count. Check them in Servers, then switch them on.');

            if ($this->secret === null) {
                $report->problem(Preflight::WARNING, $unreadable, 'Server passwords that need the WHMCS key: :count. Add the key, or enter the passwords in Servers after the import.');
            } elseif ($unreadable > 0 && $unreadable === $withPassword->count()) {
                $report->problem(Preflight::ERROR, $unreadable, 'The WHMCS key did not open any of the :count server passwords. Check that it is the value of $cc_encryption_hash in configuration.php.');
            } else {
                $report->problem(Preflight::WARNING, $unreadable, 'Server passwords this key could not open: :count. Enter them in Servers after the import.');
            }
        }

        if ($this->hasTable('tblproducts')) {
            $products = $this->db()->table('tblproducts')->where('servertype', '!=', '')->get(['name', 'servertype'])
                ->filter(fn (stdClass $row): bool => $this->serverModule($row->servertype, self::SERVER_MODULES) === null);
            $report->problem(Preflight::WARNING, $products->count(), 'Products with a module Nuvabill does not have: :count. They are imported for manual setup.', examples: $products->map(fn (stdClass $row): string => $this->text($row->name).' ('.$row->servertype.')')->all());
        }

        $count = fn (string $table): int => $this->hasTable($table) ? $this->db()->table($table)->count() : 0;
        $report->problem(Preflight::WARNING, $count('tblhostingaddons'), 'Addons on services, not imported: :count.');
        $this->preflightConfigOptions($report);
        $report->problem(Preflight::WARNING, $count('tblcustomfieldsvalues'), 'Custom field values, not imported: :count.');

        if ($this->hasTable('tbldomains')) {
            $other = $this->db()->table('tbldomains')->where('registrar', '!=', '')->whereNotIn(DB::raw('LOWER(registrar)'), self::REGISTRARS);
            $report->problem(Preflight::INFO, (clone $other)->count(), 'Domains with a registrar Nuvabill does not have: :count. They are imported without one; renew them by hand.', examples: $other->distinct()->pluck('registrar')->all());
        }
    }

    /**
     * What happens to configurable options: the ones brought across as resource options, the
     * services that get extras, and what cannot come across.
     */
    private function preflightConfigOptions(Preflight $report): void
    {
        if (! $this->hasConfigOptions()) {
            $report->problem(Preflight::WARNING, $this->hasTable('tblhostingconfigoptions') ? $this->db()->table('tblhostingconfigoptions')->count() : 0, 'Configurable option values, not imported: :count.');

            return;
        }

        $options = $this->configOptionRows();
        $map = $this->optionMap();

        $report->problem(Preflight::INFO, count($map), 'Configurable options brought across as resource options: :count. Each service keeps the same renewal total.');
        $report->problem(Preflight::WARNING, $this->db()->table('tblhostingconfigoptions')->when($map !== [], fn ($query) => $query->whereNotIn('configid', array_keys($map)))->count(), 'Configurable option values, not imported: :count.');

        $choiceLists = array_filter($options, fn (stdClass $option): bool => in_array($this->optionType($option), ['dropdown', 'radio'], true));
        $priced = $this->pricedOptions(array_keys($choiceLists));
        $used = $this->optionUse('optionid');

        foreach ($choiceLists as $id => $option) {
            if ($priced[$id] ?? false) {
                $report->problem(Preflight::WARNING, $used[$id] ?? 0, 'Configurable option ":name" (dropdown or radio) was not imported: set resource options by hand. The service price still includes it.', ['name' => $this->optionName($option)]);
            }
        }

        if ($map === []) {
            return;
        }

        $products = $this->sourceProducts(array_keys($this->productOptions));
        [$twice, $hidden, $otherStep] = [[], [], []];

        foreach ($this->productOptions as $sourceProductId => $ids) {
            $product = $products[$sourceProductId] ?? null;
            $kinds = [];

            foreach ($ids as $id) {
                $kind = $map[$id]['kind'] ?? null;

                if ($kind === null || $product === null) {
                    continue;
                }

                if (isset($kinds[$kind])) {
                    $twice[] = $this->text($product->name).' ('.$this->optionName($options[$id]).')';
                } else {
                    $kinds[$kind] = $id;
                }
            }

            if ($kinds === []) {
                continue;
            }

            $localId = $this->localId('product', $sourceProductId);
            $local = $localId !== null ? Product::query()->find($localId) : null;
            $moduleKinds = $this->moduleKinds($local ?? (new Product)->forceFill(['server_module' => $this->serverModule($product->servertype ?? null, self::SERVER_MODULES), 'module_config' => []]));
            $existing = $local !== null ? $this->localOptions($local->id) : [];

            foreach ($kinds as $kind => $id) {
                $label = $this->text($product->name).' ('.ResourceKinds::label($kind).')';

                if (isset($existing[$kind]) && $existing[$kind]->step_size !== $map[$id]['units']) {
                    $otherStep[] = $label;
                } elseif (! isset($existing[$kind]) && ! $this->kindFits($moduleKinds[$kind] ?? null, $kind, $map[$id]['units'])) {
                    $hidden[] = $label;
                }
            }
        }

        $report->problem(Preflight::WARNING, count($twice), 'Configurable options set to a resource another option of the same product already has: :count. Only the first one is brought across; the price of the others stays in the plan.', examples: $twice);
        $report->problem(Preflight::WARNING, count($otherStep), 'Products that already have this resource with another step size: :count. Those configurable options are not brought across; the service price still includes them.', examples: $otherStep);
        $report->problem(Preflight::WARNING, count($hidden), 'Resource options the server module of the product cannot set: :count. They are imported hidden from the store; change these extras on the server by hand.', examples: $hidden);

        [$withExtras, $inPlan] = $this->previewExtras($map);
        $report->problem(Preflight::INFO, $withExtras, 'Services that get extras from configurable options: :count.');
        $report->problem(Preflight::WARNING, count($inPlan), 'Services whose extras cost more than their WHMCS price: :count. Their extras come across at no charge, and the whole price stays in the plan.', examples: $inPlan);
    }

    /**
     * How many services get extras, and the ones whose extras would cost more than their price.
     *
     * @param  array<int, array{kind: string, units: int}>  $map
     * @return array{0: int, 1: list<string>}
     */
    private function previewExtras(array $map): array
    {
        $serviceIds = $this->db()->table('tblhostingconfigoptions')->whereIn('configid', array_keys($map))->where('qty', '>', 0)->distinct()->orderBy('relid')->pluck('relid')
            ->map(fn (mixed $id): int => (int) $id)->all();
        [$withExtras, $inPlan] = [0, []];

        foreach (array_chunk($serviceIds, 500) as $chunk) {
            $values = $this->optionValues($chunk);
            $services = $this->db()->table('tblhosting')->whereIn('id', $chunk)->orderBy('id')->get(['id', 'userid', 'packageid', 'domain', 'amount', 'billingcycle']);
            $currencies = $this->db()->table('tblclients')->whereIn('id', $services->pluck('userid')->unique()->values()->all())->pluck('currency', 'id');

            foreach ($services as $service) {
                $resources = $this->usableResources((int) $service->packageid);
                $currency = $this->currency($currencies[$service->userid] ?? 0);
                $split = $this->splitPrice($this->money($service->amount ?? 0), $this->cycle($service->billingcycle ?? ''), $currency, $resources, $values[(int) $service->id] ?? []);

                if ($split['rows'] !== []) {
                    $withExtras++;
                }

                if ($split['included']) {
                    $inPlan[] = $this->text($service->domain ?? null) ?? '#'.$service->id;
                }
            }
        }

        return [$withExtras, $inPlan];
    }

    /**
     * The resources a WHMCS product brings across, without kinds its Nuvabill copy already has with
     * another step.
     *
     * @return array<string, array{id: int, units: int, option: stdClass}>
     */
    private function usableResources(int $sourceProductId): array
    {
        $resources = $this->productResources($sourceProductId);
        $localId = $this->localId('product', $sourceProductId);

        if ($resources === [] || $localId === null) {
            return $resources;
        }

        $existing = $this->localOptions($localId);

        return array_filter($resources, fn (array $resource, string $kind): bool => ! isset($existing[$kind]) || $existing[$kind]->step_size === $resource['units'], ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Configurable options staff can choose to bring across, for the dry run: every quantity and
     * yes/no option in a group linked to a product, and dropdowns and radio buttons with a price
     * (shown as not imported). Read without changing anything.
     *
     * @return array{options: list<array{id: int, name: string, type: string, products: list<string>, product_count: int, services: int, hidden: bool, suggest: string|null, units: int}>, more: int}
     */
    public function configOptions(): array
    {
        if (! $this->hasConfigOptions()) {
            return ['options' => [], 'more' => 0];
        }

        $options = $this->configOptionRows();
        $choiceLists = array_keys(array_filter($options, fn (stdClass $option): bool => in_array($this->optionType($option), ['dropdown', 'radio'], true)));
        $priced = $this->pricedOptions($choiceLists);
        $listed = array_filter($options, fn (stdClass $option, int $id): bool => in_array($this->optionType($option), ['quantity', 'yesno'], true) || ($priced[$id] ?? false), ARRAY_FILTER_USE_BOTH);
        $counts = $this->optionUse('qty');
        $choiceCounts = $this->optionUse('optionid');
        $productsOf = [];

        foreach ($this->productOptions as $sourceProductId => $ids) {
            foreach ($ids as $id) {
                $productsOf[$id][] = $sourceProductId;
            }
        }

        $names = $this->sourceProducts(array_keys($this->productOptions));
        $list = [];

        foreach ($listed as $id => $option) {
            $type = (string) $this->optionType($option);
            $suggest = $this->suggestedKind($option, $type);
            $products = $productsOf[$id] ?? [];

            $list[] = [
                'id' => $id,
                'name' => Str::limit($this->optionName($option), 100),
                'type' => $type,
                'products' => array_values(array_filter(array_map(fn (int $productId): ?string => $this->text($names[$productId]->name ?? null), array_slice($products, 0, 3)))),
                'product_count' => count($products),
                'services' => (int) (in_array($type, ['quantity', 'yesno'], true) ? ($counts[$id] ?? 0) : ($choiceCounts[$id] ?? 0)),
                'hidden' => (bool) (int) ($option->hidden ?? 0),
                'suggest' => $suggest,
                'units' => $suggest === 'ram' ? 1024 : 1,
            ];
        }

        usort($list, fn (array $a, array $b): int => [! isset(self::CONFIG_OPTION_KINDS[$a['type']]), $a['id']] <=> [! isset(self::CONFIG_OPTION_KINDS[$b['type']]), $b['id']]);

        return ['options' => array_slice($list, 0, self::MAX_LISTED_OPTIONS), 'more' => max(0, count($list) - self::MAX_LISTED_OPTIONS)];
    }

    /**
     * The choices staff saved for configurable options: WHMCS option ID => kind and units per
     * quantity. Entries that are not valid are left out.
     *
     * @return array<int, array{kind: string, units: int}>
     */
    public static function savedConfigOptions(): array
    {
        $map = [];

        foreach ((array) (((array) setting('import.whmcs'))['config_options'] ?? []) as $entry) {
            $entry = (array) $entry;
            $id = filter_var($entry['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $kind = (string) ($entry['kind'] ?? '');
            $units = filter_var($entry['units'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_UNITS]]);

            if ($id === false || $units === false || ! in_array($kind, self::configOptionKinds(), true) || $units < ResourceKinds::BUILT_IN[$kind]['min_step']) {
                continue;
            }

            $map[$id] = ['kind' => $kind, 'units' => $kind === 'backups' ? 1 : $units];
        }

        return $map;
    }

    /**
     * Save the choices for configurable options next to the older WHMCS settings, which stay.
     *
     * @param  array<int, array{kind: string, units: int}>  $map
     */
    public static function saveConfigOptions(Settings $settings, array $map): void
    {
        $entries = [];

        foreach ($map as $id => $choice) {
            $entries[] = ['id' => (int) $id, 'kind' => $choice['kind'], 'units' => (int) $choice['units']];
        }

        $settings->set('import.whmcs', ['config_options' => $entries] + (array) setting('import.whmcs'));
    }

    /**
     * Every kind a configurable option may become.
     *
     * @return list<string>
     */
    public static function configOptionKinds(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::CONFIG_OPTION_KINDS))));
    }

    private function hasConfigOptions(): bool
    {
        return $this->hasTable('tblproductconfigoptions') && $this->hasTable('tblproductconfiglinks') && $this->hasTable('tblhostingconfigoptions');
    }

    /**
     * WHMCS configurable options in a group linked to a product, by ID, in the order WHMCS shows them.
     * Also fills which options each product has.
     *
     * @return array<int, stdClass>
     */
    private function configOptionRows(): array
    {
        if ($this->configOptions !== null) {
            return $this->configOptions;
        }

        [$this->configOptions, $this->productOptions] = [[], []];

        if (! $this->hasConfigOptions()) {
            return [];
        }

        $links = $this->db()->table('tblproductconfiglinks')->orderBy('id')->get(['gid', 'pid']);
        $options = [];

        foreach (array_chunk($links->pluck('gid')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(), 500) as $groups) {
            array_push($options, ...$this->db()->table('tblproductconfigoptions')->whereIn('gid', $groups)->get()->all());
        }

        usort($options, fn (stdClass $a, stdClass $b): int => [(int) ($a->order ?? 0), (int) $a->id] <=> [(int) ($b->order ?? 0), (int) $b->id]);
        $byGroup = [];

        foreach ($options as $option) {
            $this->configOptions[(int) $option->id] = $option;
            $byGroup[(int) $option->gid][] = (int) $option->id;
        }

        foreach ($links as $link) {
            foreach ($byGroup[(int) $link->gid] ?? [] as $id) {
                $this->productOptions[(int) $link->pid][] = $id;
            }
        }

        $this->productOptions = array_map(fn (array $ids): array => array_values(array_unique($ids)), $this->productOptions);

        return $this->configOptions;
    }

    /**
     * The saved choices for options that are still in WHMCS with a type that can become that kind.
     *
     * @return array<int, array{kind: string, units: int}>
     */
    private function optionMap(): array
    {
        if ($this->optionMap !== null) {
            return $this->optionMap;
        }

        $options = $this->configOptionRows();
        $this->optionMap = [];

        foreach (self::savedConfigOptions() as $id => $choice) {
            $type = isset($options[$id]) ? $this->optionType($options[$id]) : null;

            if (in_array($choice['kind'], self::CONFIG_OPTION_KINDS[$type] ?? [], true)) {
                $this->optionMap[$id] = $choice;
            }
        }

        return $this->optionMap;
    }

    /**
     * The configurable options of a WHMCS product that come across as resource options: the first
     * chosen option of each kind, in the order WHMCS shows them.
     *
     * @return array<string, array{id: int, units: int, option: stdClass}>
     */
    private function productResources(int $sourceProductId): array
    {
        $options = $this->configOptionRows();
        $map = $this->optionMap();
        $resources = [];

        foreach ($this->productOptions[$sourceProductId] ?? [] as $id) {
            $choice = $map[$id] ?? null;

            if ($choice !== null && ! isset($resources[$choice['kind']])) {
                $resources[$choice['kind']] = ['id' => $id, 'units' => $choice['units'], 'option' => $options[$id]];
            }
        }

        return $resources;
    }

    /**
     * A Nuvabill product's resource options by kind; with $resources, only the ones whose step is
     * the units of one WHMCS quantity.
     *
     * @param  array<string, array{id: int, units: int, option: stdClass}>|null  $resources
     * @return array<string, ResourceOption>
     */
    private function localOptions(int $productId, ?array $resources = null): array
    {
        $options = $this->localOptions[$productId] ??= ResourceOption::query()->where('product_id', $productId)->get()->keyBy('kind')->all();

        if ($resources === null) {
            return $options;
        }

        return array_filter($options, fn (ResourceOption $option, string $kind): bool => isset($resources[$kind]) && $option->step_size === $resources[$kind]['units'], ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Option values of these WHMCS services for the options brought across.
     *
     * @param  list<int>  $serviceIds
     * @return array<int, array<int, stdClass>> WHMCS service ID => option ID => value (id, qty, optionid).
     */
    private function optionValues(array $serviceIds): array
    {
        $optionIds = array_keys($this->optionMap());
        $values = [];

        if ($optionIds === [] || $serviceIds === []) {
            return [];
        }

        foreach (array_chunk($serviceIds, 400) as $services) {
            foreach (array_chunk($optionIds, 400) as $options) {
                foreach ($this->db()->table('tblhostingconfigoptions')->whereIn('relid', $services)->whereIn('configid', $options)->orderBy('id')->get(['id', 'relid', 'configid', 'optionid', 'qty']) as $value) {
                    $values[(int) $value->relid][(int) $value->configid] ??= $value;
                }
            }
        }

        return $values;
    }

    /**
     * The first choice of each option, which a quantity or yes/no option is priced by.
     *
     * @param  list<int>  $optionIds
     * @return array<int, int|null>
     */
    private function firstChoices(array $optionIds): array
    {
        $missing = array_values(array_diff($optionIds, array_keys($this->firstChoices)));

        if ($missing !== [] && $this->hasTable('tblproductconfigoptionssub')) {
            foreach (array_chunk($missing, 500) as $chunk) {
                $choices = $this->db()->table('tblproductconfigoptionssub')->whereIn('configid', $chunk)->get(['id', 'configid', 'sortorder'])->all();
                usort($choices, fn (stdClass $a, stdClass $b): int => [(int) ($a->sortorder ?? 0), (int) $a->id] <=> [(int) ($b->sortorder ?? 0), (int) $b->id]);

                foreach ($choices as $choice) {
                    $this->firstChoices[(int) $choice->configid] ??= (int) $choice->id;
                }
            }
        }

        foreach ($missing as $id) {
            $this->firstChoices[$id] ??= null;
        }

        return array_intersect_key($this->firstChoices, array_flip($optionIds));
    }

    /**
     * The tblpricing rows of these choices.
     *
     * @param  list<int>  $choiceIds
     * @return array<int, list<stdClass>>
     */
    private function choicePrices(array $choiceIds): array
    {
        $missing = array_values(array_diff($choiceIds, array_keys($this->choicePrices)));

        foreach ($missing as $id) {
            $this->choicePrices[$id] = [];
        }

        if ($missing !== [] && $this->hasTable('tblpricing')) {
            foreach (array_chunk($missing, 500) as $chunk) {
                foreach ($this->db()->table('tblpricing')->where('type', 'configoptions')->whereIn('relid', $chunk)->orderBy('id')->get() as $price) {
                    $this->choicePrices[(int) $price->relid][] = $price;
                }
            }
        }

        return array_intersect_key($this->choicePrices, array_flip($choiceIds));
    }

    /**
     * What one quantity costs per billing period in WHMCS, in minor units, or null when WHMCS has no
     * price for that currency and cycle.
     */
    private function optionPrice(int $choiceId, string $currency, BillingCycle $cycle): ?int
    {
        $column = self::CYCLE_COLUMNS[$cycle->value][0] ?? null;

        if ($column === null) {
            return null;
        }

        foreach ($this->choicePrices([$choiceId])[$choiceId] ?? [] as $price) {
            if ($this->currency($price->currency ?? 0) === $currency && is_numeric($price->{$column} ?? null) && (float) $price->{$column} >= 0) {
                return $this->money($price->{$column});
            }
        }

        return null;
    }

    /**
     * Which of these options have a price above zero for any choice, currency or cycle.
     *
     * @param  list<int>  $optionIds
     * @return array<int, bool>
     */
    private function pricedOptions(array $optionIds): array
    {
        $priced = [];

        if ($optionIds === [] || ! $this->hasTable('tblproductconfigoptionssub') || ! $this->hasTable('tblpricing')) {
            return $priced;
        }

        $columns = array_merge(...array_values(self::CYCLE_COLUMNS));

        foreach (array_chunk($optionIds, 500) as $chunk) {
            $choices = $this->db()->table('tblproductconfigoptionssub')->whereIn('configid', $chunk)->pluck('configid', 'id')->all();

            foreach (array_chunk(array_keys($choices), 500) as $choiceIds) {
                foreach ($this->db()->table('tblpricing')->where('type', 'configoptions')->whereIn('relid', $choiceIds)->get() as $price) {
                    if (collect($columns)->contains(fn (string $column): bool => is_numeric($price->{$column} ?? null) && (float) $price->{$column} > 0)) {
                        $priced[(int) $choices[$price->relid]] = true;
                    }
                }
            }
        }

        return $priced;
    }

    /**
     * How many services have a value for each option: a quantity above zero ("qty"), or a choice
     * ("optionid").
     *
     * @return array<int, int>
     */
    private function optionUse(string $column): array
    {
        return $this->db()->table('tblhostingconfigoptions')
            ->where($column, '>', 0)
            ->groupBy('configid')
            ->selectRaw('configid, COUNT(DISTINCT relid) as services')
            ->pluck('services', 'configid')
            ->mapWithKeys(fn (mixed $count, mixed $id): array => [(int) $id => (int) $count])
            ->all();
    }

    /**
     * WHMCS products by ID, with their name and server module.
     *
     * @param  list<int>  $ids
     * @return array<int, stdClass>
     */
    private function sourceProducts(array $ids): array
    {
        $products = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->db()->table('tblproducts')->whereIn('id', $chunk)->get(['id', 'name', 'servertype']) as $product) {
                $products[(int) $product->id] = $product;
            }
        }

        return $products;
    }

    private function optionType(stdClass $option): ?string
    {
        return self::OPTION_TYPES[(int) ($option->optiontype ?? 0)] ?? null;
    }

    /**
     * The name clients saw: WHMCS names may be "variable|Shown name".
     */
    private function optionName(stdClass $option): string
    {
        $name = (string) $this->text($option->optionname ?? null);

        return str_contains($name, '|') ? (trim(Str::after($name, '|')) ?: trim(Str::before($name, '|'))) : ($name ?: '#'.$option->id);
    }

    /**
     * The kind an option's name suggests, for staff to confirm. Only quantity options suggest a
     * size, and only yes/no options suggest backups.
     */
    private function suggestedKind(stdClass $option, string $type): ?string
    {
        $names = array_map(
            fn (string $name): string => (string) preg_replace('/^(additional|extra)\s+/', '', trim((string) preg_replace('/[\s_]+/', ' ', mb_strtolower($name)))),
            explode('|', (string) $this->text($option->optionname ?? null)),
        );

        if ($type === 'yesno') {
            return collect($names)->contains(fn (string $name): bool => str_contains($name, 'backup')) ? 'backups' : null;
        }

        if ($type !== 'quantity') {
            return null;
        }

        foreach (self::OPTION_NAMES as $kind => $known) {
            if (array_intersect($names, $known) !== []) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The kinds the product's server module can set (every built-in kind for a product without a
     * module, applied by hand), or none when it has none or cannot say.
     *
     * @return array<string, ResourceKind>
     */
    private function moduleKinds(Product $product): array
    {
        try {
            return app(Provisioner::class)->resourceKinds($product);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Whether the module's kind can sell steps of these units: same unit, at least its smallest
     * step, a multiple of its step, and within its maximum.
     */
    private function kindFits(?ResourceKind $kind, string $key, int $units): bool
    {
        return $kind !== null
            && $kind->ladder === null
            && $kind->unit === ResourceKinds::BUILT_IN[$key]['unit']
            && $units >= $kind->minStep
            && $units % max(1, $kind->stepMultiple) === 0
            && ($kind->maxExtra === null || $kind->maxExtra >= $units);
    }

    protected function forgetMappings(): void
    {
        parent::forgetMappings();

        $this->localOptions = [];
    }

    private function preflightBilling(Preflight $report): void
    {
        $suspendDays = (int) setting('automation.suspend_days');

        if ($suspendDays > 0 && $this->hasTable('tblhosting') && $this->hasTable('tblinvoiceitems')) {
            $overdue = $this->db()->table('tblhosting')
                ->where('domainstatus', 'Active')
                ->whereIn('id', fn ($query) => $query->select('relid')->from('tblinvoiceitems')
                    ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
                    ->where('tblinvoiceitems.type', 'Hosting')
                    ->where('tblinvoices.status', 'Unpaid')
                    ->where('tblinvoices.duedate', '>', '0000-00-00')
                    ->where('tblinvoices.duedate', '<=', today()->subDays($suspendDays)->toDateString()))
                ->count();

            $report->problem(Preflight::WARNING, $overdue, 'Active services with an invoice more than :days days overdue: :count. Nuvabill\'s first nightly run suspends them.', ['days' => $suspendDays]);
        }

        if ($this->hasTable('tblcurrencies')) {
            $known = array_merge([strtoupper((string) setting('billing.currency'))], array_keys((array) setting('currency.rates', [])));
            $other = $this->db()->table('tblcurrencies')->pluck('code')->map(fn (mixed $code): string => strtoupper((string) $code))->reject(fn (string $code): bool => in_array($code, $known, true));
            $report->problem(Preflight::INFO, $other->count(), 'Currencies not set up in Nuvabill: :count. Their clients and invoices keep them; add exchange rates in Settings.', examples: $other->values()->all());
        }

        if ($this->hasTable('tblaccounts')) {
            $known = array_merge(array_keys(self::GATEWAYS), app(ExtensionManager::class)->ofType(ExtensionManifest::TYPE_GATEWAY)->keys()->all());
            $gateways = $this->db()->table('tblaccounts')->distinct()->pluck('gateway')->map(fn (mixed $name): string => strtolower((string) $name))->filter()->reject(fn (string $name): bool => in_array($name, $known, true));
            $report->problem(Preflight::INFO, $gateways->count(), 'Payment methods not in Nuvabill: :count. Old payments keep their name.', examples: $gateways->values()->all());
        }

        $report->problem(Preflight::INFO, $this->db()->table('tblclients')->pluck('credit')->filter(fn (mixed $credit): bool => (float) $credit > 0)->count(), 'Clients with credit: :count. It becomes their Nuvabill wallet balance.');
    }

    private function preflightSupport(Preflight $report): void
    {
        if ($this->hasTable('tbltickets')) {
            $report->problem(Preflight::WARNING, $this->db()->table('tbltickets')->where('userid', 0)->count(), 'Tickets from guests without a client account: :count. They are skipped.');
            $report->problem(Preflight::INFO, $this->hasColumn('tbltickets', 'attachment') ? $this->db()->table('tbltickets')->where('attachment', '!=', '')->count() : 0, 'Tickets with attachments: :count. The messages are imported without the files.');
        }
    }

    /**
     * A value WHMCS encrypted with its own key, or null without the key or when it cannot be read.
     */
    private function decrypt(mixed $value): ?string
    {
        return $this->secret !== null && filled($value) ? (new WhmcsCrypt($this->secret))->decrypt((string) $value) : null;
    }

    /**
     * WHMCS 8 keeps passwords on users (tblusers); the owner of each client account signs in with it.
     *
     * @param  list<int|string>|null  $clientIds  Null for every client, without a long list of IDs in the query.
     * @return array<int, string>
     */
    private function ownerPasswords(?array $clientIds = null): array
    {
        if (! $this->hasTable('tblusers_clients') || ! $this->hasTable('tblusers')) {
            return [];
        }

        return $this->db()->table('tblusers_clients')
            ->join('tblusers', 'tblusers.id', '=', 'tblusers_clients.auth_user_id')
            ->when($clientIds !== null, fn ($query) => $query->whereIn('tblusers_clients.client_id', $clientIds))
            ->where('tblusers_clients.owner', 1)
            ->pluck('tblusers.password', 'tblusers_clients.client_id')
            ->all();
    }

    protected function sourceClients(array $ids): ?array
    {
        return $this->keyClients($this->db()->table('tblclients')->whereIn('id', $ids)->get(['id', 'email', 'datecreated as created']));
    }

    /**
     * Values of a product custom field (for example "vpsid") by WHMCS service ID.
     *
     * @param  list<int|string>  $serviceIds
     * @return array<int, string>
     */
    private function customFieldValues(string $fieldName, array $serviceIds): array
    {
        if (! $this->hasTable('tblcustomfields') || ! $this->hasTable('tblcustomfieldsvalues')) {
            return [];
        }

        return $this->db()->table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('f.type', 'product')
            ->where('f.fieldname', 'like', $fieldName.'%')
            ->whereIn('v.relid', $serviceIds)
            ->where('v.value', '!=', '')
            ->pluck('v.value', 'v.relid')
            ->all();
    }

    private function staffId(string $name): ?int
    {
        if ($this->staffByName === null) {
            $this->staffByName = [];

            if ($this->hasTable('tbladmins')) {
                foreach ($this->db()->table('tbladmins')->get(['id', 'firstname', 'lastname']) as $admin) {
                    $this->staffByName[mb_strtolower(trim($this->text($admin->firstname).' '.$this->text($admin->lastname)))] = $this->localId('admin', $admin->id);
                }
            }
        }

        return $this->staffByName[mb_strtolower(trim($name))] ?? null;
    }

    private function cycle(mixed $value): BillingCycle
    {
        return match (strtolower(str_replace(['-', ' '], '', (string) $value))) {
            'quarterly' => BillingCycle::Quarterly,
            'semiannually' => BillingCycle::SemiAnnually,
            'annually' => BillingCycle::Annually,
            'biennially' => BillingCycle::Biennially,
            'triennially' => BillingCycle::Triennially,
            'onetime' => BillingCycle::OneTime,
            'free', 'freeaccount' => BillingCycle::Free,
            default => BillingCycle::Monthly,
        };
    }

    /**
     * The currency code for a WHMCS currency ID. ID 0 means "the client's currency" in some tables.
     */
    private function currency(mixed $currencyId, ?string $fallback = null): string
    {
        if ($this->currencies === null) {
            $this->currencies = [];

            if ($this->hasTable('tblcurrencies')) {
                foreach ($this->db()->table('tblcurrencies')->orderBy('id')->get() as $currency) {
                    $this->currencies[(int) $currency->id] = strtoupper((string) $currency->code);

                    if ($currency->default ?? false) {
                        $this->defaultCurrency = strtoupper((string) $currency->code);
                    }
                }
            }
        }

        return $this->currencies[(int) $currencyId] ?? $fallback ?? $this->defaultCurrency ?? (string) setting('billing.currency');
    }
}
