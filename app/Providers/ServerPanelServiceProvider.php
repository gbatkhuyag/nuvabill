<?php

namespace App\Providers;

use App\View\Composers\ProductPanelSectionsComposer;
use App\View\Composers\ServiceClientAccessComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * The client server panel (tabs, power and the browser console): its rate limits, and the view
 * composers of the admin product and service pages.
 */
class ServerPanelServiceProvider extends ServiceProvider
{
    /**
     * Section reads a client may make each minute, over all their services together. Each section
     * also has its own limit per service in the panel gate.
     */
    public const READS_PER_MINUTE = 120;

    /**
     * Panel actions a client may run each minute, over all their services together. Each action
     * also has its own limit per service in the panel gate.
     */
    public const ACTIONS_PER_MINUTE = 30;

    public function boot(): void
    {
        RateLimiter::for('panel-reads', fn (Request $request): Limit => Limit::perMinute(self::READS_PER_MINUTE)->by(self::visitor($request)));
        RateLimiter::for('panel-actions', fn (Request $request): Limit => Limit::perMinute(self::ACTIONS_PER_MINUTE)->by(self::visitor($request)));

        // The product form's "Client panel" switches and the service page's "Client access" card.
        View::composer('admin.products.partials.panel-sections', ProductPanelSectionsComposer::class);
        View::composer('admin.services.partials.client-access', ServiceClientAccessComposer::class);
    }

    /**
     * The signed-in client, or the visitor's IP address (the routes need a client, so this only
     * counts requests that are turned away anyway).
     */
    private static function visitor(Request $request): string
    {
        $client = $request->user('web');

        return $client !== null ? 'client:'.$client->getAuthIdentifier() : 'guest:'.$request->ip();
    }
}
