<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Virtualizor 2.0 sells extra CPU, RAM, disk and IPv4 with resource options (sliders). Before, staff
 * named product add-ons for them in the product setting "resource_addons", as JSON such as
 * {"version":1,"ids":{"cpu":5,"ram":6,"disk":7,"ipv4":8}}. This moves each such product over:
 *
 * - one option per kind, with the add-ons' step, their prices in every currency and cycle, and as
 *   many steps as a client could buy before (each add-on once);
 * - one resource row per kind for each pending, active or suspended service that has those add-ons,
 *   with the same price, so every renewal total stays exactly the same (checked per service);
 * - the add-ons are hidden, the service add-ons are marked "converted", and the JSON is kept under
 *   "resource_addons_converted".
 *
 * Each product moves in its own transaction: a product that cannot move (JSON that is not valid, or
 * totals that would change) is left exactly as it was and logged as resource_options.convert_failed.
 * An option is shown to clients only when every add-on was shown, it has a price in the default
 * currency, and its prices were not worked out from a bigger add-on; otherwise staff check it first.
 *
 * Self-contained (query builder only, no extension classes). Safe to run again: a moved product no
 * longer has the JSON, and a failed product is logged once. Does nothing in pretend mode.
 */
return new class extends Migration
{
    private const FIELD = 'resource_addons';

    private const CONVERTED = 'resource_addons_converted';

    /**
     * The kinds the add-on JSON knows, in display order: the field its "base" uses, the unit, the
     * default size of one add-on, and the kind as Virtualizor 2.0 declares it.
     *
     * @var array<string, array{field: string, unit: string, step: int, max_extra: int, can_shrink: bool, restarts: bool, note: string|null}>
     */
    private const KINDS = [
        'cpu' => ['field' => 'cores', 'unit' => 'core', 'step' => 1, 'max_extra' => 64, 'can_shrink' => true, 'restarts' => true, 'note' => null],
        'ram' => ['field' => 'ram', 'unit' => 'MB', 'step' => 2048, 'max_extra' => 262144, 'can_shrink' => true, 'restarts' => true, 'note' => null],
        'disk' => ['field' => 'disk', 'unit' => 'GB', 'step' => 40, 'max_extra' => 16384, 'can_shrink' => false, 'restarts' => false, 'note' => 'Grow the partition inside your server to use the new space.'],
        'ipv4' => ['field' => 'num_ips', 'unit' => 'IP', 'step' => 1, 'max_extra' => 16, 'can_shrink' => true, 'restarts' => false, 'note' => 'New addresses may need a restart.'],
    ];

    private const SETTINGS = ['version', 'server_id', 'public_pool_id', 'plan_id', 'base', 'ids', 'steps', 'max'];

    private const BASE_FIELDS = ['cores', 'ram', 'disk', 'num_ips', 'bandwidth'];

    /**
     * Service statuses whose add-ons move. Ended services keep theirs as they are.
     */
    private const STATUSES = ['pending', 'active', 'suspended'];

    /**
     * The most steps a moved option offers, and the most a resource row can hold.
     */
    private const MAX_STEPS = 1000;

    private const MAX_ROW_STEPS = 65535;

    /**
     * Exception code of this migration's own refusals, whose message is safe for the staff log.
     */
    private const REFUSED = 4220;

    public function up(): void
    {
        if (DB::pretending() || ! $this->tablesExist()) {
            return;
        }

        $products = [];

        foreach (DB::table('products')->where('server_module', 'virtualizor')->whereNotNull('module_config')->orderBy('id')->get(['id', 'name', 'module_config']) as $product) {
            $config = json_decode((string) $product->module_config, true);
            $raw = is_array($config) ? ($config[self::FIELD] ?? null) : null;

            if (is_string($raw) && trim($raw) !== '') {
                $products[] = ['id' => (int) $product->id, 'name' => (string) $product->name, 'config' => $config, 'raw' => $raw];
            }
        }

        if ($products === []) {
            return;
        }

        $policies = [];

        foreach ($products as $product) {
            try {
                $policies[$product['id']] = $this->policy($product['raw']);
            } catch (RuntimeException $exception) {
                $this->failed($product, $exception->getMessage());
            }
        }

        // Read before any product hides them: several products may share the same add-ons.
        $ids = array_unique(array_merge(...array_map(fn (array $policy): array => array_keys($policy['ids']), array_values($policies)) ?: [[]]));
        $visible = DB::table('product_addons')->whereIn('id', $ids)->pluck('is_visible', 'id')->map(fn (mixed $shown): bool => (bool) $shown)->all();
        $currency = $this->defaultCurrency();

        foreach ($products as $product) {
            $policy = $policies[$product['id']] ?? null;

            if ($policy === null) {
                continue;
            }

            try {
                [$addons, $services] = DB::transaction(fn (): array => $this->convert($product, $policy, $visible, $currency));
            } catch (Throwable $exception) {
                $this->failed($product, $exception->getCode() === self::REFUSED ? $exception->getMessage() : 'the database refused a change ('.class_basename($exception).')');

                continue;
            }

            $this->log($product['id'], 'resource_options.converted', "Product {$this->name($product)}: {$addons} add-ons became sliders for {$services} services. Check the prices and raise the maximum.");
        }
    }

    public function down(): void
    {
        // Nothing to undo: the moved options, rows and prices are now the services' billing.
    }

    /**
     * Move one product. Runs in a transaction; any throw rolls the whole product back.
     *
     * @param  array{id: int, name: string, config: array<string, mixed>, raw: string}  $product
     * @param  array{ids: array<int, array{0: string, 1: int}>, base: array<string, int>, public_pool_id: int|null}  $policy
     * @param  array<int|string, bool>  $visible
     * @return array{0: int, 1: int} The number of add-ons moved and of services with rows.
     */
    private function convert(array $product, array $policy, array $visible, string $currency): array
    {
        $now = now();
        $options = [];
        // New options go after any the product has, in kind order.
        $next = DB::table('resource_options')->where('product_id', $product['id'])->exists()
            ? (int) DB::table('resource_options')->where('product_id', $product['id'])->max('sort_order') + 1
            : 0;

        foreach (self::KINDS as $kind => $info) {
            $entries = array_values(array_filter(
                array_map(fn (int $id, array $entry): array => ['id' => $id, 'amount' => $entry[1], 'kind' => $entry[0]], array_keys($policy['ids']), $policy['ids']),
                fn (array $entry): bool => $entry['kind'] === $kind,
            ));

            if ($entries === []) {
                continue;
            }

            $existing = DB::table('resource_options')->where('product_id', $product['id'])->where('kind', $kind)->first(['id', 'step_size']);

            if ($existing !== null) {
                $options[$kind] = ['id' => (int) $existing->id, 'step' => (int) $existing->step_size, 'unit' => $info['unit']];

                continue;
            }

            usort($entries, fn (array $a, array $b): int => [$a['amount'], $a['id']] <=> [$b['amount'], $b['id']]);
            $step = array_reduce($entries, fn (int $gcd, array $entry): int => $this->gcd($gcd, $entry['amount']), 0);
            // The add-on of exactly one step sets the price; otherwise the smallest, scaled down.
            $source = collect($entries)->first(fn (array $entry): bool => $entry['amount'] === $step) ?? $entries[0];
            $scaled = $source['amount'] !== $step;
            $prices = DB::table('product_addon_prices')->where('product_addon_id', $source['id'])->orderBy('id')->get(['currency', 'billing_cycle', 'price', 'setup_fee']);
            $shown = collect($entries)->every(fn (array $entry): bool => $visible[$entry['id']] ?? false);

            $optionId = DB::table('resource_options')->insertGetId([
                'product_id' => $product['id'],
                'kind' => $kind,
                'unit' => $info['unit'],
                'label' => null,
                'description' => null,
                'step_size' => $step,
                'included' => $policy['base'][$info['field']] ?? null,
                'min_steps' => 0,
                'max_steps' => max(1, min(self::MAX_STEPS, intdiv(array_sum(array_column($entries, 'amount')), $step))),
                'default_steps' => 0,
                'stock' => null,
                'client_can_add' => true,
                'client_can_remove' => $info['can_shrink'],
                'is_active' => $shown && ! $scaled && $prices->contains('currency', $currency),
                'sort_order' => $next++,
                'can_shrink' => $info['can_shrink'],
                'restarts' => $info['restarts'],
                'note' => $info['note'] === null ? null : __($info['note']),
                'ladder' => null,
                'max_extra' => $info['max_extra'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($prices as $price) {
                DB::table('resource_option_prices')->insert([
                    'resource_option_id' => $optionId,
                    'currency' => $price->currency,
                    'billing_cycle' => $price->billing_cycle,
                    'price' => $this->scale((int) $price->price, $step, $source['amount']),
                    'setup_fee' => $this->scale((int) $price->setup_fee, $step, $source['amount']),
                ]);
            }

            $options[$kind] = ['id' => $optionId, 'step' => $step, 'unit' => $info['unit']];
        }

        $services = $this->moveServices($product['id'], $policy, $options, $now);
        $ids = array_keys($policy['ids']);

        DB::table('product_addons')->whereIn('id', $ids)->update(['is_visible' => false, 'updated_at' => $now]);

        $config = $product['config'];
        $config[self::CONVERTED] = $config[self::FIELD];
        unset($config[self::FIELD]);

        if ($policy['public_pool_id'] !== null && trim((string) ($config['ipv4_pool_id'] ?? '')) === '') {
            $config['ipv4_pool_id'] = (string) $policy['public_pool_id'];
        }

        DB::table('products')->where('id', $product['id'])->update(['module_config' => json_encode($config), 'updated_at' => $now]);

        return [count($ids), $services];
    }

    /**
     * Give each pending, active or suspended service of the product resource rows for the add-ons
     * the policy names, and mark those add-ons converted. Throws when a service's renewal total or
     * extras would not stay exactly the same.
     *
     * @param  array{ids: array<int, array{0: string, 1: int}>}  $policy
     * @param  array<string, array{id: int, step: int, unit: string}>  $options
     * @return int The number of services that got rows.
     */
    private function moveServices(int $productId, array $policy, array $options, DateTimeInterface $now): int
    {
        $moved = 0;
        $services = DB::table('services')->where('product_id', $productId)->whereIn('status', self::STATUSES)->orderBy('id')->pluck('id');

        foreach ($services as $serviceId) {
            $serviceId = (int) $serviceId;
            $addons = DB::table('service_addons')
                ->where('service_id', $serviceId)
                ->where('status', 'active')
                ->whereIn('product_addon_id', array_keys($policy['ids']))
                ->orderBy('id')
                ->get(['id', 'product_addon_id', 'recurring_amount']);

            if ($addons->isEmpty()) {
                continue;
            }

            $before = $this->renewalTotal($serviceId);
            $units = $this->extraUnits($serviceId);
            $groups = [];

            foreach ($addons as $addon) {
                [$kind, $amount] = $policy['ids'][(int) $addon->product_addon_id];
                $groups[$kind]['units'] = ($groups[$kind]['units'] ?? 0) + $amount;
                $groups[$kind]['amount'] = ($groups[$kind]['amount'] ?? 0) + (int) $addon->recurring_amount;
                $units[$kind] = ($units[$kind] ?? 0) + $amount;
            }

            foreach ($groups as $kind => $group) {
                $option = $options[$kind];

                // A row that is there already (made by hand) is not doubled: the check below refuses.
                if (DB::table('service_resource_options')->where('service_id', $serviceId)->where('kind', $kind)->exists()) {
                    continue;
                }

                $steps = intdiv($group['units'], $option['step']);

                if ($group['units'] % $option['step'] !== 0 || $steps > self::MAX_ROW_STEPS) {
                    throw new RuntimeException("the {$kind} add-ons of service #{$serviceId} do not fit the option's step", self::REFUSED);
                }

                DB::table('service_resource_options')->insert([
                    'service_id' => $serviceId,
                    'resource_option_id' => $option['id'],
                    'kind' => $kind,
                    'unit' => $option['unit'],
                    'step_size' => $option['step'],
                    'steps' => $steps,
                    'recurring_amount' => $group['amount'],
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('service_addons')->whereIn('id', $addons->pluck('id'))->update(['status' => 'converted', 'cancelled_at' => $now, 'updated_at' => $now]);

            if ($this->renewalTotal($serviceId) !== $before || $this->extraUnits($serviceId) != array_filter($units)) {
                throw new RuntimeException("totals differ for service #{$serviceId}", self::REFUSED);
            }

            $moved++;
        }

        return $moved;
    }

    /**
     * What the service's renewal costs: its plan, its active add-ons and its active extras.
     */
    private function renewalTotal(int $serviceId): int
    {
        return (int) DB::table('services')->where('id', $serviceId)->value('recurring_amount')
            + (int) DB::table('service_addons')->where('service_id', $serviceId)->where('status', 'active')->sum('recurring_amount')
            + (int) DB::table('service_resource_options')->where('service_id', $serviceId)->where('status', 'active')->where('steps', '>', 0)->sum('recurring_amount');
    }

    /**
     * The service's active extras, in units per kind.
     *
     * @return array<string, int>
     */
    private function extraUnits(int $serviceId): array
    {
        $units = [];

        foreach (DB::table('service_resource_options')->where('service_id', $serviceId)->where('status', 'active')->where('steps', '>', 0)->get(['kind', 'steps', 'step_size']) as $row) {
            $units[(string) $row->kind] = ($units[(string) $row->kind] ?? 0) + (int) $row->steps * (int) $row->step_size;
        }

        ksort($units);

        return $units;
    }

    /**
     * The add-on JSON, checked with the same rules Virtualizor 1.x used.
     *
     * @return array{ids: array<int, array{0: string, 1: int}>, base: array<string, int>, public_pool_id: int|null}
     *
     * @throws RuntimeException With the reason, for the staff log.
     */
    private function policy(string $raw): array
    {
        $policy = json_decode($raw, true);

        $this->ensure(is_array($policy) && ! array_is_list($policy) && ($policy['version'] ?? null) === 1, 'it must be a JSON object with "version":1');
        $this->ensure(array_diff(array_keys($policy), self::SETTINGS) === [], 'it has a setting this version does not know');

        $steps = array_map(fn (array $info): int => $info['step'], self::KINDS);

        foreach ($this->object($policy['steps'] ?? [], 'steps') as $kind => $amount) {
            $this->ensure(array_key_exists($kind, self::KINDS), '"steps" only takes cpu, ram, disk and ipv4');
            $steps[$kind] = $this->number($amount, 1, 'steps');
        }

        $ids = [];
        $kinds = $this->object($policy['ids'] ?? null, 'ids');
        $this->ensure($kinds !== [], '"ids" needs at least one add-on');

        foreach ($kinds as $kind => $value) {
            $this->ensure(array_key_exists($kind, self::KINDS), '"ids" only takes cpu, ram, disk and ipv4');

            $entries = match (true) {
                is_array($value) && array_is_list($value) => array_map(fn (mixed $id): array => [$id, $steps[$kind]], $value),
                is_array($value) => array_map(fn (mixed $id, mixed $amount): array => [$id, $amount], array_keys($value), $value),
                default => [[$value, $steps[$kind]]],
            };

            $this->ensure($entries !== [], '"ids" needs at least one add-on');

            foreach ($entries as [$id, $amount]) {
                $id = $this->number($id, 1, 'ids');
                $this->ensure(! array_key_exists($id, $ids), 'an add-on ID is listed twice');
                $ids[$id] = [(string) $kind, $this->number($amount, 1, 'ids')];
            }
        }

        $base = [];

        foreach ($this->object($policy['base'] ?? [], 'base') as $field => $value) {
            $this->ensure(in_array($field, self::BASE_FIELDS, true), '"base" only takes cores, ram, disk, num_ips and bandwidth');
            $base[(string) $field] = $this->number($value, $field === 'bandwidth' ? 0 : 1, 'base');
        }

        foreach ($this->object($policy['max'] ?? [], 'max') as $field => $value) {
            $this->ensure(in_array($field, self::BASE_FIELDS, true), '"max" only takes cores, ram, disk, num_ips and bandwidth');
            $this->number($value, 1, 'max');
        }

        foreach (['server_id', 'plan_id'] as $key) {
            if (isset($policy[$key])) {
                $this->number($policy[$key], 1, $key);
            }
        }

        return [
            'ids' => $ids,
            'base' => $base,
            'public_pool_id' => isset($policy['public_pool_id']) ? $this->number($policy['public_pool_id'], 1, 'public_pool_id') : null,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function object(mixed $value, string $name): array
    {
        $this->ensure(is_array($value) && ($value === [] || ! array_is_list($value)), "\"{$name}\" must be a JSON object");

        return $value;
    }

    private function number(mixed $value, int $min, string $name): int
    {
        $number = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => 2147483647]]) : false;

        $this->ensure($number !== false, "\"{$name}\" needs whole numbers of {$min} or more");

        return $number;
    }

    private function ensure(bool $condition, string $problem): void
    {
        if (! $condition) {
            throw new RuntimeException('the add-on JSON is not valid: '.$problem, self::REFUSED);
        }
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }

    /**
     * An add-on price for one step of a smaller size, rounded half up. Amounts are minor units.
     */
    private function scale(int $price, int $step, int $amount): int
    {
        if ($step === $amount || $price === 0) {
            return $price;
        }

        $sign = $price < 0 ? -1 : 1;

        return $sign * intdiv(abs($price) * $step * 2 + $amount, $amount * 2);
    }

    /**
     * The shop's default currency, from the saved setting (a JSON value), else USD.
     */
    private function defaultCurrency(): string
    {
        $currency = json_decode((string) DB::table('settings')->where('key', 'billing.currency')->value('value'), true);

        return is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'USD';
    }

    /**
     * Log that the product was not moved, once for each reason.
     *
     * @param  array{id: int, name: string}  $product
     */
    private function failed(array $product, string $reason): void
    {
        $this->log($product['id'], 'resource_options.convert_failed', "Product {$this->name($product)}: its resource add-ons were not moved to sliders, so nothing was changed for it: {$reason}. Set up its resource options by hand.", once: true);
    }

    /**
     * @param  array{name: string}  $product
     */
    private function name(array $product): string
    {
        return mb_strlen($product['name']) > 60 ? mb_substr($product['name'], 0, 59).'…' : $product['name'];
    }

    private function log(int $productId, string $action, string $description, bool $once = false): void
    {
        $description = mb_strlen($description) > 255 ? mb_substr($description, 0, 254).'…' : $description;
        $entry = ['subject_type' => 'product', 'subject_id' => $productId, 'action' => $action, 'description' => $description];

        if ($once && DB::table('activity_logs')->where($entry)->exists()) {
            return;
        }

        DB::table('activity_logs')->insert($entry + ['actor_type' => null, 'actor_id' => null, 'client_id' => null, 'ip_address' => null, 'created_at' => now()]);
    }

    private function tablesExist(): bool
    {
        foreach (['products', 'product_addons', 'product_addon_prices', 'services', 'service_addons', 'resource_options', 'resource_option_prices', 'service_resource_options', 'activity_logs', 'settings'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
};
