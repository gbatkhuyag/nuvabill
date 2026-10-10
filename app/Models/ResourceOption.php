<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Support\Locales;
use Database\Factories\ResourceOptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * A slider a product offers, such as extra RAM: what one step adds, how many steps clients may
 * choose, and the price per step in each currency and billing cycle. The can_shrink, restarts,
 * note, ladder and max_extra columns are a copy of the module's kind, taken when staff save the
 * option, so store and client pages never ask the module or its server.
 */
class ResourceOption extends Model
{
    /** @use HasFactory<ResourceOptionFactory> */
    use HasFactory;

    /**
     * Services in these states hold none of an option's stock.
     */
    private const STATUSES_WITHOUT_STOCK = [ServiceStatus::Terminated, ServiceStatus::Cancelled];

    protected $fillable = [
        'product_id',
        'kind',
        'unit',
        'label',
        'description',
        'step_size',
        'included',
        'min_steps',
        'max_steps',
        'default_steps',
        'stock',
        'client_can_add',
        'client_can_remove',
        'is_active',
        'sort_order',
        'can_shrink',
        'restarts',
        'note',
        'ladder',
        'max_extra',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'unit' => '',
        'min_steps' => 0,
        'default_steps' => 0,
        'client_can_add' => true,
        'client_can_remove' => true,
        'is_active' => true,
        'sort_order' => 0,
        'can_shrink' => true,
        'restarts' => false,
    ];

    protected function casts(): array
    {
        return [
            'step_size' => 'integer',
            'included' => 'integer',
            'min_steps' => 'integer',
            'max_steps' => 'integer',
            'default_steps' => 'integer',
            'stock' => 'integer',
            'client_can_add' => 'boolean',
            'client_can_remove' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'can_shrink' => 'boolean',
            'restarts' => 'boolean',
            'ladder' => 'array',
            'max_extra' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<ResourceOptionPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ResourceOptionPrice::class);
    }

    /**
     * What services have of this option.
     *
     * @return HasMany<ServiceResourceOption, $this>
     */
    public function serviceRows(): HasMany
    {
        return $this->hasMany(ServiceResourceOption::class);
    }

    /**
     * Options clients see. A retired option is hidden, but the services that have it keep it and
     * keep paying for it.
     *
     * @param  Builder<ResourceOption>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function priceFor(string $currency, BillingCycle $cycle): ?ResourceOptionPrice
    {
        return $this->prices->first(
            fn (ResourceOptionPrice $price): bool => $price->currency === $currency && $price->billing_cycle === $cycle,
        );
    }

    /**
     * The name clients see: the one staff typed, or the kind's own name.
     */
    public function displayLabel(): string
    {
        return filled($this->label) ? (string) $this->label : self::kindLabel($this->kind);
    }

    /**
     * Whether a service has (or had) this option, or a change uses it. Then its kind and step size
     * cannot change.
     */
    public function isUsed(): bool
    {
        return $this->serviceRows()->exists()
            || ResourceChangeItem::query()->where('resource_option_id', $this->getKey())->exists();
    }

    /**
     * How many more steps can be sold, or null when the option has no stock limit. Good for showing;
     * where the answer decides a sale or a change, use lockedStockLeft().
     */
    public function stockLeft(): ?int
    {
        if ($this->stock === null) {
            return null;
        }

        $used = (int) $this->rowsHoldingStock()->sum('steps');

        return max(0, $this->stock - $used - $this->stepsHeldByChanges());
    }

    /**
     * How many more steps can be sold, or null when the option has no stock limit, counted from the
     * newest saved rows. Call it inside the transaction that sells the steps, after the products and
     * machines it needs are locked: it locks the option's row, then the service rows that hold its
     * stock in id order, until the transaction ends. See Product::lockedStockLeft() for the one lock
     * order every checkout, plan change and resource change uses.
     *
     * Steps held by waiting changes are counted with a plain read, after the locks, as for plan
     * changes: a change is saved under the option's lock, so it is either seen here or waits.
     */
    public function lockedStockLeft(): ?int
    {
        $saved = static::query()->whereKey($this->getKey())->lockForUpdate()->first(['id', 'stock']);

        if ($saved === null) {
            return 0;
        }

        if ($saved->stock === null) {
            return null;
        }

        $used = (int) $this->rowsHoldingStock()->orderBy('service_resource_options.id')->lockForUpdate()
            ->get(['service_resource_options.id', 'service_resource_options.steps'])->sum('steps');

        return max(0, $saved->stock - $used - $this->stepsHeldByChanges());
    }

    /**
     * The name of a built-in kind, or a readable form of a module's own kind.
     */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'cpu' => __('CPU cores'),
            'ram' => __('RAM'),
            'disk' => __('Disk space'),
            'ipv4' => __('IPv4 addresses'),
            'ipv6' => __('IPv6 addresses'),
            'bandwidth' => __('Monthly traffic'),
            'backups' => __('Backups'),
            default => Str::ucfirst(str_replace('_', ' ', $kind)),
        };
    }

    /**
     * An amount of a kind as people read it: RAM in GB from 1024 MB up (1536 MB is "1.5 GB"), traffic
     * and disk in TB at whole thousands of GB, and other units as a plain number with the unit. Cores,
     * IP addresses and plain counts are just the number, as the kind's name says what is counted
     * ("CPU cores +2"). A ladder shows the name of the step.
     *
     * @param  list<string>|null  $ladder
     */
    public static function formatUnits(string $unit, int $units, ?array $ladder = null, ?int $steps = null): string
    {
        if ($ladder !== null && $steps !== null && isset($ladder[$steps])) {
            return (string) $ladder[$steps];
        }

        $number = fn (int|float $value): string => (string) Number::format($value, maxPrecision: 1, locale: Locales::numberLocale());

        return match (true) {
            $unit === 'MB' && $units >= 1024 => $number($units / 1024).' GB',
            $unit === 'GB' && $units >= 1000 && $units % 1000 === 0 => $number(intdiv($units, 1000)).' TB',
            in_array($unit, ['', 'core', 'IP'], true) => $number($units),
            default => $number($units).' '.$unit,
        };
    }

    /**
     * Rows of services that hold this option's stock: active ones of services that are not
     * terminated or cancelled.
     *
     * @return HasMany<ServiceResourceOption, $this>
     */
    private function rowsHoldingStock(): HasMany
    {
        return $this->serviceRows()
            ->where('status', ServiceResourceOption::STATUS_ACTIVE)
            ->whereHas('service', fn (Builder $query): Builder => $query->whereNotIn('status', self::STATUSES_WITHOUT_STOCK));
    }

    /**
     * Steps added by waiting changes that will not count the stock when they happen: the ones that
     * wait for the renewal date and the ones staff made, as for plan changes.
     */
    private function stepsHeldByChanges(): int
    {
        return (int) ResourceChangeItem::query()
            ->where('resource_option_id', $this->getKey())
            ->whereColumn('to_steps', '>', 'from_steps')
            ->whereHas('change', fn (Builder $query): Builder => $query
                ->where('status', ResourceChange::STATUS_PENDING)
                ->where(fn (Builder $query): Builder => $query->where('mode', ResourceChange::MODE_RENEWAL)->orWhereNotNull('admin_id'))
                ->whereHas('service', fn (Builder $query): Builder => $query->whereNotIn('status', self::STATUSES_WITHOUT_STOCK)))
            ->get(['from_steps', 'to_steps'])
            ->sum(fn (ResourceChangeItem $item): int => $item->to_steps - $item->from_steps);
    }
}
