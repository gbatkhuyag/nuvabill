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
        $data = $request->validate([
            'ip' => ['required', 'string', 'max:45', 'ip'],
            // A one-time code the site keeps when its server goes out from a new address: the store
            // calls the site at that address with it, to check that the address is the site's own.
            'move_token' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
        ]);

        return response()->json($this->sites->heartbeat(
            $site,
            (string) $data['ip'],
            isset($data['move_token']) ? (string) $data['move_token'] : null,
            (bool) $request->attributes->get(AuthenticateMarketplaceSite::PREVIOUS_KEY),
        ));
    }

    public function rotate(Request $request): JsonResponse
    {
        return response()->json(['key' => $this->sites->rotate($this->site($request), (string) $request->bearerToken())]);
    }

    private function site(Request $request): MarketplaceSite
    {
        $site = $request->attributes->get(AuthenticateMarketplaceSite::ATTRIBUTE);

        abort_unless($site instanceof MarketplaceSite, response()->json(['message' => __('This needs the site\'s marketplace key.'), 'code' => 'reconnect'], 401));

        return $site;
    }
}
