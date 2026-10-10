<?php

namespace App\Enums;

/**
 * Where a dedicated server machine is in its life: free to sell, held by a service, waiting for a
 * disk wipe, or out of use. It moves only through MachineInventory, never by a free choice in a
 * form, so a machine that was not wiped can never be sold again.
 */
enum MachineStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Wiping = 'wiping';
    case Maintenance = 'maintenance';
    case Decommissioned = 'decommissioned';

    /**
     * The moves MachineInventory may make. Wiping to available, maintenance or decommissioned is
     * only allowed through MachineInventory::confirmWiped().
     *
     * @var array<string, list<string>>
     */
    private const MOVES = [
        'available' => ['reserved', 'provisioning', 'maintenance', 'decommissioned'],
        'reserved' => ['provisioning', 'available'],
        'provisioning' => ['active', 'wiping'],
        'active' => ['suspended', 'wiping'],
        'suspended' => ['active', 'wiping'],
        'wiping' => ['available', 'maintenance', 'decommissioned'],
        'maintenance' => ['available', 'decommissioned'],
        'decommissioned' => ['maintenance'],
    ];

    public function label(): string
    {
        return match ($this) {
            self::Available => __('Available'),
            self::Reserved => __('Reserved'),
            self::Provisioning => __('Setting up'),
            self::Active => __('In use'),
            self::Suspended => __('Suspended'),
            self::Wiping => __('Needs wipe'),
            self::Maintenance => __('Maintenance'),
            self::Decommissioned => __('Retired'),
        };
    }

    /**
     * Visual tone used by status pills: good, warn, crit, info or muted.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Available, self::Active => 'good',
            self::Reserved, self::Provisioning => 'info',
            self::Suspended, self::Maintenance => 'warn',
            self::Wiping => 'crit',
            self::Decommissioned => 'muted',
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to->value, self::MOVES[$this->value], true);
    }

    /**
     * Whether a machine in this status belongs to a service (machines.service_id is set).
     */
    public function holdsService(): bool
    {
        return in_array($this, [self::Reserved, self::Provisioning, self::Active, self::Suspended], true);
    }
}
