<?php

namespace App\Models;

use Database\Factories\MachineIpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An IP address routed to a machine: its main address, addresses that come with every plan, or an
 * extra address a service rents. Which service rents an extra address is set only by
 * MachineInventory, never from a form.
 */
class MachineIp extends Model
{
    /** @use HasFactory<MachineIpFactory> */
    use HasFactory;

    public const KIND_PRIMARY = 'primary';

    public const KIND_INCLUDED = 'included';

    public const KIND_EXTRA = 'extra';

    protected $fillable = ['machine_id', 'address', 'version', 'prefix', 'gateway', 'kind'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'prefix' => 'integer',
            'assigned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * The service that rents this extra address, if any.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
