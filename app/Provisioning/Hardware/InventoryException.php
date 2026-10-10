<?php

namespace App\Provisioning\Hardware;

use RuntimeException;

/**
 * MachineInventory refused a change, for example a status move that would skip a wipe. The message
 * is plain English for staff and holds no secret, so it can be shown as it is.
 */
final class InventoryException extends RuntimeException
{
    /**
     * @param  int  $missing  How many more free extra IP addresses the machine needs (syncExtraIps), else 0.
     */
    public function __construct(string $message, public readonly int $missing = 0)
    {
        parent::__construct($message);
    }
}
