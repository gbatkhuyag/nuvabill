<?php

namespace App\Models;

use App\Enums\BmcType;
use App\Enums\MachineStatus;
use App\Enums\PowerState;
use Database\Factories\MachineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A dedicated server in the host's own rack (Admin → Hardware). It is sold as any product it is
 * linked to, held by one service at a time, and wiped before it is sold again.
 *
 * The status, the holding service, the certificate pin and everything read from the BMC are not
 * fillable: only MachineInventory and the power gateway set them, so no form can skip a wipe or
 * trust a certificate. The BMC login is encrypted with the app key and never leaves the server.
 */
class Machine extends Model
{
    /** @use HasFactory<MachineFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'location',
        'rack',
        'rack_unit',
        'cpu',
        'cpu_cores',
        'ram_gb',
        'storage',
        'port_speed',
        'notes',
        'bmc_type',
        'bmc_host',
        'bmc_port',
        'bmc_username',
        'bmc_password',
        'bmc_tls_mode',
        'bmc_ipmi_cipher',
    ];

    protected $hidden = ['bmc_username', 'bmc_password', 'bmc_tls_pin'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'available',
        'bmc_type' => 'none',
        'bmc_tls_mode' => 'pinned',
        'bmc_failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => MachineStatus::class,
            'cpu_cores' => 'integer',
            'ram_gb' => 'integer',
            'bmc_port' => 'integer',
            'bmc_username' => 'encrypted',
            'bmc_password' => 'encrypted',
            'bmc_ipmi_cipher' => 'integer',
            'bmc_reset_types' => 'array',
            'bmc_failures' => 'integer',
            'provisioned_at' => 'datetime',
            'released_at' => 'datetime',
            'wiped_at' => 'datetime',
            'power_check_at' => 'datetime',
            'bmc_checked_at' => 'datetime',
            'power_checked_at' => 'datetime',
        ];
    }

    /**
     * The service that holds the machine now.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The products this machine can be sold as.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'machine_product');
    }

    /**
     * @return HasMany<MachineIp, $this>
     */
    public function ips(): HasMany
    {
        return $this->hasMany(MachineIp::class);
    }

    /**
     * @return HasOne<MachineIp, $this>
     */
    public function primaryIp(): HasOne
    {
        return $this->hasOne(MachineIp::class)->where('kind', MachineIp::KIND_PRIMARY);
    }

    /**
     * @param  Builder<Machine>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('machines.status', MachineStatus::Available->value);
    }

    /**
     * Free machines linked to the product: the product's stock.
     *
     * @param  Builder<Machine>  $query
     */
    public function scopeSellableFor(Builder $query, Product $product): void
    {
        $query->where('machines.status', MachineStatus::Available->value)
            ->whereExists(fn (QueryBuilder $link): QueryBuilder => $link->from('machine_product')
                ->whereColumn('machine_product.machine_id', 'machines.id')
                ->where('machine_product.product_id', $product->getKey()));
    }

    /**
     * The power driver type, or null for a type no installed driver knows (a removed package).
     */
    public function bmcType(): ?BmcType
    {
        return BmcType::tryFrom((string) $this->bmc_type);
    }

    /**
     * Whether staff set up a BMC to switch the power with.
     */
    public function hasBmc(): bool
    {
        return $this->bmc_type !== BmcType::None->value && filled($this->bmc_host);
    }

    /**
     * Whether Nuvabill may send the BMC login: its certificate is checked by a trusted CA, or staff
     * trusted (pinned) its key.
     */
    public function bmcIsTrusted(): bool
    {
        return $this->bmc_tls_mode === 'verified' || filled($this->bmc_tls_pin);
    }

    /**
     * The last power state the BMC reported.
     */
    public function powerState(): PowerState
    {
        return PowerState::tryFrom((string) $this->power_state) ?? PowerState::Unknown;
    }
}
