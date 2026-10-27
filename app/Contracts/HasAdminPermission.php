<?php

namespace App\Contracts;

/**
 * A server module with its own staff permission, for its admin pages (HasAdminPages) and its card
 * on the admin service page (HasStaffPanel). The permission shows in Settings → Roles under the
 * module's name, and the owner role always has it.
 */
interface HasAdminPermission
{
    /**
     * The permission key: the module's slug, a dot and a short name, for example
     * "proxmox-ve-servers.manage". Anything else is refused and the module gets no staff pages.
     */
    public function adminPermission(): string;

    /**
     * What the permission allows, already translated, for example "Move guests and see node status".
     */
    public function adminPermissionLabel(): string;
}
