<?php

namespace App\Models;

use Database\Factories\ServiceResourceOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a service has of one resource kind: how many steps above its plan, and the price it pays for
 * them each billing period (kept when option prices change later). The kind, unit and step size are
 * copies, so the row still means the same after a plan change or when its option is gone. A row whose
 * steps went to 0 is cancelled but kept, because invoice lines point to it.
 */
class ServiceResourceOption extends Model
{
    /** @use HasFactory<ServiceResourceOptionFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'service_id',
        'resource_option_id',
        'kind',
        'unit',
        'step_size',
        'steps',
        'recurring_amount',
        'status',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'unit' => '',
        'steps' => 0,
        'recurring_amount' => 0,
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'resource_option_id' => 'integer',
            'step_size' => 'integer',
            'steps' => 'integer',
            'recurring_amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The product's option this row belongs to, or null when the option is gone or not on the
     * service's current product.
     *
     * @return BelongsTo<ResourceOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ResourceOption::class, 'resource_option_id');
    }

    /**
     * The extra units on top of the plan: steps × step size, or 0 when the row is not active.
     */
    public function units(): int
    {
        return $this->isActive() ? $this->steps * $this->step_size : 0;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->steps > 0;
    }

    /**
     * A short line for invoices and pages, such as "RAM +4 GB". A switch (one step, such as backups)
     * is just its name, and a ladder step shows the name of that step.
     */
    public function describe(): string
    {
        $option = $this->option;
        $label = $option !== null ? $option->displayLabel() : ResourceOption::kindLabel($this->kind);

        if ($option?->ladder !== null) {
            return $label.': '.ResourceOption::formatUnits($this->unit, $this->steps * $this->step_size, $option->ladder, $this->steps);
        }

        if ($this->unit === '' && ($this->kind === 'backups' || $option?->max_steps === 1)) {
            return $label;
        }

        return $label.' +'.ResourceOption::formatUnits($this->unit, $this->steps * $this->step_size);
    }
}
