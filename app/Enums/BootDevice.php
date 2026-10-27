<?php

namespace App\Enums;

/**
 * Where a dedicated server machine starts from on its next boot, for drivers that can set it
 * (ChoosesBootDevice). It applies to the next boot only; after that the machine uses its own
 * boot order again.
 */
enum BootDevice: string
{
    case Disk = 'disk';
    case Pxe = 'pxe';
    case Bios = 'bios';

    public function label(): string
    {
        return match ($this) {
            self::Disk => __('Local disk'),
            self::Pxe => __('Network (PXE)'),
            self::Bios => __('BIOS setup'),
        };
    }
}
