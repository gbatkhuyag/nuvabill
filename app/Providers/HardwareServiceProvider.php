<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Dedicated servers in your own rack (Admin → Hardware): the power drivers, the certificate check
 * of BMCs, freeing a machine when its service ends, and the view composers of the admin pages.
 *
 * Empty for now; the hardware parts register here.
 */
class HardwareServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}
