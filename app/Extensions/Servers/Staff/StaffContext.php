<?php

namespace App\Extensions\Servers\Staff;

/**
 * Who runs a staff action from a module's card on the admin service page (HasStaffPanel). Put
 * the name in the module's own logs as the operator.
 */
final readonly class StaffContext
{
    public function __construct(
        public int $adminId,
        public string $adminName,
        public string $ipAddress,
    ) {}
}
