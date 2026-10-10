<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Resource options (extra RAM, disk, IP addresses and so on, sold as sliders): the view composers
 * of the admin product and service pages, and the resources card on the client's service page.
 *
 * Empty for now; the resource option screens register their parts here.
 */
class ResourceOptionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}
