<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Database\Factories\ResourceChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mid-cycle change of a service's extras, like a plan change: waiting for its invoice, waiting for
 * the renewal date, applied, or cancelled. The amounts are the extras' renewal price before and
 * after; the difference is what the client pays now (positive) or gets back (negative). Billing and
 * the server are tracked apart: server_state follows the job that sets the server.
 */
class ResourceChange extends Model
{
    /** @use HasFactory<ResourceChangeFactory> */
    use HasFactory;

    public const STATUS_PENDING = PlanChange::STATUS_PENDING;

    public const STATUS_APPLIED = PlanChange::STATUS_APPLIED;

    public const STATUS_CANCELLED = PlanChange::STATUS_CANCELLED;

    /** The client pays the difference first; the change happens when the invoice is paid. */
    public const MODE_INVOICE = PlanChange::MODE_INVOICE;

    /** The change happens now; any unused amount goes to the client's wallet. */
    public const MODE_NOW = PlanChange::MODE_NOW;

    /** The change happens on the next renewal date, with no money back. */
    public const MODE_RENEWAL = PlanChange::MODE_RENEWAL;

    /** Nothing to set on the server (billing only, or not applied yet). */
    public const SERVER_NONE = 'none';

    /** Waiting for the job that sets the server. */
    public const SERVER_QUEUED = 'queued';

    /** The job is setting the server now. */
    public const SERVER_APPLYING = 'applying';

    /** The module started the change and it is checked again later. */
    public const SERVER_CHECKING = 'checking';

    public const SERVER_DONE = 'done';

    /** The server did not take the change after every try; staff must act. */
    public const SERVER_FAILED = 'failed';

    /** No module can set it, so staff set it by hand and mark it done. */
    public const SERVER_MANUAL = 'manual';

    protected $fillable = [
        'service_id',
        'client_id',
        'invoice_id',
        'admin_id',
        'billing_cycle',
        'currency',
        'old_amount',
        'new_amount',
        'difference',
        'mode',
        'status',
        'apply_on',
        'applied_at',
        'sets_server',
        'server_state',
        'server_attempts',
        'server_checks',
        'server_message',
        'server_done_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'sets_server' => true,
        'server_state' => self::SERVER_NONE,
        'server_attempts' => 0,
        'server_checks' => 0,
    ];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'old_amount' => 'integer',
            'new_amount' => 'integer',
            'difference' => 'integer',
            'apply_on' => 'immutable_date',
            'applied_at' => 'datetime',
            'sets_server' => 'boolean',
            'server_attempts' => 'integer',
            'server_checks' => 'integer',
            'server_done_at' => 'datetime',
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
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The staff member who made the change, or null when the client made it.
     *
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<ResourceChangeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ResourceChangeItem::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Whether the server side is still running: waiting for the job, being set, or being checked.
     */
    public function serverBusy(): bool
    {
        return in_array($this->server_state, [self::SERVER_QUEUED, self::SERVER_APPLYING, self::SERVER_CHECKING], true);
    }
}
