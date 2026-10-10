<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Resources\ResourceCheck;
use App\Extensions\Servers\Resources\ResourceExtras;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Extensions\Servers\Resources\ResourceLimit;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceAddon;
use LogicException;
use RuntimeException;

/**
 * Extra VPS resources: what a VPS gets on top of its plan, set when it is made, after a plan change
 * and when the client changes their extras. Totals are the plan's base plus the extras. A made VPS
 * is checked against them, and disk space only grows.
 *
 * Today the extras come from the product's resource add-on JSON (see ResourceAddons). They move to
 * the core resource options ($service->resourceExtras()), which this class sets on the server.
 */
final class ResourceTotals
{
    /**
     * Where a resource is in Virtualizor's VPS details.
     */
    public const FIELDS = ['cores' => 'cores', 'ram' => 'ram', 'disk' => 'space', 'num_ips' => 'ips', 'bandwidth' => 'bandwidth'];

    public function __construct(private AdminApi $admin) {}

    /**
     * The resource kinds the product can sell. Local only: it never calls the server.
     *
     * @return array<string, ResourceKind>
     *
     * @throws \InvalidArgumentException With a message for staff when the product's settings are wrong.
     */
    public function kinds(Product $product): array
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * What the product's plan gives without extras, in units per kind (null where it is not known).
     * May read the plan from the server.
     *
     * @return array<string, int|null>
     */
    public function base(Product $product, ?Server $server): array
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Live limits for one service, in extra units per kind. May call the server.
     *
     * @return array<string, ResourceLimit>
     */
    public function limits(Service $service): array
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Set the live VPS to its plan's base plus these extras, then check it.
     */
    public function apply(Service $service, ResourceExtras $extras): ModuleResult
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Compare the live VPS with its plan's base plus these extras. Changes nothing.
     *
     * @throws \Throwable When the server does not answer.
     */
    public function check(Service $service, ResourceExtras $extras): ResourceCheck
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * The totals a new VPS gets with its extras, or null when it has none (it is made exactly as
     * before). Whatever else the array holds besides "totals" goes into the create note. Throws when
     * the extras cannot be worked out safely; nothing is sent then.
     *
     * @return array{totals: array<string, int>, addons: list<int>, pool: int|null}|null
     *
     * @throws RuntimeException With a message for staff. Code ResourceAddons::UNPAID when the extras wait for their payment.
     */
    public function totalsForCreate(Service $service): ?array
    {
        return $this->resources($service, forCreate: true);
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
        return $this->resources($service, forCreate: false)['totals'] ?? null;
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
     * Apply the new plan, then set the extras on top again and check the result.
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
            [$post, $problems] = $this->edits($totals, $before, $after, (array) ($response['vs_info']['disks'] ?? []));

            if ($post !== []) {
                $this->admin->call($server, ['act' => 'managevps', 'vpsid' => $vpsId], ['editvps' => 1] + $post);
                $after = $this->admin->vpsInfo($server, $vpsId);
            }

            $problems = array_values(array_unique([...$problems, ...$this->mismatches($service, ['totals' => $totals], $after)]));
        } catch (RuntimeException) {
            $problems = [__('no clear answer from Virtualizor')];
        }

        if ($problems === []) {
            return ModuleResult::ok(__('Plan applied to the VPS, with its add-on extras.'));
        }

        return ModuleResult::fail(__('The new plan is applied, but its add-on extras could not all be set again (:problems). Set them in Virtualizor by hand: :totals.', [
            'problems' => implode(', ', $problems),
            'totals' => collect($totals)->map(fn (int $total, string $field): string => $this->resourceName($field).' '.$total)->implode(', '),
        ]));
    }

    /**
     * The extra resources the service's add-ons give under its product's policy, with the totals
     * to set on the VPS. Null when there are none: a service without add-ons, without a policy or
     * with only other add-ons works as before. Throws when the extras cannot be worked out safely.
     *
     * @return array{totals: array<string, int>, addons: list<int>, pool: int|null}|null
     */
    private function resources(Service $service, bool $forCreate): ?array
    {
        $record = ResourceAddons::record($service);

        if ($record === null && ! $service->addons()->where('status', ServiceAddon::STATUS_ACTIVE)->exists()) {
            return null;
        }

        $policy = ResourceAddons::policy($service);
        $extras = $policy === null ? ['add' => [], 'addons' => [], 'rows' => []] : ResourceAddons::extras($service, $policy);

        // On a plan change, add-ons that gave this VPS extras when it was made must still count.
        if (! $forCreate && $record !== null && $record['state'] === ResourceAddons::VERIFIED) {
            $active = $service->addons()->where('status', ServiceAddon::STATUS_ACTIVE)->pluck('product_addon_id')->map(fn (mixed $id): int => (int) $id)->all();

            if (array_diff(array_intersect($record['addons'], $active), $extras['addons']) !== []) {
                throw new RuntimeException(__('This VPS has extra resources from add-ons that the new plan\'s add-on policy does not cover, so the plan was not applied on the server. Set its resources in Virtualizor by hand.'));
            }
        }

        if ($policy === null || $extras['add'] === []) {
            return null;
        }

        if ($policy['server_id'] !== null && $policy['server_id'] !== (int) $service->server_id) {
            throw new RuntimeException(__('The resource add-on policy of this product is for another server, so nothing was changed. Check the product.'));
        }

        if ($policy['plan_id'] !== null && $policy['plan_id'] !== (int) self::setting($service, 'plan_id', 0)) {
            throw new RuntimeException(__('The resource add-on policy of this product names another plan than the product, so nothing was changed. Check the product.'));
        }

        if ($forCreate) {
            ResourceAddons::assertPaid($service, $extras['rows']);
        }

        return ['totals' => $this->resourceTotals($service, $policy, $extras['add']), 'addons' => $extras['addons'], 'pool' => $policy['public_pool_id']];
    }

    /**
     * The totals for the resources that add-ons raise: the product's base plus the extras. The
     * base comes from the policy, the product settings or the Virtualizor plan, in that order.
     *
     * @param  array<string, mixed>  $policy
     * @param  array<string, int>  $add
     * @return array<string, int>
     */
    private function resourceTotals(Service $service, array $policy, array $add): array
    {
        $planId = (int) self::setting($service, 'plan_id', 0);
        $plan = null;

        // A policy that lists the plan's base or an IP pool is checked against the live plan.
        if ($planId > 0 && ($policy['base'] !== [] || $policy['public_pool_id'] !== null)) {
            $plan = $this->plan($service->server, $planId);
            $this->checkPlan($service, $policy, $plan);
        }

        $totals = [];

        foreach ($add as $field => $extra) {
            $base = $policy['base'][$field] ?? $this->productBase($service, $field);

            if ($base === null && $planId > 0) {
                $plan ??= $this->plan($service->server, $planId);
                $value = filter_var($plan[self::FIELDS[$field]] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $base = $value === false ? null : $value;
            }

            if ($base === null) {
                throw new RuntimeException(__('The base :resource of this product is not known, so the add-on extras cannot be added. Set it in the product\'s add-on policy ("base"). Nothing was changed.', ['resource' => $this->resourceName($field)]));
            }

            $totals[$field] = $base + $extra;

            if ($totals[$field] > $policy['max'][$field]) {
                throw new RuntimeException(__('With its add-ons this VPS would get more :resource than the product\'s add-on policy allows ("max"). Nothing was changed.', ['resource' => $this->resourceName($field)]));
            }
        }

        return $totals;
    }

    /**
     * The base of a resource from the product settings, or null when the plan sets it.
     */
    private function productBase(Service $service, string $field): ?int
    {
        if ($field === 'num_ips') {
            return (int) self::setting($service, 'num_ips', 1) ?: 1;
        }

        $value = (int) self::setting($service, $field, 0);

        return $value > 0 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $plan
     */
    private function checkPlan(Service $service, array $policy, array $plan): void
    {
        $virt = (string) self::setting($service, 'virt', '');

        if ($virt !== '' && isset($plan['virt']) && (string) $plan['virt'] !== $virt) {
            throw new RuntimeException(__('The Virtualizor plan is for another virtualization type than the product, so nothing was changed. Check the product.'));
        }

        foreach ($policy['base'] as $field => $value) {
            if (filter_var($plan[self::FIELDS[$field]] ?? null, FILTER_VALIDATE_INT) !== $value) {
                throw new RuntimeException(__('The Virtualizor plan no longer matches the base in the product\'s add-on policy. Update one of them; nothing was changed.'));
            }
        }

        if ($policy['public_pool_id'] !== null && $this->poolIds($plan['ippoolid'] ?? null) !== [$policy['public_pool_id']]) {
            throw new RuntimeException(__('The Virtualizor plan does not use exactly the IP pool in the product\'s add-on policy, so nothing was changed.'));
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
     * @return array<string, mixed>
     */
    private function plan(?Server $server, int $planId): array
    {
        $plan = $this->admin->call($server, ['act' => 'plans', 'reslen' => 500])['plans'][$planId] ?? null;

        if (! is_array($plan) || (int) ($plan['plid'] ?? $planId) !== $planId) {
            throw new RuntimeException(__('Virtualizor did not list plan :id, so the add-on extras cannot be worked out. Nothing was changed.', ['id' => $planId]));
        }

        return $plan;
    }

    /**
     * What on the VPS differs from the record, as names for staff.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $info
     * @return list<string>
     */
    private function mismatches(Service $service, array $record, array $info): array
    {
        if ($info === []) {
            return [__('VPS not found')];
        }

        $problems = [];

        if (filled($record['hostname'] ?? null) && strtolower((string) ($info['hostname'] ?? '')) !== strtolower((string) $record['hostname'])) {
            $problems[] = __('hostname');
        }

        $virt = (string) self::setting($service, 'virt', '');

        if ($virt !== '' && isset($info['virt']) && (string) $info['virt'] !== $virt) {
            $problems[] = __('virtualization');
        }

        foreach ((array) $record['totals'] as $field => $total) {
            if (array_key_exists($field, self::FIELDS) && $this->resourceOf($info, $field) !== (float) $total) {
                $problems[] = $this->resourceName($field);
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function resourceOf(array $info, string $field): ?float
    {
        if ($field === 'num_ips') {
            return (float) count(AdminApi::addresses($info, FILTER_FLAG_IPV4));
        }

        $value = $info[self::FIELDS[$field]] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function resourceName(string $field): string
    {
        return match ($field) {
            'cores' => __('CPU cores'),
            'ram' => __('RAM'),
            'disk' => __('disk space'),
            'num_ips' => __('IPv4 addresses'),
            default => __('bandwidth'),
        };
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
     * The changes that bring the VPS back to the totals, and what cannot be set safely.
     *
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<int|string, mixed>  $disks
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function edits(array $totals, array $before, array $after, array $disks): array
    {
        $post = [];
        $problems = [];

        foreach ($totals as $field => $total) {
            if ($this->resourceOf($after, $field) === (float) $total) {
                continue;
            }

            if ($field === 'num_ips') {
                // Keep the addresses the VPS had, so clients do not get new ones.
                $kept = array_slice(AdminApi::addresses($before, FILTER_FLAG_IPV4), 0, $total);
                $post['num_ips'] = $total;

                if (count($kept) === $total) {
                    $post['ips'] = $kept;
                }
            } elseif ($field === 'disk') {
                $disks = array_values(array_filter($disks, 'is_array'));

                if (count($disks) !== 1 || blank($disks[0]['st_uuid'] ?? null)) {
                    $problems[] = $this->resourceName('disk');

                    continue;
                }

                $post['space'] = [array_filter(['size' => $total, 'st_uuid' => (string) $disks[0]['st_uuid'], 'disk_uuid' => isset($disks[0]['disk_uuid']) ? (string) $disks[0]['disk_uuid'] : null], fn (mixed $value): bool => $value !== null)];
            } else {
                $post[$field] = $total;
            }
        }

        return [$post, $problems];
    }

    /**
     * A product setting of the service's product.
     */
    private static function setting(Service $service, string $key, mixed $default = null): mixed
    {
        return $service->product?->module_config[$key] ?? $default;
    }
}
