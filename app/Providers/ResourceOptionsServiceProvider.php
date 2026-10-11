<?php

namespace App\Providers;

use App\Billing\ResourceChanges;
use App\Models\Service;
use App\Support\ServicePanels;
use App\View\Composers\AdminServiceResourcesComposer;
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
        View::composer('admin.services.partials.resources', AdminServiceResourcesComposer::class);

        // The "Upgrade resources" card on the client's service page, after the server panel.
        $this->app->make(ServicePanels::class)->add(fn (Service $service): ?array => $this->app->make(ResourceChanges::class)->panelFor($service));
    }
}
