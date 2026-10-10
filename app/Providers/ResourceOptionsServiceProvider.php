<?php

namespace App\Providers;

use App\View\Composers\ProductResourceTabsComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Resource options (extra RAM, disk, IP addresses and so on, sold as sliders): the view composers
 * of the admin product and service pages, and the resources card on the client's service page.
 */
class ResourceOptionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::composer(['admin.products.partials.resource-tabs', 'admin.products.index'], ProductResourceTabsComposer::class);
    }
}
