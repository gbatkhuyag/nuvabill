<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Marketplace\MarketplaceClient;
use App\Marketplace\SiteConnection;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Admin → Marketplace → "Connect to marketplace": sends staff to the store to confirm this site.
 * The store then calls this site back (MarketplaceConnectCallbackController) with its key.
 */
class MarketplaceConnectController extends Controller
{
    public function store(SiteConnection $connection, MarketplaceClient $client): RedirectResponse
    {
        if (config('nuvabill.marketplace.store')) {
            return back()->with('error', __('This is the marketplace store, so it does not connect to itself.'));
        }

        try {
            $url = $connection->begin($client->site());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        Activity::log('marketplace.connect', 'Started connecting this site to the marketplace');

        return redirect()->away($url);
    }
}
