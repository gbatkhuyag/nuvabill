<?php

namespace App\Extensions\Servers\Resources;

/**
 * One kind of extra a module can sell with a slider, such as RAM or extra IPv4 addresses. A
 * built-in key (cpu, ram, disk, ipv4, ipv6, bandwidth, backups) must use its built-in unit; a
 * module's own key is "{slug with - as _}_{name}", such as "hetzner_cloud_size".
 */
final readonly class ResourceKind
{
    /**
     * A letter, then 1 to 39 lowercase letters, digits or underscores.
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/D';

    /**
     * Up to 10 letters or slashes, such as "MB", "IP" or "KB/s". Empty for a plain count.
     */
    public const UNIT_PATTERN = '/^[A-Za-z\/]{0,10}$/D';

    /**
     * @param  string  $key  See KEY_PATTERN.
     * @param  string  $label  Shown to clients and staff, already translated.
     * @param  string  $unit  See UNIT_PATTERN.
     * @param  int  $minStep  The smallest step size staff may set, in units.
     * @param  int  $stepMultiple  Step sizes must be a multiple of this.
     * @param  int|null  $maxExtra  The most extra units the module can add, or null for no limit of its own.
     * @param  bool  $canShrink  False when extras can only grow on a live server, such as disk.
     * @param  bool  $restarts  Applying it restarts the server, or needs a restart to take effect.
     * @param  string|null  $note  Plain text for clients, already translated, such as "Mount the new volume inside your server."
     * @param  list<string>|null  $ladder  Step labels when steps are sizes rather than units (step 0 is the plan itself). Only for a module's own kinds.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $unit = '',
        public int $minStep = 1,
        public int $stepMultiple = 1,
        public ?int $maxExtra = null,
        public bool $canShrink = true,
        public bool $restarts = false,
        public ?string $note = null,
        public ?array $ladder = null,
    ) {}

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    public static function isValidUnit(string $unit): bool
    {
        return preg_match(self::UNIT_PATTERN, $unit) === 1;
    }
}
