<?php

namespace App\Models;

use App\Enums\BootDevice;
use App\Enums\PowerAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of a machine's power history: who asked for which power action or boot device, when,
 * and what came of it. PowerControl writes it for every action of any driver; nothing else does.
 * It never holds anything about the BMC login or address.
 */
class MachinePowerEvent extends Model
{
    public const UPDATED_AT = null;

    public const RESULT_OK = 'ok';

    public const RESULT_FAILED = 'failed';

    /** The machine was already in the asked state. */
    public const RESULT_ALREADY = 'already';

    /** The action key of a next boot device choice: "boot:" and the device. */
    public const BOOT_PREFIX = 'boot:';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * The client or staff member who asked; null when Nuvabill or an add-on did it.
     *
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The action in the staff member's language, such as "Force off" or "Next boot: Network (PXE)".
     */
    public function actionLabel(): string
    {
        if (str_starts_with($this->action, self::BOOT_PREFIX)) {
            $device = BootDevice::tryFrom(substr($this->action, strlen(self::BOOT_PREFIX)));

            return __('Next boot: :device', ['device' => $device?->label() ?? substr($this->action, strlen(self::BOOT_PREFIX))]);
        }

        return PowerAction::tryFrom($this->action)?->label() ?? $this->action;
    }

    /**
     * Who asked, for staff: the client's or staff member's name, or "Automatic".
     */
    public function actorLabel(): string
    {
        $actor = $this->actor;

        return match (true) {
            $actor instanceof Admin, $actor instanceof Client => (string) $actor->name,
            $this->actor_kind === 'client' => __('Client'),
            $this->actor_kind === 'staff' => __('Staff'),
            default => __('Automatic'),
        };
    }
}
