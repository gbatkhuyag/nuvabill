<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMarketplaceSite;
use App\Marketplace\Store\ConnectedSites;
use App\Models\MarketplaceSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The store API for sites connected to the marketplace. Every call needs the site's key.
 */
class SiteApiController extends Controller
{
    public function __construct(private ConnectedSites $sites) {}

    public function heartbeat(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $ip = (string) $request->validate(['ip' => ['required', 'string', 'max:45', 'ip']])['ip'];

        return response()->json($this->sites->heartbeat($site, $ip));
    }

    public function rotate(Request $request): JsonResponse
    {
        return response()->json(['key' => $this->sites->rotate($this->site($request))]);
    }

    private function site(Request $request): MarketplaceSite
    {
        $site = $request->attributes->get(AuthenticateMarketplaceSite::ATTRIBUTE);

        abort_unless($site instanceof MarketplaceSite, response()->json(['message' => __('This needs the site\'s marketplace key.'), 'code' => 'reconnect'], 401));

        return $site;
    }
}
