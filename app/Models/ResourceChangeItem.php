<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One kind in a resource change: the steps before and after, the row's renewal price before and
 * after, and what the client pays (cost, setup fee) or gets back (credit) for the rest of the period.
 */
class ResourceChangeItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'resource_change_id',
        'resource_option_id',
        'kind',
        'unit',
        'step_size',
        'from_steps',
        'to_steps',
        'old_amount',
        'new_amount',
        'cost',
        'credit',
        'setup_fee',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'unit' => '',
        'cost' => 0,
        'credit' => 0,
        'setup_fee' => 0,
    ];

    protected function casts(): array
    {
        return [
            'step_size' => 'integer',
            'from_steps' => 'integer',
            'to_steps' => 'integer',
            'old_amount' => 'integer',
            'new_amount' => 'integer',
            'cost' => 'integer',
            'credit' => 'integer',
            'setup_fee' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ResourceChange, $this>
     */
    public function change(): BelongsTo
    {
        return $this->belongsTo(ResourceChange::class, 'resource_change_id');
    }

    /**
     * @return BelongsTo<ResourceOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ResourceOption::class, 'resource_option_id');
    }
}
