<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The price of one step of a resource option in one currency and billing cycle, in minor units,
 * plus the setup fee charged once for each step a client adds.
 */
class ResourceOptionPrice extends Model
{
    public $timestamps = false;

    protected $fillable = ['resource_option_id', 'currency', 'billing_cycle', 'price', 'setup_fee'];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'price' => 'integer',
            'setup_fee' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ResourceOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ResourceOption::class, 'resource_option_id');
    }
}
