<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages that reach many clients' servers at once, such as server module admin pages and staff
 * actions, need two-factor login on the staff member's own account, also when the company does
 * not require it for everyone (Settings → Security). Staff without it are sent to their profile.
 */
class RequireStaffTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin instanceof Admin && ! $admin->hasTwoFactorEnabled() && ! Demo::isEnabled()) {
            $message = __('Turn on two-factor login in your profile to use server module tools.');

            return $request->expectsJson()
                ? response()->json(['message' => $message], 403)
                : redirect()->route('admin.profile.edit')->with('error', $message);
        }

        return $next($request);
    }
}
