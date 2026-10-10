<?php

namespace App\Billing;

use App\Extensions\Servers\Resources\ResourceKind;
use App\Models\ResourceOption;

/**
 * The built-in resource kinds every module shares, with their fixed units. A module that sells a
 * built-in kind must use its unit here; its own kinds are named "{slug}_{name}".
 *
 * Also the one place that turns units into text ("4 GB", "RAM +4 GB"), so store pages, invoices
 * and admin screens read the same.
 */
final class ResourceKinds
{
    /**
     * The pattern every kind key follows: safe in form names, JSON keys and HTML ids.
     */
    public const KEY_PATTERN = ResourceKind::KEY_PATTERN;

    /**
     * Built-in kind => unit, the smallest step staff may set, whether a live server can shrink it,
     * and the most extra units (null for no limit of its own). Backups is a switch: 0 or 1.
     *
     * @var array<string, array{unit: string, min_step: int, can_shrink: bool, max_extra: int|null}>
     */
    public const BUILT_IN = [
        'cpu' => ['unit' => 'core', 'min_step' => 1, 'can_shrink' => true, 'max_extra' => null],
        'ram' => ['unit' => 'MB', 'min_step' => 128, 'can_shrink' => true, 'max_extra' => null],
        'disk' => ['unit' => 'GB', 'min_step' => 1, 'can_shrink' => false, 'max_extra' => null],
        'ipv4' => ['unit' => 'IP', 'min_step' => 1, 'can_shrink' => true, 'max_extra' => null],
        'ipv6' => ['unit' => 'IP', 'min_step' => 1, 'can_shrink' => true, 'max_extra' => null],
        'bandwidth' => ['unit' => 'GB', 'min_step' => 1, 'can_shrink' => true, 'max_extra' => null],
        'backups' => ['unit' => '', 'min_step' => 1, 'can_shrink' => true, 'max_extra' => 1],
    ];

    /**
     * The icon (from the x-icon component) shown next to each built-in kind on staff screens.
     *
     * @var array<string, string>
     */
    private const ICONS = [
        'cpu' => 'zap',
        'ram' => 'layers',
        'disk' => 'server',
        'ipv4' => 'globe',
        'ipv6' => 'globe',
        'bandwidth' => 'activity',
        'backups' => 'shield',
    ];

    public static function isBuiltIn(string $key): bool
    {
        return array_key_exists($key, self::BUILT_IN);
    }

    /**
     * The built-in kind with its translated name, or null for a key that is not built in.
     */
    public static function builtIn(string $key): ?ResourceKind
    {
        $kind = self::BUILT_IN[$key] ?? null;

        if ($kind === null) {
            return null;
        }

        return new ResourceKind(
            key: $key,
            label: ResourceOption::kindLabel($key),
            unit: $kind['unit'],
            minStep: $kind['min_step'],
            maxExtra: $kind['max_extra'],
            canShrink: $kind['can_shrink'],
        );
    }

    /**
     * Every built-in kind, for a product without a server module: staff apply the extras by hand.
     *
     * @return array<string, ResourceKind>
     */
    public static function forProductWithoutModule(): array
    {
        $kinds = [];

        foreach (array_keys(self::BUILT_IN) as $key) {
            $kinds[$key] = self::builtIn($key);
        }

        return $kinds;
    }

    /**
     * The translated name of a built-in kind, or a readable form of a module's own kind.
     */
    public static function label(string $kind): string
    {
        return ResourceOption::kindLabel($kind);
    }

    public static function icon(string $kind): string
    {
        return self::ICONS[$kind] ?? 'puzzle';
    }

    /**
     * Whether a live server can take extras of this kind away. Unknown kinds can, unless the
     * module said otherwise when the option was saved.
     */
    public static function canShrink(string $kind): bool
    {
        return self::BUILT_IN[$kind]['can_shrink'] ?? true;
    }

    /**
     * An amount as people read it, such as "4 GB", "2 TB" or "2". A ladder shows the name of the
     * step. See ResourceOption::formatUnits().
     *
     * @param  list<string>|null  $ladder
     */
    public static function format(string $unit, int $units, ?array $ladder = null, ?int $steps = null): string
    {
        return ResourceOption::formatUnits($unit, $units, $ladder, $steps);
    }

    /**
     * A short line for carts, invoices and pages: "RAM +4 GB", "Server size: CX33", or just the
     * name for a switch that counts nothing ("Backups"). The same rules as
     * ServiceResourceOption::describe().
     *
     * @param  list<string>|null  $ladder
     */
    public static function describe(string $label, string $unit, int $units, ?array $ladder = null, ?int $steps = null, bool $toggle = false): string
    {
        if ($ladder !== null) {
            return $label.': '.self::format($unit, $units, $ladder, $steps ?? $units);
        }

        if ($toggle && $unit === '') {
            return $label;
        }

        return $label.' +'.self::format($unit, $units);
    }

    /**
     * Whether an option is shown as an on/off switch: one step and no ladder.
     *
     * @param  list<string>|null  $ladder
     */
    public static function isToggle(int $maxSteps, ?array $ladder): bool
    {
        return $ladder === null && $maxSteps === 1;
    }
}
