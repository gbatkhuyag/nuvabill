<?php

namespace App\Http\Middleware;

use App\Models\MarketplaceSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The marketplace store API: a site connected to the marketplace sends its key as a Bearer token.
 * A key that is unknown, revoked or expired gets 401 with code "reconnect", so the site tells its
 * staff to connect again. Calls without a key work as before.
 */
class AuthenticateMarketplaceSite
{
    public const ATTRIBUTE = 'marketplace_site';

    /**
     * True when the call came with the key before the last change (see ConnectedSites::rotate()).
     */
    public const PREVIOUS_KEY = 'marketplace_site_previous_key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->bearerToken();

        if ($key === null || $key === '') {
            return $next($request);
        }

        $site = MarketplaceSite::findByKey($key);

        if ($site === null || ! $site->isUsable()) {
            return response()->json([
                'message' => __('This site is no longer connected to the marketplace. Connect it again on its Marketplace page.'),
                'code' => 'reconnect',
            ], 401);
        }

        $previous = $site->usesPreviousKey($key);

        if (! $previous && $site->previous_key_hash !== null) {
            // The site has its new key, so the old one stops working now.
            $site->forceFill(['previous_key_hash' => null, 'previous_key_expires_at' => null])->saveQuietly();
        }

        // At most once every ten minutes, so busy sites do not write on every call.
        if ($site->last_seen_at === null || $site->last_seen_at->lt(now()->subMinutes(10))) {
            $site->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        }

        $request->attributes->set(self::ATTRIBUTE, $site);
        $request->attributes->set(self::PREVIOUS_KEY, $previous);

        return $next($request);
    }
}
