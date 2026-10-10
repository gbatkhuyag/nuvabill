<?php

use App\Providers\AppServiceProvider;
use App\Providers\HardwareServiceProvider;
use App\Providers\MarketplaceStoreServiceProvider;
use App\Providers\ResourceOptionsServiceProvider;
use App\Providers\ServerPanelServiceProvider;

return [
    AppServiceProvider::class,
    MarketplaceStoreServiceProvider::class,
    ResourceOptionsServiceProvider::class,
    ServerPanelServiceProvider::class,
    HardwareServiceProvider::class,
];
