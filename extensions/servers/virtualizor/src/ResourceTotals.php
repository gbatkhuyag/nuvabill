<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Resources\ResourceCheck;
use App\Extensions\Servers\Resources\ResourceExtras;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Extensions\Servers\Resources\ResourceLimit;
use App\Models\Product;
use App\Models\ResourceOption;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceAddon;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Extra VPS resources sold with resource options (sliders): what a VPS gets on top of its plan, set
 * when it is made, after a plan change and when the client changes their extras. Totals are the
 * plan's base plus the extras core gives the service ($service->resourceExtras()). A made VPS is
 * checked against them, and disk space only grows.
 *
 * The base of each kind is the product setting when staff set one (as the create request sends
 * it), else the Virtualizor plan (kept 10 minutes per server and plan), else the "included" amount
 * staff gave the option. Traffic and network speed of 0 mean no limit: extras change nothing there.
 */
final class ResourceTotals
{
    /**
     * Where a resource is in Virtualizor's VPS details, by the request field that sets it.
     */
    public const FIELDS = [
        'cores' => 'cores',
        'ram' => 'ram',
        'disk' => 'space',
        'num_ips' => 'ips',
        'bandwidth' => 'bandwidth',
        'num_ips6' => 'ips6',
        'num_ips6_subnet' => 'ips6_subnet',
        'network_speed' => 'network_speed',
    ];

    /**
     * Module data key: the totals last set on the VPS and checked, with the time.
     */
    public const DATA = 'virtualizor_resources';

    /**
     * The kinds sold: the request field that sets each one, its name in a Virtualizor plan, its
     * unit, the most extra units an option may add, and the most the VPS may have in total.
     *
     * @var array<string, array{field: string, plan: string, unit: string, max_extra: int, max_total: int}>
     */
    private const KINDS = [
        'cpu' => ['field' => 'cores', 'plan' => 'cores', 'unit' => 'core', 'max_extra' => 64, 'max_total' => 64],
        'ram' => ['field' => 'ram', 'plan' => 'ram', 'unit' => 'MB', 'max_extra' => 262144, 'max_total' => 262144],
        'disk' => ['field' => 'disk', 'plan' => 'space', 'unit' => 'GB', 'max_extra' => 16384, 'max_total' => 16384],
        'ipv4' => ['field' => 'num_ips', 'plan' => 'ips', 'unit' => 'IP', 'max_extra' => 16, 'max_total' => 16],
        'ipv6' => ['field' => 'num_ips6', 'plan' => 'ips6', 'unit' => 'IP', 'max_extra' => 64, 'max_total' => 256],
        'bandwidth' => ['field' => 'bandwidth', 'plan' => 'bandwidth', 'unit' => 'GB', 'max_extra' => 10000000, 'max_total' => 10000000],
        'virtualizor_ipv6_subnet' => ['field' => 'num_ips6_subnet', 'plan' => 'ips6_subnet', 'unit' => '', 'max_extra' => 8, 'max_total' => 16],
        'virtualizor_network_speed' => ['field' => 'network_speed', 'plan' => 'network_speed', 'unit' => 'KB/s', 'max_extra' => 10000000, 'max_total' => 10000000],
    ];

    /**
     * Kinds where a base of 0 means no limit.
     */
    private const UNLIMITED_AT_ZERO = ['bandwidth', 'virtualizor_network_speed'];

    /**
     * How long a Virtualizor plan is kept, in seconds.
     */
    private const PLAN_SECONDS = 600;

    public function __construct(private AdminApi $admin) {}

    /**
     * The resource kinds the product can sell. Local only: it never calls the server. Traffic and
     * network speed are left out when the product sets them to 0 (no limit).
     *
     * @return array<string, ResourceKind>
     *
     * @throws InvalidArgumentException With a message for staff when the product's settings are wrong.
     */
    public function kinds(Product $product): array
    {
        $config = (array) ($product->module_config ?? []);

        try {
            self::poolId($config);
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException($exception->getMessage());
        }

        $kinds = [];

        foreach (self::KINDS as $key => $kind) {
            if (in_array($key, self::UNLIMITED_AT_ZERO, true) && self::productBase($config, $kind['field']) === 0) {
                continue;
            }

            $kinds[$key] = new ResourceKind(
                key: $key,
                label: $this->label($key),
                unit: $kind['unit'],
                minStep: $key === 'ram' ? 128 : 1,
                maxExtra: $kind['max_extra'],
                canShrink: $key !== 'disk',
                restarts: in_array($key, ['cpu', 'ram'], true),
                note: match ($key) {
                    'disk' => __('Grow the partition inside your server to use the new space.'),
                    'ipv4' => __('New addresses may need a restart.'),
                    default => null,
                },
            );
        }

        return $kinds;
    }

    /**
     * What the product's plan gives without extras, in units per kind (null where it is not known).
     * May read the plan from the server.
     *
     * @return array<string, int|null>
     */
    public function base(Product $product, ?Server $server): array
    {
        return $this->baseOf($product, $server, array_keys(self::KINDS));
    }

    /**
     * Live limits for one service, in extra units per kind: no kind may go above what Virtualizor
     * allows in total, extras on a plan without a limit are not sold, and disk space cannot go
     * below what the VPS has now. A kind whose plan amount is not known cannot grow, because the
     * server step would refuse it after payment; its extras can still be removed. May call the
     * server. A service without a VPS has no limits.
     *
     * @return array<string, ResourceLimit>
     */
    public function limits(Service $service): array
    {
        $vpsId = (string) (((array) $service->module_data)['vpsid'] ?? '');
        $units = self::known($service->resourceExtras());
        $kinds = array_keys($units);

        if (! ctype_digit($vpsId) || $kinds === []) {
            return [];
        }

        $base = $this->serviceBase($service, $kinds);
        $limits = [];

        foreach ($kinds as $kind) {
            if ($base[$kind] === null) {
                $limits[$kind] = new ResourceLimit(maxUnits: max(0, $units[$kind]), reason: __('We cannot add more of this right now.'));

                continue;
            }

            $limits[$kind] = $this->unlimited($kind, $base[$kind])
                ? new ResourceLimit(maxUnits: 0, reason: __('Your plan has no limit here, so extras add nothing.'))
                : new ResourceLimit(maxUnits: max(0, self::KINDS[$kind]['max_total'] - $base[$kind]));
        }

        if (($base['disk'] ?? null) !== null) {
            $live = $this->resourceOf($this->admin->vpsInfo($service->server, $vpsId), 'disk');

            if ($live !== null) {
                $limits['disk'] = new ResourceLimit(
                    minUnits: max(0, (int) $live - $base['disk']),
                    maxUnits: $limits['disk']->maxUnits ?? null,
                    reason: __('Disk space can only grow.'),
                );
            }
        }

        return $limits;
    }

    /**
     * Set the live VPS to its plan's base plus these extras, then check it. Only kinds Nuvabill
     * raised are set (see raised()), and only the fields that differ are sent, without the plan, so
     * running it again changes nothing. Fewer IPv4 or IPv6 addresses keep the first ones. Disk space
     * only grows: a bigger disk is left as it is, and a disk that cannot grow here (more than one
     * disk, or no storage ID) is left for staff.
     */
    public function apply(Service $service, ResourceExtras $extras): ModuleResult
    {
        try {
            $this->guardLegacy($service);
            $server = $service->server;
            $vpsId = AdminApi::vpsId($service);
            [$totals, $refusals] = $this->totalsFor($service, self::raised($service, $extras));

            if ($refusals !== []) {
                return ModuleResult::fail($refusals[0]);
            }

            if ($totals === []) {
                return ModuleResult::ok(__('The server already has these resources.'));
            }

            $pool = isset($totals['num_ips']) ? self::poolId((array) ($service->product?->module_config ?? [])) : null;
            $before = $this->admin->vpsInfo($server, $vpsId);

            if ($before === []) {
                return ModuleResult::fail(__('Virtualizor has no VPS #:id.', ['id' => $vpsId]));
            }

            if ($pool !== null) {
                $this->noteAddresses($service, $before);
            }

            [$post, $unset] = $this->edits($totals, $before, $before, $this->growsDisk($totals, $before) ? $this->disks($server, $vpsId) : []);

            if ($post !== []) {
                $response = $this->admin->call($server, ['act' => 'managevps', 'vpsid' => $vpsId], ['editvps' => 1] + $post);

                if (empty($response['done']) && empty($response['vs_info'])) {
                    return ModuleResult::fail(__('Virtualizor did not confirm the action.'));
                }
            }

            $after = $post === [] ? $before : $this->admin->vpsInfo($server, $vpsId);
            $problems = $this->compare($totals, $after, diskMayBeBigger: true, skip: $unset)['problems'];

            if ($pool !== null) {
                $problems = [...$problems, ...$this->poolProblems($service, $after, $vpsId, $pool)];
            }

            if ($problems !== []) {
                return ModuleResult::fail(__('The server does not match yet: :problems', ['problems' => implode('; ', $problems)]));
            }

            $this->remember($service, array_diff_key($totals, array_flip($unset)), $after);
            $restart = isset($post['cores']) || isset($post['ram']);

            return match (true) {
                $unset !== [] => ModuleResult::ok(__('Saved, but the disk did not grow: this VPS has more than one disk, or its disk has no storage ID. Grow it to :size in Virtualizor.', [
                    'size' => ResourceOption::formatUnits('GB', $totals['disk']),
                ]), ['manual' => true, 'restart' => $restart]),
                $restart => ModuleResult::ok(__('Saved. CPU and memory change after the next restart.'), ['restart' => true]),
                $post !== [] => ModuleResult::ok(__('Saved. The VPS has its new resources.')),
                default => ModuleResult::ok(__('The server already has these resources.')),
            };
        } catch (RuntimeException $exception) {
            return ModuleResult::fail($exception->getMessage());
        }
    }

    /**
     * Compare the live VPS with its plan's base plus these extras, for the kinds Nuvabill raised
     * (see raised()). Changes nothing. Traffic and network speed without a limit are expected to
     * stay 0.
     *
     * @throws \Throwable When the server does not answer.
     */
    public function check(Service $service, ResourceExtras $extras): ResourceCheck
    {
        $vpsId = AdminApi::vpsId($service);
        [$totals, $problems] = $this->totalsFor($service, self::raised($service, $extras), unlimitedAsZero: true);
        $info = $this->admin->vpsInfo($service->server, $vpsId);

        if ($info === []) {
            $expected = [];

            foreach ($totals as $field => $total) {
                $expected[self::kindOf($field)] = $total;
            }

            return new ResourceCheck($expected, array_fill_keys(array_keys($expected), null), [...$problems, __('Virtualizor has no VPS #:id.', ['id' => $vpsId])]);
        }

        $compared = $this->compare($totals, $info, diskMayBeBigger: false);

        return new ResourceCheck($compared['expected'], $compared['live'], [...$problems, ...$compared['problems']]);
    }

    /**
     * The totals a new VPS gets with its extras, or null when it has none (it is made exactly as
     * before). Whatever else the array holds besides "totals" goes into the create note: the extras
     * they came from, and the IP pool every IPv4 address must come from when extra IPv4 is sold
     * with one. Throws when the extras cannot be worked out safely; nothing is sent then. Core makes
     * the VPS only once its extras are paid.
     *
     * @return array{totals: array<string, int>, extras: array<string, int>, pool: int|null}|null
     *
     * @throws RuntimeException With a message for staff.
     */
    public function totalsForCreate(Service $service): ?array
    {
        $this->guardLegacy($service);
        $extras = array_filter(self::known($service->resourceExtras()), fn (int $units): bool => $units > 0);

        if ($extras === []) {
            return null;
        }

        [$totals, $refusals] = $this->totalsFor($service, $extras);

        if ($refusals !== []) {
            throw new RuntimeException($refusals[0]);
        }

        if ($totals === []) {
            return null;
        }

        $pool = isset($totals['num_ips']) ? self::poolId((array) ($service->product?->module_config ?? [])) : null;

        if ($pool !== null) {
            $this->checkPlan($service, $pool);
        }

        return ['totals' => $totals, 'extras' => $extras, 'pool' => $pool];
    }

    /**
     * The totals to set again on top of the product's new plan, or null when the VPS has no extras
     * (the plan is applied as before). Throws when the plan must not be applied.
     *
     * @return array<string, int>|null
     *
     * @throws RuntimeException With a message for staff.
     */
    public function totalsForPlanChange(Service $service): ?array
    {
        $this->guardLegacy($service);
        $extras = array_filter(self::known($service->resourceExtras()), fn (int $units): bool => $units > 0);

        if ($extras === []) {
            return null;
        }

        [$totals, $refusals] = $this->totalsFor($service, $extras);

        if ($refusals !== []) {
            throw new RuntimeException($refusals[0]);
        }

        return $totals === [] ? null : $totals;
    }

    /**
     * The create request fields that set these totals.
     *
     * @param  array<string, int>  $totals
     * @return array<string, mixed>
     */
    public function createFields(array $totals): array
    {
        $fields = [];

        foreach ($totals as $field => $total) {
            $fields[$field === 'disk' ? 'space' : $field] = $field === 'disk' ? [['size' => $total]] : $total;
        }

        return $fields;
    }

    /**
     * What on a made VPS differs from its create note, as names for staff: name, virtualization,
     * resources and the IP pool. Empty when it matches.
     *
     * @param  array<string, mixed>  $record  The create note (ResourceAddons::record()).
     * @param  array<string, mixed>  $info  The VPS from Virtualizor's list.
     * @return list<string>
     *
     * @throws RuntimeException When Virtualizor does not answer the pool check.
     */
    public function problems(Service $service, array $record, array $info): array
    {
        $problems = $this->mismatches($service, $record, $info);

        if ($problems === [] && ($record['pool'] ?? null) !== null) {
            foreach (AdminApi::addresses($info, FILTER_FLAG_IPV4) as $ip) {
                if (! $this->inPool($service->server, $ip, (string) $record['vpsid'], (int) $record['pool'])) {
                    $problems[] = __('IP pool');
                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * Apply the new plan, then set the extras on top again and check the result. A disk the new
     * plan left bigger than its total is fine: disk space only grows.
     *
     * @param  array<string, int>  $totals
     */
    public function changePlan(Service $service, int $planId, array $totals): ModuleResult
    {
        $server = $service->server;
        $vpsId = AdminApi::vpsId($service);
        $before = $this->admin->vpsInfo($server, $vpsId);
        $response = $this->admin->call($server, ['act' => 'managevps', 'vpsid' => $vpsId], ['editvps' => 1, 'plid' => $planId, 'apply_plan' => 1]);

        if (empty($response['done']) && empty($response['vs_info'])) {
            return ModuleResult::fail(__('Virtualizor did not confirm the action.'));
        }

        try {
            $after = $this->admin->vpsInfo($server, $vpsId);
            $disks = (array) ($response['vs_info']['disks'] ?? []);

            if ($disks === [] && $this->growsDisk($totals, $after)) {
                $disks = $this->disks($server, $vpsId);
            }

            [$post, $unset] = $this->edits($totals, $before, $after, $disks);

            if ($post !== []) {
                $this->admin->call($server, ['act' => 'managevps', 'vpsid' => $vpsId], ['editvps' => 1] + $post);
                $after = $this->admin->vpsInfo($server, $vpsId);
            }

            $problems = array_values(array_unique([
                ...array_map(fn (string $field): string => $this->label(self::kindOf($field)), $unset),
                ...$this->mismatches($service, ['totals' => $totals], $after, diskMayBeBigger: true),
            ]));
        } catch (RuntimeException) {
            $problems = [__('no clear answer from Virtualizor')];
        }

        if ($problems === []) {
            $this->remember($service, $totals, $after);

            return ModuleResult::ok(__('Plan applied to the VPS, with its extras.'));
        }

        return ModuleResult::fail(__('The new plan is applied, but its extras could not all be set again (:problems). Set them in Virtualizor by hand: :totals.', [
            'problems' => implode(', ', $problems),
            'totals' => collect($totals)->map(fn (int $total, string $field): string => $this->label(self::kindOf($field)).' '.$total)->implode(', '),
        ]));
    }

    /**
     * The totals for these extras, by request field: the plan's base plus the extras, and why kinds
     * cannot be set (a base that is not known while the kind has extras, or a total above what
     * Virtualizor allows). A kind without a known base and without extras is left alone, and so is
     * a kind without a limit (traffic or network speed of 0), unless $unlimitedAsZero asks for 0.
     *
     * @param  array<string, int>  $extras  kind => extra units, only kinds of this module.
     * @return array{0: array<string, int>, 1: list<string>}
     *
     * @throws RuntimeException When the Virtualizor plan cannot be read.
     */
    private function totalsFor(Service $service, array $extras, bool $unlimitedAsZero = false): array
    {
        if ($extras === []) {
            return [[], []];
        }

        $base = $this->serviceBase($service, array_keys($extras));
        $totals = [];
        $refusals = [];

        foreach ($extras as $kind => $units) {
            $kindInfo = self::KINDS[$kind];

            if ($base[$kind] !== null && $this->unlimited($kind, $base[$kind])) {
                if ($unlimitedAsZero) {
                    $totals[$kindInfo['field']] = 0;
                }

                continue;
            }

            if ($base[$kind] === null) {
                if ($units > 0) {
                    $refusals[] = __(':resource: the plan amount of this product is not known, so its extras cannot be added. Set it on the product or in its Virtualizor plan. Nothing was changed.', ['resource' => $this->label($kind)]);
                }

                continue;
            }

            $total = $base[$kind] + max(0, $units);

            if ($total > $kindInfo['max_total']) {
                $refusals[] = __(':resource: with its extras this VPS would get more than Virtualizor allows (:max). Nothing was changed.', [
                    'resource' => $this->label($kind),
                    'max' => ResourceOption::formatUnits($kindInfo['unit'], $kindInfo['max_total']),
                ]);

                continue;
            }

            $totals[$kindInfo['field']] = $total;
        }

        return [$totals, $refusals];
    }

    /**
     * The base of these kinds for a service: as baseOf(), with the "included" amount staff gave the
     * product's option where neither the product nor the plan sets it.
     *
     * @param  list<string>  $kinds
     * @return array<string, int|null>
     */
    private function serviceBase(Service $service, array $kinds): array
    {
        $base = $this->baseOf($service->product, $service->server, $kinds);
        $missing = array_keys(array_filter($base, fn (?int $units): bool => $units === null));

        if ($missing !== []) {
            $included = ResourceOption::query()->where('product_id', $service->product_id)->whereIn('kind', $missing)->whereNotNull('included')->pluck('included', 'kind');

            foreach ($included as $kind => $units) {
                $base[(string) $kind] = (int) $units;
            }
        }

        return $base;
    }

    /**
     * The base of these kinds: the product setting the create request sends, else the Virtualizor
     * plan when the product names one and a server is known, else null.
     *
     * @param  list<string>  $kinds
     * @return array<string, int|null>
     */
    private function baseOf(?Product $product, ?Server $server, array $kinds): array
    {
        $config = (array) ($product?->module_config ?? []);
        $planId = self::whole($config['plan_id'] ?? null, 1) ?? 0;
        $plan = null;
        $base = [];

        foreach ($kinds as $kind) {
            $field = self::KINDS[$kind]['field'];
            $units = self::productBase($config, $field);

            if ($units === null && $planId > 0 && $server !== null) {
                $plan ??= $this->plan($server, $planId);
                $units = self::whole($plan[self::KINDS[$kind]['plan']] ?? null, in_array($field, ['cores', 'ram', 'disk'], true) ? 1 : 0);
            }

            $base[$kind] = $units;
        }

        return $base;
    }

    /**
     * A product setting as the create request sends it: IPv4 addresses always (1 when empty), CPU,
     * RAM and disk only above 0, the others when set (0 included). Null when the plan decides.
     *
     * @param  array<string, mixed>  $config
     */
    private static function productBase(array $config, string $field): ?int
    {
        return match ($field) {
            'num_ips' => self::whole($config['num_ips'] ?? null, 1) ?? 1,
            'cores', 'ram', 'disk' => self::whole($config[$field] ?? null, 1),
            default => self::whole($config[$field] ?? null, 0),
        };
    }

    /**
     * Whether extras of this kind change nothing, as its base has no limit.
     */
    private function unlimited(string $kind, int $base): bool
    {
        return $base === 0 && in_array($kind, self::UNLIMITED_AT_ZERO, true);
    }

    /**
     * Refuse while the product still has add-on JSON from before 2.0 that names an active add-on
     * of this service: the migration could not move it to resource options, so the extras the
     * client pays for are not known here. JSON that cannot be read stops any service with add-ons.
     *
     * @throws RuntimeException
     */
    private function guardLegacy(Service $service): void
    {
        $raw = ((array) ($service->product?->module_config ?? []))[ResourceAddons::FIELD] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return;
        }

        $active = $service->addons()->where('status', ServiceAddon::STATUS_ACTIVE)->whereNotNull('product_addon_id')->pluck('product_addon_id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($active === []) {
            return;
        }

        $named = self::legacyAddonIds($raw);

        if ($named === null || array_intersect($named, $active) !== []) {
            throw new RuntimeException(__('This product still has resource add-on JSON that was not moved to sliders. Set up its resource options first; nothing was changed.'));
        }
    }

    /**
     * The add-on IDs the add-on JSON names, or null when it cannot be read.
     *
     * @return list<int>|null
     */
    private static function legacyAddonIds(string $raw): ?array
    {
        $policy = json_decode($raw, true);

        if (! is_array($policy) || ! is_array($policy['ids'] ?? null)) {
            return null;
        }

        $ids = [];

        foreach ($policy['ids'] as $value) {
            $listed = match (true) {
                is_array($value) && array_is_list($value) => $value,
                is_array($value) => array_keys($value),
                default => [$value],
            };

            foreach ($listed as $id) {
                if ((! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id)) {
                    return null;
                }

                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * The IP pool extra IPv4 addresses must come from, or null when the product sets none.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws RuntimeException When the setting is not a pool ID.
     */
    private static function poolId(array $config): ?int
    {
        $value = $config['ipv4_pool_id'] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return self::whole(is_string($value) ? trim($value) : $value, 1)
            ?? throw new RuntimeException(__('Set ":field" on the product to a whole number of :min or more, or leave it empty.', ['field' => 'IP pool for extra IPv4', 'min' => 1]));
    }

    /**
     * Before a VPS with extra IPv4 from a pool is made: the plan must be for the product's
     * virtualization type and use exactly that pool, or the new VPS could not match.
     *
     * @throws RuntimeException
     */
    private function checkPlan(Service $service, int $pool): void
    {
        $config = (array) ($service->product?->module_config ?? []);
        $planId = self::whole($config['plan_id'] ?? null, 1);

        if ($planId === null || $service->server === null) {
            return;
        }

        $plan = $this->plan($service->server, $planId);
        $virt = (string) ($config['virt'] ?? '');

        if ($virt !== '' && isset($plan['virt']) && (string) $plan['virt'] !== $virt) {
            throw new RuntimeException(__('The Virtualizor plan is for another virtualization type than the product, so nothing was changed. Check the product.'));
        }

        if ($this->poolIds($plan['ippoolid'] ?? null) !== [$pool]) {
            throw new RuntimeException(__('The Virtualizor plan does not use exactly the IP pool set for extra IPv4 on the product, so nothing was changed.'));
        }
    }

    /**
     * IP pool IDs from a plan. Virtualizor keeps them as a PHP-serialized list; they are read with a
     * pattern, never unserialized.
     *
     * @return list<int>
     */
    private function poolIds(mixed $value): array
    {
        if (is_string($value) && preg_match('/\Aa:\d+:\{(.*)\}\z/s', $value, $match)) {
            preg_match_all('/i:\d+;(?:i:(\d+)|s:\d+:"(\d+)");/', $match[1], $pairs, PREG_SET_ORDER);
            $value = array_map(fn (array $pair): string => $pair[2] ?? $pair[1], $pairs);
        } elseif (is_string($value) || is_int($value)) {
            $value = [$value];
        }

        return array_values(array_map('intval', array_filter((array) $value, fn (mixed $id): bool => (is_int($id) || is_string($id)) && ctype_digit((string) $id) && (int) $id > 0)));
    }

    /**
     * A Virtualizor plan, kept 10 minutes per server and plan.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When Virtualizor does not answer or does not list the plan.
     */
    private function plan(Server $server, int $planId): array
    {
        return Cache::remember('nuvabill.virtualizor.plan.'.$server->id.'.'.$planId, self::PLAN_SECONDS, function () use ($server, $planId): array {
            $plan = $this->admin->call($server, ['act' => 'plans', 'reslen' => 500])['plans'][$planId] ?? null;

            if (! is_array($plan) || (int) ($plan['plid'] ?? $planId) !== $planId) {
                throw new RuntimeException(__('Virtualizor did not list plan :id, so the extras cannot be worked out. Nothing was changed.', ['id' => $planId]));
            }

            return $plan;
        });
    }

    /**
     * Whether the totals grow the VPS's disk, which needs its list of disks.
     *
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $info
     */
    private function growsDisk(array $totals, array $info): bool
    {
        $live = $this->resourceOf($info, 'disk');

        return isset($totals['disk']) && $live !== null && $live < (float) $totals['disk'];
    }

    /**
     * The disks of a VPS, from Virtualizor's edit page, for growing its disk.
     *
     * @return array<int|string, mixed>
     */
    private function disks(?Server $server, string $vpsId): array
    {
        $page = $this->admin->call($server, ['act' => 'managevps', 'vpsid' => $vpsId]);

        return (array) ($page['vps']['disks'] ?? $page['vs_info']['disks'] ?? $page['disks'] ?? []);
    }

    /**
     * What on the VPS differs from the record, as names for staff.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $info
     * @return list<string>
     */
    private function mismatches(Service $service, array $record, array $info, bool $diskMayBeBigger = false): array
    {
        if ($info === []) {
            return [__('VPS not found')];
        }

        $problems = [];

        if (filled($record['hostname'] ?? null) && strtolower((string) ($info['hostname'] ?? '')) !== strtolower((string) $record['hostname'])) {
            $problems[] = __('hostname');
        }

        $virt = (string) ($service->product?->module_config['virt'] ?? '');

        if ($virt !== '' && isset($info['virt']) && (string) $info['virt'] !== $virt) {
            $problems[] = __('virtualization');
        }

        foreach ((array) $record['totals'] as $field => $total) {
            if (! array_key_exists($field, self::FIELDS)) {
                continue;
            }

            $live = $this->resourceOf($info, $field);

            if ($live !== (float) $total && ! ($diskMayBeBigger && $field === 'disk' && $live !== null && $live > (float) $total)) {
                $problems[] = $this->label(self::kindOf($field));
            }
        }

        return $problems;
    }

    /**
     * What each total should be (expected) and is (live), by kind, with a line for staff for each
     * one that differs. With $diskMayBeBigger a disk bigger than expected is fine: it cannot shrink,
     * and the client has at least what they pay for.
     *
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $info
     * @param  list<string>  $skip  Fields left out.
     * @return array{expected: array<string, int>, live: array<string, int|null>, problems: list<string>}
     */
    private function compare(array $totals, array $info, bool $diskMayBeBigger, array $skip = []): array
    {
        $result = ['expected' => [], 'live' => [], 'problems' => []];

        foreach ($totals as $field => $total) {
            if (in_array($field, $skip, true)) {
                continue;
            }

            $kind = self::kindOf($field);
            $unit = self::KINDS[$kind]['unit'];
            $live = $this->resourceOf($info, $field);
            $result['expected'][$kind] = $total;
            $result['live'][$kind] = $live === null ? null : (int) $live;

            if ($live === null) {
                $result['problems'][] = __(':kind could not be read from the server.', ['kind' => $this->label($kind)]);
            } elseif ($live !== (float) $total && ! ($diskMayBeBigger && $field === 'disk' && $live > (float) $total)) {
                $result['problems'][] = __(':kind: :live, expected :expected', [
                    'kind' => $this->label($kind),
                    'live' => ResourceOption::formatUnits($unit, (int) $live),
                    'expected' => ResourceOption::formatUnits($unit, $total),
                ]);
            }
        }

        return $result;
    }

    /**
     * IPv4 addresses the VPS got since its addresses were last noted that are not from the pool,
     * as lines for staff. The noted addresses change only after a change that checked out, or in
     * noteAddresses() when they are missing or out of date, so a new try checks the same addresses
     * again.
     *
     * @param  array<string, mixed>  $info
     * @return list<string>
     *
     * @throws RuntimeException When Virtualizor does not answer.
     */
    private function poolProblems(Service $service, array $info, string $vpsId, int $pool): array
    {
        $noted = array_filter((array) (((array) $service->module_data)['ips'] ?? []), 'is_string');
        $problems = [];

        foreach (array_diff(AdminApi::addresses($info, FILTER_FLAG_IPV4), $noted) as $ip) {
            if (! $this->inPool($service->server, $ip, $vpsId, $pool)) {
                $problems[] = __(':ip is not from IP pool :pool', ['ip' => $ip, 'pool' => $pool]);
            }
        }

        return $problems;
    }

    /**
     * Before a change whose new IPv4 addresses are checked against the pool: when the service has
     * no noted addresses (a VPS imported from WHMCS notes only its ID), or the VPS no longer has all
     * of them (staff changed its addresses in Virtualizor), note the addresses it has now. Then only
     * the addresses this change adds are checked, never the main address, which may come from
     * another pool. Noted addresses the VPS still has are kept, so a new try after a failed check
     * checks the same new addresses again.
     *
     * @param  array<string, mixed>  $before  The VPS before the change.
     */
    private function noteAddresses(Service $service, array $before): void
    {
        $noted = array_filter((array) (((array) $service->module_data)['ips'] ?? []), 'is_string');
        $addresses = AdminApi::addresses($before);

        if ($addresses === [] || ($noted !== [] && array_diff($noted, $addresses) === [])) {
            return;
        }

        $this->saveData($service, function (array $data) use ($addresses): array {
            $data['ips'] = $addresses;

            return $data;
        });
    }

    /**
     * Note the totals the VPS has now, and its addresses.
     *
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $info
     */
    private function remember(Service $service, array $totals, array $info): void
    {
        $this->saveData($service, function (array $data) use ($totals, $info): array {
            $earlier = is_array($data[self::DATA]['totals'] ?? null) ? $data[self::DATA]['totals'] : [];
            $data[self::DATA] = ['totals' => array_merge($earlier, $totals), 'checked_at' => now()->getTimestamp()];
            $addresses = AdminApi::addresses($info);

            if ($addresses !== []) {
                $data['ips'] = $addresses;
            }

            return $data;
        });
    }

    /**
     * Change some keys of the service's module data. The row is read again under a lock, so other
     * module data saved meanwhile stays, and the model is kept in step.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    private function saveData(Service $service, Closure $change): void
    {
        $data = DB::transaction(function () use ($service, $change): ?array {
            $fresh = Service::query()->lockForUpdate()->find($service->id);

            if ($fresh === null) {
                return null;
            }

            $data = $change((array) $fresh->module_data);
            $fresh->module_data = $data;
            $fresh->save();

            return $data;
        });

        if ($data !== null) {
            $service->module_data = $data;
            $service->syncOriginalAttribute('module_data');
        }
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function resourceOf(array $info, string $field): ?float
    {
        return match ($field) {
            'num_ips' => (float) count(AdminApi::addresses($info, FILTER_FLAG_IPV4)),
            'num_ips6' => (float) count(AdminApi::addresses($info, FILTER_FLAG_IPV6)),
            'num_ips6_subnet' => (float) count(array_filter((array) ($info['ips6_subnet'] ?? []), 'is_string')),
            default => is_numeric($info[self::FIELDS[$field]] ?? null) ? (float) $info[self::FIELDS[$field]] : null,
        };
    }

    /**
     * The name of a kind for clients and staff.
     */
    private function label(string $kind): string
    {
        return match ($kind) {
            'virtualizor_ipv6_subnet' => __('IPv6 subnets'),
            'virtualizor_network_speed' => __('Network speed'),
            default => ResourceOption::kindLabel($kind),
        };
    }

    /**
     * The kind a request field sets.
     */
    private static function kindOf(string $field): string
    {
        foreach (self::KINDS as $kind => $info) {
            if ($info['field'] === $field) {
                return $kind;
            }
        }

        return $field;
    }

    /**
     * The extras of this module's kinds, as whole units.
     *
     * @return array<string, int>
     */
    private static function known(ResourceExtras $extras): array
    {
        return array_map('intval', array_intersect_key($extras->units, self::KINDS));
    }

    /**
     * The extras of the kinds Nuvabill sets on a live VPS: kinds with extras above 0, and kinds the
     * service has a resource row for, active or cancelled (a cancelled row takes its kind back to
     * the plan, so removals still reach the server). A kind at 0 without a row was never raised by
     * Nuvabill, so the VPS keeps what it has there, such as addresses or memory staff gave it in
     * Virtualizor, the same way a bigger disk is kept.
     *
     * @return array<string, int>
     */
    private static function raised(Service $service, ResourceExtras $extras): array
    {
        $rows = $service->resources()->distinct()->pluck('kind')->map(fn (mixed $kind): string => (string) $kind)->all();

        return array_filter(self::known($extras), fn (int $units, string $kind): bool => $units > 0 || in_array($kind, $rows, true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * A whole number of at least $min, or null.
     */
    private static function whole(mixed $value, int $min): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => 2147483647]]);

        return $number === false ? null : $number;
    }

    /**
     * Whether Virtualizor's IP list shows the address as this VPS's public IPv4 from the pool.
     */
    private function inPool(?Server $server, string $ip, string $vpsId, int $pool): bool
    {
        $rows = array_values(array_filter((array) ($this->admin->call($server, ['act' => 'ips', 'ipsearch' => $ip, 'ippid' => $pool, 'page' => 1, 'reslen' => 50])['ips'] ?? []), fn (mixed $row): bool => is_array($row) && ($row['ip'] ?? null) === $ip));

        return count($rows) === 1
            && (int) ($rows[0]['ippid'] ?? 0) === $pool
            && (string) ($rows[0]['vpsid'] ?? '') === $vpsId
            && (int) ($rows[0]['ipv6'] ?? 1) === 0
            && (int) ($rows[0]['internal'] ?? 1) === 0
            && (int) ($rows[0]['nat'] ?? 1) === 0;
    }

    /**
     * The changes that bring the VPS to the totals, and the fields that cannot be set here. Fewer
     * addresses keep the first ones the VPS had (the main one first), so clients keep theirs. A disk
     * only grows, and only when the VPS has a single disk with a storage ID.
     *
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $before  The VPS before any change, for the addresses it keeps.
     * @param  array<string, mixed>  $after  The VPS as it is now.
     * @param  array<int|string, mixed>  $disks
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function edits(array $totals, array $before, array $after, array $disks): array
    {
        $post = [];
        $unset = [];

        foreach ($totals as $field => $total) {
            $live = $this->resourceOf($after, $field);

            if ($live === (float) $total) {
                continue;
            }

            if ($field === 'num_ips' || $field === 'num_ips6') {
                $flag = $field === 'num_ips' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
                $kept = array_slice(AdminApi::addresses($before, $flag), 0, $total);
                $post[$field] = $total;

                if (count($kept) === $total) {
                    $post[$field === 'num_ips' ? 'ips' : 'ips6'] = $kept;
                }
            } elseif ($field === 'disk') {
                if ($live !== null && $live > (float) $total) {
                    continue;
                }

                $disks = array_values(array_filter($disks, 'is_array'));

                // A disk whose size cannot be read is never sent: it could be bigger than the total.
                if ($live === null || count($disks) !== 1 || blank($disks[0]['st_uuid'] ?? null)) {
                    $unset[] = 'disk';

                    continue;
                }

                $post['space'] = [array_filter(['size' => $total, 'st_uuid' => (string) $disks[0]['st_uuid'], 'disk_uuid' => isset($disks[0]['disk_uuid']) ? (string) $disks[0]['disk_uuid'] : null], fn (mixed $value): bool => $value !== null)];
            } else {
                $post[$field] = $total;
            }
        }

        return [$post, $unset];
    }
}
