<?php

namespace App\Http\Controllers;

use App\Marketplace\SiteConnection;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The marketplace store calls this at the site's own web address with the one-time code staff got
 * when they clicked "Connect to marketplace", and the site's new key. Without a valid, unused code
 * from the last half hour nothing is saved. No session, cookies or form token: the code is the check.
 */
class MarketplaceConnectCallbackController extends Controller
{
    public function __invoke(Request $request, SiteConnection $connection): JsonResponse
    {
        $state = $request->input('state');
        $key = $request->input('key');
        $siteId = $request->input('site_id');

        if (! is_string($state) || ! is_string($key) || ! (is_int($siteId) || (is_string($siteId) && ctype_digit($siteId) && strlen($siteId) <= 18))) {
            return response()->json([], 404);
        }

        $saved = $connection->accept($state, $key, (int) $siteId);

        if ($saved === null) {
            return response()->json([], 404);
        }

        Activity::log('marketplace.connected', 'This site was connected to the marketplace (outgoing address '.$saved['ip'].')');

        return response()->json([
            'ok' => true,
            'ip' => $saved['ip'],
            'family' => $saved['family'],
            'return_path' => route('admin.marketplace.index', [], false),
        ]);
    }

    /**
     * The store checks a new server address: it calls this site at that address with the one-time
     * code this site sent with its heartbeat. Nothing is saved here.
     */
    public function verify(Request $request, SiteConnection $connection): JsonResponse
    {
        $token = $request->input('token');
        $ip = is_string($token) ? $connection->verifyMove($token) : null;

        return $ip === null ? response()->json([], 404) : response()->json(['ok' => true, 'ip' => $ip]);
    }
}
