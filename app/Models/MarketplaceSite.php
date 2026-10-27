<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The marketplace store only: a site that connected to the marketplace. Its key is only kept as a
 * SHA-256 hash. An active site's server address is let through Cloudflare by an access rule.
 *
 * @property string $host
 * @property string $ip
 * @property string $ip_family
 * @property string $status
 * @property ?string $cloudflare_rule_id
 */
class MarketplaceSite extends Model
{
    public const STATUS_ACTIVE = 'active';

    /**
     * Waits for store staff to allow its address.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    /**
     * Days after which a site is asked to change its key.
     */
    public const KEY_DAYS = 90;

    protected $fillable = ['host', 'ip', 'ip_family', 'key_hash', 'key_created_at', 'status', 'cloudflare_rule_id', 'ip_matches_dns', 'ip_reached_directly', 'last_seen_at', 'last_ip', 'approved_by', 'notes'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'key_created_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ip_matches_dns' => 'boolean',
            'ip_reached_directly' => 'boolean',
        ];
    }

    /**
     * The site with this key, when the key is known. Only the hash is looked up and compared.
     */
    public static function findByKey(string $key): ?self
    {
        if (! preg_match('/^nvb_site_[A-Za-z0-9_-]{43}$/', $key)) {
            return null;
        }

        $hash = hash('sha256', $key);
        $site = self::query()->where('key_hash', $hash)->first();

        return $site !== null && hash_equals((string) $site->key_hash, $hash) ? $site : null;
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Sites whose key still works: active, or waiting for staff.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_PENDING]);
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_PENDING], true);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function needsNewKey(): bool
    {
        return $this->key_created_at === null || $this->key_created_at->lt(now()->subDays(self::KEY_DAYS));
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }
}
