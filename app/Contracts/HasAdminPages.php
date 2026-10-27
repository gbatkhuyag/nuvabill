<?php

namespace App\Contracts;

use App\Extensions\Servers\Staff\ModuleAdmin;
use App\Models\Admin;

/**
 * A server module with pages in the admin area, under /admin/server-modules/{slug}/. Core puts them
 * behind the staff sign-in, the module's admin permission, two-factor login (staff must have it
 * on), the form token, a limit of 30 requests a minute and the demo lock, and lists the "index"
 * page in the menu. The manifest must list "admin-page" in its permissions. Since Nuvabill 0.7.0.
 */
interface HasAdminPages extends HasAdminPermission
{
    /**
     * Register the pages with short names, for example Route::get('/', ...)->name('index'). Link to
     * them with route(ModuleAdmin::routeName($this->slug(), 'index')). A page named "index" is
     * listed in the admin menu under the module's name.
     *
     * @see ModuleAdmin::routeName()
     */
    public function adminRoutes(): void;

    /**
     * Items for the dashboard's "Needs attention" list, shown only to staff with the module's admin
     * permission. Same shape as App\Support\AttentionItems: key (starts with "ext."), tone (warn or
     * crit), text, url (on this site) and action. At most 5 are shown. Keep it fast: it runs on
     * every dashboard view, so read saved state, never the server.
     *
     * @return list<array{key: string, tone: string, text: string, url?: string|null, action?: string|null}>
     */
    public function attentionItems(Admin $admin): array;
}
