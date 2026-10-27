<?php

namespace App\Extensions\Servers\Staff;

/**
 * Route names of server module admin pages (HasAdminPages).
 */
final class ModuleAdmin
{
    /**
     * The full route name of one of a module's admin pages, for example
     * ModuleAdmin::routeName('proxmox-ve-servers', 'index').
     */
    public static function routeName(string $slug, string $name): string
    {
        return 'admin.server-modules.'.$slug.'.'.$name;
    }
}
