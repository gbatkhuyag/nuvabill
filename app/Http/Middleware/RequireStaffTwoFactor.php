<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Security\StaffSecondFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages that reach many clients' servers at once, such as server module admin pages and staff
 * actions, need two-factor login on the staff member's own account, also when the company does
 * not require it for everyone (Settings → Security), and a session that passed it
 * (StaffSecondFactor). Staff without it are sent to their profile; staff whose session did not
 * pass it (a remember-me sign-in, say) are asked for a code first.
 */
class RequireStaffTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (! $admin instanceof Admin || StaffSecondFactor::passed($admin)) {
            return $next($request);
        }

        if (! $admin->hasTwoFactorEnabled()) {
            $message = __('Turn on two-factor login in your profile to use server module tools.');

            return $request->expectsJson()
                ? response()->json(['message' => $message], 403)
                : redirect()->route('admin.profile.edit')->with('error', $message);
        }

        return $request->expectsJson()
            ? response()->json(['message' => __('Enter a code from your authenticator app to use server module tools.')], 403)
            : redirect()->guest(route('admin.two-factor.step-up'));
    }
}
